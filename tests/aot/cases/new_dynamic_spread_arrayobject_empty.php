<?php
// new $cls(...$args) of ArrayObject ignores the spread array argument and builds an empty object
function mk(string $c, array $args) { return new $c(...$args); }
$a = mk('ArrayObject', [[1, 2, 3]]);
var_dump(count($a));
