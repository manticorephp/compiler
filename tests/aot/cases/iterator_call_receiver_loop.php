<?php
class W { public function __construct(private Iterator $i) {} public function inner(): Iterator { return $this->i; } }
$w = new W(new ArrayIterator([1, 2, 3]));
$t = 0;
for ($i = 0; $i < 100000; $i++) {
    if (!$w->inner()->valid()) { $w->inner()->rewind(); }
    $t += $w->inner()->current() + $w->inner()->key();
    $w->inner()->next();
}
echo $t, "\n";
