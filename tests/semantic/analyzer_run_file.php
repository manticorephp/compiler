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

exit($fail);
