<?php
// A program with an ArrayAccess class makes an untyped array property "maybe an object": the value of
// `$this->cache[$k] = $v` used as an expression must still be $v.
class L { private $cache = [];
  public function f(string $n) { if (isset($this->cache[$n])) { return $this->cache[$n]; } $r = strtoupper($n); return $this->cache[$n] = $r; }
  public function g(string $n) { return $this->cache[$n] = strtoupper($n) . '!'; }
  public function h(int $i) { return $this->cache[] = $i * 2; } }
$l = new L;
var_dump($l->f('a'), $l->f('a'), $l->g('b'), $l->g('b'), $l->h(4), $l->h(5));
if (count($argv) > 5) { $o = new ArrayObject([]); }
