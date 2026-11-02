<?php
// By-ref foreach: storing a new value into $v never releases the element's old object (its destructor runs at exit, php runs it at the store).
// issue: #549
class O { public function __destruct() { echo "dtor\n"; } }
$a = [new O];
foreach ($a as &$v) { $v = 5; echo "stored\n"; }
unset($v);
echo "end\n";
