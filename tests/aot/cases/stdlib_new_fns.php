<?php
// Newly added stdlib: array_product, array_flip, str_split (prelude);
// ucwords, substr_count (stdlib .o).
echo array_product([1, 2, 3, 4]), "\n";
$f = array_flip(["a", "b", "c"]);
echo $f["b"], "\n";
$parts = str_split("hello", 2);
echo count($parts), ":", $parts[0], $parts[1], $parts[2], "\n";
echo ucwords("foo bar baz"), "\n";
echo substr_count("mississippi", "ss"), "\n";
