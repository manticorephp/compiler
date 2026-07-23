<?php
// get_class must read the RUNTIME class, not the static (super)type — including
// when a value flows through an interface-typed slot (Throwable) or a base-typed
// return. Regression for class-identity erasure through upcast.
function mk(): \Throwable { return new \TypeError("x"); }
echo get_class(mk()), "\n";                     // TypeError (via interface return)
try { throw new \RuntimeException("e"); }
catch (\Throwable $t) { echo get_class($t), "\n"; }   // RuntimeException (via catch)
class A {} class B extends A {}
function pick(A $x): A { return $x; }
echo get_class(pick(new B())), "\n";            // B (via base-class param)
$e = new \LogicException("l");
echo get_class($e), "\n";                        // LogicException (direct)
