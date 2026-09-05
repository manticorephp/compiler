<?php
// `@return string[]` says what the VALUES are and nothing about the keys, so it
// lowers to a VEC — while the body builds a hashed, string-keyed buffer. The
// caller then read every key with the raw packed accessor and printed a
// pointer. The body BUILDS the buffer, so the body decides the shape.

/** @return string[] */
function strMap(int $i): array { return ["k" . $i => "v" . $i, "z" . $i => "w" . $i]; }

/** @return int[] */
function intMap(string $p): array { return [$p . "a" => 1, $p . "b" => 2]; }

/** @return string[] */
function realList(int $n): array { $o = []; for ($i = 0; $i < $n; $i++) { $o[] = "e" . $i; } return $o; }

class Bag
{
    /** @return string[] */
    public function pairs(int $i): array { return ["m" . $i => "n" . $i]; }
}

foreach (strMap(1) as $k => $v) { echo "s $k=$v\n"; }
foreach (intMap("p") as $k => $v) { echo "i $k=$v\n"; }
foreach (realList(3) as $k => $v) { echo "l $k=$v\n"; }
foreach ((new Bag())->pairs(7) as $k => $v) { echo "b $k=$v\n"; }

// Read back by key, and the endpoint builtins over the same shape.
$m = strMap(2);
echo $m["k2"], " ", $m["z2"], " ", count($m), "\n";
echo array_key_first($m), " ", array_key_last($m), "\n";
echo implode(",", array_keys(strMap(3))), "\n";
echo implode(",", array_values(intMap("q"))), "\n";

// A mixed-shape return keeps its erasure rather than picking one arm.
/** @return string[] */
function either(bool $c): array { return $c ? ["x" => "1"] : ["2"]; }
foreach (either(true) as $k => $v) { echo "e $k=$v\n"; }
foreach (either(false) as $k => $v) { echo "e $k=$v\n"; }
