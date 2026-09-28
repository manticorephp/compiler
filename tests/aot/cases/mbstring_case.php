<?php
// mbstring step 4: case mapping (Zend's own tables, special casing, final sigma,
// Turkish i in ISO-8859-9), case-insensitive search, display width.
$t = "ΟΔΥΣΣΕΥΣ ΚΑΙ Σ'ΑΓΑΠΩ — straße ǅungla ﬁnal ŉ İstanbul ı i hello o'neil";
foreach ([MB_CASE_UPPER, MB_CASE_LOWER, MB_CASE_TITLE, MB_CASE_FOLD, MB_CASE_UPPER_SIMPLE, MB_CASE_LOWER_SIMPLE,
    MB_CASE_TITLE_SIMPLE, MB_CASE_FOLD_SIMPLE] as $m) {
    echo $m, ": ", mb_convert_case($t, $m), "\n";
}
echo mb_strtoupper("привіт, світе"), " ", mb_strtolower("ПРИВІТ ΣΟΦΟΣ"), "\n";
echo mb_convert_case(str_repeat("α", 63) . "Σ", MB_CASE_LOWER) === str_repeat("α", 63) . "ς" ? "sigma ok" : "sigma bad", "\n";
echo bin2hex(mb_strtoupper("i", "ISO-8859-9")), " ", bin2hex(mb_strtolower("I\xDD", "ISO-8859-9")), "\n";
echo mb_ucfirst("ǆemal"), " ", mb_ucfirst("ßa"), " ", mb_lcfirst("ÉCOLE"), " ", mb_ucfirst(""), "|\n";
var_dump(mb_stripos("Straße STRASSE", "strasse"), mb_strripos("ÀÉÎõü àéîÕÜ", "éî"), mb_stristr("Hello WÖRLD", "wö"),
    mb_strrichr("a/B/c/b", "B", true), mb_stripos("abc", "", 1), mb_stripos("ΣΑΣ", "σ", -1));
echo mb_strwidth("日本語 abc ｱｲｳ Ｘ"), " ", mb_strimwidth("日本語テキストです", 0, 10, "…"), " ",
    mb_strimwidth("Hello World", 2, 6, ".."), " ", mb_strimwidth("abc", 0, 2, "long marker"), "\n";
foreach ([fn() => mb_convert_case("x", 8), fn() => mb_strimwidth("abc", 5, 1), fn() => mb_stripos("abc", "a", 9)] as $f) {
    try { var_dump($f()); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
}
