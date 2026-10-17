<?php
// intval($s, $base) with a non-literal base ignores the base and answers 0.
// issue: #325
function f(mixed $s, int $base) { var_dump(intval($s, $base)); }
f('ff', 16);
