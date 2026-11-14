<?php
function f(int $x): int { return $x * 2; }
function g(): int { return 1; }
class C
{
    public static function s(): int { return 7; }
    public function cmp(int $a, int $b): int { return $a <=> $b; }
    public function sortIt(array $a): array { usort($a, [$this, 'cmp']); return $a; }
}
function makesClosure(): callable { return function () { return 'reached'; }; }
function neverCalled(): callable { return function () { return 'dead'; }; }
echo implode(',', array_map('f', [1, 2])), "\n";
$h = g(...);
echo $h(), "\n";
echo implode(',', (new C())->sortIt([3, 1, 2])), "\n";
echo call_user_func('C::s'), "\n";
echo makesClosure()(), "\n";
