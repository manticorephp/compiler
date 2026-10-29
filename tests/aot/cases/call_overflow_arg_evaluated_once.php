<?php
class A { public function find() { return 'A:' . implode(',', func_get_args()); } }
class B { public function find($name) { return "B:$name"; } }
$cnt = 0;
function side($v) { global $cnt; $cnt++; return $v; }
function run($f) { global $cnt; $cnt = 0; $x = 'q'; $r = $f->find($x = side('v')); return $r . '/' . $cnt . '/' . $x; }
echo run(new A), ' ', run(new B), "\n";
