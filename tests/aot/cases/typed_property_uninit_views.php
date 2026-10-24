<?php
// isset / ?? / var_dump / print_r / json / serialize / reflection see an uninitialized typed property as php does
class A { public array $a; public int $i; public ?int $n; public mixed $m; public $u; public string $s; protected int $pi; private float $pf; public int $z = 0; }
$o = new A;
var_dump(isset($o->i), $o->i ?? 'dflt', isset($o->z), property_exists($o, 'i'));
$b = new A;
var_dump($b); print_r($b); echo "\n"; var_dump((array)$b, get_object_vars($b), json_encode($b), serialize($b));
var_export($b); echo "\n";
foreach ($b as $k => $v) echo "$k\n";
$b->i = 0; var_dump(isset($b->i), $b->i ?? 'd');
$b->i = 5; unset($b->i); try { echo $b->i; } catch (Error $e) { echo $e->getMessage(), "\n"; }
var_dump(isset($b->i));
$rp = new ReflectionProperty(A::class, 'i'); var_dump($rp->isInitialized($b)); $rp->setValue($b, 3); var_dump($rp->isInitialized($b), $b->i);
$b->s = "x"; $b->n = null;
echo json_encode($b), "\n"; echo serialize($b), "\n"; var_dump($b);
$u = unserialize(serialize($b)); var_dump($u);
try { $rp->getValue(new A); } catch (Error $e) { echo $e->getMessage(), "\n"; }
class D { public int $z = 4; public string $t = "q"; }
$d = new D; var_dump(isset($d->z), $d->z); unset($d->z); var_dump(isset($d->z), $d->z ?? 'gone');
try { echo $d->z; } catch (Error $e) { echo $e->getMessage(), "\n"; }
$d->z = 0; var_dump(isset($d->z), $d->z ?? 'gone', json_encode($d));
