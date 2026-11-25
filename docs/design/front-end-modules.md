# Design: modular front end — summaries, cache, parallel inference

Status: **step 1 of #817: measurement + proposal.** Builds on
[`inference-fixpoint.md`](inference-fixpoint.md) (slots, recorded dependencies,
semi-naive scans); read that first.

_2026-11-25, branch `perf/fe-modules` off `4004167f5`, macOS arm64. The host was
shared with a native-backend build (load average 5–13), so every number below is
from repeated runs and reported as CPU time. The front end is single-threaded, so
CPU ≈ wall for it._

## Summary

- The self-compile front end (parse → `Verify`) costs **35–36 s CPU**, plus ~5 s
  for `EmitLlvm` (40.5 s user to the end of IR emission). That is half the ~70 s
  quoted in #817. The 70 s presumably counts later stages or a loaded `-j 9`
  run. Peak RSS at `Verify` is 915 MB.
- **71% of it is 12 full `InferTypes` runs** (≈25 s). `NarrowReturns (full)` alone
  is 19 s (54%), with 9 full runs. Five of those runs find nothing to narrow. They
  only carry a widening one property hop per run.
- **Namespace modules are no unit of inference for this tree.** All 34 namespaces
  that hold code form one SCC containing 100% of the lines. At class level the
  largest SCC is 120 classes and 66% of the code (embedded stub data excluded). At
  **function level**, on the call graph resolved after inference, the largest SCC
  is 647 of 9 548 functions (6.8%) and the DAG is 24 components deep. Summaries
  and caching have to work per function. A module can be the storage unit (a file
  of summaries), not the inference unit.
- One clearly safe cheap win landed: −1.5 s (4%), with byte-identical IR. Three
  bigger ones are identified below, all inside inference.

## A. Where the time goes

`MANTICORE_PHASE_TRACE=1 bin/manticore build --apps-only <manifest>` (manifest =
`manticore.json` with the application output redirected). The build was killed
after the `precision:` line, which follows `Verify`. `MANTICORE_STATS=1` adds the
per-run split (`infer run: pre-scans/walk/post`).

| Pass | CPU s | Share | Full `InferTypes` runs | RSS after |
|---|---:|---:|---:|---:|
| prelude demand + parse | 1.97 | 5.6% | | 81 MB |
| LowerFromAst (+ Reachability 0.16) | 2.30 | 6.6% | | 570 MB |
| ConstFold, DeadStore, VivifyRefArgs | 0.40 | 1.1% | | 604 MB |
| InferTypes #1 | 2.43 | 7.0% | 1 | 603 MB |
| NarrowReturns (concreteOnly) | 2.28 | 6.5% | 1 (+1 light) | 614 MB |
| InferTypes #2 | — | | skipped (`moduleTyped`) | |
| InlineClosures | 0.09 | 0.3% | | |
| InferTypes #3 | 1.88 | 5.4% | 1 | 624 MB |
| Monomorphize | 0.92 | 2.6% | 4 light rounds | 637 MB |
| FuseSplitJoin, TypeCheck | 0.18 | 0.5% | | |
| **NarrowReturns (full)** | **19.06** | **54.5%** | **9** (+8 light, 21 narrow rounds) | 750 MB |
| CheckTypeDefs … ApplyMemoryMode | 0.56 | 1.6% | | |
| SpillFreshBases (escape summaries 0.43) | 0.84 | 2.4% | | 765 MB |
| InsertMemoryOps | 0.02 | | | |
| OwnershipFlow | 1.76 | 5.0% | | 915 MB |
| Verify | 0.12 | 0.3% | | 915 MB |
| **through Verify** | **34.96** | 100% | **12** | 915 MB |
| EmitLlvm (streamed, to `IR:`) | ~5.1 | | | 1.2 GB |

