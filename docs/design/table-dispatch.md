# Table-driven dynamic dispatch

Status: steps 1–3 of the ranking implemented, step 4 built and parked (phase B, see the notes at the end); the rest is design.

## Problem

Every dynamic member access the emitter cannot resolve statically is lowered to
a generated helper whose body is a switch over the program's classes (or names)
with one ARM per candidate: call this method with these coerced arguments, read
this slot at this offset and box it, write it with this type check, fall back to
`__get` / `__call`. Arms are code, and their number is (live classes × names),
so they grow with the program, not with the call site. A table is data: one row
per (class, member), read by ONE shared routine.

## Census

`bin/manticore build -O0 --keep-ir`, the whole-module `bin/app.dbg.ll` (the
`p*.ll` parts are its split, not extra), `define` bodies summed by symbol family
(base `perf/table-dispatch` @ 14f297de, stacked on PR #707). Arm counts: `cases`
= `switch` entries, `calls` = direct `call @manticore_*` lines (one per arm that
calls a body), `labels` = `*.case` blocks.

| family | what an arm does | symfony MB | fns | laravel MB | fns | arms (laravel) |
|---|---|---:|---:|---:|---:|---|
| `__mc_rtramp_` | per method: arity guard + inline `ArgumentCountError`; per legal arity: decode pack, coerce, fill defaults, call | 61.7 | 7 734 | 235.1 | 33 698 | 128 746 calls |
| `__mir_vdisp_` | per callee group: re-coerce the site's words, call, box the return | 27.0 | 3 389 | 123.4 | 9 775 | 138 794 labels, 148 570 calls |
| `__mc_prop_write_` | per holder: readonly guard, coerce + type-check, drop old, store; set hook; `__set`; bag | 21.2 | 5 313 | 63.5 | 10 717 | 64 852 cases |
| `__mir_dynarm_` | per (receiver type, name, fixed argc): a full typed method call (with its own vdisp) | 29.8 | 7 465 | 62.1 | 29 635 | 1 per fn |
| `__mc_static_callable` | per (class, static method): call fn; name trees per class | 8.4 | 1 166 | 57.6 | 10 260 | 40 794 calls |
| `__mc_cdoc_` | no arms: builds the docblock/line metadata array at run time | 13.8 | 908 | 57.3 | 3 480 | — |
| `__mc_prop_read_` | per holder: init / lazy check, slot load, box; enum; get hook; `__get` | 12.0 | 1 938 | 44.2 | 3 999 | 88 677 cases |
| `__mir_spfill_` | per (callee, first index, param types): decode a pack into param words | 17.2 | 6 576 | 42.1 | 18 938 | 1 per fn |
| `__mir_magiconly_` | no arms per class: two class-id membership sets per method NAME | 24.1 | 2 406 | 29.3 | 2 932 | — |
| `__mir_ovars_` + `__mir_object_vars` | per class `get_object_vars` builder + a class-id switch | 7.6 | 2 038 | 26.8 | 6 052 | 6 134 cases |
| `__mc_pset_` / `__mc_pget_` / `__mc_prraw_` | per declared property: reflection accessor pair | 16.4 | 4 114 | 40.6 | 15 250 | — |
| `__mir_dynfs_` / `__mir_dynft_` | per callable-string callee: arity / spread thunk (already behind a name table) | 6.5 | 2 420 | 19.5 | 6 813 | 1 per fn |
| `__mir_newdyn_` | chains for classes outside `.newdyn.rows` | 5.2 | 4 | 7.8 | 6 | 1 752 calls |
| `__mc_consts_`, `__mc_fntramp_`, `__mc_prop_deny` | constants factory, function invoke tramp, visibility deny probe | 5.1 | 773 | 11.6 | 1 145 | — |

Module totals: symfony 711 MB IR (649 MB in `define`s, of which 338 MB user
bodies); laravel 2 048 MB (1 823 MB in `define`s, 856 MB user bodies). The
families above are **256 MB of symfony (39 % of all defines)** and **821 MB of
laravel (45 %)** — more than the user code in laravel.

What the biggest bodies are made of (symfony, sampled):

- `__mc_rtramp_` (7 734 fns, median 229 IR lines, max 4 674): per method one
  arity guard that builds `ArgumentCountError` INLINE (4 876 copies, ~75 lines
  each ≈ 20 % of the family), then per legal arity R..T one arm that decodes
  `$a[i]` (`__mir_array_get_int` + `__mir_elem_decode` + `__manticore_deref`,
  20 392 decodes), coerces to the param repr, fills that arm's defaults and calls
  the body (32 472 calls in total ≈ 4.2 per trampoline).
- `__mir_magiconly_` (2 406 fns, 264–341 lines each, near-identical): the
  lowering of `is_object($v) && method_exists($v,'__call') && !method_exists($v,'m')`,
  i.e. two class-id membership tests per method NAME.
- `__mc_prop_write_` (5 313 fns, 21 023 switch entries): one function per
  (name × flavor): `__ro`, `__in_<scope>`, `__drop`, `b`, `h`, `u` are separate
  bodies over the same holder set.
- `__mc_cdoc_` (908 fns, ~15 KB each): builds the class's docblock / line array
  at run time, element by element, from code.

## Requirements (binding)

1. **Statically resolved calls stay direct.** A site whose target the compiler
   knows (monomorphic receiver, exact class, `final`, private, static call on a
   named class) keeps its direct call — no table, no indirect call. Tables serve
   only sites the compiler cannot resolve: `$o->$m()`, `new $cls`, `$cls::m()` /
   `$c::$m()`, `call_user_func` and callable strings/arrays, dynamic
   property/constant/global access, reflection — and the polymorphic static-name
   sites on erased/union receivers (see open question 1).
2. **Tables are fully resolved at compile time.** Inheritance, overrides,
   visibility, LSB clones, the `__call`/`__get` fallback, the declared types of
   params and slots are all known to the emitter; every per-class table is
   FLATTENED (own + inherited rows, override wins), and each row names its final
   target. The run time does a lookup and a call, never a parent walk, a
   visibility derivation from scratch, or a type resolution.
3. **Inline cache per site** in front of every table lookup (below).
4. **Performance gate**: no regression vs the current arms on monomorphic sites
   (below).

Invariants inherited from the tree:

- Every offset, row size and kind code comes from `MemoryAbi` (new fields are
  APPENDED to the class descriptor; the descriptor is the one place a class's
  tables hang).
- Tables and their routines are `linkonce_odr` pure functions of (class, member)
  — never of a module-local fact; per-class tables are keyed by the stable
  class id, exactly like `@__mir_cd_<id>` today.
- Prelude bodies emit identical text in every program: the IC slot of a site in
  a prelude body is named by (function symbol, ordinal of the site in that
  body), never by a module counter (`docs/design/shared-generics.md`).

## Common machinery

### Class key

`obj@0` is the descriptor pointer (`OBJECT_DESCRIPTOR_OFFSET`), `desc@0` the
class id. Descriptors are `linkonce_odr`, one per class after the link, so the
descriptor POINTER is a process-wide class identity — the IC compares it (one
load, one compare). Sorted class-keyed tables use the class id (stable FQN hash,
`DESCRIPTOR_CLASS_ID_OFFSET`), since rows are emitted per module.

### Two table shapes

**(A) per-class member table, hung off the descriptor** — name → row. Serves
every site whose NAME is the variable part (`$o->$m()`, `$o->$p`, reflection,
`get_object_vars`). This generalises the existing dynamic-method table
(`DESCRIPTOR_DYN_METHODS_OFFSET`, rows `{name, tramp, decl, vis}` sorted by
lower-cased name, `__mc_dyn_method_row` binary search with `__mir_name_icmp`).
New: a property table at an appended descriptor offset, and a hash column in
both.

**(B) per-shape class table** — class id → target, for a fixed NAME at a
polymorphic site (`__mir_vdisp_`): `[{i64 cid, ptr fn}]` sorted by cid plus a
header `{i64 n, ptr fallback}`. It replaces the inline `cid.*` binary-search
tree that `emitAdaptiveClassIdBranch` writes into every dispatcher — same search,
as data.

### Name lookup

Rows carry `i64 hash` of the name and are sorted by (hash, name). The probe is a
binary search on the 64-bit hash, then one byte compare to confirm — no
`strcmp` per probe step as today.

- A literal name has its FNV-1a hash baked at `data − 32`
  (`STRING_HASH_OFFSET`); a site with a literal name also passes the hash as an
  immediate, so the probe never touches the string until the confirm.
- A run-time name (`$m` built by concatenation) is hashed once; a headered
  string caches it at `−32` (0 = not computed), so a name reused across calls
  pays once.
- Methods are case-insensitive: method rows are keyed by the hash of the
  LOWER-CASED name. A literal site passes the lower-case hash computed at
  compile time. A run-time name tries the exact-case hash first against an
  exact-spelling column (the declared spelling is what real code passes), and
  only on a miss computes the lower-case hash (no allocation, a byte loop).
  Properties are case-sensitive: one column.

### Uniform thunk ABI

Manticore bodies already use a word ABI: every parameter and the result is an
`i64` (a float travels as its bits, an object/string/array as its pointer). So
"a uniform signature" is simply arity: `i64 (i64 recv, i64 a0 … a(n-1))`. Two
calling forms exist and both stay:

- **words**: the site already holds its arguments as `i64`s — `vdisp`, dynf
  thunks; the target is called with the site's words.
- **pack**: `i64 (i64 recv, ptr args)`, the reflection trampoline ABI
  (`__mc_rtramp_`, `__mc_dyn_method_dispatch`, `new $cls`, `call_user_func`).

### Param descriptor (the shared coercion primitive)

Most of the per-arm IR in `rtramp`, `dynarm`, `spfill`, `dynft/dynfs` is the
same thing: decode a cell from a pack, coerce it to a declared param repr, fill
a missing optional from its default. A **param descriptor** makes that data:

```
pdesc  = { i32 required, i32 total, i32 flags (variadic, returns-void, box-ret-kind),
           i32 pad, [total x pslot] }
pslot  = { i16 kind, i16 flags (nullable, has-default, const-default, by-ref),
           i32 _, i64 class-id-or-0, i64 default (a pre-boxed immortal word, or a ptr
           to the existing `__mc_pdef_` factory) }
```

`kind` is the target repr (int, float, bool, string, array<cell>, array<typed
elem>, object-of-class, cell, int|float, …) and comes from the same table the
emitter's own `unboxCellArg` / `taggedParams` switch uses. ONE shared routine per
form: `__mc_bind_args(ptr pack, i64 first, ptr pdesc, ptr out_words) -> i1`
(throws `ArgumentCountError` / `TypeError` with names read from the cold part
of the row) and `__mc_invoke_n(ptr fn, i64 recv, ptr pack, ptr pdesc)` per arity
0..8 (above 8: pack form through the variadic body). A param whose coercion the
kind language cannot express (rare unions with class members, by-ref, a default
that must be evaluated in a frame) keeps a per-callee thunk — the thunk is then
the row's target, the routine is bypassed.

## Inline cache

Every table-served site gets a cache in front of the lookup.

**Slot.** A site-private global, `internal` (or `linkonce_odr` when the
enclosing body is `linkonce_odr`, so coalesced copies share one), zero-init,
named `@__mc_ic.<function symbol>.<ordinal>` — a function of the body text only,
which keeps prelude bodies byte-identical across programs. Size: 1 word
(monomorphic) or 4 words (polymorphic), chosen per site by the emitter (a
static-name site on a 2-candidate union gets 2).

**Content.** Each word is a pointer to an immutable, already-resolved table
ROW, and every row carries its owner's descriptor pointer (and its name pointer
for shape A). So an entry is ONE machine word, written with one store:

