<?php
// A method called on an erased receiver whose declarers return arrays of different element kinds reads the join.
class Route {
    private array $methods = [];
    private array $schemes = [];
    public function __construct(string|array $methods = []) { $this->setMethods($methods); }
    public function setMethods(string|array $methods): static { $this->methods = array_map('strtoupper', (array) $methods); return $this; }
    public function getMethods(): array { return $this->methods; }
}
class Other { public function getMethods(): array { return [new stdClass()]; } }
function ids(mixed $v): mixed { return $v; }
$r = ids(new Route(['get']));
var_dump(array_map('gettype', $r->getMethods()));
foreach ($r->getMethods() as $m) { var_dump($m); }
$o = ids(new Other());
var_dump(array_map('gettype', $o->getMethods()));
