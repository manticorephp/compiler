<?php
/** @return array{0:Closure,1:int} */
function mk(int $n): array { return [fn(int $x): int => $x + $n, $n]; }
/** @param array{0:Closure,1:int} $p */
function run2(array $p): int { $f = $p[0]; return $f(1) + $p[1]; }
/** @return array{name: string, tags: string[], hits: int, ratio: float} */
function rec(): array { return ['name' => 'x', 'tags' => ['a', 'b'], 'hits' => 3, 'ratio' => 0.25]; }
/** @param array{name: string, tags: string[], hits: int, ratio: float} $r */
function describe(array $r): string { return $r['name'] . ':' . implode('|', $r['tags']) . ':' . ($r['hits'] + 1) . ':' . ($r['ratio'] * 2); }
echo run2(mk(41)), "\n";
echo describe(rec()), "\n";
