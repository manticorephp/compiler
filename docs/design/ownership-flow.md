# OwnershipFlow — who holds a count of a local, decided per program point

Status: **shipped.** PR #15 (`b40d04be`, "Ownership of locals decided per program
point") and PR #104 (`21337382`, "generator frames own what they hold", ABI v17).
`MemoryAbi::VERSION` is 18 today (`src/Compile/MemoryAbi.php`). The rc encoding,
tags and generator-frame layout are in `memory-abi.md` (§5 is the short form of
this note); this note says how the decision is made and what a contributor may
not break.

## Why

Before PR #15 the question "does this local hold a +1?" had several deciders that
could disagree:

- `InsertMemoryOps` kept a **name-level** rc track: one verdict per local for the
  whole function, plus a "transfer veto" and an element-share scan. A name bound to
  an owned value on one path and a borrowed one on another was either blocked
  (and leaked) or released on a path that did not own it (and double-freed).
- The emitter re-derived ownership for temps and aliases on its own. The store side
  and the temp side of the same predicate lived in two classes
  (`Ownership::classifyStored` vs `classifyTemp`) and drifted — every drift was a
  leak or a double free (`src/Compile/Mir/Ownership.php` header).
- Return drops and container moves were guessed by the emitter ("which erased
  returns the emitter retains").

The replacement: one pass computes ownership **per program point**, writes the
decision into the MIR as explicit ops, and the emitter only executes it. The name
level track, the transfer veto and the element-share scan were deleted in the same
PR.

## Where it runs

`lower_module` in `src/Manticore/Main.php`:

```
... → ApplyMemoryMode → SpillFreshBases → InsertMemoryOps → OwnershipFlow → Verify → EmitLlvm
```

- `SpillFreshBases` (`requires()` of the pass) gives a fresh container that a read
  goes through (`f()->data`, `rows()[0]`) a hidden local, so it has an owner the
  flow releases on the next store and at scope exit.
- `InsertMemoryOps` is now the frame's **arena scope only** (`arena_enter` /
  `arena_leave`); it plants no per-local release.
