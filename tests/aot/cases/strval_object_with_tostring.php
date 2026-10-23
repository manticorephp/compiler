<?php
// strval() of an object that defines __toString throws "could not be converted to string".
class S { function __toString(): string { return "s!"; } }
echo strval(new S), "\n";
function f(mixed $v) { return strval($v); }
echo f(new S), "\n";
$o = new S;
echo strval($o), "\n";
echo (string)$o, "\n";
