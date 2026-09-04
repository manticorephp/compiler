<?php
// php's `+` keeps the LEFT side's value for a key present on both sides. The
// union helper asked `__mir_array_isset_str` whether the result already had the
// key — with two of the callee's four arguments missing, so it read garbage for
// `hash`/`haveHash`, always answered "absent", and let the right side overwrite.

$a = ['k' => 1];
$b = ['k' => 5, 'j' => 6];
echo json_encode($a + $b), "\n";
echo json_encode($b + $a), "\n";

$acc = ['k' => 1];
$acc += ['k' => 5, 'j' => 6];
echo json_encode($acc), "\n";

// Int keys never renumber and the left side still wins.
echo json_encode([1, 2] + [10, 20, 30]), "\n";

// Mixed key kinds, several shared.
$l = ['x' => 'L', 0 => 'L0', 'y' => 'Ly'];
$r = ['x' => 'R', 0 => 'R0', 'z' => 'Rz', 1 => 'R1'];
echo json_encode($l + $r), "\n";

// An empty left side copies the right verbatim; an empty right adds nothing.
echo json_encode([] + ['k' => 'v']), "\n";
echo json_encode(['k' => 'v'] + []), "\n";

// Nested arrays as values are taken from the left where the key collides.
echo json_encode(['n' => [1, 2]] + ['n' => [9], 'm' => [8]]), "\n";
