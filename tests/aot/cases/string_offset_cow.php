<?php
// 1. plain copy must not alias
$a = "hello";
$b = $a;
$b[0] = "J";
echo $a, " ", $b, "\n";

// 2. copy INTO an array must not alias
$arr = [];
$arr[] = $a;
$c = $a;
$c[1] = "X";
echo $a, " ", $arr[0], " ", $c, "\n";

// 3. a literal is immortal — writing through a copy must not corrupt it
function f(): string { return "lit"; }
$x = f();
$x[0] = "L";
$y = f();
echo $x, " ", $y, "\n";

// 4. hash cache: mutate a string used as an assoc key
$k = "aa";
$m = [];
$m[$k] = 1;
$k2 = $k;
$k2[0] = "b";
$m[$k2] = 2;
echo $m["aa"], $m["ba"], " ", count($m), "\n";

// 5. param passed by value
function g(string $s): string { $s[0] = "Z"; return $s; }
$p = "abc";
$q = g($p);
echo $p, " ", $q, "\n";

// 6. growth past the end still pads
$w = "ab";
$w[4] = "!";
echo "[", $w, "] ", strlen($w), "\n";
