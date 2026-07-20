<?php

// A `mixed`-declared return handing back an OBJECT read out of a bare-`array`
// param: the element must refine to obj<C> or the raw handle is boxed by
// guessing int and gettype() reports "integer".

final class Bag {
    public function __construct(public string $s) {}
}

final class Other {
    public function __construct(public int $n) {}
}

function lastOf(array $xs): mixed { $acc = null; foreach ($xs as $x) { $acc = $x; } return $acc; }
function lastOther(array $xs): mixed { $acc = null; foreach ($xs as $x) { $acc = $x; } return $acc; }
function lastInt(array $xs): mixed { $acc = null; foreach ($xs as $x) { $acc = $x; } return $acc; }
function lastStr(array $xs): mixed { $acc = null; foreach ($xs as $x) { $acc = $x; } return $acc; }

$a = new Bag("x");
$b = new Bag("y");

var_dump(gettype(lastOf([$a, $b])));
var_dump(gettype(lastOf([])));
var_dump(lastOf([$a, $b])->s);
var_dump(lastOf([$a])->s);

// A DIFFERENT class through the same shape must not merge into the first.
var_dump(gettype(lastOther([new Other(7)])));
var_dump(lastOther([new Other(7)])->n);

// The scalar paths that already worked must stay put.
var_dump(gettype(lastInt([1, 2])));
var_dump(gettype(lastStr(["a", "b"])));
