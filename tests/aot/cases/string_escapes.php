<?php
// Hex / octal / control escapes in double-quoted strings, embedded-NUL literals,
// and trim's default mask (round-trips through the stdlib .sig as \u00XX).
echo ord("\x41"), "\n";           // 65
echo ord("\xB"), "\n";            // 11
echo "\x48\x69\x21\n";            // Hi!
echo ord("\101"), "\n";           // 65 octal
echo ord("\0"), "\n";             // 0
echo strlen("a\x00b"), "\n";      // 3 (embedded NUL literal)
echo (int)("\v\f\e" === "\x0B\x0C\x1B"), "\n"; // 1
echo "[", trim("  spaced  "), "]\n";           // [spaced]
echo "[", trim("x y"), "]\n";                  // [x y] (default mask, no spurious strip)
echo "[", trim("\x0B\tvt-tab\t\x0B"), "]\n";   // [vt-tab]