Base binary with PGO, run 1. Non-PGO binaries of the same commit: 36.2 and 35.9 s.
Inside one full run: pre-scans ≈ 250 ms, **walk ≈ 660 ms**, post-walk scans and
re-inference 0.75–1.7 s, ≈ 1.9–2.7 s in all. Across the 12 runs the walk is ≈ 8 s
and scans plus post-walk re-inference ≈ 17 s, the same shape the fixpoint doc
found on symfony-app.

### The convergence tail

`MANTICORE_NARROW_MOVED=1` (added here) prints what each closing run moved:

| Full run in NarrowReturns (full) | Narrowed before it | Functions it moved |
|---|---:|---:|
| 1 | 196 | 869 |
| 7 (after 5 light rounds) | 0 | 348 |
| 11 | 0 | 46 |
| 13 | 0 | 8 |
| 15 | 0 | 2 |
| 16 | 0 | 8 |
| 17 | 0 | 12 |
| 19 | 0 | 13 |
| 20 | 0 | **0**, fixpoint |

From run 11 on, nearly every moved node is the same pattern:
`property_access : vec[obj<Parser\Ast\Expr>] -> vec[cell]`, then
`store_property` in an AST constructor, then the factory's `array $args`
parameter (`Expr::methodCall`, `Expr::staticCall`, `DynamicStaticCall::__construct`),
then the readers in `Analyze\AstWalk`, `LowerFromAst::glScanExpr`, … An erased
argument widens a bare-`array` parameter. The parameter's store widens a property
in the next run's pre-scan, and the property's readers move one run later still.
**One hop per full run, ≈ 2 s a hop.** Runs 15–20 (5 runs, ≈ 9.4 s, 27% of the
front end) narrow nothing. They exist only because the previous run's fingerprint
moved (`needsFullInfer` after a light Monomorphize round).

### Hot functions

`sample` at 1 ms on a `MANTICORE_FRAME_POINTERS=1` build. Runtime helpers are
charged to their nearest PHP caller (scratch scripts walking the call tree; the
caller fold of `tools/prof/report.php callers` mis-charges ~40% to
`LowerFromAst::lowerExpr` on this binary).

| Self, PHP level | Share of front end |
|---|---:|
| `InferTypes::bodyHas` (+ `collectBodyKinds`) | 6.9% (+2.1%), **fixed below** |
| `InferTypes::mergeLocals` | 5.6% |
| `InferTypes::inferTernary` | 4.0% |
| `DependencyIndex::collect` (Monomorphize, light rounds) | 2.4% |
| `InferTypes::fpCallTypes` (fingerprints, 2 per closing run) | 2.2% |
| `inferIf`, `slotView`, `walkLocalFacts`, `scanLocalShapes`, `inferNode`, `collectScanNodes`, `bodyNodesOf` | 1.6–2.2% each |

At runtime-helper level, string-keyed hashing (`__mir_array_index_find`,
`set_str`, `hash_str`, `lookup_str`, `str_eq`) is ≈ 26%. The `Walk::children`
vdispatch is 7.4% and rc/release ≈ 7%. The profile is flat. No single function
pays back a redesign, which confirms the fixpoint doc: the cost is in the
**number of whole-module runs**, not in any one step.

## B. The dependency graph

`php tools/module_graph.php --roots=src --edges=40 src prelude` (new). Edges come
from tokens: extends, implements, trait `use`, `new`, static calls, type hints,
`instanceof`, `::class`, function calls and docblock tag types. The graph is
restricted to what `src/` reaches. Function level comes from the resolved call
graph `EscapeSummaries` builds after inference, printed by the new
`call-graph sccs:` stats line.

| Self-compile (src + reachable prelude, 316k lines by token span) | |
|---|---|
| Namespace modules | 39 (34 hold code) |
| Module SCCs | 6; **one holds 34 modules = 100% of the lines**; critical path 2 |
| Classes and free-function files | 611 units, 5 117 edges, 283 SCCs |
| Largest class SCC | **120 classes, 158k lines**: 50% of all, **66% without `EmbeddedStubs`** (75k lines of embedded stub data, a leaf) |
| Class critical path | 15 SCCs, 74% of lines on it |
| Functions (resolved call graph) | 9 548 functions, 8 367 SCCs |
| Largest function SCC | **647 functions (6.8%)**; 1 244 (13%) in any cycle |
| Function critical path | **24** components |

