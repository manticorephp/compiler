<?php
// A local passed to a function called by run-time name reads as a raw box address once a later `$x = &$a[$k]` in the loop has rebound it.
// issue: #642
function show(mixed $v): string { return var_export($v, true); }
function run(string $n, array $a): void {
    $x = 10;
    foreach ([0, 1] as $k) {
        echo $n($x), "\n";
        $x = &$a[$k];
    }
    $x = 99;
    echo json_encode($a), "\n";
}
run($argc > 9 ? '' : 'show', [1, 2]);
