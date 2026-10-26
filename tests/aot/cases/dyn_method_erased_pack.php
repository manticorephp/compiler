<?php
// $o->{$m}(...$args) where $args is a bare `array` param: the dispatch must go through the method table.
class Builder {
    public function where(string $c, $v = null, string $b = 'and') { return "where $c " . var_export($v, true) . " $b"; }
    public function many(...$x) { return 'many ' . count($x); }
    public function sum(int $a, int $b) { return $a + $b; }
    public function str(string $s) { return strtoupper($s); }
}
class Model {
    public function __call($method, $parameters) { return $this->forward(new Builder, $method, $parameters); }
    protected function forward($object, $method, array $parameters) { return $object->{$method}(...$parameters); }
}
$m = new Model;
echo $m->where('a', 3), "\n";
echo $m->where('a'), "\n";
echo $m->sum(4, 5), "\n";
echo $m->str('abc'), "\n";
echo $m->where('x', null, 'or'), "\n";