The module cycle is closed by a handful of upward edges. Each would be cheap to
cut for layering, but inference does not care about layering, see below.

- `Compile\Mir → Manticore` (`CompileArgs`)
- `Compile → Cli` (`Progress → Cli\Style`)
- `Lexer → Parser` (`ParseError`)
- `Parser\Ast → Analyze` (`Param → DocType`)
- `Compile\Mir → Compile\Mir\Passes` (`FunctionEmitFrame → OwnershipFlow`)
- `Analyze`, `Semantic`, `Lsp → Main.php` free functions

The class SCC is the compiler proper: `LowerFromAst` + `Lower*`, `InferTypes` +
`Infer*`, `EmitLlvm` + `EmitLlvm*` (traits on one host), `RuntimeLibrary`,
`UnifiedArrayRuntime`, `Main.php`.

| symfony-app (src + vendor + public, 126k lines) | All files | Reachable from src/public |
|---|---|---|
| Modules / largest module SCC | 181 / **54 modules, 45%** (next: 26 modules, 21%) | 120 / 16 modules, 26% |
| Largest class SCC | 103 classes, **11.8%** | 19.1% |
| Class critical path | 24 SCCs | 24 |

**What this decides.**

1. A namespace module cannot be the inference unit for the compiler: every edit
   would re-infer everything. For symfony it would re-infer half.
2. A class is not much better: one SCC holds two-thirds of the compiler.
3. The function graph is fine-grained and shallow: 87% of functions are
   singleton SCCs, and 24 levels deep means wide levels for parallel work.
4. So summaries, the cache key and early cutoff are **per function (per slot)**,
   as in HHBBC and the fixpoint doc. A namespace module or file is only the unit
   of storage and of source hashing.

Caveat: the token graph misses `$x->m()` on an untyped receiver. Inference
resolves those by method name across classes (the fixpoint doc measured name-keyed
edges reaching 16–21k of 36k functions on symfony-app). The real *static*
dependency set is denser than the table. The *recorded* one (what inference
actually read) is what the cache must key on.

## C. Cheap wins

**Done: `InferTypes::bodyHas` reads its set in place** (`befe901c9`). Before, it
copied the per-body kind set out of `bodyKinds` on every call. Result: −1.5 s of
36 s (two interleaved runs each way: 36.2/35.9 → 34.9/34.1 s). Emitted IR of
`src/` (`dump-llvm-mir src`, 313 MB) is byte-identical.

**Identified, not done.** Each changes the order in which the fixpoint is reached,
so output identity has to be proven, not assumed.

1. **Convergence tail, −9.4 s (27%).** Close the property hop inside a run. When
   a post-walk scan widens a parameter that a `store_property` reads, re-run the
   property-from-stores scan (`scanCellElemProps`, `scanPropElemFromStores`) and
   re-infer the readers in the same run (`inferAfterPropScan` already does this
   for `prop_elem`). Alternatively, make the closing runs scoped to what the last
   run moved plus its readers, with one full verification run at the end, which
   is phase 2 of the fixpoint doc. The trigger is `needsFullInfer`
   (`Monomorphize::lightRoundRan`): runs 15–20 exist only because of it.
2. **Closing full runs after light rounds, up to −6.9 s.** Runs 7, 11 and 13 close
   light rounds with a full inference. With recorded dependencies they become
   targeted re-inference.
3. **bodyKinds is rebuilt in every run** (`collectBodyKinds` ≈ 1.2 s after the
   fix). It could be kept across `InferTypes` instances, keyed by body identity and
   `bodyGen`. That is only sound once every pass that mutates a body bumps
   `bodyGen`, which is not true today.
