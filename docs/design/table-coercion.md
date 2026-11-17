# Table-driven argument coercion

Status: design (phase A: census + plan). Nothing here is built.
Follows [table-dispatch.md](table-dispatch.md), "Phase C notes": a runtime argument binder for the
dispatch helpers is worth building only if coercion itself becomes one routine that both the
emitter and the binder call. This document measures what coercion costs today and plans that
routine.

## Census

### Method

The coercion emitters were wrapped, temporarily, so every non-empty piece of IR they return is
bracketed by `; @CO <label>` … `; @CE` comment lines (comments change no code; the wrapper was
reverted afterwards):

| label | emitter | what it is |
|---|---|---|
| `arg:<argkind>><paramkind>` | `unboxCellArg` | the call-argument boundary (everything below may nest in it) |
| `unbox:<kind>` | `unboxCellToType` | cell → typed repr, every caller (args, returns, property / element / static stores, dispatch arms) |
| `objguard` | `emitObjArgGuard` | scalar-tag guard with a TypeError / Error; outermost = the RECEIVER guard of a method call on a cell (`Call to a member function m() on int`), nested in `arg:` = an object argument |
| `raise` | `emitColdRaise` | the cold per-site throw block (message `select` + raise call) |
| `caststr` | `coerceCellToStr` | the `Cast('string')` TypeCheck plants for a cell bound to a `string` param |
| `box:<kind>` | `boxToCell` | typed → cell (mixed params, returns into cell slots) |
| `boxret` | `boxDispatchReturn` | return boxing in dispatch helpers |
| `default` | `boxOmittedDefault` | omitted-default boxing (never fired above 0.1 MB) |

