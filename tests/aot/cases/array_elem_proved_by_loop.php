<?php

// An array built from `[]` whose ELEMENT type only the loop body proves.
//
// Both sides of the back-edge are KIND_ARRAY, so the loop merge used to see no
// kind change and the entry `vec[unknown]` stood for the whole body: a read that
// appeared LEXICALLY BEFORE the store that proves the element rode the erased
// channel and handed back a raw pointer. It printed as an address.
//
// The read-before-store ordering is the whole point — case A below passes even
// without the fix if the two lines are swapped.

// A: store and read-back in sibling branches, read branch first.
$a = [];
$n = 0;
for ($i = 0; $i < 3; $i++) {
    if ($i === 1) {
        $a[$n - 1] = $a[$n - 1] . "-x";
        continue;
    }
    $a[$n] = "s" . $i;
    $n++;
}
echo $a[0], "|", $a[1], "\n";

// B: the real shape this came from — unfolding a continuation line into the
// header it belongs to.
function fold(string $head): array
{
    $out = [];
    $n = 0;
    foreach (explode("\n", $head) as $line) {
        if ($line === "") {
            continue;
        }
        if ($line[0] === " ") {
            if ($n === 0) {
                return [];
            }
            $out[$n - 1] = $out[$n - 1] . " " . trim($line);
            continue;
        }
        $out[$n] = $line;
        $n++;
    }
    return $out;
}
foreach (fold("A: 1\n cont\n more\nB: 2") as $l) {
    echo "[", $l, "]\n";
}

// C: an ASSOC built the same way — isUnknownArrayElem only inspected a vec, so
// the string-keyed shape needed its own predicate.
$m = [];
$keys = ["x", "y"];
foreach ($keys as $k) {
    if (isset($m["x"])) {
        $m["x"] = $m["x"] . "+" . $k;
        continue;
    }
    $m[$k] = "v" . $k;
}
echo $m["x"], "\n";

// D: ints, not strings — a raw-pointer read would have been invisible here, so
// this pins that the promotion does not disturb a numeric element.
$q = [];
$c = 0;
for ($i = 0; $i < 4; $i++) {
    if ($i > 1) {
        $q[$c - 1] = $q[$c - 1] + 10;
        continue;
    }
    $q[$c] = $i;
    $c++;
}
echo $q[0], ",", $q[1], "\n";

// E: an array that did NOT come from `[]` must be left alone — its elements may
// be NaN-boxed, and re-typing them from a loop store would read a tag as a
// pointer. `het()` returns a mixed literal, so its element is a cell.
function het(): array
{
    return [1, "two", 3.5];
}
$h = het();
foreach ($h as $v) {
    echo gettype($v), " ";
}
echo "\n";

echo "done\n";
