# Header tagging — self-routing rc helpers (heisenbug durable kill)

Status: **IMPLEMENTED** (commit `fb84157`, 2026-06-07). run.sh 200/200, goldens
39/39, stability_check 6/6, enum_backed_int/string 100/100 compile stress. The
final impl used a SINGLE `RC_TAG_MAGIC` (no obj/vec sub-kind — the named release
helper already knows whether to drop), free is uniformly `ptr-8`, and strings/
assoc/closures stayed untouched (closures have no rc header; assoc keeps its own
helper; strings self-route by the absent magic). Validation caveat below.

> **Validation — CONFIRMED with a sound repro.** A sound heap pad
> (`MANTICORE_HEAP_PAD=N`, leaked startup strings — pure layout shift) tested
> pre/post tagging on enum_backed_int (×20 per pad ∈ {0,1,3,7,13,50,200,500}):
> untagged ≈ 6/20 ok (~70% fail) at EVERY pad; tagged 20/20 at every pad (140
> runs, 0 fail). Same perturbation, opposite result ⇒ tagging kills the bug.
> (The `consistentPropOffset` repro is CONFOUNDED — it writes guessed offsets,
> unsound — so disregard it as a validator; pre-tagging it string-flipped,
> post-tagging it aborts on a corrupted tag, neither being the clean signal.)

---

## Original design (below)


## Problem (from the 07c-07h hunt)

The enum/arena heisenbug is a **dynamic rc misroute**: a value that is
statically typed `obj`/`vec` but is a **string** at runtime gets passed to
`__mir_rc_retain` / `__mir_rc_release`, which write the rc at `ptr+8`. A
string's rc lives at `ptr-8` (hidden 8-byte header behind the data ptr), so the
write lands at `ptr+8` = byte 8 of the string contents — or, for a short
string, +1 into the adjacent arena/heap chunk's first byte. Low-rate,
ASLR-dependent, invisible to static analysis (static type == runtime type at
every checkable site, confirmed by the §07h LIVEREL probe). Both the B2 transfer
guard and a `returnsBorrowedElements` pass are therefore INERT — the bug is not
a static over-release.

The only durable kill: make `__mir_rc_retain` / `__mir_rc_release` **self-route
by a runtime discriminant**, so a wrong static type cannot send a string down
the obj/vec path (or vice-versa).

## Current layouts (from `MemoryAbi`)

| kind   | ptr points at | rc location | header before ptr |
|--------|---------------|-------------|-------------------|
| obj    | `class_id@0`  | `ptr+8`     | none              |
| vec    | `len@0`       | `ptr+8`     | none              |
| assoc  | `len@0`       | `ptr+24`    | none              |
| string | `data@0` (char*, for libc) | `ptr-8` | 8B rc (−1 = immortal) |

Key enabler: **a string's `ptr-8` is ALWAYS a valid i64** (its rc; `< 0` ⇒
immortal literal/arena, already special-cased by both `__mir_rc_retain_str` /
`_release_str`). obj/vec have **nothing defined** at `ptr-8` today.

## Design — magic tag at `ptr-8` on obj/vec

Give obj and vec a tag word at `ptr-8`; leave strings and assoc untouched. The
universal helper reads `w = *(ptr-8)`:
- `(w & ~0xF) == TAG_MAGIC` ⇒ obj/vec — rc at `ptr+8`, kind in `w & 0xF`.
- else ⇒ `w` is a string's rc — route to the string path (`< 0` immortal
  no-op; else inc/dec at `ptr-8`, free `ptr-8` at zero).

`TAG_MAGIC` must be unreachable by a real string rc:
- heap rc is small (`< 2^56`, `RC_MASK`); immortal rc is `-1` (all bits set).
- Pick `TAG_MAGIC = 0x7E66_0000_0000_0000` (kind in low nibble). Distinct from
  any `rc < 2^56` and from `-1`. (Avoid `0xFFFF…` and small ints.)
- Sanity: a release that ever observes a real obj/vec rc word equal to the magic
  is impossible (rc never reaches bit 57+). Add a `MANTICORE_DEBUG_VERIFY`
  assert anyway.

Kinds in the nibble: `TAG_OBJ=0`, `TAG_VEC=1`. (assoc keeps its own
`__manticore_assoc_*` helper — it is type-called and never reaches
`__mir_rc_retain`, so it is out of scope; revisit only if a misroute into assoc
is ever observed.)

### Why this kills the bug

After the change, `__mir_rc_retain`/`_release` and `_retain_str`/`_release_str`
all become the **same self-routing body** (the static-type choice at the call
site no longer matters): a string arg routes to the string path, an obj/vec arg
to the `+8` path, regardless of which name the codegen picked. The misroute is
structurally impossible.

## Implementation surface

Mechanical but wide. Every obj/vec **alloc** reserves 8 leading bytes, writes
the tag at the base, and returns `base+8`; every obj/vec **free** frees
`ptr-8`. Internal header offsets relative to ptr are UNCHANGED (`class_id@0`,
`rc@8`, props@16, vec `len@0`/`rc@8`/elems@16) — only the allocation base and
free pointer shift.

