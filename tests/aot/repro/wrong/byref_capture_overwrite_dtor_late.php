<?php
// file-scope use(&$x): overwriting $x inside the closure must destroy the old object at once; native destroys it only at shutdown
// issue: #69
// item: 15
class D { function __construct(public string $n) {} function __destruct() { echo "dtor {$this->n}\n"; } }
$x = new D("x");
$inc = function () use (&$x) { $x = new D("x2"); };
$inc();
echo "end\n";
