# Type System v2 — deeper static typing

Goal: close the inference gaps that force the runtime cell/mixed fallback,
catch more at compile time (toward "stricter than Zend, which only checks at
runtime"), and emit more specialised code (fewer runtime tag dispatches).

## Where we are (v1)

`Compile\Mir\Type` is a single-kind lattice: `int float string bool string
array(vec|assoc) obj<Class> cell unknown void closure null generator`. Merges
(`unionWith`) collapse anything heterogeneous to `unknown`; the only "union" is
`KIND_CELL` with `atoms[]` (a NaN-tagged runtime value — `cell{int|string}`).

Representation: every value is one i64. A concrete kind picks the codegen path
(int math, ptr deref, typed method dispatch via the class descriptor). `cell`
carries a NaN tag and is unboxed at use. `unknown` flows as a raw i64.

## The gaps (observed)

1. **Polymorphic base-typed field read.** `$base->prop` where subclasses declare
   `prop` with DIFFERENT types (`AST Expr->value`: int / string / obj<Expr>).
   `InferTypes::subclassPropType` returns an ARBITRARY (first) subclass's type.
   Wrong for checking; *works at runtime* only because all reps are i64 and the
   arbitrary class still enables method dispatch.
   - DEAD END (proven): returning `unknown`/`cell` instead BREAKS self-host —
     the arbitrary concrete class is load-bearing for `$x->method()` dispatch;
     drop it and dispatch falls back to a free call `@manticore_<m>` → undefined
     symbol. Representation is fine; *dispatch needs a class*.
2. **No real union.** `int|float`, `Stmt|null`, `A|B` all become `unknown`.
   Nullable types (`?T`) lose the null arm.
3. **Param type erasure.** bare `array $x` → `unknown` (intentional, element
   recovered from use). A typed-array/scalar arg crossing into it loses info.
   Worked around per-case (scanParamElements, scanParamCellSinks).
4. **No flow narrowing** except `instanceof` in `if`. `is_string($x)`,
   `$x->kind === 'StringLiteral'`, `$x === null` don't refine the branch.
5. **Type checking limited to array-ness** (TypeCheck pass) — obj↔scalar and
   scalar↔scalar blocked by 1 and by PHP's non-strict coercion.

## v2 design — phased, each self-host-gated

### Phase 1 — Flow narrowing (SAFE, additive, branch-local)
Refine `localTypes` inside a conditional branch; revert at the merge. Extends
the existing `instanceof` narrowing in `InferTypes::inferIf`:
- `is_int/is_string/.../is_array/is_object($x)` → narrow `$x` in the then-branch.
- `$x === null` / `!== null` → narrow to null / strip null.
- `$x instanceof C` already done.
No lattice or representation change → cannot break codegen (only adds precision).
Highest value-to-risk. Does NOT need unions. START HERE.

### Phase 2 — Union type in the lattice
Add `KIND_UNION` with `atoms[]` (distinct from `cell`: a STATIC union, not yet a
runtime tag). `unionWith` builds a union instead of collapsing to unknown
(bounded: cap arms, dedupe). Critically, a union must DEGRADE GRACEFULLY at every
existing consumer: a `union` it can't handle behaves like today's `unknown`
(raw i64) — so adding the kind is inert until consumers opt in. Self-host gate
after the lattice change alone (no consumer reads it yet).

### Phase 3 — Union-aware codegen
- **Dispatch:** `$x->m()` where `$x: union<obj<A>|obj<B>>` → runtime class_id
  dispatch through the class descriptor (the vtable mechanism already exists for
  virtual calls). This is what unblocks gap 1 WITHOUT losing dispatch.
- **Representation:** a union of same-rep arms (all ptr, or all i64-scalar) needs
  no boxing; a mixed-rep union (int|obj) becomes a `cell`. Decide at lowering.
- `subclassPropType` returns `union<all subclass types>` (not arbitrary first).

### Phase 4 — Nullable + stricter checks
- `?T` = `union<T|null>`; null-safety checks (`$x->p` on a maybe-null → warn).
  - ✅ **ptr-null DONE** (`6ba66b8`): `box_ptr/array/object` of a 0 pointer → a
    NULL cell (a real ptr is never 0). Fixes SIGSEGV on `var_dump` of a null
    `?string`/`?Obj` property + a null element in a cell array → `NULL`.
  - ✅ **echo/concat null DONE** (`07a900e`): a null `?string` (ptr 0) in echo /
    concat / interpolation mapped to a static `@.cstr.empty` → prints `''` (was
    a SIGSEGV deref of 0). echo selects it before printf; `__mir_concat` selects
    it for either operand.
  - ✅ **scalar-nullable `?int`/`?float`/`?bool` DONE** (`f09bc71`): null collides
    with 0/0.0/false on a raw i64, so lower these to `Type::numericCell()` (a
    boxed cell — null gets the NULL tag, arithmetic promotes by tag, `=== null`
    via tag). KIND_CELL is overloaded (SPL cell-array backing props are RAW), so
    property default/store boxing is gated on the NUMERIC flag (`isNumericCell`):
    a scalar-nullable prop defaults to a boxed NULL + box-stores; a general
    mixed/array cell prop stays raw 0 (SPL untouched).
  - ✅ **null `?string` in string builtins DONE** (`1f9e319`): a null `?string`
    rides as ptr 0 (a NULL cell unboxes to payload 0) → strlen/substr/strtoupper/
    strpos/... derefed it → SIGSEGV. `emitPtrArg` now maps a null ptr to the static
    `@.cstr.empty` (PHP null→"" coercion: strlen(null)=0, substr(null)=""). Covers
    both the raw-ptr and cell-arg paths uniformly.
  - ✅ **null in a string-CONCAT context DONE** (`7746b08`): `coerceToStr` had no
    KIND_NULL branch → a null local (`$a=null; "v=".$a`) hit the int path → "0";
    now maps to `@.cstr.empty`.
  - OPEN: (a) `?array` reads as `unknown` (the bare-`array`→unknown erasure —
    var_dump shows int(ptr); `?array` param var_dump → `int(0)`/`int(ptr)`);
    (b) ✅ **SCALAR-only `mixed` property DONE** (`799c1ae`): a non-numeric cell
    prop stored/defaulted RAW → a cell read tag-dispatched the raw bits → array
    branch → deref → crash (`mixed $x=null; var_dump($x)`→array(0){}; `$c->x=5`→
    SIGSEGV). FIX: `emit()` pre-scans every StoreProperty; a prop NAME ever stored
    a non-scalar (array/string/obj/unknown/general cell) value stays RAW (the SPL
    `$__s` backing — rc-managed, boxToCell would REBUILD the array). A cell prop
    whose every store is a non-rc SCALAR (int/float/bool/null/numericCell) becomes
    self-describing: boxed-NULL default + box-store, NO retain (scalars carry no
    rc) → reads dispatch by tag. Name-global classification (sidesteps inheritance/
    class-qual; over-conservative=safe). `cellPropBoxed()` gates both default
    (EmitLlvmObjects:71) + store (:275). ✅ **STRING-valued extended** (`45b28e6`):
    boxable-KIND whitelist (`cellBoxableKind`: int/float/bool/null/string/cell box
    in place); a string store retains the RAW ptr BEFORE boxing (box_ptr). STILL
    OPEN: array/object-valued mixed props — array boxToCell REBUILDS (wrong for a
    co-owned/SPL slot); a boxed TYPED object loses its static class so `$cell->prop`
    can't resolve the offset (emitPropertyAccess cell branch assumes a stdClass bag).
    ✅ **nested cell-array element** (`0cc634a`): emitVec/AssocToCellArrayUnified now
    recursively rebuild a KIND_ARRAY element (was box_int → raw ptr rendered) →
    json_encode/var_dump of nested arrays 1:1 at any depth.

### OBJECT-in-cell (s9, partial — the program-logic gaps fixed, var_dump pending)
- ✅ **instanceof on a cell** (`dcd9376`): `$cell instanceof C` inttoptr'd the
  tagged bits → SIGSEGV. Now tag-guarded (object tag 8 only; non-object cell →
  false without deref) + unbox before the class_id compare. Shared
  `emitClassIdMatch`.
- ✅ **narrowed-object unbox on load** (`a2c851d` param-only → `a0363d8` general):
  `if ($v instanceof C) { $v->prop; $v->m(); }` on a mixed value crashed — the
  slot holds a NaN-boxed cell but the narrowed load is typed obj → `->prop`/dispatch
  inttoptr'd the tag. emitLoadLocal now strips the tag on ANY obj-typed load (the
  48-bit mask is IDENTITY on a real heap ptr < 2^48 → no-op for a genuine obj
  local, unboxes a narrowed cell). Covers params, foreach values, assigned locals.
