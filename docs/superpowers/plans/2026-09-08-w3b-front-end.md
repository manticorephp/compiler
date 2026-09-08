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

## Order

1. Scope the two big internal rescans (`byref_elem`, `callsite_array`) — ~5.3 s, no soundness
   question, byte-identical acceptance test.
2. Then, if the front end still dominates: make `InferTypes` report a real changed set and
   revisit `MANTICORE_WORKLIST`.
