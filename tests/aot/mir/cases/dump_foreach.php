<?php
function sumkv(array $xs): int {
    $t = 0;
    foreach ($xs as $k => $v) {
        $t += $k + $v;
    }
    return $t;
}
echo sumkv([10, 20, 30]);