- ✅ **var_dump of a typed object DONE** (`192a0dc`): synthesized
  `__mir_dump_object` dispatches over the complete class table via instanceof
  (most-derived first) + renders each declared prop through the recursive
  `__mir_var_dump`; bag fallback for dynamic objects. Built post-class-registration
  (line ~286 LowerFromAst), NOT in the prelude (parsed before user classes). Reuses
  the cell-instanceof + narrowed-prop-access enablers. CLARITY not parity: public
  -style keys (no `:private`/`:protected`), fixed `#1` id (single-object matches PHP
  exactly; multi-object ids differ — accepted for debug). Also fixed a LATENT
  inference bug it surfaced: `inferIf` now drops a DIVERGING branch's (return/throw)
  flow-narrowed locals from the merge (`blockDiverges`) — else `if ($v instanceof A)
  {...return;}` left `$v` mistyped non-cell → next instanceof read tagged bits raw →
  SIGSEGV. And `47338cd`: the includeVarDump heuristic now needs a REAL `var_dump(`
  call (was a substring test → always-true for the self-build, which IMPLEMENTS
  var_dump → linked the whole runtime + per-class dump into the compiler; 2.4→2.0MB).
- ✅ **heterogeneous object array** now works (instanceof on a foreach value /
  assigned local over `[new A(), new B()]`) via the generalized obj-load tag mask.
