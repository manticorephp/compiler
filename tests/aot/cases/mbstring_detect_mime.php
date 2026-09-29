<?php
// mbstring steps 2b/2c: detection, UTF-7, the byte encodings, numeric entities, MIME headers.
$uk = "Привіт, як справи? Все добре.";
foreach (["UTF-8", "Windows-1251", "KOI8-R", "UTF-16LE", "CP866"] as $e) {
    $b = mb_convert_encoding($uk, $e, "UTF-8");
    echo $e, ": ", var_export(mb_detect_encoding($b, ["ASCII", "UTF-8", "Windows-1251", "KOI8-R", "CP866"], true), true),
        " / ", var_export(mb_detect_encoding($b, "UTF-8, Windows-1251, KOI8-R, UTF-16LE"), true), "\n";
}
var_dump(mb_detect_encoding("plain"), mb_detect_encoding("\xFF\xFE", "ASCII,UTF-8", true), mb_detect_order());
var_dump(mb_detect_order("UTF-8, auto"), mb_detect_order(), mb_detect_order(["ASCII", "UTF-8"]));
echo mb_convert_encoding("\xCF\xF0\xE8\xE2\xB3\xF2", "UTF-8", "auto, Windows-1251"), "\n";

foreach (["UTF-7", "UTF7-IMAP"] as $e) {
    $b = mb_convert_encoding("Входящие & Sent/2024 +😀", $e, "UTF-8");
    echo $e, ": ", $b, " -> ", mb_convert_encoding($b, "UTF-8", $e), " len=", mb_strlen($b, $e),
        " ok=", var_export(mb_check_encoding($b, $e), true), var_export(mb_check_encoding("&Jjo", $e), true), "\n";
}
$html = "<p>Café «naïve» — 5 €</p>";
echo mb_convert_encoding($html, "HTML-ENTITIES", "UTF-8"), "\n";
echo mb_convert_encoding("&eacute;&#8364;&#x41;&amp;&bogus;", "UTF-8", "HTML-ENTITIES"), "\n";
echo mb_convert_encoding($html, "BASE64", "UTF-8"), " ", mb_convert_encoding("0J/RgNC40LLRltGC", "UTF-8", "BASE64"), "\n";
echo mb_convert_encoding("a=é\n", "Quoted-Printable", "UTF-8"), "|", mb_convert_encoding("caf=C3=A9", "UTF-8", "Quoted-Printable"), "\n";
echo mb_convert_encoding("uuencode me", "UUENCODE", "UTF-8");

$map = [0x80, 0x10FFFF, 0, 0x1FFFFF];
echo mb_encode_numericentity($html, $map, "UTF-8"), "\n", mb_encode_numericentity("ї😀", $map, "UTF-8", true), "\n";
echo mb_decode_numericentity("&#1111;&#x1F600;&#65;&#9999999999;&#;x", $map, "UTF-8"), "\n";

echo mb_encode_mimeheader("Тема листа: звіт за вересень 2026 року, остаточна версія"), "\n";
echo mb_encode_mimeheader("Grüße aus Köln", "ISO-8859-1", "Q"), "\n", mb_encode_mimeheader("plain subject line"), "\n";
echo mb_decode_mimeheader("=?UTF-8?B?0KLQtdC80LA=?= =?ISO-8859-1?Q?Gr=FC=DFe?=\r\n tail"), "\n";

foreach ([fn() => mb_detect_encoding("x", []), fn() => mb_detect_encoding("x", "UTF-8,zz"), fn() => mb_detect_order(""),
    fn() => mb_ord("a", "UTF-7"), fn() => mb_chr(65, "HTML-ENTITIES"), fn() => mb_encode_numericentity("x", [1, 2, 3]),
    fn() => mb_encode_mimeheader("é", "Quoted-Printable")] as $f) {
    try { var_dump($f()); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }
}
