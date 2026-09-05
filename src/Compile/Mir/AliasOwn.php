<?php

namespace Compile\Mir;

/**
 * The ownership contract of an ALIAS — `$b = $s` / `$m = $obj`, and the
 * pass-through `(string)$s` that is the same thing spelled differently.
 *
 * The ONE place both {@see Passes\InsertMemoryOps} (which decides whether the
 * destination local earns a release — at scope exit and before an overwrite)
 * and the EmitLlvm traits (which emit the co-owner retain) answer "does the
 * destination co-own this value?". Same discipline, and the same reason, as
 * {@see CondOwn}: the two answers must be identical or the value is freed twice
 * or never.
 *
 * Both failure modes were paid for in one session. With only the RETAIN, every
 * `$s = $x;` in a function leaked one reference per call — the shape half the
 * stdlib opens with. With only the RELEASE — which is what happened the moment
 * the pass learned about aliases while the emitter still read a bare LoadLocal
 * — `$t = (string)$tok;` was claimed owned with nothing behind it, the
 * release-before-overwrite freed a buffer another alias still held, and
 * `__mc_hosts_lookup_in` answered '' for every host after the first.
 *
 * OBJ and STRING only. A vec/assoc alias is deliberately NOT co-owned (arrays
 * COW-copy on mutation, and blanket-retaining local assoc aliases wrote a
 * refcount into a neighbouring heap string); a CELL alias stays borrowed —
 * its word may be a raw scalar, and a retain on one is a write to a bogus
 * address.
 *
 * Type-only, like `CondOwn::armsCoverable`: no class tables, no signatures, so
 * both callers compute the identical answer. Each caller keeps its own
 * rc-eligibility guard on the value's type — a `#[Struct]`, a closure, an enum
 * case and an `Ffi\Ptr` have no refcount to touch.
 */
final class AliasOwn
{
    /**
     * Strip pass-through casts. `(string)$s` on a STRING operand returns the
     * SAME pointer ({@see Passes\EmitLlvmExpr::emitCast}), so it aliases
     * whatever its operand aliases; every other cast MINTS or retains and is an
     * owned producer in its own right.
     */
    public static function peel(Node $v): Node
    {
        while ($v->kind === Node::KIND_CAST
            && $v->type->kind === Type::KIND_STRING
            && $v->operand->type->kind === Type::KIND_STRING) {
            $v = $v->operand;
        }
        return $v;
    }

    /** Does a destination slot co-own this value — i.e. is it an alias of a
     *  local holding an rc'd by-handle value? */
    public static function coOwns(Node $v): bool
    {
        $a = self::peel($v);
        if ($a->kind !== Node::KIND_LOAD_LOCAL) { return false; }
        $k = $a->type->kind;
        return $k === Type::KIND_OBJ || $k === Type::KIND_STRING;
    }
}