```
row = ic[0]
if (row != null && row->desc == obj->desc            ; class
    && (static-name site || row->name == name))      ; name, pointer identity
    -> call row->target                              ; hit: 2 loads, 2 compares
for i in 1..k-1: same test on ic[i]                  ; polymorphic
row = table lookup (hash binary search)              ; miss
ic[next] = row                                        ; round-robin, see below
```

A dynamic-name site compares the name by pointer first (literal / interned names
are the same pointer every time); a pointer miss falls back to `hash` equality +
byte compare before declaring the entry stale. After k distinct misses at a
k-entry site, a megamorphic bit (low bit of `ic[0]`) stops further writes, so a
thrashing site costs one test, not a store per call.

**Thread / fiber safety.** PHP code runs on one OS thread: the async engine's
fibers are green threads switched only at suspension points, and an IC update
is a load and a store with no suspension in between. The blocking pool threads
run C work, not PHP bodies. Even so, an entry is one aligned word that points at
immutable data, so a reader never sees a torn {class, target} pair. `fork`
copies the cache with the process, which is correct (same binary, same tables).

**Invalidation.** None. The program is closed: no class is loaded, no method
added, no table changed after link; a row's verdict is a pure function of
(class, name, site). The one scope-dependent verdict — visibility of a
private/protected member — is decided per SITE scope, which is fixed, except in
a closure that `Closure::bind` can re-scope: such sites key the IC on the scope
too (or skip caching non-public rows). A visibility failure never enters the
cache: the error / `__call` path is not a hit.

