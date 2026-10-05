<?php
// Integer overflow wraps instead of promoting to float
// issue: #48
$a = PHP_INT_MAX;
var_dump($a + 1, $a * 2);
