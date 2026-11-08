<?php
// A docblock `\Closure(A): T|class-string<T>` is a union, not a closure: a class-name string reaches the param untouched
class Dep {}
class K {
    /**
     * @template TClass of object
     *
     * @param  \Closure(static, array): TClass|class-string<TClass>  $concrete
     * @return TClass
     */
    public function build($concrete) {
        if ($concrete instanceof Closure) { return $concrete($this); }
        try { $r = new ReflectionClass($concrete); } catch (ReflectionException $e) { return 'none'; }
        return $r->getName();
    }
}
$k = new K;
echo $k->build(Dep::class), "\n";
echo $k->build(fn ($x) => new Dep) instanceof Dep ? "closure ok\n" : "bad\n";
