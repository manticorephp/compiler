<?php
// A Stringable object bound to a builtin's or stdlib's string parameter is not rendered through __toString.
class A { public function __toString(): string { return "Ax"; } }
$a = new A;
echo strtoupper($a), "|", strlen($a), "|", var_export(strpos($a, 'x'), true), "\n";
echo str_replace('x', 'y', $a), "|", preg_replace('/x/', 'y', $a), "\n";