- ✅ **object-valued mixed PROP DONE** (`2f71732`): object is now a boxable KIND
  (box_object + retain-the-RAW-ptr-before-box), so `$box->v instanceof A`, var_dump,
  and method dispatch on the prop work. Property-PATH narrowing (`$local->prop
  instanceof C` keyed `local\0prop` in localTypes, `propPathKey`) + an obj-typed
  property-read mask in emitPropertyAccess make `$box->v->field`/`->m()` resolve a
  typed offset inside the guard. `mergeLocals` DROPS a one-sided path narrowing
  (transient — else a later reassign reads the prop at the stale narrowed type and
  mis-unboxes a boxed scalar as an obj → crash). Open edges: UNGUARDED `$box->v->x`
  (no instanceof) still hits the cell→bag path; a method call on an UNNARROWED cell
  receiver renders its return untyped (the guarded / local-assign idioms work).
- Exception internal-property parity (our model = message/code; PHP adds string/
  file/line/trace/previous) — accepted simplification.

### `?array`/`array` erasure — LARGELY A NON-ISSUE (s9 finding)
var_dump of an array renders correctly WHENEVER the element type is known: a
docblock `@param int[]`/`string[]`/`array<string,int>`, a `@var` prop hint, or a
concrete literal — boxToCell rebuilds it into a cell array (regression test
`var_dump_typed_array`). The ONLY remaining gap is a BARE `array`/`?array` with NO
element annotation (the value's element type erases to unknown, and our arrays
carry no per-element runtime tag) → var_dump shows `int(ptr)`. foreach/count/return/
element-access all WORK on it (element recovered from use). A real fix needs the
rc-risky cell-array-everywhere cascade (changing bare `array`→KIND_ARRAY adds rc
retain/release where there was none + cascades through the stdlib .sig) — NOT worth
it; the workaround is to annotate the element type.
- Re-enable obj↔scalar and add arg-count / missing-required-arg in TypeCheck
  (now precise because unions/narrowing removed the false positives).
