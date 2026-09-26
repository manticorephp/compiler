<?php
function mk(): \Closure {
    $name = 'x';
    return static function ($options, array $value) use ($name): array { return $value; };
}
function mk2(): \Closure {
    return static function ($options, array $value): array { return $value; };
}
$n = mk(); $m = mk2();
var_dump($n(null, ['A']), $m(null, ['B']));
/** @param array<string, mixed> $o */
function run(\Closure $n, array $o): void { var_dump($n(null, $o['order'])); }
run($m, ['order' => ['C']]);
