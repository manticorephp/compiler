<?php

// The string functions the symfony stack needs: entities, tag stripping,
// C-escape decoding, slice comparison, version ordering and unpack.

echo htmlspecialchars_decode("&lt;a href=&quot;x&quot;&gt;&amp;amp;&lt;/a&gt;"), "\n";
echo htmlspecialchars_decode("&lt;b&gt; &quot;q&quot; &#039;s&#039;", ENT_NOQUOTES), "\n";

echo htmlentities("<b>caf\u{e9} & cr\u{e8}me</b>"), "\n";
echo html_entity_decode("caf&eacute; &amp; cr&egrave;me &#8364; &#x20AC; &unknown;"), "\n";
echo html_entity_decode(htmlentities("na\u{ef}ve \"quoted\" 'single'")), "\n";

echo strip_tags("<p>Hello <b>world</b></p>"), "\n";
echo strip_tags("<p>Keep <b>bold</b></p>", "<b>"), "\n";
echo strip_tags("a <!-- comment <b>x</b> --> b"), "\n";
echo strip_tags("<a href=\"x>y\">link</a>"), "\n";

echo stripcslashes("a\\tb\\nc"), "|\n";
echo stripcslashes("\\x41\\102\\q"), "\n";

echo substr_compare("Hello World", "World", 6), "\n";
echo substr_compare("Hello World", "world", 6), "\n";
echo substr_compare("Hello World", "world", 6, null, true), "\n";
echo substr_compare("Hello", "He", 0, 2), "\n";
echo substr_compare("Hello", "he", -5, 2, true), "\n";

echo version_compare("1.0.0", "1.0.1"), " ";
echo version_compare("1.2", "1.2.0"), " ";
echo version_compare("2.0", "1.9.9"), " ";
echo version_compare("1.0.0-beta", "1.0.0"), " ";
echo version_compare("1.0.0-rc1", "1.0.0-beta2"), "\n";
echo version_compare("8.1.0", "8.0.0", ">=") ? "ge" : "lt", "\n";
echo version_compare("5.6", "7.0", "<") ? "lt" : "ge", "\n";

$b = unpack("C*", "AB\xFF");
echo $b[1], ",", $b[2], ",", $b[3], "\n";
$n = unpack("n*", "\x01\x02\x03\x04");
echo $n[1], ",", $n[2], "\n";
$one = unpack("N", "\x00\x00\x01\x00");
echo $one[1], "\n";
$named = unpack("Cfirst/Csecond", "\x0A\x14");
echo $named["first"], "-", $named["second"], "\n";
$two = unpack("C2pair", "\x01\x02");
echo $two["pair1"], "-", $two["pair2"], "\n";
$sig = unpack("c", "\xFF");
echo $sig[1], "\n";
$str = unpack("a3tag", "abcdef");
echo $str["tag"], "\n";

// pack, the inverse of unpack over the same code set.
echo bin2hex(pack("C*", 65, 66, 255)), "\n";
echo bin2hex(pack("n", 258)), " ", bin2hex(pack("N", 256)), "\n";
echo bin2hex(pack("v", 258)), " ", bin2hex(pack("V", 256)), "\n";
echo pack("a5", "ab") === "ab\x00\x00\x00" ? "a5-ok" : "a5-BAD", "\n";
echo pack("A5", "ab"), "|\n";
echo bin2hex(pack("H*", "48656c6c6f")), "\n";
echo pack("H*", "48656c6c6f"), "\n";
$rt = unpack("C*", pack("C*", 1, 2, 3));
echo $rt[1], $rt[2], $rt[3], "\n";
echo bin2hex(pack("x3")), "\n";

// The whole documented code table, round-tripped.
echo bin2hex(pack("H*", "1234")), " ", bin2hex(pack("h*", "1234")), "\n";
echo unpack("H*", "\x12\x34")[1], " ", unpack("h*", "\x12\x34")[1], "\n";
echo bin2hex(pack("s", -2)), " ", unpack("s", pack("s", -2))[1], "\n";
echo unpack("l", pack("l", -70000))[1], " ", unpack("q", pack("q", -5000000000))[1], "\n";
echo unpack("V", pack("V", 4000000000))[1], " ", unpack("J", pack("J", 1099511627776))[1], "\n";
echo bin2hex(pack("Z5", "ab")), " ", unpack("Z5", "ab\x00\x00\x00")[1], "|\n";
echo bin2hex(pack("C2X", 1, 2)), "\n";
echo bin2hex(pack("C@4C", 9, 8)), "\n";
$sk = unpack("Cfirst/x2/Clast", "\x01\xAA\xBB\x02");
echo $sk["first"], "-", $sk["last"], "\n";
