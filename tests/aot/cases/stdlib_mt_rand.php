<?php

// The MT19937 engine, seed for seed against php. Every number printed here is
// php's own answer for that seed — the point of the case is that a compiled
// binary and the interpreter agree on the SEQUENCE, not merely on the range.

mt_srand(12345);
$a = [];
for ($i = 0; $i < 5; $i++) { $a[] = mt_rand(); }
echo implode(',', $a), "\n";

// A reload boundary: draw 623 and print the ones that straddle it.
mt_srand(12345);
for ($i = 0; $i < 622; $i++) { mt_rand(); }
echo mt_rand(), ' ', mt_rand(), ' ', mt_rand(), "\n";

mt_srand(777);
$b = [];
for ($i = 0; $i < 8; $i++) { $b[] = mt_rand(1, 100); }
echo implode(',', $b), "\n";

// A power-of-two width takes the masked fast path, an odd one the rejecting path.
mt_srand(3);
$c = [];
for ($i = 0; $i < 6; $i++) { $c[] = mt_rand(0, 255); }
echo implode(',', $c), "\n";

mt_srand(3);
$d = [];
for ($i = 0; $i < 6; $i++) { $d[] = mt_rand(-10, 7); }
echo implode(',', $d), "\n";

// Wider than 32 bits: two draws glued, high word first.
mt_srand(99);
echo mt_rand(PHP_INT_MIN, PHP_INT_MAX), "\n";
mt_srand(42);
$e = [];
for ($i = 0; $i < 4; $i++) { $e[] = mt_rand(-5, 20000000000); }
echo implode(',', $e), "\n";

// rand() is the same engine; it swaps a reversed range instead of throwing.
srand(2468);
echo rand(), ' ', rand(1, 6), "\n";
srand(2468);
echo rand(), ' ', rand(6, 1), "\n";

echo mt_getrandmax(), ' ', getrandmax(), "\n";

// Seeding twice with the same seed replays the sequence.
mt_srand(1);
$first = mt_rand();
mt_srand(1);
var_dump($first === mt_rand());

try { mt_rand(9, 2); } catch (\ValueError $e) { echo $e->getMessage(), "\n"; }

// Everything that draws from the engine is reproducible with it.
mt_srand(555);
$arr = [1, 2, 3, 4, 5, 6, 7, 8];
shuffle($arr);
echo implode(',', $arr), "\n";

mt_srand(555);
echo str_shuffle('abcdefgh'), "\n";

mt_srand(31337);
$m = ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4, 'e' => 5];
echo array_rand($m), "\n";
echo implode(',', array_rand($m, 2)), "\n";
echo implode(',', array_rand($m, 4)), "\n";
echo implode(',', array_rand($m, 5)), "\n";

// A single-byte or empty string has nothing to shuffle.
echo str_shuffle(''), '|', str_shuffle('z'), "|\n";
