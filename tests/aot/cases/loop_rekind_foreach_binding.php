<?php
// A name a loop re-kinds is a cell for the whole function, and a foreach
// binding is a store to it: the element must be boxed, or a read past an
// empty loop takes the previous value's raw word by the wrong tag.
final class O { public function __construct(public int $i) {} }
/** @param O[] $objs */
function md(array $objs): void { foreach (['x'] as $v) {} foreach ($objs as $v) {} var_dump($v); }
md([]);
md([new O(7)]);
/** @param O[] $objs */
function md3(array $objs): void { $v = str_repeat('x', 1); foreach ($objs as $v) { } var_dump($v); }
md3([]);
md3([new O(2)]);
/** @param O[] $objs */
function mdb(array $objs): void { foreach (['x'] as $v) {} foreach ($objs as $v) { if ($v->i > 5) { echo "big\n"; break; } } var_dump($v); }
mdb([]);
mdb([new O(7), new O(1)]);
mdb([new O(1)]);
