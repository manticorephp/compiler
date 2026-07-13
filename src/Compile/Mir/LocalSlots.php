<?php

namespace Compile\Mir;

/**
 * Where each local of the function being emitted lives.
 *
 * The common case is an `alloca` slot ({@see $slots}). Three kinds of local are
 * indirected instead:
 *  - a by-ref param ({@see $refLocals}) holds the CALLER's address — loads and
 *    stores deref it;
 *  - a static local, or a `global $x` name in `__main`, is backed by a module
 *    global cell ({@see $globalBacked}) so its value survives the frame;
 *  - a local captured by-ref by a closure ({@see $byRefCaptured}) is heap-boxed
 *    so the closure and the frame see the same cell.
 *
 * One instance per {@see EmitLlvm::emit()}; refilled per function.
 */
final class LocalSlots
{
    /** @var array<string, string> local name → alloca SSA id */
    public array $slots = [];
    /** @var array<string, true> by-ref param names in the current fn */
    public array $refLocals = [];
    /** @var array<string, string> static-local / `global $x` name → global cell */
    public array $globalBacked = [];
    /** @var array<string, true> locals captured by-ref by a closure (heap-boxed) */
    public array $byRefCaptured = [];

    public function collectByRefCaptured(Node $n): void
    {
        if ($n->kind === Node::KIND_CLOSURE) {
            $cl = $n;
            $i = 0;
            foreach ($cl->captures as $c) {
                if (($cl->captureByRef[$i] ?? false) && $c->kind === Node::KIND_LOAD_LOCAL) {
                    $this->byRefCaptured[$c->name] = true;
                }
                $i = $i + 1;
            }
            return;
        }
        foreach (Walk::children($n) as $c) {
            $this->collectByRefCaptured($c);
        }
    }

    /**
     * Pre-scan: register every static-local name → its global cell so
     * Load/StoreLocal route to the cell and preallocateLocals skips an
     * alloca. Recurses through structured control flow.
     */
    public function collectStatics(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $this->globalBacked[$n->name] = $n->cell;
            return;
        }
        if ($k === Node::KIND_BLOCK) {
            foreach ($n->stmts as $s) { $this->collectStatics($s); }
            return;
        }
        if ($k === Node::KIND_IF) {
            $this->collectStatics($n->then);
            if ($n->else !== null) { $this->collectStatics($n->else); }
            return;
        }
        if ($k === Node::KIND_WHILE) {
            $this->collectStatics($n->body);
            return;
        }
        if ($k === Node::KIND_FOR) {
            $this->collectStatics($n->body);
            return;
        }
        if ($k === Node::KIND_DOWHILE) {
            $this->collectStatics($n->body);
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $this->collectStatics($n->body);
            return;
        }
        if ($k === Node::KIND_SWITCH) {
            foreach ($n->arms as $arm) {
                foreach ($arm->body as $s) { $this->collectStatics($s); }
            }
            return;
        }
    }
}
