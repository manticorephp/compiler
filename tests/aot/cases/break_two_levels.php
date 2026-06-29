<?php
$total = 0;
for ($i = 0; $i < 5; $i = $i + 1) {
    for ($j = 0; $j < 5; $j = $j + 1) {
        if ($j == 2) { break 2; }
        $total = $total + 1;
    }
}
echo $total;
