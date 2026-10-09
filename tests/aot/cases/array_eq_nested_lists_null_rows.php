<?php
// == / === of nested lists, or of rows holding null, against an array_map copy is false (php true)
// item: 24
var_dump([[1, [2, 3]]] === array_map(fn($x) => $x, [[1, [2, 3]]]));
class T { public array $rows; function __construct(array $r) { $this->rows = $r; } }
$t = new T([['a' => null]]);
var_dump($t->rows == array_map(fn($x) => $x, $t->rows));
