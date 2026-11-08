<?php
// A variadic method whose name other classes declare differently is packed once inference knows the receiver's class (Doctrine's createQueryBuilder()->select($alias)).
class QB { public array $s = []; public function select(mixed ...$sel): static { $this->s[] = json_encode($sel); return $this; }
  public function two(string $a, int ...$r): array { return [$a, $r]; } }
class DB { public function select(string $a, string ...$e): self { return $this; } public function two($x) { return $x; } }
class RD { public function select($db) { return 1; } }
function mk(): QB { return new QB; }
function viaLocal(string $a): QB { $q = mk(); return $q->select($a); }
function viaTwo(string $a): QB { $q = mk(); return $q->select($a, 'b'); }
function viaArr(): QB { $q = mk(); return $q->select(['x', 'y']); }
function viaNone(): QB { $q = mk(); return $q->select(); }
function viaSpread(array $l): QB { $q = mk(); return $q->select(...$l); }
function fixed(): array { $q = mk(); return [$q->two('a'), $q->two('a', 1), $q->two('a', 1, 2)]; }
function loop(): array { $out = []; foreach ([1, 2] as $i) { $q = mk(); $out[] = $q->select($i)->s; } return $out; }
echo json_encode([viaLocal('p')->s, viaTwo('a')->s, viaArr()->s, viaNone()->s, viaSpread([1, 2])->s, fixed(), loop()]), "\n";
