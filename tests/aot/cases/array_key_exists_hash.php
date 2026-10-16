<?php
// array_key_exists: one hash probe for every key kind and array representation.

function id(mixed $x): mixed { return $x; }

/** @param array $a */
function erased(array $a, mixed $k): bool { return array_key_exists($k, $a); }

$a = [0 => 'zero', 'a' => null, '' => 'empty', 7 => null, '12' => 'twelve', '012' => 'lead'];
foreach ([0, 7, 12, 3, 'a', 'b', '', '7', '12', '012', '0', '-0', '1.5'] as $k) {
    echo var_export($k, true), ' ', var_export(array_key_exists($k, $a), true), ' ',
        var_export(array_key_exists(id($k), $a), true), ' ', var_export(erased($a, $k), true), "\n";
}

// mixed keys: bool, null and float take php's key cast
$m = id($a);
foreach ([true, false] as $k) {
    echo var_export($k, true), ' ', var_export(array_key_exists(id($k), $m), true), "\n";
}
$n = id(null);
echo 'null ', var_export(@array_key_exists($n, $a), true), ' ', var_export(@array_key_exists($n, [1]), true), "\n";
$f = id(7.9);
echo 'float ', var_export(@array_key_exists($f, $a), true), ' ', var_export(@isset($a[$f]), true), "\n";

// packed vec, null elements
$v = [null, 1, null];
echo var_export(array_key_exists(0, $v), true), var_export(array_key_exists(2, $v), true),
    var_export(array_key_exists(3, $v), true), var_export(array_key_exists(-1, $v), true), "\n";
echo var_export(array_key_exists(0, []), true), "\n";

// big arrays: hashed int and string keys, holes from unset
$big = [];
for ($i = 0; $i < 200000; $i++) { $big['k' . $i] = $i % 3 === 0 ? null : $i; }
$ints = [];
for ($i = 0; $i < 200000; $i++) { $ints[$i * 3] = null; }
unset($big['k5'], $ints[9]);
$hits = 0;
for ($i = 0; $i < 200000; $i++) {
    if (array_key_exists('k' . $i, $big)) { $hits++; }
    if (array_key_exists($i, $ints)) { $hits++; }
    if (array_key_exists((string)$i, $ints)) { $hits++; }
}
echo $hits, "\n";

// array behind a mixed / nested cell
$nest = id(['x' => ['y' => null], 5 => [6 => 0]]);
echo var_export(array_key_exists('y', $nest['x']), true), var_export(array_key_exists(6, $nest[5]), true),
    var_export(array_key_exists('z', $nest['x']), true), "\n";

// a fresh temp array and a fresh string key
echo var_export(array_key_exists('k' . 1, ['k1' => null]), true), var_export(array_key_exists(2, range(0, 5)), true), "\n";

// dynamic call reaches the stdlib body
$fn = 'array_key_exists';
echo var_export($fn('a', $a), true), var_export($fn('7', $a), true), var_export($fn('q', $a), true), "\n";