4. **Per-function passes after the last `InferTypes`.** `InferAllocKind` 0.24 s,
   `ApplyMemoryMode` 0.10 s, `SpillFreshBases` 0.84 s (needs module-wide escape
   summaries first), `InsertMemoryOps`, `OwnershipFlow` 1.76 s and `Verify` add up
   to ≈ 3.1 s. `OwnershipFlow` is a per-function loop over module-wide read-only
   tables. These passes mutate MIR in place, though, and there is no MIR
   serialisation to ship results back from a fork. They only pay in fork workers
   **fused with emission**: each worker runs the per-function tail plus `EmitLlvm`
   (or native lowering) for its slice and returns IR or objects. Expected
   −3 s + most of emit's 5 s on 9 cores. Not trivial, so not done this round.

Not a win: forking `InferTypes` itself as it stands. Its post-walk scans are
module-wide and order-dependent (first observation wins), so it needs step 3 of
the plan first.

## D. Design

### What crosses a boundary

A function's inference reads **slots** (fixpoint doc): returns, parameters,
properties, static properties, globals, closure captures and class facts. Today
these facts flow across files in both directions:

| Flow | Direction | Today |
|---|---|---|
| Return type | callee → caller | `sigs`, `NarrowReturns` |
| Bare-`array` parameter element from call sites | **caller → callee** | `scanCallSiteArrayElems`, `scanDocListKeyPromote` |
| By-ref parameter retype and ref-cell argument widening | both, iterated | `scanByRefParamRetype`, `scanRefCellArgWiden` |
| Property type and element from stores anywhere | **storer → owner** | `scanPropElemFromStores`, `scanCellElemProps`, `scanAssocProps`, `scanCtorPropContainers` |
| Static property, global | storer → owner | `scanStaticPropElemFromStores`, `globalVarTypes` |
| Instantiation per argument shape | **caller → callee clone** | `Monomorphize` |
| Overload choice | callee set → caller | `ResolveOverloads` (read-only on callee signatures) |
| Method family (overrides, interfaces) | class → every dispatcher | `resolveMethodClass`, `interfaceAncestors` |
| Effects (throws, alloc kind, escape, park) | callee → caller | `InferEffects`, `InferAllocKind`, `EscapeSummaries`, `NothrowSummary` (already SCC summaries) |

A function's summary therefore has two halves:

- **Exports**: the slot values it owns (return; params once settled; for a class,
  property types and the override family) and its effects.
- **Contributions**: facts it asserts about slots it does *not* own. These are
  the argument shapes it passes to each callee parameter, the values it stores
  into each foreign property or static or global, the by-ref uses, and the
  instantiations it requests (callee + shape key, the same key `Monomorphize`
  uses, owned by the requester as in `shared-generics.md`).

A slot's value is the join of its owner's own facts and all contributions to it.
This is what makes the reverse flows explicit, which #817 point 4 asks for. They
become ordinary inputs, not whole-program scans.

### Granularity and SCCs

- The inference unit is the **function**. Its SCC on the *recorded* dependency
  graph is the unit of joint iteration: a cycle of mutually recursive methods is
  iterated to a local fixpoint against frozen summaries of everything outside it.
  The largest such SCC today is 647 functions. That is a few hundred ms of walk at
  ~66 µs per function per run, so cycles do not threaten warm builds.
- A **file** (one class) is the storage unit: one summary file holding its
  functions' exports and contributions, keyed by the source hash.
- Properties couple all of a class's methods, plus every foreign storer. A
  property slot is its own node, owned by the class, with contributions as
  in-edges.

### How the consumers read summaries

- **`NarrowReturns`** disappears into the fixpoint (phase 5 of the fixpoint doc):
  a return is the join of the body's returns, published as an export. A caller
  reads it from the callee's summary. A change wakes readers only if the value
  changed (early cutoff).
- **`Monomorphize`** reads callers' requested instantiations (contributions keyed
  by callee + shape). The clone's name is derived from the shape, never from a
  counter, so a clone is reusable across builds. A clone is inferred as a function
  of its own, whose parameters are fixed by the key.
