<?php
// base64 / hex codecs across block boundaries, binary bytes, junk and padding.
$all = '';
for ($i = 0; $i < 256; $i++) { $all .= chr($i); }
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 15, 16, 17, 31, 32, 33, 63, 64, 65, 255, 256] as $n) {
    $s = substr(str_repeat($all, 2), 0, $n);
    $e = base64_encode($s);
    $h = bin2hex($s);
    echo $n, ' ', $e, ' ', $h === '' ? '-' : substr($h, 0, 40), ' ',
        base64_decode($e) === $s ? 'ok' : 'BAD', ' ', base64_decode($e, true) === $s ? 'ok' : 'BAD', ' ',
        hex2bin($h) === $s ? 'ok' : 'BAD', ' ', hex2bin(strtoupper($h)) === $s ? 'ok' : 'BAD', "\n";
}
foreach (['QUJD', 'QUJDRA==', 'QUJDRA', 'QU JD\nRA==', 'QUJ=DA', '=QUJD', 'QUJDRA===', 'Q', 'QU', 'QUJ', '!!!!', 'QUJD!QUJD', "QUJD\r\nQUJD", 'QUJDQUJDQUJD', 'QUJDQUJDQ=', ' QUJD', 'QUJD ', 'QUJDRA==QUJD'] as $d) {
    $l = base64_decode($d); $st = base64_decode($d, true);
    echo json_encode($d), ' ', bin2hex($l), ' ', $st === false ? 'F' : bin2hex($st), "\n";
}
foreach (['', '00', 'aF', 'ABCDEFabcdef0123456789', 'ff00'] as $x) {
    try { echo bin2hex(hex2bin($x)), "\n"; } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
$big = str_repeat($all, 400);
echo strlen($big), ' ', md5(base64_encode($big)), ' ', md5(base64_decode(base64_encode($big))) === md5($big) ? 'ok' : 'BAD',
    ' ', md5(bin2hex($big)), ' ', md5(hex2bin(bin2hex($big))) === md5($big) ? 'ok' : 'BAD', "\n";
