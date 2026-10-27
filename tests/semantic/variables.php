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

/** @return int[] [line, col] (1-based, bytes) of the $nth occurrence of $needle */
function where(string $src, string $needle, int $nth = 1, int $off = 0): array
{
    $at = -1;
    for ($i = 0; $i < $nth; $i++) { $at = strpos($src, $needle, $at + 1); if ($at === false) { throw new \RuntimeException("no '$needle' #$nth"); } }
    $before = substr($src, 0, $at);
    $nl = strrpos($before, "\n");
    return [1 + substr_count($before, "\n"), $at - ($nl === false ? -1 : $nl) + $off];
}

/** "line:col" list of the references of the variable at the $nth occurrence of $needle */
function refs(string $src, string $needle, int $nth = 1, string $uri = 'a.php', ?Workspace $w = null): string
{
    $w = $w ?? ws([$uri => $src]);
    $p = where($src, $needle, $nth);
    $o = [];
    foreach ($w->referencesTo($uri, $p[0], $p[1]) as $l) { $o[] = $l->line . ':' . $l->col; }
    return implode(' ', $o);
}

/** the line:col of each needle occurrence, +offset, as a "a b c" list */
function at(string $src, string $needle, array $nths, int $off = 0): string
{
    $o = [];
    foreach ($nths as $n) { $p = where($src, $needle, $n, $off); $o[] = $p[0] . ':' . $p[1]; }
    return implode(' ', $o);
}

function uncertain(Workspace $w, string $src, string $needle, int $nth = 1, string $uri = 'a.php'): array
{
    $p = where($src, $needle, $nth);
    $o = [];
    foreach ($w->uncertainSitesFor($uri, $p[0], $p[1]) as $s) { $o[] = $s->file . ':' . $s->line . ':' . $s->col . ':' . $s->reason; }
    sort($o);
    return $o;
}

$P = "<?php\nfunction f(int \$a, \$b) {\n    \$c = \$a + 1;\n    return \$a * \$c + \$b;\n}\nfunction g(\$a) {\n    return \$a;\n}\n";

check('param -> declaration and uses', function () use ($P) {
    $want = at($P, '$a', [1, 2, 3]);
    $got = refs($P, '$a', 2);
    $d = ws(['a.php' => $P])->definitionOf('a.php', ...where($P, '$a', 3));
    return $got === $want && $d !== null && $d->line === 2 && $d->col === 16 ? true : "$got | $want | " . json_encode($d);
});

check('shadowing across two functions is isolated', function () use ($P) {
    $g = refs($P, '$a', 4);
    return $g === at($P, '$a', [4, 5]) ? true : $g;
});

check('reassigned var: every assignment and use', function () {
    $s = "<?php\nfunction f() {\n    \$x = 1;\n    \$x = \$x + 1;\n    \$x .= 'a';\n    return \$x;\n}\n";
    return refs($s, '$x', 3) === at($s, '$x', [1, 2, 3, 4, 5]) ? true : refs($s, '$x', 3);
});

check('same name in unrelated methods is isolated', function () {
    $s = "<?php\nclass A {\n    public function m(\$v) { return \$v; }\n    public function n(\$v) { return \$v + 1; }\n}\n";
    $r = refs($s, '$v', 1);
    return $r === at($s, '$v', [1, 2]) && refs($s, '$v', 4) === at($s, '$v', [3, 4]) ? true : $r;
});

$C = "<?php\nfunction f() {\n    \$x = 1;\n    \$h = function (\$y) use (\$x, &\$z) { \$z = \$x + \$y; return \$x; };\n    \$z = 0;\n    \$k = fn(\$q) => \$x + \$q;\n    return \$x;\n}\n";

check('closure use: outer renames use entry and inner uses', function () use ($C) {
    $got = refs($C, '$x', 1);
    return $got === at($C, '$x', [1, 2, 3, 4, 5, 6]) ? true : "$got | " . at($C, '$x', [1, 2, 3, 4, 5, 6]);
});

check('closure use: inner use resolves to the outer binding', function () use ($C) {
    $got = refs($C, '$x', 3);
    $d = ws(['a.php' => $C])->definitionOf('a.php', ...where($C, '$x', 3));
    return $got === at($C, '$x', [1, 2, 3, 4, 5, 6]) && $d->line === 3 ? true : $got;
});

check('closure use by reference shares the binding', function () use ($C) {
    $got = refs($C, '$z', 1);
    $want = at($C, '$z', [1, 2, 3]);
    return $got === $want && refs($C, '$z', 3) === $want ? true : "$got | $want";
});

