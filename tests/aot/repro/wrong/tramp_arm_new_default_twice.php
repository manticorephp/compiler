<?php
// A reflection invoke that omits a `new` default evaluates the default twice (per-arity trampoline arms).
class Box { public static int $made = 0; public function __construct(public string $tag = 'box') { self::$made++; } }
class Svc {
    public function run(int $a, string $s = 'x', Box $nb = new Box('fresh')): string { return $s . $nb->tag; }
    public function n(): int { return func_num_args(); }
}
$rm = new ReflectionMethod(Svc::class, 'run');
echo $rm->invoke(new Svc(), 1), ' ', Box::$made, "\n";
echo $rm->invoke(new Svc(), 1, 'y'), ' ', Box::$made, "\n";
