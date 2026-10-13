<?php
// A closure returning a mixed float 0.0, called through a callable parameter, yields NULL instead of float(0)
// issue: #229
function show(callable $f): void { var_dump($f()); }
show(fn() => json_decode('0.0'));
show(fn() => current([0.0]));
show(fn() => json_decode('0.5'));
