<?php
// json_encode string escaping — php default flags: `"` `\` `/` and C0 controls
// escape, and every non-ASCII byte becomes `\uXXXX` (a codepoint above the BMP
// as a surrogate pair). Values AND string keys go through the same escaper.
$vals = [
    "a/b/c",                 // slashes
    "he said \"hi\"",        // quote
    "c:\\path\\to",          // backslash
    "line\nfeed\ttab\rret",  // short-form controls
    "bell\x07vtab\x0bunit\x1f", // controls with no short form -> \u00XX
    "café",                  // 2-byte UTF-8
    "日本語",                // 3-byte UTF-8
    "emoji 😀 rocket 🚀",    // 4-byte UTF-8 -> surrogate pairs
    "€ price",               // U+20AC
    "\x00zero",              // NUL
    "plain ascii word",      // clean (fast path)
];
foreach ($vals as $v) {
    echo json_encode($v), "\n";
}
// escaping in keys + nested structures
echo json_encode([
    "url/path" => "http://x/y",
    "naïve"    => ["café", "日"],
    "q\"key"   => 1,
]), "\n";
