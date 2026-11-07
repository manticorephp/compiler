<?php
// A `callable` return of a cell property takes a count and compares equal to itself:
// the array callable was released once per listener and freed under its owner.
class Svc { public function index(): string { return 'ok'; } }
final class CE { private array|string|object $controller; public function __construct(callable $c) { $this->controller = $c; }
    public function getController(): callable { return $this->controller; } }
final class CAE { public function __construct(private CE $e) {} public function getController(): callable { return $this->e->getController(); } }
function listener(CE|CAE $event): int {
    $controller = $event->getController();
    $seen = 0;
    foreach ([1, 2, 3] as $i) {
        $c = $event->getController();
        if ($c instanceof \Closure ? $c != $controller : $c !== $controller) { $controller = $c; $seen++; }
    }
    return $seen;
}
function raw(Svc $s): string {
    $ev = new CE([$s, 'index']);
    $n = listener($ev);
    $ev2 = new CAE($ev);
    $n += listener($ev2) + listener($ev2) + listener($ev2);
    $pad = ['x' . random_int(0, 9), 'y'];
    $controller = $ev2->getController();
    return $controller() . $n . count($pad);
}
$s = new Svc;
for ($i = 0; $i < 3; $i++) { echo raw($s), "\n"; }
$fn = fn() => 'closure';
$e = new CE($fn);
echo listener($e), ($e->getController())(), "\n";
