<?php
function f(mixed $o): string { return $o === null ? "null" : "set"; }
echo f(null), "\n";
echo f(5), "\n";
echo f("x"), "\n";
class Box { private mixed $d;
  public function __construct(mixed $x) { $this->d = $x; }
  public function put(mixed $o, mixed $v): void { if ($o === null) { $this->d[] = $v; } else { $this->d[$o] = $v; } }
  public function n(): int { return count($this->d); }
}
$b = new Box([]); $b->put(null, 1); $b->put(null, 2); $b->put("k", 3);
echo "count=", $b->n(), "\n";
