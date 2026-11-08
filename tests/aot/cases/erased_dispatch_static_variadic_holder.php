<?php
// An erased-receiver call must not take a static variadic holder (a trait's `static dispatch(...$args)`) as its signature
trait Dispatchable { public static function dispatch(...$arguments) { return 'static:' . count($arguments); } }
class Evt { use Dispatchable; }
class Dispatcher { public function dispatch($event, $payload = [], $halt = false) { return "D:$event:" . count($payload); } }
class Cont implements ArrayAccess {
    protected $b = [];
    public function offsetExists(mixed $k): bool { return isset($this->b[$k]); }
    public function offsetGet(mixed $k): mixed { return $this->b[$k]; }
    public function offsetSet(mixed $k, mixed $v): void { $this->b[$k] = $v; }
    public function offsetUnset(mixed $k): void { unset($this->b[$k]); }
    public function run() { return $this['events']->dispatch('x', [$this]); }
}
$c = new Cont; $c['events'] = new Dispatcher;
echo $c->run(), "\n";
