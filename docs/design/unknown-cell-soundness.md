# The `unknown` soundness problem (and the epic to fix it)

Status: **LIVING LOG — largely executed.** This started as a problem definition
and staged plan; the later sections record the work actually landing (STAGE 1
EXECUTED, the 36→0 milestone, GATED GREEN, the array cluster). Read it
chronologically: an early "NOT GATED" line is superseded by a later section, not
contradicted by it.

⚠ The `EmitLlvm*.php` line numbers in §1/§2 predate the split of `EmitLlvm` into
14 traits. Treat every line reference here as unreliable; grep the symbol.

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

## 15. Re-audit + property-element erasure fixed at the SOURCE (2026-07-16)

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

## 16. The OTHER two array-holding declarations (2026-07-16)

§15 killed the element erasure on an INSTANCE property by widening the store
scan. Probing the same shape against every other declaration that can hold an
array found the identical bug twice more — plus a crash that had been hiding
under it. Same lesson, three declarations:

| decl | before | root |
|------|--------|------|
| instance prop `$c->xs[]` | fixed §15 | scan saw only `$this->` |
| **static prop `B::$xs[]`** | **SIGSEGV** | `[]` default never initialised |
| **global `global $g; $g[]`** | `2.1e-314` | append is not a `StoreLocal` |

**The crash (worse than the erasure, and it masked it).** `globalInit`
(`EmitLlvmObjects`) renders int/bool/string/float consts and returns `'0'` for
everything else — an array literal default silently became a NULL pointer.
`var_dump(B::$xs)` printed `int(0)` and the first `B::$xs[] = v` appended onto
NULL. Only a static prop can carry a non-constant default (every other
`addGlobalCell` caller registers `IntConst(0)`), so `globalInitIsConst` now
splits them and `emitGlobalRuntimeInits` builds those at `__main` entry, before
any top-level statement. **Why the suite missed it:** the one existing case
(`static_array_prop`) only ever STRING-KEYED the static, and `set_str` allocates
from a null buffer — `__mir_array_append` does not.

**The erasures**, both fixed the §15 way — at the source, by widening a scan:
- `scanStaticPropElemFromStores` — a static's stores live OUTSIDE the declaring
  class, so the lowering-time AST scan (`inferPropElemFromStores`, which walks
  `$this->p` in `$decl->methods` and skips statics outright) could never see
  them. Keyed by the cell symbol every `StaticProp_` carries.
- `collectGlobalStoreTypes` — `$g[] = v` is a `STORE_ELEMENT`, not a
  `StoreLocal`, so the cross-scope join never saw it. Also: `__main` binds a
  `global $x` name with NO decl node, so its `$g = []` was invisible to the scan
  AND (once the element was known) would have re-erased the global — hence the
  `inMainBody` guard in `inferStoreLocal`.

**Two adjacent value-semantics gaps the crash had been hiding** (both surfaced
only once statics stopped faulting; the instance and global paths already did
this, only `STATIC_PROP` was missing from each list):
- `$copy = B::$xs; $copy[] = v` MUTATED the static — `EmitLlvmLocals` copies a
  vec on a `PROPERTY_ACCESS` read but not a `STATIC_PROP` one.
