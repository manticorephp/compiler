<?php
// ReflectionReference::fromArrayElement() answers null for a plain value and rejects a missing key.
$a = ['x' => 1, 'y' => [2, 3], 5 => 'five'];
var_dump(ReflectionReference::fromArrayElement($a, 'x'));
var_dump(ReflectionReference::fromArrayElement($a, 'y'));
var_dump(ReflectionReference::fromArrayElement($a, 5));
try {
    ReflectionReference::fromArrayElement($a, 'missing');
} catch (ReflectionException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
