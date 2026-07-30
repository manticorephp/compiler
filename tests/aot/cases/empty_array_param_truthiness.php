<?php
// A bare `array` hint erases to KIND_UNKNOWN, so truthiness fell through to a
// raw pointer test — and `[]` lowers to the non-null empty-array singleton, so
// every empty array read as TRUE. symfony's AsCommand ctor is
// `if (!$hidden && !$aliases) { return; }`: the guard did not return and the
// body it protects rewrote $this->name from an erased array, which is how every
// #[AsCommand] command ended up with a garbage name.

function f1(array $al = []): string { if (!$al) { return 'early'; } return 'late'; }
function f2(array $al = [], bool $h = false): string { if (!$h && !$al) { return 'early'; } return 'late'; }
function f3(array $al = []): string { return $al ? 'late' : 'early'; }

class P1 { public function __construct(public string $n, array $al = []) { if (!$al) { return; } $this->n = 'CHANGED'; } }
class P2 { public string $n = ''; public function __construct(string $n, array $al = []) { $this->n = $n; if (!$al) { return; } $this->n = 'CHANGED'; } }
class P3 { public function __construct(public string $n, array $al = []) { if ($al) { $this->n = 'CHANGED'; } } }
class P4 { public string $n = 'keep'; public function set(array $al = []): void { if (!$al) { return; } $this->n = 'CHANGED'; } }
class P5 { public static function s(array $al = []): string { return $al ? 'late' : 'early'; } }

echo f1(), ' ', f1([1]), "\n";
echo f2(), ' ', f2([1]), ' ', f2([], true), "\n";
echo f3(), ' ', f3([1]), "\n";
echo (new P1('keep'))->n, ' ', (new P1('keep', [1]))->n, "\n";
echo (new P2('keep'))->n, ' ', (new P2('keep', [1]))->n, "\n";
echo (new P3('keep'))->n, ' ', (new P3('keep', [1]))->n, "\n";
$a = new P4(); $a->set(); $b = new P4(); $b->set([1]);
echo $a->n, ' ', $b->n, "\n";
echo P5::s(), ' ', P5::s([1]), "\n";

// A by-ref array param must keep aliasing the caller, not take the hint path.
function byref(array &$al): string { if (!$al) { $al[] = 'filled'; return 'was-empty'; } return 'had-items'; }
$r = [];
echo byref($r), ' ', \count($r), "\n";
