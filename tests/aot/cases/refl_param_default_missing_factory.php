<?php
// ReflectionParameter default value throws "Failed to retrieve the default value" for trait, interface and prelude-class (Exception, ArrayObject) methods
interface I { public function f(int $a = 5); }
trait T { public function t($z = 'a', $q = null) {} }
class C implements I { use T; public function f(int $a = 5) {} }
class MyEx extends Exception {}
class AO extends ArrayObject {}
foreach ([['C', 't'], ['I', 'f'], ['MyEx', '__construct'], ['AO', '__construct']] as [$cls, $m]) {
    foreach ((new ReflectionMethod($cls, $m))->getParameters() as $p) {
        if ($cls === "AO" && $p->getPosition() > 0) { continue; } // prelude ArrayObject::__construct declares only $array
        echo $cls, '::', $m, ' ', $p->getName(), ' = ',
            $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-', "\n";
    }
}
