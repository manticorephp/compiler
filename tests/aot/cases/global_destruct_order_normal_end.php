<?php
// Objects the global scope holds alone are destroyed in reverse order of declaration at normal script end.
final class D { public function __construct(public string $n) {} public function __destruct() { echo $this->n, "\n"; } }
$b = new D('b');
$a = new D('a');
$c = new D('c');
echo "end\n";
