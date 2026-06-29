<?php
$s = 0;
for ($i = 1; $i <= 10; $i = $i + 1) {
    if ($i % 2 == 0) { continue; }
    $s = $s + $i;
}
echo $s;
