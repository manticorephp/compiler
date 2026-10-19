<?php
// urlencode / rawurlencode / urldecode / rawurldecode over block boundaries and every byte.
$all = '';
for ($i = 0; $i < 256; $i++) { $all .= chr($i); }
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 31, 32, 33, 63, 64, 65] as $n) {
    $s = substr(str_repeat($all, 1), 0, $n);
    $t = substr(str_repeat("a b~c-d_e.f\xff\0%+/", 8), 0, $n);
    foreach ([$s, $t] as $x) {
        $e = urlencode($x); $r = rawurlencode($x);
        echo $n, ' ', $e, ' ', $r, ' ', bin2hex(urldecode($e)) === bin2hex($x) ? 'ok' : 'BAD', ' ', bin2hex(rawurldecode($r)) === bin2hex($x) ? 'ok' : 'BAD', "\n";
    }
}
echo urlencode($all), "\n", rawurlencode($all), "\n";
foreach (['%', '%4', '%41', '%4g', '%g1', 'a%2', 'a%2B+b', '%%41', '+%2b+', '%41%', "%\0", '%e9%FF%0a', '100%', 'x%zz%41'] as $d) {
    echo bin2hex(urldecode($d)), ' ', bin2hex(rawurldecode($d)), "\n";
}
$big = str_repeat("The quick brown fox, jumps over the lazy dog. \xc3\xa9&=?\n", 2000);
echo strlen($big), ' ', md5(urlencode($big)), ' ', md5(rawurlencode($big)), ' ', md5(urldecode(urlencode($big))) === md5($big) ? 'ok' : 'BAD', "\n";
echo md5(urldecode(str_repeat('%41+%zz', 20000))), "\n";
