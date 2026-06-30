<?php
// explode — explode / implode round-trip loop (boxed array path).
$line = "alpha,beta,gamma,delta,epsilon,zeta,eta,theta";
$acc = 0;
for ($i = 0; $i < 200000; $i++) {
    $parts = explode(",", $line);
    $joined = implode("|", $parts);
    $acc += strlen($joined);
}
echo $acc, "\n";
