<?php
// explode — explode / implode round-trip. The subject is picked data-dependently
// ($acc feeds the index) so the optimizer can't hoist the constant call.
$lines = ["alpha,beta,gamma,delta,epsilon,zeta,eta,theta", "1,2,3,4,5,6,7,8,9",
          "red,green,blue,cyan,magenta,yellow,black,white"];
$m = count($lines);
$acc = 1;
$n = 500000 * $argc;
for ($i = 0; $i < $n; $i++) {
    $parts = explode(",", $lines[($i + $acc) % $m]);
    $joined = implode("|", $parts);
    $acc += strlen($joined) + $i;
}
echo $acc, "\n";
