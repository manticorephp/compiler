<?php

// A local that takes an ARRAY on one path and a CELL (`mixed`) on the other used
// to type UNKNOWN: the `[]` store wrote a raw buffer pointer into a slot the
// foreach then read as a NaN-boxed cell → SIGSEGV. planMergeShadow now boxes the
// array arm, so the slot is uniformly tagged; a cell handed on to a bare-`array`
// param is stripped back to the buffer pointer at the call.

function count_of(mixed $r = []): int {
    $t = [];
    if (is_array($r)) { $t = $r; }
    $n = 0;
    foreach ($t as $x) { $n = $n + 1; }
    return $n;
}
echo count_of(['a', 'b', 'c']), "\n";
echo count_of([]), "\n";
echo count_of('scalar'), "\n";
echo count_of(null), "\n";

// Both arms assign — the array one in the else.
function norm(mixed $m): int {
    if (is_array($m)) { $t = $m; } else { $t = [$m]; }
    return count($t);
}
echo norm([1, 2, 3]), " ", norm('x'), "\n";

// The merged local is then WRITTEN to.
function withTail(mixed $m): array {
    $t = [];
    if (is_array($m)) { $t = $m; }
    $t[] = 'tail';
    return $t;
}
print_r(withTail(['a']));
print_r(withTail(42));

// …and handed to a bare-`array` param (erased to unknown, read as a raw buffer).
function count_all(array $a): int { return count($a); }
function via(mixed $m): int {
    $t = [];
    if (is_array($m)) { $t = $m; }
    return count_all($t);
}
echo via(['p', 'q']), " ", via(null), "\n";

// The array_splice shape that first hit this: a rebuild buffer plus a mixed
// replacement, written back through a by-ref param.
function rebuild(array &$input, mixed $replacement = []): void {
    $out = [];
    foreach ($input as $v) { $out[] = $v; }
    $repl = [];
    if (is_array($replacement)) { $repl = $replacement; } else { $repl = [$replacement]; }
    foreach ($repl as $rv) { $out[] = $rv; }
    $input = $out;
}
$b = [1, 2, 3];
rebuild($b, ['z']);
print_r($b);
$c = [1, 2];
rebuild($c, 'solo');
print_r($c);