- Optional strict sub-mode: flag scalar↔scalar that PHP would coerce-or-throw.

## Progress (2026-06-14) — mixed/cell value completeness

Made EXPLICIT `mixed`/cell values work across the common operations (each a
committed, gated root-cause fix). A cell carries a NaN tag; consumers must
unbox/dispatch by tag, not read the raw i64:
- **method dispatch** on a cell receiver (`$mixed->m()`) — unbox to the obj ptr
  for `$this` + class_id virtual dispatch.
- **string builtins** (strlen/ord/substr/strtoupper/…) — `emitPtrArg` strips the
  tag before treating a cell as a ptr.
- **return** of a cell where the declared type is concrete — `unboxCellToType`.
- **sprintf %s** of a cell — `__manticore_tagged_to_str` (stringify by tag).
- **`(int)` cast** of a cell — `__manticore_tagged_to_int` (tag-switch).

### Untyped params → cell (`function f($x)` = mixed): ATTEMPTED, reverted
`lowerParamType` typed an absent hint as cell. Worked for is_*/arith/strlen/
casts/dispatch, self-host rebuilt + fixpoint byte-identical, BUT regressed
`short_circuit_and`: **boolean truthiness of a cell isn't unboxed** — a boxed
`0`/`false` has non-zero raw bits, so `if ($cell)` / `$a && $b` read it as
truthy. This is ALSO a pre-existing gap for explicit `mixed` in a boolean
context. The cond truthiness is SCATTERED (`icmp ne i64 x, 0` inlined at
emitIf/emitWhile/&&/||/!/ternary/for/emitCast-bool). NEXT STEP to unblock
untyped→cell: a central `emitTruthyBit(value, type)` + a
`__manticore_tagged_truthy(i64)->i1` (int≠0, ""/"0"→false, null→false, []→false,
float≠0, obj→true), routed through every cond site. Then re-attempt the flip.
Other likely follow-ons before/after: `(float)`/`(bool)` cast on a cell,
array-write of a mixed element, more builtins on cell args.

## DONE (2026-06-15, s8) — function return-type inference (Tier 1)

Both gated (suite 249 · difftest 240 · fixpoint · stability):
- **Multi-`return` of distinct value kinds → cell** (`c580f6a`). `function f(){ if
  return "big"; if return 1.5; return $n; }` collapsed to `unknown` (unionWith) →
  callers read garbage. `inferReturn` now cell-aware (distinct concrete value
  kinds / any cell arm → cell), mirroring inferTernary/inferMatch; the existing
  cell return-adoption sets the sig, emitReturn already boxes each return.
- **Untyped fn concrete SCALAR return adopted** (`1e3fa80`). `function greet($n){
  return "Hello ".$n; }` kept an `unknown` sig → `echo greet()` rendered the
  string ptr as %d. When every return agrees on ONE scalar kind (string/int/
  float/bool) adopt it as the sig. Scalars only — array/obj carry rc (NarrowReturns
  / their own discipline); a mixed-path return is already unknown (not adopted).

### CELL ARITHMETIC — easy tier DONE (`4ad5f45`), full tier OPEN
A cell operand (untyped param = cell since s6, or an int|float merge/return) in
arithmetic was only handled on the INTEGER path (`unboxCellInt` for `+ - *`); the
FLOAT path and `emitDiv` `coerceTo('double')`'d a cell → bitcast garbage.
- ✅ **Easy DONE**: `__manticore_tagged_to_double` + `coerceDoubleOperand` route a
  cell operand through it in `emitDiv` + the `emitArith` float path. `$x / 2`,
  `$x + 0.5`, `$x * 1.5`, int|float-merge `/` now 1:1.
