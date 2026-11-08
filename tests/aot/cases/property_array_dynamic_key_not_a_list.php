<?php
// A bare array property stored through a key not provably an int is no list: its string keys survive a foreach (Doctrine's Parser::$identVariableExpressions).
final class Ex { public function __construct(public string $f = '') {} }
class P { private array $qc = []; private array $iv = []; private array $mixed = [];
 public function sel(mixed $e, bool $c): Ex {
   $id = null;
   if ($c) { $id = $e; }
   $expr = new Ex('x');
   $this->qc[$e] = ['parent' => null];
   if ($id) { $this->iv[$id] = $expr; }
   $this->mixed[$e] = $expr;
   return $expr; }
 public function ok(): string { foreach ($this->iv as $a => $x) { if (isset($this->qc[$a]) && !isset($this->qc[$a]['parent'])) { return 'root:' . $a; } } return 'ERR'; }
 public function keys(): array { $o = []; foreach ($this->iv as $a => $x) { $o[] = $a; } foreach ($this->mixed as $k => $x) { $o[] = $k; } return $o; }
 public function get(mixed $k): ?Ex { return $this->iv[$k] ?? null; } }
$p = new P; $p->sel('p', true); $p->sel('a', true); $p->sel(7, true); $p->sel('8', false);
echo $p->ok(), json_encode($p->keys()), $p->get('a')?->f, $p->get(7)?->f, var_export($p->get('no'), true), "\n";
class IntKeys { private array $s = []; public function put(int $i, string $v): void { $this->s[$i] = $v; } public function all(): array { return $this->s; } }
$i = new IntKeys; $i->put(3, 'c'); $i->put(1, 'a'); echo json_encode($i->all()), "\n"; foreach ($i->all() as $k => $v) { echo $k + 1, $v; } echo "\n";