## Families

Laravel normal-build magnitudes are given where they differ in kind; all
numbers are in the census.

### 1. `__mir_magiconly_` — erased call that only `__call` may answer

Arm: none per class; the body is two class-id membership sets inlined from
`method_exists`. Table: shape A already holds the answer — the per-class method
table has a row for every declared method (null tramp for non-invokable ones)
and a `__call` slot. Shared routine `__mc_magic_only(i64 v, ptr lname, i64 lhash)`
in `RuntimeLibrary`: not an object → 0; `desc->dyn == null` (prelude, struct) →
the compile-time answer for tableless classes (a prelude class with `__call` is
listed explicitly; today there is none); else `magic != null && !has(name)`. IC:
1 word (desc → verdict). IR: the family goes to zero, one call per site.
Effort S, risk low. Prereq: none.

### 2. `__mc_rtramp_` (+ `__mc_fntramp_`, `__mc_rmagic_`) — invoke trampolines

These are already table targets (rmeta rows, `.dynm.rows.*`, `.newdyn.rows`);
the cost is the body. Steps:

- **2a — arity once, not per trampoline.** The row already knows
  `required|total<<8|variadic<<16` (`RMETA_ROW_ARITY_OFFSET`); append the same
  packed word to the dyn-method row (`DYN_METHOD_ROW_*`, row size grows: one
  `MemoryAbi` change, no pin raise per AGENTS.md) and let the shared callers
  (`__mc_dyn_method_dispatch`, `__mc_dyn_method_try_call`, `__mc_refl_invoke*`,
  the newdyn lookup) throw `ArgumentCountError` from one cold routine.
  TrampolineSynth then emits arms without the guard. ≈ −20 % of the family.
