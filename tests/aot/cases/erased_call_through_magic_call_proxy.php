<?php
class Proxy { public function __construct(public $target) {} public function __call($m, $p) { $this->target->$m(...$p); return $this->target; } }
class Thing { public array $log = []; public function add(array $a): void { $this->log[] = $a; } }
/**
 * @template TValue
 * @param  TValue  $value
 * @param  (callable(TValue): mixed)|null  $callback
 * @return ($callback is null ? \Proxy<TValue> : TValue)
 */
function tap($value, $callback = null) {
    if (is_null($callback)) { return new Proxy($value); }
    $callback($value);
    return $value;
}
$t = tap(new Thing)->add([1, 2]);
echo get_class($t), count($t->log), "\n";
$u = tap(new Thing, fn($x) => $x->add([3]));
echo get_class($u), count($u->log), "\n";
