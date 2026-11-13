<?php
// ReflectionMethod::hasReturnType() is true for an internal method whose return type is only tentative
$m = new ReflectionMethod(Countable::class, 'count');
var_dump($m->hasReturnType(), $m->hasTentativeReturnType());
$m = new ReflectionMethod(ArrayAccess::class, 'offsetGet');
var_dump($m->hasReturnType(), $m->hasTentativeReturnType());
