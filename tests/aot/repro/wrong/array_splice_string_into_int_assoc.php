<?php
// array_splice on a copy of an int-valued string-keyed array with string replacements throws TypeError
// issue: #428
function main(): void {
    $assoc = [];
    foreach (['k0', 'k1', 'k2', 'k3', 'k4', 'k5'] as $i => $k) { $assoc[$k] = $i; }
    $a = $assoc;
    $r = array_splice($a, 2, 2, ['a', 'b']);
    echo json_encode($a), json_encode($r), "\n";
}
main();
