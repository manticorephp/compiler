<?php
// A polyfill of a built-in class, skipped by an early `return` when the class exists.
require_once __DIR__ . '/lib_spl.php';
$a = new SplFixedArray(3);
$a[1] = 'x';
echo count($a), ' ', $a[1], "\n";
