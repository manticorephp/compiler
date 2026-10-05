<?php
// Loose `==` on an untyped promoted property is false
// issue: #49
class C { public function __construct(public $n) {} public function t() { return $this->n == 2; } }
var_dump((new C(2))->t());