- `OwnershipFlow` always runs (no flag; `MANTICORE_OWNFLOW=1` was only the
  transition switch in PR #15).
- `Verify` throws `MIR.verify: …` when `Module::ownFlowErrors` is non-empty
  (`Passes/Verify.php::run`): an ownership the flow cannot settle fails the build
  with function, name and line, never a silent choice of one release over another.

## The model

Files: `src/Compile/Mir/Passes/OwnershipFlow.php` (the pass),
`src/Compile/Mir/Flow/Forward.php` (the dataflow engine),
`src/Compile/Mir/Flow/OwnLattice.php` (the lattice),
`src/Compile/Mir/Flow/MixedSlots.php` (cell-vs-raw slots),
`src/Compile/Mir/Ownership.php` (the predicates).

`Forward` is a structured forward dataflow over the tree-shaped MIR: the tree is the
CFG, loops iterate to a fixpoint of the head state, and break / continue / goto /
return / throw carry their state to the target that joins it. A catch arm enters
with the join over every point of the try; a `finally` enters with the join over
every way out of the try. "Unreachable" is a sentinel state, not `null`
(a nullable typed array is a cell natively — see the comment in `Forward.php`).

A state maps each managed local to one of (`OwnLattice.php` header):

| state | meaning |
|---|---|
| `EMPTY` (absent) | null, or a word every drop no-ops on |
| `Borrow(k)` | an rc value of release class `k` this frame holds no count of |
| `SCALAR` | raw non-rc word, never dropped or retained |
| `CELLNIL` | cell slot holding a non-rc word (boxed scalar / null) |
| `MIXDEAD` | representation differs per incoming path; nobody may read it |
| `Own(k)`, k > 0 | frame holds a +1; `k` = a release class (flavor + slot type a drop releases by) |

Flavors are `Ownership::STR/OBJ/VEC/ASSOC/CELL/CLOSURE/GEN`; the flavor IS the
release-helper choice (`Ownership::flavorOf`, same decision as `releaseFlavor`).

Join:

- equal states keep; `EMPTY` is the identity;
- `Own(k) ⊔ Borrow(k) = Own(k)` and the name is a **conflict**: the pass compensates
  with `own_retain` on the borrowed edge;
- `Own` against anything else non-empty is a **mismatch** → `MIXDEAD`; the owned
  side is dropped on its edge, and a read past the join (or an edge with no place
  for the drop) goes to `ownFlowErrors`;
- two borrows of different classes, or a borrow and a raw word → `SCALAR`.

A store transfers to the state `Ownership::classifyStored` answers for its value,
**read against the slot's representation** (a cell slot, a raw slot, a mixed slot).
Store modes (`OwnLattice::PLAIN / SELF_MOVE / SELF_APPEND / SELF_COPY`) cover
`$x = $x`-shaped stores.

## What it decides, how emission consumes it

The pass writes ops onto nodes; EmitLlvm executes them and does not re-decide
(`EmitLlvmLocals.php`, `EmitLlvmControl.php`, `EmitLlvmMemory.php`, `EmitLlvmObjects.php`).
Fields live in `src/Compile/Mir/Nodes.php`.

- **Drop of the old value** on a store reached by `Own`: `StoreLocal::$ownOld`, run
  after the new value is computed. Also before `unset`, at the fall-through end,
  and at every return (`Return_::$ownDrops`, after the value and any finally).
- **Return**: an owned whole returned local MOVES (`Return_::$ownMove`). Which +1 a
  return takes is one decision (`Ownership::returnRetain`); a conditional return
  that took no +1 lists its arm locals (`Ownership::returnArmLocals` →
  `Return_::$ownArms`), and the emitter drops each only if its slot word is not
  the returned word. Every declared bare `array` / `?array` return is +1 on every
  path (`Ownership::erasedArrayReturn`), so a caller owns an erased-array result
  (release class `erasedarr`).
- **Join compensation**: `own_retain` on the borrowed edge. Where no edge has a
  statement position (catch entry, loop exit, switch dispatch, expression arm)
  the name is FORCED: every borrowed store / binding / param of it takes its +1 on
  the spot.
- **Self ops**: `own_retain` before a self-append of a borrowed string; after an
  array alias of another local (a borrow of a buffer its owner may drop first).
- **Container stores**: a local handed to a container that takes no count
  (`Ownership::containerStoreRetains` refuses) MOVES there only when nothing reads
  the name afterwards (`Ownership::containerMoves`: not in a loop, no `goto`, no
  symbol-table reader); otherwise the container takes its own +1 (`own_share`)
  and the local stays owned.
- **Element-shared args**: an array local handed to an object-producing call whose
  callee may keep it with its element refs (`Ownership::elementSharedArgs`) is
  released buffer-only (`vecbuf` / `assocbuf`).
- **Foreach**: binding co-ownership and the drop of what the slot still holds
  (`Foreach_::$ownCoOwn`, `$ownDropValue`, `$ownDropKey`). The `getIterator()`
  result of an IteratorAggregate loop lives in a hidden local the flow owns, so a
  return / `break N` / `continue N` / exception out of the loop releases it.
- **Registration**: one `own_local` (`own_local_b` when some source borrows) per
  name: the release class the emitter's remaining per-name questions read
  (foreach retain depth, MIXED flag slot, an array base's release type).

Conditionals (`?:`, `??`, ternary, `match`) are a shared contract through
`Compile\Mir\CondOwn`; aliases, property reads and borrowing builtins through
`Compile\Mir\AliasOwn`. An emitter-only fix leaks; a pass-only fix double-frees.

## Special cases

**Try / finally and unwinding.** Every may-unwind `call` line, `throw` and try
re-raise is marked with the set of locals `Own` at that point
(`Call::$ownLive`, name → its `drop` op; `Throw_`, `Foreach_` carry the same).
`EmitLlvmExceptions.php` (comment "cleanup pads") turns a marked call into an
`invoke … unwind label %ehlp.K` whose pad drops that set and resumes: one pad per
distinct drop set per function. A try region keeps the locals alive for its catch
(`TryCatch_::$ownCatch`); the `finally` entry state is the join over every exit
(`Forward`), and `TryCatch_::$ownFinally` carries the drops a rethrow out of the
finally performs (`OwnershipFlow.php`, `unwindDrops`). A foreach's iterator
protocol calls (resume / rewind / valid / current / key / next) carry `ownLive`
too (`EmitLlvmControl.php`).

**Generators (ABI v17).** The frame owns what it holds: params (from creation),
locals, and the four header cells `current`@16, `key`@24, `sent`@40, `retval`@48.
Readers take their own +1; `yield` moves `sent` out. Destroying an unfinished frame
re-enters its resume function at `-2 - state`; each yield carries the exit
(`Yield_::$ownLive`, or the outermost finally's `ownFinally`): the enclosing
`finally` blocks run, then the locals Own at that yield are dropped. A frame nobody
started drops its params. The resume entry stores state -1, so an exception that
leaves the body leaves the generator finished and unwinds through the ordinary
cleanup pads. `__mir_str_reclaim` calls `__mir_gen_destroy` first. Emission:
`EmitLlvmGenerator.php`. Layout and who-takes-what: `memory-abi.md` §5.

**By reference / aliased names.** A name reachable through `&`, `static`, a by-ref
capture or by-ref foreach is left to its storage (not managed). A join never boxes
alone a word another frame shares: its erased side is that frame's raw write.

**Mixed slots.** A slot holding a raw value on some paths and a cell on others
(`MixedSlots.php`) releases through its representation flag. A local an expression
arm rebinds to another representation (ternary, `&&`, `||`, `??`, …) is a cell
(`joinDisagrees`).

**Loops.** `Forward` iterates the head state to a fixpoint. PHP locals are
function-scoped, so break / continue / goto edges need only compensation; the old
value is dropped at the next overwrite or at exit. A foreach binding of a name the
loop re-kinds is boxed back on every path out of the body (box-back before each
loop-leaving `break` / `continue`).

**Calls and escapes.** A user call carries a full contract: caller releases a fresh
rc arg temp, callee retains what it keeps, return is +1. Codegen builtins carry no
contract; the table is `docs/design/builtin-ownership.md` (`Ownership::classifyTemp`
is the temp-side question). Escape summaries (`EscapeSummaries.php`) decide what a
callee keeps.

## Invariants — do not break

1. **One decider.** Never add an emitter-side "should I retain/release this local?"
   guess. Extend the plan (a new `own*` field) and make the emitter execute it.
2. **Store side and temp side agree.** Change `Ownership::classifyStored` and
   `classifyTemp` together; the header lists the deliberate drift rows.
3. **Pass and emitter agree on the return +1.** Both read `Ownership::returnRetain`.
4. **A drop needs a place.** Where no edge has a statement position, FORCE the name
   (borrowed sources take +1) rather than drop on the edge; a mismatch with no drop
   site is an error, not a leak.
5. **No read of a `MIXDEAD` name.** Typing must not produce one; Verify reports it.
6. **Buffer-only counts.** A holder's count on an array is on the buffer
   (`Debug::$rcBufferOnly`); elements are dropped once at rc 0 by hint.
7. **Cell `$x = $x`** emits nothing only for a slot the plan owns or a parameter;
   an untracked local's alias store borrows.
8. **`ownLive` is not cloned** (`NodeClone`): clones run before the pass.
9. **Generator frames own their header cells**; any new cell in the header needs a
   drop in `__mir_gen_destroy` and an ABI bump.
10. Bisect-only switch: `MANTICORE_OWNFLOW_ONLY=a,b` (`Debug::$ownFlowOnly`) —
    unmanaged functions keep every retain and get no drop (leak, never free).

## Debugging

- `MANTICORE_OWN_TRACE=<fn substring>` — pass trace (`OwnershipFlow.php`, also read
  by `SpillFreshBases.php`).
- `php tools/ownflow_dump.php <file.php> <args>` — Zend-hosted: lowers up to
  `SpillFreshBases` (`lower_module` stops there for it) and prints per-node
  `Forward` states and edges. `bash tools/ownflow_check.sh [--bless]` diffs it over
  `tests/flow/*.php` against `.expected` (`// ownflow-args:` line per fixture).
- `php tools/ownership_probe.php` — classifies codegen builtins by measuring RSS
  (`docs/design/builtin-ownership.md`).
- Leak regression cases: `tests/aot/cases/*no_leak*`, `generator_frame_owns.php`,
  `foreach_iterator_unwind_releases.php`; open leak repros go to
  `tests/aot/repro/leak/` (see AGENTS.md).
- Re-`bin/build` before believing a red: ownership bugs surface as a poisoned
  stage, not a local diff.

## Known open edges

Not derivable from code alone; check the issue tracker (`gh issue list --label leak`)
before relying on any of these. Visible in code: "Ref-cell leftovers" in
`reference-cells.md` (`=&` on a property target, static-prop source, `&...$vars`)
sit outside the managed set, since reachable-by-reference names are left to their
storage.
