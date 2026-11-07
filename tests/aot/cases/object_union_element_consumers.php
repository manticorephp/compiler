<?php
// A value typed by a union of classes (two returns of different object arrays) converts, dispatches and reports as the object it holds (Symfony's String split).
abstract class A { public string $s = ''; public function __toString(): string { return $this->s; } public function me(): string { return static::class; }
  /** @return static[] */ public function split(?int $f = null): array { $c = clone $this; $c->s = 'pa'; return [$c, clone $c]; } }
class B extends A { public function __construct(string $s = '') { $this->s = $s; }
  public function split(?int $f = null): array { if (null !== $f) { return parent::split($f); } $c = clone $this; $c->s = 'ch'; return [$c, clone $c]; } }
function str(string $x): string { return "<$x>"; }
function ns(?string $x = ''): string { return '[' . ($x ?? 'null') . ']'; }

function obj(object $x): string { return get_class($x); }
function ty(A $x): string { return $x->s; }
function mix(mixed $x): string { return get_debug_type($x); }
$l = (new B('z'))->split();
$e = $l[0];
echo str($e), ns($e), obj($e), ty($e), mix($e), "\n";
echo get_debug_type($e), ' ', gettype($e), ' ', get_class($e), ' ', (is_object($e) ? 'o' : 'n'), ' ', ($e instanceof B ? 'B' : 'x'), ' ', ($e instanceof Stringable ? 'S' : 'x'), "\n";
echo $e, ' ', (string)$e, ' ', "i{$e}", ' ', $e . '!', ' ', strlen($e), ' ', strtoupper($e), ' ', $e->me(), ' ', $e->s, "\n";
var_dump($e == $l[1], $e === $l[1], $e === $e, isset($e->s), empty($e), (bool)$e, is_callable($e), method_exists($e, 'me'), property_exists($e, 's'));
var_dump($e); print_r($e); echo "\n", json_encode($e), serialize($e), "\n";
var_export($e); echo "\n";
echo json_encode((array)$e), json_encode(get_object_vars($e)), "\n";
$k = clone $e; $k->s = 'K'; echo $e->s, $k->s, "\n";
echo implode(',', $l), ' ', implode(',', array_map(static fn ($x) => ns($x), $l)), ' ', implode(',', array_map(static fn ($x) => get_debug_type($x), $l)), "\n";
foreach ($l as $x) { echo ns($x), get_debug_type($x); } echo "\n";
echo match (true) { $e instanceof B => 'mB', default => 'md' }, ' ', str_contains($e, 'c') ? 'y' : 'n', "\n";

