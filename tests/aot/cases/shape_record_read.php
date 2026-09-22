<?php
/** @param array{name: string, tags: string[], hits: int, ratio: float} $rec */
function describe(array $rec): string
{
    $hits = $rec['hits'] + 1;
    $r = $rec['ratio'] * 2;
    return $rec['name'] . ':' . implode('|', $rec['tags']) . ':' . $hits . ':' . $r;
}
echo describe(['name' => 'x', 'tags' => ['a', 'b'], 'hits' => 3, 'ratio' => 0.25]), "\n";
$rec = ['name' => 'y', 'tags' => [], 'hits' => 0, 'ratio' => 1.5];
echo describe($rec), "\n";
