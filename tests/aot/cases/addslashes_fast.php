<?php
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 31, 32, 33, 63, 64, 65] as $n) {
    foreach (["abcdefghij", "ab'cd\"ef\\gh\0ij", "'", "\\\\", "\0\0\0", "plain text only ", "\xff\xfe'\x80"] as $unit) {
        $s = substr(str_repeat($unit, intdiv($n, strlen($unit)) + 1), 0, $n);
        echo $n, ' ', bin2hex(addslashes($s)), "\n";
    }
}
$big = str_repeat("The quick brown fox, it's \"jumping\" over\\the lazy dog\0.\n", 2000);
echo strlen(addslashes($big)), ' ', md5(addslashes($big)), ' ', md5(addslashes(str_repeat('x', 100001))), "\n";
