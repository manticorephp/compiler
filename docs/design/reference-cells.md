# Reference cells — a `&` that is a value

Status: **implemented through main `f58e5d6` (2026-09-14).** `[&$a]`, `[&$this->p]`,
`[&$a[$k]]`, `[$v, &$v]`, `$a[$k] = &$v`, cell-alias ownership, `unset` breaking the
binding and a mutated `mixed` by-ref param all match php; the deepclone witness is
byte-identical. Still open: a `PropertyAccess` as the TARGET of `=&` (a copy), the
static-property source, `&...$vars`, `R:` in `serialize` and the var_dump `&` marker.
The box is counted and a property is promoted in place since br `refbox` (2026-09-23),
so `clone` now shares the BINDING as php does. The refusal this replaced
(`LowerFromAst::lowerArrayLit`, "an array literal cannot bind an element by
reference") is gone. The rest of this document is the design as it was decided.

## Why

PHP's `&` binds two slots to one storage. Manticore has three narrower
mechanisms and no general one:

- `RefAlias_` / `emitRefAlias` — aliases by **sharing the source's alloca**. Only
  works when the source HAS an alloca, i.e. a local.
- `RefAddr_` — binds a local to a slot **address**.
- by-ref parameters — the callee writes through a caller-supplied address.

None of them can express a reference whose source is a *property* or an *array
element*, and none can be **stored**. So every construct that needs a reference
as a first-class value is refused or silently wrong:

| construct | today | finding |
|---|---|---|
| `[&$a, &$b]` | refused, loudly | `parser-ref-in-array-literal` |
| `f(&...$vars)` | compiles, writes NOTHING back | `variadic-byref-no-writeback` (S0) |
| `fscanf($s, $f, $a, $b)` | refused | `fscanf-byref-form-absent` |
| `array_multisort($a, SORT_DESC, $b)` | desugared at the call site, by hand | — |
| `sscanf(...)` | desugared at the call site, by hand | — |
| `serialize()` emitting php's `R:` | cannot — "arrays carry no is_ref bit" | — |

The corpus witness is one file, `symfony/http-kernel/DataCollector/
DumpDataCollector.php:60`, and it is the shape that forces the general answer:

```php
$this->rootRefs = [&$this->data, &$this->dataCount, &$this->isCollected, &$this->clonesCount];
```

⚠ Those properties are **concretely typed** — `private int $dataCount`,
`private bool $isCollected`. So "the slots are already cells, just box them" does
not apply. Taking a reference has to be able to change a slot's representation,
and that is the whole of the work.

## The model

A **ref cell** is a NaN-boxed cell like any other, with its own tag nibble, whose
payload is a pointer to a one-word heap **box**. The box holds the value; every
holder of the reference holds a cell pointing at the same box.

```
$a = &$b;     both slots hold  cell(REF, ptr)  ->  box[ value ]
```

This is php's own model (a zval promoted to IS_REFERENCE), and it is what makes
the reference a VALUE: it can sit in an array element, cross a `.o.sig`, be
copied by `__clone`, and be stored — none of which an address-sharing alias can
do.

Encoding: a cell's tag is the nibble at bits 48-51 (`EmitLlvmExpr::cellTagIr`),
payload the low 48 bits. The container header magics in `MemoryAbi` run
`RC_TAG_MAGIC` 0 … `CLOSURE_TAG_MAGIC` 7, so **8 is free** for the box header.

## The box's lifetime (ABI v9)

The box is `[REF_TAG_MAGIC@-8, value@0, rc@+8]` (`MemoryAbi::REF_*`); every
reader addresses `data`, so the header moved nothing. Each holder owns one count:

| holder | takes it | gives it back |
|---|---|---|
| the frame that made it (`use (&$x)`, `[&$a]`) | `__mir_ref_new` in the prologue | every return and the fall-through, and `unset($a)` (which hands the name a fresh box) |
| a closure env's by-ref capture | `__mir_ref_retain` at the capture | the env's `__mc_drop` |
| a REF cell in an array / property | `__mir_cell_retain` (tag 9) at the store | `__mir_cell_drop` (tag 9) |
| an array element promoted by `[&$v[$k]]` | `__mir_array_ref_box` makes it rc 1 | the element's drop |
| a property slot promoted by `&$o->p` | `__mir_ref_promote_slot` makes it rc 1; `clone` retains | the class drop (`__mir_ref_slot_drop`) |
| a by-value parameter a `[&$x]` points at | boxed in the prologue, holding the argument | as the frame's own box |

The last holder drops the VALUE and frees the box. A holder that knows the
value's representation drops it by that flavor — a ref-cell target holds a
cell, a capture box holds the local's own type; a holder that does not (a REF
cell, a capture of a box some other frame made) either drops it as a cell or
frees the box shell alone, which leaks the value rather than misroute it.

