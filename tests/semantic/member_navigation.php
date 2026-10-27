<?php

require __DIR__ . '/bootstrap.php';

use Analyze\ParsedFile;
use Semantic\LibrarySymbols;
use Semantic\Location;
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

/** @param Location[] $ls */
function locs(array $ls): array
{
    $o = [];
    foreach ($ls as $l) { $o[] = $l->file . ':' . $l->line . ':' . $l->col; }
    sort($o);
    return $o;
}

$A = <<<'PHP'
<?php
class A { public function run(): int { return 1; } public int $n = 0; }
class B { public function run(): string { return ''; } }
class P { public function m(): int { return 1; } public static int $sp = 1; const BAR = 2; }
class C extends P { public function m(): int { return 2; } }
interface HasK { const K = 1; }
class Impl implements HasK {}
trait T { public function tm(): int { return 1; } }
class UT { use T; }
enum Suit { case Hearts; case Spades; }
class Q { public function self(): static { return $this; } public function go(): ?A { return null; } public function mk(): Q { return new Q(); } }
function f(A $a, B $b, C $c, $mixed, ?A $maybe, Impl $im, UT $ut, Q $q) {
    $a->run();
    $b->run();
    $c->m();
    $mixed->run();
    $maybe?->run();
    $q->go()?->run();
    $q->self()->go();
    P::$sp;
    P::BAR;
    Impl::K;
    Suit::Hearts;
    $ut->tm();
    (new Q())->mk()->go();
    $a->n;
}
PHP;

check('A::run vs B::run isolation', function () use ($A) {
    $w = ws(['a.php' => $A]);
    [$l, $c] = where($A, 'run', 1, 0); // A::run decl
    $refs = locs($w->referencesTo('a.php', $l, $c));
    $want = [where($A, 'run', 1), where($A, '$a->run', 1, 4)];
    $exp = [];
    foreach ($want as $p) { $exp[] = "a.php:{$p[0]}:{$p[1]}"; }
    // plus nullsafe uses typed ?A / ?A from go()
    $exp[] = 'a.php:' . implode(':', where($A, '$maybe?->run', 1, 9));
    $exp[] = 'a.php:' . implode(':', where($A, '?->run', 2, 3));
    sort($exp);
    return $refs === $exp ? true : json_encode([$refs, $exp]);
});

check('definition of use site -> declaring method', function () use ($A) {
    $w = ws(['a.php' => $A]);
    $p = where($A, '$b->run', 1, 4);
    $d = $w->definitionOf('a.php', $p[0], $p[1]);
    $e = where($A, 'run', 2);
    return $d !== null && $d->line === $e[0] && $d->col === $e[1] ? true : json_encode($d);
});

check('inherited $child->m() resolves to parent, parent refs include override and child-typed call', function () use ($A) {
    $w = ws(['a.php' => $A]);
    $p = where($A, '$c->m', 1, 4);
    $d = $w->definitionOf('a.php', $p[0], $p[1]);
    $own = where($A, 'm()', 2); // C::m decl
    if ($d === null || $d->line !== $own[0] || $d->col !== $own[1]) { return 'child override expected: ' . json_encode($d); }
    $pm = where($A, 'm()', 1);
    $refs = locs($w->referencesTo('a.php', $pm[0], $pm[1]));
    $need = ['a.php:' . $pm[0] . ':' . $pm[1], 'a.php:' . $own[0] . ':' . $own[1], 'a.php:' . $p[0] . ':' . $p[1]];
    sort($need);
    return $refs === $need ? true : json_encode([$refs, $need]);
});

check('inherited member not overridden: declClass is the parent', function () {
    $src = "<?php\nclass P { public function m(): int { return 1; } }\nclass C extends P {}\nfunction f(C \$c) { \$c->m(); }\n";
    $w = ws(['a.php' => $src]);
    $p = where($src, '$c->m', 1, 4);
    $d = $w->definitionOf('a.php', $p[0], $p[1]);
    $e = where($src, 'm()', 1);
    return $d !== null && $d->line === $e[0] && $d->col === $e[1] ? true : json_encode($d);
});

