<?php
// A temporary passed as an argument is destroyed after the callee's locals, so destruct order and reused object ids differ from php.
final class O { public function __construct(public int $i) {} public function __destruct() { echo "d{$this->i}\n"; } }
/** @param O[] $objs */
function first(array $objs): void { $v = $objs[0]; echo spl_object_id($v), "\n"; }
first([new O(7), new O(1)]);
echo spl_object_id(new O(3)), "\n";
