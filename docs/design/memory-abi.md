# Memory ABI contract — the stone tablet

The single source of truth for string / object / array layout, refcount encoding,
ownership transitions, destructor order and the child-iteration ABI. Anything that breaks
this contract breaks the compiler; anything that *extends* it updates this doc in the same
patch.

**Every number here is mirrored by a constant in `src/Compile/MemoryAbi.php`** — that file
is the machine-readable version and wins any disagreement. Cite it, do not re-derive it.
Current `MemoryAbi::VERSION` is **7**.

> Supersedes the former `docs/bootstrap/12-memory-abi-contract.md` and the unified-array
> design note `docs/bootstrap/16`, which source files used to cite. Sections on the deleted
> AST backend (`exprProducesAssoc`, `assocOwnedLocals`, `emitObjReleaseHelper`,
> `forEachRefcountedChild`) are gone — none of those symbols exist; ownership is decided by
> MIR passes, described in §5.

---

## 1. The rc self-routing tag

Every heap value that participates in refcounting is reachable through a data pointer, and
`ptr-8` (`RC_TAG_OFFSET`) always holds something the rc helpers can dispatch on:

| Word at `ptr-8` | Kind | rc lives at |
|---|---|---|
| `0x7E66000000000000` (`RC_TAG_MAGIC`) | object / vec | `ptr+8` |
| `0x7E66000000000001` (`ASSOC_TAG_MAGIC`) | assoc | `ptr+24` |
| `0x7E66000000000002` (`ARRAY_TAG_MAGIC`) | unified array, heap | `ptr+24` |
| `0x7E66000000000003` (`ARRAY_TAG_ARENA`) | unified array, arena | never — the arena reclaims it |
| anything else | string | `ptr-8` *is* the rc |

This is why `__mir_rc_retain` / `__mir_rc_release` **self-route** regardless of the static
type the call site guessed. The magic is chosen far above any real refcount (`< 2^56`, see
`RC_MASK`) and distinct from the immortal `-1`, so a string's rc word can never collide
with it. Tagged allocations hand out `malloc_base + 8` as the data pointer.

An arena array is never rc-bumped and never `free()`d — the retain/release helpers bail on
`ARRAY_TAG_ARENA` immediately; grow / promote / index paths route to the arena allocator.

## 2. String

`STRING_HEADER_SIZE = 32`, all offsets relative to the **data** pointer:

```
data - 32 : i64  hash    -- cached FNV-1a; 0 = not computed. Literals bake it in.
data - 24 : i64  cap     -- byte capacity of the data region (content + NUL)
data - 16 : i64  len     -- content length, binary-safe
data -  8 : i64  rc      -- small count, or -1 = immortal
data +  0 : bytes...
```

`cap` is what makes amortized `.=` possible: the append happens in place when `rc == 1` and
`len + addlen < cap`. Immortal strings (literals, arena) carry `rc = -1` and still carry a
full header, so `len` reads stay valid.

Two small-string free-list size classes recycle freed buffers (`STRING_POOL0_ALLOC = 64`,
`STRING_POOL1_ALLOC = 128`); a class's data capacity is `alloc - 32`, and a freed buffer is
recognised by that cap.

## 3. Object

`OBJECT_HEADER_SIZE = 16`. Non-`#[Struct]` instances reserve it before the first property:

```
offset 0  : ptr  class descriptor      -- NOT the raw class id
offset 8  : i64  rc_word               -- packed rc | color | buffered
offset 16 : ...  properties
```

The descriptor (`@__mir_cd_<id>`, `DESCRIPTOR_SIZE = 24`) is a static global, `linkonce_odr`
so each class has exactly one across every separately-linked object:

```
descriptor + 0  : i64  class_id     -- never 0
descriptor + 8  : ptr  drop_fn      -- or null
descriptor + 16 : ptr  rmeta        -- reflection metadata, or null
```

`instanceof`, method dispatch and exception catch read `class_id` at descriptor offset 0;
object release calls `drop_fn` **indirectly**. Offsets 0 and 8 are ABI — new fields append.
The struct is spelled in exactly one place: `Compile\Mir\RuntimeLibrary::descriptorType`.

`rmeta` stays null unless reflection actually reaches the class, so a binary that never
reflects pays 8 rodata bytes per class and nothing else. Its layout (`RMETA_*`,
`RMETA_SIZE = 104`, plus method / param / attribute row shapes) lives in `MemoryAbi.php`;
it is metadata, not a memory-management contract, and is not duplicated here.

### 3.1 `rc_word` encoding

One i64 at offset 8:

| Bits | Width | Purpose |
|---|---|---|
| 63 | 1 | `buffered` — member of the cc candidate list (`BUFFERED_MASK`) |
| 62..56 | 7 | `color` — 0 BLACK, 1 PURPLE, 2 GRAY, 3 WHITE (`COLOR_MASK`, `COLOR_SHIFT = 56`) |
| 55..0 | 56 | `rc` — signed; trial deletion drives it negative (`RC_MASK`) |