check('unknown receiver is uncertain, never a confirmed reference', function () use ($A) {
    $w = ws(['a.php' => $A]);
    $d = where($A, 'run', 1);
    $refs = locs($w->referencesTo('a.php', $d[0], $d[1]));
    $m = where($A, '$mixed->run', 1, 8);
    if (in_array('a.php:' . $m[0] . ':' . $m[1], $refs, true)) { return 'mixed site confirmed'; }
    $un = [];
    foreach ($w->uncertainSitesFor('a.php', $d[0], $d[1]) as $s) { $un[] = $s->file . ':' . $s->line . ':' . $s->col . ':' . $s->reason; }
    return in_array('a.php:' . $m[0] . ':' . $m[1] . ':unresolved-receiver', $un, true) ? true : json_encode($un);
});

check('enum case definition = case decl; const via interface; static prop; class const', function () use ($A) {
    $w = ws(['a.php' => $A]);
    foreach ([['Suit::Hearts', 'Hearts', 'Hearts', 1], ['Impl::K', 'K', 'const K', 0], ['P::$sp', '$sp', '$sp', 1], ['P::BAR', 'BAR', 'BAR', 1]] as [$use, $name, $decl, $skip]) {
        $u = where($A, $use, 1, strpos($use, $name === '$sp' ? '$' : $name));
        $d = $w->definitionOf('a.php', $u[0], $u[1]);
        $e = where($A, $decl, 1, $skip === 0 ? 6 : 0);
        if ($d === null || $d->line !== $e[0] || $d->col !== $e[1]) { return "$use: " . json_encode($d) . ' want ' . json_encode($e); }
    }
    return true;
});

check('trait member: declClass = trait', function () use ($A) {
    $w = ws(['a.php' => $A]);
    $u = where($A, '$ut->tm', 1, 5);
    $h = $w->hover('a.php', $u[0], $u[1]);
    return $h !== null && $h->text === 'T::tm(): int' ? true : json_encode($h);
});

check('hover: declared signature of a method call', function () {
    $src = "<?php\nclass Baz {}\nclass Foo { public function bar(int \$a, string ...\$r): ?Baz { return null; } }\nfunction f(Foo \$x) { \$x->bar(1); }\n";
    $w = ws(['a.php' => $src]);
    $u = where($src, '$x->bar', 1, 4);
    $h = $w->hover('a.php', $u[0], $u[1]);
    return $h !== null && $h->text === 'Foo::bar(int $a, string ...$r): ?Baz' && $h->range->col === $u[1] ? true : json_encode($h);
});

check('hover on expression = type', function () use ($A) {
    $w = ws(['a.php' => $A]);
    $u = where($A, '$maybe?->run', 1, 1);
    $h = $w->hover('a.php', $u[0], $u[1]);
    return $h !== null && $h->text === '?A' ? true : json_encode($h);
});

check('typeAt: nullsafe call nullable; chains; static; new()->m()', function () use ($A) {
    $w = ws(['a.php' => $A]);
    $t = fn(string $needle, int $off, int $n = 1) => (function () use ($w, $A, $needle, $off, $n) {
        $p = where($A, $needle, $n, $off);
        $ty = $w->typeAt('a.php', $p[0], $p[1]);
        return $ty === null ? 'null' : $ty->toString();
    })();
    $got = [
        $t('$maybe?->run()', 8),   // int|null
        $t('$q->go()?->run()', 11),
        $t('$q->self()->go()', 13),
        $t('(new Q())->mk()->go()', 17),
        $t('$a->n;', 4),
    ];
    $exp = ['?int', '?int', '?A', '?A', 'int'];
    $norm = fn(array $x) => array_map(fn($s) => $s === 'int|null' ? '?int' : $s, $x);
    return $norm($got) === $exp ? true : json_encode($got);
});

check('typeAt: whitespace, EOF, end of line, multibyte: no throw', function () {
    $src = "<?php\n\$s = 'héllo'; \$t = \$s;\n";
    $w = ws(['a.php' => $src]);
    foreach ([[1, 1], [2, 1], [2, 8], [2, 9], [2, 200], [3, 1], [99, 1], [0, 0], [2, 0]] as [$l, $c]) { $w->typeAt('a.php', $l, $c); $w->hover('a.php', $l, $c); }
    $p = where($src, '$s;', 1);
    $t = $w->typeAt('a.php', $p[0], $p[1]);
    return $t !== null && $t->toString() === 'string' ? true : json_encode($t);
});

