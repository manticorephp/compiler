<?php
class B { public ?string $s = null; }
$b = new B();
echo "[", $b->s, "]\n";
echo "x=" . $b->s . "!\n";
$b->s = "hi";
echo "[", $b->s, "]\n";
function f(?string $x): string { return "(" . $x . ")"; }
echo f(null), "\n";
echo f("y"), "\n";
