<?php
// A Stringable object bound to a string parameter of a builtin or of the stdlib is rendered through __toString.
class A { public function __toString(): string { return "Ax"; } }
$a = new A;
echo strtoupper($a), "|", strlen($a), "|", var_export(strpos($a, 'x'), true), "|", trim($a), "|", str_pad($a, 4, '-'), "\n";
echo str_replace('x', 'y', $a), "|", preg_replace('/x/', 'y', $a), "|", str_ireplace('X', 'z', $a), "\n";
echo implode(',', explode('x', $a)), "|", substr($a, 1), "|", ucfirst(new A), "|", strcmp($a, "Ax"), "\n";
echo preg_match('/^A/', $a), str_contains($a, 'x') ? 'y' : 'n', str_starts_with($a, 'A') ? 'y' : 'n', "\n";
// A function that takes the object itself is untouched.
echo get_class($a), "|", spl_object_id($a) > 0 ? 'id' : 'none', "|", method_exists($a, '__toString') ? 'has' : 'no', "\n";
