<?php
// array_replace() over an `(array)$object` result from an `object`-typed function throws a TypeError.
// issue: #256
function cast(object $o): array { return (array) $o; }
$a = array_replace(["x" => 1], cast(new RuntimeException("x")));
echo count($a), "\n";
