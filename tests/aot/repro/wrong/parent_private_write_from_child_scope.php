<?php
// A write to $this->a from a child class scope creates a dynamic property when the parent's $a is private; the binary overwrites the parent's private.
// issue: #475
class Base { private $a = 'parent-private'; public function pa() { return $this->a; } }
#[AllowDynamicProperties]
class Child extends Base {}
function hydrate(object $o): void
{
    $set = Closure::bind(function (): void { $this->a = 'x'; }, $o, get_class($o));
    $set();
}
$c = new Child();
hydrate($c);
echo $c->pa(), "\n";
print_r($c);
