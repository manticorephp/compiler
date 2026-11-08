<?php
// The UTF-8 walks skip ASCII a word at a time: a multibyte or ill-formed byte at every position
// around a word boundary, in mb_strlen, mb_substr (forward, negative, bounded) and mb_scrub.
$pieces = ["é", "€", "😀", "\xff", "\xC3", "\xE2\x82", "\xF0\x9F\x98"];
foreach ($pieces as $p) {
    for ($at = 0; $at <= 18; $at++) {
        $s = str_repeat('a', $at) . $p . str_repeat('b', 18 - $at);
        echo bin2hex($p), ' ', $at, ': ', mb_strlen($s), ' ', bin2hex(mb_substr($s, $at, 2)), ' ', bin2hex(mb_substr($s, $at + 1)), ' ', bin2hex(mb_substr($s, -3, 2)), ' ', bin2hex(mb_substr($s, 7, 9)), ' ', bin2hex(mb_scrub($s)), "\n";
    }
}
foreach (['', 'a', 'abcdefg', 'abcdefgh', 'abcdefghi', str_repeat('x', 64)] as $s) {
    echo strlen($s), ': ', mb_strlen($s), ' [', mb_substr($s, 0, 1), '] [', mb_substr($s, 3), '] [', mb_substr($s, -2), '] [', mb_substr($s, 100), "]\n";
}
