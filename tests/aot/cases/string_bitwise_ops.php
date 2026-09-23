<?php

// Byte-wise string bitwise operators. Both operands strings ⇒ bytes, not ints.
// Expected output is php's own.

function x(string $a, string $b): string { return $a ^ $b; }
function a(string $a, string $b): string { return $a & $b; }
function o(string $a, string $b): string { return $a | $b; }
function n(string $a): string { return ~$a; }

echo bin2hex(x("abc", "\x01\x02")), "\n";          // truncates to the shorter
echo bin2hex(a("abc", "\xff\x0f")), "\n";
echo bin2hex(o("ab", "\x01\x02\x03\x04")), "\n";    // pads from the longer
echo bin2hex(o("\x01\x02\x03\x04", "ab")), "\n";
echo bin2hex(n("\x00\xff\x0f")), "\n";
echo bin2hex(x("", "abc")), "|", bin2hex(o("", "abc")), "\n";

$mask = "\x37\xfa\x21\x3d";
$data = "Hello";
$k = substr(str_repeat($mask, 2), 0, 5);
$m = $data ^ $k;
echo bin2hex($m), " ", $m ^ $k, "\n";               // RFC 6455 §5.7 masked "Hello"

$s = "abcd";
$s ^= "    ";
echo $s, "\n";                                      // ABCD
$s |= "\x20\x20";
echo $s, "\n";                                      // abCD
$s &= "\xdf\xdf\xdf";
echo $s, "\n";                                      // ABC

$big = str_repeat("\xaa", 70000);
echo strlen($big ^ str_repeat("\xff", 70000)), " ", bin2hex(substr($big ^ str_repeat("\xff", 70000), 0, 4)), "\n";

// Non-string pairs keep the integer path.
echo 6 ^ 3, " ", "6" ^ 3, " ", 6 & "3", " ", ~5, "\n";

// Operands whose static type is erased (mixed) but hold strings at run time.
/** @param mixed $p @param mixed $q */
function mx(mixed $p, mixed $q): mixed { return $p ^ $q; }
var_dump(mx("ab", "\x01\x01"));
var_dump(mx(12, 10));