Read by masking and sign-extending from bit 55; compare with **signed** predicates:

```llvm
%rc_only = and i64 %word, 0x00FFFFFFFFFFFFFF
%shl8    = shl i64 %rc_only, 8
%rc_s    = ashr i64 %shl8, 8
```

Write only the rc field — colour and buffered are mutated by their own helpers:

```
new_word = (word & ~RC_MASK) | ((rc + delta) & RC_MASK)
```

A plain `add %word, 1` is **forbidden**: the carry overflows into the colour field. It used
to look like it worked because the corruption landed in bytes nobody read.

### 3.2 `#[Struct]` opt-out

Classes tagged `#[Struct]` skip the header entirely — fields start at offset 0, dispatch is
fully static, and no retain / release / cycle-collector hook touches them
(`ClassDef::headerSize()` returns 0). A 3-field struct is 24 bytes against 40 for the
refcounted form.

There is **no** `#[NoRefcount]` attribute. `AllocationKind::NoRefcount` exists but is an
escape-analysis verdict produced by `InferAllocKind`, not a user-facing marker — do not
confuse the two.

## 4. Arrays

### 4.1 Unified array (the live representation)

One header for every PHP array, packed or hashed — `ARRAY_HEADER_SIZE = 56`:

```
offset 0  : i64  length          -- live element count
offset 8  : i64  capacity        -- slots (i64 if packed, entry slots if hashed)
offset 16 : i64  next_int_key
offset 24 : i64  rc              -- SINGLE FIXED OFFSET for every array
offset 32 : i64  flags           -- mode + element repr + tombstones + internal pointer
offset 40 : i64  n_buckets       -- 0 = index not built
offset 48 : ptr  buckets         -- side allocation; null until built
offset 56 : ...  data
```

The fixed rc offset is the structural fix: a value can never have its rc read at the wrong
offset regardless of mode. Mode lives in the flags word instead.

**PACKED** (`flags & ARRAY_FLAG_HASHED == 0`): contiguous i64 values at `data + i*8`,
implicit int keys. **HASHED**: 24-byte entries.

```
entry + 0  : i64  kind    -- 0 STRING, 1 INT, -1 DELETED
entry + 8  : ptr  key
entry + 16 : i64  value
```

Flags-word bitfields:

| Bits | Field | Notes |
|---|---|---|
| 0 | `ARRAY_FLAG_HASHED` | cleared ⇒ PACKED |
| 1..3 | element repr (`ARRAY_REPR_*`, shift 1) | 0 RAW, 2 STR, 4 OBJ, 6 ARR, 8 CELL |
| 8..35 | tombstone counter (`ARRAY_TOMB_SHIFT`, 28 bits) | **every read must mask** |
| 36..63 | internal pointer (`ARRAY_PTR_SHIFT`, 28 bits) | `current()`/`next()` cursor |

The element-repr nibble is the runtime-truthful record of what retain / release / COW must
do to each element. It is stamped **as elements are stored**, so it travels with the array
through erased aliases — unlike the compile-time flavour guess.

⚠ The tombstone counter used to run to bit 63. It is now bounded so the internal pointer can
live above it: an unmasked `flags >> 8` reads the pointer as tombstones, and
`__mir_array_live_len` compacts whenever that is non-zero — so a moved cursor would compact
the array on every `foreach` and every `count`. Compaction resets both fields, which is why
`and flags, 255` (`ARRAY_FLAGS_LOW_MASK`) is still the right reset.

`IMMORTAL_ARRAY_RC = 1 << 62` is baked into the empty-array singleton. It is deliberately
**not** `-1`: COW's `sle rc, 1` and release's `sle rc, 0` must both stay false, and the
string immortal encoding would make COW mutate the shared singleton and release free it
(`__mir_array_cow` never reads the tag).

### 4.2 Arena arrays

Non-escaping arrays can be bump-allocated and bulk-freed at scope exit
(`__mir_array_alloc_arena`, `__mir_arena_realloc`), tagged `ARRAY_TAG_ARENA`. Eligibility is
decided by `InferAllocKind::isArenaEligibleType()` and is deliberately narrow today: flat
int / float / bool arrays with int keys. Gated by `Debug::$arenaArrays`
(`MANTICORE_ARENA_ARRAYS`, on by default).

### 4.3 Legacy vec / assoc headers

`VEC_*` and `ASSOC_*` constants still exist and are still referenced by paths that predate
the unified array. `VEC_HEADER_SIZE = 16` (length@0, rc@8, 8-byte elements).
`ASSOC_HEADER_SIZE = 48` (length@0, capacity@8, next_int@16, rc@24, n_buckets@32,
buckets@40, 24-byte entries at 48); an empty `[]` stub is `ASSOC_STUB_SIZE = 16` with
`capacity == 0` and **no rc slot**, so every assoc helper must guard `cap == 0` before
touching the rc word. The hash index is built at `ASSOC_INDEX_THRESHOLD = 8` live entries —
below that a linear scan beats hashing plus the bucket allocation.

