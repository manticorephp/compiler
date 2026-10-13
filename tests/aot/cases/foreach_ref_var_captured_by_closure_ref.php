<?php
// A by-reference foreach variable captured by reference in a closure binds each element, as php does.
$l = [1, 2];
foreach ($l as &$x) {
    $c = function () use (&$x) { return $x; };
}
unset($x, $c);
var_dump($l);
$s = str_repeat('a', 3);
$m = [[$s, 'on']];
foreach ($m as &$y) {
    if ($y[0] === 'z') { $d = function () use (&$y) { return $y; }; }
}
unset($y);
echo count($m[0]), "\n";
$arr = [1, 2, 3];
$fs = [];
foreach ($arr as &$v) { $fs[] = function () use (&$v) { return $v; }; }
unset($v);
foreach ($fs as $f) { echo $f(), "\n"; }
$arr[0] = 9;
echo $fs[0](), "\n";
function g(array $a): array { $o = []; foreach ($a as &$x) { $o[] = static function () use (&$x) { $x = $x * 10; return $x; }; } return $o; }
foreach (g([4, 5]) as $f) { echo $f(), "\n"; }
final class Ev { public array $log = []; }
final class L {
    public function __construct(public string $n) {}
    public function on(Ev $e, string $name): void { $e->log[] = $this->n . ':' . $name; }
}
final class D {
    private array $listeners = [];
    private ?array $optimized = [];
    public function addListener(string $eventName, callable|array $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][$priority][] = $listener;
        unset($this->optimized[$eventName]);
    }
    public function dispatch(Ev $event, string $eventName): Ev
    {
        $listeners = $this->optimized[$eventName] ?? (empty($this->listeners[$eventName]) ? [] : $this->optimizeListeners($eventName));
        foreach ($listeners as $listener) { $listener($event, $eventName, $this); }
        return $event;
    }
    private function optimizeListeners(string $eventName): array
    {
        krsort($this->listeners[$eventName]);
        $this->optimized[$eventName] = [];
        foreach (array_merge(...$this->listeners[$eventName]) as &$listener) {
            $closure = &$this->optimized[$eventName][];
            if (\is_array($listener) && isset($listener[0]) && $listener[0] instanceof \Closure && 2 >= \count($listener)) {
                $closure = static function (...$args) use (&$listener, &$closure) {
                    if ($listener[0] instanceof \Closure) {
                        $listener[0] = $listener[0]();
                        $listener[1] ??= '__invoke';
                    }
                    ($closure = $listener(...))(...$args);
                };
            } else {
                $closure = $listener(...);
            }
        }
        return $this->optimized[$eventName];
    }
}
$d = new D();
foreach ([['a', 10], ['b', 5], ['c', 5], ['r', 32]] as [$n, $p]) {
    $o = new L($n);
    $d->addListener('k', [static fn () => $o, 'on'], $p);
}
$d->addListener('k', [new L('plain'), 'on'], 1);
$d->addListener('k', static function (Ev $e) { $e->log[] = 'closure'; }, 0);
$e = $d->dispatch(new Ev(), 'k');
echo implode(',', $e->log), "\n";
$e = $d->dispatch(new Ev(), 'k');
echo implode(',', $e->log), "\n";
