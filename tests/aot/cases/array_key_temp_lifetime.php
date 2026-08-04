<?php
// A COMPUTED string key is a fresh +1 string at every read / isset / unset —
// the read paths now drop it ({@see EmitLlvm::keyTempRelease}). Correctness
// side of that fix: the key must still be alive for the lookup itself, and the
// value it finds must be untouched by the key's release.
$m = [];
for ($i = 0; $i < 4; $i++) {
    $m["key" . $i] = "value" . $i;
}

// read with a computed key
$sum = '';
for ($i = 0; $i < 4; $i++) {
    $sum .= $m["key" . $i] . ";";
}
echo $sum, "\n";

// isset with a computed key
$found = 0;
for ($i = 0; $i < 6; $i++) {
    if (isset($m["key" . $i])) {
        $found++;
    }
}
echo $found, "\n";

// the value read out survives past the key's death
$held = $m["key" . 2];
unset($m["key" . 2]);
echo $held, "\n";
echo count($m), "\n";
echo isset($m["key2"]) ? "still" : "gone", "\n";

// re-insert the same key after the unset dropped the old key + value
$m["key" . 2] = "again";
echo $m["key2"], "\n";
echo count($m), "\n";

// a key built by a call, not a concat
$k = strtolower("KEY0");
echo $m[$k], "\n";
echo isset($m[strtolower("KEY3")]) ? "yes" : "no", "\n";
