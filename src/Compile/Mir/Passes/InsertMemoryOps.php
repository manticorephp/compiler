<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\Effects;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * The ARENA scope of the memory plan: a function with at least one Arena
 * allocation (the `hybrid` default confines non-escaping allocations there,
 * `--memory=arena` confines more) gets one whole-frame scope — `arena_enter`
 * at body entry, `arena_leave` at every exit (bulk free, O(1), no per-object
 * rc). A loop-confined allocation lives until the frame's arena leaves: a
 * bounded in-frame leak, never a UAF. A NoRefcount (`--memory=rc`) allocation
 * is released like any rc value — by {@see OwnershipFlow}, which owns every rc
 * local per program point.
 *
 * The static predicates below are the shared halves of the foreach /
 * element-read / array-alias co-ownership contracts that pass and the emitter
 * both ask.
 */
final class InsertMemoryOps implements Pass
{
    public const NAME = 'insert-memory-ops';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferAllocKind::NAME]; }

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            if (!self::hasArenaAlloc($fn->body)) { continue; }
            $stmts = [new MemoryOp_('arena_enter', '', null, Type::void())];
            foreach ($fn->body->stmts as $s) { $stmts[] = $s; }
            // Fall-through exit; a `return` leaves the arena itself.
            $stmts[] = new MemoryOp_('arena_leave', '', null, Type::void());
            $fn->body->stmts = $stmts;
            if (\Compile\Stats::$on) { \Compile\Stats::bump('own.arena.scopes', 1); }
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private static function hasArenaAlloc(Node $n): bool
    {
        if (($n->effects & Effects::ALLOC) !== 0 && $n->allocKind === AllocationKind::ARENA) { return true; }
        foreach (Walk::children($n) as $c) {
            if (self::hasArenaAlloc($c)) { return true; }
        }
        return false;
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
}
