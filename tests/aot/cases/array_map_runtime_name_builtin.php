<?php
// array_map with a callable string built at run time (a builtin or stdlib name) crashes with no output.
$n = implode('', ['str', 'len']);
var_dump(array_map($n, ['ab', 'c']));
