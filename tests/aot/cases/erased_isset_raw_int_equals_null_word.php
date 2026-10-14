<?php
// isset() over an erased (mixed) base reads a raw int element whose bits equal
// the boxed-NULL word as unset. php: true.
function probe(mixed $m): void {
    var_dump(isset($m[0]), isset($m[1]), $m[0] ?? 'miss');
}
probe([-3659174697238528, 5]);
