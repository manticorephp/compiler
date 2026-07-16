<?php

// Legacy leading-zero octal (0777) is what every file-permission literal in the
// wild uses. It parsed as decimal, so mkdir($d, 0777) silently asked for mode
// 777 == 0o1411 — a directory with no owner write bit.

echo "-- legacy octal --\n";
var_dump(0777);
var_dump(0644);
var_dump(0755);
var_dump(0400);
var_dump(010);
var_dump(077);
var_dump(07);
var_dump(00);
var_dump(0);
var_dump(01_0);

echo "-- 0o octal (PHP 8.1) --\n";
var_dump(0o777);
var_dump(0O644);
var_dump(0o10);

echo "-- hex --\n";
var_dump(0x1F);
var_dump(0XFF);
var_dump(0xdead);
var_dump(0x0);

echo "-- binary --\n";
var_dump(0b1011);
var_dump(0B1);
var_dump(0b0);

echo "-- decimal is untouched --\n";
var_dump(777);
var_dump(10);
var_dump(1_000_000);
var_dump(9);
var_dump(80);

echo "-- floats with a leading zero stay floats --\n";
var_dump(0.5);
var_dump(0.0);
var_dump(0e3);

echo "-- octal in expressions --\n";
var_dump(0777 & 0022);
var_dump(0644 | 0100);
var_dump(0777 - 0111);
$mode = 0755;
var_dump($mode);
var_dump(decoct(0777));
var_dump(sprintf('%o', 0644));
