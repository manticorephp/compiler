<?php
// A function whose every return is a bare null is typed null, so is_null() on its result holds
function f1($a) { return null; }
class K {
    protected $contextual = [];
    protected function ctx($a) { if ($this->contextual === []) { return null; } return null; }
    protected function conc($a) { return $a; }
    public function run($abstract) {
        $concrete = $this->ctx($abstract);
        if (is_null($concrete)) { $concrete = $this->conc($abstract); }
        return is_string($concrete) ? "string:$concrete" : 'other';
    }
}
$x = f1(1);
var_dump(is_null($x));
if (is_null($x)) { $x = "s"; }
var_dump($x);
echo (new K)->run('Dep'), "\n";
