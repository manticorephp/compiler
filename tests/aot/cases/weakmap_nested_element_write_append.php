<?php
class P { public function __construct(public int $n) {} }
class H { private ?\WeakMap $pc = null;
  public function build(array $ps): \WeakMap {
    $this->pc = new \WeakMap();
    foreach ($ps as $ep) { foreach ($ps as $e) { if ($e->n > $ep->n) {
        if (!isset($this->pc[$e])) { $this->pc[$e] = []; }
        $this->pc[$e][] = $ep; } } }
    return $this->pc; } }
$ps = [new P(1), new P(2), new P(3)];
$w = (new H)->build($ps);
foreach ($ps as $p) { echo $p->n, ':', isset($w[$p]) ? count($w[$p]) : '-', "\n"; }
$w2 = new WeakMap; $k = new P(9); $w2[$k] = []; $w2[$k][] = 1; $w2[$k][] = 2; echo count($w2[$k]), count($w2), "\n";
$ao = new ArrayObject(['a' => []]); $ao['a'][] = 1; $ao['a'][] = 2; $ao['b'] = ['x' => 1]; $ao['b']['y'] = 2;
echo count($ao['a']), count($ao['b']), "\n";
