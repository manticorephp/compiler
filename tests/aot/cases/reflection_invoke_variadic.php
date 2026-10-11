<?php
class Sum {
    public function add(int $x, int ...$more): int { return $x + array_sum($more); }
    public static function sadd(string $p, string ...$m): string { return $p . implode("-", $m); }
}
function vf(int ...$z): int { return array_sum($z); }
$m = new ReflectionMethod(Sum::class, 'add');
echo $m->invoke(new Sum(), 1, 2, 3), "\n";
echo $m->invoke(new Sum(), 4), "\n";
echo $m->invokeArgs(new Sum(), [1, 2, 3, 4]), "\n";
echo $m->getClosure(new Sum())(1, 2, 3), "\n";
echo (new ReflectionMethod(Sum::class, 'sadd'))->invoke(null, "a", "b", "c"), "\n";
echo (new ReflectionFunction('vf'))->invoke(1, 2, 3), "\n";
$mm = 'add'; echo (new Sum())->$mm(1, 2, 3), "\n";
