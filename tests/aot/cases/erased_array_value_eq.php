<?php
function mk(): \Closure {
    $name = 'x';
    return static function ($options, array $value) use ($name): array {
        if ($value !== array_unique($value)) {
            throw new \InvalidArgumentException($name . ': not unique');
        }
        return $value;
    };
}
/** @param array<string, mixed> $o */
function run(\Closure $n, array $o): void {
    try { var_dump($n(null, $o['order'])); } catch (\InvalidArgumentException $e) { echo $e->getMessage(), "\n"; }
}
$n = mk();
run($n, ['order' => []]);
run($n, ['order' => ['A', 'B']]);
run($n, ['order' => ['A', 'A']]);
/** @param list<string> $a */
function same(array $a): bool { return $a === array_values(array_unique($a)); }
var_dump(same([]), same(['p', 'q']), same(['p', 'p']));
$x = ['k' => 1, 'j' => [1, 2]]; $y = ['k' => 1, 'j' => [1, 2]];
var_dump($x === $y, $x !== $y, [] === [], ['a'] == ['a']);
