<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
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

function ws(array $files): Workspace
{
    $prelude = [];
    foreach (['core_interfaces.php', 'exceptions.php'] as $f) {
        $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/' . $f);
        $prelude[] = new ParsedFile('lib:prelude/' . $f, \Parser\Parser::parseTolerant($src, $f)->program);
    }
    $w = new Workspace(LibrarySymbols::fromParts($prelude, ['strlen', 'count']));
    foreach ($files as $u => $t) { $w->open($u, $t); }
    return $w;
}

/** @return int[] [line, col] (1-based, bytes) of the $nth occurrence of $needle, +$off columns */
function where(string $src, string $needle, int $nth = 1, int $off = 0): array
{
    $at = -1;
    for ($i = 0; $i < $nth; $i++) { $at = strpos($src, $needle, $at + 1); if ($at === false) { throw new \RuntimeException("no '$needle' #$nth"); } }
    $before = substr($src, 0, $at);
    $nl = strrpos($before, "\n");
    return [1 + substr_count($before, "\n"), $at - ($nl === false ? -1 : $nl) + $off];
}

/** @return string[] */
function reasons(Workspace $w, string $f, array $p): array
{
    $o = [];
    foreach ($w->uncertainSitesFor($f, $p[0], $p[1]) as $s) { $o[] = $s->reason; }
    return $o;
}

/** The contract: no uncertain site without a blocker. */
function contract(Workspace $w, string $f, array $p): ?string
{
    $u = $w->uncertainSitesFor($f, $p[0], $p[1]);
    $b = $w->renameBlockers($f, $p[0], $p[1]);
    return count($u) > 0 && count($b) === 0 ? 'uncertain without blockers: ' . json_encode(reasons($w, $f, $p)) : null;
}

check('typed call site in a closed file is a blocker', function () {
    $a = "<?php\nclass Q { function m() {} }\nfunction f(Q \$q) { \$q->m(); }\nfunction g(\$u) { \$u->m(); }\n";
    $b = "<?php\nfunction h(Q \$c) { \$c->m(); }\n";
    $w = ws(['a.php' => $a, 'b.php' => $b]);
    $w->close('b.php');
    $p = where($a, 'm()', 1);
    $bl = $w->renameBlockers('a.php', $p[0], $p[1]);
    $un = array_values(array_filter($bl, fn($x) => str_starts_with($x, 'unresolved-member:')));
    $hasB = false;
    foreach ($un as $x) { if (str_starts_with($x, 'unresolved-member:b.php:2:')) { $hasB = true; } }
    return $hasB && count($un) === 2 ? (contract($w, 'a.php', $p) ?? true) : json_encode($bl);
});

check('many unresolved sites are capped with a count entry', function () {
    $a = "<?php\nclass Q { function m() {} }\n";
    for ($i = 0; $i < 60; $i++) { $a .= "function g$i(\$u) { \$u->m(); }\n"; }
    $w = ws(['a.php' => $a]);
    $p = where($a, 'm()', 1);
    $bl = $w->renameBlockers('a.php', $p[0], $p[1]);
    $un = array_values(array_filter($bl, fn($x) => str_starts_with($x, 'unresolved-member:')));
    return count($un) === 51 && in_array('unresolved-member:…+10 more', $un, true) ? true : count($un) . ' ' . json_encode(array_slice($un, -2));
});

check('fully resolved family has no blockers', function () {
    $a = "<?php\nclass Q { function m() {} }\nfunction f(Q \$q) { \$q->m(); }\n";
    $w = ws(['a.php' => $a]);
    $p = where($a, 'm()', 1);
    return $w->renameBlockers('a.php', $p[0], $p[1]) === [] ? true : json_encode($w->renameBlockers('a.php', $p[0], $p[1]));
});

check('callable string A::run is an uncertain site and a blocker', function () {
    $a = "<?php\nclass A { static function run() {} }\ncall_user_func('A::run');\ncall_user_func([A::class, 'run']);\n\$f = 'run';\n";
    $w = ws(['a.php' => $a]);
    $p = where($a, 'run()', 1);
    $un = [];
    foreach ($w->uncertainSitesFor('a.php', $p[0], $p[1]) as $s) { $un[] = $s->line . ':' . $s->reason; }
    sort($un);
    $want = ['3:callable-string', '4:string-class-name', '5:string-class-name'];
    return $un === $want ? (contract($w, 'a.php', $p) ?? true) : json_encode($un);
});

