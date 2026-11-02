<?php
// A fresh object a scalar consumer reads (`(bool)`, `!`, a compare, `instanceof`, a ternary condition) is freed as soon as it has answered, not at the end of the statement.
class RC { public function __construct(public int $i = 0) {} public function __destruct() { echo "d{$this->i} "; } }
function ids(mixed $v): mixed { return $v; }
function mk(int $i): ?RC { return new RC($i); }
var_dump((bool)ids(new RC(1)), spl_object_id(new RC(2)));
var_dump(!mk(3), spl_object_id(new RC(4)));
var_dump(mk(5) === null, spl_object_id(new RC(6)));
var_dump(mk(7) instanceof RC, spl_object_id(new RC(8)));
var_dump(ids(new RC(9)) ? 'y' : 'n', spl_object_id(new RC(10)));
echo "\n";
