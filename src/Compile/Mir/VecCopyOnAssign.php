<?php

namespace Compile\Mir;

/**
 * `$b = $a` between array locals: does the store take an independent COPY?
 *
 * PHP arrays are values, so an assignment must separate the two names as soon
 * as either side is mutated. {@see \Compile\Mir\Passes\EmitLlvmLocals::
 * emitStoreLocal} answers that with a `__mir_array_copy` plus the adopt that
 * co-owns the copy's elements — and the answer decides OWNERSHIP, which is why
 * it cannot live only in the emitter:
 *
 *   copy  → the destination holds a FRESH rc=1 buffer it must release, and the
 *           source is untouched.
 *   alias → the destination is a borrow that must NOT be released, and the
 *           source must not be released twice.
 *
 * {@see \Compile\Mir\Passes\InsertMemoryOps} read the second answer for BOTH,
 * blocking the destination as "notowned" and the source as "vecalias", so
 * `$q = $r;` in a loop emitted no release at all for either name — 69 MB per
 * 200k iterations, and the largest row left in tools/prof/packleak.php.
 * One predicate, both readers, the way {@see AliasOwn} is one for the obj /
 * string alias contract.
 */
final class VecCopyOnAssign
{
    /**
     * The array locals mutated anywhere in `$body` — an append, an element
     * store, an `unset($a[$k])`, a by-ref builtin over argument 0, or an
     * element whose ADDRESS is taken. Over-approximate on purpose: a needless
     * copy is safe, a shared write is not.
     *
     * @return array<string, bool>
     */
    public static function mutatedLocals(Node $body): array
    {
        /** @var array<string, bool> $out */
        $out = [];
        self::scan($body, $out, false);
        return $out;
    }

    /**
     * The same scan with the static `isArray()` gate off: every local written
     * THROUGH as an array, whatever its declared type says. A `mixed` param the
     * body stores into (`$v[$k] = …`) is exactly the name {@see mutatedLocals}
     * cannot see — its base is a cell — and exactly the one that must take its
     * own share of the buffer before that store ({@see
     * \Compile\Mir\Passes\InsertMemoryOps}'s mutated-cell-param registration).
     *
     * @return array<string, bool>
     */
    public static function mutatedLocalsAnyType(Node $body): array
    {
        /** @var array<string, bool> $out */
        $out = [];
        self::scan($body, $out, true);
        return $out;
    }

    /**
     * Whether `$p` is COPIED on function entry: a by-value `array`-hinted param
     * the body element-stores into (`$p[$k] = …`, `$p[] = …`, `$p[0][] = …`)
     * takes a private copy so the store never reaches the caller's buffer
     * ({@see \Compile\Mir\Passes\EmitLlvmModule}). That copy is a fresh rc=1
     * buffer the FRAME owns, so the same answer decides OWNERSHIP too: the
     * param is released at scope exit and takes no entry retain
     * ({@see \Compile\Mir\Passes\InsertMemoryOps}, {@see
     * \Compile\Mir\Passes\EmitLlvmMemory::initRcObjSlots}). Without the release
     * every call leaked the copy — `InferTypes` alone stranded a map per
     * mutated `array` param per call. A closure or generator prologue copies
     * nothing, so neither does this.
     */
    public static function paramCopiedOnEntry(FunctionDef $fn, Param $p, bool $isClosure): bool
    {
        if ($isClosure || $fn->isGenerator) { return false; }
        if ($p->byRef || !$p->arrayHinted) { return false; }
        return self::storesInto($fn->body, $p->name);
    }

