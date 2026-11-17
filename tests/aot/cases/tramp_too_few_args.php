<?php
// Calls that reach a method through its invoke trampoline with too few arguments throw
// ArgumentCountError (the guard's shared thrower), and the right count still runs.
class Calc {
    public function __construct(public int $base = 0) {}
    public function add(int $a, int $b): int { return $this->base + $a + $b; }
    public static function twice(int $x): int { return 2 * $x; }
}
class Need { public function __construct(int $a, int $b) { echo "need $a $b\n"; } }
function attempt(string $label, callable $f): void
{
    try { echo $label, ' = ', $f(), "\n"; }
    catch (\ArgumentCountError $e) { echo $label, ': ', \get_class($e), "\n"; }
}
$c = new Calc(10);
$m = \str_repeat('a', 1) . 'dd';
$rm = new \ReflectionMethod(Calc::class, 'add');
attempt('reflect ok', fn () => $rm->invoke($c, 1, 2));
attempt('reflect few', fn () => $rm->invoke($c, 1));
attempt('reflect args few', fn () => $rm->invokeArgs($c, []));
attempt('static few', fn () => (new \ReflectionMethod(Calc::class, 'twice'))->invoke(null));
attempt('dyn ok', fn () => $c->$m(...[3, 4]));
attempt('dyn few', fn () => $c->$m(...[3]));
$cls = 'Ne' . 'ed';
attempt('new ok', function () use ($cls) { new $cls(1, 2); return 'made'; });
attempt('new few', function () use ($cls) { new $cls(...[1]); return 'made'; });
attempt('newInstanceArgs few', function () { (new \ReflectionClass(Need::class))->newInstanceArgs([5]); return 'made'; });
