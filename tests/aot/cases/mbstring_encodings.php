<?php
// mbstring step 2a: every encoding name, single-byte tables, the UTF-16/32 family, CJK through iconv.
$s = "Привіт, 世界! ¥€";
foreach (["UTF-16", "UTF-16LE", "UCS-2", "UTF-32", "UCS-4LE", "Windows-1251", "KOI8-R", "ISO-8859-5", "CP866"] as $e) {
    $b = mb_convert_encoding($s, $e, "UTF-8");
    echo str_pad($e, 14), bin2hex($b), " len=", mb_strlen($b, $e), " back=", mb_convert_encoding($b, "UTF-8", $e), "\n";
}
foreach (["SJIS", "EUC-JP", "ISO-2022-JP", "CP932", "GB18030", "CP936", "BIG-5", "EUC-KR", "UHC"] as $e) {
    $t = $e === "BIG-5" ? "繁體中文 abc" : ($e === "EUC-KR" || $e === "UHC" ? "한국어 abc" : ($e[0] === 'G' || $e === "CP936" ? "简体中文 abc" : "日本語テキスト abc"));
    $b = mb_convert_encoding($t, $e, "UTF-8");
    echo str_pad($e, 14), bin2hex($b), " len=", mb_strlen($b, $e), " sub=", bin2hex(mb_substr($b, 1, 2, $e)),
        " pos=", var_export(mb_strpos($b, mb_convert_encoding("abc", $e, "UTF-8"), 0, $e), true),
        " split=", count(mb_str_split($b, 1, $e)), " ok=", var_export(mb_check_encoding($b, $e), true),
        " back=", mb_convert_encoding($b, "UTF-8", $e), "\n";
}
echo bin2hex(mb_convert_encoding("a😀b", "SJIS", "UTF-8")), " ", bin2hex(mb_convert_encoding("a€b", "ISO-8859-1", "UTF-8")), "\n";
mb_substitute_character("long");
echo mb_convert_encoding("a€b", "ISO-8859-1", "UTF-8"), " ", mb_convert_encoding("a😀b", "SJIS", "UTF-8"), "\n";
mb_substitute_character("entity");
echo mb_convert_encoding("a€b", "ASCII", "UTF-8"), "\n";
mb_substitute_character(63);
var_dump(mb_convert_encoding(["ключ" => ["значення", 5]], "Windows-1251", "UTF-8") === ["\xEA\xEB\xFE\xF7" => ["\xE7\xED\xE0\xF7\xE5\xED\xED\xFF", 5]]);
echo mb_convert_encoding("\xE9t\xE9", "UTF-8", "ISO-8859-1, UTF-8"), " ", mb_convert_encoding("été", "UTF-8", ["ASCII", "UTF-8"]), "\n";
echo count(mb_list_encodings()), " ", implode(",", mb_encoding_aliases("SJIS")), " ", mb_preferred_mime_name("sjis-win"), " ", mb_preferred_mime_name("cp1251"), "\n";
var_dump(mb_strlen("abc", "Shift_JIS"), mb_internal_encoding("cp1251"), mb_internal_encoding(), mb_strlen("\xEF\xF0"), mb_internal_encoding("UTF-8"));
foreach ([fn() => mb_convert_encoding("a", "zz"), fn() => mb_convert_encoding("a", "UTF-8", "UTF-8,zz"),
    fn() => mb_convert_encoding("a", "UTF-8", []), fn() => mb_encoding_aliases("zz"),
    fn() => mb_substr_count("ab", "\xFE\xFF", "UTF-16")] as $f) {
    try { $f(); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
}
