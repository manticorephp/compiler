<?php
// PHP arrays are values: clone must value-copy an array property even when a
// bare `array` hint erased the element type to unknown (typed props already
// worked). Covers bare / typed / constructor-promoted array properties.
class B { public array $items = []; }
class T { /** @var int[] */ public array $it = []; }
class P { public function __construct(public array $d = []) {} }
$a = new B; $a->items[] = 1; $b = clone $a; $b->items[] = 2;
echo count($a->items), " ", count($b->items), "\n";   // 1 2
$c = new T; $c->it[] = 1; $d = clone $c; $d->it[] = 2;
echo count($c->it), " ", count($d->it), "\n";          // 1 2
$e = new P; $e->d[] = 1; $f = clone $e; $f->d[] = 2;
echo count($e->d), " ", count($f->d), "\n";            // 1 2