check('A::$$n and "${n}" are not missed: ${n} is a plain reference', function () {
    $a = "<?php\nclass A { public static \$sp = 1; }\n\$n = 'sp';\necho A::\$\$n;\n\$s = \"\${n}\";\n";
    $w = ws(['a.php' => $a]);
    $p = where($a, '$n', 1);
    $refs = $w->referencesTo('a.php', $p[0], $p[1]);
    $lines = [];
    foreach ($refs as $r) { $lines[] = $r->line; }
    $re = reasons($w, 'a.php', $p);
    $sp = where($a, '$sp', 1);
    $reSp = reasons($w, 'a.php', $sp);
    return $lines === [3, 4, 5] && in_array('dynamic-member', $reSp, true)
        && contract($w, 'a.php', $p) === null && contract($w, 'a.php', $sp) === null ? true : json_encode([$lines, $re, $reSp]);
});

check('readonly-only promoted parameter is a property with a member record', function () {
    $a = "<?php\nclass R { function __construct(readonly int \$x) {} }\nfunction f(R \$r) { return \$r->x; }\n";
    $w = ws(['a.php' => $a]);
    $p = where($a, '$r->x', 1, 4);
    $refs = $w->referencesTo('a.php', $p[0], $p[1]);
    $bl = $w->renameBlockers('a.php', $p[0], $p[1]);
    return count($refs) >= 2 && !in_array('owner-unknown', $bl, true) && !in_array('unresolved-member', $bl, true) ? true : json_encode([count($refs), $bl]);
});

check('instance access of a static property is not in the static family', function () {
    $a = "<?php\nclass A { public static int \$sp = 1; }\nfunction f(A \$x) { \$x->sp; return A::\$sp; }\n";
    $w = ws(['a.php' => $a]);
    $d = where($a, '$sp', 1);
    $lines = [];
    foreach ($w->referencesTo('a.php', $d[0], $d[1]) as $r) { $lines[] = $r->line . ':' . $r->col; }
    $bad = where($a, '->sp', 1, 2);
    $un = [];
    foreach ($w->uncertainSitesFor('a.php', $d[0], $d[1]) as $s) { $un[] = $s->line . ':' . $s->col; }
    return !in_array($bad[0] . ':' . $bad[1], $lines, true) && count($lines) === 2 && in_array($bad[0] . ':' . $bad[1], $un, true) && contract($w, 'a.php', $d) === null
        ? true : json_encode([$lines, $un]);
});

check('union receiver: definitionsOf lists every member; hover hides anonymous class names', function () {
    $a = "<?php\nclass A { function run() {} }\nclass B { function run() {} }\nfunction f(A|B \$u) { \$u->run(); }\n\$o = new class { function z() {} };\n\$o->z();\n";
    $w = ws(['a.php' => $a]);
    $p = where($a, 'run();', 1);
    $ds = $w->definitionsOf('a.php', $p[0], $p[1]);
    $q = where($a, 'z();', 1);
    $h = $w->hover('a.php', $q[0], $q[1]);
    return count($ds) === 2 && $h !== null && !str_contains($h->text, '__anon_class') && str_contains($h->text, 'class@anonymous') ? true : json_encode([count($ds), $h === null ? null : $h->text]);
});

check('renaming a parameter is blocked by a named argument spelled like it', function () {
    $src = "<?php\nfunction m(int \$val) { return \$val; }\nm(val: 2);\n";
    $w = ws(['file:///t/a.php' => $src]);
    $p = where($src, '$val', 1, 1);
    $b = $w->renameBlockers('file:///t/a.php', $p[0], $p[1]);
    return (count($b) === 1 && str_starts_with($b[0], 'named-argument:file:///t/a.php:3:')) ?: json_encode($b);
});
check('renaming a plain local is not blocked by a named argument of the same name', function () {
    $src = "<?php\nfunction m(int \$other) {}\nfunction g() { \$val = 1; return \$val; }\nm(val: 2);\n";
    $w = ws(['file:///t/a.php' => $src]);
    $p = where($src, '$val', 1, 1);
    return $w->renameBlockers('file:///t/a.php', $p[0], $p[1]) === [] ?: 'blocked';
});
check('#519 a heredoc in an unrelated file does not block; one that mentions the symbol does; cursor file always', function () {
    $a = "<?php\nclass Foo { public function run() {} }\n\$f = new Foo();\n";
    $p = where($a, 'Foo', 1);
    $q = where($a, 'run', 1);
    $unrelated = "<?php\n\$o = 1;\n\$s = <<<EOT\nhello \$o\nEOT;\n";
    $mentions = "<?php\n\$o = 1;\n\$s = <<<EOT\nhello \$o FOO\nEOT;\n";
    $isHd = fn($b) => count(array_filter($b, fn($x) => str_starts_with($x, 'heredoc-interpolation:'))) > 0;
    $w = ws(['a.php' => $a, 'v.php' => $unrelated]);
    $w->close('v.php');
    if ($isHd($w->renameBlockers('a.php', $p[0], $p[1])) || $isHd($w->renameBlockers('a.php', $q[0], $q[1]))) { return 'unrelated blocks'; }
    $w = ws(['a.php' => $a, 'v.php' => $mentions]);
    $w->close('v.php');
    if (!$isHd($w->renameBlockers('a.php', $p[0], $p[1]))) { return 'mentioning file does not block (case-insensitive)'; }
    if ($isHd($w->renameBlockers('a.php', $q[0], $q[1]))) { return 'mentioning Foo blocks a rename of run'; }
    $w = ws(['a.php' => $a . "\$s = <<<EOT\nx \$a\nEOT;\n"]);
    return $isHd($w->renameBlockers('a.php', $q[0], $q[1])) ?: 'cursor-file heredoc ignored';
});

