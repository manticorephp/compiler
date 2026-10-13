<?php
class P { public $pub = 1; protected $pro = 2; private $pri = 3; }
#[AllowDynamicProperties]
class C extends P { private $pri2 = 30; public $own = 4; }
$o = new C; $o->dyn = 5;
foreach (get_mangled_object_vars($o) as $k => $v) { echo str_replace("\0", "~", $k), "=", $v, " "; }
echo "\n";
echo count(get_mangled_object_vars(new stdClass)), "\n";
