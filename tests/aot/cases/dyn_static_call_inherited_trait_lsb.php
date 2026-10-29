<?php
trait Creator { public static function instance($d) { return new static($d); } public function __construct(public $v = 0) {} }
trait Date { use Creator; }
class Base { use Date; }
class Child extends Base {}
class Factory {
    public function __call($method, $parameters) {
        $cls = 'Child';
        if (method_exists($cls, $method) || ! method_exists(Base::class, 'hasMacro')) {
            return $cls::$method(...$parameters);
        }
        return null;
    }
}
$f = new Factory;
$o = $f->instance(5);
echo get_class($o), ' ', $o->v, "\n";
