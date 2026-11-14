<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Analyzer;
use Analyze\Index;
use Analyze\ParsedFile;
use Analyze\Report;

function pf(string $path, string $src): ParsedFile
{
    return new ParsedFile($path, \Parser\Parser::parseTolerant($src, $path)->program);
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

$A = pf('a.php', '<?php namespace N; function f(int $a) {} class Foo { function m() {} } f(1, 2, 3); (new Foo)->nope(); new Missing();');
$B = pf('b.php', '<?php namespace N; function g(int $a) {} g(); undefined_fn();');

check('run_equals_runfile_loop', function () use ($A, $B) {
    foreach ([false, true] as $undef) {
        $got = (new Analyzer())->run([$A, $B], [], $undef);
        $index = Index::build([$A, $B]);
        $index->addExternFunctions([]);
        $want = [];
        foreach ([$A, $B] as $p) {
            foreach ((new Analyzer())->runFile($p, $index, $undef) as $d) { $want[] = $d; }
        }
        $want = Report::sortDiags($want);
        if (count($got) === 0) { return "empty diagnostics (undef=" . (int) $undef . ")"; }
        if (serialize($got) !== serialize($want)) { return "mismatch (undef=" . (int) $undef . ")"; }
    }
    return true;
});

/** @return array<string, int> code => count, for runFile over the fixtures */
function codes(bool $undef): array
{
    global $A, $B;
    $index = Index::build([$A, $B]);
    $index->addExternFunctions([]);
    $out = [];
    foreach ([$A, $B] as $p) {
        foreach ((new Analyzer())->runFile($p, $index, $undef) as $d) { $out[$d->code] = ($out[$d->code] ?? 0) + 1; }
    }
    return $out;
}

check('every_rule_is_wired_into_run_file', function () {
    $on = codes(true);
    foreach (['arg.count', 'undefined.method', 'undefined.class', 'undefined.function'] as $code) {
        if (!isset($on[$code])) { return "missing $code: " . json_encode($on); }
    }
    return ($on['arg.count'] === 2) ?: 'arg.count count ' . json_encode($on);
});

check('closed_world_rules_are_off_without_check_undefined', function () {
    $off = codes(false);
    foreach (['undefined.class', 'undefined.function'] as $code) {
        if (isset($off[$code])) { return "$code present with checkUndefined=false"; }
    }
    return (isset($off['arg.count']) && isset($off['undefined.method'])) ?: json_encode($off);
});
exit($fail);