- `B::$xs[] = v` emitted no COW at all (`EmitLlvmArrays`'s cow list).

**Method note.** Every fix is inference/lowering-side; no A-site was flipped, so
no producer/consumer co-flip and the fixpoint re-converges for free. Probing with
FLOAT and `2^50` was load-bearing — NaN-boxing makes unbox ≈ identity for small
ints, so an int-only probe would have shown all four bugs as passing.

**Known-unfixed, deliberately (the §2 meaning-#3 wall).** `var_dump` of an
ERASED bare-`array` renders `int(<ptr>)` instead of `array(…)`. NOT specific to
statics — a bare-`array` PARAM and an instance property do it too (a bare `array`
hint has no branch in `lowerTypeHint`, so it falls through to `unknown`). This is
the blanket `unknown → cell` that §8 measured as BREAKING `cow2`; it belongs to
the array cluster, not here.

## 17. Re-audit 2026-08-07 + the plan to actually finish this

Audited against `main` `83b8386`. Two things moved since §15/§16, and together they
make the structural fix affordable in a way it was not before.

**The §2 hinge is half-disarmed.** `boxToCell`'s unmatched-kind tail
(`EmitLlvmBuiltins.php:543`, tail at `:618`) no longer blind-`box_int`s an erased
word. It PROBES: an already-boxed word passes through, a raw container is
identified from its allocator magic at `ptr-8`, anything else is left as it was.
So the "same value, opposite guesses" pair is now `boxRawValue` (`:1930`, treats
`CELL || UNKNOWN` alike — correct) against a tail that mostly declines to guess.

**The violations are still not greppable.** There are **223** `=== Type::KIND_CELL`
gates across `src/Compile/Mir/Passes/`, and only ~45 of them mention `KIND_UNKNOWN`
at all. As §15 found, essentially no code says `KIND_UNKNOWN ⇒ raw`; rawness
arrives by FALL-THROUGH out of a `=== KIND_CELL` gate. Anyone starting here by
grepping `KIND_UNKNOWN` will conclude the problem is already solved.

### 17.1 Why it is still stuck: one type carries two answers

§8 measured the blanket `unknown → cell`: it FIXES offset-16 and every
scalar/object case, and it BREAKS `cow2` (an erased array read+append) and `s3`.
The split is **scalar/object vs ARRAY**, not "stdlib vs not". While both live in
`unknown`, no blanket normalization can ever be right — each one needs the
opposite answer.

### 17.2 The proposal: give meaning #3 its own type

Introduce a distinct type for *raw array, element unknown* — the stdlib-ABI
channel — so `unknown` is left meaning ONLY "runtime-polymorphic".

What makes this cheap NOW and not before: **meaning #3 has already narrowed to
essentially one producer.** §15 recorded that user and closure untyped params
lower to `cell` on their own; raw-unknown survives at the stdlib extern-signature
boundary. One producer is a refactor, not an epic.

What it unblocks, in order:
1. `unknown` means one thing ⇒ the tail `unknown → cell` becomes sound.
2. The ~5 fall-through predicates §15 named can widen to `CELL || UNKNOWN`
   WITHOUT touching the array cluster, because the array cluster now has its own
   type and is no longer collateral.
3. The stdlib stops being a hazard for anything array-shaped — which is the
   precondition for moving prelude modules into prebuilt `.o`s (a separate idea:
   `.sig` v2 already carries classes, so the old blocker there is gone).

### 17.3 Driving regression: `array_fill`

Use it as the test that must go green. It is meaning #3 in its purest form, it
FAULTS today, and it needs no self-build to reproduce:

    function pick(int $parts): int {
        $load = array_fill(0, $parts, 0);
        foreach ([353307, 70000, 53000] as $sz) {
            $min = 0;
            for ($p = 1; $p < $parts; $p = $p + 1) {
                if ($load[$p] < $load[$min]) { $min = $p; }
            }
            $load[$min] = $load[$min] + $sz;
        }
        return $load[0];
    }
    echo pick(8) . "\n";   // php: 353307   native: SIGSEGV

`array_fill` is in the stdlib (`src/Runtime/Stdlib/Arrays.php`), so its `array`
return crosses the `.o.sig`, which re-erases the element type, and `mixed $value`
makes the elements cells — writing a raw int in and reading it back faults on an
integer read as a pointer. The comment under `array_fill` already names the local
remedy (prelude-injection); the type split is the general one.
`Compile\Mir\SplitModule` currently fills its counter array with an explicit loop
to dodge this.

### 17.4 Method — non-negotiable, it is what makes stages land

§15 and §16 landed byte-identical THREE times by obeying one rule:

> **Stop the erasure in inference; do not teach the consumer to guess better.**

The mechanism is not stylistic. An inference fix needs no producer/consumer
co-flip, so the self-host fixpoint re-converges for free. Flipping a codegen
A-site requires producer and consumer to change TOGETHER or generation 2 crashes
— that is exactly how the earlier offset-16 attempts died. So: inference fix
first, always; A-site only when there is no inference-side cause left.

### 17.5 Staged plan

Each stage: validate on USER programs through the Zend harness (no self-build),
then `bin/build` twice, then the full gate.

1. **Split the type.** Add the raw-array type; make the stdlib extern-signature
   boundary its only producer. No consumer changes yet — everything that reads
   `unknown` today must read the new type identically, so this stage is
   behaviour-neutral and should land byte-identical.
2. **Prove it on `array_fill`** (17.3) with no other change.
3. **Widen the fall-through predicates** to `CELL || UNKNOWN`, one at a time,
   starting with the ones §15 named (`tmask`, `cellPropBoxed`, `$boxVal`,
   `coerceArithOperand`). Each is independently gateable now that the array
   cluster cannot be caught by them.
4. **InferTypes tail `unknown → cell`**, then delete the raw fallbacks. This is
   the §8 blanket, now safe because the array meaning left the type.
5. **Element channel** — only after 1–4. The read-side decode is sound ONLY
   paired with a store-side re-encode and the channel retyped to CELL; scalars
   need hint codes too, or a cell reader misreads a raw int as a double. See the
   withdrawal record in memory `element-repr-hint-nibble-2026-07-29` before
   rebuilding it a third time.

### 17.6 References belong to the aliasing invariant, not here

The three non-self-describing channels were: **array element · by-ref-captured
local · static prop**. Static prop is closed (§16). Array element is half done
(the `ARRAY_ELEM_HINT_*` nibble). The by-ref local is open.

But a reference is not a value-representation problem — it is a **slot-identity**
problem: a by-ref binding makes two names share one slot, and both ends must agree
on that slot's representation. That is the invariant the aliasing epic already
states as "one slot = one representation", so the fix belongs to its agreement
scan rather than to a new mechanism. The tell that it is the same class: `mixed
&$var` was closed, while a CLOSURE's `&$v` still stays RAW.

### 17.7 Do not repeat

- The blanket flip before the type split — measured, breaks `cow2` and `s3`.
- The element read-decode without the store re-encode — built twice, withdrawn
  twice; only a 2-generation self-build catches it, the AOT suite passes.
- Grepping `KIND_UNKNOWN` to find the work — it is all fall-through.

## 18. What was actually wrong (2026-08-07) — §17 measured, and mostly superseded

§17 was written from a re-audit, not from a run. Measured against its own driver
on `main` `ac84a52`, two of its three premises do not hold:

| §17 said | measured |
|---|---|
| the stdlib `.o.sig` re-erases arrays — meaning #3 is the thing to split out | the `.sig` erases **1 of 900** returns and **17 of 1747** params. `array_fill`'s is `ret:"mixed[]"` — a `vec[cell]`, correct. Splitting the type would have bought almost nothing |
| §16's "var_dump of an ERASED bare-`array` prints int(ptr)" is still open | fixed at HEAD; matches php |
| `array_fill` faults because its element re-erased | its element is a **cell**. The fault is in the ARITHMETIC over that cell, and in what boxes the result |

The real chain, off `--keep-ir`:

1. `$a[$i]` reads a cell element → `__manticore_unbox_int` → a raw i64.
2. `arithType` typed `cell ⊕ int` **`unknown`** — its own comment saying a plain
   mixed cell "falls through to unknown (the integer path)".
3. `coerceArithOperand` coerces every non-float operand to i64, so the value IS
   a raw int. The type said otherwise.
4. Storing it into the cell array called `boxToCell(unknown)` → the probing
   boxer, which **dereferences `[v-8]`** for an allocator magic under
   `plausiblePtrIr` (`65535 < v < 2^48`). Every result above 65535 faulted.
   Into a `mixed` property the same word was stored raw and read back as a
   denormal double.

**The two-boxer divergence is the finding that replaces §17.2's type split.**
The RETURN boundary boxes an erased word with `boxLastByRepr` — a tag test, no
dereference, `box_int` otherwise — and is correct. The element/property boundary
uses `boxUnknownShallowIr`, which dereferences. §4 already called the return path
"the template for the fix"; it is also the SAFE one, and the divergence is what
turns a wrong value into a SIGSEGV.

### 18.1 What landed

Both halves are inference-side, per §17.4, and both re-converged the self-host.

- **The integer path is total, so its result is an `int`.** Typing it so changes
  no emitted arithmetic and stops every consumer guessing.
- **A plain mixed cell in numeric arithmetic promotes by tag** — the numeric-cell
  route to `__manticore_tagged_<op>`, which also promotes an int overflow to
  float. The comment claiming this "SIGKILLs the self-build" was **stale**: two
  generations build, and what it really needed was §18.3.

Eight latent bugs surfaced, each pre-existing and each with its own root:

1. A CELL-KEYED array reports `isVec()` (`isAssoc()` is string-key-only), and the
   foreach key rode a cell only when the ELEMENT was cell/unknown — the element
   standing in for the key. A concrete element sent string keys down the vec
   int-key path, printing pointers.
2. `NarrowReturns` rebuilt an array return as `isAssoc() ? assoc : vec`, dropping
   a cell key on the way out of the function that built it.
3. A monomorphic clone inherited the generic's ADOPTED return type; `array_sum`'s
   `int|float` had narrowed to `int` for the erased body, so the vec[float]
   specialization summed doubles behind an `-> int` signature. Fixed by carrying
   the DECLARED return per function and having the adoption gate read that
   instead of the type a previous pass wrote in place.
4. `public static mixed $n = 0;` stored its default RAW into a cell slot — only
   the `null` default had been boxed. Every tag consumer read it as a double.
5. The dynamic-name call's SPREAD arm built its call by hand and passed raw
   scalars to cell params. It survived only because the callee unboxed with
   `unbox_int`, the identity on a raw int.
6. `$s[$i]` never unboxed a CELL index — in EITHER emitter. The NaN bits read as
   an i64 are a vast offset, so `__mir_str_char_at`'s out-of-range arm answered
   `""` for every character. The second emitter is the one that mattered:
   {@see DemoteCharLocals} rewrites a character read to a raw `__mir_str_byte_at`
   whenever the character is only compared to one-char literals and never used
   AS a string — so **adding a `. $c .` to a debug line disables the pass and
   the bug with it**. A marker that printed nothing but a constant is what
   finally kept the failure alive.
7. `scanKeyUsedLocals` counted a STRING byte offset as an array key. See §18.3.
8. A local handed to a by-ref param typed `int &$pos` had no pin, so the loop
   promotion could change a slot whose representation the CALLEE owns.

Note the shape of 3, 4 and 5: each is a place where a value crossed into a cell
channel without being self-describing, and each was invisible while the consumer
happened to unbox it with an operation that is the identity on a raw int.
`unbox_int` is a very forgiving reader, and it hid all of them.

### 18.3b The real blocker, found by merging main in (2026-08-07)

The three predicates below were real and are fixed. But turning tagged
arithmetic on and then merging `main` — which brought ~60 new suite cases —
produced a fourth failure of a different KIND, and it is the one that matters:

> **`cell` is not yet a runtime GUARANTEE.** It is a static claim that several
> producers do not honour: they type a slot `cell` and store a RAW word into it.

Tagged arithmetic is the first consumer that TRUSTS the claim — every other
consumer either re-probes the word or treats it raw — so it fails on exactly
those channels, and only there. Three of them, each pre-existing and each
independently demonstrable with NO arithmetic in the program:

| channel | witness |
|---|---|
| the `$GLOBALS['x']` view | lowered `cell`; `global $x` types the same storage from the join. Both read it RAW, so they agree by accident |
| an UNHINTED static property READ | typed `unknown` while the STORE boxes by the declared type. Typing the read `cell` was already tried and reverted — an ARRAY rides the same slot raw (`staticPropRef`'s ⚠) |
| the elements of an erased array | `array_combine($k, array_map('strlen', $k))` then `foreach` — the value types `cell` and arrives raw. `var_dump($len)` alone answers `float(5.4E-323)` |

The integer path hides all three by being raw at BOTH ends. That is the same
accidental agreement §18 found at the element/property boundary, one level up.

So the routing stays built and stays OFF (a `false &&` in `arithType`, with the
list above). **Do not carve out the consumers one channel at a time** — the
first carve-out was written and then deleted for exactly this reason: it moves
the lie around instead of removing it. The fix is §3's invariant at the
PRODUCERS: a slot typed `cell` must be written boxed, whoever writes it. When
that holds, delete the `false &&` and move
`docs/bugs/erased_arith_float_cell.php` back into the suite.

### 18.3 What the tagged-arith half really needed

Not the store plant it first looked like. Once arithmetic over a cell yields a
cell, a loop-carried slot changes representation across the back edge — and the
machinery for exactly that already exists and had been running for other kinds
all along:

> `loopMerge` promotes a re-kinded loop local to a CELL, records it in
> `cellLoopLocals`, sets `loopPromoGrew`, and `inferFunction` re-infers the
> whole body — so the pre-loop store, the guard and the body all agree. A
> promoted PARAM even gets a `$p = box($p)` prepended at entry
> ({@see InferNodes::coercePromotedParams}).

Three things kept `$pos` out of it, and each was a rule that had quietly
outgrown its reason:

1. **A STRING byte offset counted as an array KEY.** `scanKeyUsedLocals` marks
   every subscript index, and a marked name is excluded from cell promotion —
   because a cell ARRAY key has no dispatch. `$s[$i]` has no key channel at all;
   it is an offset. That single over-broad mark is why `$pos` stayed pinned raw
   while the body wrote a boxed word into it. **Ask what the base is.**
2. **`$s[$i]` did not unbox a cell index** — and the emitter that mattered was
   the DEMOTED one (`__mir_str_byte_at`), which only exists when the character
   is never used as a string. Instrumenting with `. $c0 .` disabled the
   demotion and the symptom together; a constant-only marker was needed to keep
   it alive.
3. **A by-ref callee owns its slot's representation.** `int &$pos` writes back a
   raw int, so the caller must NOT promote that name — a new `refPinnedLocals`
   pin, and for the store into such a pinned slot the fourth plant after all:
   a concrete-scalar store node with a cell value un-cellifies
   ({@see EmitLlvmLocals::emitStoreLocal}, mirror of the box-back).

The lesson is §17.4's, one level up: the cheapest fix was not a new mechanism
but **finding the predicate that was excluding the value from the mechanism
already there**. Both exclusions (key-used, ref-pinned) are conservative rules
whose justification is narrower than their test.

### 18.2 Still open

- **The `$GLOBALS['x']` view and a `global $x` binding disagree about the repr
  of the SAME cell.** The view is lowered `cell` unconditionally
  (`LowerSuperglobals::lowerGlobalsRead`); the binding is typed from the
  cross-scope join, which deliberately declines to record an INT because that
  is what a `global` decl is hard-lowered to anyway. Both currently read the
  slot RAW, so they agree by accident. Tagged arithmetic breaks that accident —
  `$GLOBALS['n'] = $GLOBALS['n'] + 1` would box into a slot the other scope
  reads raw — so `arithType` carves the view out and keeps the integer path
  there. Closing it means picking ONE repr for the cell and making both ends
  say so, INCLUDING `__main`'s seeding store (`$counter = 7`), which writes the
  slot before any other scope runs. That is a global-storage change, not an
  arithmetic one, and it is the last known place where one slot still has two
  representations.
- **`plausiblePtrIr` still dereferences an unvalidated word** at ~10 call sites
  (the erased-`foreach` classifier, `is_array` on an erased operand, the memory
  passes). The two fixes above stop FEEDING it raw ints, which is why the
  crashes are gone, but the predicate itself is unchanged: any erased raw word
  in `(65535, 2^48)` is still dereferenced. Hardening it means bounding it by a
  real allocation range — the pool already brackets its region with
  `@__mir_pool_base`/`@__mir_pool_top`, but large blocks bypass to `malloc` from
  ~14 sites, so a watermark needs a single allocation choke point first.
- The type split of §17.2 is NOT done and, on these numbers, is not worth doing
  for the stdlib boundary alone. Revisit it only if a new producer of
  "raw array, element unknown" appears.

## Related
- `is_callable` pin, offset-16 crash diagnostics, prior reverted attempts:
  memory `unknown-receiver-propread-offset16-2026-07-08`.
- array value-semantics symptoms (clone/COW/bare-append): memory
  `array-value-semantics-cluster-2026-07-08`.
- why the cell path blocks int-overflow→float, and the VRA parked on top of it:
  memory `vra-and-cell-soundness-2026-07-16`.
