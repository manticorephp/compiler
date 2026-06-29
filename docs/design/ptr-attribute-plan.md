# `#[Pointer]` parameter attribute — executable staged plan

Status: **plan, not built.** The design (rationale, dual Zend/native identity,
immutable-borrow contract) lives in `generators-and-pointers.md §2a`; this is
the concrete, gateable build order with code anchors.

## The core realization (why this is mostly subtractive)

In this ABI a string / array value **already is** its buffer pointer (an `i64`
carrying the data ptr). So passing `$buf` by value already forwards the
pointer. A normal value param differs from a `#[Pointer]` borrow only in what
the **callee** does:

| | normal value param | `#[Pointer]` immutable borrow |
|---|---|---|
| entry | `initRcObjSlots` retains (+1) | **no retain** |
| scope exit | release (−1) | **no release** |
| array write | copy-on-write | **forbidden** (borrow-checked) |
| caller | passes the value | **identical — passes the value** |

So the caller is **automatic with zero call-site change** (plain `f($buf)`),
and the codegen change is *removing* rc/CoW traffic for the borrowed param,
guarded by a compile-time borrow check that makes the elision sound.

Under Zend (cold bootstrap) `#[Pointer]` is an inert attribute — the callee
gets a by-value copy; immutability makes it observationally identical to the
native pointer. Bootstrap stays intact.

## Naming

`Ffi\Ptr` is already a real class (`src/Ffi/Ptr.php`) used as a *type*
(`Ptr $p` in `src/Runtime/Libc.php`). To avoid collision the attribute is
**`#[Pointer]`** (matched by name; a marker class `Ffi\Pointer` is optional —
Zend parses an unresolved attribute fine, the compiler matches the string like
`ffiSymbolOf` does).

## Anchors (already in place)

- **Parse — DONE.** `Parser\Ast\Param` already carries `$attributes`
  (`AttributeNode[]`), populated by `collectAttributes()` at the top of
  `Parser::parseParam()` (`src/Parser/Parser.php:748`). Nothing to add.
- **Attribute-read pattern.** `LowerFromAst::ffiSymbolOf()`
  (`src/Compile/Mir/Passes/LowerFromAst.php:975`) shows how to read an
  attribute by name off a decl. Mirror it for params.
- **MIR Param** (`src/Compile/Mir/Param.php`) — has `byRef/variadic/default`,
  no attribute flag yet.
- **By-ref reference points** (the contrast, NOT reused for the caller):
  function entry seeds `refLocals` for byRef params at
  `EmitLlvm.php:845`; the by-ref call site passes a slot address at
  `EmitLlvm.php:3815-3826`. `#[Pointer]` is NOT by-ref — it does none of this.
- **rc-on-param retain** to elide: `initRcObjSlots()` retains an obj/str/array
  param on entry at `EmitLlvm.php:1105-1109`; the matching scope-exit release
  comes from the `rc_release` MemoryOps collected into `currentRcObjLocals`
  (`collectRcObjLocals`, `EmitLlvm.php:1118`).

## Build order (gate each; revert on any non-determinism)

### Stage 1 — plumb the attribute (inert, behavior-neutral)
- `MIR Param`: add `public readonly bool $isPtr = false`.
- `LowerFromAst`: where params are lowered to MIR `Param`s, read `#[Pointer]`
  (and tolerate `#[Ptr]`) from `$astParam->attributes` via a new
  `paramIsPointer(array $attributes): bool` (mirror `ffiSymbolOf`); pass into
  the `Param` ctor.
- A `dump-sig` / `dump-mir` line showing the flag, plus one AOT test that a
  `#[Pointer]`-annotated function still compiles and runs unchanged.
- **Gate:** suite + difftest. Pure plumbing — must be byte-neutral for code
  that doesn't use the attribute.

### Stage 2 — borrow-check pass (compile-time safety, still no elision)
New MIR pass `CheckBorrows` (runs after lowering / InferTypes). For each
`#[Pointer]` param, FAIL the compile (clear message + span) if the param is:
1. reassigned (`$p = …`),
2. mutated (`$p[$i] = …`, `$p->x = …`, `$p++`, passed to `&`/another `#[Pointer]`… actually mutation only),
3. returned, thrown, stored to a property / element / static / closure capture
   (escape), or
4. passed as a non-borrow argument that could retain it.
Allowed: reads (`$p[$i]`, `strlen($p)`, `$p === …`, passing to another
`#[Pointer]` or read-only builtin). This is what makes Stage 3 sound.
- **Gate:** suite + difftest (no behavior change yet) + new negative tests
  (each violation rejected) + positive tests (read-only uses accepted).

### Stage 3 — rc / CoW elision (the behavior change; rc heisenbug zone)
- `initRcObjSlots` (`EmitLlvm.php:1105`): **skip the retain** for an `isPtr`
  param.
- Scope-exit release: **suppress** the `rc_release` for an `isPtr` param
  (gate in `collectRcObjLocals` / wherever param releases are emitted).
- Array CoW: an `isPtr` array param is never written (Stage 2 guarantees), so
  the CoW path is already dead — assert/skip it.
- **Gate HARD:** suite + difftest + fixpoint byte-identical + stability 5×2
  (rc-traffic change → the drop-ABI discipline applies). Measure: rc
  retain/release count on a hot borrowed param should drop.

### Stage 4 — (later, optional) raw byte-offset reads — the scan accelerator
For an `isPtr` **string** param, lower `\ord($buf[$i])` to a direct `i8` load
(no 1-char-string alloc) — the real speed lever for byte scanning (lexer,
strpos-style loops). Keep dual identity: only the `ord(...)`-consumed form is
specialized; a bare `$buf[$i]` still yields a 1-char string. Separate, bigger
change — do after Stages 1–3 are solid.

### Dogfood
Annotate hot read-only params in the compiler (`Lexer` source buffer, the
`strpos`/scan `$haystack`) with `#[Pointer]`; re-`sample` and confirm rc
traffic / time drop. This is also the dual-identity proof: the same source
must self-host (native pointer) and cold-bootstrap under Zend (by-value copy).

## Risk / discipline
Stage 3 touches rc retain/release — the documented heisenbug zone. Do stages
in order, gate each, and revert on any non-determinism (per the drop-ABI A'
discipline). Stages 1–2 are safe (plumbing + a compile-time check); the risk is
concentrated in Stage 3.
