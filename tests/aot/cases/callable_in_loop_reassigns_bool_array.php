<?php
// $value = $callable($value) inside a loop turns an array holding a bool into [].
function id(array $v) { return $v; }
$value = ['a' => 'x', 'b' => true];
foreach (['id'] as $c) { $value = $c($value); }
echo json_encode($value), "\n";
