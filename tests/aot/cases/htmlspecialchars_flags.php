<?php
// php's flag surface: quote flags, doc types, ENT_IGNORE/ENT_SUBSTITUTE on bad
// UTF-8, double_encode=false, and the untouched-string fast path.
$s = "a<b>'c'\"d\"&e";
echo htmlspecialchars($s), "\n";
echo htmlspecialchars($s, ENT_QUOTES, 'UTF-8'), "\n";
echo htmlspecialchars($s, ENT_COMPAT), "\n";
echo htmlspecialchars($s, ENT_NOQUOTES), "\n";
echo htmlspecialchars("a'b", ENT_QUOTES | ENT_HTML5), "\n";
echo htmlspecialchars("a'b", ENT_QUOTES | ENT_XML1), "\n";
echo htmlspecialchars("a'b", ENT_QUOTES | ENT_XHTML), "\n";
echo htmlspecialchars("a'b", ENT_QUOTES | ENT_HTML401), "\n";
echo htmlspecialchars("&amp; &lt; &#x41; &#65; &#9999999; &foo; &apos; &", ENT_QUOTES, null, false), "\n";
echo htmlspecialchars("&apos;", ENT_QUOTES | ENT_HTML5, null, false), "\n";
echo bin2hex(htmlspecialchars("x\xff<y")), "\n";
echo bin2hex(htmlspecialchars("x\xff<y", ENT_QUOTES | ENT_IGNORE)), "\n";
echo bin2hex(htmlspecialchars("x\xff<y", ENT_QUOTES)), "|\n";
echo bin2hex(htmlspecialchars("\xE2\x82<\xC3\xA9\xF0\x9F\x98\x80\xED\xA0\x80")), "\n";
echo bin2hex(htmlspecialchars("caf\xE9", ENT_QUOTES, 'ISO-8859-1')), "\n";
echo htmlspecialchars(""), "|", htmlspecialchars("plain text 123"), "\n";
$big = str_repeat("<p class=\"x\">Hello & 'world' — plain text</p>\n", 300);
echo strlen(htmlspecialchars($big)), " ", md5(htmlspecialchars($big)), "\n";
echo crc32("The quick brown fox jumped over the lazy dog."), " ", crc32(""), " ", crc32(str_repeat("abc", 5000)), "\n";
echo hash('crc32b', 'hello'), " ", ENT_SUBSTITUTE, " ", ENT_DISALLOWED, "\n";