- **`ResolveOverloads`** reads only callee signatures (exports) plus the caller's
  own argument types, so it is local once the argument types are.
- Effects (`InferEffects`, `EscapeSummaries`, `NothrowSummary`) are already
  callee-first SCC summaries. They move into the same per-function summary file
  unchanged.

### Early cutoff and the cache key

- Key = hash(function body after lowering) + values of the slots it **read** during
  its last inference, recorded at lookup time (fixpoint doc: recorded dependencies,
  HHBBC `add_dependency`). Name-keyed guesses are not used.
- After re-inference, the new exports and contributions are compared structurally.
  If they are unchanged, nothing downstream wakes (Salsa backdating).
- Because the fixpoint's path depends on order, cache **final** slot values and
  check them with one read-only round over the re-inferred set. That round must
  move nothing (warm == cold identity test).

### Parallelism

- **Walk**: functions in one level of the SCC DAG are independent against frozen
  summaries. There are 24 levels for 9.5k functions. The walk is ≈ 0.66 s per run
  today, so this is secondary.
- **Scans**: the 45 `scan*` methods have to become per-function fact extraction
  plus an ordered reduce (fixpoint doc phase 3). Extraction then parallelises; the
  reduce keeps module order where a scan keeps the first observation.
- **Post-inference tail and emit**: fork workers per function slice, fused (see
  C.4).

## Expected gains

Derived from A and B. Estimates, not measurements.

| Step | Cold front end (35 s today) | Warm, one-method edit, same signature |
|---|---|---|
| bodyHas (done) | −1.5 s | — |
| Convergence tail closed in-run | −9 s → ≈ 25 s | — |
| Recorded deps: closing runs targeted | −5…7 s → ≈ 18–20 s | — |
| Per-function scans + parallel extraction | −3…5 s (scans ≈ 17 s over all runs today) | prerequisite |
| Fused tail + emit in workers | −3 s, plus emit 5 s → ~1 s | — |
| Function summaries + cache | — | re-infer the edited function's SCC (median 1, max 647 functions, < 0.5 s) + one verification round over woken readers; parse/lower of that file only (parse 2 s and lower 2.3 s are whole-program today, so they need per-file caching too) |

The #817 target (cold ≤ 20 s on 9 cores) is reachable with the first three rows
alone. The warm target (seconds) needs the summary cache *and* per-file
parse/lower caching. Inference would be ~10% of a warm build once the full runs
are gone.

## Risks

- **Byte-identity versus order dependence.** Several scans keep the first
  observation and fold in module order, and the closing rounds are load-bearing
  (fixpoint doc). Any reordering, from in-run propagation or a cache, can reach a
  different fixpoint. Every step is gated on the emitted IR of `src/`, the AOT
  suite and symfony-app being byte-identical, not just green.
- **Non-monotone steps.** `NarrowReturns` narrows downwards while properties
  widen upwards. Generic returns and withdrawn doc claims move against the
  lattice. A cached final value is only valid if the verification round is
  mandatory.
- **Name-keyed dispatch.** An untyped receiver depends on every class declaring
  that method name. Contributions to a method family must be per family
  (HHBBC `FuncFamily`), or one edit wakes most of the program.
- **In-place mutation during inference** (box-back stores, entry stores) has to be
  moved out (fixpoint phase 4) before a cached body can be trusted.
- **Precision sources show up as cost.** The tail in A is sound widening caused by
  untyped factory parameters (`array $args` with no `@param Expr[]`) meeting one
  erased argument. Better docblocks in `src/Parser/Ast` would shorten the
  self-compile tail but change its output, so they are a separate change. User
  code has the same shape.
- **Measurement.** The ~70 s in #817 does not match the 35–40 s measured here.
  Re-measure on an idle host before committing to targets.

## Results

### Stage 1: the property hop closed inside a run

