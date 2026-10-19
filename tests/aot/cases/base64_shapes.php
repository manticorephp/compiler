<?php
mt_srand(42);
foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 31, 32, 33, 47, 48, 49, 63, 64, 65, 100, 255, 256, 1000, 4097, 100000] as $n) {
    $s = ''; for ($i = 0; $i < $n; $i++) { $s .= chr(mt_rand(0, 255)); }
    $e = base64_encode($s);
    echo $n, ' ', strlen($e), ' ', md5($e), ' ', base64_decode($e) === $s ? 'rt' : 'BAD', ' ', base64_decode($e, true) === $s ? 'st' : 'BAD', "\n";
}
foreach (['', 'f', 'fo', 'foo', 'foob', 'fooba', 'foobar', "\0", "\0\0\0", "\xff\xff\xff", "\xff", "Man", "ManMan", "ManManM", "ManManMa", "ManManMan"] as $s) { echo base64_encode($s), ' ', bin2hex(base64_decode(base64_encode($s))), "\n"; }
// decode: junk, whitespace, padding, strict mode
foreach (['Zm9v', 'Zm9vYg==', 'Zm9vYg=', 'Zm9vYg', 'Zm9v YmFy', "Zm9v\nYmFy\r\n", 'Zm9v!YmFy', 'Z', 'Zg', 'Zg=', 'Zg==', 'Zg===', '====', '=Zg==', 'Zg==Zg==', 'Zm9v=YmFy', "Zm\x009v", 'Zm9vYmFyZm9vYmFy', '-_-_', '+/+/', '', ' ', 'A', 'AA', 'AAA', 'AAAA'] as $s) {
    echo json_encode($s), ' ', json_encode(base64_decode($s)), ' ', json_encode(base64_decode($s, true)), "\n";
}
