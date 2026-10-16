<?php
// ReflectionMethod->class and getDeclaringClass() of an inherited method name the subclass instead of the declaring class
// issue: #195
abstract class Animal { public function eat(): string { return 'eat'; } }
class Dog extends Animal {}
$m = new ReflectionMethod('Dog', 'eat');
echo $m->class, ' ', $m->getDeclaringClass()->getName(), "\n";
