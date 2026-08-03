<?php
// The element repr is stamped from the type the RELEASE is chosen from, not
// from the (possibly narrowed) type at the store site. `$out = []` pins the
// local erased; a later store that INFERENCE has narrowed to
// assoc[string,string] must still stamp, or the buffer reaches the plain repr
// release with no bits and drops none of its values.
//
// Correctness side: the values must read back, survive a copy of the map, and
// still be there after the first branch's shape is never taken.
/** @return array<string,string> */
function pairs(string $qs): array
{
    $out = [];
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') {
            continue;
        }
        $e = strpos($pair, '=');
        if ($e === false) {
            $out[$pair] = '';
            continue;
        }
        $out[substr($pair, 0, $e)] = substr($pair, $e + 1);
    }
    return $out;
}

$a = pairs("page=2&sort=name&flag");
ksort($a);
foreach ($a as $k => $v) {
    echo $k, "=[", $v, "]\n";
}
echo count($a), "\n";

// a copy keeps its own live values after the source is replaced
$copy = $a;
$a = pairs("x=1");
echo $copy["sort"], "\n";
echo $copy["flag"] === '' ? "empty" : "set", "\n";
echo count($a), "\n";
echo $a["x"], "\n";

// the same shape with an int-valued map (the non-rc element path)
/** @return array<string,int> */
function lens(string $qs): array
{
    $out = [];
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') {
            continue;
        }
        $out[$pair] = strlen($pair);
    }
    return $out;
}
$l = lens("aa&bbb&c");
ksort($l);
echo implode(",", $l), "\n";
