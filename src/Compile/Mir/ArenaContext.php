<?php

namespace Compile\Mir;

/**
 * Arena-allocation state for the function being emitted.
 *
 * Two jobs. First, which locals hold arena-allocated vecs — their `$x[]=`
 * appends must go through `@__mir_arena_realloc` rather than the heap path.
 * Second, the loop arena-reset analysis: a loop whose body bump-allocates may
 * reset the arena each iteration, but only if nothing it allocated outlives the
 * iteration ({@see $hasAlloc}, {@see $bindsNonLocal}, {@see $boundLocals} are the
 * scan's verdict inputs).
 *
 * One instance per {@see EmitLlvm::emit()}.
 */
final class ArenaContext
{
    /** Set by emitArrayLit when it bump-allocated a vec, read by the enclosing
     *  emitStoreLocal to mark the target as an arena vec. */
    public bool $vecAllocated = false;
    /** @var array<string, bool> locals holding an arena-allocated vec — their
     *  `$x[]=` appends must use @__mir_arena_realloc. */
    public array $vecLocals = [];

    // ── loop arena-reset scan (arenaScan fills these; the verdict reads them) ──

    /** The loop subtree contains an Arena allocation. */
    public bool $hasAlloc = false;
    /** An Arena value is bound to a NON-local sink (property / element / static /
     *  dyn prop) — always unsafe to reset, the value outlives the frame. */
    public bool $bindsNonLocal = false;
    /** @var array<string, bool> locals a store binds an Arena value to in the
     *  loop — each must pass the reset-liveness check (written-before-read each
     *  iteration AND not read outside the loop). */
    public array $boundLocals = [];

    /** SSA regs threaded from the arena position save to its restore. */
    public string $saveCurReg = '';
    public string $saveUsedReg = '';

    /** Restart the loop-reset scan. */
    public function resetScan(): void
    {
        $this->hasAlloc = false;
        $this->bindsNonLocal = false;
        $this->boundLocals = [];
    }

    /**
     * A loop may reset the arena each iteration iff its body (+cond/step)
     * allocates Arena temporaries and every Arena value it *binds* is safe to
     * free at the iteration boundary. Binding to a non-local sink (property /
     * element / static) is never safe. Binding to a LOCAL is safe when the
     * local is (A) written before it is read on each iteration (so the prior
     * iteration's freed value is never observed) AND (B) not read anywhere in
     * the function outside this loop (so the last iteration's value — freed by
     * the pre-exit reset — is never observed either). `$step` may be null.
     */
    public function canResetPerIteration(?Node $cond, Node $body, ?Node $step, ?Node $fnBody, bool $inGenerator): bool
    {
        // A generator resume body re-enters mid-loop via the entry state
        // switch (irreducible CFG), so a per-iteration arena save placed
        // before the loop no longer dominates the in-loop reset. Disable the
        // arena loop optimization inside generators.
        if ($inGenerator) { return false; }
        $this->resetScan();
        if ($cond !== null) { $this->scan($cond); }
        $this->scan($body);
        if ($step !== null) { $this->scan($step); }
        if (!$this->hasAlloc || $this->bindsNonLocal) { return false; }
        foreach ($this->boundLocals as $name => $ignored) {
            // (B) read outside the loop? Reads within the loop are cond+body+step;
            // any surplus in the whole function body is an outside read.
            $inLoop = $this->countLocalReads($name, $body)
                + ($cond !== null ? $this->countLocalReads($name, $cond) : 0)
                + ($step !== null ? $this->countLocalReads($name, $step) : 0);
            $total = $fnBody !== null
                ? $this->countLocalReads($name, $fnBody) : $inLoop;
            if ($total > $inLoop) { return false; }
            // (A) written before read on each iteration.
            if (!$this->writtenBeforeRead($name, $body)) { return false; }
        }
        return true;
    }

    private function scan(Node $n): void
    {
        if ($n->allocKind === AllocationKind::ARENA) {
            $this->hasAlloc = true;
        }
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            if ($this->bindsArenaValue($n->value)) {
                $this->boundLocals[$n->name] = true;
            }
        } else {
            $sv = $this->storeBoundValue($n);
            if ($sv !== null && $this->bindsArenaValue($sv)) {
                $this->bindsNonLocal = true;
            }
        }
        foreach (Walk::children($n) as $c) { $this->scan($c); }
    }

    /** Count LOAD_LOCAL reads of `$name` in the subtree. */
    private function countLocalReads(string $name, Node $n): int
    {
        $c = 0;
        if ($n->kind === Node::KIND_LOAD_LOCAL && $n->name === $name) {
            $c = 1;
        }
        foreach (Walk::children($n) as $ch) {
            $c = $c + $this->countLocalReads($name, $ch);
        }
        return $c;
    }

    /**
     * Whether, in `$body`, `$name` is assigned by a plain StoreLocal (whose
     * value does NOT read `$name`) before any read of it — sound if the body
     * is a statement sequence: the first statement that mentions `$name` must
     * be that fresh assignment. Conservative (false) for any other first use
     * (a read, an element/compound store, or a self-referential value).
     */
    private function writtenBeforeRead(string $name, Node $body): bool
    {
        foreach ($this->stmtList($body) as $stmt) {
            if ($stmt->kind === Node::KIND_STORE_LOCAL
                && $stmt->name === $name) {
                // Fresh re-init iff the value doesn't read $name itself.
                return $this->countLocalReads($name, $stmt->value) === 0;
            }
            // Any other statement that mentions $name (read, or a nested/
            // conditional/element write) reaches a use before a clean write.
            if ($this->countLocalReads($name, $stmt) > 0
                || $this->mentionsLocalStore($name, $stmt)) {
                return false;
            }
        }
        return false;
    }

    /**
     * Statement list of a block body (else a one-element list).
     *
     * @return Node[]
     */
    private function stmtList(Node $body): array
    {
        if ($body->kind === Node::KIND_BLOCK) {
            return $body->stmts;
        }
        return [$body];
    }

    /** Whether the subtree contains a StoreLocal targeting `$name` (any depth). */
    private function mentionsLocalStore(string $name, Node $n): bool
    {
        if ($n->kind === Node::KIND_STORE_LOCAL && $n->name === $name) {
            return true;
        }
        foreach (Walk::children($n) as $ch) {
            if ($this->mentionsLocalStore($name, $ch)) { return true; }
        }
        return false;
    }

    /** Whether the value bound by a store is (or yields) an Arena alloc. */
    private function bindsArenaValue(Node $v): bool
    {
        if ($v->allocKind === AllocationKind::ARENA) { return true; }
        if ($v->kind === Node::KIND_TERNARY) {
            $t = $v;
            if ($t->then !== null && $this->bindsArenaValue($t->then)) { return true; }
            return $this->bindsArenaValue($t->else_);
        }
        if ($v->kind === Node::KIND_NULLCOALESCE) {
            $nc = $v;
            return $this->bindsArenaValue($nc->left) || $this->bindsArenaValue($nc->right);
        }
        return false;
    }

    /** The value a store binds to a name, or null for a non-store node. */
    private function storeBoundValue(Node $n): ?Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) { return $n->value; }
        if ($k === Node::KIND_STORE_PROPERTY) { return $n->value; }
        if ($k === Node::KIND_STORE_ELEMENT) { return $n->value; }
        if ($k === Node::KIND_STORE_STATIC_PROP) { return $n->value; }
        if ($k === Node::KIND_STORE_DYN_PROP) { return $n->value; }
        return null;
    }
}
