<?php
// A call used as the base of a compound write is evaluated more than once
// issue: #29
final class O { public int $n = 0; public array $arr = ['a']; }
$calls = 0;
function mk(): O { global $calls; $calls++; return new O(); }
mk()->n++;
echo $calls, "\n";
mk()->arr[0] .= 'z';
echo $calls, "\n";
$c = true;
$c && (mk()->arr[] = 1);
echo $calls, "\n";
echo (string)(mk()->arr[] = 5), " ", $calls, "\n";
