<?php
// Overwriting an object variable with null (or another object) destructs the old value right
// there — also when the variable is never read again: DeadStore dropped the unread `$x = null`
// and the destructor ran at scope exit instead.
class D { public function __construct(public string $n) {} public function __destruct() { echo "destruct ", $this->n, "\n"; } }
$x = new D("a"); $x = null; echo "after a\n";
$y = new D("b"); $y = new D("c"); echo "after b\n";
function f(): void { $z = new D("z"); $z = null; echo "after z\n"; }
f();
$w = new D("w"); unset($w); echo "after w\n";
