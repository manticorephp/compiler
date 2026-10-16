<?php
// `f(...$arr)` with fixed params ahead of f's variadic: the array feeds the fixed params, the pack gets the tail.
function fj(string $sep, string ...$parts): string { return $sep . count($parts) . implode($sep, $parts); }
function fd(string $a, string $b = 'B', string ...$rest): string { return $a . $b . '[' . implode(',', $rest) . ']'; }
class K
{
    public function __construct(public string $s = '', string ...$more) { $this->s .= count($more); }
    public static function join(string $sep, string ...$parts): string { return fj($sep, ...$parts); }
    public function m(int $n, int ...$xs): int { return $n * array_sum($xs); }
}
function ids(mixed $v): mixed { return $v; }

$x = ['-', 'a', 'b'];
echo fj(...$x), "\n";
echo K::join(...$x), "\n";
echo fj(...ids(['+'])), "\n";
echo fd(...['a']), ' ', fd(...['a', 'b']), ' ', fd(...['a', 'b', 'c', 'd']), "\n";
echo fd('z', ...['y', 'q']), "\n";
echo (new K(...['k', 'p', 'q']))->s, "\n";
echo (new K())->m(...[2, 3, 4]), "\n";
$calls = 0;
$gen = function () use (&$calls): array { $calls++; return ['/', 'x', 'y', 'z']; };
echo fj(...$gen()), ' calls=', $calls, "\n";
