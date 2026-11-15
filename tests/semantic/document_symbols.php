<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Semantic\DocumentSymbol;
use Semantic\LibrarySymbols;
use Semantic\Workspace;

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

function ws(): Workspace
{
    $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/core_interfaces.php');
    $pf = new ParsedFile('lib:prelude/core_interfaces.php', \Parser\Parser::parseTolerant($src, 'core_interfaces.php')->program);
    return new Workspace(LibrarySymbols::fromParts([$pf], ['strlen']));
}

/** @param DocumentSymbol[] $l */
function dump(array $l): string
{
    $o = [];
    foreach ($l as $s) {
        $o[] = $s->kind . ' ' . $s->name . ' ' . $s->nameRange->line . ':' . $s->nameRange->col . '-' . $s->nameRange->endCol
            . '@' . $s->range->line . '-' . $s->range->endLine
            . ($s->children === [] ? '' : '{' . dump($s->children) . '}');
    }
    return implode('; ', $o);
}

$U = 'file:///t/a.php';
$SRC = "<?php\nnamespace App;\nclass Foo {\n    const X = 1;\n    public int \$p = 2;\n    public function a() { return function () {}; }\n    public function b() {}\n}\nenum E { case C; }\ninterface I {}\ntrait T {}\nfunction f() {}\n";

check('nested tree, kinds and name ranges', function () use ($U, $SRC): bool|string {
    $w = ws();
    $w->open($U, $SRC);
    $got = dump($w->documentSymbols($U));
    $want = 'class Foo 3:7-10@3-8{constant X 4:11-12@4-4; property p 5:16-18@5-5; method a 6:21-22@6-6; method b 7:21-22@7-7}; '
        . 'enum E 9:6-7@9-9{case C 9:15-16@9-9}; interface I 10:11-12@10-10; trait T 11:7-8@11-11; function f 12:10-11@12-12';
    return $got === $want ?: $got;
});
check('whole-declaration range spans the body', function () use ($U, $SRC): bool|string {
    $w = ws();
    $w->open($U, $SRC);
    $c = $w->documentSymbols($U)[0];
    return ($c->range->line === 3 && $c->range->col === 1 && $c->range->endLine === 8 && $c->range->endCol === 2) ?: dump([$c]);
});
check('parse error inside one method still lists the others', function () use ($U): bool|string {
    $w = ws();
    $w->open($U, "<?php\nclass K {\n    public function ok1() {}\n    public function bad() { \$x = ; }\n    public function ok2() {}\n}\nfunction after() {}\n");
    $got = dump($w->documentSymbols($U));
    foreach (['ok1', 'ok2', 'after', 'K'] as $n) {
        if (!str_contains($got, $n)) { return $got; }
    }
    return true;
});
check('closed and unknown files -> []', function () use ($U, $SRC): bool|string {
    $w = ws();
    if ($w->documentSymbols('file:///t/none.php') !== []) { return 'unknown'; }
    $w->open($U, $SRC);
    $w->close($U);
    return $w->documentSymbols($U) === [];
});

exit($fail);
