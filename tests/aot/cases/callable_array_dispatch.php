<?php
class Sorter {
    private function byLen(string $a, string $b): int { return strlen($a) <=> strlen($b); }
    public function up(string $s): string { return strtoupper($s); }
    public function sort(array $xs): array { usort($xs, [$this, 'byLen']); return $xs; }
    public function mapUp(array $xs): array { return array_map([$this, 'up'], $xs); }
}
class Magic {
    public function __call(string $n, array $a): string { return "magic:$n(" . implode(',', $a) . ')'; }
}
function invoke(mixed $cb, mixed ...$args): mixed { return $cb(...$args); }
$s = new Sorter();
echo implode(' ', $s->sort(['ccc', 'a', 'bb'])), "\n";
echo implode(' ', $s->mapUp(['x', 'y'])), "\n";
echo implode(' ', array_map([$s, 'up'], ['p', 'q'])), "\n";
echo call_user_func([$s, 'up'], 'cuf'), "\n";
echo invoke([new Magic(), 'anything'], 1, 2), "\n";
try { invoke([$s, 'nope'], 1); } catch (\Error $e) { echo get_class($e), ': ', $e->getMessage(), "\n"; }
$m = 'up';
echo $s->$m('dyn'), "\n";
$ao = new ArrayObject([10, 20, 30]);
echo invoke([$ao, 'offsetGet'], 1), ' ', invoke([$ao, 'count']), "\n";
echo implode(',', array_map([$ao, 'offsetGet'], [2, 0])), "\n";
$n = 'offsetExists';
var_dump($ao->$n(5), $ao->$n(strlen('ab')));
