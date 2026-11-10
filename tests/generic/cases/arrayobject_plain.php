<?php
// ArrayObject without any property references in the program.
$ao = new ArrayObject(['b' => 2, 'a' => 1]);
$ao['c'] = 3;
$ao->offsetUnset('b');
echo count($ao), ' ', json_encode($ao->getArrayCopy()), "\n";
