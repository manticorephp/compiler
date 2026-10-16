<?php
function check(string $s, int $n): void
{
    $r = str_repeat($s, $n);
    $slow = '';
    for ($i = 0; $i < $n; $i++) { $slow .= $s; }
    echo strlen($r), ' ', $r === $slow ? 'ok' : 'DIFF', ' ', md5($r), "\n";
}
foreach (['', 'a', 'ab', "\x12\x34\x56\x78", 'abcde', 'x7 chars'] as $s) {
    foreach ([0, 1, 2, 3, 4, 5, 7, 8, 9, 15, 16, 17, 100, 1000, 4097] as $n) {
        check($s, $n);
    }
}
try { str_repeat('a', -1); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
