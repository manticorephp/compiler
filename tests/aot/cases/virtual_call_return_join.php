<?php

// Each implementer's return is narrowed on its own; a call through the
// interface / the base class must answer each one's value, not the first's type.
interface Keyed { public function k(): mixed; }
final class IntKey implements Keyed { private int $p = 5; public function k(): mixed { return $this->p; } }
final class StrKey implements Keyed { public function __construct(private string $s) {} public function k(): mixed { return $this->s; } }
final class FloatKey implements Keyed { public function k(): mixed { return 1.5; } }

function viaIface(Keyed $k): mixed { return $k->k(); }
var_dump(viaIface(new StrKey('str')), viaIface(new IntKey()), viaIface(new FloatKey()));
$o = [];
foreach ([new StrKey('x'), new IntKey(), new FloatKey()] as $k) { $o[] = (string)viaIface($k) . '/' . gettype($k->k()); }
echo implode(',', $o), "\n";

abstract class Shape { public function label(): mixed { return 0; } }
class Sq extends Shape { public function label(): mixed { return 'square'; } }
class Tri extends Shape {}
function lab(Shape $s): string { return (string)$s->label(); }
echo lab(new Sq()), ' ', lab(new Tri()), "\n";