`retain` / `unref` check the magic first. A REF cell or a by-ref capture can
still carry an address that is not a box — a static, a caller's by-ref slot —
and those have no count to touch.

A store through a box the frame owns releases the value it held, as a module
cell does, but only while the slot still points at that box: `$a = &$b`
rebinds the slot, and the storage it points at then has another owner.

## The three seams

1. **Create** — `boxToCell` (`EmitLlvmBuiltins:549`) gains a ref arm, and
   `&$x` PROMOTES `$x`'s slot: allocate a box holding the slot's current value,
   store `cell(REF, box)` back into the slot, and hand the same cell to the
   consumer.
2. **Read** — `unboxCellToType` (`EmitLlvmExpr:4580`) and the ~22 inline tag
   tests in the same file dereference a REF box before doing anything else. This
   is the same dispatch that already distinguishes int/float/string/array, so it
   is an arm, not a new machine.
3. **Write** — a store into a slot that MAY hold a ref must write **through** the
   box instead of overwriting the cell. This is the seam with no precedent and
   the one to design first.

## What decides the size: the promotion analysis

A slot that is ever referenced must carry a representation that can hold a ref
cell for its whole lifetime — the tree's existing rule that **one slot has one
representation**. So a pass has to mark every slot reachable by `&` (local,
property, static property, array element) as ref-capable, before repr is fixed.

Precedent to build on, not to duplicate: `VivifyRefArgs` already walks argument
positions to decide definitions, and `emitStoreLocal` already carries three
"un-cellify" plants for by-ref-pinned slots.

⛔ Do NOT start from the consumer side. Today's erasure family is exactly what
happens when a representation is decided per-consumer: `cell` is a static CLAIM
that three producers already violate. A ref cell that some readers deref and
others do not is that bug again, one layer deeper.

## Measured before starting stage 3: the element paths are not ready

The read/write seams are not one place each. In `EmitLlvmArrays`:

- the element STORE has **four near-identical arms** (~1094, 1112, 1143, 1165), each repeating
  the same `storeElemDeCellifyType` → `unboxCellToType` → `coerceToI64` → `rcRetainByType`
  sequence, under `emitStoreElement` (261) and `emitStoreElementUnified` (1040);
- the element READ decodes a cell in several more.

A REF tag has to be honoured in **every one of them**. Honoured in some and not others is
precisely the failure this document already warns about one section up — the erasure family is
what a representation decided per-consumer looks like after a year.

⇒ **Prerequisite, and it is a refactor with no behaviour change:** collapse those four store arms
to one before adding the tag. Then "a ref cell is dereferenced on read and written through on
store" is two edits instead of ten, and the suite gates the collapse on its own. Doing it the
other way round means landing the tag in a shape where a missed arm is a silent wrong answer.

## Staged plan

1. **Write-through first.** Pick the smallest end-to-end shape — `$a = &$b;` on
   two locals, both `int` — and make the promotion, the read and the write
   correct with an AOT case that fails without each of the three.
