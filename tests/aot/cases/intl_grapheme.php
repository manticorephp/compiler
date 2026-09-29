<?php
// ext/intl grapheme_* over the host ICU: clusters, collation-based search, offsets.
$s = "e\u{301}👨‍👩‍👧 नमस्ते Ä\u{308}bc 🇺🇦 x\r\ny";
$cases = [$s, "hello world", "a\r\nb", "", "🇺🇦🇵🇱", "ÅÉÎ", "\xFF\xFE"];
foreach ($cases as $t) {
    echo bin2hex($t), ": ", var_export(grapheme_strlen($t), true), " | ", implode(",", array_map('bin2hex', grapheme_str_split($t) ?: [])), "\n";
}
foreach ([[0, null], [1, 2], [-3, null], [2, -2], [-20, 3], [5, 0], [30, null], [0, 100]] as [$o, $l]) {
    echo "substr($o,", var_export($l, true), "): ", var_export(grapheme_substr($s, $o, $l), true), "\n";
}
foreach (["Ä", "ä", "b", "🇺🇦", "e", "é", "", "zz", "\u{308}"] as $n) {
    echo bin2hex($n), ": ", var_export(grapheme_strpos($s, $n), true), " ", var_export(grapheme_stripos($s, $n), true), " ",
        var_export(grapheme_strrpos($s, $n), true), " ", var_export(grapheme_strripos($s, $n), true), " ",
        var_export(grapheme_strstr($s, $n), true), " ", var_export(grapheme_stristr($s, $n, true), true), "\n";
}
var_dump(grapheme_strpos("abcabc", "b", 2), grapheme_strrpos("abcabc", "b", -3), grapheme_stripos("ABC", "b"), grapheme_strpos($s, "b", -3), grapheme_strrpos($s, "c", -2));
foreach ([[3, GRAPHEME_EXTR_COUNT, 0], [4, GRAPHEME_EXTR_MAXBYTES, 0], [5, GRAPHEME_EXTR_MAXCHARS, 0], [2, GRAPHEME_EXTR_COUNT, 2], [1, GRAPHEME_EXTR_COUNT, -5]] as [$sz, $ty, $st]) {
    $next = -1;
    echo "extract: ", var_export(grapheme_extract($s, $sz, $ty, $st, $next), true), " next=$next\n";
}
var_dump(grapheme_levenshtein("kitten", "sitting"), grapheme_levenshtein("e\u{301}a", "éb"), grapheme_levenshtein("", "abc", 2), grapheme_levenshtein("🇺🇦x", "🇺🇦"));
foreach ([fn() => grapheme_strpos("abc", "a", 9), fn() => grapheme_str_split("x", 0), fn() => grapheme_extract("x", 1, 7), fn() => grapheme_strpos("aé", "é", -5)] as $f) {
    try { var_dump($f()); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
}
