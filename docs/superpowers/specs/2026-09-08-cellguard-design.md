# `cellguard` — W4: self-describing value channels + a cell verifier

_Branch `cellguard`, worktree `~/var/projects/manticore-cellguard`, off LOCAL `main` `77a10e8`._

## 1. The problem, stated exactly

`Type::KIND_CELL` is a **static claim with no runtime guarantee**. Several producers type a
slot `cell` and store a RAW machine word into it. Every consumer today either re-probes the
word or reads it raw, so the lie is invisible — the two ends agree by accident.

Tagged arithmetic is the first consumer that **trusts** the claim. It is built, it works, and
it is switched off by a literal `false &&` at `src/Compile/Mir/Passes/InferTypes.php:1701`
because the producers do not honour the contract. That one line is the prize.

Downstream of the same root: `plausiblePtrIr` dereferences an unvalidated word at 15 sites in
5 emitter files; int-overflow→float cannot be turned on; erased array elements, `$GLOBALS`
slots and static `mixed` properties all read back as denormal doubles when they hold arrays.

## 2. Why a verifier comes first

`docs/design/unknown-cell-soundness.md` §17 was written from a re-audit rather than a run, and
§18 measured **two of its three premises false**. The producer list in prose has already been
wrong once. So the epic does not start by fixing the producers it names; it starts by building
the instrument that enumerates them.

Precedent in this repo: the 10-shape probe matrix found the `$GLOBALS` bug in a minute
(`cell-contract-producers-2026-09-08`), `ircensus.php` named the IR-volume root, and
`MANTICORE_INFER_DIFF=1` falsified the inference-scoping hypothesis in one run. Measurement
first is the method that lands stages here.

## 3. Stage 1 — provenance at the emitter seam

The lie lives at the emitter, not in MIR: MIR says `cell`, the emitted store writes a raw word.
A MIR-level check cannot see the §18 bug class. The emitter can, and it already has the choke
points.

**Existing plumbing.** `EmitLlvm` carries `private string $lastValue` / `$lastValueType`
(`EmitLlvm.php:3133`); every producing site allocates via `SsaBuilder::allocReg()` and assigns
`$this->lastValue`. So an SSA value is identified by its register-name string, and a
provenance map keyed on that string needs no dataflow pass.

**The lattice.**

| state | set by |
|---|---|
| `BOXED` | `boxToCell` (136 sites), `boxRawValue` (21), `emitCellifyArrayRaw` (10), `boxUnknownShallowIr` (5), `boxLastByRepr` (2), and direct `@__manticore_box_*` calls |
| `OPAQUE` | a load from a slot already typed `cell`, the return of a call whose signature says `cell`, a phi of non-RAW inputs |
| `RAW` | everything else |

**The sinks.** A store or pass into a `cell`-typed destination: `StoreLocal`, `StoreProperty`,
`StoreDynProp_`, `StoreStaticProp_`, `StoreElement`, a call argument whose parameter is `cell`,
and a `return` from a `cell`-typed function.

**The report.** `RAW → cell` only. That is a proven violation, not a suspicion, so the pass has
no false-positive budget to argue about. `OPAQUE` counts are published alongside as a
**coverage metric**: a large OPAQUE share means the instrument is blind, and that is visible on
the first run instead of three sessions later.

Output: one line per violation — `file:line`, enclosing function, sink kind, source node kind —
plus a per-kind histogram. Gated by an env flag, non-fatal in this stage.

## 4. Stage 2 — the runtime cross-check

`MANTICORE_CELL_ASSERT=1` emits `__mir_assert_cell(v, siteid)` at every cell **read**: a tag
test that prints the site and the offending word to stderr and continues. The build never
fails on it.

Run it over the AOT suite and the probe matrix, then diff the site set against Stage 1:

- in the runtime set, not the static set ⇒ a blind spot in Stage 1, fix Stage 1;
- in the static set, never in the runtime set ⇒ either a cold path or a false positive; the
  static rule gets narrowed only with a witness.

