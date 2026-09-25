<?php
class M1 { public array $d = []; public function __get($n) { return "M1:$n"; } public function __set($n, $v) { $this->d[] = "M1.$n=$v"; } }
class M2 { public array $d = []; public int $known = 5; public function __get($n) { return strlen($n); } public function __set($n, $v) { $this->d[] = "M2.$n=$v"; } }
class M3 { public array $d = []; public function __get($n) { return "m3"; } public function __set($n, $v) { $this->d[] = "M3.$n=$v"; } }
class Plain { public $color = 'red'; public $known = 1; }
/** @param mixed $o */
function rd($o, int $i) { return $i === 0 ? $o->color : $o->known; }
/** @param mixed $o */
function wr($o, $v): void { $o->color = $v; $o->known = 9; }
$objs = [new M1(), new M2(), new M3(), new Plain()];
foreach ($objs as $o) {
    var_dump(rd($o, 0), rd($o, 1));
    wr($o, 'blue');
}
echo implode(',', $objs[0]->d), '|', implode(',', $objs[1]->d), '|', implode(',', $objs[2]->d), "\n";
echo $objs[1]->known, ' ', $objs[3]->color, ' ', $objs[3]->known, "\n";
