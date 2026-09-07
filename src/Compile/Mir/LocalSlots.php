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
    /** @var array<string, Type> by-ref param name → its DECLARED type.
     *  A store through the reference must match what the CALLER's slot reads,
     *  and the declared param type is the only statement of that contract —
     *  {@see Passes\Monomorphize} specializes a by-ref param to the caller's
     *  actual slot type precisely so this is knowable here. */
    public array $refParamTypes = [];
    /** @var array<string, string> static-local / `global $x` name → global cell */
    public array $globalBacked = [];
    /** @var array<string, true> locals captured by-ref by a closure (heap-boxed) */
    public array $byRefCaptured = [];
    /** @var array<string, string> name → the slot it owned BEFORE `$name = &$src`
     *  rebound it to `$src`'s slot ('' when it owned none). Presence means the
     *  name is currently an ALIAS, which `unset($name)` has to know: php's
     *  `unset` on a reference breaks that one binding and leaves the aliased
     *  storage alone, while zeroing the shared slot wipes the source. Restoring
     *  the saved slot (rather than allocating a new one) keeps the unbind free
     *  of an alloca inside a loop.
     *  Declared LAST — a field added mid-struct shifts every later offset. */
    public array $aliasLocals = [];
    /** @var array<string, true> locals a `&` in a STORING position points at —
     *  a `[&$a]` element today. Heap-boxed on entry exactly like
     *  {@see $byRefCaptured}, for the same reason: the reference outlives the
     *  frame's stack slot. Appended LAST for the same offset reason as above. */
    public array $refCellTargets = [];

    /**
     * True when this function contains a `try`. A try is `_setjmp` and a throw
     * is `_longjmp`, and longjmp restores the callee-saved registers to what
     * they held at the setjmp — so any local slot `-O2` promoted OUT of memory
     * REVERTS on the catch path to its value before the try. That is C's rule
     * (a local modified between setjmp and longjmp is indeterminate unless it
     * is volatile), and it silently un-did every assignment a try body made:
     * `lower_module`'s `$stmts = []` came back as the array it had just
     * released, the catch released it a second time, and the `Program` that
     * owned the buffer then double-freed it — POINTER_BEING_FREED_WAS_NOT_
     * ALLOCATED, three frames deep in a drop body with nothing wrong in it.
     * Every local of such a function is pinned to the frame instead
     * ({@see \Compile\Mir\Passes\EmitLlvmLocals::localSlotAlloca}); the set is
     * not narrowed to "assigned inside the try" because a local is written by
     * a dozen node kinds and missing one is a silent miscompile again.
     * Declared LAST — a field added mid-struct shifts every later offset.
     */
    public bool $sjljPinAll = false;

    /**
     * Locals a reference CELL points at ({@see \Compile\Mir\RefCell_}). Only a
     * plain local is collected here — a property / element / static-prop source
     * is addressed through its container and needs no per-frame box.
     */
    /** Narrow to the concrete class so a field read uses ITS offsets. */
    private static function asRefCell(Node $n): RefCell_ { return $n; }

    public function collectRefCellTargets(Node $n): void
    {
        if ($n->kind === Node::KIND_REF_CELL) {
            $rc = self::asRefCell($n);
            $lv = $rc->refSource;
            if ($lv->kind === Node::KIND_LOAD_LOCAL) {
                $this->refCellTargets[$lv->name] = true;
            }
            return;
        }
        foreach (Walk::children($n) as $c) {
            $this->collectRefCellTargets($c);
        }
    }

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
    /**
     * Set {@see $sjljPinAll} from one function body. Any `try` anywhere in the
     * tree counts: a rethrow lands on an OUTER setjmp whose register snapshot
     * is older still, so nesting only makes the staleness worse.
     */
    public function collectSjljPins(Node $n): void
    {
        if ($this->sjljPinAll) { return; }
        if ($n->kind === Node::KIND_TRY_CATCH) { $this->sjljPinAll = true; return; }
        foreach (Walk::children($n) as $c) { $this->collectSjljPins($c); }
    }
}
