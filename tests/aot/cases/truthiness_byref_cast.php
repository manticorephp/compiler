<?php
// empty() / ! / if / (bool) on a by-reference `array &$a` compared the array
// POINTER with 0, so `[]` read as truthy; `(bool)` of any non-cell value did the
// same for [], "0", "" and -0.0; `if (-0.0)` read the sign bit.
function probe(array &$a): string
{
    $r = (!$a ? "T" : "F") . ($a ? "T" : "F") . ((bool)$a ? "T" : "F") . (empty($a) ? "T" : "F");
    if ($a) { $r .= "y"; } else { $r .= "n"; }
    return $r;
}
$x = [];
echo probe($x), "\n";
$x[] = 1;
echo probe($x), "\n";
function arr(): array { return []; }
function str(string $s): string { return $s; }
function flt(float $f): float { return $f; }
var_dump((bool)arr(), (bool)[1], (bool)str("0"), (bool)str(""), (bool)str("a"), (bool)str("0.0"));
var_dump((bool)flt(-0.0), (bool)flt(0.0), (bool)flt(0.5));
$z = flt(-0.0);
echo $z ? "t" : "f", !$z ? "t" : "f", "\n";
if ($z) { echo "neg-zero truthy\n"; } else { echo "neg-zero falsy\n"; }
$failures = [];
function report(array &$f): string { return !empty($f) ? "has " . count($f) : "none"; }
echo report($failures), "\n";
$failures[] = ['id' => 1];
echo report($failures), "\n";