check('closure param is its own binding', function () use ($C) {
    return refs($C, '$y', 1) === at($C, '$y', [1, 2]) ? true : refs($C, '$y', 1);
});

check('use-list entry range covers $name only (by-ref too)', function () use ($C) {
    $w = ws(['a.php' => $C]);
    $p = where($C, '$z', 1);
    foreach ($w->referencesTo('a.php', $p[0], $p[1]) as $l) {
        if ($l->line === $p[0] && $l->col === $p[1] && $l->endCol === $p[1] + 2) { return true; }
    }
    return 'no exact $z range';
});

check('arrow fn captures outer var; its param shadows', function () use ($C) {
    $got = refs($C, '$q', 1);
    return $got === at($C, '$q', [1, 2]) && refs($C, '$x', 5) === at($C, '$x', [1, 2, 3, 4, 5, 6]) ? true : $got;
});

check('nested arrow fns reach the outermost binding', function () {
    $s = "<?php\nfunction f(\$a) {\n    \$g = fn(\$b) => fn(\$c) => \$a + \$b + \$c;\n    return \$a;\n}\n";
    $w = ws(['a.php' => $s]);
    $want = at($s, '$a', [1, 2, 3]);
    return refs($s, '$a', 2, 'a.php', $w) === $want && refs($s, '$b', 2, 'a.php', $w) === at($s, '$b', [1, 2]) ? true : refs($s, '$a', 2);
});

check('static fn opens no $this link; $this is special', function () {
    $s = "<?php\nclass A {\n    public function m() {\n        \$f = fn() => \$this;\n        return \$this;\n    }\n}\n";
    $w = ws(['a.php' => $s]);
    $p = where($s, '$this', 2);
    $bl = $w->renameBlockers('a.php', $p[0], $p[1]);
    return in_array('special-variable', $bl, true) && $w->definitionOf('a.php', $p[0], $p[1]) === null
        && refs($s, '$this', 2, 'a.php', $w) === at($s, '$this', [1, 2]) ? true : json_encode($bl);
});

check('foreach key/value and nested destructuring, skip element filtered', function () {
    $s = "<?php\nfunction f(\$rows) {\n    foreach (\$rows as \$k => [\$x, , \$y]) { echo \$k, \$x, \$y; }\n    [\$p, [\$q]] = \$rows;\n    list(\$m, \$n) = \$rows;\n    return \$p + \$q + \$m + \$n;\n}\n";
    $w = ws(['a.php' => $s]);
    foreach (['$k' => 2, '$x' => 2, '$y' => 2, '$p' => 2, '$q' => 2, '$m' => 2, '$n' => 2] as $v => $cnt) {
        $n = count(explode(' ', refs($s, $v, 1, 'a.php', $w)));
        if ($n !== $cnt) { return "$v has $n"; }
    }
    $r = refs($s, '$rows', 1, 'a.php', $w);
    return count(explode(' ', $r)) === 4 ? true : $r;
});

check('catch variable', function () {
    $s = "<?php\nfunction f() {\n    try { g(); } catch (\\Exception \$e) { echo \$e->getMessage(); }\n    try { g(); } catch (\\Exception \$e) { echo \$e; }\n}\n";
    $w = ws(['a.php' => $s]);
    return refs($s, '$e', 1, 'a.php', $w) === at($s, '$e', [1, 2, 3, 4]) ? true : refs($s, '$e', 1, 'a.php', $w);
});

check('static local', function () {
    $s = "<?php\nfunction f() {\n    static \$n = 0;\n    \$n++;\n    return \$n;\n}\nfunction g() { static \$n; return \$n; }\n";
    return refs($s, '$n', 2) === at($s, '$n', [1, 2, 3]) ? true : refs($s, '$n', 2);
});

check('global statement binds {main}; sibling functions share it', function () {
    $s = "<?php\n\$g = 1;\nfunction f() { global \$g; return \$g; }\nfunction h() { global \$g; \$g++; }\nfunction k(\$g) { return \$g; }\n";
    $w = ws(['a.php' => $s]);
    $want = at($s, '$g', [1, 2, 3, 4, 5]);
    $k = refs($s, '$g', 6, 'a.php', $w);
    return refs($s, '$g', 1, 'a.php', $w) === $want && $k === at($s, '$g', [6, 7]) ? true : refs($s, '$g', 1, 'a.php', $w) . " | $k";
});