check('error file: typeAt null in ErrorExpr, later positions fine', function () {
    $src = "<?php\nclass A { public function run(): int { return 1; } }\nfunction f(A \$a) {\n    \$x = ;\n    \$a->run();\n}\n";
    $w = ws(['a.php' => $src]);
    $e = where($src, ';', 3, -2);
    $w->typeAt('a.php', $e[0], $e[1]);
    $p = where($src, '$a->run', 1, 4);
    $t = $w->typeAt('a.php', $p[0], $p[1]);
    $d = $w->definitionOf('a.php', $p[0], $p[1]);
    $r = where($src, 'run', 1);
    return $t !== null && $t->toString() === 'int' && $d !== null && $d->line === $r[0] ? true : json_encode([$t, $d]);
});

check('stale occurrences after update are not served from cache', function () {
    $v1 = "<?php\nclass A { public function run(): int { return 1; } }\nclass B { public function run(): int { return 1; } }\nfunction f(A \$x) { \$x->run(); }\n";
    $w = ws(['a.php' => $v1]);
    $p = where($v1, '$x->run', 1, 4);
    $d1 = $w->definitionOf('a.php', $p[0], $p[1]);
    $v2 = str_replace('function f(A $x)', 'function f(B $x)', $v1);
    $w->update('a.php', $v2);
    $d2 = $w->definitionOf('a.php', $p[0], $p[1]);
    $a = where($v1, 'run', 1);
    $b = where($v1, 'run', 2);
    return $d1->line === $a[0] && $d2->line === $b[0] ? true : json_encode([$d1, $d2]);
});

check('cross-file: class edit elsewhere invalidates resolution', function () {
    $use = "<?php\nfunction f(A \$x) { \$x->run(); }\n";
    $cls = "<?php\nclass A { public function run(): int { return 1; } }\n";
    $w = ws(['u.php' => $use, 'c.php' => $cls]);
    $p = where($use, '$x->run', 1, 4);
    if ($w->definitionOf('u.php', $p[0], $p[1]) === null) { return 'not resolved'; }
    $w->update('c.php', "<?php\nclass A { public function other(): int { return 1; } }\n");
    return $w->definitionOf('u.php', $p[0], $p[1]) === null ? true : 'stale hit';
});

check('references across files + decl site as query', function () {
    $c = "<?php\nclass A { public function run(): int { return 1; } }\n";
    $u = "<?php\nfunction f(A \$x) { \$x->run(); \$x->run(); }\n";
    $w = ws(['c.php' => $c, 'u.php' => $u]);
    $d = where($c, 'run', 1);
    $refs = locs($w->referencesTo('c.php', $d[0], $d[1]));
    return count($refs) === 3 && $refs[0] === 'c.php:2:' . $d[1] ? true : json_encode($refs);
});

check('union receiver: references of either declaration include the site', function () {
    $src = "<?php\nclass A { public function run(): int { return 1; } }\nclass B { public function run(): int { return 1; } }\nfunction f(A|B \$x) { \$x->run(); }\n";
    $w = ws(['a.php' => $src]);
    $a = where($src, 'run', 1);
    $b = where($src, 'run', 2);
    $u = where($src, '$x->run', 1, 4);
    $ra = locs($w->referencesTo('a.php', $a[0], $a[1]));
    $rb = locs($w->referencesTo('a.php', $b[0], $b[1]));
    $s = 'a.php:' . $u[0] . ':' . $u[1];
    return in_array($s, $ra, true) && in_array($s, $rb, true) && count($ra) === 2 ? true : json_encode([$ra, $rb]);
});

check('self / static / parent / $this receivers', function () {
    $src = "<?php\nclass P { public function m(): int { return 1; } public static function s(): int { return 1; } }\nclass C extends P {\n  public function m(): int { return parent::m() + self::s() + static::s() + \$this->m(); }\n}\n";
    $w = ws(['a.php' => $src]);
    $pm = where($src, 'm()', 1);
    $refs = locs($w->referencesTo('a.php', $pm[0], $pm[1]));
    $par = where($src, 'parent::m', 1, 8);
    $ps = where($src, 'self::s', 1, 6);
    $st = where($src, 'static::s', 1, 8);
    $ok = in_array("a.php:{$par[0]}:{$par[1]}", $refs, true);
    $s = where($src, 's()', 1);
    $rs = locs($w->referencesTo('a.php', $s[0], $s[1]));
    return $ok && in_array("a.php:{$ps[0]}:{$ps[1]}", $rs, true) && in_array("a.php:{$st[0]}:{$st[1]}", $rs, true) ? true : json_encode([$refs, $rs]);
});

