<?php
class A { static function hi($x) { return "hi $x"; } }
function f($v, $m) { return is_string($v) ? $v::{$m}(1) : $v::{$m}(2); }
echo f('A', 'hi'), "\n", f(new A, 'hi'), "\n";
