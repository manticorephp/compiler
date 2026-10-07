<?php
// A literal of ROWS spliced in through `mixed $replacement` must survive the
// call: array_splice appends each row without a reference of its own, so a
// cell rebuild that MOVED the literal's rows freed them under the result.
/** @param list<array{0: mixed, 1: int}> $diff */
function w(array $diff): string {
    \array_splice($diff, \count($diff), 0, [["\nNL\n", 4]]);
    $out = '';
    foreach ($diff as $e) { $out .= $e[1] . ':' . \trim((string)$e[0]) . ' '; }
    return $out;
}
echo w([['a', 0], ['b', 1]]), "\n";
/** @param list<array{0: string, 1: int}> $d */
function w2(array $d): string { \array_splice($d, 1, 0, [['x', 9]]); $o = ''; foreach ($d as $e) { $o .= $e[1] . $e[0]; } return $o; }
echo w2([['a', 0], ['b', 1]]), "\n";
function w3(array $d): string { \array_splice($d, 1, 0, [['x', 9]]); $o = ''; foreach ($d as $e) { $o .= $e[1] . $e[0]; } return $o; }
echo w3([['a', 0], ['b', 1]]), "\n";