    /** Whether the local `$name` is the base of an element store anywhere in
     *  `$n` — mutated as an array, independent of its (possibly erased) type. */
    public static function storesInto(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $base = $n->array;
            while ($base->kind === Node::KIND_ARRAY_ACCESS) {
                $base = $base->array;
            }
            if ($base->kind === Node::KIND_LOAD_LOCAL && $base->name === $name) {
                return true;
            }
        }
        foreach (Walk::children($n) as $c) {
            if (self::storesInto($c, $name)) { return true; }
        }
        return false;
    }

    /**
     * Whether `$slotName = $value` must copy. Both names count: the copy is
     * needed when EITHER side is mutated later.
     *
     * @param array<string, bool> $mutated
     */
    public static function copies(Node $value, string $slotName, array $mutated): bool
    {
        return $value->kind === Node::KIND_LOAD_LOCAL
            && $value->type->isArray()
            && (isset($mutated[$value->name]) || isset($mutated[$slotName]));
    }

    /**
     * Whether php declares `$fn`'s FIRST parameter by reference over an array,
     * i.e. whether the call mutates the argument in place. Independent of HOW
     * the name is implemented here — a codegen builtin (`array_pop`), a prelude
     * body (`sort`) and a desugar (`array_multisort`) all mutate the caller's
     * array, and the copy-on-assign decision is about php's contract, not ours.
     *
     * The four cursor moves are in the list because php's internal pointer
     * lives IN the array value: `next($a)` writes the header.
     * `current` / `key` / `array_key_first` only read.
     */
    public static function mutatesArg0(string $fn): bool
    {
        $bare = $fn;
        // Monomorphize has already run, so a prelude body arrives as
        // `sort$mono$p0_vec_int` — cut the suffix, then the namespace.
        $m = \strpos($bare, '$mono$');
        if ($m !== false) { $bare = \substr($bare, 0, $m); }
        $p = \strrpos($bare, '\\');
        if ($p !== false) { $bare = \substr($bare, $p + 1); }
        foreach ([
            'array_multisort', 'array_pop', 'array_push', 'array_shift', 'array_splice',
            'array_unshift', 'array_walk', 'array_walk_recursive', 'arsort', 'asort',
            'each', 'end', 'krsort', 'ksort', 'natcasesort', 'natsort', 'next', 'prev',
            'reset', 'rsort', 'shuffle', 'sort', 'uasort', 'uksort', 'usort',
        ] as $k) {
            if ($k === $bare) { return true; }
        }
        return false;
    }

    /** @param array<string, bool> $out */
    private static function scan(Node $n, array &$out, bool $anyType): void
    {
        if ($n->kind === Node::KIND_CALL && \count($n->args) > 0) {
            // Shape first, NAME second: a kind compare is a word compare while
            // the name test is a walk of string equalities, and this pre-scan
            // visits every node of every function.
            $base = $n->args[0];
            // `array_pop($x[0])` mutates the ROOT local too.
            while ($base->kind === Node::KIND_ARRAY_ACCESS) { $base = $base->array; }
            if ($base->kind === Node::KIND_LOAD_LOCAL && ($anyType || $base->type->isArray())
                && self::mutatesArg0($n->function)) {
                $out[$base->name] = true;
            }
        }
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $arr = $n->array;
            if ($arr->kind === Node::KIND_LOAD_LOCAL && ($anyType || $arr->type->isArray())) {
                $out[$arr->name] = true;
            }
            // A NESTED element store (`$x[0][] = …`) mutates the root local too.
            $base = $arr;
            while ($base->kind === Node::KIND_ARRAY_ACCESS) { $base = $base->array; }
            if ($base->kind === Node::KIND_LOAD_LOCAL && ($anyType || $base->type->isArray())) {
                $out[$base->name] = true;
            }
        }
        // `unset($a[$k])` REMOVES an entry — a mutation exactly like a store,
        // and the one lvalue shape this scan did not see: `$b = $a;
        // unset($a['x']);` took no copy and the unset removed the entry from
        // `$b` as well (php keeps it).
        if ($n->kind === Node::KIND_UNSET) {
            foreach ($n->targets as $t) {
                if ($t->kind !== Node::KIND_ARRAY_ACCESS) { continue; }
                $base = $t;
                while ($base->kind === Node::KIND_ARRAY_ACCESS) { $base = $base->array; }
                if ($base->kind === Node::KIND_LOAD_LOCAL && ($anyType || $base->type->isArray())) {
                    $out[$base->name] = true;
                }
            }
        }
        // Taking an element's ADDRESS by reference can mutate the vec.
        if ($n->kind === Node::KIND_REF_ADDR) { self::markElemBase($n->lvalue, $out, $anyType); }
        if ($n->kind === Node::KIND_CALL) {
            foreach ($n->args as $a) { self::markElemBase($a, $out, $anyType); }
        }
        if ($n->kind === Node::KIND_METHOD_CALL) {
            foreach ($n->args as $a) { self::markElemBase($a, $out, $anyType); }
        }
        if ($n->kind === Node::KIND_STATIC_CALL) {
            foreach ($n->args as $a) { self::markElemBase($a, $out, $anyType); }
        }
        foreach (Walk::children($n) as $c) { self::scan($c, $out, $anyType); }
    }

    /** @param array<string, bool> $out */
    private static function markElemBase(Node $a, array &$out, bool $anyType): void
    {
        if ($a->kind !== Node::KIND_ARRAY_ACCESS) { return; }
        $arr = $a->array;
        if ($arr->kind === Node::KIND_LOAD_LOCAL && ($anyType || $arr->type->isArray())) {
            $out[$arr->name] = true;
        }
    }
}
