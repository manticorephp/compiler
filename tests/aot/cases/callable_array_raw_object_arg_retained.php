<?php
// An erased callable-array invoke packs its arguments into a cell list for the
// method table. An `object` argument travels as a RAW pointer: retained raw it
// was a no-op, then boxed and dropped with the list — the event was freed
// under its caller (symfony EventDispatcher::callListeners).
final class Ev { public int $n = 0; public function __destruct() { echo "dtor ", $this->n, "\n"; } }
final class L {
    public function on(object $e): void { $e->n += 1; }
    public function after(object $e): void { $e->n += 10; }
}
final class D {
    private array $ls = [];
    public function add(callable $c): void { $this->ls[] = $c; }
    public function fire(object $event): void {
        foreach ($this->ls as $l) { $l($event); }
    }
}
function dyn(object $o, string $m): void { $o->$m(new Ev()); }
$d = new D();
$l = new L();
$d->add([$l, 'on']);
$d->add([$l, 'after']);
dyn($l, 'on');
for ($i = 0; $i < 3; $i++) {
    $e = new Ev();
    $d->fire($e);
    $d->fire($e);
    echo "n=", $e->n, "\n";
    $e = null;
}
echo "end\n";
