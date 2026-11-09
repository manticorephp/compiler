<?php
// `$o->m(...$args)` with a string key naming no parameter binds the value to the first parameter instead of throwing "Unknown named parameter"
// issue: #595
class C1 { public function __construct(public int $a = 0) {} }
function f(array $args): void { $o = new C1(); $o->__construct(...$args); echo $o->a, "\n"; }
try { f(['zz' => 1]); echo "no error\n"; } catch (\Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
