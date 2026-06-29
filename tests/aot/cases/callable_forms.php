<?php
class Box {
    public int $v = 3;
    public function dbl(int $x): int { return $x * 2; }
    public static function neg(int $x): int { return -$x; }
}
$o = new Box();
// first-class callables as stored values
$fb = count(...);       echo $fb([1,2,3]), "\n";   // 3
$fs = strlen(...);      echo $fs("abcd"), "\n";     // 4
$fm = $o->dbl(...);     echo $fm(5), "\n";          // 10
$fc = Box::neg(...);    echo $fc(5), "\n";          // -5
echo $fm(3) + $fm(4), "\n";                         // 14
// literal callables invoked directly
echo "strtoupper"("hey"), "\n";                     // HEY
echo "Box::neg"(8), "\n";                           // -8
echo [$o,"dbl"](6), "\n";                           // 12
echo ["Box","neg"](6), "\n";                        // -6
// invokable object + closure value
$cl = fn($x) => $x + 100;  echo $cl(1), "\n";       // 101
