<?php

// A `T|false` local is a CELL, and a cell local was never released: the boxed
// object leaked and its __destruct never ran. InsertMemoryOps::isOwnedObj only
// admitted OBJ/ARRAY/STRING, so no release op was ever inserted; the emitters
// had no 'cell' flavor either, and the rc_release default would have picked
// 'obj' — which inttoptr's the NaN tag and faults.
//
// This is the shape every resource-returning function has (`fopen(): X|false`),
// so the leak is not exotic. Scalars in a cell must stay a no-op — __mir_cell_drop
// dispatches on the tag.

final class Handle
{
    public function __construct(public int $id) {}
    public function __destruct() { echo "close:", $this->id, "\n"; }
}

function openOk(int $id): Handle|false { return new Handle($id); }
function openFail(): Handle|false { return false; }

echo "-- union return, unset --\n";
$a = openOk(1);
var_dump($a === false);
unset($a);
echo "after unset\n";

echo "-- union return, false arm --\n";
$b = openFail();
var_dump($b === false);
var_dump(!$b);
unset($b);
echo "after unset false\n";

echo "-- reassignment drops the old one --\n";
$c = openOk(2);
$c = openOk(3);
echo "after reassign\n";
unset($c);

echo "-- scope exit --\n";
function useIt(): void { $h = openOk(4); echo "  using ", $h === false ? "?" : $h->id, "\n"; }
useIt();
echo "after scope\n";

echo "-- cell holding a scalar must not be dropped as a pointer --\n";
function num(bool $ok): int|false { return $ok ? 42 : false; }
$n = num(true);
var_dump($n);
unset($n);
$m = num(false);
var_dump($m);
echo "-- cell holding a string --\n";
function txt(bool $ok): string|false { return $ok ? "hello" : false; }
$s = txt(true);
echo $s, "\n";
unset($s);
echo "-- done --\n";
