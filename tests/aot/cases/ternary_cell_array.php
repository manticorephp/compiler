<?php
// A ternary whose branches have different runtime reprs: a NaN-boxed cell
// (json array element) vs a raw array literal. The result must unify on the
// cell and box both branches, else foreach reads the boxed bits as a raw
// pointer and faults.
$src = '{"items":[{"name":"a"},{"name":"b"}]}';
$m = json_decode($src, true);
$items = isset($m["items"]) ? $m["items"] : [];
foreach ($items as $it) {
    echo (string)$it["name"], "\n";
}
$missing = isset($m["nope"]) ? $m["nope"] : [];
echo "missing-count:", count($missing), "\n";
