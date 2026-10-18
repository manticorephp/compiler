<?php
// `unset($n); $n = 5;` on a by-ref parameter traps instead of making $n a fresh local.
// issue: #346
function f(int &$n): void { unset($n); $n = 5; echo $n, "\n"; }
$a = 1; f($a); echo $a, "\n";
