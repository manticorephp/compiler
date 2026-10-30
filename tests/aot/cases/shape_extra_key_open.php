<?php
/** @param list<array{name: string}> $items */
function names(array $items): string { return implode(',', array_column($items, 'name')); }
/** @param array{id: int} $r */
function ident(array $r): int { return $r['id']; }
$items = [['name' => 'a', 'extra' => 'x'], ['name' => 'b', 'extra' => 'y']];
echo names($items), "\n";
echo ident(['id' => 7, 'more' => 1]), "\n";
