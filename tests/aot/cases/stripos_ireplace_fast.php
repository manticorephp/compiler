<?php
// stripos / str_ireplace across vector-block boundaries, offsets, binary bytes.
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 31, 32, 33, 63, 64, 65] as $n) {
    $s = substr(str_repeat("xyAbCdE\0\xff@[`{z", 8), 0, $n);
    echo $n, ': ';
    foreach (['abc', 'ABC', 'cde', "\0\xff", '@', '[', '`', '{', 'Z', 'zx', 'q', $s === '' ? 'a' : $s] as $needle) {
        foreach ([0, 1, 5, $n, -1, -3, -$n] as $off) {
            if ($off > $n || $off < -$n) { continue; }
            try { $r = stripos($s, $needle, $off); echo var_export($r, true), ','; }
            catch (ValueError $e) { echo 'E,'; }
        }
    }
    echo "\n";
    $c = 0;
    echo bin2hex(str_ireplace('ABC', '--', $s, $c)), ' ', $c, ' ', bin2hex(str_ireplace('d', 'DDD', $s, $c)), ' ', $c,
        ' ', bin2hex(str_ireplace(['a', 'C'], ['1', '22'], $s, $c)), ' ', $c, ' ', bin2hex(str_ireplace('Z', 'q', $s, $c)), ' ', $c, "\n";
}
$big = str_repeat("The quick brown fox, jumps over the lazy dog.\n", 2300);
$o = 0; $k = 0;
while (($p = stripos($big, 'LAZY', $o)) !== false) { $k++; $o = $p + 1; }
echo $k, ' ', stripos($big, "DOG.\nthe", 100), ' ', stripos($big, 'FOX', 99990), "\n";
$c = 0;
$r = str_ireplace('FOX', 'cats', $big, $c); echo strlen($r), ' ', $c, ' ', md5($r), "\n";
$r = str_ireplace('o', '', $big, $c); echo strlen($r), ' ', $c, ' ', md5($r), "\n";
$r = str_ireplace('the', 'THE', $big, $c); echo strlen($r), ' ', $c, ' ', md5($r), "\n";
echo str_ireplace('', 'x', 'abc'), '|', str_ireplace('ABCD', 'x', 'abc'), '|', str_ireplace('A', 'bb', 'aAa'), "\n";
print_r(str_ireplace('A', 'b', ['x' => 'aa', 'y' => 'cA'], $c)); echo $c, "\n";
echo stripos('abc', ''), stripos('abc', '', 2), "\n";
