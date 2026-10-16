<?php
foreach ([[0, 0], [0, 1], [1, 0], [0, 6], [3, 3], [-3, 3], [3, -3], [0, 17], [0, 999], [1000, 0]] as [$a, $b]) {
    echo $a, ' ', $b, ' ', md5(json_encode(range($a, $b))), ' ', (abs($a - $b) >= 2 ? md5(json_encode(range($a, $b, 2))) : '-'), ' ', (abs($a - $b) >= 7 ? md5(json_encode(range($a, $b, 7))) : '-'), "\n";
}
echo json_encode(range(1, 10, 3)), json_encode(range(10, 1, 3)), json_encode(range(0, 1, 1)), json_encode(range(5, 5)), "\n";
echo json_encode(range(1.0, 4.0)), json_encode(range(0, 1, 0.25)), json_encode(range('a', 'e')), json_encode(range('e', 'a', 2)), "\n";
$r = range(1, 5); $r[] = 6; $r[0] = 'x'; $q = range(1, 5);
echo json_encode($r), json_encode($q), count(range(1, 100000)), array_sum(range(1, 100000)), "\n";
$big = range(PHP_INT_MAX - 3, PHP_INT_MAX);
echo json_encode($big), "\n";
foreach (range(1, 3) as $i) { echo $i; }
echo "\n", implode(',', range(0, 20, 5)), ' ', max(range(3, 9)), ' ', json_encode(array_reverse(range(1, 4))), "\n";
function ri(int $a, int $b, int $s): array { return range($a, $b, $s); }
echo json_encode(ri(1, 10, 4)), json_encode(ri(10, 1, 4)), json_encode(range(3, 1)), json_encode(range(-2, -5)), "\n";
try { range(0, PHP_INT_MAX); } catch (ValueError $e) { echo "ValueError\n"; }
