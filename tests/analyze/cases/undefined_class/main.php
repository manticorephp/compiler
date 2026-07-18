<?php

class Circle implements Shape {}          // ok
class Broken implements Missing {}        // unknown interface

$a = new Known();                         // ok
$b = new Ghost();                         // unknown class

try {
    risky();
} catch (\RuntimeException $e) {           // ok (prelude)
    echo "caught\n";
} catch (Nope $e) {                        // unknown class
    echo "nope\n";
}

if ($a instanceof Phantom) {              // unknown class
    echo "x\n";
}
