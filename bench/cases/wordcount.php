<?php
// wordcount — assoc map build (string keys) + iterate (hashed array path).
$words = ["alpha", "beta", "gamma", "alpha", "delta", "beta", "alpha", "gamma"];
$counts = [];
for ($i = 0; $i < 100000; $i++) {
    foreach ($words as $w) {
        if (isset($counts[$w])) { $counts[$w] = $counts[$w] + 1; }
        else { $counts[$w] = 1; }
    }
}
foreach ($counts as $w => $c) {
    echo $w, "=", $c, "\n";
}
