<?php

require __DIR__ . '/bootstrap.php';

use Analyze\Analyzer;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\ParsedFile;

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

/** @return Diagnostic[] */
function diagsOf(string $src, bool $ranges, bool $names): array
{
    $pf = new ParsedFile('t.php', \Parser\Parser::parseTolerant($src, 't.php', $ranges, $names, $names ? 1 : 0)->program);
    $index = Index::build([$pf]);
    $index->addExternFunctions([]);
    return (new Analyzer())->runFile($pf, $index, true);
}

function slice(string $src, Diagnostic $d): string
{
    $lines = explode("\n", $src);
    $off = 0;
    for ($l = 1; $l < $d->startLine; $l++) { $off += strlen($lines[$l - 1]) + 1; }
    $a = $off + $d->startCol - 1;
    $b = $a;
    if ($d->endLine === $d->startLine) { $b = $off + $d->endCol - 1; }
    return substr($src, $a, $b - $a);
}

function byCode(array $diags, string $code): ?Diagnostic
{
    foreach ($diags as $d) { if ($d->code === $code) { return $d; } }
    return null;
}

$SRC = "<?php\n\$x = new stdClass;\necho \"é \\\" {\$x->y(new Nope)} and\n{\$x->z(undef_fn(2))}\";\n";

check('class_inside_interpolation_has_real_range', function () use ($SRC) {
    $d = byCode(diagsOf($SRC, true, true), 'undefined.class');
    if ($d === null) { return 'no undefined.class'; }
    if ($d->startLine !== 3 || $d->startCol !== 20) { return "start $d->startLine:$d->startCol"; }
    return slice($SRC, $d) === 'new Nope' ? true : json_encode(slice($SRC, $d));
});

check('function_inside_interpolation_has_real_range', function () use ($SRC) {
    $d = byCode(diagsOf($SRC, true, true), 'undefined.function');
    if ($d === null) { return 'no undefined.function'; }
    if ($d->startLine !== 4 || $d->startCol !== 8) { return "start $d->startLine:$d->startCol"; }
    return slice($SRC, $d) === 'undef_fn(2)' ? true : json_encode(slice($SRC, $d));
});

check('unranged_parse_keeps_legacy_anchors', function () use ($SRC) {
    $out = [];
    foreach (diagsOf($SRC, false, false) as $d) { $out[] = "$d->code@$d->startLine:$d->startCol"; }
    sort($out);
    return implode(' ', $out) === 'undefined.class@1:7 undefined.function@1:7' ? true : implode(' ', $out);
});

exit($fail);
