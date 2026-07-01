# Handoff — library perf + the representation epic (2026-07-01)

Everything below is **committed and green**. Two threads remain: finishing the
representation epic **(a)** and the codegen-builtin perf work **(b)**. Both are
deep/risky — start fresh, with the full gate budget, not at the tail of a long
session. This session's 100 GB memory leak came from a deep change made tired.

## State — all green
- Branch `main`, HEAD `ea2eb8e`. Working tree clean.
- Gates: `bash tools/selfhost_fixpoint.sh` → FIXPOINT OK, suite **359/359**,
  stability 5×2. `bash tools/difftest.sh` → **350 / 0 / 0**.
- Build: `bin/build` (self-host). `bin/build --seed` = clean Zend cold bootstrap
  — **use it whenever the current binary is suspect** (a reverted *source* does
  NOT fix a bad *binary*; rebuild). Emitter changes need **2 gens** to reach
  `stdlib.o` (json_encode etc.); user-program emission needs 1.
- Benchmarks: `bash bench/run.sh` (best-of-N; `REPS=5`). All cases are
  data-dependent + `$argc`-seeded so LLVM can't fold the loop — see
  [[bench_suite_2026_06_30]].

## Perf snapshot (best-of-5, honest/non-hoistable)
Compute/algorithms strong: spectralnorm 44× · oop 28× · fib 19× · closures 18× ·
mathf 14× · loop 11× · sieve/matmul/dijkstra 7–9× · array 4× · strcat 3×.
Library tail: funcarr 8× · wordcount 7× · strops 2.7× · sort/sprintf 2.2× ·
explode 1.0× · **json 0.6×** (correct now; the one native loss).

## (a) Representation epic — the deep root, PARTLY done
Root: `unknown = raw` is a **load-bearing invariant** — arithmetic reads raw ints
directly, so you CANNOT broadly box unknown-element arrays (proven: it broke the
whole suite; a raw int read back as a boxed cell = `-4222124650659837`). Mixed
arrays that store non-int values RAW then read them as garbage are the recurring
bug (edge1, het-array, json mixed arrays). Full notes:
[[representation_consistency_root_2026_06_30]].

**DONE (`ea2eb8e`):** stable-local mixed arrays. `$r=[1,2]; $r[0]="a"` and
`json_encode` of a mixed array now correct. `scanAssocLocals` seeds the
value-class set from an array-LITERAL StoreLocal too (not just store_element), so
literal `num` + store `string` = 2 classes → cell element. Homogeneous arrays
untouched (matmul still 7×).

**DONE (`402b6f6`): dynamic nested-subscript array.**
```php
$a = []; $a["k"] = []; $a["k"][] = "v"; echo $a["k"][0];   // now: v
```
`scanAssocLocals` tracks `emptyArrValLocals` (`$a[k] = <empty []>`) +
`nestedScalarStoreLocals` (`$a[k][…] = scalar-LITERAL`, i.e. `$se->array` is an
ArrayAccess of `$a` and the value's `coarseValueClass` is num/string/bool/null).
Both set → `nestedCellVecLocals[$a]`, value element promoted to `vec[cell]`; held
across the outer `[]` binding (inferStoreLocal) + store refinement
(inferStoreElement). Matmul untouched: its inner value is a VARIABLE (`$m[$i]=$row`,
class `''`) and its nested store is a COMPUTED expr (`$m[$i][$j]=$i*3+$j`, class
`''`) — neither gate fires, so no boxing (matmul still 7×). Test
`nested_subscript_mixed`. Gate: FIXPOINT OK, suite 360/360, difftest 351/0/0, stab 5×2.
LIMIT: only literal-valued nested stores promote; a nested store of a VARIABLE
string (`$a[k][]=$s`) still writes raw (class `''` → no trigger) — a deeper gap,
same shape as the general unknown-element problem.

## (b) Codegen builtin for json_encode (perf) — NOT started
`json_encode(scalar)` is 8× slower than php PURELY from the `mixed` dispatch
(box → is_null?/is_bool?/is_int?… chain → unbox). The bench (assoc) is dominated
by this per-value cost, not string building (string builders are now amortized —
see the self-concat fix `9178350`). String escaping is correct (`c775450`).
- Plan: `biJsonEncode($args)` in EmitLlvmBuiltins, dispatch on `$args[0]->type`:
  scalar (int/float/bool/null) → emit the literal JSON directly (int_to_str,
  "true"/"false"/"null", float repr); string → `"` + a `__mir_json_escape`
  runtime + `"`; concrete vec/assoc (non-cell element) → a specialized loop that
  encodes each element by its static type; CELL/mixed/unknown → fall back to the
  PHP `__mc_json_enc` (call `manticore___mc_json_enc`). Model on `biVarDump`
  (deep-box + call a prelude backend) and `biExplode`.
- Note: the PHP `__mc_json_enc` object/assoc branches do NOT escape KEYS yet
  (only string VALUES go through `__mc_json_escape`) — fix keys too.

## Gotchas that bit this session (READ)
- **macOS `ulimit -v` does NOT enforce.** To test a suspected leak: inspect the
  IR first (`dump-llvm-mir`, grep `__mir_str_append` vs `__mir_concat`), then run
  at a TINY N (≤5000, an O(n²) leak is still ≤ a few MB), only then scale.
- `rtk diff` AND `command diff` both false-pass here — compare with python/`cmp`.
- Emitter change → rebuild; if the binary might be bad, `bin/build --seed`.
- Self-host divergence is real: a change can be correct under the current binary
  but crash/miscompile the SELF-BUILT stage-2. Always build stage-2 + run the
  suite through it (the fixpoint gate does this) before trusting a codegen change.
