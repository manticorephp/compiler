<?php
$sizes = [0, 1, 7, 8, 9, 15, 16, 17, 100, 4097];
$alpha = "ab Cd\n\r\t \0\x0B'\"\\\xC3\xA9\xFF\x80zZ-.";
foreach ($sizes as $n) {
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= $alpha[($i * 7 + ($i >> 3)) % strlen($alpha)]; }
    $p = "  \t\n" . $s . "\0\x0B \r";
    echo $n, " ", md5(trim($p)), " ", md5(ltrim($p)), " ", md5(rtrim($p)), " ", md5(trim($p, "a..z \t")), " ", md5(trim($p, "b")), " ", md5(trim($s, "Z-.")), "\n";
}
var_dump(trim("  abc  "), trim("abc", "a..c"), trim("", "abc"), ltrim("0012", "0"), rtrim("1.500", "0"));
