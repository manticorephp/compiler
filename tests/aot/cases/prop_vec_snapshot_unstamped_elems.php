<?php
// A snapshot `$x = $n->args` of an array whose object elements carry no
// element hint (a vec of an object UNION, built by appends) must co-own the
// elements its release gives back: the copy adopted nothing while its release
// dropped every object, and the property still holding them freed them again.
abstract class Nd { public function __construct(public string $s) {} }
final class A extends Nd {}
final class B extends Nd {}
final class NewO extends Nd {
    /** @param Nd[] $args */
    public function __construct(public string $cls, public array $args) { parent::__construct('new'); }
}
final class Em {
    public function emit(NewO $n): int { $x = $n->args; $c = count($x); unset($x); return $c; }
}
function lower(array $raw): array { $out = []; foreach ($raw as $r) { $out[] = $r === 'a' ? new A($r . str_repeat('1', 20)) : new B($r . str_repeat('2', 20)); } return $out; }
$n = new NewO('C', lower(['a', 'b']));
$keep = [$n->args[0], $n->args[1]];
$e = new Em();
for ($i = 0; $i < 3; $i++) { $e->emit($n); }
$n->args = [];
$junk = [];
for ($i = 0; $i < 50; $i++) { $junk[] = new A(str_repeat('j', 20) . $i); }
echo $keep[0]->s, ' ', $keep[1]->s, "\n";
