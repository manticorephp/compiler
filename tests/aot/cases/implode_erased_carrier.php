<?php
// implode() over an ERASED carrier — an array that arrives with no static type
// at all (a bare `mixed` param, an element of an untyped record). The old
// lowering boxed the argument with boxToCell, which for an unknown type falls
// through to __manticore_box_int; its 48-bit fit test FAILS on an already-
// tagged word, so it took the HEAP arm, mallocked 8 bytes and handed implode a
// fresh block to read as an array. Every join came back "" — symfony's Table
// rendered blank cells.
//
// The elements themselves stay raw; implode decodes each one by the array's
// element-kind hint (MemoryAbi::ARRAY_ELEM_HINT_*).

function joinAny($rows): string
{
    $out = '';
    foreach ($rows as $r) { $out = $out . implode(',', $r) . ';'; }
    return $out;
}

echo joinAny([['a', 'b'], ['c', 'd', 'e']]), "\n";

function joinOne($row): string { return implode('|', $row); }
echo joinOne(['x' => 'one', 'y' => 'two']), "\n";
echo joinOne([1, 2, 3]), "\n";
echo joinOne([1.5, 2.5]), "\n";
echo joinOne(['mixed', 7, 2.5, true]), "\n";

// A record read out of an erased outer array, then joined.
$data = ['first' => ['alpha', 'beta'], 'second' => ['gamma']];
foreach ($data as $k => $row) { echo $k, '=', joinOne($row), "\n"; }

// The concrete paths are untouched.
echo implode('-', ['keep', 'raw']), "\n";
echo implode('-', [10, 20]), "\n";
echo implode(['no', 'sep']), "\n";
