<?php
// unpacked named args the callee cannot bind: both Errors, first offending key wins
class P { public function __construct(public int $a = 0, public int $b = 0) {} public function m(int $x = 0, int $y = 0): int { return $x * 10 + $y; } }
function f(int $x = 0, int $y = 0): int { return $x * 10 + $y; }
/** @param array<int|string,int> $args */
function run(array $args): void {
    $o = new P();
    $tries = [
        fn () => (new P(...$args))->a . ',' . (new P(...$args))->b,
        fn () => $o->m(...$args),
        fn () => f(...$args),
    ];
    foreach ($tries as $t) {
        try { echo 'ok ', $t(), "\n"; }
        catch (\Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
    }
}
run(['x' => 1, 'y' => 2]);
run(['a' => 1, 'b' => 2]);
run([3, 'y' => 4]);
run(['x' => 1, 'nope' => 2]);
run(['q' => 1, 'x' => 2, 'r' => 3]);
run(['y' => 1, 9]);

// file of the thrown Error must be the script (never a helper's placeholder)
final class Q { public function __construct(public int $a = 0, public int $b = 0) {} public function m(int $x = 0): int { return $x; } }
function g(int $p = 0): int { return $p; }
$cases = [
    fn() => new Q(...['a' => 1, 'zz' => 2]),
    fn() => (new Q())->m(...['x' => 1, 'q' => 2]),
    fn() => g(...['p' => 1, 0 => 2]),
];
foreach ($cases as $i => $c) {
    try { $c(); echo "no error\n"; }
    catch (\Error $e) { echo $i, ' ', get_class($e), ': ', $e->getMessage(), ' @', basename($e->getFile()), "\n"; }
}
