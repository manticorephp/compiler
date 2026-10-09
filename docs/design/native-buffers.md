# Native buffers — design

Why `SplFixedArray` and `Manticore\Ds\*` sit on a malloc'd fixed-width block instead of a PHP
array, and how the compiler inlines access to it. **Layout** lives in
[`memory-abi.md`](memory-abi.md) §7c; user guide in [`../ds.md`](../ds.md); this doc is the
reasoning and the wiring.

## Status

Shipped in PR #108 (`54365770`), ABI v18 (`MemoryAbi::VERSION`, `BUF_*` block, descriptor
`json_fn@56`, kind 12 `U64`). In the v0.13.0 release. Tracking: #92 (epic), #101 (follow-ups).
Open items are at the end.

## Why

A PHP array is a hash/vector of tagged cells: 8+ bytes per element plus bucket and tag traffic,
for data that is homogeneous and numeric (offsets, token kinds, pixels, bit sets). Two costs:
memory (an `int32` costs a cell) and speed (every read decodes a tag; every store can COW or
grow). The old `SplFixedArray` prelude class kept its elements in a PHP array property, so it
paid all of that AND inherited every array ownership rule (offsetGet +1, element-borrowed
property overwrite, `array_slice` in `setSize` — each leaked at some point; php-cs-fixer
`Tokens` was the driver).

A native buffer stores elements at their declared width in one contiguous block, reached through
an int. Superset feature: Zend has no such classes (`docs/ds.md` polyfill section).

## Handle model

