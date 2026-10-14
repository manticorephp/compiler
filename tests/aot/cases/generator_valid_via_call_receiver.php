<?php
// valid() on an unstarted Generator reached through a method-call receiver of Iterator type SIGSEGVs
function g() { yield 'a' => 1; yield 'b' => 2; }
class W { public function __construct(private Iterator $i) {} public function inner(): Iterator { return $this->i; } }
$w = new W(g());
var_dump($w->inner()->valid());
