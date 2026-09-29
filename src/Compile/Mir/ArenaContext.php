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
     * the pre-exit reset — is never observed either) AND (C) bound by nothing
     * but direct arena allocations stored inside this loop — no store outside
     * it, no copy of another local, no heap value, not a param / foreach /
     * catch variable.
     *
     * (C) is what keeps this ONE answer before and after {@see
     * Passes\OwnershipFlow}: such a local is only ever a Borrow (or Empty) to
     * the flow, so the flow plans no drop, retain or share on it that could
     * touch a value a reset already freed. {@see Passes\ApplyMemoryMode} asks
     * before the flow ran and the emitter after, and both get the same verdict.
     * The flow ops ride on node fields, not children ({@see countLocalReads}
     * still counts them, a backstop should (C) ever let an Own through).
     * `$step` may be null.
     *
     * @param array<string, bool> $params the function's param names
     */
    public function canResetPerIteration(?Node $cond, Node $body, ?Node $step, ?Node $fnBody, bool $inGenerator, array $params = []): bool
    {
        // A generator resume body re-enters mid-loop via the entry state
        // switch (irreducible CFG), so a per-iteration arena save placed
        // before the loop no longer dominates the in-loop reset. Disable the
        // arena loop optimization inside generators.
        if ($inGenerator) { return false; }
        $this->resetScan();
        // To a fixpoint: a local that copies an arena-bound local (`$prev = $name`)
        // holds the same arena value, whichever order the stores come in.
        do {
            $bound = \count($this->boundLocals);
            if ($cond !== null) { $this->scan($cond); }
            $this->scan($body);
            if ($step !== null) { $this->scan($step); }
        } while (\count($this->boundLocals) > $bound);
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
            // (C) arena-bound only, and only here.
            if (isset($params[$name])) { return false; }
            $here = $this->arenaStores($name, $body)
                + ($cond !== null ? $this->arenaStores($name, $cond) : 0)
                + ($step !== null ? $this->arenaStores($name, $step) : 0);
            if ($fnBody !== null && $this->bindings($name, $fnBody) !== $here) { return false; }
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

    /** Count reads of `$name`'s slot in the subtree: every LOAD_LOCAL, and
     *  every {@see Passes\OwnershipFlow} op riding on a node field (a store's
     *  drop of the old value or retain of the new, a return's drops and
     *  identity-compared arms, a foreach binding's drop). An `own_local` /
     *  `own_local_b` registration names the local for the emitter and executes
     *  nothing: not a read. */
    private function countLocalReads(string $name, Node $n): int
    {
        $k = $n->kind;
        if ($k === Node::KIND_MEMORY_OP) {
            $op = self::asMemoryOp($n)->op;
            if ($op === 'own_local' || $op === 'own_local_b') { return 0; }
        }
        $c = 0;
        if ($k === Node::KIND_LOAD_LOCAL && $n->name === $name) {
            $c = 1;
        } elseif ($k === Node::KIND_STORE_LOCAL) {
            $sl = self::asStoreLocal($n);
            if ($sl->name === $name && ($sl->ownOld !== null || $sl->ownNew !== null)) { $c = 1; }
        } elseif ($k === Node::KIND_RETURN) {
            $r = self::asReturn($n);
            if (isset($r->ownArms[$name])) { $c = 1; }
            foreach ($r->ownDrops as $d) {
                $t = $d->target;
                if ($t !== null && $t->kind === Node::KIND_LOAD_LOCAL && self::asLoadLocal($t)->name === $name) { $c = 1; }
            }
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            if (($fe->valueVar === $name && $fe->ownDropValue !== null)
                || ($fe->keyVar === $name && $fe->ownDropKey !== null)) { $c = 1; }
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
                // Fresh re-init iff the value doesn't read $name itself, and
                // no flow drop reads the previous iteration's value first.
                return $this->countLocalReads($name, $stmt->value) === 0
                    && self::asStoreLocal($stmt)->ownOld === null;
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

    /** Whether the value bound by a store is (or yields) an Arena alloc — a
     *  read of a local this loop binds to one included. */
    private function bindsArenaValue(Node $v): bool
    {
        if ($v->allocKind === AllocationKind::ARENA) { return true; }
        if ($v->kind === Node::KIND_LOAD_LOCAL && isset($this->boundLocals[$v->name])) { return true; }
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

    /** StoreLocals of `$name` in the subtree whose value is an arena allocation. */
    private function arenaStores(string $name, Node $n): int
    {
        $c = 0;
        if ($n->kind === Node::KIND_STORE_LOCAL && self::asStoreLocal($n)->name === $name
            && self::asStoreLocal($n)->value->allocKind === AllocationKind::ARENA) { $c = 1; }
        foreach (Walk::children($n) as $ch) { $c = $c + $this->arenaStores($name, $ch); }
        return $c;
    }

    /** Everything that binds `$name` in the subtree: its stores, and a foreach
     *  or catch binding it (counted twice, so it never equals a store count). */
    private function bindings(string $name, Node $n): int
    {
        $c = 0;
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL && self::asStoreLocal($n)->name === $name) { $c = 1; }
        if ($k === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            if ($fe->valueVar === $name || $fe->keyVar === $name) { $c = 2; }
        }
        if ($k === Node::KIND_TRY_CATCH) {
            foreach (self::asTryCatch($n)->catches as $cc) { if ($cc->var === $name) { $c = $c + 2; } }
        }
        foreach (Walk::children($n) as $ch) { $c = $c + $this->bindings($name, $ch); }
        return $c;
    }

    private static function asMemoryOp(Node $n): MemoryOp_ { return $n; }
    private static function asStoreLocal(Node $n): StoreLocal { return $n; }
    private static function asLoadLocal(Node $n): LoadLocal { return $n; }
    private static function asReturn(Node $n): Return_ { return $n; }
    private static function asForeach(Node $n): Foreach_ { return $n; }
    private static function asTryCatch(Node $n): TryCatch_ { return $n; }
}
