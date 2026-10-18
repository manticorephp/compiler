<?php
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    foreach ([0, 5, -3] as $start) {
        foreach ([1, 'ab', 1.5, null, true, [1, 2]] as $v) {
            $a = array_fill($start, $n, $v);
            echo $n, ' ', $start, ' ', gettype($v), ' ', count($a), ' ', $n ? array_key_first($a) . '..' . array_key_last($a) : '-', ' ', md5(serialize($a)), "\n";
        }
    }
}
$s = 'x'; $a = array_fill(0, 3, $s); $a[0] .= 'y'; echo json_encode($a), $s, "\n";
$arr = [1]; $a = array_fill(2, 2, $arr); $a[2][] = 2; echo json_encode($a), json_encode($arr), "\n";
$o = new stdClass; $a = array_fill(0, 2, $o); $a[0]->p = 1; echo json_encode($a), "\n";
$a = array_fill(0, 3, 7); $a[] = 8; $a[1] = 'z'; echo json_encode($a), array_sum(array_fill(0, 10, 3)), "\n";
try { array_fill(0, -1, 1); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
$b = array_fill(5, 3, 0); $b[] = 1; echo json_encode($b), "\n";
