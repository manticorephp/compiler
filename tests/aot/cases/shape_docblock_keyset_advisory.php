<?php
/** @return array{0:string,1:string} */
function pair(bool $b): array { return $b ? ['a', 'b'] : []; }
/** @param array{name:string,id:int} $row */
function show(array $row): string { return implode(',', array_keys($row)); }
/** @return array{k:int} */
function wide(): array { return ['k' => 1, 'extra' => 2]; }
echo count(pair(false)), count(pair(true)), "\n";
echo show(['name' => 'x']), "\n";
echo implode(',', array_keys(wide())), "\n";
/** @param array{base?:int,auth?:array{0:string}} $o */
function opts(array $o): int { return count($o); }
echo opts(['http' => false, 'x' => true]), "\n";
