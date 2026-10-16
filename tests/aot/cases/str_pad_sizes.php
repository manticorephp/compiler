<?php
foreach ([0, 1, 2, 3, 4, 5, 7, 8, 9, 15, 16, 17, 100, 4097] as $n) {
    foreach ([STR_PAD_RIGHT, STR_PAD_LEFT, STR_PAD_BOTH] as $t) {
        foreach (['x', 'ab', 'xyz'] as $p) {
            $r = str_pad('hey', $n, $p, $t);
            echo $n, ' ', $t, ' ', $p, ' ', strlen($r), ' ', md5($r), "\n";
        }
    }
}
var_dump(str_pad('abc', 2), str_pad('', 3, '-'));
