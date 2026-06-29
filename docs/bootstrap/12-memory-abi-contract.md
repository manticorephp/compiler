# Memory ABI Contract — stone tablet

The single source of truth for object / assoc / string layout, refcount
encoding, ownership transitions, destructor order, and the child
iteration ABI. Anything that breaks this contract breaks the
compiler. Anything that *extends* it must update this doc in the
same patch.

Status: **WIP — written 2026-05-26 to cement what's already shipped
plus what the cycle collector + verification work will rely on.** The
self-host compiler currently violates the obj-release invariant
below; tracking under [[obj-alias-norefcount-field-bug]].

---

## 1. Object header

Every non-`#[Struct]` class instance starts with this 16-byte header:

```
offset 0  : i64  class_id      -- unique stable per ClassInfo
offset 8  : i64  rc_word       -- packed: rc | color | buffered
offset 16 : ...  properties
```

`class_id == 0` is RESERVED for the empty/null sentinel and must
never appear on a live object.

### 1.1 `rc_word` encoding

Single i64 at offset 8. Bit layout (MSB ↓ LSB):

| Bits   | Width | Purpose                                                  |
|--------|-------|----------------------------------------------------------|
| 63     | 1     | `buffered` — present in `__manticore_cc_candidates` list |
| 62..56 | 7     | `color` — 0=BLACK, 1=PURPLE, 2=GRAY, 3=WHITE, 4..127 reserved |
| 55..0  | 56    | `rc` — signed; trial-deletion can drive negative         |

Constants (also live in `Compile/MemoryAbi.php` once that lands):

```
RC_MASK        = 0x00FFFFFFFFFFFFFF   ( bits 0..55 )
COLOR_MASK     = 0x7F00000000000000   ( bits 56..62 )
BUFFERED_MASK  = 0x8000000000000000   ( bit  63     )
COLOR_SHIFT    = 56
```

### 1.2 Reading the rc

The rc is conceptually signed (Bacon-Rajan trial deletion decrements
beyond zero). To read, mask + sign-extend:

```llvm
%rc_only = and i64 %word, 0x00FFFFFFFFFFFFFF
%shl8    = shl i64 %rc_only, 8
%rc_s    = ashr i64 %shl8, 8        ; signed 56-bit value
```

Comparisons against 0 must use signed predicates (`sgt`, `sle`).

### 1.3 Writing the rc

Only ever rewrite the `rc` field — color and buffered are mutated by
separate helpers. To bump or decrement by ±1:

```
new_word = (word & STATE_MASK) | ((rc + delta) & RC_MASK)
```

Plain `add %word, 1` is **forbidden** — carry overflow into the
color field is a bug pattern (see history, this used to "just
work" because the corruption mostly landed in libc-internal bytes
nobody read).

### 1.4 `#[Struct]` opt-out

Classes tagged `#[Struct]` skip the 16-byte header entirely.
Fields start at offset 0, dispatch is fully static. No `obj_retain`,
`obj_release`, or cycle-collector hook touches struct values.

### 1.5 `#[NoRefcount]` opt-out (PLANNED)

A separate marker (proposed) for classes that wrap raw external
pointers (`Ffi\Ptr` and friends). Instances still have the 16-byte
header pattern in their declaration but the runtime memory is
external (e.g. `FILE *` returned by libc `fopen`). The compiler
must never emit `obj_retain` / `obj_release` for these.

