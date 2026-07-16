<?php
class A { public int $x = 10; public int $y = 20; public function who(): string { return "A"; } }
class B { public int $x = 30; public int $y = 40; public function who(): string { return "B"; } }
$cls = (count($argv) > 100) ? "A" : "B";   // runtime -> new $cls() is union{A,B,...}
$o = new $cls();
$m = "who";
echo $o->$m(), "\n";      // dynamic method on a union receiver
$p = "y";
echo $o->$p, "\n";        // dynamic property READ (2nd prop, offset != 16)
$o->$p = 99;              // dynamic property WRITE on a union receiver (was a wild-write crash)
echo $o->x, " ", $o->y, "\n";
