<?php
class C { public static int $f = 0; }
class T {
    public string $n = 'alive';
    public function __destruct() { C::$f = C::$f + 1; }
}
function take(): T {
    $m = ['a' => new T()];
    $keep = $m['a'];   // a BORROW today: no reference of its own
    unset($m);         // the array dies, releasing its elements
    return $keep;      // must still be valid
}
$x = take();
echo "freed_before_return=", C::$f, " (php: 0)\n";
echo "value=", $x->n, " (php: alive)\n";
