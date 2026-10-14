<?php
class C {
    public function inc(int &$x, int $by = 1): int { $x += $by; return $x; }
    public static function app(array &$a, string $v): void { $a[] = $v; }
}
$m = new ReflectionMethod('C', 'inc');
$n = 5;
$args = [&$n, 10];
echo $m->invokeArgs(new C, $args), "|$n\n";
$args = [&$n];
echo $m->invokeArgs(new C, $args), "|$n\n";
echo $m->getClosure(new C)($n, 2), "\n";
$list = ['a'];
(new ReflectionMethod('C', 'app'))->invokeArgs(null, [&$list, 'b']);
echo implode(",", $list), "\n";
