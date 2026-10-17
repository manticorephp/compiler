<?php
$all = ".\\+*?[^]\$(){}=!<>|:-#/~@%&'\"` \t\n\r\0\x01\xff";
foreach (['', 'a', 'ab', 'a.b', '.', '..', $all, 'abc-def', "a\0b", "\0", "x#y#z", "no specials here at all"] as $s) {
    foreach (['', '/', '#', '~x', '.', 'a'] as $d) { echo bin2hex(preg_quote($s, $d)), "\n"; }
}
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    foreach (['a', '.', 'a.'] as $unit) {
        $s = substr(str_repeat($unit, $n), 0, $n);
        $q = preg_quote($s, '/');
        echo $n, ' ', strlen($q), ' ', md5($q), "\n";
    }
}
echo preg_quote(str_repeat("The quick (brown) fox. ", 3000) . "end") === preg_quote(str_repeat("The quick (brown) fox. ", 3000) . "end") ? "same\n" : "diff\n";
