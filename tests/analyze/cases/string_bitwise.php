<?php
// `&` `|` `^` `~` over two strings are byte-wise and answer a string (php is
// the oracle); a string return type over them is not a type error.

function x(string $a, string $b): string { return $a ^ $b; }
function a(string $a, string $b): string { return $a & $b; }
function o(string $a, string $b): string { return $a | $b; }
function n(string $a): string { return ~$a; }
function i(int $a, string $b): int { return $a ^ $b; }

echo x("ab", "cd"), a("ab", "cd"), o("ab", "cd"), n("ab"), i(6, "3"), "\n";
