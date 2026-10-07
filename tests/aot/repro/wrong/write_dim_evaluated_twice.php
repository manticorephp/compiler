<?php
// A side-effecting index of a write target in EXPRESSION context is evaluated more than once
// issue: #66
$n = 0;
function f(): int { global $n; $n++; return 0; }
function g(): int { return 1; }
$w = [0];
while ($w[f()]++ < 3) {}
echo $w[0], " ", $n, "\n";
$a = [''];
echo $a[f()] .= 'x', " ", $n, "\n";
$b = [0];
echo $b[f()]++ + g(), " ", $n, "\n";
