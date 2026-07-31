<?php

// iconv over host libiconv, plus the UTF-8 walkers.

// Round trip through Latin-1.
$u = "caf\u{e9} cr\u{e8}me";
$l1 = iconv("UTF-8", "ISO-8859-1", $u);
echo strlen($u), " ", strlen($l1), "\n";
echo bin2hex($l1), "\n";
echo iconv("ISO-8859-1", "UTF-8", $l1), "\n";

// Windows-1252 has the smart quotes Latin-1 lacks.
$cp = iconv("UTF-8", "Windows-1252", "\u{201c}hi\u{201d}");
echo bin2hex($cp), "\n";
echo iconv("Windows-1252", "UTF-8", $cp), "\n";

// //TRANSLIT is the reason this is a binding and not a reimplementation:
// symfony/string's ascii() throws without it.
//
// ⚠ //TRANSLIT is where the two hosts disagree, and php disagrees with
// ITSELF the same way: glibc renders "na\u{ef}ve caf\u{e9}" as `naive cafe`,
// macOS libiconv as `na\"ive caf'e`. Both are the host library speaking, so
// the expectation is split — expected/iconv_basic.linux.out carries glibc's.
echo iconv("UTF-8", "ASCII//TRANSLIT", "na\u{ef}ve caf\u{e9}"), "\n";
echo iconv("UTF-8", "ASCII//IGNORE", "a\u{e9}b"), "\n";

// The character-oriented helpers.
$s = "\u{4f60}\u{597d}, world";
echo iconv_strlen($s), " ", strlen($s), "\n";
echo iconv_substr($s, 0, 2), "\n";
echo iconv_substr($s, 2), "\n";
echo iconv_substr($s, -5), "\n";
var_dump(iconv_strpos($s, "world"));
var_dump(iconv_strpos($s, "nope"));
var_dump(iconv_strrpos("a/b/c", "/"));

// MIME encoded words, both transfer encodings.
echo iconv_mime_decode("=?UTF-8?B?" . base64_encode("caf\u{e9}") . "?="), "\n";
echo iconv_mime_decode("Subject: =?UTF-8?Q?caf=C3=A9_bar?="), "\n";
echo iconv_mime_decode("plain header"), "\n";

// The settings accessors, header composition and bulk decoding.
$all = iconv_get_encoding("all");
echo $all["input_encoding"], " ", $all["output_encoding"], " ", $all["internal_encoding"], "\n";
echo iconv_get_encoding("internal_encoding"), "\n";
var_dump(iconv_get_encoding("bogus"));
// php raises a Deprecated for every iconv.*_encoding ini setting; silenced so
// the two sides compare on the RESULT rather than on an ini deprecation this
// runtime has no ini to deprecate.
var_dump(@iconv_set_encoding("internal_encoding", "ISO-8859-1"));
echo iconv_get_encoding("internal_encoding"), "\n";
var_dump(@iconv_set_encoding("nonsense", "UTF-8"));

echo iconv_mime_encode("Subject", "caf\u{e9} bar"), "|\n";
echo iconv_mime_encode("Subject", "plain ascii"), "|\n";
echo iconv_mime_encode("X", "caf\u{e9}", ["scheme" => "Q"]), "|\n";
echo iconv_mime_decode(iconv_mime_encode("Subject", "caf\u{e9} bar")), "|\n";

$h = iconv_mime_decode_headers("Subject: =?UTF-8?B?" . base64_encode("caf\u{e9}") . "?=\r\nTo: a@b\r\n");
echo $h["Subject"], " / ", $h["To"], "\n";