check('global across files is uncertain (global-scope), never guessed', function () {
    $a = "<?php\n\$g = 1;\n";
    $b = "<?php\nfunction f() { global \$g; return \$g; }\n";
    $w = ws(['a.php' => $a, 'b.php' => $b]);
    $u = uncertain($w, $a, '$g', 1, 'a.php');
    $u2 = uncertain($w, $b, '$g', 2, 'b.php');
    $r = refs($a, '$g', 1, 'a.php', $w);
    return $u === ['b.php:2:23:global-scope', 'b.php:2:34:global-scope'] && in_array('a.php:2:1:global-scope', $u2, true) && $r === '2:1' ? true : json_encode([$u, $u2, $r]);
});

check('superglobals are not renameable', function () {
    $s = "<?php\nfunction f() { return \$_GET['a'] . \$_GET['b']; }\nfunction g() { return \$GLOBALS['x']; }\n";
    $w = ws(['a.php' => $s]);
    $p = where($s, '$_GET', 2);
    return in_array('special-variable', $w->renameBlockers('a.php', $p[0], $p[1]), true)
        && $w->definitionOf('a.php', $p[0], $p[1]) === null
        && in_array('special-variable', $w->renameBlockers('a.php', ...where($s, '$GLOBALS')), true) ? true : 'no blocker';
});

check('compact / extract / func_get_args / variable-variable are dynamic-variable sites', function () {
    $s = "<?php\nfunction f(\$a, \$b) {\n    \$z = compact('a');\n    return \$a + \$b;\n}\nfunction g(\$a) { extract(\$_GET); return \$a; }\nfunction h(\$a) { \$n = 'a'; \$\$n = 2; return \$a; }\nfunction i(\$a) { return func_get_args(); }\nfunction j(\$a) { return \$a; }\n";
    $w = ws(['a.php' => $s]);
    $fa = uncertain($w, $s, '$a', 1);
    $fb = uncertain($w, $s, '$b', 1);
    $lit = where($s, "'a'", 1);
    return $fa === ['a.php:' . $lit[0] . ':' . $lit[1] . ':dynamic-variable'] && $fb === []
        && count(uncertain($w, $s, '$a', 3)) === 1 && count(uncertain($w, $s, '$a', 4)) === 1
        && count(uncertain($w, $s, '$a', 5)) >= 1 && count(uncertain($w, $s, '$a', 7)) === 1 && uncertain($w, $s, '$a', 8) === []
        && in_array('dynamic-variable:a.php:' . $lit[0] . ':' . $lit[1], $w->renameBlockers('a.php', ...where($s, '$a', 1)), true) ? true : json_encode([$fa, $fb]);
});

check('compact with a non-literal argument taints the whole unit', function () {
    $s = "<?php\nfunction f(\$a, \$names) {\n    return compact(\$names) + [\$a];\n}\n";
    $w = ws(['a.php' => $s]);
    return count(uncertain($w, $s, '$a', 1)) === 1 && count(uncertain($w, $s, '$names', 1)) === 1 ? true : 'missing';
});

check('include inside a unit makes its bindings include-scope uncertain', function () {
    $s = "<?php\nfunction f(\$a) {\n    include 'x.php';\n    return \$a;\n}\nfunction g(\$a) { return \$a; }\n";
    $w = ws(['a.php' => $s]);
    $u = uncertain($w, $s, '$a', 1);
    return count($u) === 1 && str_ends_with($u[0], ':include-scope') && uncertain($w, $s, '$a', 3) === [] ? true : json_encode($u);
});

check('interpolation "$a {$a}" has per-part locations', function () {
    $s = "<?php\nfunction f(\$a) {\n    return \"x \$a y {\$a} z \$a[0]\";\n}\n";
    $r = refs($s, '$a', 1);
    return count(explode(' ', $r)) === 4 && substr_count($r, '3:') === 3 ? true : $r;
});

check('heredoc interpolation is an uncertain site, not a location', function () {
    $s = "<?php\nfunction f(\$a) {\n    return <<<EOT\n    hi \$a\n    EOT;\n}\n";
    $w = ws(['a.php' => $s]);
    $p = where($s, '$a', 1);
    $u = uncertain($w, $s, '$a', 1);
    $bl = $w->renameBlockers('a.php', $p[0], $p[1]);
    return count(explode(' ', refs($s, '$a', 1, 'a.php', $w))) === 1 && count($u) === 1 && str_contains($u[0], 'heredoc-interpolation')
        && count(array_filter($bl, fn($b) => str_starts_with($b, 'heredoc-interpolation:'))) === 1 ? true : json_encode([$u, $bl]);
});

