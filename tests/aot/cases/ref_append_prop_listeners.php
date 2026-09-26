<?php
final class D {
    private array $listeners = [];
    private array $optimized = [];
    public function add(string $e, callable $l, int $p = 0): void { $this->listeners[$e][$p][] = $l; }
    public function opt(string $eventName): array {
        krsort($this->listeners[$eventName]);
        $this->optimized[$eventName] = [];
        foreach ($this->listeners[$eventName] as &$listeners) {
            foreach ($listeners as &$listener) {
                $closure = &$this->optimized[$eventName][];
                $closure = $listener(...);
            }
        }
        return $this->optimized[$eventName];
    }
}
$d = new D();
$d->add('x', static function (string $a) { echo "L1 $a\n"; });
$d->add('x', static function (string $a) { echo "L2 $a\n"; }, 5);
foreach ($d->opt('x') as $c) { $c('go'); }
echo count($d->opt('x')), "\n";