- **2b — descriptor thunks.** For a method whose params are all expressible in
  the `pslot` kind language and whose defaults are constants or existing
  `__mc_pdef_` factories, the row's target becomes `{body fn, pdesc}` and the
  call goes through `__mc_invoke_n`; the per-method trampoline is not
  synthesised at all. An instance row points at the RESOLVED body for that class
  (flattened table, requirement 2), so the trampoline's internal virtual
  dispatch disappears too; a static row points at the class's LSB clone. A
  constructor row is `{template, ctor body, pdesc}` and goes through
  `__mc_new_invoke` (allocate from the instance template, init, bind, call).
  Remaining trampolines: by-ref params, inexpressible coercions, defaults that
  read the frame. Expected ≥ 80 % of the family.

Effort L, risk medium (coercion and default semantics must match direct calls
byte for byte; the AOT suite's reflection, named-args, defaults and
strict-types cases are the guard).

### 3. `__mir_dynarm_` — `$o->$m(...$args)` on a typed receiver

Arm: per (receiver type, method name, fixed count, return type) a function that
re-emits a full typed method call (which itself contains a vdisp). Table: the
same shape-A method row as family 2 with the pack form; the spread is the pack.
After 2b the arm set shrinks to by-ref-param methods, which must stay inline
(the dispatcher writes back to the caller's variable). Effort M after 2b.

### 4. `__mir_vdisp_` — static name, polymorphic receiver

Arm: per distinct callee group: re-coerce the site's words to the callee's
param reprs (`vdArmArgs`), call, box the result by the callee's declared return
when the merge is a cell. Table: shape B, `{n, fallback, [{cid, fn}]}`. `fn` is
the callee body ITSELF when the callee's word ABI equals the site's (no
re-coercion, no boxing — the common override case); otherwise an adapter thunk
`i64 (i64 recv, i64 a…)` shared per (callee, site coercion key), emitted once.
The site does: IC test → hit: indirect call; miss: `__mc_cid_find(cid, tab)`,
store, indirect call. The class sets and adapters are all decided at compile
time (requirement 2); the run time only searches. This is NOT a statically
resolved site (≥ 2 candidates), so requirement 1 allows it — see open question 1.
Effort M, risk low-medium (indirect call where there was a direct one; the IC
makes the monomorphic case faster than today's inline binary search).

### 5. `__mc_prop_read_` / `__mc_prop_write_` — property by name on an erased or union receiver

Arm (read): per holder class: optional init / lazy-ghost check, load the slot at
its offset, box by its declared repr; enum `name`/`value`; hook getter; `__get`.
Arm (write): readonly re-init guard, coerce + type-check the cell against the
declared type, drop the old value, store; set hook; `__set`; bag default.

Table: shape A property table on the descriptor, flattened, one row per
accessible property of the class:

```
prow = { i64 hash, ptr name, ptr desc, i32 offset, u16 kind, u16 flags
         (readonly, init-checked, lazy, static, hooked-get, hooked-set, visibility),
         i64 type-class-id, ptr cold }        ; cold = {decl class name, type string, get/set hook fn}
```

Shared routines `__mc_prop_get(obj, name, hash, mode)` and
`__mc_prop_set(obj, name, hash, val, mode)` where `mode` carries what are today
separate function flavors (`fresh`, `chk`, `__ro`, `drop`, `b`, `h`, `u`). A
miss falls to the bag-by-name / magic predicate already shared
(`magicDispatchCall`). The per-name helpers disappear; a static-name site calls
the routine with literal name + baked hash and an IC (desc → row), a dynamic
name site the same routine (the `dynp` name table goes away too).
`holdersWithin` narrowing becomes unnecessary (it existed to shrink arms).
Effort L (the store coercion), risk medium. Families 7 and 10 reuse this table.

### 6. `__mir_spfill_` — spread pack into a known callee

Arm: per (callee, first index, param types) a function that decodes the pack
into param words. Table: the callee's `pdesc` (family 2) and the shared
`__mc_bind_args`. Stays specific only where a by-ref param binds a frame slot
(already excluded today). Effort S after the pdesc exists, risk low.

### 7. `__mc_pget_` / `__mc_pset_` / `__mc_prraw_` — reflection accessors

Arm: per declared property, a getter/setter pair lowered from PHP source. Table:
the family-5 property row (`ReflectionProperty` keeps a row pointer); reflection
calls `__mc_prop_get/set` with a mode that bypasses visibility and applies the
readonly-once rule. Effort S after 5.

### 8. `__mc_cdoc_` (+ `__mc_consts_`, `__mc_cfile_`, `__mc_attr_*`) — metadata factories

Not dispatch: run-time construction of constant metadata, because there is no
emit-time constant-cell builder. Table: emit the rows as constant structs of
immortal strings and ints (`{member, doc, start, end}`) and one generic builder
that turns rows into the array on first use (and caches it on the rmeta).
Effort S, risk low — the cheapest megabytes on the list.

### 9. `__mc_static_callable` — `$c::$m()` / `['C','m']()`

Arm: one function per (class, static method) plus a name tree per class. Table:
class name → descriptor through the existing name registry, then the class's
shape-A method table filtered to static rows (a flag), target per family 2.
Effort S after 2b.

### 10. `__mir_ovars_` — `get_object_vars` / `(array)` on an erased object

Arm: per class a builder function, plus a class-id switch over all of them.
Cheap step: hang the builder off the descriptor like `DESCRIPTOR_PROPS_FN_OFFSET`
(the switch goes). Full step: one generic walk over the family-5 property table
(visibility vs the site scope, private-name mangling for `(array)`). Effort S/M.

### 11. Already tables, or small

`__mir_dynft_` / `__mir_dynfs_` (callable strings) and `.newdyn.rows` are
already name tables with per-callee thunks; after 2b their thunks become
`{fn, pdesc}` rows. `__mir_newdyn_` keeps chains for tableless classes.
`__mir_clone`, `__mir_cmpview_`, `__mc_dsf_` are small; not worth a design now.

## Expected IR delta and ranking

Ranked by (IR saved) / (effort × risk); symfony and laravel deltas assume the
residual arms listed per family.

| # | family | saves symfony | saves laravel | effort | risk | needs |
|---|---|---:|---:|---|---|---|
| 1 | magiconly → `__mc_magic_only` | 24 MB | 29 MB | S | low | — |
| 8 | cdoc / consts / cfile / attr as constant rows | 15 | 60 | S | low | — |
| 2a | arity check once in the shared callers | 13 | 48 | S | low | row field |
| 4 | vdisp → shape-B table + IC | 23 | 105 | M | low-med | — |
| 2b | rtramp → `{fn, pdesc}` rows, `__mc_invoke_n` | 40 | 150 | L | medium | pdesc |
| 6 | spfill → `__mc_bind_args` | 15 | 38 | S | low | 2b |
| 3 | dynarm → method rows, pack form | 24 | 50 | M | medium | 2b |
| 9 | static_callable → name registry + static rows | 7 | 52 | S | low | 2b |
| 11 | dynft / dynfs thunks → pdesc rows | 5 | 17 | S | low | 2b |
| 5 | prop read / write → shape-A property table + IC | 28 | 92 | L | medium | — |
| 7 | pget / pset → property rows | 15 | 38 | S | low | 5 |
| 10 | ovars → generic walk over property rows | 7 | 25 | S/M | low | 5 |
|   | **total** | **~216 MB (30 % of the module)** | **~704 MB (34 %)** | | | |

Savings assume the residual arms each section names (by-ref params,
inexpressible coercions, tableless prelude classes) stay; the data that replaces
the code is one 16–48 byte row per (class, member), a few MB at most.

Order: **1 → 8 → 2a → 4 → (pdesc) 2b → 6 → 3 → 9 → 11 → 5 → 7 → 10**. 1, 8 and 2a are
independent, small and safe and buy a fifth of the total; 2b introduces the
param descriptor that 6, 3, 9 and 11 reuse; 5 is the largest semantic surface
and goes last, then 7 and 10 fall out of its table.

## Performance gate

- **Microbench** `bench/cases/dyn_dispatch.php` (committed with this doc):
  method by name (mono, 4-way poly), callable array, `call_user_func`,
  `$cls::$m()`, dynamic prop read (mono, poly) and write, `new $cls`. Run with
  `bash bench/run.sh -k dyn_dispatch` (best-of-3, output parity vs php); per-row
  timings go to stderr. Member names go through `opaque()` so no site folds to
  a static call. Baseline at 14f297de, `-O2`, macOS arm64, 2 M iterations
  (`new` 0.5 M), best of 3 (php 8.5 in brackets): method-name mono 40 ms (77),
  poly 41 (100), callable-array mono 75 (86), `call_user_func` poly 136 (135),
  `$c::$f()` mono 257 (60), prop-read mono 7 (23), poly 5 (37), prop-write mono
  5 (26), `new $cls` poly 12 (36). `$c::$f()` is 4× slower than php today —
  the `__mc_static_callable` path lower-cases both names and walks two trees
  per call; family 9 with an IC is expected to fix it, not just hold it.
- **Request throughput**: symfony-app built at the default `-O2`, served with
  one worker, `wrk -t2 -c32 -d20s` on `/` (the harness in
  `tools/prof/serve.sh` without `sample`), median of 3 runs, before/after each
  family lands. Laravel `/` the same, as a second witness.
- **Target**: monomorphic rows of the microbench and both request rates within
  noise (≤ 2 %) of the base; polymorphic rows may not regress more than 5 %.
  An IC hit is 2 loads + 2 compares + an indirect call, against today's inline
  binary search over class ids + direct call: expected neutral-to-faster. The
  risk is the MISS path (hash probe, row confirm) on megamorphic sites, which is
  the row the poly benches watch.
- Build-side measure per family: `-O0 --keep-ir` census as above, plus clang
  time of the build (`--timings`), since IR volume is what the work is for.

## Open questions

1. Requirement 1 lists the syntactically dynamic sites. Are the polymorphic
   STATIC-NAME sites — `vdisp` (method call on a union / erased / interface
   receiver), the per-name property readers/writers, `magiconly` — in scope?
   They are not statically resolved (≥ 2 candidates) and carry ~60 MB of
   symfony IR; this design assumes yes, with an IC per site.
2. Defaults in `pdesc`: a default that is a constant expression of the
   declaring class (`self::X`, enum case, `[]`) can be a pre-boxed immortal
   word; anything else uses the existing `__mc_pdef_` factory. Is calling the
   factory acceptable on the hot path, or should such methods keep their
   trampoline?
3. Per-class property table size: every class gets rows for every inherited
   property (flattened). For Laravel models that is the largest data term; the
   alternative (rows for own properties + parent pointer) violates requirement 2.
   Accept the data?
4. IC slots in `linkonce_odr` prelude bodies: one shared cache per coalesced body
   is correct but pools its statistics across all callers of that body. Fine for
   a cache; flagging because it is the one place a per-site global is not
   per-site.
5. Row-size change of `DYN_METHOD_ROW_*` and new descriptor fields: a memory-ABI
   change, shipped by a plain `bin/build` per AGENTS.md — confirm no external
   consumer reads the dyn-method row (none found in `src/`, `prelude/`).

## Phase B notes (steps 1–4 as built)

Where the implementation departs from the plan above, and why:

- **Magic-only (1).** Built as planned (MAGIC_ONLY table, generic find, per-site cache), with
  two residuals. A name no class answers only through `__call` is a constant 0, no call at all.
  A name at most four classes answer that way keeps a tiny pure per-name class-id test: a cache
  write makes the verdict impure, so an inlined copy can no longer be hoisted out of a loop, and
  for a handful of classes the test is less code than a call plus a cache global. The verdict
  cache is two words (newest first), so a site alternating between two classes stays a hit.
- **Metadata (2).** Only the docblock/line factory (`__mc_cdoc_`) changed: its value is constant
  data, emitted as one serialized string literal decoded by the existing native decoder
  (`__mc_unser_native`). `__mc_consts_` builds from constant EXPRESSIONS (enum cases, `new`,
  other classes' constants) that are not known before lowering, so it stays code;
  `__mc_cfile_` (≈270 B a class) and the attribute factories were not worth a change.
- **Trampoline arity (3).** Not moved into the callers. The guard stays in each trampoline as a
  compare and one call to a shared `__mc_tramp_too_few`, which builds and throws the
  ArgumentCountError: that is where the ~75 lines per trampoline were, and it needs no row-layout
  change and no change to the five callers (reflection invoke, dyn-method dispatch/try-call,
  `new $cls`).
- **Virtual dispatch (4) — parked.** Built and green (branch `perf/table-dispatch-vcall`): sites
  of a shape with five or more instantiable classes call a generic `__mc_vcall_<argc>` with a
  per-site four-way cache (plus a megamorphic switch) and the shape's open-addressed class-id
  table; arms the callee takes unchanged point at the callee, the rest at a content-addressed
  adapter. It does not pay: on symfony-app `__mir_vdisp_` fell 27.0 → 13.4 MB, but 8 959
  adapters (6.8 MB), 1 103 tables (4.2 MB) and 30 646 cache globals (4.5 MB) took it back
  (defines 585.9 → 579.6 MB, module +0.6 MiB net with the data), clang -O0 rose 11.7 → 15.0 s,
  and the -O2 binary grew 144.0 → 147.3 MB. Most arms need per-arm re-coercion or return
  boxing, so few rows can point at the callee itself, and the old thunks were `optnone`, which
  made them cheap to compile. The runtime side did hold: a monomorphic site on an 8-class
  union ran 29.0 → 16.3 ms (bench `vcall8 mono`), 2- and 8-way sites unchanged, 4-class
  shapes keep their thunk. Worth revisiting only together with a cheaper adapter story (arms
  that box/re-coerce through a shared descriptor routine, as 2b plans for trampolines).

## Phase C notes

- **C2, trampolines (built).** A trampoline makes one call and takes omitted arguments from the
  `__mc_pdef_*` default factories reflection already synthesizes, instead of an arm per arity.
  symfony `__mc_rtramp_` 37.7 → 31.6 MB, laravel 135.0 → 117.2 MB. Arms remain where an
  explicit default is observable or unavailable: a class whose source counts its arguments
  (`func_get_args` and friends — detected per source file, since bodies are not lowered yet), a
  by-reference parameter, no factory, no reflection.
- **C1, the descriptor + runtime binder (not built).** What remains of a trampoline after C2 is
  per-parameter coercion (an int check with its TypeError, a string render with its own
  retain/release, enum ordinals, float unboxing, payload masks) plus fixed frame work (receiver
  check, arena, backtrace site, exception checks). A runtime binder would have to re-implement
  `unboxCellToType` and its error texts as a second, hand-written authority beside the emitter's —
  the two-sources-of-truth arrangement the tree avoids elsewhere (`cellTagIr`'s note) — and the
  per-callee adapter would still need typed native arguments the MIR can only express by
  re-coercing. It is worth building only together with making `unboxCellToType` itself table-
  driven (one routine both the emitter and the binder call), which is a change to every typed
  call boundary, not to dispatch.
- **C3 (spread fill, dynarm, static callables, callable-string thunks) and C4 (vdisp) not
  attempted:** each was to ride the C1 binder; without it their arms carry the same per-argument
  coercion C2 could not remove, and C4's adapters are exactly that coercion.
