<?php
// strtod reads hex, inf and nan; php's is_numeric and numeric-string == do not.
foreach (["0x1A", "0X1a", "inf", "-INF", "nan", "infinity", " 12", "12 ", "1e5", ".5", "5.", "-0", "1e", "", " ", "0b1", "1_000"] as $s) {
    echo json_encode($s), " ", var_export(is_numeric($s), true), "\n";
}
var_dump("0x1A" == "26", "inf" == "INF", " 1e3" == "1000");
// A float against a non-numeric string compares as strings: only the
// infinities' "INF" / "-INF" can match.
function eqm(mixed $a, mixed $b): string { return var_export($a == $b, true); }
$inf = INF;
var_dump($inf == "INF", -INF == "-INF", $inf == "inf", 1.5 == "1.5x");
echo eqm(INF, "INF"), eqm("-INF", -INF), eqm(INF, "inf"), eqm(NAN, "NAN"), "\n";
