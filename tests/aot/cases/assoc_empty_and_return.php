<?php
// Two correctness holes in the default path:
//  1. an empty `[]` later string-keyed must allocate an assoc buffer
//     (not a vec — a string key on a vec buffer faults).
//  2. a function returning an assoc must narrow its return type to assoc
//     so the caller reads `$r["k"]` via the assoc index, not a vec offset.
$m = [];
$m["host"] = "localhost";
$m["port"] = "8080";
echo $m["host"], ":", $m["port"], "\n";

function config(string $env): array {
    $c = [];
    $c["env"] = $env;
    $c["debug"] = "on";
    return $c;
}
$r = config("prod");
echo $r["env"], " ", $r["debug"], "\n";

// seeded assoc, returned and read back
function pair(int $a, int $b): array {
    $p = ["lo" => $a];
    $p["hi"] = $b;
    return $p;
}
$q = pair(3, 9);
echo $q["lo"], " ", $q["hi"], "\n";
