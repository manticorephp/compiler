<?php
// Stdlib functions are checked against their stub signatures, with php's internal-function rules.
class Name { public function __toString(): string { return "n"; } }
$a = str_pad("a");                       // too few arguments
$k = array_keys(['x' => 1], 1, true);    // php's extra parameters: accepted
$n = strlen(null);                       // null coerced (deprecated), not a type error
$s = strtoupper(new Name());             // Stringable passes a string parameter
$i = str_repeat("a", "3");               // numeric string coerced in coercive mode
echo $a, json_encode($k), $n, $s, $i, "\n";
