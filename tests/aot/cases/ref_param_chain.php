<?php
function inc(int &$a, int &$b): void {
    $a = $a + 1;
    $b = $b + 2;
}
$p = 1; $q = 10;
inc($p, $q);
echo $p, ",", $q;
