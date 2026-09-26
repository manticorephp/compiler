<?php

namespace Compile\Mir;

/**
 * The function EmitLlvm is currently emitting: its identity, ABI and the
 * owned locals its every `ret` must clean up.
 *
 * Reset per function ({@see reset}); one instance per {@see EmitLlvm::emit()}.
 */
final class FunctionEmitFrame
{
    /** Name of the function being emitted (property-hook self-ref guard). */
    public string $name = '';
    /** Body of the function being emitted — the loop arena-reset liveness
     *  check needs it to see uses OUTSIDE the loop. */
    public ?Node $body = null;
    /** Declared return type (cell → box on the way out). */
    public ?Type $returnType = null;
    /** The fn returns by-ref: `emitReturn` yields an address, not a value. */
    public bool $returnsByRef = false;
    /** This is `__main` (emitted as `i32 @main`). A top-level `return` here is a
     *  whole-program include-return (require is a no-op) — evaluated for side
     *  effects then IGNORED, never a mid-body `ret i64` that mismatches the i32
     *  result AND would exit before the entry's own top-level runs. */
    public bool $isMain = false;
    /** The fn is a closure — uniform ABI: scalar params/returns travel as
     *  tagged cells. */
    public bool $isClosure = false;
    /** The fn is a reflection invoke trampoline (`__mc_rtramp_*`), reached only
     *  through the indirect `__mc_refl_invoke` builtin — same uniform ABI as a
     *  closure for the RETURN: a scalar result must be boxed to a tagged cell,
     *  else the indirect caller reads a raw int as a cell. */
    public bool $isTrampoline = false;
    /** The fn opened an arena scope: every `ret` must `@__mir_arena_leave` first. */
    public bool $hasArena = false;
    /** @var array<string, bool> param names — a param arrives holding the
     *  caller's value, so it never pairs element refs of its own. */
    public array $paramNames = [];
    /** @var array<string, MemoryOp_> {@see \Compile\Mir\Passes\OwnershipFlow}'s
     *  managed locals → their `own_local` registration (the flavor is re-derived
     *  per use via rcReleaseFlavor; storing the flavor string here corrupts
     *  under the self-host backend). Slots null-inited. */
    public array $ownLocals = [];
    /** @var array<string, bool> vec locals mutated in this fn (append / element
     *  store) — drive copy-on-assign value semantics. */
    public array $mutatedVecLocals = [];
    /** @var array<string, bool> locals whose value was acquired BY RETAIN — the
     *  `$saved = $this->map` property snapshot, the one read shape that takes a
     *  reference — and whose retain and release name the SAME flavor. Their
     *  release drops the element refs its own retain took, on EVERY release
     *  (`__mir_array_release_ownel_*`), instead of only at rc → 0. Element
     *  ownership is a property of the REFERENCE: a name qualifies only when
     *  EVERY store to it is that one shape, so both ends of the pair are the
     *  same site with the same static type. {@see EmitLlvmMemory::
     *  collectOwnElemLocals}. Appended at the END — no mid-struct insertion. */
    public array $ownElemLocals = [];
    /** The body is a prelude function, emitted `linkonce_odr`: every module's
     *  copy must be identical, so nothing module-local (the closure pad chain,
     *  {@see Passes\EmitLlvmCalls::emitDynClosurePaddedCall}) may shape it.
     *  Appended at the END — no mid-struct insertion. */
    public bool $isPrelude = false;
    /** @var array<string, string> a MIXED rc local (raw on some paths, a cell on
     *  others — {@see Passes\InsertMemoryOps::settleMixedSlots}) → the alloca of
     *  its "slot holds a cell" flag. Appended at the END. */
    public array $mixedFlagSlots = [];
    /** @var array<string, string> the same flags keyed by the local's SLOT, for
     *  the release helpers that only see the slot. Appended at the END. */
    public array $mixedFlagBySlot = [];
    /** @var array<string, bool> OwnershipFlow locals some source of which is a
     *  BORROW (a param, an alias, a non-co-owning binding): registered, but not
     *  proven to hold element refs of their own. Appended at the END. */
    public array $ownBorrowed = [];
}
