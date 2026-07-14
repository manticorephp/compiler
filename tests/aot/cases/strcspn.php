<?php
// strcspn — bytes before the first charlist member. Bounded and binary-safe:
// the scan stops at $length, never at a NUL and never past the span.
$s = 'hello "wor\\ld" rest';

echo strcspn($s, '"\\'), "\n";        // 6  — the opening quote
echo strcspn($s, '"\\', 7), "\n";     // 3  — from 'w' to the backslash
echo strcspn($s, 'xyz'), "\n";        // 19 — no member: the whole span
echo strcspn($s, 'o'), "\n";          // 4
echo strcspn($s, 'o', 5), "\n";       // 2
echo strcspn($s, '"\\', 0, 3), "\n";  // 3  — clamped by $length
echo strcspn($s, '"\\', -5), "\n";    // 5  — negative offset counts from the end
echo strcspn($s, 'l', 2, -14), "\n";  // 0  — negative length stops before the 'l'
echo strcspn('', 'ab'), "\n";         // 0  — empty subject
echo strcspn($s, ''), "\n";           // 19 — empty charlist matches nothing
echo strcspn($s, 'el', 100), "\n";    // 0  — offset past the end

// A charlist bigger than the memchr fast path (the 256-bit bitmap route).
echo strcspn('abcdefgXhij', 'XYZ0123456789'), "\n";  // 7

// Binary-safe: an embedded NUL is data, not a terminator.
$b = "ab\0cd|ef";
echo strcspn($b, '|'), "\n";          // 5
