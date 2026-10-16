<?php
function mk(int $n): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= chr(($i * 37 + 200) & 255); }
    return $s;
}
foreach ([0, 1, 2, 3, 4, 5, 7, 8, 9, 15, 16, 17, 100, 4097] as $n) {
    $s = mk($n);
    $h = bin2hex($s);
    echo $n, ' ', substr($h, 0, 24), ' ', strlen($h), ' ', md5($h), "\n";
    var_dump(hex2bin($h) === $s, hex2bin(strtoupper($h)) === $s);
}
var_dump(bin2hex("\x00\xff\x7f\x80"));
var_dump(hex2bin("00ff7f80"));
