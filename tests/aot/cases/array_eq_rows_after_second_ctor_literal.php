<?php
// == of a property array against its array_map copy is false once a second `new` passes a literal of another shape to the same constructor
class T { public array $rows; function __construct(array $r) { $this->rows = $r; } }
$t = new T([['a' => null]]);
var_dump($t->rows == array_map(fn($x) => $x, $t->rows));
$k = new T([[1]]);
