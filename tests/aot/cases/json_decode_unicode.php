<?php
// json_decode's \u escape decoding. The native decoder's hex reader once
// accepted DIGITS only, so every escape whose four digits happened to be
// numeric worked and every one carrying a-f leaked its digits as literal text.
// It surfaced through the stdlib .o.sig (which is JSON) as a corrupted trim
// mask, not through any json test — hence this one.
$d = json_decode('{"a":"\\u0041","b":"\\u00e9","c":"\\u20ac","d":"\\ud83d\\ude00","e":"\u0020\u0009\u000a\u000d\u0000\u000b"}', true);
foreach ($d as $k => $v) {
    echo $k, ' len=', strlen($v), ' bytes=';
    for ($i = 0; $i < strlen($v); $i++) { echo ord($v[$i]), ','; }
    echo "\n";
}
// upper-case hex digits decode identically
$u = json_decode('["\\u00E9","\\uD83D\\uDE00","\\u20AC"]', true);
foreach ($u as $v) { echo bin2hex($v), ' '; }
echo "\n";
// the short escapes
$s = json_decode('"q\"w\\\\e\/r\bt\fy\nu\ri\to"', true);
echo bin2hex($s), "\n";
