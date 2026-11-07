# Design: one inference fixpoint

Status: **proposal**. Replaces the round structure of `InferTypes`, `NarrowReturns` and the inference rounds of `Monomorphize`, and is the foundation incremental compilation (#498) needs.

_2026-11-07. Numbers are from `examples/symfony-app` (1154 files, 36k functions), macOS arm64, branch of PR #585, unless stated._

## The problem

Inference is still the largest part of a large build: about 51 s of CPU reach the end of `Verify`, with six full `InferTypes` runs of 4-15 s each. A one-line edit pays all of it, because the object cache only skips clang.

The cheap work is done (PR #585). What is left is flat in the profile and comes from the structure: analyses are separate passes that hand results to each other through whole-module re-runs.

## What was measured

| Experiment | Result | What it means |
|---|---|---|
| Callees-first SCC order for the walk | Same rounds, same narrow counts, 16 more `cell` nodes | Order alone cannot help: returns narrow in `NarrowReturns`, between runs, not during the walk |
| Existing scoped mode, `MANTICORE_WORKLIST=on` | ~55% more CPU, 218 more `cell` nodes | Do not build on it |
| `MANTICORE_INFER_DIFF`: full pass after a scoped one | 98→0 and 75→11→0→0 functions of 36k move, alongside 2, then 27, then 2 property retypes only the full pass finds | Divergence is narrow and appears to come through property slots |
| Interning shape `Type`s | CPU within noise, RSS not better | Not the bottleneck |
| Time inside each of the six full runs | Pre-scans 2 s, walk 6 s, post-walk 24 s in total | The walk over all functions costs ~1 s per run. **Almost all the time is after it** |
| Profile of `InferTypes::run` | `inferFunction` from post-walk re-inference 37%, from the walk 19%; scans ~40% | Re-inference triggered by scans and the scans themselves are the cost |
| Functions whose types change between runs | 257 to 4576 of ~34k (0.8-13%) | Scans mostly re-read bodies that did not change |
| Precise reads in shadow mode (`MANTICORE_DEPS_SHADOW`) | Slots that moved between runs have 3-1880 recorded readers out of 34k, against 16-21k targeted by the name-keyed edges; 4-260 changed bodies were not predicted, among 7-1812 changed | Resolved edges cut the fan-out by an order of magnitude. Misses seen so far are mostly property readers, where a scan retyped the property after the walk |
| Stopping `NarrowReturns` after one closing inference | One more inference still moved 9 returns, 1 param and 5 properties; on `main` it moves nothing | Closing rounds are load-bearing, not waste: a property stays narrower than its stores. Fixed; shadow mode now asserts the module ends at a fixpoint |
| Arrays with an `unknown` element | 17058 of 120940 erased (14%), 7004 of them literal `[]`; about 1071 local nodes have the same local concrete elsewhere | `bottom` has little to recover |

Scoped mode targets 16-21k of 36k functions per round (`would-target`), so its static dependency edges are too wide to save time. They are keyed by bare method name, and dispatch on a common name reaches much of the module.

## Root causes

**1. Two directions at once.** Returns start erased and narrow downward; properties start narrow and widen to `cell`. Neither pass reaches a fixpoint of the other, hence the rounds. A descending analysis is also pessimistic: it can only keep what its first, least informed answer allowed.

**2. `unknown` means two things,** "no information yet" and "erased", but this is minor. `Type::joinElement` already treats an `unknown` element as an identity, so for array elements it behaves as a bottom today, and few nodes depend on the difference (see the table).

**3. Static dependencies.** Edges are guessed from the shape of the code instead of recorded from what inference read.

**4. Scans re-derive facts.** About 40 module scans walk bodies to find store patterns, many followed by a re-inference. They are facts about slots computed outside the analysis that consumes them.

**5. In-place mutation during inference** (box-back stores). Earlier work found it makes a pass non-idempotent, which any cached or scoped result has to survive.

## Design

**Slots.** One table of everything crossing a function boundary: function return, parameter, property, static property, global, closure capture. Each holds a value in one lattice.

**One ascending fixpoint.** Every slot starts at a new `bottom`, distinct from `unknown`, and only moves up by `join`; declared types bound it from above. There is no separate narrowing pass, because a return is simply the join of what its body returns. This is the optimistic style of SCCP: a recursive function's return can settle on a precise type a pessimistic start would never recover, so precision should improve rather than merely hold.

**Recorded dependencies.** Inferring a function records each slot it reads, against the resolved target. HHBBC does exactly this (`add_dependency` from `lookup_return_type`, with a `Dep` bitmask of what was read): a virtual call depends on a `FuncFamily`, the set of overrides, and is woken only when that family's cached return changes. Nothing is guessed from method names.

**Read-only rounds, then an update step.** HHBBC's rounds (`analyze_iteratively`) analyse functions against a read-only index and apply results afterwards (`refine_return_info`, `update_func`), with the next round's work coming from `find_deps` on what changed. This also answers mutation during inference, and makes the walk parallelisable. SCC order is optional on top: opcache has none either (`zend_sort_op_arrays` is a stub), and our experiment showed none is needed to stop re-inferring.

**Early cutoff on the slot value.** Readers wake only when a slot's value changes, compared structurally, with the direction asserted (HHBBC `strictlyMoreRefined`) and a per-slot limit that widens at the cap. Salsa calls this backdating. Hashing bodies is the wrong key.

**Unseen writers, by what is unknown.** A write with a known property name and unknown receiver joins a per-name slot that every read of that name also reads; an unknown name (`$o->$n =`, `extract`, reflection `setValue`, a reference to a property) poisons every non-private slot to `cell`. This follows HHBBC's `mergeUnknownClass` and `mergeUnknown`. Slots start from the declared type, and only ones proven closed may start low.

**Scans become transfer functions.** A store to a property joins into that property's slot when the store is inferred, so each scan is removed as its fact moves into the walk.

**Monomorphize requests clones from the same queue.** A clone is a new function with its own parameter and return slots.

**Mutation moves out.** Box-back stores and similar rewrites run once after the fixpoint, from final types. Inference itself only writes node types and slots.

**Summaries, then #498.** A function's inputs are the slots it read, so body hash + the values of those slots key its output. Because a fixpoint's path depends on order, cache final slot values and re-verify with a read-only round that must move nothing. Vendor and stdlib get a higher durability tier, as in Salsa, and are skipped unless their hash changes. Salsa validates lazily by pull; a whole-program compile has no demand order, so here it would be one verification round.

## Plan

Every phase lands alone and must pass the full suite and difftest, with `precision:` no worse and CPU no worse.

The first version of this plan started by splitting `bottom` from `unknown`. Measurement rejected that, and also the idea that fewer walks are the main saving: the walk is about 19% of a run.

1. **Trim post-walk re-inference.** Done in part: propagation visited 10-40 times more functions than moved, and a body whose signature held still now wakes only its callees; settled element-as-key bodies are skipped. Together about 15% of CPU on symfony-app.
2. **Record dependencies in shadow mode.** Started: `MANTICORE_DEPS_SHADOW=1` builds the read table from final node types and reports what a re-run moved that no edge predicted. Target: zero misses, on symfony-app, Laravel and the demo. Next: separate misses caused by a retype after the walk, add `FuncFamily`-style nodes, and read at lookup time rather than from final types.
3. **Semi-naive scans, on top of those dependencies.** Split each scan into per-function fact extraction and a reduce, re-extracting only where an input changed. Gating on the function's own types is not enough: `scanRefCellArgNode` reads the callee's parameter types and body, and `scanByRefElemWiden` reads a module-wide map, so a stale skip misses a widening to `cell`, which is a miscompile. Scans also fold in module order, and some keep the first observation, so the reduce must preserve that order.
4. **Move mutation out of inference.**
5. **Replace `NarrowReturns` with the fixpoint for returns,** using the queue.
6. **Bring `Monomorphize` onto the queue.**
7. **Function summaries and the cache,** with a warm == cold byte-identity test (#498).

Phases 4-7 stay proposals: decide on them after 2 and 3 show what is left. Phases 2 and 7 are what #498 needs regardless of time saved.

## Risks

- **Monotonicity may not hold.** A retype that legitimately narrows something (a `declared` claim withdrawn, say) breaks termination. Phase 2 should count these; the fallback is a per-slot visit cap, and a capped slot goes to `cell`.
- **`unknown` is load-bearing.** `KIND_UNKNOWN` appears on 435 lines in the passes alone. An ascending fixpoint needs a real `bottom`, so this cost returns at phase 5.
- **Too-narrow types are miscompiles,** not just slowdowns: a raw word read as a typed value. An optimistic start has more ways to be wrong than a pessimistic one if a store goes unseen, so the "unseen writer" channels (references, dynamic properties, `extract`, reflection) must force their slots to `cell` up front.
- **Long-lived branches conflict.** `InferTypes` is touched by most bug fixes, which is why each phase is small and merges on its own.

## Expected gain

An estimate, not a measurement: scans are about 40% of the 32 s spent in full runs on symfony-app, so semi-naive scans could save up to roughly a third of that. Laravel is more post-heavy, so it should gain more. The precision benefit of an optimistic start is unmeasured, and the `unknown` numbers above weaken it for array elements.

## What this does not fix

Emit (about 20-25 s of CPU here), split and clang are unaffected, apart from what fewer boxes save. Most erased arrays stay erased: about half are in generated code that is erased by design, and in a sample of vendor locals 37% were truly heterogeneous.
