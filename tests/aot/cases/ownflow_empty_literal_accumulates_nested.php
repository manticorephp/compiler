<?php
/** @return array<int, list<string|int>> */
function fixClass(int $i): array { return [$i => ['a', $i]]; }
/** @return list<array{type: string, n: int}> */
function elems(int $i): array { return [['type' => 'method', 'n' => $i]]; }
/** @param list<int> $xs */
function run(array $xs): void {
    $ins = [];
    foreach ($xs as $x) {
        if ($x % 2 === 0) {
            $ins += fixClass($x);
        }
    }
    var_dump($ins);
}
/** @param list<int> $xs */
function collect(array $xs): array {
    $elements = [];
    for ($i = 0, $c = \count($xs); $i < $c; ++$i) {
        if ($xs[$i] < 0) { continue; }
        if ($xs[$i] > 5) {
            $elements += elems($xs[$i]);
            break;
        }
        $elements += elems($xs[$i] + 10);
    }
    return $elements;
}
run([1, 2, 3, 4]);
var_dump(collect([-1, 2, 7, 9]));
