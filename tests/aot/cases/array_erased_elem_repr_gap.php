<?php

// ⚠ KNOWN GAP — this case has NO expected/ file on purpose, so the suite reports
// it as a SKIP rather than a green PASS over a wrong answer. It is checked in as
// the minimal reproduction; give it an expected/ file the day the erased element
// channel is retyped, and it becomes the regression test for free.
//
// THE DISAGREEMENT. A bare `array` gets element type `unknown` by design. The
// READ side decodes an `unknown` element as a NaN-TAGGED cell
// (EmitLlvmArrays::arrayBaseToPtr and ::storeElemDeCellifyType both pair
// KIND_UNKNOWN with KIND_CELL); the WRITE side, ::storeElemBoxesValue, boxes
// only for KIND_CELL and stores everything else RAW.
//
// Normally the two ends agree anyway, because InferTypes refines the container
// from its stores: `$a = []; $a[] = 'lit';` makes $a a vec[string] and both ends
// read raw. Move that one store into a CLOSURE and the outer local stays
// vec[unknown] while the closure body still sees a `string` — stored raw, read
// back tagged. `echo` then prints the ADDRESS and var_dump reports
// float(2.1E-314), with no diagnostic anywhere.
//
// WHY THE ONE-LINE FIX DOES NOT WORK. Making storeElemBoxesValue return true for
// KIND_UNKNOWN was tried: a container's repr nibble is fixed at ALLOCATION, so
// boxed values landing in a raw-repr vec make the release path free tagged
// words. The self-host gen-2 compiler segfaulted on its own smoke test. Closing
// this needs the erased element channel retyped to cell end to end — the parked
// element-repr epic — not a change to that predicate.
//
// THE REMEDY THAT WORKS TODAY is at the bottom: annotate the element type.

$a = [];
$f = function () use (&$a) { $a[] = 'lit'; };
$f();
echo "closure store, bare array:\n";
echo "  echo:      ", $a[0], "\n";
echo "  gettype:   ", \gettype($a[0]), "\n";
echo "  is_string: ", \is_string($a[0]) ? 'yes' : 'no', "\n";
// These two go through paths that coerce by other means and are already right,
// which is part of why the gap reads as "works" until something prints.
echo "  strlen:    ", \strlen($a[0]), "\n";
echo "  ===:       ", $a[0] === 'lit' ? 'yes' : 'no', "\n";

// A by-VALUE captured object with a bare `array` property is the same shape.
final class Bag { public array $rows = []; }
$bag = new Bag();
$g = function (string $s) use ($bag) { $bag->rows[] = $s; };
$g('prop');
echo "closure store, bare property:\n";
echo "  echo:      ", $bag->rows[0], "\n";

// ── The remedy ──────────────────────────────────────────────────────────────
// Annotating the element type keeps both ends on the same repr.
/** @var string[] $ok */
$ok = [];
$h = function () use (&$ok) { $ok[] = 'lit'; };
$h();
echo "annotated:\n";
echo "  echo:      ", $ok[0], "\n";
echo "  gettype:   ", \gettype($ok[0]), "\n";

final class TypedBag { /** @var string[] */ public array $rows = []; }
$tb = new TypedBag();
$i = function (string $s) use ($tb) { $tb->rows[] = $s; };
$i('prop');
echo "  property:  ", $tb->rows[0], "\n";

// ── The same disease without any array at all ───────────────────────────────
//
// A by-ref captured local is heap-BOXED: its slot holds a pointer to a one-word
// cell that two frames share. Nothing pins that word's repr, so InferTypes can
// (and does) decide `int` from the outer `$max = 0` while the closure stores a
// `cell` it received through a `mixed` parameter. The MIR says so outright —
// `load_local max : int` and `store_local max <- ... : cell` in ONE function:
//
//   fn __closure_0(unknown %max, cell %v) -> cell {
//     %1 = load_local max : int
//     %4 = store_local max <- %3 : cell
//
// A STATIC PROPERTY is aliased storage for the same reason and fails the same
// way. A plain local does not — nothing else writes it, so its one type holds.
//
// ⚠ ARITHMETIC AND COMPARISON ON THE CELL ARE FINE — `$sum + 1` and `$v > $max`
// both unbox on the way in. It is the bare ASSIGNMENT of a cell into an aliased
// slot that stores the tagged word raw. That is why this reads as "works" until
// something assigns.
function fwd2(mixed $cb, mixed $a): mixed { return $cb($a); }

$max = 0;
$sum = 0;
$byref = function ($v) use (&$max, &$sum) {
    $sum = $sum + 1;                    // concrete int stored — fine
    if ($v > $max) { $max = $v; }       // cell stored into an int slot — NOT
};
\fwd2($byref, 4096);
echo "aliased local:\n";
echo "  sum:       ", $sum, "\n";
echo "  max:       ", $max, "\n";

final class Slot { public static int $m = 0; }
$stat = function ($v) { Slot::$m = $v; };
\fwd2($stat, 4096);
echo "  static:    ", Slot::$m, "\n";

$plain = function ($v) { $loc = 0; $loc = $v; return $loc; };
echo "  plain loc: ", \fwd2($plain, 4096), "\n";
