<?php

namespace Compile\Mir;

/**
 * The ownership contract of a CONDITIONAL expression (ternary, `?:`, `??`,
 * `match`) — the ONE place both {@see Passes\InsertMemoryOps} (which decides
 * whether the destination local earns a scope-exit release) and the EmitLlvm
 * traits (which emit the per-arm +1) answer "is this value owned?".
 *
 * The arms of a conditional DISAGREE about ownership — one may be a fresh +1
 * (concat / new / call), the other a borrow (local / property / element read) —
 * and the node kind alone says nothing about which one ran. So the emitter
 * normalizes EVERY arm to +1 of the RESULT type and every consumer then treats
 * the conditional as an owned producer, exactly like a call. That only holds if
 * the two passes agree exactly: emitter normalizes but InsertMemoryOps says
 * borrowed ⇒ the value leaks; the other way round ⇒ it is double-freed. Hence
 * one predicate, not two copies of one.
 *
 * `armsCoverable` is deliberately a TYPE-ONLY test — no class tables, no
 * signatures — so both callers compute the identical answer. Each caller adds
 * its own rc-eligibility guard on the RESULT type (a #[Struct] / closure / enum
 * / `Ffi\Ptr` is not rc-managed).
 *
 * An UNKNOWN-typed arm is NEVER coverable: its word may be a raw pointer or a
 * NaN-tagged cell at runtime, and a retain on a tagged word writes the refcount
 * into a bogus address. Those conditionals keep the historical borrowed
 * treatment (a leak at worst, never corruption).
 */
final class CondOwn
{
    public static function isConditional(Node $n): bool
    {
        $k = $n->kind;
        return $k === Node::KIND_TERNARY || $k === Node::KIND_NULLCOALESCE
            || $k === Node::KIND_MATCH;
    }

    /**
     * The value-producing arms. A short ternary (`?:`) has no `then` — its
     * CONDITION is that arm (emitTernary stores the raw condition value).
     * @return Node[]
     */
    public static function arms(Node $n): array
    {
        $k = $n->kind;
        if ($k === Node::KIND_TERNARY) {
            $t = self::asTernary($n);
            $then = $t->then;
            if ($then === null) { $then = $t->cond; }
            return [$then, $t->else_];
        }
        if ($k === Node::KIND_NULLCOALESCE) {
            $nc = self::asNullCoalesce($n);
            return [$nc->left, $nc->right];
        }
        if ($k === Node::KIND_MATCH) {
            $out = [];
            foreach (self::asMatch($n)->arms as $arm) { $out[] = $arm->body; }
            return $out;
        }
        return [];
    }

    /** Can EVERY arm be handed a +1 of the conditional's own result type? */
    public static function armsCoverable(Node $n): bool
    {
        $arms = self::arms($n);
        if (\count($arms) === 0) { return false; }
        foreach ($arms as $arm) {
            if (self::isEmptyArrayLit($arm)) { continue; }
            if (!self::armCoverable($arm->type, $n->type)) { return false; }
        }
        return true;
    }

    /**
     * An EMPTY array literal is coverable at ANY depth: a retain or a release of
     * it walks zero elements, whatever flavor the result names (and the empty
     * singleton is immortal, so both are no-ops on it outright).
     *
     * Without this it is the arm that disqualifies the whole conditional, because
     * `[]` carries no element type and {@see sameArrayShape} then reads the pair
     * as two different shapes. `return $this->value === null ? [] : [$this->value];`
     * — `Node::children()`, the hottest method in the compiler — was therefore
     * treated as BORROWED at the return, and {@see Passes\EmitLlvmModule::
     * emitReturn} put a +1 on top of the fresh literal's own: ONE array leaked per
     * call, 46431 blocks in a single file's front-end run. The statement-`if`
     * spelling of the same function never leaked, which is what named it.
     */
    public static function isEmptyArrayLit(?Node $n): bool
    {
        if ($n === null) { return false; }
        return $n->kind === Node::KIND_ARRAY_LIT && \count(self::asArrayLit($n)->elements) === 0;
    }

    /**
     * Is the result type rc-managed by its SHAPE alone? A class-bearing result
     * (obj / union) answers false here and is decided by the caller, which holds
     * the class tables — the only part of the contract the two passes compute
     * separately, so they keep the guards (struct / closure / enum / `Ffi\Ptr`)
     * character-for-character identical.
     *
     * A cell-KEYED array is deliberately NOT rc here: it is neither vec nor
     * assoc, so {@see Passes\EmitLlvm::discardReleaseFlavor} gives it no flavor
     * and there is nothing to retain it with.
     */
    public static function shapeIsRc(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_STRING || $k === Type::KIND_CELL) { return true; }
        if ($k === Type::KIND_ARRAY) { return $t->isVec() || $t->isAssoc(); }
        return false;
    }

    private static function armCoverable(Type $arm, Type $res): bool
    {
        $ak = $arm->kind;
        $rk = $res->kind;
        if ($ak === Type::KIND_UNKNOWN) { return false; }
        // A null arm carries 0 / the boxed-null sentinel — nothing to retain.
        if ($ak === Type::KIND_NULL) { return true; }
        // A cell result boxes every arm and its ownership is the tag-guarded
        // __mir_cell_retain / __mir_cell_drop discipline, which accepts any
        // concrete payload.
        if ($rk === Type::KIND_CELL) { return true; }
        // An all-object union rides raw, exactly like a bare object pointer.
        if ($rk === Type::KIND_UNION) {
            return $ak === Type::KIND_OBJ || $ak === Type::KIND_UNION;
        }
        if ($ak !== $rk) { return false; }
        if ($rk === Type::KIND_ARRAY) { return self::sameArrayShape($arm, $res); }
        return true;
    }

    /**
     * An array arm is retained at the DEPTH the result's flavor drops (buffer /
     * str / obj / cell elements), so a `vec[string]` arm under a `vec[cell]`
     * result would be walked by the wrong helper — inferTernary types the join
     * from the THEN arm, so the pair really can differ. Require the same key
     * shape and the same element kind (and class).
     */
    private static function sameArrayShape(Type $arm, Type $res): bool
    {
        if ($arm->isAssoc() !== $res->isAssoc()) { return false; }
        $ae = $arm->element;
        $re = $res->element;
        if ($ae === null || $re === null) { return $ae === null && $re === null; }
        if ($ae->kind !== $re->kind) { return false; }
        if ($ae->kind === Type::KIND_OBJ) { return ($ae->class ?? '') === ($re->class ?? ''); }
        return true;
    }

    private static function asArrayLit(Node $n): ArrayLit { return $n; }
    private static function asTernary(Node $n): Ternary { return $n; }
    private static function asNullCoalesce(Node $n): NullCoalesce_ { return $n; }
    private static function asMatch(Node $n): Match_ { return $n; }
}
