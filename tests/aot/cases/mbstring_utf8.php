<?php
// mbstring step 1: UTF-8 with Zend's malformed-input rules, single-byte encodings.
$s = "Привіт, 世界 😀!";
echo mb_strlen($s), " ", strlen($s), "\n";
echo mb_substr($s, 8, 2), "|", mb_substr($s, -2), "|", mb_substr($s, 2, -3), "\n";
echo implode(",", mb_str_split("ї中😀ab", 2)), "\n";
var_dump(mb_strpos($s, "世"), mb_strrpos("abcabc", "b", -3), mb_strpos($s, "zz"));
var_dump(mb_strstr($s, "世"), mb_strstr($s, "世", true), mb_strrchr("a/b/c", "/"));
echo mb_substr_count("ааа", "аа"), "\n";
echo mb_str_pad("ї", 5, "中", STR_PAD_BOTH), "|", mb_str_pad("ab", 5, "xyz", STR_PAD_LEFT), "\n";
echo "[", mb_trim("\u{3000} ї \u{00A0}"), "][", mb_ltrim("xxїx", "x"), "][", mb_rtrim("xxїx", "x"), "]\n";
var_dump(mb_ord("ї"), mb_chr(0x1F600), mb_chr(0xD800), mb_check_encoding("ї"), mb_check_encoding("\xC3"));
var_dump(mb_check_encoding(["k" => ["ok", "\xFF"]]));

// malformed input: the decoder, the mblen table and the fast count disagree
$bad = "a\xE4\xB8b\x80\xC3";
echo mb_strlen($bad), " ", bin2hex(mb_substr($bad, 1)), " ", bin2hex(mb_scrub($bad)), "\n";
echo implode(",", array_map('bin2hex', mb_str_split($bad))), "\n";
var_dump(mb_strpos($bad, "b"), mb_substr_count($bad, "\xFF"));
echo bin2hex(mb_strcut("\xE4\xB8\xAD\xE4\xB8\xAD\xC3\xA9", 5, -3)), "\n";

mb_substitute_character("none");
echo bin2hex(mb_scrub($bad)), "\n";
mb_substitute_character(0x263A);
echo mb_scrub($bad), " ", mb_substitute_character(), "\n";
mb_substitute_character(63);

var_dump(mb_internal_encoding(), mb_internal_encoding("latin1"), mb_internal_encoding(), mb_strlen("ї"));
mb_internal_encoding("UTF-8");
var_dump(mb_strlen("ї", "8bit"), bin2hex(mb_chr(0xE9, "ISO-8859-1")), mb_check_encoding("\x80", "ASCII"));

foreach ([fn() => mb_strlen("x", "nope"), fn() => mb_str_split("x", 0), fn() => mb_strpos("abc", "b", 5),
    fn() => mb_ord(""), fn() => mb_substr_count("a", ""), fn() => mb_str_pad("a", 3, ""),
    fn() => mb_substitute_character("zz"), fn() => mb_substitute_character(0x110000)] as $f) {
    try { $f(); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
}