- ✅ **Full DONE via NUMERIC-CELL unions** (`9b9323e`). First a DEAD END: making
  `arithType` return `cell` for ANY cell operand + tagged-arith SIGKILLed the
  self-build — the compiler's OWN untyped params (= cell) do arithmetic that
  relies on the integer path, so dynamic-cell-everywhere broke semantics + bloated
  IR. ROOT: a cell-always-int is statically indistinguishable from a
  cell-maybe-float. FIX = the first union typing: `Type::numericCell()` =
  `KIND_CELL` + a `bool $numeric` flag (NOT a `Type[]` atom list — a self-host
  miscompile hazard) marking an int|float union. Same NaN-boxed repr as a plain
  cell (every cell consumer still matches `KIND_CELL`), so it is inert to them;
  only arithmetic reads the flag. Producers (ternary/match/multi-return/if-else
  merge) emit a numericCell via `unifyToCell` when every arm is numeric;
  `arithType` keeps a numericCell result; `emitArith` routes it to the runtime
  `__manticore_tagged_{add,sub,mul}`. A PLAIN mixed cell stays integer-path → the
  self-build emits ZERO tagged-arith → no break. Gated suite 252 · difftest 243.
  LIMIT (safe): an untyped-param cell holding a float (`$x+1`, `f(2.5)`) stays
  integer-path — only an EXPLICIT int|float union is numeric. Sigs encode a cell
  as `mixed`, so the numeric flag is compilation-unit-local.
- **Also OPEN (pre-existing, separate)**: `/` is ALWAYS float here; php returns
  int on exact integer division (`10/2` → `int(5)`). A runtime divisibility check
  in emitDiv (both-int operands) would close it — affects all division, not cells.

## RESOLVED (2026-06-15, s8) — cell local across a merge, FLOW-SENSITIVE (`abcbe08`)

The OPEN gap below is now fixed for the if/else case. After the two whole-name
DEAD ENDS, the working approach is flow-sensitive and contained in `inferIf`:
- `planMergeShadow` detects a local bound to DISTINCT scalar kinds on the two
  paths and appends a self-boxing `$x = box($x)` at the END of each merging
  branch (synthesising an else that boxes the pre-if value for the no-else case).
  The slot holds a NaN-boxed cell AFTER the if; reads before/inside the branches
  stay concrete (box is last); `mergeLocals` types post-merge reads cell. A later
  concrete re-assignment re-narrows the slot — so the ORIGINAL NAME keeps its
  reps everywhere else (this is what the whole-name attempts got wrong).
- EmitLlvm boxes on the `(store node cell + value concrete)` combo — otherwise
  impossible (inferStoreLocal types a store = its value type), so a precise
  signal that touches no genuine cell store.
- Guard: skip a name used as an array index/KEY (the cell-key store path doesn't
  render a boxed key yet) → leaves prior behaviour, no regression.
Gated: suite 248 · difftest 239 · fixpoint byte-identical · stability 5×2.
STILL OPEN sub-gaps: (a) multi-`return` of distinct types — function return-type
inference, separate from locals; (b) a merge-cell used as an assoc KEY (cell-key
store rendering); (c) an UNTYPED function returning a concat infers ret unknown →
echo renders the string ptr as %d (PRE-EXISTING, unrelated to merges — repro
`function f($x){return "v=".$x;}`).

## Progress (2026-06-15, s8) — heterogeneous value unions → cell

