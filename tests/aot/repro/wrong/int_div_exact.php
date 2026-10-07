<?php
// `/` over two divisible int variables yields float instead of int
// issue: #46
$a = 6;
$b = 2;
var_dump($a / $b);
