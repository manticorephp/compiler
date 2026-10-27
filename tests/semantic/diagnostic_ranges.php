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
function diagsOf(string $src, bool $ranges = true, bool $names = true, int $declNames = 1): array
{
    $pf = new ParsedFile('t.php', \Parser\Parser::parseTolerant($src, 't.php', $ranges, $names, $declNames)->program);
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

/** Slice of the first diagnostic with $code. */
function sliceOf(string $src, string $code, bool $ranges = true, bool $names = true): ?string
{
    foreach (diagsOf($src, $ranges, $names) as $d) {
        if ($d->code === $code) { return slice($src, $d); }
    }
    return null;
}

/** @return string[] slices of every diagnostic with $code */
function allSlices(string $src, string $code): array
{
    $out = [];
    foreach (diagsOf($src) as $d) {
        if ($d->code === $code) { $out[] = slice($src, $d); }
    }
    return $out;
}

check('undefined_extends_range_is_the_name', function () {
    $s = "<?php\nclass A extends Nope { public function m() {} }\n";
    $g = allSlices($s, 'undefined.class');
    return $g === ['Nope'] ?: json_encode($g);
});

check('undefined_implements_range_is_the_name', function () {
    $s = "<?php\ninterface I {}\nclass A implements I, Gone, \\Also\\Gone { }\n";
    $g = allSlices($s, 'undefined.class');
    return $g === ['Gone', '\\Also\\Gone'] ?: json_encode($g);
});

check('undefined_trait_use_range_is_the_name', function () {
    $s = "<?php\nclass A {\n    public function m() { return 1; }\n    use NoTrait;\n}\n";
    $g = allSlices($s, 'undefined.class');
    return $g === ['NoTrait'] ?: json_encode($g);
});

check('undefined_catch_range_is_the_class_name', function () {
    $s = "<?php\ntry { f(); } catch (Ok | Missing \$e) { }\nfunction f() {}\nclass Ok {}\n";
    $g = allSlices($s, 'undefined.class');
    return $g === ['Missing'] ?: json_encode($g);
});

check('missing_array_return_range_is_narrow', function () {
    $s = "<?php\nfunction build(): array\n{\n    return [1];\n}\nclass K { public function items(): array { return []; } }\n";
    $g = allSlices($s, 'array.no-value-type');
    return $g === ["build", "public function items(): array { return []; }"] ?: json_encode($g);
});

check('cli_mode_keeps_old_ranges_and_anchors', function () {
    $s = "<?php\nclass A extends Nope { }\nfunction build(): array { return []; }\ntry { } catch (Missing \$e) { }\n";
    foreach (diagsOf($s, false, false) as $d) {
        if ($d->endLine !== 0 || $d->endCol !== 0) { return "$d->code has an end"; }
    }
    $a = array_map(fn($d) => [$d->code, $d->line, $d->col], diagsOf($s, false, false));
    $w = [['array.no-value-type', 3, 1], ['undefined.class', 2, 1], ['undefined.class', 4, 1]];
    return $a === $w ?: json_encode($a);
});

check('anchors_unchanged_in_workspace_mode', function () {
    $s = "<?php\ninterface I {}\nclass A extends Nope implements I, Gone { use T; }\nfunction build(): array { return []; }\ntry { } catch (Missing \$e) { }\n";
    $key = fn($ds) => json_encode(array_map(fn($d) => [$d->code, $d->line, $d->col, $d->message], $ds));
    $on = diagsOf($s, true, true);
    $off = diagsOf($s, true, false, 0);
    if (count($on) < 5) { return 'too few diagnostics: ' . count($on); }
    return $key($on) === $key($off) ?: $key($on) . ' != ' . $key($off);
});

check('trait_use_range_skips_earlier_mention_in_property_type', function () {
    $s = "<?php\nclass A {\n    public Gone \$p;\n    use Gone;\n}\n";
    $ds = array_values(array_filter(diagsOf($s), fn($d) => $d->code === 'undefined.class'));
    if (count($ds) !== 1) { return 'count ' . count($ds); }
    return ($ds[0]->startLine === 4 && slice($s, $ds[0]) === 'Gone') ?: "line " . $ds[0]->startLine . " " . json_encode(slice($s, $ds[0]));
});

check('trait_use_range_skips_earlier_param_type_and_attribute', function () {
    $s = "<?php\n#[Attribute]\nclass At {}\nclass A {\n    #[Gone] public int \$q = 1;\n    public function m(Gone \$x) {}\n    use Gone;\n}\n";
    $ds = array_values(array_filter(diagsOf($s), fn($d) => $d->code === 'undefined.class' && $d->message === 'unknown class Gone'));
    $uses = array_values(array_filter($ds, fn($d) => $d->startLine === 7));
    if (count($uses) !== 1) { return 'no diagnostic on the use line: ' . json_encode(array_map(fn($d) => [$d->startLine, $d->startCol], $ds)); }
    return slice($s, $uses[0]) === 'Gone' ?: json_encode(slice($s, $uses[0]));
});

check('implements_range_skips_earlier_mention_in_attribute', function () {
    $s = "<?php\n#[Gone]\nclass A implements Gone {\n    #[Gone] public int \$q = 1;\n}\n";
    $ds = array_values(array_filter(diagsOf($s), fn($d) => $d->code === 'undefined.class'));
    foreach ($ds as $d) {
        if ($d->startLine === 3) { return ($d->startCol === 20 && slice($s, $d) === 'Gone') ?: "range " . $d->startLine . ':' . $d->startCol . ' ' . json_encode(slice($s, $d)); }
    }
    return 'no diagnostic on the header';
});

check('second_catch_of_same_class_gets_its_own_range', function () {
    $s = "<?php\ntry { } catch (Gone \$e) { } catch (Gone \$f) { }\n";
    $ds = array_values(array_filter(diagsOf($s), fn($d) => $d->code === 'undefined.class'));
    if (count($ds) !== 2) { return 'count ' . count($ds); }
    $first = strpos($s, 'Gone');
    $second = strpos($s, 'Gone', $first + 1);
    $col = fn($o) => $o - strrpos(substr($s, 0, $o), "\n");
    return ($ds[0]->startCol === $col($first) && $ds[1]->startCol === $col($second) && slice($s, $ds[1]) === 'Gone')
        ?: json_encode([$ds[0]->startCol, $ds[1]->startCol, $col($first), $col($second)]);
});

exit($fail);
