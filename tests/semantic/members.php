<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Index;
use Analyze\ParsedFile;

function pf(string $path, string $src): ParsedFile
{
    return new ParsedFile($path, \Parser\Parser::parseTolerant($src, $path, true, true, 2)->program);
}

$fail = 0;
function check(string $name, callable $fn): void
{
    global $fail;
    try {
        $r = $fn();
        if ($r === true) { echo "PASS $name\n"; return; }
        echo "FAIL $name: " . (is_string($r) ? $r : 'false') . "\n";
    } catch (\Throwable $e) {
        echo "FAIL $name: " . get_class($e) . ' ' . $e->getMessage() . "\n";
    }
    $fail = 1;
}

$P = pf('p.php', "<?php\nnamespace N;\nclass P {\n    private int \$x = 1;\n    public function run(): int { return 1; }\n    protected static function make(): static { return new static; }\n}\n");
$T = pf('t.php', "<?php\nnamespace N;\ntrait T { public function hi(): string { return ''; } public ?int \$tp = null; }\n");
$I = pf('i.php', "<?php\nnamespace N;\ninterface I { const V = 1; public function ab(): void; }\n");
$C = pf('c.php', "<?php\nnamespace N;\nclass C extends P implements I {\n    use T;\n    public function __construct(public string \$pp = '') {}\n    public function run(): int { return 2; }\n    public function ab(): void {}\n}\nclass D extends C {}\nenum E: int { case A = 1; const K = 2; }\n");
$idx = Index::build([$P, $T, $I, $C]);

check('own method', fn() => $idx->lookupMember('N\C', 'RUN', 'method')?->declClass === 'N\C');
check('inherited via parent', fn() => $idx->lookupMember('N\D', 'make', 'method')?->declClass === 'N\P');
check('static flag', fn() => $idx->lookupMember('N\D', 'make', 'method')?->isStatic === true);
check('via trait', fn() => $idx->lookupMember('N\D', 'hi', 'method')?->declClass === 'N\T');
check('trait prop', fn() => $idx->lookupMember('N\C', 'tp', 'prop')?->typeHint === '?int');
check('promoted prop', function () use ($idx) { $m = $idx->lookupMember('N\C', 'pp', 'prop'); return $m !== null && $m->visibility === 'public' && $m->typeHint === 'string'; });
check('interface const', fn() => $idx->lookupMember('N\D', 'V', 'const')?->declClass === 'N\I');
check('interface method abstract', fn() => $idx->lookupMember('N\I', 'ab', 'method')?->isAbstract === true);
check('private reported', fn() => $idx->lookupMember('N\D', 'x', 'prop')?->visibility === 'private');
check('missing', fn() => $idx->lookupMember('N\D', 'nope', 'method') === null);
check('enum from', fn() => $idx->lookupMember('N\E', 'from', 'method')?->typeHint === 'static');
check('enum cases', fn() => $idx->lookupMember('N\E', 'cases', 'method')?->isStatic === true);
check('enum case typed as enum', fn() => $idx->lookupMember('N\E', 'A', 'case')?->typeHint === 'N\E');
check('case found as const', fn() => $idx->lookupMember('N\E', 'A', 'const')?->kind === 'case');
check('enum const', fn() => $idx->lookupMember('N\E', 'K', 'const')?->kind === 'const');
check('name-precise position', function () use ($idx) {
    $m = $idx->lookupMember('N\P', 'run', 'method');
    return $m->file === 'p.php' && $m->line === 5 && $m->col === 21 ? true : "$m->line:$m->col";
});
check('prop position', function () use ($idx) {
    $m = $idx->lookupMember('N\P', 'x', 'prop');
    return $m->line === 4 && $m->col === 17 ? true : "$m->line:$m->col";
});
check('overridersOf', function () use ($idx) {
    $o = $idx->overridersOf('N\P', 'run');
    return count($o) === 1 && $o[0]->declClass === 'N\C' ? true : 'got ' . count($o);
});
check('overridersOf interface', fn() => count($idx->overridersOf('N\I', 'ab')) === 1);
check('memo invalidated on parent update', function () use ($idx, $P) {
    if ($idx->lookupMember('N\D', 'make', 'method') === null) { return 'precondition'; }
    $idx->replaceFile(pf('p.php', "<?php\nnamespace N;\nclass P { public function other() {} }\n"));
    if ($idx->lookupMember('N\D', 'make', 'method') !== null) { return 'stale hit'; }
    if ($idx->lookupMember('N\D', 'other', 'method') === null) { return 'new member missing'; }
    $idx->removeFile('p.php');
    if ($idx->lookupMember('N\D', 'other', 'method') !== null) { return 'stale after remove'; }
    $idx->replaceFile($P);
    return $idx->lookupMember('N\D', 'make', 'method') !== null ? true : 'not restored';
});
check('negative memo invalidated by late definition', function () {
    $i = Index::build([pf('d.php', "<?php\nclass Child extends Late {}\n")]);
    if ($i->lookupMember('Child', 'm', 'method') !== null) { return 'precondition'; }
    $i->replaceFile(pf('l.php', "<?php\nclass Late { function m() {} }\n"));
    return $i->lookupMember('Child', 'm', 'method') !== null ? true : 'stale null';
});
check('no decl names falls back to decl start', function () {
    $i = Index::build([new ParsedFile('x.php', \Parser\Parser::parseTolerant("<?php\nclass X { function f() {} }\n", 'x.php')->program)]);
    $m = $i->lookupMember('X', 'f', 'method');
    return $m !== null && $m->line === 2 ? true : 'bad';
});
check('workspace owner on member decls', function () {
    $ni = new \Semantic\NameIndex();
    $ni->replaceFile('o.php', \Parser\Parser::parseTolerant("<?php\nnamespace A;\nclass K { function f() {} public \$p; const Q = 1; }\nclass L { function f() {} }\n", 'o.php', true, true, 2)->program);
    $owners = [];
    foreach ($ni->allOccurrences() as $o) { if ($o->isDecl && $o->kind === 'member') { $owners[] = $o->resolved . '@' . $o->owner; } }
    return $owners === ['f@A\K', 'p@A\K', 'Q@A\K', 'f@A\L'] ? true : implode(',', $owners);
});

