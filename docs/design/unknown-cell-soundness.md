# The `unknown` soundness problem (and the epic to fix it)

Status: **problem definition + staged plan.** No code changed by this doc.
Author context: distilled from a codegen-wide audit of `KIND_UNKNOWN` handling.

## 1. The heart of the problem

Manticore uses a **type-directed representation**: a value's *static type* decides
its *runtime representation* and the *code emitted* to operate on it.

- Known type → **raw, unboxed** (bare i64/ptr, fixed property offset, enum ordinal).
  Fast, no tag checks.
- `KIND_UNKNOWN` → the fallback. The type lattice's own doc (`Type.php`, the
  `KIND_UNION` comment) defines it literally as **"raw i64" at every consumer**.

PHP is dynamic; inference over it **always** leaves residual `unknown`. So a
type-directed representation over inferred PHP types has an **inherent soundness
gap**: whenever inference fails, there is a value whose representation codegen
cannot determine — so it **guesses**. Every guess is the bug:

- unknown receiver `$x->p` → guess the property is at byte offset **16**
  (`EmitLlvmObjects.php:1836`) → wrong slot / SIGSEGV.
- unknown value read → `bitcast i64 … to double` gated on a *static* KIND_FLOAT
  guess (`EmitLlvmObjects.php:363`, `EmitLlvm.php:8147`) → garbage floats.
- unknown arithmetic operand → fed raw into `add/mul i64` with no unbox
  (`EmitLlvm.php:3998`) → mis-read of a possibly-boxed value.

## 2. `unknown` is TRIPLE-overloaded (the real root)

The single kind `KIND_UNKNOWN` means three incompatible things:

1. **Runtime-polymorphic, tagged** (should be a NaN-boxed `cell`). A heterogeneous
   array value, a `mixed` field. Sound to operate on by tag dispatch.
2. **Inference-failed, but physically raw** (an obj ptr / int the inferrer just
   lost track of). Unsound: nothing at runtime says what it is.
3. **Raw array for the stdlib ABI.** `LowerFromAst.php:1578` DELIBERATELY lowers a
   stdlib-erased param to `unknown`, *not* `cell`, precisely so the caller does
   NOT box an array to a cell (a raw-walking stdlib callee would deref the cell
   tag → SIGSEGV). So here `unknown` = "raw `PhpArray*`, do not box."

Because one kind carries three meanings, the two most load-bearing consumers make
**opposite** guesses about the *same* value:

- `boxRawValue(unknown)` (`EmitLlvmBuiltins.php:750`) → "already a cell, passthrough."
- `boxToCell(unknown)` (`EmitLlvmBuiltins.php:305`) + arithmetic (`EmitLlvm.php:3998`)
  → "raw int."

That contradiction is the disease. Everything else is a symptom.

## 3. The invariant we commit to

> **A value whose representation cannot be statically determined is ALWAYS a
> runtime-tagged cell.** Known types stay raw/fast; every *erasure boundary*
> (typed → unresolved) BOXES to a cell; every consumer of an unresolved value
> DISPATCHES on the tag. No consumer ever guesses a representation.

Corollary — split correctness from performance:
- **Correctness:** erased ⟹ cell (sound, maybe slower).
- **Optimization (separate, later):** narrow cell → concrete where provable (drop
  the box). The compiler currently fuses these and leaves *raw* where it cannot
  narrow — that "raw" is the unsound gap.

## 4. Violation map (audit result — the work list)

### Consumers that guess a raw repr on an unknown value
| id | site | guess |
|----|------|-------|
| A1 | `EmitLlvmObjects.php:1836` `return 16` | property offset = 16 |
| A2 | `EmitLlvmObjects.php:1749` union-prop `$o=16` | same, union atom |
| A3 | `EmitLlvm.php:2955` exception msg off 16 | same, catch type unknown |
| A4 | `EmitLlvmObjects.php:350-375` (bitcast 363) | prop read raw i64 / float |
| A5 | `EmitLlvm.php:8115-8151` (bitcast 8147) | array elem read raw / float |
| A6 | `EmitLlvm.php:3998` | arith operand = raw int (no unbox) |
| A7 | `EmitLlvm.php:3937` | arith result-kind = int |
| A8/A9 | `EmitLlvm.php:4020`, `6483` | float operand via `sitofp` (int) |
| A10 | `EmitLlvm.php:6368` (+ match 5192/5290/5311/5332) | operand = string ptr |
| A11 | `EmitLlvmBuiltins.php:305` | box unknown as int |

