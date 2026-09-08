# W3 step 2 — the front end

**Spec:** [`docs/design/backend-and-build-strategy.md`](../../design/backend-and-build-strategy.md) §4 W3,
continued from [`2026-09-08-w3-object-cache.md`](2026-09-08-w3-object-cache.md), which ended with
the front end as the floor (~29 s of a 38 s incremental rebuild).

## Where the front end goes (the compiler's own module, 4864 functions)

`InferTypes` runs **twelve times** and is essentially the whole front end:

| phase | ms |
|---|---|
| parse | 830 |
| LowerFromAst | 1 102 |
| InferTypes #1 | 2 469 |
| NarrowReturns (concreteOnly) — 2 rounds | 3 196 |
| InferTypes #2 / #3 | 1 591 / 1 567 |
| Monomorphize — 3 internal rounds | 5 011 |
| NarrowReturns (full) — 4 rounds | 6 911 |
| everything else | < 200 |

## ★ The finding: it is not the OUTER rounds, it is the INNER rescans

`MANTICORE_STATS=1` counters for one build:

```
infer.rescan_calls           100
infer.rescan_functions   197 770      ← function bodies re-inferred
infer.rescan.byref_elem       12 calls / 58 801 fns / 2 659 ms
infer.rescan.callsite_array   12 calls / 58 801 fns / 2 657 ms
infer.rescan.doc_list_key      1 call  /  4 864 fns /   221 ms
infer.rescan.prop_elem         1 call  /  4 864 fns /   227 ms
infer.rescan.static_prop_elem  1 call  /  4 864 fns /   226 ms
```

Each `InferTypes::run` does its main pass and then **re-infers the whole module again, twice**,
because `scanByRefElemWiden` and `scanCallSiteArrayElems` widened *some* parameter and the
current answer to "who is affected" is "everyone". That is ~5.3 s of a ~24 s front end, in two
call sites, with no soundness question attached — the scans already know which functions they
touched, they simply do not say.

**The work:** have each scan return the touched function names and pass them as the `$only`
argument `inferFunctionsForScope()` already accepts, widened by the callers of those functions.
Acceptance is exact: the emitted `.ll` must be byte-identical to a build without the change.

## What landed here (the detour that had to happen first)

Targeted inference (`MANTICORE_WORKLIST=on`) existed and had never once engaged. Three fixes:

1. ★★★ **`DependencyIndex` carried ZERO call edges.** `collect()` read `$node->function` off a
   variable declared `Node` with only a `@var Call` docblock — which Zend honours by looking the
   property up by name and the native build does NOT, because it resolves by OFFSET off the
   DECLARED type. Every callee name was garbage, nothing matched a module function, and the
   index still looked healthy (`would-target=733`) because method dispatch is keyed separately.
   Narrowing through `asCall()` / `asMethodCall()` took it to `edges=3358`, `would-target=1107`.
   **This is the third time this shape has cost a day** — see the `DynProp_` note in
   `emitInvoke` and the `asSpreadNode` fix in W2.
2. **A barrier is no longer a global veto.** One method call anywhere set `barriers` non-empty
   and forced `fallback=yes` forever. A barrier is a property of ONE function, so the escaper
   set (2343 of 4864) is folded into the SCOPE instead — sound, because those are exactly the
   functions whose dependencies the index cannot see.
3. **Prelude bodies are scanned** by both the index and the barrier scan. An unscanned function
   is neither an escaper nor edge-connected, i.e. invisible to both halves of the decision.

**And the honest result: targeted inference still does not pay.** With the scope sound, ON is
33 s against OFF's 31 s on the same module. The reason is measurable: a full "closing" round
after an apparent convergence still narrows 8 functions the scope missed
(`narrow: MISSED BY SCOPE …`, stats-only), and each closing round costs a full inference.

The missing edge is the **parameter-refinement direction**: `InferTypes` refines a parameter
from its CALL SITES, so a change in a caller's argument types must invalidate the callee — but
`ChangeSet` records only RETURN-type changes, so that direction is invisible. Closing it needs
`InferTypes` to report which functions' types actually moved, not just which returns narrowed.

`MANTICORE_WORKLIST` stays opt-in. Box counts are identical between ON and OFF (10 091), so the
closing round does its job; the IR still differs by 0.02%, which is the remaining gap.

## Tried and REVERTED: rescanning only what the scan touched

The obvious version of step 1 — each scan records the functions whose type it moved, the
rescan runs over exactly those — **does not type the program correctly**. Both scans were
instrumented (three change sites in `scanCallSiteArrayElems`, one in `scanByRefElemWiden`) and
the rescan scoped to that set; the compiler then refused its own source:

```
error: … cellPropertyReadHelper() — array KEY repr conflict — a string-keyed array read as
int-keyed walks the key as the wrong type (assoc[string, obj<EnumDef>] given, vec[obj<EnumDef>]
expected)
```

Retyping a parameter of F changes what F RETURNS and what F stores, so the callers and the
callees have to be re-inferred with it. The touched set alone is the same mistake the
`MANTICORE_WORKLIST` scope makes, in a smaller place: **the affected set is at least
touched ∪ callers(touched) ∪ callees(touched)**, and InferTypes has no call graph to ask.
So step 1 is not a two-line change — it needs `DependencyIndex` (or an equivalent) available
inside `InferTypes`, which is the same prerequisite step 2 has.

⚠ The failed build POISONED `bin/manticore` (it compiled the reverted source into the same
error, so `bin/build` could not recover on its own). `cp bin/.manticore.prev bin/manticore`,
verify `preg_match` answers 1, rebuild — exactly the documented recovery.

## LANDED: the rescans go through a call graph

`InferTypes` now builds a `DependencyIndex` ONCE per run (lazily, on the first scoped rescan)
and each scan reports the functions it retyped. The rescan set is that closure —
`DependencyIndex::invalidate()`: transitively the callers, plus one hop of callees — which is
what the touched-only attempt was missing.

| counter | before | after |
|---|---|---|
| `infer.rescan_functions` | 197 770 | **97 089** |
| `infer.rescan.byref_elem` | 58 801 fns / 2 659 ms | **8 291 fns / 930 ms** |
| `infer.rescan.callsite_array` | 58 801 fns | **8 609 fns** |

Phases (compiler own module): InferTypes#1 2469 -> 2260 · #2 1591 -> 1339 · #3 1567 -> 1341 ·
NarrowReturns(concreteOnly) 3196 -> 2725 · Monomorphize 5011 -> 4249 · NarrowReturns(full)
6911 -> 5615. **Emission starts at 20.1 s instead of 23.4 s — the front end is ~14% off.**

**Acceptance, honestly:** NOT byte-identical. On the fixed t1 corpus the IR is 14 793 855 ->
14 780 768 (-13 KB) with **22 of 4489 definitions changed** and `box` / `unbox` / cell counts
IDENTICAL (6704 / 1184 / 1429). The changed bodies are SHORTER — the diff on
`mb_convert_case` is redundant static-local load/store pairs going away — so the fixpoint
converges somewhere at least as good, not worse. Suite 1061/0/1063.

## Order

1. Scope the two big internal rescans (`byref_elem`, `callsite_array`) — ~5.3 s, no soundness
   question, byte-identical acceptance test.
2. Then, if the front end still dominates: make `InferTypes` report a real changed set and
   revisit `MANTICORE_WORKLIST`.
