<?php
// mb_strlen / mb_substr / mb_str_split / mb_strtoupper / mb_strtolower over UTF-8: boundaries, multibyte, invalid bytes
$samples = [
    '', 'a', 'abc', "caf\u{e9}", "\u{e9}\u{e8}\u{ea}", "\u{4e2d}\u{6587}abc", "x\u{1F600}y", "\u{1F600}\u{1F601}",
    "\u{df}stra\u{df}e", "\u{149}", "\u{130}stanbul", "I\u{307}", "\u{3a3}\u{3a3}", "\u{39f}\u{394}\u{3a5}\u{3a3}\u{3a3}EY\u{3a3}",
    "\u{3c3}\u{3c2}", "\u{fb01}ne \u{1c5}", "\u{10428}\u{10400}", "\u{ff21}\u{ff41}",
    "ab\xC3", "\xC3(", "\xE2\x82", "\xF0\x9F\x98", "\xFF\xFE", "a\x80b", "\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80", "\xE0\x80\x80z",
    str_repeat('abc', 30) . "\u{e9}" . str_repeat('xyz', 30),
    str_repeat("\u{e9}\u{4e2d}\u{1F600}a", 40),
    str_repeat("\xC3", 70),
];
foreach ($samples as $i => $s) {
    echo $i, ' ', mb_strlen($s), ' ', bin2hex(mb_strtoupper($s)), ' ', bin2hex(mb_strtolower($s)), "\n";
    foreach ([[0, null], [1, 1], [2, 3], [-2, null], [-3, 2], [1, -1], [100, 5], [0, 0], [3, 100]] as [$st, $ln]) {
        echo '  ', $st, ',', var_export($ln, true), ' ', bin2hex(mb_substr($s, $st, $ln)), "\n";
    }
    echo '  split ', implode('|', array_map('bin2hex', mb_str_split($s))), "\n";
    echo '  split3 ', implode('|', array_map('bin2hex', mb_str_split($s, 3))), "\n";
}
echo mb_strtoupper("abc\u{e9}", 'ISO-8859-1') === "ABC\xC9" ? 'ok' : 'no', "\n";
echo bin2hex(mb_strtoupper("i", 'ISO-8859-9')), "\n";
echo mb_strtoupper("\u{e9}x", 'UTF-8'), mb_convert_case("hello wOrld", MB_CASE_TITLE), "\n";
