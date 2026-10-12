<?php
abstract class Animal { public function eat(): string { return 'eat'; } public function run(): string { return 'a'; } }
class Dog extends Animal { public function run(): string { return 'd'; } }
$m = new ReflectionMethod('Dog', 'eat');
echo $m->class, ' ', $m->getDeclaringClass()->getName(), "\n";
$m = new ReflectionMethod('Dog', 'run');
echo $m->class, ' ', $m->getDeclaringClass()->getName(), "\n";