DONE + gated (suite 247 · difftest 238 · fixpoint · stability):
- **Heterogeneous vec literal element → cell** (`ef7f984`). `[1,"x",2.5,true]`
  was `vec[unknown]` built RAW; now ≥2 distinct concrete element kinds → element
  is a cell, each entry boxed. inferForeach already lifts `$v` to the element
  type → `$v: cell`. 1:1 with php (is_*/var_dump/truthiness dispatch by tag).
- **Heterogeneous ternary/match value union → cell** (`425a3d6`). `$b ? 3 : 2.5`
  (int|float), `match {=> "s", => 1.5, => $n}` (string|float|int) inferred
  `unknown` and stored RAW → the float/string branch rode as a raw i64 → echo
  rendered 0 / garbage. Now distinct concrete value branches unify on a cell;
  emitTernary already boxed both branches, emitMatch now boxes each arm. The
  declared union return type was already cell (lowerTypeHint maps `|` → cell), so
  it round-trips. Helper `InferTypes::isValueKind`.
- **narrowFromCond extracted + dead-end recorded** (`3050cec`). `is_*($x)`
  flow-narrowing of an `unknown` local is UNSOUND: post the vec-literal change,
  `unknown` can mask a NaN-boxed cell (a heterogeneous-literal element erased
  through a bare `array` param), so retyping to a scalar makes codegen read the
  boxed bits raw → segfault. Only `instanceof` narrowing kept (obj→obj, ptr rep).
  Sound scalar narrowing of a cell needs an UNBOX at the narrow point.

### OPEN gap found this session — cell local across a control-flow MERGE
`if ($b) { $x = 1.5; } else { $x = "hi"; } var_dump($x)` prints garbage EVEN for
an explicit `mixed $x`. Root cause now precise: a cell/mixed local uses a
**store-RAW + box-at-USE + flow-narrow** model — the slot holds the raw i64, and
boxing happens at each cell-consuming site keyed off the flow-narrowed concrete
type (so `mixed $x; $x = 1.5; var_dump($x)` works — the use sees `float`). This
MODEL BREAKS at a control-flow merge: `mergeLocals(float, string) = unknown`, so
after the merge the load is typed `unknown`, the slot holds raw bits of EITHER
branch's value, and var_dump/echo can't recover the tag. Same bug class:
multi-`return` of distinct concrete types with no declared return type.
- The correct model is a **boxed cell slot**: stores BOX, loads read a cell, no
  narrow-at-use needed → merge-safe. Flipping it touches every cell-local store
  (emitStoreLocal boxes when slot is cell) + load (yields cell) + a SCAN that
  promotes a local to a boxed-cell slot when it's assigned ≥2 distinct concrete
  value kinds whose ranges merge (cf. scanParamCellSinks / assocLocals). Heavy
  self-host risk — the compiler uses mixed/cell locals; gate hard. EPIC-SIZED,
  give it a focused session. This is the next type-system priority.

### DEAD END (s8 attempt) — naive blanket boxed-cell-slot promotion
Attempted: a `mergeLocals` detector marks a local assigned ≥2 distinct scalar
kinds across a merge, promotes it to a cell, and a fixup retypes EVERY load/store
of that name to cell (stores box, loads read cell). Under Zend it worked 1:1 on
u4/u6. It BROKE SELF-HOST two ways:
1. **Cross-pass transport miscompiled.** Carrying the promoted-name set on a new
   `FunctionDef.cellLocals` field worked under Zend but the native compiler read
   it empty (no boxing). Fixed by riding the signal on the NODES both passes
   share (retype the store node to cell; box when the store node is cell and the
   value is concrete). Lesson: a NEW cross-pass `FunctionDef` field is a
   self-host hazard; prefer node-carried signals.
