<?php
// `$c += $f('abc')` with $f a function-name string at top level leaves $c at 0.
// issue: #305
$f = 'strlen';
$c = 0;
for ($i = 0; $i < 10; $i++) { $c += $f('abc'); }
echo $c, "\n";
