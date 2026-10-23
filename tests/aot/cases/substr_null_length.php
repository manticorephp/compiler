<?php
// substr($s, $o, null) with a null length returns "" instead of the rest of the string.
function f(mixed $len) { var_dump(substr('hello', 1, $len)); }
f(null);
f(2);
f('3');
function g(?int $len) { var_dump(substr('hello', 1, $len)); }
g(null);
g(2);
var_dump(substr('hello', 1, null));