2. **Blanket retype is representation-unsound (the killer).** The compiler REUSES
   a variable name across types: `$x` is scalar in a merge (→ promoted) then
   later rebound to an ARRAY and used as `isset($x[$k])`. Retyping EVERY load of
   `$x` to cell makes a store box the slot while an array-access load still reads
   it raw (or vice versa) → `__mir_array_isset_int` derefs the boxed-cell bits →
   crash (`EXC_BAD_ACCESS`, the bad ptr is ASCII string bytes). A single i64 slot
   can't be raw-array at one program point and boxed-cell at another; the
   promotion must be FLOW-SENSITIVE (only the merge-ambiguous live range is cell)
   or the WHOLE slot must be cell with array/obj stores ALSO boxing and EVERY
   array access using the `__mir_array_*_cell` dispatch — neither is a small
   change. Recovered with `bin/build --seed`; reverted clean.

**Detector-precision retry ALSO failed (s8, second attempt).** Added a
`scanNonScalarLocals` guard: promote only a name NEVER used as array base /
receiver / property object / foreach source / invoke callee. Self-host SMOKE then
passed (the array-isset crash was gated out) and u6 + the local-merge cases went
1:1. But the AOT suite regressed `var_dump` + `closure_generators`: a stdlib
string local that is conditionally bound (heterogeneous merge) and then used as
an ASSOC KEY got promoted → boxing the string ptr → the stdClass property keys
rendered as raw pointers (`["4298463912"]` not `["name"]`). The guard only
excludes the array BASE, not a string used as a KEY / index / store-element value
/ array-literal element / raw-ptr builtin arg. KEY LESSON: boxing changes a
value's representation, and string/array values have PERVASIVE raw-ptr uses
(keys, indices, builtins) that a blacklist can't fully enumerate — every guard
added just moved the regression. Blanket whole-name promotion is the wrong shape.

The ONLY sound re-approaches (next attempt, pick one):
- **Flow-sensitive (RECOMMENDED)**: SSA-split / a fresh SHADOW local for the
  merged value so the original name keeps its concrete reps at every use; only
  the merge-result read is a cell. Needs real renaming at the merge but is the
  only representation-correct option.
- **Full-cell slot, consistently**: a promoted local is cell EVERYWHERE — box
  array/obj/string/scalar stores alike AND route every `$x[...]` / `isset` /
  foreach / key-use / builtin through the cell dispatch. Uniform but touches many
  sites; gate hard.
A use-whitelist (promote only if EVERY use is provably cell-safe: echo/var_dump/
return/arith/concat/compare) is a weaker variant of full-cell — likely too
conservative to fire, and still must catch every consuming context.

## Next session — concrete worklist (priority order)

1. ✅ **Cell local across an if/else merge — DONE flow-sensitively** (`abcbe08`,
   see RESOLVED section above). Remaining siblings: **multi-`return` of distinct
   types** (function return-type inference — and the PRE-EXISTING untyped-concat-
   return `ret unknown → %d` bug surfaced alongside it), a **merge-cell as an
   assoc KEY** (guarded out; needs cell-key store rendering), and merges in
   **loops** (only `inferIf` is handled; `while`/`for` heterogeneous scalar
   merges still collapse — extend planMergeShadow analogously if needed).

2. **Union types** (the epic — Phase 2/3 below). Enables obj↔scalar checking +
   fixes polymorphic base-field reads (subclassPropType dead-end). NOTE: the
   single-expression union forms (literal/ternary/match) are now handled via
   cell; what remains is the LATTICE union (KIND_UNION) for dispatch + checking.

3. **foreach over a static assoc with runtime int keys** (s5 crash):
   `$a=["x"=>1]; $a[]=10; foreach($a as $k=>$v)` — `$k` typed string, the int
   key returned raw → echo derefs a small int → SIGSEGV. Box assoc foreach keys
   as cells (keyT=cell + key_cell_at) — but that changes ALL assoc iteration →
   self-host risk (the compiler iterates assocs expecting string keys). Now that
   cell completeness is high, re-evaluate.

4. **Broaden the gated type checker** (`MANTICORE_TYPECHECK`): re-enable
   obj↔scalar (after unions remove the polymorphic-field false positives; FFI
   Ffi\Ptr exemption already present), add arg-count + missing-required-arg and
   prop-assign mismatch.

