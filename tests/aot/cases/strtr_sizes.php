<?php
function mk(int $n): string {
    $s = '';
    for ($i = 0; $i < $n; $i++) { $s .= chr(($i * 37 + 11) & 255); }
    return $s;
}
foreach ([0, 1, 2, 3, 4, 5, 7, 8, 9, 15, 16, 17, 100, 4097] as $n) {
    $s = mk($n);
    echo $n, ' ', md5(strtr($s, "\x0b\x30\xff", "ab\x00")), ' ', md5(strtr($s, "\x0b\x30\xff", "abcdef")), ' ',
         md5(strtr($s, "\x0b\x0b\x30", "xyz")), ' ', md5(strtr($s, "", "abc")), ' ', md5(strtr($s, "abc", "")), "\n";
    echo md5(strtr($s, ["\x0b" => 'AB', "\x0b\x30" => 'LONG', "\xff\xff" => '', "z" => 'Q', "5" => 'five'])), ' ',
         md5(strtr($s, ["\x0b\x30" => 'X', "\x0b" => 'Y'])), "\n";
}
echo strtr("Hi all, I said", ["Hi" => "Hello", "Hello" => "x", "a" => "A"]), "\n";
echo strtr("aaa", ["a" => "b", "aa" => "c"]), "\n";
echo strtr("abc", ["abc" => "abcabc"]), "|", strtr("abc", []), "\n";
echo strtr("12345", [1 => "a", "23" => "b"]), "\n";
