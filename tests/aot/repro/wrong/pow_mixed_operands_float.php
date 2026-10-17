<?php
// pow() of mixed ints answers float(1024) instead of int(1024).
// issue: #322
function f(mixed $a, mixed $b) { var_dump(pow($a, $b)); }
f(2, 10);