5. **Audit remaining cell ops**: ✅ `(float)` cast on a cell DONE (`7746b08` —
   routed through `tagged_to_double`, was `sitofp` of the raw tagged bits). Still
   open: array-write of a mixed element, any builtin still coerceToPtr-ing a cell
   arg without emitPtrArg.

## DONE (2026-06-16, s9) — nullable/cell deref + cast + numeric-builtin fixes
Contained, gated user-facing fixes (final: suite 261 · difftest 252 · fixpoint ·
stability 5×2):
- **null `?string` in string builtins → "" not SIGSEGV** (`1f9e319`).
- **(float) cast of a cell** (`7746b08`) + **null in string-concat → ""**.
- **intdiv()** codegen builtin (`e1d08b0`) — was an UNDEFINED `@manticore_intdiv`
  symbol → hard clang fail; `sdiv i64` (truncates toward zero like PHP). Wired into
  emitBuiltin + isCodegenBuiltin + builtinReturnType(int).
- **gettype/get_debug_type** (`e1d08b0`) — (1) return type unlisted → unknown →
  echo rendered the result string ptr as int; now string. (2) array/object/closure
  kinds unmapped (→ "unknown type"); add array/object names (+ class for
  get_debug_type) + tags 7/8 in the cell tag-dispatch chain.
- **max/min with a float operand** (`77e458b`) — coerced every arg to i64 → a float
  arg's bits became garbage; now box + compare-as-double + select the winning boxed
  cell → numericCell (winner's own type preserved, 1:1 with PHP). All-int/all-cell
  keep the integer path. SURFACED + fixed **unboxCellToType had no KIND_FLOAT case**
  (a boxed NaN-pattern cell returned through a `: float` fn → NaN) → tagged_to_double.
- **array_is_list + json_encode object encoding** (`1bc6247`): `array_is_list(\$a)`
  new global stdlib fn; `json_encode` now encodes a non-list array as a JSON object
  `{"k":v}` (was always a list, dropping keys). Top-level string-keyed objects 1:1.
- **number_format + int→float call-boundary coercion** (`8fbcd03`): number_format
  was an undefined symbol (compile-fail); added (integer-unit arithmetic). FOUND +
  fixed a general bug: an int/bool arg to a declared `float` param crossed the i64
  ABI as raw int bits → callee bitcast a garbage double (`f(5)`→float(0)); emitCall
  now sitofp's it.
Found-but-deferred (risky/bigger, see Phase 4 OPEN): `?array` var_dump erasure;
general `mixed` prop with null default (RAW store/default → read crash, entangled
with SPL backing); `array_sum`/`max` over a bare-`array`-typed float array (the
bare-array element erasure — `$v` read raw); `/` still always float on exact integer
division (needs a numericCell result — blast radius across all `/`); `json_encode`
of NESTED heterogeneous cell-array values + non-zero-based int keys (cell-array
element/key fidelity gap); float formatting precision (`var_dump(0.1+0.2)` →
`0.29999999999927`, PHP `0.30000000000000004` — needs shortest-round-trip float→str).

## Constraints / lessons (hard-won)
- Self-host is the gate. Every phase: suite + difftest + fixpoint + stability,
  AND the Zend cold-seed (`bin/build --seed`) which enforces PHP param type
  hints the native rebuild ignores.
- Mutating a `Param->type` in a readonly array: fetch the Param to a local first
  (see scanParamElements) — indirect write through the readonly array crashes.
- Comparing two computed method-call strings (`$a->toString() !== $b->toString()`)
  silently mis-fires under self-host; compare a plain `->kind` field.
- Recovery from a native binary that mis-builds: `bin/build --seed`.
- Representation ≠ type: many "wrong" concrete types are harmless because every
  value is i64 — but DISPATCH and var_dump/checking read the type, so precision
  matters there.
