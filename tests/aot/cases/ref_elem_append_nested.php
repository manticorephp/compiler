<?php
// `$r = &$a[...]` promotes the element into a reference box: nested, appended,
// and surviving the buffer's relocation.
$x = []; $r = &$x['k'][]; $r = 7; $r2 = &$x['k'][]; $r2 = 8; echo json_encode($x), "\n";
$y = []; $q = &$y[]; $q = 'a'; echo json_encode($y), "\n";
$n = []; $n['k'][] = 0; $w = &$n['k'][0]; $w = 9; echo $n['k'][0], ' ', json_encode($n), "\n";
final class D { public array $o = [];
  public function a(string $e): void { $c = &$this->o[$e][]; $c = 'v1'; $c = &$this->o[$e][]; $c = 'v2'; } }
$d = new D(); $d->a('e'); echo json_encode($d->o), "\n";
function f(mixed $v): void { foreach ($v as $k => $x) { echo $k, ':', gettype($x), ':', $x, ' '; } echo "\n"; }
$m = ['a' => 1, 'b' => 'z']; $p = &$m['a']; $p = 5; f($m);
