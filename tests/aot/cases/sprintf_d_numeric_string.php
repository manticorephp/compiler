<?php
// sprintf('%d', '42') with a numeric string argument prints a pointer instead of 42.
var_dump(sprintf('%d', '42'));
var_dump(sprintf('%05d|%x|%.2f', '42', '255', '3.14159'));
function f(string $s) { return sprintf('%d-%f', $s, $s); }
var_dump(f('7'));
printf("%d\n", '12');
