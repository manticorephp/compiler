<?php
// `new $obj->prop()` names the class by the property and passes the arguments to its constructor
// (symfony's KernelTrait::registerBundle writes `new $required->class()`).
class Req
{
    public function __construct(public string $class, public array $map = []) {}
    public function class(): string { return 'METHOD-MUST-NOT-RUN'; }
}

class A { public function __construct(public string $tag = 'default') {} }
class B { public function __construct(public string $tag = 'default') {} }

$r = new Req('A', ['x' => 'B']);
echo get_class(new $r->class()), "\n";
echo (new $r->class('given'))->tag, "\n";
echo get_class(new $r->map['x']()), "\n";
$name = 'class';
echo get_class(new $r->$name()), "\n";
$list = [new Req('B')];
echo get_class(new $list[0]->class()), "\n";