function ownersOf(string $src): string
{
    $ni = new \Semantic\NameIndex();
    $ni->replaceFile('o.php', \Parser\Parser::parseTolerant($src, 'o.php', true, true, 2)->program);
    $owners = [];
    foreach ($ni->allOccurrences() as $o) { if ($o->isDecl && $o->kind === 'member') { $owners[] = $o->resolved . '@' . preg_replace('/^(\\\\?)__anon_class_\d+_\d+_\d+$/', 'ANON', $o->owner); } }
    return implode(',', $owners);
}
check('owner: anon class inside method', fn() => ownersOf("<?php\nclass A { function m() { return new class { function run() {} }; } function run() {} }\n") === 'm@A,run@ANON,run@A' ? true : ownersOf("<?php\nclass A { function m() { return new class { function run() {} }; } function run() {} }\n"));
check('owner: top-level anon after class', fn() => ownersOf("<?php\nclass A { function f() {} }\n\$x = new class { function g() {} };\n") === 'f@A,g@ANON' ? true : ownersOf("<?php\nclass A { function f() {} }\n\$x = new class { function g() {} };\n"));
check('owner: named class in function', fn() => ownersOf("<?php\nfunction mk() { class Inner { function z() {} } }\nclass B { function y() {} }\n") === 'z@Inner,y@B' ? true : ownersOf("<?php\nfunction mk() { class Inner { function z() {} } }\nclass B { function y() {} }\n"));
check('anon owner matches Index key', function () {
    $src = "<?php\nclass A { function m() { return new class { function run() {} }; } }\n";
    $p = \Parser\Parser::parseTolerant($src, 'o.php', true, true, 2)->program;
    $ni = new \Semantic\NameIndex();
    $ni->replaceFile('o.php', $p);
    $idx = Index::build([new ParsedFile('o.php', $p)]);
    foreach ($ni->allOccurrences() as $o) {
        if ($o->isDecl && $o->resolved === 'run' && $idx->lookupMember($o->owner, 'run', 'method') !== null && $o->owner !== 'A') { return true; }
    }
    return 'no anon owner resolvable in Index';
});
check('enum implicit members flagged', function () use ($idx) {
    $m = $idx->lookupMember('N\E', 'from', 'method');
    $c = $idx->lookupMember('N\E', 'A', 'case');
    return $m->implicit && $m->line === 0 && !$c->implicit && $c->line > 0 ? true : 'bad';
});
check('declOf: member decl cannot win over function own name', function () {
    $p = \Parser\Parser::parseTolerant("<?php\nfunction run(array \$a) { return new class { function run(array \$b) {} }; }\n", 'o.php', true, true, 2)->program;
    $fn = $p->statements[0]->decl;
    $r = \Analyze\NameRanges::declOf($p->names, 'run', $fn->span);
    return $r !== null && $r->kind === 'function' ? true : 'wrong decl';
});

exit($fail);