check('#519 a parse-error file blocks only a symbol it mentions; cursor file always', function () {
    $a = "<?php\nclass Foo { public function run() {} }\n\$f = new Foo();\n";
    $p = where($a, 'Foo', 1);
    $has = fn($b) => count(array_filter($b, fn($x) => str_starts_with($x, 'parse-error-region:'))) > 0;
    $w = ws(['a.php' => $a, 'bad.php' => "<?php\n\$x = ;\nbar(;\n"]);
    if ($has($w->renameBlockers('a.php', $p[0], $p[1]))) { return 'unrelated error blocks'; }
    $w = ws(['a.php' => $a, 'bad.php' => "<?php\n\$x = ;\nnew foo(;\n"]);
    if (!$has($w->renameBlockers('a.php', $p[0], $p[1]))) { return 'mentioning error file does not block'; }
    $w = ws(['a.php' => $a . "bar(;\n"]);
    return $has($w->renameBlockers('a.php', $p[0], $p[1])) ?: 'cursor-file error ignored';
});

check('#521 magic methods and implicit built-in interface methods are rename blockers', function () {
    $a = "<?php\nclass K implements Countable, ArrayAccess, IteratorAggregate, JsonSerializable {\n"
        . "    public function __construct() {}\n    public function __toString(): string { return ''; }\n"
        . "    public function count(): int { return 0; }\n"
        . "    public function offsetGet(mixed \$o): mixed { return 1; }\n    public function offsetSet(mixed \$o, mixed \$v): void {}\n"
        . "    public function offsetExists(mixed \$o): bool { return true; }\n    public function offsetUnset(mixed \$o): void {}\n"
        . "    public function getIterator(): Traversable { return new ArrayIterator([]); }\n"
        . "    public function jsonSerialize(): mixed { return 1; }\n    public function plain() {}\n}\n"
        . "class It implements Iterator { function current(): mixed { return 1; } function key(): mixed { return 1; } function next(): void {} function rewind(): void {} function valid(): bool { return false; } }\n"
        . "class NoIface { public function count() {} public function current() {} }\n"
        . "function f(K \$k) { \$k->count(); \$k->plain(); }\nfunction g(NoIface \$n) { \$n->count(); }\n";
    $w = ws(['a.php' => $a]);
    $bl = function (string $needle, int $nth = 1, int $off = 0) use ($w, $a): array { $p = where($a, $needle, $nth, $off); return $w->renameBlockers('a.php', $p[0], $p[1]); };
    $magic = fn(array $b) => in_array('magic-method', $b, true);
    $impl = fn(array $b, string $i) => in_array('implicit-interface-method:' . $i, $b, true);
    $bad = [];
    if (!$magic($bl('__construct'))) { $bad[] = '__construct'; }
    if (!$magic($bl('__toString'))) { $bad[] = '__toString'; }
    foreach ([['count()', 'Countable'], ['offsetGet', 'ArrayAccess'], ['offsetSet', 'ArrayAccess'], ['offsetExists', 'ArrayAccess'], ['offsetUnset', 'ArrayAccess'], ['getIterator', 'IteratorAggregate'], ['jsonSerialize', 'JsonSerializable']] as [$n, $i]) {
        if (!$impl($bl($n), $i)) { $bad[] = $n; }
    }
    foreach (['current', 'key', 'next', 'rewind', 'valid'] as $n) {
        if (!$impl($bl('function ' . $n, 1, 9), 'Iterator')) { $bad[] = $n; }
    }
    if (!$impl($bl('count()', 3), 'Countable')) { $bad[] = 'count use site'; }
    $plain = array_filter($bl('plain'), fn($x) => $x === 'magic-method' || str_starts_with($x, 'implicit-interface-method'));
    $no = array_filter($bl('count() {} public function current'), fn($x) => $x === 'magic-method' || str_starts_with($x, 'implicit-interface-method'));
    if ($plain !== [] || $no !== []) { $bad[] = 'false positive ' . json_encode([$plain, $no]); }
    return $bad === [] ? true : implode(',', $bad);
});
exit($fail);
