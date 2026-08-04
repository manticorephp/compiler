<?php
// `unset()` on a hashed map tombstones the entry, and the free walk skips
// tombstones on purpose — so whatever the entry owned has to be dropped AT the
// unset or it leaks forever. The KEY is dropped here (it balances the retain in
// __mir_array_set_str); the VALUE is not, and must not be until an element read
// co-owns what it hands out: `$keep = $m[$k]` is a BORROW in this compiler, so
// an unset that freed the value would hand `$keep` freed memory.
//
// TODO: once element reads co-own, drop the value at unset too and extend this
// case with a `__destruct` order check against php (php destructs an unheld
// value AT the unset and a held one at shutdown — we do neither today).
$m = [];
$m["a"] = "value-a";
$m["b"] = "value-b";
echo "built ", count($m), "\n";

unset($m["a"]);
echo count($m), "\n";
echo isset($m["a"]) ? "a-here" : "a-gone", "\n";
echo isset($m["b"]) ? "b-here" : "b-gone", "\n";

// re-insert the same key: the tombstone must not resurrect the old entry
$m["a"] = "again";
echo count($m), "\n";
echo $m["a"], "\n";

// a value read out before the unset stays readable after it
$keep = $m["b"];
unset($m["b"]);
echo "held: ", $keep, "\n";
echo count($m), "\n";

// a KEY read out of the map and used AFTER its entry is unset — the key drop
// balances set_str's retain, so a borrowed key must still read back here.
$km = ["alpha" => 1, "beta" => 2, "gamma" => 3];
$first = array_key_first($km);
unset($km[$first]);
echo $first, " ", count($km), "\n";
$seen = [];
foreach ($km as $k => $v) {
    $seen[] = $k . ":" . $v;
}
echo implode(",", $seen), "\n";

// unset inside a foreach over the same map (the compact-on-iterate path)
$cm = ["x" => 1, "y" => 2, "z" => 3];
foreach ($cm as $k => $v) {
    if ($v === 2) {
        unset($cm[$k]);
    }
}
echo implode(",", array_keys($cm)), " ", count($cm), "\n";

// churn: unset + reinsert the same keys repeatedly, size must stay stable
$ch = [];
for ($i = 0; $i < 8; $i++) {
    $ch["k" . $i] = $i;
}
for ($r = 0; $r < 3; $r++) {
    for ($i = 0; $i < 8; $i += 2) {
        unset($ch["k" . $i]);
        $ch["k" . $i] = $i * 10 + $r;
    }
}
ksort($ch);
echo count($ch), " ", implode(",", $ch), "\n";

// int keys take the same path via the packed → hashed promote
$v = [10, 20, 30];
unset($v[1]);
echo count($v), "\n";
echo isset($v[1]) ? "1-here" : "1-gone", "\n";
echo $v[2], "\n";
echo "end\n";
