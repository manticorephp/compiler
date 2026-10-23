<?php
class A { public int $p = 1; }
class B extends A { public function __construct(public string $p) {} }
