<?php
// substr($s, $o, null) with a null length returns "" instead of the rest of the string.
// issue: #321
function f(mixed $len) { var_dump(substr('hello', 1, $len)); }
f(null);