Alloc sites (write tag, `+8`):
- `emitNewObj` (obj) + the synthetic closure allocator.
- `emitArrayLit` (vec literal), `emitVecLitSpread`, `emitVecAppend`
  (**realloc**: `realloc(ptr-8, sz+8)`, ret `+8`), the vec-copy helpers
  (`__mir_vec_copy` / `_obj` / `_str`).
- Arena variants (`__mir_arena_alloc` for obj/vec) — MUST also carry the tag,
  else an arena obj/vec misreads as a string. Arena frees are bulk (no per-obj
  free), so only the tag write + `+8` shift apply.

Free sites (`free(ptr-8)`):
- `__mir_rc_release` (obj), `_release_vec`, `_release_vec_obj`,
  `_release_vec_str` — each `free(%p)` ⇒ `free(getelementptr %p, -8)`.

Helper rewrite:
- `__mir_rc_retain` / `__mir_rc_release` become tag-dispatch (obj/vec `+8` vs
  string `-8`). The element-drop variants (`_vec_obj` / `_vec_str` / obj
  `drop_dispatch`) stay type-called (they need the element type, which the tag
  does not carry) but their leading buffer-rc dec + `free` route through the
  shifted base.
- `__mir_rc_retain_str` / `_release_str` can alias the universal body (a string
  arg self-routes correctly), or stay as-is — either works once obj/vec carry
  the tag.

`MemoryAbi`: add `TAG_OFFSET = -8`, `TAG_MAGIC`, `TAG_OBJ`, `TAG_VEC`; bump
`OBJECT_HEADER_SIZE`/`VEC_HEADER_SIZE` accounting for the leading tag (or model
the tag as a separate `-8` prefix, leaving the in-header offsets as today). Bump
`MemoryAbi::VERSION`.

## Risk & rollout

This shifts EVERY obj/vec allocation base — exactly the kind of layout change
that has woken the dormant bug before. But here the change makes the misroute
**structurally impossible**, so a correct landing eliminates the class rather
than moving it. The danger is an INCORRECT landing: one missed `+8` alloc or one
`free(%p)` not switched to `free(%p-8)` is instant corruption.

Rollout:
1. Land alloc-tag + free-shift + universal helper in ONE change (cannot be
   half-applied — a tagged alloc with an un-shifted free, or vice-versa,
   corrupts).
2. Gate with `tools/stability_check.sh 6` (build once + suite ×6 on one binary).
3. Keep `MANTICORE_DEBUG_VERIFY=1` asserts: tag-or-valid-rc at `ptr-8`;
   `class_id != 0` after the shift; free base alignment.
4. Validate goldens 39/39 + the MIR sweep.

Estimated 1 focused session. Do it standalone (no other rc changes in flight),
because partial application corrupts and the validation signal is the whole
suite × N.

## Implementation checklist (site map, EmitLlvm.php line refs @ `fed1db3`)

Each obj/vec alloc: `base = alloc(sz+8); store TAG_MAGIC|kind, base; ptr = base+8`.
Each obj/vec free: `free(ptr-8)`. Strings/assoc untouched. Verify which kind each
`__mir_alloc` site is before editing (obj vs vec vs string).

Alloc sites (`grep __mir_alloc`):
- `2945` emitNewObj-ish / `6516` emitNewObj — **obj** (tag OBJ).
- `4590` closure allocator — **obj** (tag OBJ).
- `6196` emitVecLitSpread init / array-lit `emitArrayLit` (~`6140`) — **vec** (tag VEC).
- `2351` / `2678` / `786` — string / cell paths — classify each (string allocs
  keep their own `-8` rc header; do NOT tag — they self-route by absence).
- `727` `__mir_alloc` def, `1001` `__mir_arena_alloc` def, `749` arena entry —
  arena obj/vec MUST write the tag too (no per-obj free; bulk arena free).

Realloc (vec grow — must preserve/relocate the tag, realloc the BASE):
- `3233`/`3236` emitVecAppend (`realloc(ptr-8, sz+8)`, ret `+8`; arena variant
  `__mir_arena_realloc` at `1071`/`3233`).
- `5283` (vec builtin grow), `6261` emitVecLitSpread grow — same.

Free sites (`grep @free`) → `free(ptr-8)` for obj/vec, unchanged for string(`980`):
- `837` obj release, `857` vec release, `895` vec_obj release, `931` vec_str
  release, `3824` (other vec free). `980` is the string-header free (`%h` =
  data-8) — leave as-is.

Helper bodies to make self-routing: `__mir_rc_retain` (`807`), `__mir_rc_release`
(`821`); the `_vec*` release variants (`845`/`867`/`903`) keep their element walk
but free the shifted base. `__mir_rc_retain_str`/`_release_str` (`947`+) may alias
the universal body.

Also: the assoc runtime is a SEPARATE file (`src/Compile/Runtime/AssocRuntime`)
— out of scope here (assoc keeps its own helper).

## Out of scope / follow-ups
- assoc self-routing (only if a misroute into `__manticore_assoc_*` is observed).
- Folding the cycle-collector color/buffered bits into the same tagged header
  (Stage 3) — the tag and the rc word can coexist; revisit at CC revival.
