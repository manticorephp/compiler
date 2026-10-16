<?php
function mk(int $n): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= chr(($i * 37 + 11) & 255); }
    return $s;
}
foreach ([0, 1, 2, 3, 4, 5, 7, 8, 9, 15, 16, 17, 100, 4097] as $n) {
    $s = mk($n);
    $e = base64_encode($s);
    echo $n, ' ', $e === '' ? '-' : substr($e, 0, 24), ' ', strlen($e), ' ', md5($e), "\n";
    var_dump(base64_decode($e) === $s, base64_decode($e, true) === $s);
}
var_dump(base64_decode("QUJD\nREVG\r\n GHI=", true));
var_dump(base64_decode("QU*JD", true));
var_dump(base64_decode("QU*JD"));
var_dump(base64_decode("QUJD=REVG"));
var_dump(base64_decode("QQ"));
var_dump(base64_decode(""));
foreach (["QQ==", "QQ=", "QQ", "Q", "QUI=", "QUI==", "QUJD=", "QQ==QQ==", "QQ== ", " QQ ==", "QU\tJD", "QU\x0bJD", "====", "=", "QQ=A", "QUJDRA=", "QUJDRA=="] as $x) {
    var_dump(base64_decode($x, true), base64_decode($x));
}
