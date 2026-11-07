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
| Fewer closing rounds in `NarrowReturns` | 137 fewer `cell` nodes, CPU down | More rounds were not buying precision |
| Interning shape `Type`s | CPU within noise, RSS not better | Not the bottleneck |

Scoped mode targets 16-21k of 36k functions per round (`would-target`), so its static dependency edges are too wide to save time. They are keyed by bare method name, and dispatch on a common name reaches much of the module.

## Root causes

**1. Two directions at once.** Returns start erased and narrow downward; properties start narrow and widen to `cell`. Neither pass reaches a fixpoint of the other, hence the rounds. A descending analysis is also pessimistic: it can only keep what its first, least informed answer allowed.

**2. `unknown` means two things:** "no information yet" and "erased". This also shows up as `[]` being `vec[unknown]`, and a loop-carried first append keeps that type on its base load even when every store is a string.

**3. Static dependencies.** Edges are guessed from the shape of the code instead of recorded from what inference read.

**4. Scans re-derive facts.** About 40 module scans walk bodies to find store patterns, many followed by a re-inference. They are facts about slots computed outside the analysis that consumes them.

**5. In-place mutation during inference** (box-back stores). Earlier work found it makes a pass non-idempotent, which any cached or scoped result has to survive.

## Design

**Slots.** One table of everything crossing a function boundary: function return, parameter, property, static property, global, closure capture. Each holds a value in one lattice.

**One ascending fixpoint.** Every slot starts at a new `bottom`, distinct from `unknown`, and only moves up by `join`; declared types bound it from above. There is no separate narrowing pass, because a return is simply the join of what its body returns. This is the optimistic style of SCCP: a recursive function's return can settle on a precise type a pessimistic start would never recover, so precision should improve rather than merely hold.

**Recorded dependencies.** Inferring a function records each slot it reads. When a slot changes, exactly its recorded readers are re-queued, as in Salsa and rustc's query system. HHBBC, the closest precedent for PHP, also tracks dependencies per function (from memory, to be checked against its source before phase 2). Nothing is guessed from method names; a dispatch reads the slots of the candidates it actually resolved.

**SCC order as a scheduler.** The queue is ordered callees first, by a graph that recorded reads refine. The experiment above showed no gain because returns did not settle during the walk; here they do. Order then should affect speed, not the result.

**Scans become transfer functions.** A store to a property joins into that property's slot when the store is inferred, so each scan is removed as its fact moves into the walk.

**Monomorphize requests clones from the same queue.** A clone is a new function with its own parameter and return slots.

**Mutation moves out.** Box-back stores and similar rewrites run once after the fixpoint, from final types. Inference itself only writes node types and slots.

**Summaries, then #498.** A function's inputs are the slots it read, so hashing body + inputs gives a cache key for its output and its written slots. Warm rebuilds then skip unchanged functions instead of re-running the program.

## Plan

Every phase lands alone and must pass the full suite and difftest, with `precision:` no worse and CPU no worse.

1. **Split `bottom` from `unknown`.** Smallest change that can show the precision gain. Includes `[]` and the base of a first append. Needs a soundness check on by-ref and dynamic writes, and on the runtime element hint in `emitStoreElementUnified`.
2. **Record dependencies in shadow mode.** Build the read table next to the current code and report what a re-run moved that no recorded edge predicted. Target: zero, on symfony-app, Laravel and the demo.
3. **Move mutation out of inference.**
4. **Replace `NarrowReturns` with the fixpoint for returns,** using the queue. This should retire most full runs.
5. **Move scans into transfer functions** one at a time, most expensive first. Each is its own commit and can be measured alone.
6. **Bring `Monomorphize` onto the queue.**
7. **Function summaries and the cache,** with a warm == cold byte-identity test (#498).

## Risks

- **Monotonicity may not hold.** A retype that legitimately narrows something (a `declared` claim withdrawn, say) breaks termination. Phase 2 should count these; the fallback is a per-slot visit cap, and a capped slot goes to `cell`.
- **`unknown` is load-bearing.** `KIND_UNKNOWN` appears on 435 lines in the passes alone, some relying on "not yet known" and some on "erased". Phase 1 will show how entangled this is, and is the go/no-go point for the whole plan.
- **Too-narrow types are miscompiles,** not just slowdowns: a raw word read as a typed value. An optimistic start has more ways to be wrong than a pessimistic one if a store goes unseen, so the "unseen writer" channels (references, dynamic properties, `extract`, reflection) must force their slots to `cell` up front.
- **Long-lived branches conflict.** `InferTypes` is touched by most bug fixes, which is why each phase is small and merges on its own.

## Expected gain

An estimate, not a measurement: the six full runs become one walk plus re-queued readers, so inference could fall by half or more on symfony-app. Phase 2's shadow table will give a real number (total functions re-queued against 6 × 36k) before the expensive phases start.

## What this does not fix

Emit (about 20-25 s of CPU here), split and clang are unaffected, apart from what fewer boxes save. Most erased arrays stay erased: about half are in generated code that is erased by design, and in a sample of vendor locals 37% were truly heterogeneous.
