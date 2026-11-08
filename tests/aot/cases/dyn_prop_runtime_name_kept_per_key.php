<?php
class H { public function __construct(public string $n) {} public function __destruct() { echo "drop {$this->n}\n"; } }
function esc() { $o = new stdClass(); for ($j = 0; $j < 3; $j++) { $k = "key$j"; $o->$k = $j * 10; } return $o; }
function noise() { $a = []; for ($i = 0; $i < 2000; $i++) { $a[] = "zzzzzzzzzz$i"; } return count($a); }
$o = esc(); noise();
var_dump($o); foreach ($o as $k => $v) { echo "$k=$v\n"; }
function dt() { $o = new stdClass(); $k = "a" . strlen("xx"); $o->$k = new H("arena-key"); echo "set\n"; }
dt(); echo "after dt\n";
function dt2() { $o = new stdClass(); $k = str_repeat("a", 2); $o->$k = new H("heap-key"); echo "set\n"; }
dt2(); echo "after dt2\n";
function dt3() { $o = []; $k = "a" . strlen("xx"); $o[$k] = new H("arr-arena-key"); echo "set\n"; }
dt3(); echo "after dt3\n";
function arrk() { $a = []; for ($j = 0; $j < 3; $j++) { $k = "key$j"; $a[$k] = $j; } return $a; }
$a = arrk(); noise(); var_dump($a);
function rd() { $o = esc(); for ($j = 0; $j < 3; $j++) { $k = "key$j"; echo $o->$k, ","; } echo "\n"; var_dump(isset($o->key1), property_exists($o, 'key2')); }
rd();
function direct() { $o = new stdClass(); foreach ([1, 2] as $j) { $o->{"p$j"} = $j; } return get_object_vars($o); }
var_dump(direct());
