<?php
$sizes = [0, 1, 7, 8, 9, 15, 16, 17, 100, 4097];
$alpha = "ab Cd\n\r\t \0\x0B'\"\\\xC3\xA9\xFF\x80zZ-.";
foreach ($sizes as $n) {
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= $alpha[($i * 7 + ($i >> 3)) % strlen($alpha)]; }
    echo $n, " ", md5(strrev($s)), " ", strrev(strrev($s)) === $s ? "ok" : "BAD", "\n";
}
