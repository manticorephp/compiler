<?php
// substr_count / str_replace over word boundaries, binary bytes, edge args.
$sizes = [0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 31, 32, 33, 63, 64, 65];
foreach ($sizes as $n) {
    $s = str_repeat("ab\0c\xff", intdiv($n, 5) + 1);
    $s = substr($s, 0, $n);
    echo $n, ': ', substr_count($s, 'a'), ' ', substr_count($s, "\0"), ' ', substr_count($s, "b\0"),
        ' ', substr_count($s, "\xff"), ' ', substr_count($s, 'zz'), ' ', substr_count($s, $s === '' ? 'q' : $s), "\n";
    $c = 0;
    $r = str_replace('b', 'XYZ', $s, $c);
    echo bin2hex($r), ' ', $c, "\n";
    $r = str_replace("\0", '', $s, $c);
    echo bin2hex($r), ' ', $c, "\n";
    $r = str_replace(["a", "c\xff"], ['', '!!'], $s, $c);
    echo bin2hex($r), ' ', $c, "\n";
}
$big = str_repeat("The quick brown fox, jumps over the lazy dog.\n", 2300);
echo strlen($big), ' ', substr_count($big, 'o'), ' ', substr_count($big, 'the'), ' ', substr_count($big, "dog.\nThe"), "\n";
$c = 0;
$r = str_replace('fox', 'cat', $big, $c);
echo strlen($r), ' ', $c, ' ', md5($r), "\n";
$r = str_replace('o', '', $big, $c);
echo strlen($r), ' ', $c, ' ', md5($r), "\n";
echo substr_count('aaaa', 'aa'), substr_count('abcabc', 'bc', 2), substr_count('abcabc', 'bc', -4), substr_count('abcabc', 'bc', 1, 3), substr_count('abcabc', 'bc', 0, -1), "\n";
foreach ([['abc', '', 0, null], ['abc', 'a', 4, null], ['abc', 'a', -4, null], ['abc', 'a', 0, 4], ['abc', 'a', 1, -3]] as [$h, $n, $o, $l]) {
    try { echo substr_count($h, $n, $o, $l), "\n"; } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
}
echo str_replace('', 'x', 'abc'), '|', str_replace('abcd', 'x', 'abc'), '|', str_replace('a', 'bb', 'aaa'), "\n";
print_r(str_replace('a', 'b', ['x' => 'aa', 'y' => 'ca'], $c)); echo $c, "\n";
echo str_replace(['a', 'b'], 'b', 'ab', $c), $c, "\n";
echo str_replace("a\0b", 'X', "1a\0b2a\0b", $c), $c, "\n";
