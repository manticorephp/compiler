<?php
// Reading an undeclared property of a class-typed object through ?? / isset returns the object's first field instead of null.
function viaThrowable(Throwable $t) { return $t->nope ?? 'dflt'; }
function viaClass(RuntimeException $t) { return $t->nope ?? 'dflt'; }
function viaIsset(Throwable $t) { return isset($t->nope); }
function viaNullsafe(?Throwable $t) { return $t?->nope ?? 'dflt'; }
interface I {}
class C implements I { public string $m = 'mm'; }
function viaIface(I $o) { return $o->nope ?? 'dflt'; }
function viaIssetIface(I $o) { return isset($o->nope) ? 'set' : 'unset'; }
try { throw new ValueError('x'); } catch (Throwable $e) { echo json_encode($e->errorInfo ?? null), "\n"; }
var_dump(viaThrowable(new RuntimeException('z')));
var_dump(viaClass(new RuntimeException('z')));
var_dump(viaIsset(new RuntimeException('z')));
var_dump(viaNullsafe(new LogicException('z')));
var_dump(viaNullsafe(null));
var_dump(viaIface(new C));
var_dump(viaIssetIface(new C));
$s = new stdClass; $s->nope = 'dyn';
function viaObj(object $o) { return $o->nope ?? 'dflt'; }
var_dump(viaObj($s), viaObj(new C));
class Holder extends Exception { public ?array $info = ['h']; }
class Big extends Error { public string $a = 'A'; public string $b = 'B'; }
function oneHolder(Throwable $e) { return json_encode($e->info ?? null); }
echo oneHolder(new Big('v')), ' ', oneHolder(new Holder('h')), "\n";
function oneHolderMixed(mixed $e) { return json_encode($e->info ?? null); }
echo oneHolderMixed(new Big('v')), ' ', oneHolderMixed(new Holder('h')), "\n";
