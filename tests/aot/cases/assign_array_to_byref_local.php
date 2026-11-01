<?php
// Assigning an array held in another variable to a local that is passed by reference elsewhere leaks the old value on every assignment.
function byRef(?array &$a): void {}
function run(array $set): void {
    $m0 = memory_get_peak_usage();
    for ($i = 0; $i < 300000; $i++) { $r = $set; }
    byRef($r);
    $mb = (memory_get_peak_usage() - $m0) >> 20;
    echo $mb < 4 ? "flat\n" : "LEAK\n";
}
run([1, 2, 3, 4, 5, 6, 7, 8]);
