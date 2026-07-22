<?php
// assoc_small — millions of ~6-key string-keyed records (API-payload shape).
// Exercises the sub-INDEX_THRESHOLD linear-scan path: build + literal-key reads.
$sum = 0;
$reps = 400000 * $argc;
for ($r = 0; $r < $reps; $r++) {
    $u = ["id" => $r, "name" => "user", "age" => 33, "score" => 17, "level" => 4, "flags" => 9];
    $u["score"] = $u["score"] + $r % 5;
    $sum += $u["id"] + $u["age"] + $u["score"] + $u["level"] + $u["flags"];
}
echo $sum, "\n";
