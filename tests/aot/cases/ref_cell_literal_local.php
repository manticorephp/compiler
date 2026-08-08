<?php
// @epic: ref-cells
//
// An array literal element that IS a reference (docs/design/reference-cells.md).
// The element holds a cell tagged REF whose payload is the reference's box, so
// the binding survives being STORED — which is what an address-based alias
// could never do.
//
// Three seams, and the case fails without each one:
//   create  — MANTICORE_REF_CELLS=0 restores the refusal in lowerArrayLit
//   read    — without the deref in emitArrayAccessUnified / __manticore_tag,
//             `$refs[0]` prints the box ADDRESS
//   write   — without emitElemWriteThrough, `$refs[0] = 10` overwrites the
//             binding and `$a` keeps its old value
//
// The slot's REPRESENTATION is the fourth: with `$a` left `int`, the box holds
// a raw word while the element decodes by tag, and `$refs[0]` printed
// 4.94E-324 — the bit pattern of 1 read as a double.

$a = 1;
$b = 2;
$refs = [&$a, &$b];

echo "read: ", $refs[0], " ", $refs[1], "\n";

// source -> element
$a = 7;
echo "a->elem: ", $refs[0], " ", $refs[1], "\n";

// element -> source, through the box
$refs[0] = 10;
echo "elem->a: ", $a, "\n";

// the binding SURVIVES the store: another write through the source is still
// visible, which a plain overwrite of the element would have broken.
$a = 11;
echo "still bound: ", $refs[0], "\n";

// the reference is a value, so the container is an ordinary array
echo "count: ", count($refs), "\n";
echo "is_int: ", (is_int($refs[0]) ? "yes" : "no"), "\n";

// a second reference to the same local aliases the same box
$again = [&$a];
$again[0] = 42;
echo "aliased: ", $a, " ", $refs[0], "\n";

// a name that exists only because a reference was taken to it is null, not
// undefined — php vivifies it.
$fresh = [&$never];
echo "vivified: ", var_export($never, true), "\n";
$fresh[0] = 'set';
echo "through fresh: ", $never, "\n";
