<?php
foreach (['', 'a', 'ab', 'abc', "a\0b\xff", 'hello world'] as $s) {
    foreach ([1, 2, 3, 5, 100] as $l) { echo json_encode(array_map('bin2hex', str_split($s, $l))), "\n"; }
}
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    $s = substr(str_repeat("\x00ab\xff,", intdiv($n, 4) + 1), 0, $n);
    foreach ([1, 2, 7, 64] as $l) {
        $p = str_split($s, $l);
        echo $n, ' ', $l, ' ', count($p), ' ', md5(implode('|', $p)), ' ', ($p ? strlen($p[count($p) - 1]) : -1), ' ', implode('', $p) === $s ? 'ok' : 'bad', "\n";
    }
}
try { str_split('abc', 0); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
