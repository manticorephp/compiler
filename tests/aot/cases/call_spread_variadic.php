<?php
function sum(int ...$xs): int {
    $t = 0; foreach ($xs as $x) { $t = $t + $x; } return $t;
}
$a = [10, 20, 30];
echo sum(1, 2, ...$a, 100);
