<?php
// Assoc set operations compare both the key and the value using PHP's
// string-comparison semantics, while retaining keys from the first array.
$source = ['same' => 'green', 'value-only' => 'brown', 'also' => 'blue', 10 => 'red'];
$other = ['same' => 'green', 'value-only' => 'yellow', 'also' => 'blue', 10 => 'RED'];
$third = ['same' => 'green', 'also' => 'blue', 10 => 'red'];

$diff = array_diff_assoc($source, $other, $third);
echo count($diff), "\n";
echo $diff['value-only'], "\n";
echo array_key_exists('same', $diff) ? "bad\n" : "diff-keys\n";

$intersect = array_intersect_assoc($source, $other, $third);
echo count($intersect), "\n";
echo $intersect['same'], ":", $intersect['also'], "\n";
echo array_key_exists('value-only', $intersect) ? "bad\n" : "intersect-keys\n";
