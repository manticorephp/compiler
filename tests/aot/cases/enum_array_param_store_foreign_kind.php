<?php
// Storing a string into a bare `array` param whose callers pass enum cases prints the enums as floats
enum E { case A; case B; }
function f(array $a) { $a[] = "x"; var_dump($a); }
f([E::A, E::B]);
