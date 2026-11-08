<?php
// isset($o->$name) and (array)$o on an erased receiver of any class: only a class with a bag has one (Twig's getAttribute).
class Pg { public const P = 10; private int $n = 1; private ?array $r = null; protected $q; public int $pub = 5; public function getN(): int { return $this->n; } }
#[AllowDynamicProperties] class Dy { public int $a = 1; }
final class Ent { private ?int $id = null; private string $t = 'x'; }
function g(mixed $object, mixed $item): string {
  $o = [];
  $o[] = isset($object->$item) ? 'set' : 'no';
  $o[] = \array_key_exists((string) $item, (array) $object) ? 'ak' : 'na';
  if (isset($object->$item)) { $o[] = get_debug_type($object->$item); }
  return implode(',', $o);
}
foreach ([new Pg, new Ent, new stdClass, new Dy, new DateTime('2020-01-01'), new Exception('x')] as $ob) {
  foreach (['pub', 'nope', 'getN', 'a', 'zz', 0] as $i) { echo get_class($ob), '.', $i, '=', g($ob, $i), ' '; }
  echo "\n";
}
$d = new Dy; $d->z = 5; echo g($d, 'z'), g($d, 'a'), "\n";
$s = new stdClass; $s->q = [1]; echo g($s, 'q'), "\n";
