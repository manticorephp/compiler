<?php
// A `|` INSIDE brackets does not make the whole hint a union. The check was a
// plain strpos, so `array<int,string|null>` — str_getcsv's own declared return,
// and every `array<K, V|null>` in the tree — collapsed to a bare cell: the
// array-ness was gone, the .sig carried `mixed` instead of `mixed[]`, and a
// caller cannot own an erased word.

/** @return array<int,string|null> */
function pairs(int $i): array { return ["a" . $i, null, "b" . $i]; }

/** @return (string|null)[] */
function grouped(int $i): array { return [null, "g" . $i]; }

/** @return list<string|null> */
function listed(int $i): array { return ["l" . $i, null]; }

/** @return array<string,int|float> */
function mixedMap(int $i): array { return ["n" => $i, "f" => $i + 0.5]; }

foreach (pairs(1) as $k => $v) { echo "p ", $k, "=", $v === null ? "NULL" : $v, "\n"; }
foreach (grouped(2) as $v) { echo "g ", $v === null ? "NULL" : $v, "\n"; }
foreach (listed(3) as $v) { echo "l ", $v === null ? "NULL" : $v, "\n"; }
foreach (mixedMap(4) as $k => $v) { echo "m ", $k, "=", $v, "\n"; }

echo count(pairs(5)), " ", count(grouped(6)), " ", count(listed(7)), "\n";
echo var_export(pairs(8), true), "\n";

// The same hint on a PARAMETER.
/** @param array<int,string|null> $xs */
function joinNonNull(array $xs): string
{
    $o = "";
    foreach ($xs as $x) { if ($x !== null) { $o .= $x . ";"; } }
    return $o;
}
echo joinNonNull(pairs(9)), "\n";

// str_getcsv is the stdlib entry that declares exactly this shape.
$row = str_getcsv("alpha,beta,gamma", ",", chr(34), chr(92));
echo count($row), " ", implode("|", $row), "\n";
