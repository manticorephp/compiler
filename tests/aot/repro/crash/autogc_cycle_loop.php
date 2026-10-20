<?php
// Automatic cycle collection crashes (SIGBUS) in a loop of two-object cycles when the program references gc_collect_cycles().
// issue: #374
class A { public $o; }
if ($argc > 5) { gc_collect_cycles(); }
for ($i = 0; $i < 300000; $i++) {
    $x = new A; $y = new A;
    $x->o = $y; $y->o = $x;
}
echo "done\n";
