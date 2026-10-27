<?php

require __DIR__ . '/bootstrap.php';

ini_set('memory_limit', '2G');

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

function gen(int $n): string
{
    $s = "<?php\nnamespace App;\n";
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $s .= "class C$i {\n public int \$p = 1;\n";
        for ($m = 0; $m < 8; $m++) {
            $s .= " public function m$m(C$j \$o, array \$a): int {\n  \$x = 0;\n  if (\$o->p > 1) { \$x = \$this->m" . (($m + 1) % 8) . "(\$o, \$a); }\n"
                . "  foreach (\$a as \$k => \$v) { \$x += \$v + \$this->p; }\n  \$f = function (\$q) use (\$x) { return \$q + \$x; };\n  return \$o->m0(\$o, \$a) + \$f(1);\n }\n";
        }
        $s .= "}\n";
    }
    return $s;
}

/** @return float ms of the first (cold) referencesTo on a property declaration of an n-class file, best of two. */
function coldRefs(int $n, int &$count): float
{
    static $prelude = null;
    if ($prelude === null) {
        $prelude = [];
        foreach (['core_interfaces.php', 'exceptions.php'] as $f) {
            $src = (string)file_get_contents(dirname(__DIR__, 2) . '/prelude/' . $f);
            $prelude[] = new ParsedFile('lib:prelude/' . $f, \Parser\Parser::parseTolerant($src, $f)->program);
        }
    }
    $src = gen($n);
    $best = INF;
    for ($k = 0; $k < 2; $k++) {
        $w = new Workspace(LibrarySymbols::fromParts($prelude, ['strlen']));
        $w->open('/a.php', $src);
        $t = hrtime(true);
        $count = count($w->referencesTo('/a.php', 4, 15));
        $best = min($best, (hrtime(true) - $t) / 1e6);
    }
    return $best;
}

// Regression: VarIndex::scopeAt scanned every scope for every declaration name (params x scopes), so a cold
// referencesTo on a property grew 4x per doubling (5.5 s at 400 classes).
check('cold referencesTo is not quadratic in the number of classes', function () {
    $c = 0;
    $a = coldRefs(200, $c);
    $b = coldRefs(400, $c);
    if ($c !== 17) { return "unexpected reference count $c"; }
    if ($b > 2000) { return sprintf('400 classes cold %.0f ms (bound 2000)', $b); }
    if ($b / max($a, 1.0) > 3.5) { return sprintf('200 -> 400 classes: %.0f -> %.0f ms (ratio %.2f, bound 3.5)', $a, $b, $b / max($a, 1.0)); }
    return true;
});

exit($fail);
