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

## Related
- `is_callable` pin, offset-16 crash diagnostics, prior reverted attempts:
  memory `unknown-receiver-propread-offset16-2026-07-08`.
- array value-semantics symptoms (clone/COW/bare-append): memory
  `array-value-semantics-cluster-2026-07-08`.
