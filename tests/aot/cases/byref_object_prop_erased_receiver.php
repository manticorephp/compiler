<?php
// An object property passed by reference through an erased (mixed) receiver SIGSEGVs.
final class D {}
final class Bucket { public ?D $o = null; }
final class Sink { public function fo(?D &$x): void { $x = new D(); } }
function feed(Sink $s, mixed $o): void { $s->fo($o->o); }
$c = new Bucket();
feed(new Sink(), $c);
echo get_class($c->o), "\n";
