<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\MemoryMode;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\Walk;

/**
 * Memory-mode overlay (contract step #5). Keeps {@see InferAllocKind}
 * a pure escape analysis (RcHeap / NoRefcount) and folds the chosen
 * {@see MemoryMode} on top, so the same escape verdict can drive rc,
 * arena, or hybrid reclaim without re-running the analysis.
 *
 * Remap of a confined (NoRefcount) allocation:
 *  - HYBRID → Arena   (bump-alloc, bulk-free at scope exit)
 *  - ARENA  → Arena
 *  - RC     → NoRefcount (unchanged)
 * Escaping (RcHeap) stays RcHeap under rc/hybrid. Under ARENA it also
 * routes to Arena (the emission side adds the escape bypass guard).
 */
final class ApplyMemoryMode implements Pass
{
    public const NAME = 'apply-memory-mode';

    private string $mode;

    public function __construct(string $mode)
    {
        $this->mode = MemoryMode::resolve($mode);
    }

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferAllocKind::NAME]; }

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            // An extern carries a signature and no body — the memory mode of the
            // code behind it was fixed when the library was compiled.
            if ($fn->isExtern) { continue; }
            $this->remap($fn->body);
            $this->unconfineUnresettableLoops($fn);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /**
     * Take an allocation back OUT of the arena when the loop around it will not
     * be able to reset the arena each iteration.
     *
     * An arena value is reclaimed by exactly two things: the per-iteration reset
     * {@see \Compile\Mir\ArenaContext::canResetPerIteration} allows, and the
     * frame's own `arena_leave`. A hot loop that gets neither accumulates every
     * iteration's temporaries for the whole frame — measured at **2.7 GB** for
     * 300 000 iterations of `$s = $long . $long` whose `$s` is read once after
     * the loop, against 1 MB when the reset is allowed. Not a leak (the frame
     * does free it, which is why `memory_get_usage()` stays flat while RSS does
     * not), but for a server loop the distinction is academic.
     *
     * The two decisions were made independently: the escape analysis proved the
     * value frame-confined, which is true, and the emitter then refused the
     * reset for its own good reason, which is also true. Nothing reconciled
     * them. This does: a confined allocation whose INNERMOST enclosing loop
     * cannot reset goes back to RcHeap, where the ordinary release-before-
     * overwrite bounds it at one live value.
     *
     * "Innermost" is the operative word. An outer reset that fires once per
     * outer iteration does not bound an inner loop running 300 000 times, so a
     * node is safe only when the loop DIRECTLY around it resets.
     *
     * Verdicts are computed over the whole tree BEFORE anything is demoted:
     * demotion only ever removes arena allocations, so evaluating first keeps
     * the answer independent of the order loops are visited.
     */
    private function unconfineUnresettableLoops(\Compile\Mir\FunctionDef $fn): void
    {
        if ($this->mode === MemoryMode::RC) {
            return;   // nothing was routed to the arena in the first place
        }
        $loops = [];
        $this->collectLoopVerdicts($fn->body, $fn, $loops);
        if (\count($loops) === 0) {
            return;
        }
        $this->demote($fn->body, $loops, true);
    }

    /**
     * Every loop node in the subtree, paired with whether it may reset the arena
     * per iteration.
     *
     * @param array<int, array{0:Node,1:bool}> $out
     */
    private function collectLoopVerdicts(Node $n, \Compile\Mir\FunctionDef $fn, array &$out): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_FOR || $k === Node::KIND_WHILE
            || $k === Node::KIND_DOWHILE || $k === Node::KIND_FOREACH) {
            $out[] = [$n, $this->loopResets($n, $fn)];
        }
        foreach (Walk::children($n) as $c) {
            $this->collectLoopVerdicts($c, $fn, $out);
        }
    }

    /**
     * The same question the emitter asks, asked here so the answer can shape the
     * allocation instead of only the reset. Mirrors the four call sites in
     * {@see EmitLlvmControl} — including `foreach`'s by-ref exclusion, whose
     * value aliases the array and outlives the iteration.
     */
    private function loopResets(Node $n, \Compile\Mir\FunctionDef $fn): bool
    {
        $arena = new \Compile\Mir\ArenaContext();
        $k = $n->kind;
        if ($k === Node::KIND_FOREACH) {
            if ($n->byRef) { return false; }
            return $arena->canResetPerIteration(null, $n->body, null, $fn->body, $fn->isGenerator);
        }
        if ($k === Node::KIND_FOR) {
            return $arena->canResetPerIteration($n->cond, $n->body, $n->step, $fn->body, $fn->isGenerator);
        }
        return $arena->canResetPerIteration($n->cond, $n->body, null, $fn->body, $fn->isGenerator);
    }

    /**
     * Walk with "the loop directly around here resets" threaded down, demoting
     * every Arena stamp that arrives with it false.
     *
     * @param array<int, array{0:Node,1:bool}> $loops
     */
    private function demote(Node $n, array $loops, bool $reclaimed): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_FOR || $k === Node::KIND_WHILE
            || $k === Node::KIND_DOWHILE || $k === Node::KIND_FOREACH) {
            $reclaimed = false;
            foreach ($loops as $pair) {
                if ($pair[0] === $n) { $reclaimed = $pair[1]; break; }
            }
        }
        if (!$reclaimed && $n->allocKind === AllocationKind::ARENA) {
            $n->allocKind = AllocationKind::RC_HEAP;
        }
        foreach (Walk::children($n) as $c) {
            $this->demote($c, $loops, $reclaimed);
        }
    }

    private function remap(Node $n): void
    {
        $kind = $n->allocKind;
        if ($kind !== null) {
            $n->allocKind = $this->mapped($kind);
        }
        foreach (Walk::children($n) as $c) { $this->remap($c); }
    }

    private function mapped(string $kind): string
    {
        if ($this->mode === MemoryMode::RC) {
            return $kind;
        }
        if ($this->mode === MemoryMode::ARENA) {
            // Arena-everything: confined and escaping both bump-allocate.
            return AllocationKind::ARENA;
        }
        // HYBRID: confined → Arena, escaping stays RcHeap.
        if ($kind === AllocationKind::NO_REFCOUNT) {
            return AllocationKind::ARENA;
        }
        return $kind;
    }
}
