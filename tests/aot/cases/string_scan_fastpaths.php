<?php
$hs = ["", "a", "abc", "a\nb\r\n  \tx", "  x  ", "\0 x \0", "xyzxyz", "\n\n", "\xA0\xA0y"];
$ns = ["", "a", "x", "\n", "\r", "xy", "zx", "yz", "abc", "q"];
foreach ($hs as $h) {
    foreach ($ns as $n) {
        foreach ([0, 1, -1, -2, 3, 9] as $o) {
            if (abs($o) <= strlen($h)) { var_dump(strrpos($h, $n, $o)); }
        }
        var_dump(str_contains($h, $n));
    }
    foreach ([" \t\xA0", "x", "", "\0"] as $m) {
        foreach ([0, 1, -1, 4, 20] as $o) {
            if (abs($o) <= strlen($h)) { var_dump(strspn($h, $m, $o)); }
        }
        if ($m !== "") { var_dump(strpbrk($h, $m)); }
    }
    var_dump(trim($h), ltrim($h), rtrim($h), trim($h, "x\n"), ltrim($h, ""), rtrim($h, "xyz"));
}