This is a one-off calibration that gives Stage 1 its credibility. The flag stays afterwards as
a debugging tool, not as a gate.

## 5. Stage 3 — close the producers, ranked by Stage 1

The work list is Stage 1's output, not this document's prose. Candidates to expect (from
`cell-contract-producers-2026-09-08` and §18.3b, to be confirmed or refuted):

- an **array** held in a `$GLOBALS['x']` slot (scalars closed 2026-09-08);
- an **array** held in a static `mixed` property;
- the elements of an erased array (`array_combine` + `array_map` + `foreach`);
- an unhinted static-property **read**, typed `unknown` while the store boxes by declared type.

**Non-negotiable method** (§17.4): stop the erasure **in inference**; do not teach the consumer
to guess better. An inference fix needs no producer/consumer co-flip, so the self-host fixpoint
re-converges for free. A codegen A-site is touched only when no inference-side cause remains —
that is how the earlier offset-16 attempts died.

**Explicitly forbidden** (§18.3b): carving out consumers one channel at a time. It was written
and deleted once already; it moves the lie rather than removing it.

Each producer is one commit with its own `tests/aot/cases/*.php` + `expected/*.out`.

## 6. Stage 4 — turn the guarantee on

In this order, no step early:

1. Delete the `false &&` at `InferTypes.php:1701` — **only** once Stage 1 reports zero
   `RAW → cell` across the self-host module, the AOT suite and the T5 corpus.
2. Move `docs/bugs/erased_arith_float_cell.php` back into the suite.
3. Make the cell rule of `TypeCheck` fatal by default. The precedent exists: `reprOnly`
   already runs unconditionally and is already fatal, for the same reason (a hit is a buffer
   read at the wrong type, not a style opinion).
4. Convert the 15 `plausiblePtrIr` sites to assertions now that the claim holds.

## 7. Gates

- **Iteration:** `tools/compile_user_mir.php` (~3 s) and the probe matrix under Zend. No build.
- **A step is done:** `bin/build` twice with gen2 == gen3 byte-identical, then
  `bash tests/aot/run.sh -j 0`.
- **The epic is done:** `bash tools/difftest.sh` and the Linux gate. Heavy gates are run by the
  user, on request, never unprompted.

A `MemoryAbi::VERSION` bump forces `bin/build --seed` (~8 min). Stages 1–2 do not need it;
only Stage 3 does, and only if an encoding changes.

## 8. Exit criteria

- Stage 1 reports zero `RAW → cell` on self-host + suite + T5, with the OPAQUE share reported.
- Stage 2's runtime site set is a subset of Stage 1's.
- Tagged arithmetic is on; `erased_arith_float_cell` is in the suite and green.
- `TypeCheck`'s cell rule is fatal by default.
- `plausiblePtrIr` dereferences nothing unvalidated.
- Suite and difftest no worse than the `77a10e8` baseline.

## 9. Risks

- **Tagged arith flipped mid-epic "to look".** It mixes two sources of failure. Do not.
- **A prose producer list.** §18 falsified §17's; treat §5's candidates as hypotheses that
  Stage 1 confirms or kills.
- **OPAQUE swallowing the answer.** If most cell sinks are fed by OPAQUE values, Stage 1 proves
  nothing. The metric is in the report precisely so this cannot be missed.
- **A codegen co-flip.** Producer and consumer must change together or generation 2 crashes —
  which is why §5 fixes inference first.

## 10. References

- `docs/design/unknown-cell-soundness.md` §§17–18 — the audit, and what measurement overturned.
- `docs/design/backend-and-build-strategy.md` — W4's place in the W0–W5 ladder.
- `docs/design/reference-cells.md` — the aliasing invariant; a by-ref binding is slot identity,
  not value representation, and belongs to that epic (§17.6).
- `src/Compile/Mir/Passes/InferTypes.php:1701` — the gate.
- `src/Compile/Mir/Passes/TypeCheck.php` — the pass being hardened.
