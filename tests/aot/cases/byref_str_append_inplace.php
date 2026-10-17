<?php
function cat(string &$s, string $x): void { $s .= $x; }
function cat2(string &$s): void { $s = $s . 'a' . 'b'; }
$a = '';
for ($i = 0; $i < 5; $i++) { cat($a, "x$i"); }
echo $a, "\n";
$b = $a;
cat($a, '!');
echo $a, '|', $b, "\n";
$c = 'lit';
cat2($c); cat2($c);
echo $c, "\n";
$arr = ['k' => 'v'];
cat($arr['k'], 'w');
echo $arr['k'], "\n";
$long = str_repeat('z', 40);
$copy = $long;
cat($long, 'tail');
echo strlen($long), strlen($copy), "\n";
$n = 0; $big = '';
for ($i = 0; $i < 20000; $i++) { cat($big, 'ab'); $n++; }
echo strlen($big), "\n";
