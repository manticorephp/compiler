<?php
// Destruct order of globals at normal script end differs from php
// issue: #45
final class D { public function __construct(public string $n) {} public function __destruct() { echo $this->n, "\n"; } }
$b = new D('b');
$a = new D('a');
$c = new D('c');
function f(): void { $l = new D('local'); exit(0); }
f();