check('named arguments are not variables', function () {
    $s = "<?php\nfunction f(int \$a) { return \$a; }\nfunction g() { \$a = 1; return f(a: \$a); }\n";
    return refs($s, '$a', 3) === at($s, '$a', [3, 4]) ? true : refs($s, '$a', 3);
});

check('promoted param: property family plus constructor variable uses', function () {
    $s = "<?php\nclass A {\n    public function __construct(private int \$x) { if (\$x > 0) { \$this->x = \$x; } }\n    public function get() { return \$this->x; }\n}\n";
    $w = ws(['a.php' => $s]);
    $p = where($s, '$x', 1);
    $fromDecl = [];
    foreach ($w->referencesTo('a.php', $p[0], $p[1]) as $l) { $fromDecl[] = $l->line . ':' . $l->col; }
    sort($fromDecl);
    $exp = explode(' ', at($s, '$x', [1, 2, 3]));
    $exp[] = '3:' . (strpos(explode("\n", $s)[2], '->x') + 3);
    $exp[] = '4:' . (strpos(explode("\n", $s)[3], '->x') + 3);
    sort($exp);
    // the member decl and the variable decl share one range
    $u = array_values(array_unique($fromDecl));
    $d = $w->definitionOf('a.php', $p[0], $p[1]);
    $varUse = where($s, '$x', 2);
    $vr = [];
    foreach ($w->referencesTo('a.php', $varUse[0], $varUse[1]) as $l) { $vr[] = $l->line . ':' . $l->col; }
    $bl = $w->renameBlockers('a.php', $varUse[0], $varUse[1]);
    return $u == $exp && count($vr) === 3 && in_array('promoted-parameter', $bl, true) && $d !== null ? true : json_encode([$fromDecl, $exp, $vr, $bl]);
});

check('file with parse errors: no throw, positions after the error resolve', function () {
    $s = "<?php\nfunction f(\$a) {\n    \$x = ;\n    return \$a + 1;\n}\nfunction g(\$b) { return \$b; }\n";
    $w = ws(['a.php' => $s]);
    return refs($s, '$a', 1, 'a.php', $w) === at($s, '$a', [1, 2]) && refs($s, '$b', 2, 'a.php', $w) === at($s, '$b', [1, 2]) ? true : 'wrong';
});

check('update drops the cached index', function () {
    $s = "<?php\nfunction f(\$a) { return \$a; }\n";
    $w = ws(['a.php' => $s]);
    $before = count($w->referencesTo('a.php', ...where($s, '$a')));
    $s2 = "<?php\nfunction f(\$a) { return \$a + \$a; }\n";
    $w->update('a.php', $s2);
    $after = count($w->referencesTo('a.php', ...where($s2, '$a')));
    $w->close('a.php');
    return $before === 2 && $after === 3 && $w->referencesTo('a.php', 2, 14) === [] ? true : "$before $after";
});

check('position that is not a variable: no variable answer', function () {
    $s = "<?php\nfunction f(\$a) { return 1; }\n";
    $w = ws(['a.php' => $s]);
    return $w->referencesTo('a.php', 1, 1) === [] && $w->definitionOf('a.php', 1, 1) === null ? true : 'unexpected';
});

check('perf: variable query on a 3000-line file p50 < 5 ms', function () {
    $src = "<?php\n";
    for ($i = 0; $i < 300; $i++) {
        $src .= "function fn$i(int \$n, \$m): int {\n    \$a = \$n + 1;\n    \$b = \$a + \$m;\n    if (\$n > 3) {\n        \$b = \$b + \$a;\n    }\n    \$f = fn(\$q) => \$q + \$b;\n    foreach ([1, 2, 3] as \$x) {\n        \$b += \$x;\n    }\n    return \$b + \$f(\$a);\n}\n";
    }
    $w = ws(['a.php' => $src]);
    $w->referencesTo('a.php', 2, 17); // build the index
    $times = [];
    for ($i = 0; $i < 60; $i++) {
        $p = where($src, '$b + $a', 1 + (($i * 7) % 300));
        $t0 = hrtime(true);
        $r = $w->referencesTo('a.php', $p[0], $p[1]);
        $times[] = (hrtime(true) - $t0) / 1e6;
        if (count($r) !== 6) { return 'wrong count ' . count($r); }
    }
    sort($times);
    echo sprintf("  lines=%d p50=%.3f ms max=%.3f ms\n", substr_count($src, "\n"), $times[30], $times[59]);
    return $times[30] < 5 ? true : "p50 {$times[30]} ms";
});

exit($fail);
