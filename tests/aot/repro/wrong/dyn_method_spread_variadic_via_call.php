<?php
// A variadic method reached through __call and a dynamic spread receives the whole argument array as one argument.
// issue: #476
class Builder { public function many(...$x) { return 'many ' . count($x); } }
class Model {
    public function __call($method, $parameters) { return $this->forward(new Builder, $method, $parameters); }
    protected function forward($object, $method, array $parameters) { return $object->{$method}(...$parameters); }
}
echo (new Model)->many(1, 2, 3), "\n";
