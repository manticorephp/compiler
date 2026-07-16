<?php
class A { public int $v = 1; public function who(): string { return "A"; } }
class B { public int $v = 2; public function who(): string { return "B"; } }
$cls = (count($argv) > 100) ? "A" : "B";   // runtime -> new $cls() is union{A,B,...}
$o = new $cls();
$m = "who";
echo $o->$m(), "\n";      // dynamic method on a union receiver
$p = "v";
echo $o->$p, "\n";        // dynamic property read on a union receiver