check('closure $this and static fn', function () {
    $src = "<?php\nclass A { public int \$n = 1; public function run(): int { return 1; }\n  public function g() { \$f = function () { return \$this->run(); }; \$h = static fn() => \$this->run(); } }\n";
    $w = ws(['a.php' => $src]);
    $d = where($src, 'run', 1);
    $refs = locs($w->referencesTo('a.php', $d[0], $d[1]));
    $u1 = where($src, '$this->run', 1, 7);
    $u2 = where($src, '$this->run', 2, 7);
    $s1 = 'a.php:' . $u1[0] . ':' . $u1[1];
    $s2 = 'a.php:' . $u2[0] . ':' . $u2[1];
    return in_array($s1, $refs, true) && !in_array($s2, $refs, true) ? true : json_encode([$refs, $s1, $s2]);
});

check('rename blockers: implicit enum member, owner unknown, library, heredoc', function () {
    $src = "<?php\nenum Suit: string { case H = 'h'; }\nSuit::from('h');\nSuit::H;\nfunction mk() { return new class {}; }\n\$mk = function () { class Hid { public function z() {} } };\nfunction g(Hid \$h, \\Exception \$e) { \$h->z(); \$e->getMessage(); }\n\$o = 1;\n\$s = <<<EOT\nhello \$o->p {\$o->q}\nEOT;\n\$n = <<<'EOT'\nraw \$o\nEOT;\n";
    $w = ws(['a.php' => $src]);
    $b = fn(string $needle, int $off) => (function () use ($w, $src, $needle, $off) { $p = where($src, $needle, 1, $off); return $w->renameBlockers('a.php', $p[0], $p[1]); })();
    $h = 'heredoc-interpolation:a.php:9:6';
    $from = $b('Suit::from', 6);
    $hid = $b("function z", 9);
    $lib = $b('$e->getMessage', 4);
    $plain = $b('Suit::H;', 6);
    $okFrom = in_array('implicit-member', $from, true) && in_array($h, $from, true);
    $okHid = in_array('owner-unknown', $hid, true);
    $okLib = in_array('library-declaration', $lib, true);
    $okPlain = !in_array('implicit-member', $plain, true) && !in_array('owner-unknown', $plain, true) && in_array($h, $plain, true) && count($plain) === 1;
    return $okFrom && $okHid && $okLib && $okPlain ? true : json_encode([$from, $hid, $lib, $plain]);
});

check('heredoc is an uncertain site for any member query; nowdoc is not', function () {
    $src = "<?php\nclass A { public function run(): int { return 1; } }\n\$o = new A();\n\$s = <<<EOT\n{\$o->run()}\nEOT;\n\$n = <<<'N'\n\$o->run()\nN;\n";
    $w = ws(['a.php' => $src]);
    $d = where($src, 'run', 1);
    $un = [];
    foreach ($w->uncertainSitesFor('a.php', $d[0], $d[1]) as $s) { $un[] = $s->line . ':' . $s->col . ':' . $s->reason; }
    return $un === ['4:6:heredoc-interpolation'] ? true : json_encode($un);
});

check('perf: typeAt on a 3000-line file p50 < 10 ms', function () {
    $src = "<?php\nclass K { public function ping(): int { return 1; } public int \$v = 0; }\n";
    for ($i = 0; $i < 300; $i++) {
        $src .= "function fn$i(K \$k, int \$n): int {\n    \$a = \$k->ping();\n    \$b = \$a + \$n;\n    if (\$n > 3) {\n        \$b = \$k->v + \$b;\n    }\n    foreach ([1, 2, 3] as \$x) {\n        \$b += \$x;\n    }\n    \$s = \$k->ping();\n    return \$b + \$s;\n}\n";
    }
    $w = ws(['a.php' => $src]);
    $times = [];
    for ($i = 0; $i < 60; $i++) {
        $p = where($src, '$k->ping()', 1 + (($i * 7) % 600), 1);
        $t0 = hrtime(true);
        $ty = $w->typeAt('a.php', $p[0], $p[1]);
        $times[] = (hrtime(true) - $t0) / 1e6;
        if ($ty === null || $ty->toString() !== 'K') { return 'wrong type ' . json_encode($ty); }
    }
    sort($times);
    $p50 = $times[30];
    echo sprintf("  lines=%d p50=%.3f ms max=%.3f ms\n", substr_count($src, "\n"), $p50, $times[59]);
    return $p50 < 10 ? true : "p50 $p50 ms";
});

exit($fail);
