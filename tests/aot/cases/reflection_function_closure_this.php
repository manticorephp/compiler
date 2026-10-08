<?php
// ReflectionFunction::getClosureThis() always answers null, even for a closure bound to $this.
class K {
    public function make(): Closure { return function () { return 1; }; }
}
$r = new ReflectionFunction((new K)->make());
echo get_class($r->getClosureThis()), "\n";