The same opt-out applies to every class under a reserved namespace
prefix (today: `Ffi\`).

Tracking: this opt-out is the FFI-namespace-gate work parked in
[[obj-alias-norefcount-field-bug]].

---

## 2. Assoc-array header

32 bytes at offset 0 of every hashmap allocation:

```
offset 0  : i64  length
offset 8  : i64  capacity
offset 16 : i64  next_int_key
offset 24 : i64  rc            -- plain (no color/buffered packing today)
offset 32 : ...  entries[]     -- 24 bytes each: kind / key / value
```

Each entry is 24 bytes:

```
offset +0  : i64  kind   -- KIND_INT / KIND_STRING / KIND_TOMBSTONE
offset +8  : ptr  key    -- string key (null for int keys)
offset +16 : i64  value  -- NaN-boxed payload
```

The 16-byte empty-`[]` STUB carries `length == capacity == 0` and
**has no rc field** — every assoc helper must guard `cap == 0`
before touching the rc word.

### 2.1 Cycle-collector status

Assoc rc is **not** packed with color/buffered today. Cycles routed
through assoc values are not collected. Future revision: either
widen assoc header to 40 bytes and adopt the same packing, or treat
the assoc as a transparent edge (walk its object-typed values from
the parent's child-iteration step).

---

## 3. Ownership transitions

Three categorisations, computed by `exprProducesAssoc()` /
`exprProducesObject()`:

| Category   | Source RHS                                                        | Caller action                                  |
|------------|-------------------------------------------------------------------|------------------------------------------------|
| `transfer` | `new ClassName()` or callee that already dropped its return-local | mark LHS owning → release at scope exit        |
| `alias`    | borrowed reference (`$y = $x`, prop load, call result)            | retain at the site; release at scope exit     |
| `none`     | scalar, FFI raw ptr, unknown                                       | nothing                                        |

Three-state table is enforced at every alias site:

- assignment RHS (`$x = ...`)
- property store (`$obj->p = ...`)
- call argument (function and method calls)
- foreach value/key binding
- promoted constructor parameter → property store
- closure capture by-value

Each site must consult the predicate before emitting `obj_retain` /
`assoc_retain`. Missing the predicate is the most common way to
introduce a corruption-on-raw-ptr bug.

### 3.1 `Return`

Returning a value transfers ownership to the caller. The returned
local is dropped from `assocOwnedLocals` / `objOwnedLocals` BEFORE
the expression is compiled, so scope-exit release skips it.

### 3.2 References (`&`)

By-reference assignment forwards the slot to a shared cell. Refcount
ops are bypassed at the binding site — the underlying cell still
participates via whatever local owns the actual buffer.

---

## 4. Destructor order

When `obj_release` brings rc to 0 (signed comparison), control
enters the per-class destructor switch (see `emitObjReleaseHelper`).
The destructor body for class `C`:

1. Run the user `__destruct()` if declared. (Not implemented yet —
   future work.)
2. For each ptr-typed property `p` of `C`, in **declaration
   order**:
   - If `p` is assoc → `__manticore_assoc_release(load p)`.
   - If `p` is a refcounted object (non-Struct, in class table,
     not `#[NoRefcount]`) → `__manticore_obj_release(load p)`.
   - If `p` is a struct or `#[NoRefcount]` → skip.
3. `free(self)`.

Children must be released **after** the user destructor so destructor
code still sees its referenced state. Children are released
**before** `free` so their own destructors see a valid parent
pointer when they trace upward (we don't have weak refs).

Recursion depth bound: this is direct recursion through
`obj_release`. PHP semantics allow arbitrarily deep ownership
chains; the runtime must not impose a stack limit beyond OS thread
stack. Linked-list destruction patterns can blow the stack today —
future work: iterative release via worklist.

---

## 5. Child iteration ABI

Used by both the destructor switch (above) and the cycle collector
walkers (`mark_gray`, `scan`, `scan_black`, `collect_white`).

Single helper signature:

```
forEachRefcountedChild(block, obj, classInfo, action)
forEachObjectChild(block, obj, classInfo, action)
```

`forEachRefcountedChild` walks every ptr property that's either an
assoc buffer or a refcounted-object pointer. `forEachObjectChild`
restricts to object children only (used by the cycle collector,
since assoc edges don't participate in v1 cycle detection).

Iteration order: **declaration order**. Stability matters — the
cycle collector's correctness depends on every walker visiting the
same set in the same order, otherwise trial-deletion and
restoration get out of sync.

A class with zero refcounted children contributes **no case** to
the destructor / walker switch. Default fallthrough is a no-op.

`action` receives `(block, child_ptr, is_assoc_flag)` for the
destructor variant, `(block, child_ptr)` for the object-only
variant.

---

## 6. Cycle-collector ABI (PARKED, not in tree)

Eight runtime functions, all `void` or `i64` return:

| Symbol                                | Sig            | Role                                          |
|---------------------------------------|----------------|-----------------------------------------------|
| `__manticore_cc_add_root`             | `(ptr)`        | Push to `__manticore_cc_candidates`           |
| `__manticore_cc_collect_cycles`       | `(): i64`      | Orchestrator. Returns freed count.            |
| `__manticore_cc_mark_roots`           | `()`           | Mark or drop each root                        |
| `__manticore_cc_mark_gray`            | `(ptr)`        | Recursive trial-deletion                      |
| `__manticore_cc_scan_roots`           | `()`           | Per-root `scan`                                |
| `__manticore_cc_scan`                 | `(ptr)`        | Recursive scan                                |
| `__manticore_cc_scan_black`           | `(ptr)`        | Recursive rc restore                          |
| `__manticore_cc_collect_roots`        | `(): i64`      | Drain candidates, free whites                 |
| `__manticore_cc_collect_white`        | `(ptr): i64`   | Recursive free of a white subgraph            |

Global state:

```
@__manticore_cc_candidates : ptr   (heap vec of obj ptrs)
@__manticore_cc_count      : i64
@__manticore_cc_capacity   : i64
```

Triggers (currently V1 — manual only):

- **V1**: explicit `gc_collect_cycles()` PHP call (the only way).
- **V2**: threshold trigger — capacity-based heartbeat. Env var
  `MANTICORE_CC_THRESHOLD` (default e.g. 65536 candidates).
- **V3**: safe-point trigger — collect at loop back-edges and
  allocation gates. **No async concurrent collection**: V1 thru V3
  all run synchronously.

---

## 7. Verification mode (PLANNED)

Compile-time flag `MANTICORE_DEBUG_VERIFY=1` enables a slow path
after every memory op:

| Op            | Post-condition checks                                              |
|---------------|--------------------------------------------------------------------|
| `obj_retain`  | rc was `>= 0` before bump; class_id is in the live table          |
| `obj_release` | rc was `>= 1` before dec; no double-free (rc == -1 ⇒ assert)      |
| `cc_add_root` | object not already `buffered` AND `color == PURPLE` after stamp   |
| `cc_collect`  | every freed obj's `color == WHITE` and `buffered == 0` at free   |
| `assoc_retain`| cap > 0; rc was `>= 0`                                             |
| Destructor    | each ptr child seen at most once (set-marker on entry to release) |

All checks emit a `dprintf(2, ...)` message + `abort()` on failure.
Production builds skip the check emission entirely — single bool
in `Compile/Debug.php`.

`MANTICORE_DEBUG_RC_TRACE` (already exists) stays the lower-tier
"just print every op" flag. Verify mode adds invariant checks on
top.

---

## 8. Versioning

When any of the layouts above changes, bump `MANTICORE_MEM_ABI_VERSION`
in `src/Compile/MemoryAbi.php` and update the **same patch** to:

- Rewrite the destructor / walker switch emission against the new
  shape.
- Update every direct offset GEP (`compileNew`, ExprCompile
  synthetic closure init, etc.).
- Bump `OBJECT_HEADER_SIZE` if it grew.

The version constant is read by `bin/manticore --version` so a
binary compiled against ABI N refuses to interop with ABI N+1
artefacts.

This versioning is **not in place yet** — see the improvement
roadmap in `docs/ROADMAP.md`.
