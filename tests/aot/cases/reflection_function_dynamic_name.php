<?php
if (!function_exists('my_str_split')) {
    function my_str_split(string $s, int $n = 1): array { return str_split($s, $n); }
}
function plain_fn(int $a): int { return $a; }
/** @var array<string, array{alt: string}> */
$map = ['a' => ['alt' => 'my_str_split'], 'b' => ['alt' => 'plain_fn'], 'c' => ['alt' => 'strlen']];
$f = array_filter($map, static fn (array $m): bool => (new \ReflectionFunction($m['alt']))->isInternal());
var_dump(array_keys($f));
foreach ($map as $m) { $r = new \ReflectionFunction($m['alt']); echo $r->getName(), ' ', $r->isInternal() ? 'int' : 'user ' . $r->getNumberOfParameters(), "\n"; }
