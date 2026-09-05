<?php
// A variadic call packs its trailing arguments into ONE array literal, and a
// literal OWNS its elements — it adopts a fresh one and retains a borrowed one.
// Its own release drops every element kind that has a flavor, but an ARRAY
// element has none, so both arguments of every `array_merge($a, $b)` were
// stranded. Released with each element's own static flavor now; a missing
// release leaks, an extra one frees an array the caller still holds.

/** @return string[] */
function pieces(string $s, int $i): array { return explode(",", $s . $i); }

$src = "alpha,beta,gamma";
$live = ["kept" => "value"];
$out = [];
for ($i = 0; $i < 4; $i++) {
    // Fresh temps on both sides.
    $out[] = implode("|", array_merge(pieces($src, $i), ["z" . $i]));
    // A BORROWED element: $live must survive being packed, every iteration.
    $m = array_merge($live, ["n" => $i]);
    $out[] = $m["kept"] . ":" . $m["n"];
    // Three-way, and a named local packed twice.
    $t = pieces($src, $i);
    $out[] = implode("|", array_merge($t, $t, ["tail"]));
    $out[] = implode(",", array_diff($t, ["beta"]));
    $out[] = implode(",", array_intersect($t, ["beta", "alpha"]));
}
foreach ($out as $o) { echo $o, "\n"; }
echo $live["kept"], " ", count($live), "\n";

// A nested literal that is NOT an argument still round-trips.
$nested = [pieces($src, 9), ["solo"]];
echo count($nested), " ", implode("/", $nested[0]), " ", $nested[1][0], "\n";