`InferTypes::$closePropHops`, set only by the late `NarrowReturns`: after the
post-walk scans, `scanCellElemProps` (re-infer the readers of what it retyped) and
`scanCallSiteArrayElems` (re-infer what it widened) re-run until neither moves.
The hop that took one full run each now closes in the run that started it.

- **Only the late pass.** Enabled from `InferTypes #1` on, the earlier widening
  reached `Monomorphize`, which then cloned 12 more functions (9 900 → 9 912) and
  the program grew ~800 more `cell` nodes. A widening that arrives a run sooner is
  a different input to a non-monotone step, so the tail stays after the last
  clone decision.
- **The output did differ at first, in one function.** `LowerFromAst::run` lost
  two dead loads: one box-back fewer. Cause: a box-back (`$x = $x` stored cell,
  `InferTypes::planMergeShadow`) is sticky by design (`declaredType`). A
  merge that disagreed only in an *intermediate* run of the fixpoint leaves one
  behind, and once the local settles on cell it boxes nothing. How many such
  leftovers a body carries depends on the path to the fixpoint, so any change in
  the number of runs changes the IR. `InferTypes::dropSettledBoxBacks` removes
  them once after the late `NarrowReturns` (a store typed cell, declared cell,
  over a read of the same local that is cell). On `src/` that is ~675 no-op
  stores. It is a precondition of every later stage: none of them can be
  byte-identical without it.
- **Proof.** One binary with temporary switches for both halves, same `src/`:
  `dump-llvm-mir src` (313 MB) with the tail on and off is byte-identical once
  the leftovers are dropped (`c9087f54…` both), and so is a full typed-node dump of
  every function plus every property type. Without the drop the two differ, as
  above. The final binary matches the switched one with the tail off
  (`5eba6657…`). `bin/build` ×3: gen1 = gen2 = gen3 (`c716dd53`).
- **Gates.** `tests/aot/run.sh -k` infer 2, narrow 14, generic 17, closure 120,
  return 75, box 15, merge 19, cell 164, foreach 90, loop 45: all pass.
- **Time** (`build --apps-only` of the compiler, `MANTICORE_OBJ_CACHE=0`, `-j 9`,
  base = parent commit, non-PGO both, A B A B, best of 2, load average 16–37):

| | base | stage 1 | Δ |
|---|---:|---:|---:|
| NarrowReturns (full) | 17.5 s | 10.9 s | −6.6 s |
| front end, to `Verify` | 32.7 s | 26.3 s | −6.4 s |
| total CPU (user) | 284 s | 275 s | −9 s |
| wall | 77.8 s | 70.6 s | −7.2 s |
| peak RSS | 2 774 MB | 2 686 MB | −88 MB |

  Four full runs remain in the late pass (rounds 1, 7, 11, 13), against nine.
  The tail itself costs ≈ 1 s in rounds 1 and 7. `--backend=native` is not on
  this branch (no native backend on `main` yet); the front end it would share is
  the one measured here.

## Staged plan

0. **Measure** (this doc): `MANTICORE_NARROW_MOVED`, the `call-graph sccs:` stats
   line, `tools/module_graph.php`. Plus the bodyHas win.
1. **Close the convergence tail inside a run** (C.1). Gate: IR identity on `src/`,
   suite, symfony-app. ≈ −9 s.
2. **Recorded dependencies, shadow mode to zero misses, then targeted closing
   runs** (fixpoint phase 2). ≈ −5…7 s.
3. **Per-function fact extraction for the scans** (fixpoint phase 3), with
   `MANTICORE_SCAN_VERIFY` as the guard. Unlocks parallel extraction and caching.
4. **Move mutation out of inference** (fixpoint phase 4).
5. **Function summaries (exports + contributions) and the on-disk cache** per
   file, with a mandatory verification round. Warm == cold identity test on
   `src/`, tests/aot and symfony-app (#817 acceptance). Also needed by #815
   (vendor summaries in `.sig`).
6. **Parallel**: fork workers for extraction and for SCC-level walks. Fused
   per-function tail + emit in workers, which serves both backends (#814).