Assoc rc is a plain count, not packed with colour/buffered, so cycles routed purely through
assoc values are not collected.

## 5. Ownership transitions

Ownership is **not** decided by an expression predicate any more. It is a MIR pass chain,
run in this order (`src/Manticore/Main.php`):

```
InferEffects → InferAllocKind → ApplyMemoryMode → InsertMemoryOps → Verify
```

- `InferEffects` — what each function does to its arguments and globals.
- `InferAllocKind` — per-allocation verdict: arena, heap-rc, or `NoRefcount`.
- `ApplyMemoryMode` — applies the `--memory` / `MANTICORE_MEMORY` strategy
  (`hybrid` default, `rc`, `arena`); resolved by `Compile\Mir\MemoryMode::resolve()`.
- `InsertMemoryOps` — plants the actual retain / release / COW calls.

Conditional expressions are a shared contract rather than per-consumer guesswork: `?:`,
`??`, ternary and `match` all route through `Compile\Mir\CondOwn` so the arms and their
consumer agree on who owns the result. An emitter-only fix leaks; a pass-only fix double-frees.

Returning a value transfers ownership to the caller — the returned local is dropped from the
owning set before the expression is emitted, so scope-exit release skips it. By-reference
binding forwards the slot to a shared cell and bypasses rc ops at the binding site; the
underlying buffer stays owned by whichever local holds it.

## 6. Destructor order

`__mir_rc_release` brings the rc to 0 (signed comparison) and calls the descriptor's
`drop_fn` indirectly. The drop body for class `C`:

1. Run the user `__destruct()` if declared, resolved to the **most-derived** one
   (`EmitLlvmRuntime.php`). PHP calls it before properties are released, and so do we.
2. Release each refcounted property in **declaration order** — string, object, array, or
   cell by its element repr. Structs and raw FFI pointers are skipped.
3. `free(self)`.

Children are released after the user destructor so destructor code still sees its state, and
before `free` so their own destructors see a valid parent pointer — there are no weak refs.

Iteration order is declaration order and that stability is load-bearing: the cycle
collector's walkers must visit the same set in the same order, or trial deletion and
restoration get out of sync.

Release recurses directly, so a long ownership chain (a linked list) can still exhaust the
stack. Iterative release via a worklist is open work.

## 7. Cycle collector

Synchronous Bacon–Rajan, **in tree**, emitted as LLVM IR by
`src/Compile/Mir/Passes/EmitLlvmRuntime.php` and reached through the `gc_collect_cycles()`
builtin (`EmitLlvmBuiltins.php`). Regression test: `tests/aot/cases/gc_cycles.php`.

Emitted symbols:

```
@__manticore_cc_add_root        @__manticore_cc_scan
@__manticore_cc_collect_cycles  @__manticore_cc_scan_black
@__manticore_cc_mark_gray       @__manticore_cc_collect_white
@__manticore_cc_child_apply     @__manticore_cc_drop_strings
```

Global state: `@__manticore_cc_roots`, `@__manticore_cc_count`, `@__manticore_cc_cap`,
`@__manticore_cc_children`, `@__manticore_cc_freed`.

**Triggering is manual only.** `gc_collect_cycles()` is the sole entry point; there is no
threshold heartbeat and no safe-point trigger, and the collector does not scan static or
global roots. Both are open work — see `docs/ROADMAP.md`.

## 8. Debug and verification

`src/Compile/Debug.php` reads six environment variables, once, at startup:

| Env var | Default | Effect |
|---|---|---|
| `MANTICORE_MEMORY` | `hybrid` | allocation strategy; also `--memory=<rc\|arena\|hybrid>` |
| `MANTICORE_ARENA_ARRAYS` | on | arena-allocate non-escaping eligible arrays |
| `MANTICORE_EMPTY_SINGLETON` | on | share one immortal empty-array buffer |
| `MANTICORE_DEBUG_VERIFY` | off | slow-path invariant checks at memory ops |
| `MANTICORE_PROFILE` | off | thread-local rc / alloc counters |
| `MANTICORE_REFLECT_REPORT` | off | report what reflection kept alive |

`MANTICORE_TYPECHECK=1` gates the `TypeCheck` pass and is read in the driver, not here.
There is no `MANTICORE_DEBUG_RC_TRACE`.

## 9. Versioning

Bump `MemoryAbi::VERSION` in the same patch as any layout or encoding change, and in that
patch also: rewrite the drop-body / walker emission against the new shape, update every
direct offset GEP, and bump the affected `*_HEADER_SIZE`.

The constant is **not** currently surfaced by any command — `manticore version` prints the
release version (`manticore 0.6.0`) and nothing else. Exposing the ABI version so vendored
`.o` artefacts can detect a mismatch is open work.
