<?php
// strcmp & co. are a runtime loop now: php's byte difference at the first mismatch, unsigned
// bytes, -1 / 1 on a prefix, embedded NUL, folding only ASCII, and a limit on the n* forms.
$p = [['a', 'c'], ['c', 'a'], ['abc', 'ab'], ['ab', 'abc'], ['', ''], ['', 'x'], ['x', ''], ["\xff", "\x01"], ["\x01", "\xff"], ["a\0b", "a\0c"], ["a\0", "a"], ['Hello', 'hello'], ['HELLO', 'hellp'], ["\xC9", "\xE9"], ['Z', 'a'], ['_', 'a'], ['[', 'A']];
foreach ($p as [$a, $b]) {
    echo bin2hex($a), ' ', bin2hex($b), ': ', strcmp($a, $b), ' ', strcasecmp($a, $b), ' ', strncmp($a, $b, 1), ' ', strncasecmp($a, $b, 2), ' ', strncmp($a, $b, 0), "\n";
}
try { strncmp('a', 'b', -1); } catch (ValueError $e) { echo $e->getMessage(), "\n"; }
usort($p, fn ($x, $y) => strcmp($x[0], $y[0]));
echo bin2hex($p[0][0]), ' ', bin2hex($p[16][0]), "\n";
