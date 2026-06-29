<?php
// An untyped param `$x` is `mixed` — is_*/casts/truthiness/dispatch on it work.
function classify($x): string {
    if (is_int($x)) { return "int:" . $x; }
    if (is_string($x)) { return "str:" . strlen($x); }
    if (is_array($x)) { return "arr:" . count($x); }
    return "other";
}
echo classify(5), "\n";
echo classify("hello"), "\n";
echo classify([1, 2, 3]), "\n";

// untyped param flows through truthiness + short-circuit
function side($n) { echo "S", $n, ";"; return $n; }
$r = side(0) && side(1);
echo "/", ($r ? "Y" : "N"), "\n";

// untyped param returned then used numerically
function pick($a, $b) { return $a + $b; }
echo pick(3, 4), "\n";
