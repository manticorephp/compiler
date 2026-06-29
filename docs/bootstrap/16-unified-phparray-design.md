# Unified PhpArray — design + staged plan (2026-06-09)

> ## ✅ STATUS: COMPLETE (2026-06-10) — all 4 stages + 2 follow-ups shipped
> The plan below landed in full; this doc is now historical reference.
> - **Stages 1–4 DONE.** ONE runtime `PhpArray` (56B header, rc@24, PACKED
>   fast-path + lazy HASHED), unified default, dual vec/assoc split DELETED
>   (`bb064d4`, −2244 lines; AssocRuntime/AssocIndex gone; no `--array` flag).
>   The self-host SIGSEGV that blocked the Stage-4 flip was root-caused to
>   `rcRetainByType` never routing to unified (`c3a0003`).
> - **Follow-up 1 — KIND collapse (`2fbf13c`)**: `KIND_VEC`+`KIND_ASSOC` →
>   one `KIND_ARRAY` (vec = key-null, assoc = key-string). The vec-or-assoc
>   guess (Gap A / empty-`[]` UAF) is now structurally impossible.
> - **Follow-up 2 — FNV bucket index (`2f5efb4`)**: lazy open-addressed index
>   for HASHED get/isset (~17x; build-on-demand, never-wrong-only-degrades).
> - **Arena path for unified buffers: MEASURED → DEFERRED.** Confined alloc is
>   already ~5x faster than Zend (~13ns/iter); negative risk/reward. Numbers +
>   design in memory `unified_phparray_phase3`.
>
> Gates at completion: run.sh 203/203, goldens 39/39, stability_check 4/4.
> Current roadmap: memory `docs/ROADMAP.md`.

**Phase 3 of the type-system redesign** (`docs/bootstrap/15`). Replace the
dual **vec / assoc** array representation with ONE runtime `PhpArray`
(ordered hashtable + packed-int fast path), one header layout, one rc path,
one ownership analysis. Committed direction after THREE heisenbug-blocked
patch attempts on the dual representation
(`[[residual_container_local_leaks_2026_06_09]]`).

## Why this, not another patch

Every residual-leak fix (force-RC_HEAP, ApplyMemoryMode, Fix-A rc-release)
died with the SAME signature: scan-only stable 203/203, add an rc-free on a
confined assoc → 0/203 silent `Abort trap: 6`, clean under verify, no
malloc/`[VERIFY]` message. That is a **latent heap corruption already in
`bin/manticore`** that any alloc-pattern shift wakes. Its root is the dual
representation:

- **Gap B** — vec (`rc@8`, 16B header) and assoc (`rc@24`, 48B header) are
  layout-incompatible. A value typed one way, laid out the other ⇒ rc at the
  wrong offset ⇒ OOB ⇒ corruption. Phase 1 (`fe60cd2` tagging) made the
  misroute *fail loud* but did not remove it.
- **Ownership is computed twice** (vec rules + assoc rules) and the **assoc
  side is incomplete** (heavy aliasing: COW, return, store-prop). The gap is
  where the latent corruption lives.

The corruption is **invisible to verify + libgmalloc** (2 sessions could not
catch it; FINAL BOSS note: don't chase with lldb/GM). You make an invisible
class visible by **removing the ambiguity that creates it**. One
representation ⇒ one rc offset (no wrong-offset possible) ⇒ one ownership
analysis (no assoc gap). The heisenbug class dissolves structurally; it is
not hunted.

This also subsumes:
- **Fix B** (assoc arena path) — unified alloc is arena-aware for BOTH modes,
  so confined arrays land in the arena and `arena_leave` reclaims them. The
  dominant assoc-local leak (lk4 468MB) closes as a side effect.
- **Phase 2** (empty-`[]` typing) — there is no vec-vs-assoc to mistype. `[]`
  is one empty array that becomes hashed lazily on first string/sparse key.
  The static kind drops from *correctness requirement* to *perf hint*.
- **Stage 4** (vec COW) — COW becomes one array helper.

---

## The unified layout — ONE header, two runtime MODES

Reuse today's **48-byte assoc header** (minimal disruption: every existing
fixed-offset read of len/cap/next_int/rc stays valid). Repurpose the two
index words into a `flags` word + the bucket pointer. rc stays at **offset
24 for EVERY array, packed or hashed** — this single fact kills the
wrong-offset heisenbug.

