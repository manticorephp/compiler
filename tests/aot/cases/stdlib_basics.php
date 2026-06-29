<?php
// Basic math (LLVM-intrinsic builtins) + str/array stdlib, 1:1 with Zend.
echo floor(4.7), " ", ceil(4.2), " ", sqrt(16.0), "\n";
echo round(2.5), " ", round(2.4), " ", round(3.14159, 2), " ", fmod(10.0, 3.0), "\n";
echo ucfirst("hello"), " ", lcfirst("Hello"), " ", strrev("abcde"), "\n";
echo str_pad("7", 3, "0", 0), " ", str_pad("mid", 7, "-", 2), "\n";
echo array_sum([1, 2, 3, 4]), "\n";
$r = array_reverse([10, 20, 30]);
echo $r[0], ",", $r[1], ",", $r[2], "\n";
