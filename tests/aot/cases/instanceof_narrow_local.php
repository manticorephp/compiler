<?php
class A { public int $n = 3; public function inc(): int { return $this->n + 1; } }
function each(mixed $x): void {
  foreach ($x as $o) {
    if ($o instanceof A) { echo "A:", $o->inc(), " "; }
  }
  echo "\n";
}
each([new A(), new A(), new A()]);
function wrapval(mixed $f): mixed { return $f; }
$a = wrapval(new A());
if ($a instanceof A) { echo "got ", $a->n, "\n"; }
$b = wrapval(42);
if ($b instanceof A) { echo "no\n"; } else { echo "not A\n"; }