```
PhpArray buffer — tag ARRAY_TAG_MAGIC at base-8, data ptr = base (= malloc_base+8)
  off  0:  len        i64   live element count
  off  8:  cap        i64   slot capacity (i64 slots if packed; entry slots if hashed)
  off 16:  next_int   i64   next implicit int key (for `$a[] =`)
  off 24:  rc         i64   refcount   ← SINGLE FIXED OFFSET, all arrays
  off 32:  flags      i64   bit0 = HASHED (0 = PACKED); bits 1+ reserved
  off 40:  buckets    ptr   hash bucket side-array (null while PACKED)
  data @48:
    PACKED  : contiguous i64 values, 8B each, keys implicit 0..len-1  (== today's vec body)
    HASHED  : entries, 24B each [kind i64, key ptr, value i64]        (== today's assoc body)
HEADER_SIZE = 48 (unchanged from assoc)
```

- **PACKED** is today's vec, shifted to base+48 and carrying the full header.
  Append / int-index / foreach are the current vec hot paths with a different
  constant base offset. This is the **packed-int fast path** (Zend's
  packedness): no buckets, no per-entry key, 8B/element.
- **HASHED** is today's assoc (entries + lazy FNV bucket index).
- **Transition PACKED→HASHED** is lazy, exactly Zend's break-of-packedness:
  first **string key**, or a **sparse / non-append int key** (`$a[5]=x` on a
  len-2 array), promotes the buffer — rewrite the i64 values as int-keyed
  entries 0..len-1, set `flags|=HASHED`, then insert the new key.
- **Empty `[]`** allocates a PACKED stub (cap can be 0; first op grows it).
  There is no "is it vec or assoc" decision — it is an array, currently
  packed. **Gap A dissolves.**

### rc / tag discipline
- One tag `ARRAY_TAG_MAGIC` (distinct from RC_TAG_MAGIC / ASSOC_TAG_MAGIC) at
  base-8. While both runtimes coexist (Stages 1–3) the new array is a THIRD
  tag; at Stage 4 it replaces vec+assoc tags.
- Dedicated `__mir_rc_retain_array` / `_release_array`: array-typed call sites
  always know the static kind is an array, so direct dispatch — rc always at
  24, no self-route guess. Generic `__mir_rc_*` self-routing stays for the
  string-vs-obj erased case only.
- Element drop branches on `flags` at runtime: PACKED walks i64 slots @48,
  HASHED walks entries; each drops obj/str values (and HASHED drops string
  KEYS — fixes residual root-cause 2 uniformly).
- **One arena path**: the unified allocator is arena-aware for both modes
  (PACKED append uses `__mir_arena_realloc` like vec today; HASHED set/grow
  routes through the same arena-aware alloc). Kills the assoc-no-arena leak.

---

## Staged plan — each stage independently gated, behind `--array=unified`

Gate EVERY step: `tools/stability_check.sh 6` + `tests/aot/run_mir_golden.sh`
+ `tests/aot/run.sh` (confirm via the `passed:` line — `stability_check`
false-reports STABLE on red). Verify build is the localization tool. Debug
with FILE ARGS (`.manticore.php` shadows stdin). Default build (no flag) must
stay byte-identical until Stage 4 → `bin/manticore` self-host stays green
throughout.