`bin/manticore build -O0 --keep-ir` (instrumented `bin/build --fast` binary, base
`perf/table-coercion` @ 9c6845f1, stacked on PR #707), then one awk pass over `bin/app.dbg.ll`:
each body line inside a marker is charged to the outermost label (sizes) and to the innermost
label (composition), and to the family of the enclosing `define`. "Bytes" are IR text bytes, "ins"
are instruction lines (two-space indent; labels and markers excluded). Families: `dispatch` =
`__mc_rtramp_` / `__mc_fntramp_`, `__mir_vdisp_`, `__mir_dynarm_`, `__mir_spfill_`,
`__mc_static_callable*`, `__mir_dynfs_` / `__mir_dynft_`; `propacc` = `__mc_prop_read_` /
`__mc_prop_write_` / `__mc_pget_` / `__mc_pset_`; `othergen` = every other `__mc_` / `__mir_`
body; `user` = everything else (user code, vendor code, prelude classes).

### Coercion IR by family

| family | symfony defines MB | coercion MB | % | laravel defines MB | coercion MB | % |
|---|---:|---:|---:|---:|---:|---:|
| user (ordinary typed boundaries) | 355.5 | 27.7 | 7.8 | 894.8 | 74.3 | 8.3 |
| `__mir_dynarm_` | 30.5 | 12.9 | 42.2 | 61.6 | 11.7 | 18.9 |
| `__mc_rtramp_` | 32.1 | 5.7 | 17.6 | 113.2 | 20.2 | 17.8 |
| `__mir_vdisp_` | 27.0 | 3.9 | 14.4 | 124.9 | 18.3 | 14.7 |
| `__mir_spfill_` | 17.4 | 2.2 | 12.4 | 42.3 | 4.5 | 10.7 |
| `__mir_dynfs_` / `dynft_` | 6.6 | 1.8 | 26.7 | 20.0 | 5.3 | 26.4 |
| `__mc_static_callable` | 8.8 | 0.8 | 8.6 | 59.5 | 4.0 | 6.7 |
| `__mc_prop_read_` / `write_` | 34.1 | 5.7 | 16.6 | 111.3 | 15.7 | 14.1 |
| `__mc_pget_` / `pset_` | 16.6 | 0.8 | 4.7 | 40.7 | 2.8 | 7.0 |
| other generated | 67.8 | 5.8 | 8.5 | 187.4 | 11.3 | 6.0 |
| **total** | **596.3** | **67.0** | **11.2** | **1 656** | **168.0** | **10.1** |

Dispatch helpers (the first six rows after `user`) carry 27.3 MB (symfony) / 64.0 MB (laravel),
ordinary user boundaries about the same again.

### What the IR is made of (innermost label)

| innermost | symfony MB | laravel MB | note |
|---|---:|---:|---|
| `raise` | 27.5 | 50.4 | the per-site cold throw block: or-chain, one string constant per arm, `select` chain, raise, exception check |
| `caststr` | 10.0 | 30.6 | obj/scalar diamond around two runtime calls, two exception checks, then an own/retain diamond |
| `unbox:string` | 6.0 | 17.8 | already one call (`__manticore_cell_to_strptr_s`) + ptrtoint + the own-select |
| `objguard` | 5.8 | 9.2 | tag call + 8 compares (its raise is counted above) |
| `unbox:int` | 3.8 | 8.6 | deref + `cell_int_bad` + 3 compares + `cell_to_int_arg` (raise above) |
| `box:*` (+ `boxret`) | 8.8 (+0.8) | 31.9 (+5.3) | 1–2 instructions per site: already a call or bit ops, nothing to win |
| rest | 4.3 | 14.2 | |

### Sites (outermost label), the ones above 0.3 MB

| group | label | symfony sites | B/site | laravel sites | B/site |
|---|---|---:|---:|---:|---:|
| user | receiver `objguard` | 9 475 | 1 200 | 22 309 | 1 216 |
| dispatch | receiver `objguard` (dynarm) | 9 200 | 1 124 | 6 324 | 1 124 |
| othergen | receiver `objguard` | 1 278 | 1 242 | 1 974 | 1 253 |
| user | `caststr` | 5 297 | 1 046 | 14 805 | 1 027 |
| dispatch | `caststr` | 4 381 | 982 | 15 469 | 980 |
| dispatch | `unbox:int` (+ `arg:int`) | 5 796 | 1 000 | 14 657 | 1 010 |
| propacc | `unbox:int` | 3 617 | 963 | 8 056 | 971 |
| user | `arg:int` + `unbox:int` | 2 723 | 980 | 5 727 | 1 030 |
| othergen | `unbox:int` + `arg:int` | 1 476 | 990 | 2 212 | 1 008 |
| all | `unbox:string` / `arg:string` | 18 720 | 280–410 | 60 094 | 270–410 |
| all | `box:*`, `unbox:obj/array/bool/cell` (listed rows only) | ≥ 58 000 | 45–220 | ≥ 460 000 | 45–220 |

### Findings

1. **Conversion is already out of line; the bytes are the per-site error path.** Every
   conversion is a runtime helper (`__manticore_cell_to_int_arg`, `__manticore_tagged_to_double`,
   `__manticore_cell_to_strptr_s`, `__manticore_tagged_to_str`, `__mir_obj_to_str`). What is
   inline per site is the TypeError: a cold block with one string constant per "given" type and a
   `select` chain (`raise`, 41 % of symfony's coercion IR, 30 % of laravel's), the compares feeding
   it, and the branch skeletons around the calls (`caststr`).
2. **The biggest single item is not an argument at all**: the receiver guard of a method call on a
   cell (`Call to a member function m() on int|string|false|true`), ~20 000 sites in symfony
   (23 MB), ~30 600 in laravel (37 MB), 25 instructions each. In `__mir_dynarm_` it is most of the
   family's coercion (10.3 of 12.9 MB in symfony).
3. **There is no second coercion implementation to merge.** Trampolines are PHP source
   (`TrampolineSynth`: `$o->m($a[0], …)`) and coerce through the ordinary call boundary
   (`unboxCellArg`); `vdisp` / `spfill` arms call `unboxCellToType` directly; property writers the
   same. One change at the boundary moves dispatch helpers and user code together. The routine
   this document plans therefore IS the boundary: the emitter calls it, and a later pack binder
   (C1) calls the same entry points.
4. **Typed-to-typed calls emit no coercion.** `unboxCellArg` returns `''` unless the argument is
   a cell (or a scalar rendered into a `string` param of a known function). The hot-path risk is
   confined to cell→typed sites, which today already make one or two runtime calls each.
5. **Semantic gaps the census exposed** (each becomes data in a descriptor, none is fixed by this
   plan's size steps):
   - the int TypeError reads `Argument must be of type int, null given`; php says
     `f(): Argument #1 ($x) must be of type int, null given, called in F on line L`. The object
     guard has the php head (`objArgHeads`), but only for `KIND_CALL` sites, not methods;
   - a `float` param takes null / array / non-numeric string silently (`tagged_to_double` → 0.0);
   - an object param checks only scalar tags, never the class (no instanceof), and a null into a
     non-nullable object param passes;
   - `declare(strict_types=1)` has no runtime effect (coercive everywhere);
   - `unboxCellArg` derefs twice when the caller already derefed (`__manticore_deref` × 2).

## Design

One descriptor per (target kind, message context), one runtime entry per target kind, and a
generic `__mc_co` that switches on the kind for the pack binder. The emitter replaces each inline
coercion by one call that passes the descriptor; the error path, the conversion and the
ownership bookkeeping move into the routine.

### Descriptor (`MemoryAbi::COERCE_DESC_*`)

```
cdesc  (32 B, constant)
  +0   u8   kind      INT, FLOAT, STRING, BOOL, PTR (array / object / closure / union), ENUM,
                      CALLABLE, RECV (receiver guard)
  +1   u8   flags     NULLABLE, STRICT, OWN_STR (caller frees a rendered string),
                      RENDER_SCALAR, KEEP_NULL, PROP_MSG (property wording), CHECK_CLASS
  +2   u16  conform   array element-hint code for `__mir_array_conform`, 0 = none
  +4   u32  given_set which "given" names this site may report (bit per type), for byte-identical
                      messages during migration (see below)
  +8   ptr  head      message head: today's text, e.g. "Argument must be of type int, ",
                      "Cannot assign ", "Call to a member function m() on ",
                      "f(): Argument #1 ($x) must be of type C, "
  +16  ptr  tail      message tail: " given", " to property C::$p of type int", ""
  +24  ptr  class     CHECK_CLASS: pointer to the class's name string (resolved to a class-id set
                      at first use, cached in the routine's own table), else null
```

All offsets come from `MemoryAbi`; the emitter writes the global, the routine reads it.

**Naming and linkage.** A descriptor is `linkonce_odr constant`, named by a hash of its bytes
(`@__mc_cd.<hash>`, as string literals already are). Its content is its name, so coalescing across
split parts and modules is exact, and the `linkonce_odr` invariant (never specialize from
module-local information) holds by construction. It holds no class id (ids are per program and
would make every prelude / shared-generics part a cache miss), only the class NAME, which is the
same text in every program.

### Routines

Emitted IR in `src/Compile/Runtime/` beside the existing cell helpers, one text for every module:

| entry | replaces | fast path (inline at -O2 via a 3–4 instruction test the emitter plants) | slow path (`noinline`, cold) |
|---|---|---|---|
| `i64 @__mc_co_int(i64 v, ptr d)` | `unbox:int` + its raise | tag = int → sign-extended payload | deref; null / array / non-numeric string → TypeError from `d`; bool → 0/1; numeric string → `__mir_str_to_int` |
| `ptr @__mc_co_str(i64 v, ptr d)` | `caststr`, `unbox:string` | tag = string → payload (+ retain if OWN_STR) | object → `__toString` (or its Error), scalar → render, null → KEEP_NULL ? null : TypeError |
| `double @__mc_co_float(i64 v, ptr d)` | `unbox:float` | tag = float / int | as `tagged_to_double` today (gaps stay, flagged for the semantic step) |
| `i64 @__mc_co_ptr(i64 v, ptr d)` | `unbox:array/obj/closure/union`, object `arg` guard | tag ∈ allowed → masked payload | scalar tag → TypeError from `d`; CHECK_CLASS → class test; conform → `__mir_array_conform` |
| `i64 @__mc_co_enum(i64 v, ptr d)` | `unbox:objE` | load ordinal @+16 | — |
| `void @__mc_recv_check(i64 v, ptr d)` | receiver `objguard` | tag = object or untagged | `Error` from `d` with the given value's name |
| `i64 @__mc_co(i64 v, ptr d)` | — | switch on `d->kind` to the above | used by the pack binder only |

The "given" name is computed in the slow path from the tag (null, array, string, int, float,
`true` / `false`, class name for an object), which is what php's `zend_zval_value_name` prints.

**EH.** The entries may throw, so `EhLowerIr` treats them as may-throw and puts its exception
check after the call, as it does after the raise helpers today. The emitter plants the fast-path
test inline and calls the entry only on the slow branch, so the check sits on the cold edge
and the hot path has no `@__mc_exc` load. `bool`, `cell` and the box family stay inline: they are
1–2 instructions.

### Messages stay byte-identical

- Phase 1 copies today's texts into `head` / `tail` unchanged, and `given_set` limits the names
  the routine may report to the arms the inline code had (int: null / array / string; receiver:
  int / string / false / true). A value outside the set keeps today's behaviour (no throw, plain
  unbox), so no program changes output.
- Before the first migration step, one AOT case per kind × given type (`tests/aot/cases/
  coerce_messages.php`, caught `TypeError` / `Error`, `getMessage()` printed) pins the current
  texts where they already equal php. Where they differ (finding 5), a repro under
  `tests/aot/repro/wrong/` and an issue, per AGENTS.md; fixing one is then a change of descriptor
  DATA (the head string), not of code.

### Inline vs call

| site | emitted |
|---|---|
| dispatch helpers (`__mc_rtramp_`, `vdisp`, `dynarm`, `spfill`, static callables, `dynf*`), property accessors, any `optnone` body, `-O0` | the call alone: no fast-path test |
| user code at `-O1`/`-O2` | the fast-path test inline, the call on the slow edge |
| typed → typed | nothing, as today |

Today an int argument at `-O2` is two `alwaysinline` fast paths (`cell_int_bad`, `cell_to_int_arg`)
plus an inline cold block; the plan has one fast test plus a call, so the hot path is equal or
shorter. The gate, per step:

- `bench/cases/typed_coerce.php` (added with this doc): typed call boundaries fed a cell —
  int (int value, numeric string), string (string value, rendered int), float, object, a method on
  a cell receiver, an int property store, an int return — plus a typed→typed control. Baseline at
  9c6845f1, `-O2`, macOS arm64, 20 M iterations, `bash bench/run.sh -k typed_coerce` best of 3:
  typed int 15 ms, cell→int 53, numeric string 271, cell→string 109, int→string 180, cell→float
  44, cell→object 62, cell receiver 79, int property 55, int return 57 (php total 20.4 s vs 0.78 s).
- `bench/cases/dyn_dispatch.php` (the table-dispatch gate, baseline in that doc).
- symfony-app `/` throughput, `-O2`, one worker, `wrk -t2 -c32 -d20s`, median of 3
  (`tools/prof/serve.sh` without `sample`); laravel `/` as a second witness.
- Target: every row and both request rates within 2 % of the base.

### strict_types

`STRICT` is a descriptor bit set from the CALLER's file for arguments and the callee's file for
returns (php's rule). Nothing sets it in this plan: the tree has no runtime strict mode today and
turning it on changes behaviour, so it lands with the semantic step, behind difftest. The bit
costs nothing until then, and it is why the descriptor is per site and not per param.

### Invariants

- **One owner per layout**: `COERCE_DESC_*` in `MemoryAbi`; no emitter or helper hard-codes an
  offset.
- **`linkonce_odr`**: routines are fixed text; descriptors are content-addressed. No per-program
  token, counter or class id appears in either, so a prelude body or a shared-generics part
  that coerces emits the same text in every program.
- **Bootstrap**: the routines are emitted IR, not PHP functions, so no PHP body is needed; the
  layout change ships by a plain `bin/build` (the previous generation compiles the new emitter
  with its own runtime, pass 2 rebuilds `lib/*.o`). No stdlib or `src/` code names the new
  symbols. No pin raise.
- **Ownership**: `OWN_STR` makes the string routine return an owned word (fresh render, or a
  retained borrow), replacing the caller-side `select` / retain diamond; the caller keeps today's
  single post-call release. `cellifyMoveBlocked`, the cell-temp drop by tagged word and
  `takeCellStrArgOwn` are unchanged in meaning.

## Migration and estimates

Replacement cost per site: one call with a descriptor operand plus `EhLowerIr`'s exception check,
4–5 instructions, ~220 B of IR (at `-O0` / in dispatch helpers; user code at `-O2` adds the 3–4
instruction fast test). Descriptor data: a few thousand distinct 32 B globals, under 0.2 MB.

| step | change | symfony saves | laravel saves | effort | risk |
|---|---|---:|---:|---|---|
| S1 | receiver guard → `__mc_recv_check` | ~19 MB (20.0 k sites × ~950 B) | ~30 MB (30.6 k × ~990 B) | S | low: one message family, no conversion |
| S2 | `caststr` → `__mc_co_str` with OWN_STR | ~7.6 MB (9.7 k × ~790 B) | ~23.6 MB (30.3 k × ~780 B) | S/M | medium: ownership of the rendered string |
| S3 | int coercion (args, returns, property / element stores) → `__mc_co_int` | ~10.5 MB (13.6 k × ~770 B) | ~23.6 MB (30.7 k × ~770 B) | S/M | low-medium: message wording differs per caller (arg vs property), carried in `head` / `tail` |
| S4 | object argument guard + `unbox:obj/array` with conform → `__mc_co_ptr` | ~1 MB | ~3 MB | S | low |
| S5 | `unbox:string` → `__mc_co_str` (no render) | ~2 MB (18.7 k × ~110 B) | ~7 MB (60 k × ~110 B) | S | low |
| S6 | pack binder `__mc_bind_args` on `__mc_co` for rtramp / spfill / dynf* (table-dispatch C1/C3) | re-census after S3 | re-census after S3 | L | medium: adapter cost killed C4 once |
| S7 | semantics: php heads for int / string / float, float TypeError, class check, null into non-nullable object, strict_types | ~0 | ~0 | M each | behaviour change, difftest per item |
| | **S1–S5** | **~40 MB (6.7 % of defines)** | **~87 MB (5.3 %)** | | |

Ranking by (IR saved) / (effort × risk): **S1 → S3 → S2 → S5 → S4**, then re-census, then S6 only
if the remaining per-parameter decode in the trampolines is worth more than its adapters, and S7
as its own track (it is correctness, not size).

- S1 first: largest, smallest surface, and it is most of `__mir_dynarm_`'s coercion.
- S3 before S2: S3 introduces the descriptor and the given-name slow path; S2 adds ownership on
  top.
- S6 is ranked last on evidence: table-dispatch step 4 lost its IR win to adapters. After S1–S5 a
  trampoline parameter is already one call; the binder can only remove the decode
  (`__mir_array_get_int` + `__mir_elem_decode` + deref) and must not add a per-callee adapter.

**Compile time.** The S1–S5 bytes are ~6 % of the `define` text in both apps. clang `-O0` time
tracks IR volume (table-dispatch measured 11.7 s for symfony), so expect ~0.6–0.8 s on symfony
and proportionally on laravel, plus the emitter's own string building for 30–90 k fewer cold
blocks. At `-O2` the win is larger than the text share: each removed site was a cold block with a
`select` chain and 3–4 string constants that the optimizer kept per site; the `-O2` binary should
shrink by a similar fraction. Measure with `--timings` per step.

## Open questions

1. `given_set` keeps today's "no throw" for a type outside the old arms (e.g. a float into an int
   param through a cell, which today takes the plain `__manticore_unbox_int` with no TypeError). Keep that bug-compatible
   through S1–S5 and fix in S7, or fix as the routine lands?
2. php's int message carries `called in F on line L`. The site's file / line is per site, not per
   (kind, message), so it would make descriptors per site (~30 k more globals in laravel) or need
   a second operand. Is the backtrace site the call already records enough to build it in the
   slow path?
3. `CHECK_CLASS` resolves a class name to a class-id set at first use (cached). Acceptable, or
   should the class set be a link-time constant the program's class table exports?
4. S7's float / object / strict_types fixes change behaviour for programs that rely on today's
   leniency (`docs/differences.md` #402). One PR per item with difftest, or one batch?
