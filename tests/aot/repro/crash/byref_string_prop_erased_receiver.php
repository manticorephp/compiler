<?php
// A string property passed by reference through an erased (mixed) receiver SIGSEGVs.
// issue: #204
final class Bucket { public string $s = "x"; }
final class Sink { public function fs(string &$x): void { $x = $x . "abc"; } }
function feed(Sink $s, mixed $o): void { $s->fs($o->s); }
$c = new Bucket();
feed(new Sink(), $c);
echo $c->s, "\n";
