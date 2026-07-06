<?php
function bump(int &$v) { $v += 100; }
class Store {
    public array $ints;          // ctor list-store
    public array $names = ["a", "b", "c"];   // inline default
    public array $map;           // string-keyed
    function __construct() { $this->ints = [1, 2, 3]; $this->map = ["x" => 10]; }
    function total() { $s = 0; foreach ($this->ints as $v) { $s += $v; } return $s; }
}
$s = new Store();
// by-ref arg on ctor-store list property
bump($s->ints[1]);
echo $s->total(), "\n";               // 1+102+3 = 106
// ref-assign on inline-default property
$r = &$s->names[2];
$r = "Z";
echo $s->names[2], "\n";              // Z
// string-key property element by-ref
$m = &$s->map["x"];
$m = 999;
echo $s->map["x"], "\n";             // 999
// vivify a string key on a property
$n = &$s->map["new"];
$n = 7;
echo $s->map["new"], "\n";           // 7
