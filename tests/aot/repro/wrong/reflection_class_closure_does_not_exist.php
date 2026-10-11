<?php
// new ReflectionClass('Closure') throws "Class Closure does not exist".
// issue: #198
$r = new ReflectionClass('Closure');
var_dump($r->getName(), $r->isFinal());
var_dump((new ReflectionClass(fn() => 1))->getName());
