<?php
// Two references to the same by-ref param stored in one callee do not alias each other.
// issue: #351
class T { public array $r = []; function k(string &$v) { $this->r[] = &$v; $this->r[] = &$v; } }
$t = new T; $y = "a"; $t->k($y); $t->r[0] = "b"; echo $t->r[1], "\n";
class B { public array $r = []; function __construct(mixed &$v) { $this->r[] = &$v; $v = 2; echo $this->r[0], "\n"; } }
$x = 1; new B($x);
