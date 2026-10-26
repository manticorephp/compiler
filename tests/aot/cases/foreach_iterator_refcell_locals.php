<?php
// foreach over an iterator object crashes when a reference to a function-local is held elsewhere in the program
function each_pair($src, callable $cb) {
    foreach ($src as $key => $item) { $cb($item, $key); }
}
class Keeper { public array $refs = []; public function hold(&$v) { $this->refs[] = &$v; } }
$k = new Keeper;
$z = 1;
$k->hold($z);
each_pair(new ArrayIterator(['a', 'b']), function ($x, $i) { echo $i, '=', $x, "\n"; });
