# Reference cells — a `&` that is a value

Status: **designed, not implemented.** The refusal it replaces is
`LowerFromAst::lowerArrayLit` ("an array literal cannot bind an element by
reference"), which now names its site.

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
