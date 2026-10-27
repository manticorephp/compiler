<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Analyzer;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\ParsedFile;
use Analyze\Report;

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

/** Diagnostics of $src, parsed with the given parser flags. @return Diagnostic[] */
function diagsOf(string $src, bool $ranges = true, bool $names = true): array
{
    $pf = new ParsedFile('t.php', \Parser\Parser::parseTolerant($src, 't.php', $ranges, $names)->program);
    $index = Index::build([$pf]);
    $index->addExternFunctions([]);
    return (new Analyzer())->runFile($pf, $index, true);
}

/** Byte offset of 1-based (line, byte column); lines split on \n only. */
function offsetOf(string $src, int $line, int $col): int
{
    $off = 0;
    for ($l = 1; $l < $line; $l++) { $off = strpos($src, "\n", $off) + 1; }
    return $off + $col - 1;
}

function slice(string $src, Diagnostic $d): string
{
    $a = offsetOf($src, $d->startLine, $d->startCol);
    return substr($src, $a, offsetOf($src, $d->endLine, $d->endCol) - $a);
}

/** @return array<string,string> code => slice (first diagnostic per code) */
function slices(string $src, array $diags): array
{
    $out = [];
    foreach ($diags as $d) {
        if (!isset($out[$d->code])) { $out[$d->code] = slice($src, $d); }
    }
    return $out;
}

$SRC = <<<'PHP'
<?php
class Foo { const K = 1; }
function f(int $a): int { return $a; }
function g(array $x): void {}
function h(): void { return 1; }
function r(): int { return []; }
f(1, 2);
f([1]);
$n = "abc" + 1;
undefined_fn(3);
$o = new Foo;
$o->nope();
new Missing();
echo Foo::NOPE;
PHP;

check('ranged_parse_gives_end_for_each_rule', function () use ($SRC) {
    $got = slices($SRC, diagsOf($SRC));
    $want = [
        'arg.count' => 'f(1, 2)',
        'arg.type' => '[1]',
        'return.void' => 'return 1;',
        'return.type' => 'return [];',
        'arith.string' => '"abc" + 1',
        'undefined.function' => 'undefined_fn(3)',
        'undefined.method' => '$o->nope()',
        'undefined.class' => 'new Missing()',
        'undefined.constant' => 'Foo::NOPE',
        'array.no-value-type' => 'array $x',
    ];
    foreach ($want as $code => $text) {
        if (!isset($got[$code])) { return "no $code diagnostic"; }
        if ($got[$code] !== $text) { return "$code slice " . json_encode($got[$code]) . ' != ' . json_encode($text); }
    }
    foreach (diagsOf($SRC) as $d) {
        if ($d->endLine < 1 || $d->endCol < 1) { return "$d->code has no end"; }
    }
    return true;
});

check('unranged_parse_keeps_zero_ends', function () use ($SRC) {
    $ds = diagsOf($SRC, false, false);
    if (count($ds) < 9) { return 'too few diagnostics: ' . count($ds); }
    foreach ($ds as $d) {
        if ($d->endLine !== 0 || $d->endCol !== 0) { return "$d->code has an end"; }
    }
    return true;
});

check('report_json_unchanged', function () use ($SRC) {
    $ds = diagsOf($SRC);
    $legacy = [];
    foreach ($ds as $d) { $legacy[] = new Diagnostic($d->file, $d->line, $d->col, $d->severity, $d->code, $d->message); }
    return Report::json($ds) === Report::json($legacy) && Report::human($ds) === Report::human($legacy)
        ?: 'ranged output differs from legacy';
});

check('byte_columns_with_bom_crlf_multibyte', function () {
    $src = "\xEF\xBB\xBF<?php\r\n// é ü\r\nfunction f(int \$a) {}\r\n/* ж */ f(1, 2);\r\n";
    $ds = diagsOf($src);
    $d = null;
    foreach ($ds as $x) { if ($x->code === 'arg.count') { $d = $x; } }
    if ($d === null) { return 'no arg.count'; }
    $call = strpos($src, 'f(1, 2)');
    $lineStart = strrpos(substr($src, 0, $call), "\n") + 1;
    $wantCol = $call - $lineStart + 1;
    $got = [$d->startLine, $d->startCol, $d->endLine, $d->endCol];
    $want = [4, $wantCol, 4, $wantCol + strlen('f(1, 2)')];
    return $got === $want ?: json_encode($got) . ' != ' . json_encode($want);
});

exit($fail);
