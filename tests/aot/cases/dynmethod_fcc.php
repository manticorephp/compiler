<?php
// A first-class callable with a variable method name (`$o->$h(...)`) crashes.
class C { function m(int $x) { return $x + 1; } }
$o = new C;
$h = "m";
$fc = $o->$h(...);
echo $fc(1), "\n";
