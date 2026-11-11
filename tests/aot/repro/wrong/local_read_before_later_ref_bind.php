<?php
// A local read at the top of a loop body reads its reference box's address once a later `$x = &$a[$k]` in the same body has rebound it.
// issue: #642
function show(mixed $v): string { return var_export($v, true); }
function run(array $a): void {
    $x = 10;
    foreach ([0, 1] as $k) {
        echo show($x), "\n";
        $x = &$a[$k];
    }
    $x = 99;
    echo json_encode($a), "\n";
}
run([1, 2]);