### Producers that emit raw into an unknown slot (must box)
| id | site | what stays raw |
|----|------|----------------|
| B1 | `EmitLlvm.php:395` (tmask) + `7343-7368` | arg to unknown param passed raw |
| B2 | `EmitLlvm.php:8186-8219` | store into unknown array element raw |
| B3 | `EmitLlvmObjects.php:667-692` | store into unknown property raw |
| B4 | `EmitLlvm.php:6766-6788` | return of unknown type raw |
| B5 | `EmitLlvmBuiltins.php:748-754` | `boxRawValue` passthrough (assumes cell) |
| B6 | `EmitLlvm.php:7307-7314` | spread element to unknown param raw |
| B7 | `EmitLlvmObjects.php:738-740` | static-local init raw |

**The template for the fix already exists:** the closure-return path
(`EmitLlvm.php:6759-6763`) boxes an unknown return by **runtime LLVM repr** via
`boxLastByRepr` (`EmitLlvm.php:4270-4292`). Every other producer diverges from it.

Also note: most consumers ALREADY OR `unknown` with `cell`/`string`
(`3201`, `5098`, `6022`, …) — the invariant is the *de-facto* norm at the majority
of sites. Only the ~11 A-sites above still assume raw.

## 5. Why it is hard (two walls)

1. **stdlib ABI (meaning #3).** A blanket `unknown → cell` boxes arrays crossing
   the stdlib boundary → raw-walking stdlib callees fault. Must disentangle #3
   from #1/#2 first.
2. **Self-host fixpoint.** The compiler is 159 bare-`array` properties + 418
   bare-`array` params of its own; its node-traversal "works" by accident on the
   current raw behavior. Any change to unknown-codegen changes self-compilation →
   gen2 diverges. **Consistent** changes re-converge (proven: clone / switch-cell /
   falsiness landed green this month); **inconsistent** ones (a consumer flipped
   without its producer) crash. The offset-16 attempts failed by flipping a
   consumer while producers still emitted raw.

## 6. Disentanglement plan

Stop overloading. Introduce the distinction the lattice is missing:

- **`cell`** — tagged, self-describing. The target for meanings #1 and #2.
- **A distinct "raw array (stdlib ABI)" marker** — either a real `KIND_ARRAY`
  with an "untyped element" flag, or keep it as `unknown` but ONLY at the stdlib
  call boundary, never as a value type elsewhere. This removes meaning #3 from the
  general `unknown`.
- After that, the general rule "residual `unknown` ⟹ `cell`" is safe.

## 7. Staged execution (each stage: Zend-seed-validate on USER programs → full gate)

0. **This doc.** Define + agree the invariant.
1. **Disentangle the stdlib ABI.** Give stdlib-erased array params a distinct
   "raw array" type so a later `unknown → cell` normalization can't touch them.
   Validate: array-heavy user programs still call stdlib correctly.
2. **Producer-side boxing, one boundary at a time**, extending each B-site's box
   decision from `=== CELL` to `CELL || UNKNOWN` (model: `boxLastByRepr`). Order by
   isolation: static-local (B7) → return (B4) → property store (B3) → element store
   (B2) → call arg (B1). Gate each.
3. **Consumer-side dispatch.** Once producers box, flip each A-site from its raw
   guess to the cell path (which exists: `emitCellPropertyRead`, `unboxCellInt`,
   `tagged_loose_eq`). offset-16 (A1) becomes: unknown receiver → cell path.
4. **Normalize.** Add the InferTypes tail pass `residual unknown → cell`; delete
   the raw-unknown fallbacks (offset-16 etc.). By now nothing feeds them.
5. **Break the fixpoint where needed.** For any stage that destabilizes self-host,
   first make the compiler SOURCE robust to the correct behavior — validated ONLY
   via the Zend seed compiling USER programs, never `bin/build` — then flip codegen
   and let the fixpoint re-converge.

## 8. DIAGNOSTIC RESULTS (2026-07-08) — the invariant is EMPIRICALLY CONFIRMED

A throwaway blanket `residual unknown → cell` normalization at the InferTypes tail
was compiled onto USER programs by the **Zend-hosted** compiler and linked WITHOUT
any self-build (`php tools/compile_files_mir.php user.php > u.ll; clang -c u.ll;
cc u.o lib/manticore_stdlib.o -o u`). This is the fixpoint-break harness — it proves
codegen correctness on user code independently of self-compilation.

**FIXED** (the object/scalar-erasure family — including the offset-16 research bug):
- `repro_unknown.php` (`$items[0]->s`, unknown receiver): `int(7)\nstring(5) "hello"`
  = php, **no SIGSEGV**. offset-16 is fixed by the invariant.
- `e1` (bare-array property scalar append → float): `3 a,b,c` = php.
- object programs q1/q2/q3/q5/s4/s6/kt (obj arrays, obj-in-assoc, return-new,
  closure-capture-obj, LSB, readonly, inherited-defaults) all pass.

**BROKE** (the carve-outs the blanket over-reached into):
- `cow2` (read a bare-`array` PROPERTY then append): an ARRAY value flowed through an
  unknown node → got retyped `cell` → append-on-cell is not handled → crash. **Arrays
  must stay raw, NOT be boxed to cell.**
- `s3` (Iterator `current(): mixed` summed in a loop): mixed-in-arithmetic rendered a
  garbage float. **Cell arithmetic consumers must be preserved / made unbox-aware.**
- concrete-literal arrays (cow1, std2 assoc build) were UNAFFECTED — only ERASED
  arrays break.

**Conclusion:** the disentanglement is **scalar/obj-vs-array**, not (only) stdlib ABI.
- erased SCALAR/OBJ ⟹ cell — CORRECT, fixes offset-16 + e1 + the object cluster.
- erased ARRAY ⟹ stays a raw array (its value-semantics is the separate array cluster).
- before any BROAD normalization, the array-append and arithmetic CONSUMERS must
  become cell-aware (unbox array-in-cell; keep cell-arith).

## 9. STAGE 1 EXECUTED (2026-07-08) — codegen fix DONE + correct; self-host blocker isolated

Implemented `emitRawPropByClassId` (EmitLlvmObjects): unknown `->prop` receiver →
`cellToPtr` (48-bit mask normalises BOTH a raw obj ptr AND a boxed-obj cell) →
class_id switch → per-holder REAL offset, loaded RAW (node stays unknown, no cell
ripple). Routed `KIND_UNKNOWN` receivers to it in `emitPropertyAccess`.

**Correct on USER programs (Zend-host harness, no self-build):** offset-16 repro
`int(7)`/`string "hello"` (no SIGSEGV); q1/q2/q3/s3/s4/kt all pass; **does NOT break
s3 or arrays** (surgical `->prop`-only, unlike the blanket experiment). This fix is
READY — re-apply it the moment the source blocker below is cleared.

**Self-host blocker — precisely isolated.** `bin/compile` self-build smoke SIGSEGVs
`KERN_INVALID_ADDRESS at 0x38` (a null→field@offset-56), NONDETERMINISTIC ~5% (the
documented heisenbug). Made the switch **strictly additive** (default + non-holder
class_id → keep offset-16; override ONLY confirmed holders) → **still crashes.** So the
crash is a HOLDER-receiver read whose value CHANGED — i.e. **the compiler's own source
relies on the offset-16 wrong-read for holder receivers**: it compiled itself with the
quirk, so its logic expects the quirk. Pure fixpoint self-consistency-with-the-bug.

## 10. The real remaining work: eliminate the compiler's unknown-receiver `->prop` reads

The codegen fix affects ONLY `KIND_UNKNOWN` receivers. A TYPED receiver read already
uses the correct `propertyOffset(knownClass)` TODAY. So the plan:

**Pin every genuinely-unknown-receiver `->prop` read in `src/` to a concrete type**
(the T5 `castX()` pattern the codebase already uses widely). A pinned read is correct
under TODAY's codegen (offset-16 only fires on unknown) → validate via the NORMAL
`bin/build` + gate, incrementally, one site at a time. Once NO compiler read hits the
unknown path, `emitRawPropByClassId` lands with nothing left to perturb.

**Finding the sites:** crash-atos on the heisenbug reports
(`~/Library/Logs/DiagnosticReports/manticore*.ips` → `atos -o bin/manticore -l <base>
<addr>`; all crashes = null@0x38). The prior bisect found `LowerFromAst.php:515`
(`$module->functions[$k]->isPrelude=true` — chained prop-array-element on a null elem)
and `ConstFold::foldBlock` (null `$n`→`->stmts@56`); the crash HOPS as each is pinned,
so expect several. This is bounded, incremental, and each pin is independently gateable.

## 11. BREAKTHROUGH (2026-07-09) — deterministic enumeration + inference root-fixes (NO pins)

The §10 pin/crash-atos plan is SUPERSEDED. Two better ideas replaced the whack-a-mole:

**(a) Deterministic enumeration instead of crash-atos.** A gated diagnostic in
`emitPropertyAccess` (`EmitLlvmObjects.php`) — when the receiver's static class is
empty (the offset-16 path), `error_log("UNKPROP\tfn=…\t->prop\trkind=…")`. Gate on
`MANTICORE_UNKNOWN_PROP_TRACE`; use `error_log` (a real builtin — `fwrite(STDERR,…)`
is fine under Zend but calling it with `$e->getFile()` broke self-compile emit; and
`dprint`'s `write(2,…)` is a no-op stub under Zend so its output is lost). Run the
Zend front-end over all of `src/`:
`MANTICORE_UNKNOWN_PROP_TRACE=1 find src -name '*.php' | sort | xargs php tools/compile_files_mir.php >/dev/null`.
This lists EVERY genuinely-unknown-receiver `->prop` the compiler emits over its own
source — complete, deterministic, ~seconds. **36 sites at HEAD.** No heisenbug, no gate
per discovery. (Re-run anytime to measure remaining count.)

**(b) The user's steer: eliminate the unknown-ness by INFERENCE, not by pinning each
read.** A pinned read is a manual annotation; teaching inference to derive the type is
the principled version AND crosses the fixpoint smoothly — a receiver that infers to a
known class uses the correct `propertyOffset` TODAY, so codegen is unchanged and self-
compilation does not diverge. The 36 sites clustered into a few roots:

1. **`null ∪ obj<C>` erased to `unknown`** (`Type::unionWith`, the kind-mismatch arm
   `return unknown()`). A local `$x = null; … $x = <obj>;` merged to unknown → every
   guarded `$x !== null && $x->p` read hit offset-16. FIX: null arm joined with obj/
   union keeps the obj type (PHP `?C`). Pure inference, no annotation. (Matches the
   ternary path's existing "obj|null STAYS obj<P>" — unionWith was the inconsistent one.)

2. **Doc-type short-name resolution (THE big lever).** The compiler's own core
   collections carry `@var array<string, Type>` / `array<…, ClassDef>` etc. `array<K,V>`
   IS parsed → `assoc[K,V]`, and the inner `V` goes through `lowerTypeHint`. But a short
   name like `Type` is GLOBALLY AMBIGUOUS (`Compile\Mir\Type` AND `Codegen\Llvm\Type`),
   so `shortClassFqn` rejects it; regular hints survive only via the file's
   `use Compile\Mir\Type;`, which doc-comment strings never consult (and the merged
   module drops per-file aliases). FIX: `lowerTypeHint` walks `currentDeclNamespace` AND
   ITS ANCESTORS — a pass in `Compile\Mir\Passes` naming `Type` resolves to the nearest
   enclosing `Compile\Mir\Type`, the PHP-correct pick, no per-file alias tracking needed.
   This single fix revives ALL ~30 existing `array<K,V>` annotations → **cleared 15
   sites with zero new source.** Exactly the "compiler infers itself" ideal.

3. **Bare-`array` param/prop holding node objects, NO annotation** (e.g.
   `fccParamsAndArgs(?array $declParams)` → `foreach as $p` → `$p->typeHint`). The
   element type is unrecoverable from PHP syntax; `@param \Parser\Ast\Param[]` (already
   the codebase convention, honored by `docTagType`→`lowerTypeHint`) types all reads in
   the function at once — a per-DECLARATION annotation, not a per-read cast. Proven on
   `fccParamsAndArgs` (−5). Alternative (deeper): call-site/store back-inference
   (`scanCallSiteArrayElems` machinery) to avoid annotations entirely — riskier.

**Result: 36 → 11**, via fixes 1+2 (pure inference) plus one `@param` (fcc). IR emits
clean under the Zend host (exit 0). **NOT YET GATED** — fixes 1 & 2 touch shared, load-
bearing hint/merge resolution used by 500+ self-compile sites; the fixpoint is the real
test (§5). Gate before trusting any of it.

Remaining 11 (the tail): `synthStaticClosure` (AST-Param array, annotation-fixable like
fcc); `scanCallSiteRefParams`/`scanCallSiteArrayElems`/`scanGlobalTypes`/
`mergeAdjacentStrConsts` (unannotated node-array params); `unionPropType`/
`unionMethodReturn`/`cellMethodReturn` (rkind=`null` — a smaller residual null-merge gap
distinct from root 1). Each independently diagnosable; re-run the enumeration to track.

## 12. MILESTONE (2026-07-09) — compiler unknown-receiver `->prop` reads: 36 → 0

Every genuinely-unknown-receiver `->prop` read in the compiler's self-compilation is
ELIMINATED (re-run the §11 enumeration: `UNKPROP=0`, IR emits clean, exit 0). Per §10
the offset-16 codegen fix (`emitRawPropByClassId`) now lands "with nothing left to
perturb" — the compiler no longer exercises the offset-16 / KIND_NULL-receiver path.

Mechanisms used (in the spirit "teach the compiler to infer; annotations where PHP's
syntax cannot carry the type" — the annotations double as a generics precursor):

- **Pure inference #1 — `Type::unionWith`:** a NULL arm joined with obj/union keeps the
  obj type (`?C`) instead of erasing to unknown. (`Type.php`.)
- **Pure inference #2 — ancestor-namespace doc-type resolution:** `lowerTypeHint` walks
  `currentDeclNamespace` and its ancestors, so a short name in a doc-type (`Type`,
  `Node`, globally ambiguous) resolves to the nearest enclosing package. Revived ALL
  existing `@var array<K,V>` annotations → cleared the whole collection cluster (−15),
  zero new source. (`LowerFromAst.php`.)
- **Annotation channel — `@param T[]` / `@return T[]`:** already honored by
  `docTagType`→`lowerTypeHint`; used on `fccParamsAndArgs`, `resolveMethodParams`,
  `isFccArgs`. (`|null` in a doc-type BREAKS parsing — the `?type` return already carries
  nullability; write `@return T[]` not `@return T[]|null`.)
- **NEW FEATURE — inline local `/** @var T $x */`:** parser captures a statement-leading
  doc comment (`docCommentByPos`) onto `ExpressionStmt`; `LowerFromAst` reads `@var` for
  the bound local and stamps `StoreLocal::declaredType`; `InferTypes::inferStoreLocal`
  treats it as authoritative (seeds `localTypes`, retypes an array-literal init to the
  declared shape). Types bare-`array` LOCALS that hold objects (`$observed`/`$merged`),
  which no prior channel could. This is the local-scope analogue of `@var`/`@param`.

Files: `Type.php`, `LowerFromAst.php` (ancestor-NS + `@var`-local hook + `resolveMethod
Params` @return), `InferTypes.php` (declaredType + `@var` seeds + 3 `@var Type $found`),
`Nodes.php` (`StoreLocal::declaredType`), `Parser.php` + `Ast/Stmt.php` (ExpressionStmt
docComment), `EmitLlvm.php`/`Parser.php` (annotations), `EmitLlvmObjects.php` (the
`MANTICORE_UNKNOWN_PROP_TRACE` enumeration diagnostic — keep as tooling).

**Residual pure-inference opportunity (not required, deeper):** the 3 `@var Type $found`
sites paper over a real gap — a local `$x = null;` reassigned INSIDE a loop and read in
that loop types as KIND_NULL only (the loop back-edge doesn't merge the reassignment into
the loop-entry type). Fixing the loop-carried local-type fixpoint would drop those
annotations. High blast radius; deferred.

**STATUS: NOT GATED.** All above is validated only by the Zend-host enumeration (emits
clean IR). The changes touch shared inference (unionWith / hint resolution / StoreLocal)
used by 500+ self-compile sites — the fixpoint (§5) is the real test. GATE the inference
changes FIRST (fixpoint + suite); only then apply `emitRawPropByClassId` and gate again.

## 13. GATED GREEN (2026-07-09) — offset-16 SOLVED end-to-end

The codegen fix + the 36→0 source-robustness landed and passed the FULL gate:
- **FIXPOINT OK** — Stage-2 IR == Stage-3 IR, byte-identical (the inference changes
  re-converge; the fragile fixpoint held).
- **SELF-HOST OK** — AOT suite 415/415 (incl. the new `unknown_receiver_prop` case).
- **STABILITY OK** — 5×2 rebuilds, every binary smoke-clean (the ~5% heisenbug is GONE —
  it was the offset-16 wrong-read on a holder receiver all along).
- **DIFFTEST** — 406 MATCH, 0 DIFF vs PHP 8.5.

Codegen: `emitRawPropByClassId` (EmitLlvmObjects) routes a KIND_UNKNOWN receiver `->prop`
to a class_id switch reading `$prop`'s REAL per-holder offset, BOXED by the slot's declared
type. `inferPropertyAccess` types the RESULT as a `cell` so echo/var_dump/=== dispatch on
the tag (a raw load rendered a string slot as its pointer-as-int). Self-host-neutral: the
compiler has 0 unknown-receiver reads, so it never exercises the new path on itself.

**Regression caught + fixed by the gate:** the first gate passed fixpoint but failed
`callable_forms` (method FCC `$o->dbl(...)` → 0). Root: a `@param T[]` / `@return T[]` on a
NULLABLE `?array` coerces a null to `[]` UNDER THE NATIVE self-build (not under Zend) —
dropping `fccParamsAndArgs`'s `__fa0` fallback → a param-less closure. **Rule: never
annotate a nullable `?array` param/return with `T[]`; rebind to a non-null local inside the
null guard and put the inline `@var` there.** (`|null` in a doc-type also breaks parsing.)

Recommended follow-ups (separate): the deeper pure-inference wins that would drop the
remaining annotations — the loop-back-edge local-type merge (the 3 `@var Type $found`), and
call-site/store back-inference for object array elements. And the broader stage-3/4 of §7
(normalize residual unknown → cell; delete raw-unknown fallbacks) now that the invariant is
proven and the enumeration tool exists.

## 14. ARRAY CLUSTER — first end-to-end fix (2026-07-15, `main` `a6e062a`)

The erased-**array** half (§8 called out: "erased ARRAY ⟹ stays RAW array; array
consumers must be made cell-unbox-aware") got its first self-host-safe fix, via
the monomorphization + a store-boundary reabstraction:

- **Monomorphize callable dimension** (`docs/design/monomorphize-callable-dim.md`)
  specializes callback-takers per concrete closure, so an erased array flowing
  through a callback (`usort`/`uasort`) reaches a KNOWN callee that can cellify.
- **De-cellify at the store boundary**: `emitCellArrayToTyped` (reverse of the
  cellify helper) fires at a concrete-element-array slot ← cell-element-array
  value, planted by `InferTypes::inferStoreLocal` for a typed array PARAM (the
  same box-back plant precedent §mergeShadow uses for scalars). This is the
  typed⇄cell array reabstraction §8 said was needed, landed at the assignment
  boundary rather than as a blanket flip — so it re-converges the self-host.

Gated: fixpoint byte-identical, self-host 465/465, stability 5×2. Fixes `uasort`
with any comparator (int-arith, `<=>`, strcmp) — the `bug1`/`bug3` reproducers
that lived under `docs/bugs/selfhost/` now pass and were removed. The remaining
array-consumer sites (append/arith on an erased array read) are the next targets.

## 14. Re-audit + property-element erasure fixed at the SOURCE (2026-07-16)

Re-audited the §4 map against the post-split code (every file:line above is stale —
`EmitLlvm` is now 14 collaborator traits). State: the §2 hinge is **intact**
(`boxToCell(unknown)` → int at `EmitLlvmBuiltins.php:315`; `boxRawValue(unknown)` →
cell at `:953`). A-sites: **A1, A3 fixed**; A2, A4–A11 alive. B-sites: **0/7** — every
gate is still literally `=== KIND_CELL`. Meaning #3 has NARROWED on its own: user and
closure untyped params now lower to `cell` (`LowerTypes.php:100-106`,
`LowerFns.php:314-319`); raw-unknown survives only at the stdlib extern-sig boundary
(`LowerFns.php:193-205`).

**Structural finding that changes the plan:** essentially NO code says
`kind === KIND_UNKNOWN ⇒ raw`. Every surviving violation is raw **by fall-through** —
the guess hides inside `=== KIND_CELL` gates (`tmask` `EmitLlvm.php:284`,
`cellPropBoxed` `EmitLlvmExpr.php:885`, `$boxVal` `EmitLlvmArrays.php:561`,
`coerceArithOperand` `EmitLlvmExpr.php:975`) and in `boxToCell`'s unmatched-kind tail.
So §7 stage 2 is ~5 predicates to widen — but **grepping `KIND_UNKNOWN` will not find
the violations.**

**Landed here (source-side, not codegen):** `scanPropElemFromStores` only ever saw
`$this->prop[] = v` — it skipped every non-method function outright and its collector
matched a `this` receiver only. So a property filled from OUTSIDE its class
(`$b->xs[] = "a"`) kept an ERASED element and the read guessed a repr. Added
`propElemStoreOwner()`: resolve the owner from a TYPED receiver (`$o->p[]` where `$o`
is `obj<D>` → D) as well as `$this`, and scan free functions / top-level main too.

Two live bugs, neither covered by difftest, fell to that one change:
- `public array $xs = []; $b->xs[] = "a";` → `implode` printed `2.1e-314` garbage
  (string pointers bitcast to double) — the §4 A5 guess. Now `a,b,c`.
- `$r = $c->rows; $r[] = 9;` MUTATED the property (`1 2` → php, `2 2` → us): an erased
  array aliases instead of value-copying. A concrete element type restores COW.

The lesson matches §3's corollary: **the cheapest place to kill a guess is to stop the
ERASURE, not to teach the consumer to guess better.** Prefer widening an inference scan
over flipping a codegen A-site — it needs no producer/consumer co-flip, so the self-host
fixpoint re-converges for free (this landed byte-identical).

## Related
- `is_callable` pin, offset-16 crash diagnostics, prior reverted attempts:
  memory `unknown-receiver-propread-offset16-2026-07-08`.
- array value-semantics symptoms (clone/COW/bare-append): memory
  `array-value-semantics-cluster-2026-07-08`.
- why the cell path blocks int-overflow→float, and the VRA parked on top of it:
  memory `vra-and-cell-soundness-2026-07-16`.