### Stage 1 — runtime only, flag-gated, NOT wired
Add `MemoryAbi` constants (ARRAY_TAG_MAGIC, ARR_* offsets, flags). Emit the
full unified IR runtime (modeled on `AssocRuntime` + `EmitLlvmRuntime` vec
helpers): `__mir_array_alloc` (packed stub, arena-aware), `_retain` / `_release`
/ `_cow`, `_get` (int fast + string hashed) / `_set` (with lazy PACKED→HASHED
promotion) / `_append`, `_index_*` (reuse FNV from AssocIndex), foreach
addr/key helpers, element-drop walkers (packed/hashed × obj/str + key drop),
`count`. Behind `--array=unified`: emit the IR but DO NOT route codegen.
**Gate:** flag-on build links + runs hello/class_basic; flag-off build
byte-identical (suite 203/203 unchanged). Ship alone.

### Stage 2 — wire codegen behind the flag
When `--array=unified`: route emitArrayLit / emitStoreElement / emitArrayAccess
/ foreach / count / array_pop|shift|unshift to the unified helpers. **Keep the
static KIND_VEC / KIND_ASSOC types as packed-vs-hashed HINTS** (start-packed /
start-hashed) — do NOT touch the fragile inference yet (56-test blast radius).
A wrong hint can no longer corrupt: the runtime handles string keys regardless
of static kind (lazy promotion). This is "runtime safety first" achieved by
unification. **Gate:** full suite + goldens + stability under the flag; flag-off
unchanged.

### Stage 3 — unified rc / ownership / COW
One flavor `'array'` in InsertMemoryOps / InferAllocKind; one release helper
(packed/hashed dispatch at runtime); one arena verdict. The confined-array
leak fixes (Fix A/B) become trivial and heisenbug-free — one layout, rc@24,
arena path. **Gate:** the lk4/lk4b/lk6 leak meters flat under the flag;
suite/goldens/stability green.

### Stage 4 — flip default + delete the split
Make `--array=unified` the default. Self-compile `bin/manticore` on it; it must
stay green (it embeds this runtime). Then delete dead code: emitVecLit /
emitAssocLit merge, the two rc paths collapse to one, vec/assoc tags → array
tag, the scanAssoc* heuristics simplify (kind is now a hint). Optionally
collapse the Type lattice KIND_VEC+KIND_ASSOC → KIND_ARRAY (element+key
refinements as today) — LAST, since it touches inference.

---

## Replacement worklist (from the code map, 2026-06-09)

Runtime (`EmitLlvmRuntime.php` vec helpers, `AssocRuntime.php` + `AssocIndex.php`
assoc, `MemoryAbi.php`): ~50 IR fns — alloc/realloc, rc retain/release, cow,
copy/drop walkers (`__mir_rc_release_vec{,_obj,_str}`,
`__mir_rc_release_assoc_{str,obj}`), get/set/has/unset, index build/find/drop,
foreach addr/key, count. Collapse to the unified set above.

Codegen branch points (`EmitLlvm.php`): literal 4269, store-element 4470 +
unknown-string-key 4474, index-load 4407 + 4413, foreach 2322 / foreachElemAddr
2528 / key 2396, count `EmitLlvmBuiltins.php` biCount 257, array_pop/shift/
unshift 787/816/858 (vec-only → become packed/hashed-aware). Memory:
InsertMemoryOps flavor 130, owned 172; InferAllocKind isMutableContainer 269;
ApplyMemoryMode 60; emitAssocValueDrop 3071.

Type (`Type.php`, `InferTypes.php`, `LowerFromAst.php`): KIND_VEC/KIND_ASSOC
(~61 checks), inferArrayLit 1173 (Gap A 1195), scanAssoc* 182/308/570/678/1225,
isBareArrayHint / lowerTypeHint 2054 (Gap C 2131). **Touched LAST (Stage 4).**

## Invariants
- Default (flag-off) build unchanged every stage → self-host green throughout.
- One header, rc@24 for all arrays — the structural heisenbug kill. Never
  reintroduce a layout where rc offset depends on a static guess.
- Tags load-bearing, fail loud (header-tagging precedent `docs/bootstrap/14`).
- Land minimal increments; gate each (suite via `passed:` line + goldens +
  stability 6 + verify). Leak meter = `php tools/compile_files_mir.php`
  (native-binary RSS is unreliable, see residual note).
</content>
</invoke>
