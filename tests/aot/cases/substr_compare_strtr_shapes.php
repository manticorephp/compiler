<?php
$hs = ['', 'a', 'abc', 'ABC', 'abcdef', "a\0b", "\xff\xfe", 'hello world'];
$ns = ['', 'a', 'b', 'abc', 'ABC', 'bcd', 'abcdefg', "\0b", "\xff", 'world'];
foreach ($hs as $h) {
    foreach ($ns as $n) {
        foreach ([0, 1, 2, -1, -3, 6, 20] as $o) {
            foreach ([null, 0, 1, 3, 10] as $l) {
                foreach ([false, true] as $ci) {
                    try { echo substr_compare($h, $n, $o, $l, $ci) <=> 0, ' '; } catch (ValueError $e) { echo 'V '; }
                }
            }
        }
        echo "\n";
    }
}
// strtr: pairs
$pairs = [
    [], ['a' => 'b'], ['ab' => 'x', 'a' => 'y', 'abc' => 'z'], ['fox' => 'cat', 'dog' => 'x', 'quick' => 'slow'],
    ['<' => '&lt;', '>' => '&gt;'], ['a' => ''], ['aa' => 'b', 'a' => 'c'], ["\0" => 'nul', "\xff" => 'ff'], [1 => 'one', 22 => 'tt'],
];
$subs = ['', 'a', 'aa', 'aaa', 'abc', 'abcabc', 'The quick brown fox, jumps over the lazy dog.', "a\0b\xffc", '<a>&</a>', '122 1 22'];
foreach ($pairs as $p) { foreach ($subs as $s) { echo bin2hex(strtr($s, $p)), "\n"; } }
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    $s = substr(str_repeat("The quick brown fox, jumps over the lazy dog.\n", intdiv($n, 40) + 1), 0, $n);
    $r = strtr($s, ['fox' => 'cat', 'dog' => 'x', 'quick' => 'slow', "\n" => '<br>']);
    echo $n, ' ', strlen($r), ' ', md5($r), "\n";
}
echo strtr('Hi all', 'ai', 'eo'), strtr('abc', 'ab', 'x'), strtr('abc', '', 'x'), "\n";
// more than 8 keys: the first-byte bucket path
$many = ['fox' => 'cat', 'dog' => 'x', 'quick' => 'slow', 'The' => 'A', 'brown' => 'red', 'jumps' => 'hops', 'over' => 'under', 'lazy' => 'idle', 'the' => 'a', 'qu' => 'Q', 'o' => '0', "\n" => '<br>'];
foreach ($subs as $s) { echo bin2hex(strtr($s, $many)), "\n"; }
foreach ([0, 1, 2, 3, 7, 8, 9, 15, 16, 17, 63, 64, 65, 100000] as $n) {
    $s = substr(str_repeat("The quick brown fox, jumps over the lazy dog.\n", intdiv($n, 40) + 1), 0, $n);
    $r = strtr($s, $many);
    echo $n, ' ', strlen($r), ' ', md5($r), "\n";
}
