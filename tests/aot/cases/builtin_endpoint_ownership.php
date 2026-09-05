<?php
// Class C: the result IS an element or a key of the argument, so the builtin
// retains it and the argument is freed under it. Both halves at once — a
// missing retain frees the element under the reader below, an extra one leaks.

/** @return string[] */
function pieces(string $s, int $i): array { return explode(",", $s . $i); }

$src = "alpha,beta,gamma";
$keep = [];
for ($i = 0; $i < 3; $i++) {
    $keep[] = (string)array_first(pieces($src, $i));
    $keep[] = (string)array_last(pieces($src, $i));
    $keep[] = (string)current(pieces($src, $i));
    $keep[] = (int)array_key_first(pieces($src, $i)) . ":" . (int)array_key_last(pieces($src, $i));
    $tmp = pieces($src, $i);
    $keep[] = (string)array_pop($tmp);
}
foreach ($keep as $k) { echo $k, "\n"; }

// The cursor family over a NAMED array: the element outlives the read, and a
// move separates from an alias the way php does.
$a = ["one", "two", "three"];
$b = $a;
next($a);
echo current($a), " ", current($b), "\n";
echo key($a), " ", key($b), "\n";
echo end($a), " ", reset($a), " ", prev($a) === false ? "false" : "?", "\n";

// A string-KEYED map: the key cell borrows the key buffer, so it has to be
// retained before the map that owns it goes away.
/** @return string[] */
function rowOf(int $i): array { return ["k" . $i => "v" . $i, "z" . $i => "w" . $i]; }
$ks = [];
for ($i = 0; $i < 3; $i++) {
    $ks[] = (string)array_key_first(rowOf($i));
    $ks[] = (string)array_key_last(rowOf($i));
    $ks[] = (string)array_first(rowOf($i));
}
echo implode(",", $ks), "\n";
