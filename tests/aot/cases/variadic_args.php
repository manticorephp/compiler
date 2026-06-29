<?php
function sum(int ...$xs): int {
    $t = 0; foreach ($xs as $x) { $t = $t + $x; } return $t;
}
echo sum(1, 2, 3, 4, 5);
