<?php
$cb = 'str_repeat';
$args = ['ab', 3];
echo $cb(...$args), "\n";
$n = 'strlen';
$one = ['hello'];
echo $n(...$one), "\n";
$p = [7, 2];
echo intdiv(...$p), "\n";
