<?php
function total(int ...$xs): int {
    $t = 0;
    foreach ($xs as $x) { $t += $x; }
    return $t;
}
function call_spread(array $args): int {
    return total(...$args);
}
echo call_spread([1, 2, 3]);
