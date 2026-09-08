# Decision: what to do about scoped inference

_2026-09-08, after W3 step 2 ([`../superpowers/plans/2026-09-08-w3b-front-end.md`](../superpowers/plans/2026-09-08-w3b-front-end.md))._

## The question

`InferTypes` runs twelve times per compile and is essentially the whole front end (~20 s of a
32 s warm `--fast` build of the compiler itself; minutes on a symfony tier). Re-inferring only
what a round can have moved would cut most of it. The scope machinery exists, the dependency
model is now complete enough to build a correct-looking set — **and a scoped run still does not
produce the same program as a full one.** Eight functions narrow only after a full pass, and
they were IN the scoped set every round, so no missing edge explains it.

## The fact base

`InferTypes` carries ~40 instance maps. Their keys decide whether a per-function pass is pure:

| shape | count | examples |
|---|---|---|
| reset per function (`inferFunctionOnce`) | 5 | `localTypes`, `kindAliasOf`, `currentParamTypes`, `refCellLocalsCur` |
| reset per function (`inferFunction`) | 4 | `cellLoopLocals`, `floatLoopLocals`, `nullLoopLocals`, `elemLoopLocals` |
| keyed by `fn|local` | 4 | `forcedCellElemLocals`, `byRefCellElemLocals`, `byRefCaptureCellLocals`, `cellLoopBoxedParams` |
| **keyed by the BARE LOCAL NAME, never reset** | **~18** | `assocLocals`, `cellElemLocals`, `cellKeyLocals`, `recordLocals`, `floatLocals`, `keyUsedLocals`, `nestedCellVecLocals`, … |

The last row is the problem. `$out`, `$data`, `$bag` are everywhere, so a fact learned about one
function's local is visible when the next function's local of the same name is inferred. That
makes the per-function loop ORDER-DEPENDENT, which is exactly why visiting a subset changes the
answer.

Several of those maps are also written by the module SCANS (`floatLocals`, `assocLocals`,
`cellKeyLocals` …), so they are not simply per-function state that someone forgot to reset —
they are two things sharing one table.

**How bad is the leak in practice?** Narrow. A probe with three functions that share a local
name at three different array shapes compiles to output identical to `php`, and `second()`'s IR
is byte-identical whether or not its neighbours exist (only string-literal ids shift). So this
is not a live wrong-answer bug hunting for a repro; it is an obstacle to scoping, and a latent
hazard.

## Options

**A. Bisect, then fix only what breaks equivalence.** Build a differential harness first: run
the fixpoint twice, scoped and full, and report the FIRST function whose inferred types diverge.
Then reset/key one map at a time until the divergence is gone. Fix those maps only.
Cost: ~1 week. Risk: contained — every step is measured against the full-inference answer.

**B. Key all ~18 maps by `fn|local` wholesale.** Mechanical, ~120 call sites, no analysis
needed. Cost: days of editing, then a long tail of regressions: wherever the leak was
load-bearing the typing changes, and the compiler is its own biggest test case. Risk: high, and
the diff would be impossible to review against a semantic intention.

**C. Leave inference alone.** Parse + lower are 1.9 s of the 20 s front end, so there is nothing
else of size in there. The remaining build-time levers are outside the front end: the object
cache already landed, and IR volume (W2) is where the T5-scale wins were.

**D. Park it with the evidence recorded.** Same as C, but the audit above and the differential
harness idea stay written down so the next attempt starts from the fact base instead of the
"missing dependency edge" theory that cost this epic three rounds.

## Decision

**A, with a hard stop.** Phase 1 is the differential harness and the bisect — that is cheap,
answers a question nobody has answered, and produces a list. Stop and fall back to **D** if
either holds:

- more than ~5 maps turn out to be involved, or
- fixing them moves the t1 corpus IR by more than 1% (the leak was load-bearing, and the change
  is then a typing change, not a scoping fix).

**Do not start with B.** A 120-site mechanical rewrite of inference state, justified by a
theory rather than a measurement, is the shape of change this codebase has already been burned
by twice this week (the `@var` docblock that resolved by offset, the touched-only rescan).

## What success would be worth

Scoped rounds cost 0.55 s against 1.6 s full, and the fixpoint runs 6–7 of them per compile plus
3 inside `Monomorphize`. Closing the equivalence gap should take the front end from ~20 s to
~13–14 s on the compiler's own module (**−30%**), and proportionally more on a symfony tier,
where the front end is minutes rather than seconds. That is the whole prize; it does not touch
emission, the object cache, or IR volume.

## PHASE 1 RESULT (2026-09-08): the hypothesis above is WRONG

The harness exists — `MANTICORE_INFER_DIFF=1`, in `NarrowReturns`: after each SCOPED inference it
runs a FULL one and reports every function whose observable-type fingerprint the full pass still
moved. On the compiler's own module: **129 functions after round 1**, then 8, then
(post-Monomorphize) 54 / 5 / 1 / 2. That is the number this epic never had.

The bisect handle exists too — `MANTICORE_INFER_RESET_LOCALS=all|<map>,…` clears the named
bare-local-name maps at the start of every function — and it says the hypothesis is wrong:

- with `all` (19 maps, the reset branch verified live at **160 410 hits**), the divergence is
  **129 / 8 / 54 / 5 / 1 / 2 — identical, digit for digit**;
- and the emitted module is **byte-identical** (`sha1 92a44583…` both ways).

So those ~18 maps are re-derived per function before anything reads them: stale entries are
inert, they are NOT what makes a scoped pass differ, and **option B would have been a 120-site
rewrite for exactly zero**. Phase 1 cost one afternoon and bought that.

What the harness says instead: every missed function reports `(BODY only) in-scope=n` — its
RETURN type is unchanged, only its internal types moved, and it was **outside the scope**. The
scope holds 3206 of 4877 functions, so 1671 are outside and 129 of those move.
`ArgCount::verify` is typical: no barrier construct (a plain property store, and a static call
that lowers to a direct `Call`), so it is not an escaper, and nothing it calls changed its return.

**Next hypothesis, for whoever picks this up:** the miss is a one-round LAG rather than
unsoundness — a property retyped by this round's scans is only visible to the NEXT round's scope,
so the fixpoint converges a round later and `narrowFunction` gives up before it gets there. Test
it by feeding the current round's `changes->props` into its OWN scope before inferring, and watch
the 129 rather than reasoning about it.

## Acceptance, for whatever gets built

1. `MANTICORE_INFER_DIFF=1` (the harness) reports zero divergent functions on the compiler's own
   module AND on the t1 corpus.
2. `MANTICORE_WORKLIST=on` reaches emission measurably sooner than `off` — the number this epic
   has failed to produce three times.
3. Suite green, difftest at the baseline (`MATCH 978 · DIFF 2`), three generations of self-build
   green, and the t1 IR delta explained line by line.
