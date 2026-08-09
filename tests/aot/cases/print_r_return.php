<?php

// print_r's second argument is the MODE, not decoration: `true` returns the
// text and prints nothing, absent/`false` prints and yields `true`, and a
// runtime flag picks between the two at runtime.

$s = print_r([1, "two", ["k" => "v"]], true);
echo "len=", strlen($s), "\n", $s, "---\n";

echo print_r("hi", true), "|", print_r(42, true), "|", print_r(3.5, true), "|",
     print_r(true, true), "|", print_r(false, true), "|", print_r(null, true), "|\n";

var_dump(print_r("x", true));

// The echo form's own value: php yields bool(true), and prints in place.
$r = print_r("Y");
echo "\n";
var_dump($r);

// Echo-mode in expression position still prints its trailing `1`.
echo print_r([1], true), print_r(2), "\n";

// A runtime flag, both ways.
$f = true;
$d = print_r([1, 2], $f);
echo "dyn-true len=", strlen($d), "\n";
$f = false;
$d2 = print_r([3, 4], $f);
var_dump($d2);

// Return mode must not reach an output buffer.
ob_start();
$inner = print_r(["a" => 1], true);
$cap = ob_get_clean();
echo "cap=", strlen($cap), " inner=", strlen($inner), "\n";

// Echo mode still reaches the buffer.
ob_start();
print_r(["b" => 2]);
$cap2 = ob_get_clean();
echo "cap2=", strlen($cap2), "\n";

// The argument the call borrowed must survive the call.
$owned = "keepme";
$copy = print_r($owned, true);
echo $owned, " ", $copy, " ", strlen($owned), "\n";

class Pt { public int $x = 1; }
echo strlen(print_r(new Pt(), true)) > 0 ? "obj ok\n" : "obj empty\n";
