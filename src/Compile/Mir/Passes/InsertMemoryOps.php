<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\Effects;
use Compile\Mir\FunctionDef;
use Compile\Mir\LoadLocal;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * The ARENA track of the memory plan: turns the allocation-kind verdict into
 * explicit {@see MemoryOp_} nodes, so EmitLlvm consumes a plan instead of
 * inventing one.
 *
 *  - Arena allocations → one whole-frame arena scope: `arena_enter` at body
 *    entry, `arena_leave` at exit (bulk free, O(1), no per-object RC). Scope
 *    is per function: a loop-confined allocation lives until the frame's
 *    arena leaves — a bounded in-frame leak, never a UAF.
 *  - NoRefcount allocations → a per-local `release` at scope exit, for a
 *    local EVERY store of which assigns such an allocation.
 *
 * rc locals are NOT this pass's: their ownership is decided per program point
 * by {@see OwnershipFlow}. The static predicates below are the shared halves
 * of the foreach / element-read / array-alias co-ownership contracts that pass
 * and the emitter both ask.
 */
final class InsertMemoryOps implements Pass
{
    public const NAME = 'insert-memory-ops';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferAllocKind::NAME]; }

    /** @var array<string, string> owned local name → heap flavor */
    private array $ownedFlavor = [];

    /** @var array<string, bool> locals disqualified by a non-owning store */
    private array $blocked = [];

    /** @var string[] owned locals in first-seen order (stable dump) */
    private array $ownedOrder = [];

    /** @var array<string, Type> owned local name → value type for the release target */
    private array $ownedType = [];

    /** Set when the function has at least one Arena allocation. */
    private bool $hasArena = false;

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->lowerFunction($fn);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function lowerFunction(FunctionDef $fn): void
    {
        $this->ownedFlavor = [];
        $this->blocked = [];
        $this->ownedOrder = [];
        $this->ownedType = [];
        $this->hasArena = false;
        $this->scanStores($fn->body);
        foreach ($fn->params as $p) { $this->blocked[$p->name] = true; }

        $releases = [];
        foreach ($this->ownedOrder as $name) {
            if (isset($this->blocked[$name])) { continue; }
            $releases[] = new MemoryOp_('release', $this->ownedFlavor[$name],
                new LoadLocal($name, $this->ownedType[$name]), Type::void());
        }
        if (\Compile\Stats::$on) {
            \Compile\Stats::bump('own.arena.released', \count($releases));
            foreach ($this->ownedOrder as $name) {
                if (isset($this->blocked[$name])) { \Compile\Stats::bump('own.arena.blocked', 1); }
            }
        }
        if (!$this->hasArena && \count($releases) === 0) { return; }

        $stmts = $fn->body->stmts;
        if ($this->hasArena) {
            $prefixed = [new MemoryOp_('arena_enter', '', null, Type::void())];
            foreach ($stmts as $s) { $prefixed[] = $s; }
            $stmts = $prefixed;
        }
        // Scope-exit cleanup. Return paths exit before reaching this, so it only
        // fires on fall-through.
        foreach ($releases as $r) { $stmts[] = $r; }
        if ($this->hasArena) {
            $stmts[] = new MemoryOp_('arena_leave', '', null, Type::void());
        }
        $fn->body->stmts = $stmts;
    }

    /**
     * Flag any Arena allocation (drives the frame arena scope) and collect the
     * NoRefcount owned locals (drive the per-local releases). A foreach binding
     * and a box-back `$x = box($x)` are never an allocation of this frame.
     */
    private function scanStores(Node $n): void
    {
        if (($n->effects & Effects::ALLOC) !== 0 && $n->allocKind === AllocationKind::ARENA) {
            $this->hasArena = true;
        }
        if ($n->kind === Node::KIND_FOREACH) {
            $fe = self::asForeachNode($n);
            $this->blocked[$fe->valueVar] = true;
            if ($fe->keyVar !== null) { $this->blocked[$fe->keyVar] = true; }
        }
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = self::asStoreLocalNode($n);
            $name = $sl->name;
            $value = $sl->value;
            if (self::slotStoredType($sl)->kind === Type::KIND_CELL && $value->kind === Node::KIND_LOAD_LOCAL
                && self::asLoadLocalNode($value)->name === $name && $value->type->kind !== Type::KIND_CELL) {
                $this->blocked[$name] = true;
                return;
            }
            $flavor = $this->allocFlavor($value);
            if ($flavor === null) {
                $this->blocked[$name] = true;
            } elseif (!isset($this->ownedFlavor[$name])) {
                $this->ownedOrder[] = $name;
                $this->ownedFlavor[$name] = $flavor;
                $this->ownedType[$name] = $value->type;
            }
            $this->scanStores($value);
            return;
        }
        foreach (Walk::children($n) as $c) { $this->scanStores($c); }
    }

    /**
     * Heap flavor of `$value` iff it is a NoRefcount allocation — the
     * per-local release case. Null otherwise (not an alloc, arena, escapes,
     * or non-heap type).
     */
    private function allocFlavor(Node $value): ?string
    {
        if (($value->effects & Effects::ALLOC) === 0) { return null; }
        if ($value->allocKind !== AllocationKind::NO_REFCOUNT) { return null; }
        $t = $value->type;
        $k = $t->kind;
        if ($k === Type::KIND_STRING) { return 'string'; }
        if ($t->isVec()) { return 'vec'; }
        if ($t->isAssoc()) { return 'assoc'; }
        if (\Compile\Mir\Ownership::isClosureValueType($t)) { return 'closure'; }
        if ($k === Type::KIND_OBJ) { return 'obj'; }
        if ($k === Type::KIND_CELL) { return 'cell'; }
        return null;
    }

    /**
     * Does `$b = $a` on an array make the destination a CO-OWNER?
     *
     * A local array alias is the one rc shape with no answer of its own: the
     * emitter neither copies it (that needs a proven mutation) nor retains it,
     * so the two names SHARE one buffer.
     *
     * Where this answers true the emitter takes a +1 and the destination owns.
     * ⚠ ONE predicate for both halves, and deliberately narrow — the blanket
     * version of this retain is the one `EmitLlvmLocals` records as having
     * written rc into a live heap string (the enum backing "int"→"jnt"). Both
     * sides must name the SAME element, and the element must be one whose
     * retain/release pair is fully exercised: a STRING or a plain OBJECT.
     * A cell / unknown / erased element is exactly the raw-word case that
     * corrupted, and it is refused here.
     */
    public static function arrayAliasCoOwns(?Type $value, ?Type $slot,
                                            array $enums, array $classes = []): bool
    {
        if ($value === null || $slot === null) { return false; }
        if (!$value->isArray() || !$slot->isArray()) { return false; }
        $ve = $value->element;
        $se = $slot->element;
        if ($ve === null || $se === null) { return false; }
        // Two CLOSURE elements are one representation whatever they are
        // spelled (`closure` / `obj<__closure_N>`); the alias's repr-walk pair
        // ({@see \Compile\MemoryAbi::ARRAY_REPR_CLO}) co-owns and gives back.
        if (self::isClosureElem($ve) && self::isClosureElem($se)) { return true; }
        if ($ve->kind !== $se->kind) { return false; }
        if ($ve->kind !== Type::KIND_STRING && $ve->kind !== Type::KIND_OBJ) { return false; }
        if ($ve->kind === Type::KIND_OBJ && ($ve->class ?? '') !== ($se->class ?? '')) { return false; }
        return self::elemReadCoOwns($ve, $enums, $classes);
    }

    private static function isClosureElem(Type $t): bool
    {
        if ($t->kind === Type::KIND_CLOSURE) { return true; }
        $c = $t->class ?? '';
        return $t->kind === Type::KIND_OBJ && ($c === 'Closure' || \str_starts_with($c, '__closure_'));
    }

    /** {@see \Compile\Mir\Ownership::elemReadCoOwns} */
    public static function elemReadCoOwns(?Type $t, array $enums, array $classes = []): bool
    {
        return \Compile\Mir\Ownership::elemReadCoOwns($t, $enums, $classes);
    }

    /**
     * The ONE condition behind foreach-value co-ownership, shared by both
     * halves: {@see OwnershipFlow}, which binds the loop variable OWNED so it is
     * dropped, and {@see EmitLlvmControl}'s unified-array loop, which takes
     * the matching +1 and drops the previous iteration's. One half alone is a
     * leak or a double free ({@see \Compile\Debug::$rcForeachValueOwns}).
     *
     * A PROVEN vec/assoc base only. That is not caution, it is the agreement
     * itself: `emitForeach` routes a generator, a Traversable and an erased
     * carrier elsewhere — the last of those CLASSIFIES AT RUNTIME — and this
     * pass cannot know which arm will run. `byRef` is out because `&$v` binds
     * the slot, it does not copy it.
     *
     * @param array<string, mixed> $enums enum name → def; enum values are non-rc
     */
    public static function foreachValueCoOwns(\Compile\Mir\Foreach_ $fe, array $enums, array $classes = []): bool
    {
        return self::foreachValueSlotType($fe, $enums, $classes) !== null;
    }

    /**
     * The type a co-owning loop binds its value at — the flavor both halves
     * retain and release by — or null when the loop does not co-own.
     *
     * Besides a proven vec/assoc, the two ITERATOR loops `emitForeach` routes
     * by the subject's static type co-own too, because their step already
     * hands out a +1 and a borrowed loop variable stranded it on every
     * iteration:
     *  - a GENERATOR subject: its frame owns `current`@16 and the loop takes
     *    its own +1 of it ({@see EmitLlvmGenerator::emitYield}); bound at the
     *    element type the loop unboxes to, a tagged cell when that is erased.
     *  - an Iterator-protocol subject whose `current()` answers a CELL: a
     *    Generator iterator (`getIterator(): \Generator`) and an interface one
     *    that classifies at run time — every arm of that step is +1.
     *    A user Iterator CLASS answers its declared raw type, which the pass
     *    does not see; it stays borrowed.
     *
     * @param array<string, mixed> $enums
     */
    public static function foreachValueSlotType(\Compile\Mir\Foreach_ $fe, array $enums, array $classes = []): ?Type
    {
        if (!\Compile\Debug::$rcForeachValueOwns) { return null; }
        if ($fe->byRef) { return null; }
        $at = $fe->array->type;
        if ($at->isVec() || $at->isAssoc()) {
            return self::elemReadCoOwns($at->element, $enums, $classes) ? $at->element : null;
        }
        if ($at->kind !== Type::KIND_OBJ) { return null; }
        if (($at->class ?? '') === 'Generator') {
            $el = $at->element;
            if ($el === null || $el->kind === Type::KIND_CELL || $el->kind === Type::KIND_UNKNOWN) {
                return Type::cell();
            }
            return self::elemReadCoOwns($el, $enums, $classes) ? $el : null;
        }
        $ic = $fe->iterClass;
        if ($ic === 'Generator' || ($ic !== '' && !isset($classes[$ic]))) { return Type::cell(); }
        return null;
    }

    /**
     * Loop-variable NAMES this function must not co-own, because at least one
     * `foreach` binding the name does NOT co-own.
     *
     * ★★★ The pass decides per NAME and the emitter per SITE, and that is the
     * whole reason this exists. `InferScans::scanByRefCaptureNode` binds `$c`
     * TWICE — once over `$n->captures`, once over `Walk::children($n)`. Let one
     * loop co-own and the other store a borrow into the same slot, and the
     * scope-exit release the first loop earned is paid by the second loop's
     * BORROWED node: the child is freed while the tree still holds it, and the
     * next walk faults inside `Walk::children`. That is a gen-2 compiler that
     * cannot compile hello world.
     *
     * So a name is co-owned only when EVERY foreach that binds it agrees —
     * the same "every store or none" rule {@see EmitLlvmMemory::collectOwnElemLocals}
     * applies to element-owning locals. Both halves call this.
     *
     * @param array<string, mixed> $enums
     * @return array<string, bool> name → vetoed
     */
    public static function foreachOwnVetoes(Node $body, array $enums, array $classes = []): array
    {
        $veto = [];
        self::collectForeachVetoes($body, $enums, $classes, $veto);
        return $veto;
    }

    /** @param array<string, bool> $veto */
    private static function collectForeachVetoes(Node $n, array $enums, array $classes, array &$veto): void
    {
        if ($n->kind === Node::KIND_FOREACH) {
            $fe = $n;
            if (!self::foreachValueCoOwns($fe, $enums, $classes)) { $veto[$fe->valueVar] = true; }
        }
        foreach (Walk::children($n) as $c) { self::collectForeachVetoes($c, $enums, $classes, $veto); }
    }


    /**
     * The type of what the SLOT actually receives — which is NOT always the
     * value's type, and the release reads the SLOT.
     *
     * {@see EmitLlvmLocals::emitStoreLocal} has two arms where the store NODE's
     * type and its VALUE's type deliberately disagree, and in both the emitter
     * CONVERTS on the way into the slot:
     *   - store typed CELL, value concrete — the merge box-back InferTypes plants
     *     at an if/else join; the slot receives a NaN-BOXED word;
     *   - store typed a concrete scalar/string, value a CELL — the by-ref
     *     representation plant; the slot receives the RAW payload.
     * Everywhere else `inferStoreLocal` types the store = its value, so this
     * answers exactly what it always did.
     *
     * Reading the value's type through those two arms is what emitted
     * `__mir_array_release_buf` on a boxed array cell: `/** @var int[]|null $c *\/
     * $c = mkList();` inside a loop — a plain owned CALL, a cell-typed slot —
     * SIGSEGV'd at scope exit on `0xfff7…`, i.e. the tag, not a heap pointer.
     * One owner for the slot's representation, or the release frees a tag.
     */
    public static function slotStoredType(StoreLocal $sl): Type
    {
        $st = $sl->type->kind;
        $vt = $sl->value->type->kind;
        if ($st === Type::KIND_CELL && $vt !== Type::KIND_CELL) { return $sl->type; }
        if ($vt === Type::KIND_CELL
            && ($st === Type::KIND_INT || $st === Type::KIND_FLOAT
                || $st === Type::KIND_BOOL || $st === Type::KIND_STRING)) {
            return $sl->type;
        }
        return $sl->value->type;
    }


    private static function asForeachNode(Node $n): \Compile\Mir\Foreach_ { return $n; }
    private static function asStoreLocalNode(Node $n): StoreLocal { return $n; }
    private static function asLoadLocalNode(Node $n): LoadLocal { return $n; }
}
