<?php
// An interface's methods cannot be reflected: hasMethod() is false and getMethod() throws
// issue: #196
interface Speaks { public function speak(): string; }
class Dog implements Speaks { public function speak(): string { return 'woof'; } }
$r = new ReflectionClass('Speaks');
var_dump($r->hasMethod('speak'));
echo $r->getMethod('speak')->class, "\n";