2. Property source (`&$this->x`), then static property, then array element.
3. Only then the array literal, which is the corpus witness and needs nothing new
   once an element can HOLD a ref cell.
4. Then retire the hand-written desugars: `array_multisort`, `sscanf`, and the
   `&...$vars` pack. Each has a finding and a probe already.

## Traps already paid for (2026-08-07/08)

- **A bare `array` return erases its element type across a delegation hop.** A
  method returning another `array`-returning method's result handed back cells.
  Cost: a seed build that died three steps away, blaming `array_merge`.
- **New `private bool` fields on a hot class miscompiled natively.** The Zend-run
  lexer was byte-identical on every source in the tree while the natively built
  one silently lost prelude demand. Prefer a parameter and a local.
- **A failed `bin/build` poisons `bin/manticore` + `lib/*.o`**, and the next
  suite run measures the poison, not the source. Recover with `bin/build --seed`
  before believing any number.
- **`bin/build` green says nothing about `bin/build --seed`, in BOTH directions.**
  Both were observed failing while the other passed, on one source tree.
- **Narrowing a node does NOT work inside a TRAIT.** The
  `private static function as…(Node $n): X { return $n; }` idiom resolves field
  offsets correctly in a CLASS (`Walk`, `NodeClone`, `DeadStore`) and not in a
  trait — and every `EmitLlvm*` / `Infer*` file is a trait on one host. Three
  symptoms, one cause: a SIGSEGV in `markVecElemBase`, a spurious "not
  addressable" refusal in `preallocateLocals`, and a SILENT miss in `InferNodes`
  that left a promoted local `int`. In a trait, take the child through
  `Walk::children`. ⚠ Zend resolves fields by NAME, so the whole class of bug is
  invisible when the compiler runs under php.

## The property box — the next instalment, and why it is not optional

S2 retypes a ref-taken PROPERTY to a cell and points the reference at the SLOT.
That is enough for reads and writes through the reference. It is NOT enough for
`clone`.

php shares the STORAGE. Measured on the witness shape:

```
$e = clone $d; $e->dump("three");
  php:       one,two,three|3|n  /  one,two,three|3|n     <- BOTH objects
  manticore: one,two|2|n        /  one,two,three|3|n
$d->reset();
  php:       |0|y  /  |0|y                               <- BOTH
```

A property holding a reference is shared by the clone, so the clone's writes are
the original's writes. Copying the CELL — which is what the clone path does now,
after the crash fix — copies the value, not the binding.

⇒ The property holds `cell(REF, box)` rather than merely being cell-typed — the
same box indirection the local promotion has. Shipped (br `refbox`, 2026-09-23)
as an IN-PLACE promotion, the way an array element is promoted, not as the
allocation-time box first planned here: the object may be allocated in another
module (a prelude or library class) that never saw the `&`, so only the `&`
itself can be relied on to make the box.

1. `EmitLlvm::$refCellPropNames` — the property NAMES a `[&$o->p]` in this
   module points at. By name, not class: the receiver may be typed as a parent
   or a child of the class the `&` named, and the slot's tag is the real answer.
2. `EmitLlvmLocals::byRefAddrOf` answers such a slot's BOX
   (`__mir_ref_promote_slot`: the slot's cell moves into a box, the slot keeps
   `cell(REF, box)` and the object's count on it). Every `&` to the property —
   storable reference, `$r = &$o->p`, by-ref argument — lands on the box.
3. `emitStoreProperty` writes THROUGH a promoted slot (`__mir_ref_slot_store`,
   dropping the value the box held).
4. `emitPropertyAccess` dereferences, as an element read does.
5. `clone` retains the slot's count (`__mir_ref_slot_retain`) — the clone
   SHARES the reference, php's semantics — and the class drop gives it back
   (`__mir_ref_slot_drop`, on every cell slot of every module: the drop body
   coalesces by name, so it cannot depend on which module saw the `&`).

`tests/aot/cases/refbox_sources.php` asserts the clone and the outliving read.
