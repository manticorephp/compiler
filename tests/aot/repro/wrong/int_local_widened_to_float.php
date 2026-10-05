<?php
// An int local that a loop or one branch turns float reads float everywhere
// issue: #47
function t(bool $b): void {
    $s = 1;
    if ($b) { $s = $s + 1.5; }
    var_dump($s);
    $z = 0;
    foreach ([] as $x) { $z += 1.5; }
    var_dump($z);
}
t(false);
