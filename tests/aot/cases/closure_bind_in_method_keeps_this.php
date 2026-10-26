<?php
// closure bound to another object from inside an instance method keeps the bound $this
class Pv { private $a = 'pa'; protected $b; function dump() { return [$this->a, $this->b]; } }
class H {
    public function hydrate(object $o, array $names, array $row): void {
        $set = \Closure::bind(function (array $names, array $row): void {
            for ($i = 0; $i < count($names); $i++) { $k = $names[$i]; $this->$k = $row[$i]; }
        }, $o, \get_class($o));
        $set($names, $row);
    }
}
$o = new Pv();
(new H())->hydrate($o, ['a', 'b'], ['A', 'B']);
echo json_encode($o->dump()), "\n";
