<?php
// ArgumentCountError from `$o->__construct(...$args)` names the method `C8::::construct()` instead of `C8::__construct()`
class C8 { public function __construct(public int $need) {} }
function f(array $args, object $o): void { $o->__construct(...$args); }
try { f([], new C8(1)); } catch (\ArgumentCountError $e) { echo \preg_replace('/\(\).*/', '()', $e->getMessage()), "\n"; }