- A **handle is an int: the block address** (`__mcbuf`, a plain `int` property). `0` = no block
  (a subclass constructor that skipped the parent's); every runtime op treats it as length 0 /
  free no-op (`RuntimeLibrary.php::nbuf`, `__mir_nbuf_free`).
- Block: `[len i64 | cap i64 | kind i32 | flags i32 | data…]` (`MemoryAbi::BUF_*`). One owner,
  one `malloc`, one `free`; no refcount of its own. The OBJECT that holds the handle owns it.
- An op that may reallocate (`grow`, `resize`, `insert`) returns the new handle; the caller
  stores it back to the slot.
- Slots in `[len, cap)` are zero (null cell for CELL). Growth inside `cap` is a length store;
  shrink clears the vacated slots (`__mir_nbuf_clear`). Capacity doubles (`__mir_nbuf_grow`).
- **Who frees it.**
  - `Manticore\Ds\TypedArray` (`prelude/ds.php`): explicit `__destruct` -> `__mc_nbuf_free`,
    `__clone` -> `__mc_nbuf_clone`.
  - `SplFixedArray` (`prelude/spl_iterators.php`): the slot is **compiler-owned** —
    `EmitLlvmArrays::isBufSlot` (`$pn === '__mcbuf'` on a class that `classIsA SplFixedArray`)
    makes the class drop body free it (`EmitLlvmRuntime.php`), `clone` deep-copy it, and every
    property view (var_dump, `(array)`, serialize walkers; `EmitLlvmObjects.php`,
    `EmitLlvmBuiltins.php`, `EmitLlvm.php`) skip it. A subclass's own `__destruct` / `__clone` /
    `__construct` therefore need not know. The decision is by class alone because drop bodies
    coalesce by name across modules (AGENTS.md invariant).
- **CELL kind owns its slots**: a slot holds one 8-byte cell word it OWNS. `set_c` retains the
  stored word and drops the old; removals and `free` drop; `get_c` hands out a +1. This is what
  lets `SplFixedArray` hold arbitrary `mixed` without the array ownership rules.

## Kinds

`BUF_KIND_*`: I8 1, I16 2, I32 3, I64 4, U8 5, U16 6, U32 7, F32 8, F64 9, BIT 10, CELL 11,
U64 12. Width per kind: `__mir_nbuf_w` / `__mir_nbuf_bytes`.

- Integer kinds are stored truncated to width; read through `get_i` with sign- or zero-extension
  by kind. **Truncation never happens on a user path**: the PHP layer range-checks first (below).
- F32/F64: `get_f` / `set_f`; F32 stores a `float` and widens on read.
- BIT: packed 64 per i64 word; reads as `0/1`, `BitArray` exposes `bool`.
- U64: stored exactly like I64; the *reading* is unsigned (`UInt64Array`: int word with the same
  bits; a numeric string / float is converted as an unsigned value; min/max/compare use unsigned
  ordering — `nbufReduce`'s unsigned tuple field).
- CELL: see above; only `SplFixedArray` uses it.

## Three layers (and the bootstrap rule)

Each op exists three times; the pairing is the AGENTS.md BOOTSTRAP RULE applied to a builtin
family.

1. **PHP twins** — `src/Runtime/Stdlib/Buf.php`: `__mc_nbuf_*` as plain PHP over one registry of
   PHP lists (`__mc_nbuf_op($op,$h,$a,$b,$c,$v)`). The compiler one generation behind has never
   heard of the builtins and links these; under Zend they ARE the storage of the
   `manticorephp/ds` polyfill (`tools/ds_polyfill.php`), so the polyfill is the oracle of the
   native classes by construction. Rules: pure PHP, no `pack`/`unpack` (a stdlib function must
   not call a prelude one) — the ByteBuffer float codecs are hand-written IEEE arithmetic.
2. **Codegen builtins** — `EmitLlvmBuiltins::biNbuf` dispatches any `__mc_nbuf_<op>` whose
   arity matches `RuntimeLibrary::nbufSig($op)`; the bodies are IR in `RuntimeLibrary::nbuf()` /
   `nbufReduce()` (per-kind generated sum/min/max/equals) and shadow the twins (`emitCall` asks
   `emitBuiltin` before `definedFns`). Adding an op = IR + `nbufSig` entry + twin in `Buf.php`;
   the wiring is generic by name.
3. **Inline access** — `NbufInline` + `EmitLlvmArrays::emitNbufGet / emitNbufSet /
   emitNbufAppend`: `$a[$i]`, `$a[$i] = $v`, `$a[] = $v` on a `Manticore\Ds` typed array are
   emitted in place (below). Everything else is a call into layer 2 from the PHP class body.

The name prefix is `__mc_nbuf_*`; `__mc_buf_*` collided with `Io.php`.

## Inline access

`NbufInline` is the single owner of the predicate; two passes must agree on it.

- `kindOf(class)`: the buffer kind of a `Manticore\Ds\*` class (0 = not one). The classes are
  `final`, so the static class IS the runtime class. `UInt16Array__of__…` reified specs strip to
  the base name (`LowerReify::reifySpecName`).
- `reads(array, index)`: receiver of kind != 0, int-typed index, `pureReceiver` (local, static
  property, or property of a local — the slow arm may evaluate it twice) and `pureInt` index
  (local / const / `+` `-` over them).
- Consumers: `EmitLlvmArrays::emitNbufGet` takes exactly these reads; `SpillFreshBases` lets such
  a base stay a *borrow* (no `offsetGet` runs, so nothing can overwrite the slot) instead of
  co-own-spilling it into `__fb_N` + a call.

Shape (`nbufProbe`): load handle from the object slot, load `len`, `idx <u len`; fast arm loads
at the kind's LLVM type from `handle + BUF_DATA_OFFSET`; slow arm calls the real `offsetGet` /
`offsetSet` (which throws OutOfBoundsException or ValueError) and ends in `llvm.trap;
unreachable` for raw kinds — the slow arm never returns normally, which lets LLVM LICM hoist
handle and len out of a loop. Loads and stores carry `!tbaa` (`!3` header = handle slot + len,
`!4` element; `rt->needsNbufTbaa` makes `emitPreamble` emit nodes `!0..!4`), so element stores do
not force a handle/len reload. A null handle reads length 0.

Stores additionally require `nbufValueFits` (static) and `nbufRangeTest` (dynamic): a value
outside the element range falls to the slow arm. `emitNbufAppend` inlines `[]=` while
`len < cap` (slack is zero, so it is a store + length bump) and otherwise calls `push()`.

`SplFixedArray` is not covered by `NbufInline`: its reads/stores go through the PHP class
(`offsetGet` / `offsetSet`) and the earlier in-place fast paths for `__data`/`__mcbuf`.

## Narrowing = ValueError, never silent

User rule (2026-10-07): a value that does not fit is an error. Enforced in the PHP layer
(`prelude/ds.php`): `IntTypedArray::coerce` / `inRange` throw `ValueError('Value N is out of
range for X (min..max)')`; a wrong type is `TypeError`; floats must be finite and integral;
numeric strings are accepted when exact; out-of-i64 floats are a ValueError. The inline path
range-tests and defers to the same PHP method on failure, so inline and slow arms agree.
`UInt64Array` accepts 0..2^64-1. Index errors are `OutOfBoundsException` (SplFixedArray-faithful
idx rules in `idx()`). The runtime ops themselves (`set_i`) truncate; they are not a user
surface.

## Classes on top

- `Manticore\Ds\TypedArray` (abstract, `@template T = int|float`) -> `IntTypedArray`,
  `FloatTypedArray` -> `Int8/16/32/64`, `UInt8/16/32/64`, `Float32/64`, `BitArray`,
  `ByteBuffer` (U8; get/set Int8..Float64 at a byte offset with a `bool $bigEndian` through
  `__mc_nbuf_peek/poke`). API: count, getSize/setSize, push/pop, insert/remove, fill, copyFrom,
  indexOf, sum/min/max/equals (runtime reductions: Int32 sum of 10M in ~0.7 ms).
- `SplFixedArray`: CELL kind, compiler-owned slot (above).
- `SplHeap` / `SplMinHeap` / `SplMaxHeap` / `SplPriorityQueue` and `SplDoublyLinkedList` /
  `SplQueue` / `SplStack` (`prelude/spl_iterators.php`) do **not** use the buffer: plain PHP
  list storage, because a CELL buffer buys nothing over `list<mixed>` there. Queues get O(1)
  shift through a dead-prefix offset with compaction. They share this doc only because they
  shipped in the same PR.

## `#[TypeDef]` element types

`UInt16Array<Kind>`: elements are *read as* a `#[TypeDef]` class erased to its scalar (no
allocation). Mechanics: typed arrays are generic (`@template T`, `@extends`, `@return T`);
`LowerTypes::resolveClassHint` / `bindsSameClass`, `LowerReify` (keys a TypeDef by class, not
carrier) and `InferNodes` (`$o[$k]` via `genericReturnType`) do the typing. A `@var X<T>` +
`new X` pair reifies a class copy (`X__of__t_Kind`); hint/property/param bindings stay erased
plus type args. **Width check**: `LowerTypeDefs::checkNbufBinding` (called from the generic
branch of `LowerTypes`) refuses a binding unless every value of the TypeDef's `repr` fits the
buffer kind — `NbufInline::holds` (signed never fits unsigned; unsigned fits a strictly wider
signed; float only same-or-wider float). Analyzer case: `ds_element_width`.

## JSON / serialize / dump

Typed arrays are `JsonSerializable`, `__serialize`, `__debugInfo` — all via `toArray()`.
`json_encode` of any class with `jsonSerialize()` (these included) calls through the class
descriptor's `json_fn@56` slot, which points at a synthesized `__mc_jsonser_<id>` helper
(`LowerPrelude::jsonSerSrc`, `RuntimeLibrary::jsonSerFn`). The compiler-owned slot is hidden from every
property view. `UInt64Array` shows raw bits in `toArray` (see open items).

## Contributor invariants

- Layout offsets come from `MemoryAbi::BUF_*`, never literals in an emitter.
- Every new `__mc_nbuf_*` op ships a `Buf.php` twin (bootstrap + polyfill) and an `nbufSig`
  entry; the twin and the IR must agree, difftest-style (the polyfill is the oracle).
- Never widen `NbufInline::reads` in the emitter alone — `SpillFreshBases` must follow, or a
  borrowed base is read after an overwrite.
- A subclass-visible buffer slot is class-decided (`isBufSlot`), not module-decided.
- A new inline arm must end the slow path in `nbufNoReturn` only where the PHP method
  *always* throws on that arm (raw kinds); CELL-like kinds return.
- Do not promote an `xfail` repro from macOS evidence alone; musl/Linux decide (#67).
- Zend twins must be pure arithmetic/loops; no prelude callee from stdlib.

## Open items

- #101 leftovers: per-iteration bounds compare (needs loop versioning), TBAA for
  `SplFixedArray`, views over a `ByteBuffer`, string-array container.
- #107: `foreach` over a typed array bound to a `#[TypeDef]` yields the plain scalar.
- #106: a class with a `#[TypeDef]` property makes any `var_dump` a compile error.
- #110 (PR #111): `#[TypeDef]` repr is not enforced on a narrow property slot.
- #109: `UInt64Array` JSON / dump encode unsigned values as signed bits.
- `src/` may now use `Manticore\Ds`: the pin (`BOOTSTRAP_VERSION` = 0.14.0) has it (#105, [stage0-bootstrap.md](stage0-bootstrap.md)).
