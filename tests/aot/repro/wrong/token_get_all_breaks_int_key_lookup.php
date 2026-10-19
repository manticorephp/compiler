<?php
// after token_get_all() of a concatenated string, reading an int-keyed local array by key answers 0
// issue: #364
/** @param int[] $keys */
function run(array $keys, string $name): void
{
    $ends = [];
    foreach ($keys as $k) {
        $ends[$k] = $k + 7;
    }
    foreach ($keys as $k) {
        $t = token_get_all('<?php use ' . $name . ';');
        echo $k, ' ', $ends[$k], "\n";
    }
}

run([2, 14, 31, 45, 64, 78, 93, 104], 'A');
