<?php
// by-ref foreach over an int list storing objects prints raw addresses as ints
// item: 19b
$a = [1, 2];
foreach ($a as &$v) { $v = new stdClass; }
unset($v);
echo gettype($a[0]), "\n";
