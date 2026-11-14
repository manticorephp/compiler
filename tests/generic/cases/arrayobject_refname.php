<?php
// ArrayObject in a program that stores a reference to a user property named like its private slot.
final class Holder { public mixed $__s = 'h'; }
$h = new Holder();
$r = [&$h->__s];
$r[0] = 'set';
$ao = new ArrayObject(['b' => 2, 'a' => 1]);
$ao['c'] = 3;
$ao->offsetUnset('b');
echo $h->__s, ' ', count($ao), ' ', json_encode($ao->getArrayCopy()), "\n";
