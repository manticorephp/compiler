<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ArrayAccess_;
use Compile\Mir\Call;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * A read off a FRESH base whose result is not a scalar kept the base forever.
 *
 * `f()->data`, `(new M())->name`, `$c->receive()->data`, `rows()[0]`: the call
 * hands back a +1 object or array, the read takes one word out of it — and the
 * container has no owner.
 * For a scalar result the emitter drops the base right there
 * ({@see EmitLlvm::baseTempRelease}), because the word it keeps is a copy. For
 * a string, an array, an object or a cell it cannot: the value it hands out is
 * BORROWED from the base, so freeing the base would free the value the read
 * just returned. So nothing freed it: one object (and everything it held) per
 * read, ~96 B for `$c->receive()->data`.
 *
 * The base gets an owner instead: `($__fb_N = f())->data`, `($__fb_N = rows())[0]`. That is exactly the
 * shape the ownership pass already handles for a user-written
 * `($m = f())->data` — {@see InsertMemoryOps} registers the name (every store to
 * it is the one owned producer), the emitter null-inits its slot, releases it
 * before the next store (a loop) and at scope exit, and every consumer that
 * outlives that takes its own +1 as it would from any local. The value read out
 * stays valid for as long as the statement needs it.
 *
 * The object now lives to the next evaluation of the same read or to the end
 * of the frame, where php frees it right after the read: a `__destruct` on such
 * a temporary runs later than php's (it never ran at all before).
 *
 * Runs after the last type inference (the gate reads the result's type) and
 * before {@see InsertMemoryOps}, which must see the new store.
 */
final class SpillFreshBases
{
    public const NAME = 'spill-fresh-bases';

    private int $counter = 0;

    /** @var array<string, bool> functions whose result is not a +1 (FFI, by-ref) */
    private array $notOwned = [];

    /** @var array<string, bool> classes whose instances are not rc-managed */
    private array $notRc = [];

    public function run(Module $module): Module
    {
        $this->notOwned = ['__mir_fiber_current' => true];
        foreach ($module->functions as $fn) {
            if ($fn->ffiSymbol !== null || $fn->returnsByRef) { $this->notOwned[$fn->name] = true; }
        }
        $this->notRc = ['Ffi\\Ptr' => true, 'Closure' => true];
        foreach ($module->classes as $name => $cd) {
            if ($cd->isStruct) { $this->notRc[$name] = true; }
        }
        foreach ($module->enums as $name => $unused) { $this->notRc[$name] = true; }
        foreach ($module->typeDefs as $name => $unused) { $this->notRc[$name] = true; }
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $this->rewrite($fn->body);
        }
        return $module;
    }

    private function rewrite(Node $n): void
    {
        foreach (Walk::children($n) as $c) { $this->rewrite($c); }
        if ($n instanceof PropertyAccess_) {
            if ($this->scalarResult($n) || !$this->isFreshRcBase($n->object, false)) { return; }
            $n->object = $this->owner($n->object);
        } elseif ($n instanceof ArrayAccess_) {
            if ($this->scalarResult($n) || !$this->isFreshRcBase($n->array, true)) { return; }
            $n->array = $this->owner($n->array);
        }
    }

    /** The emitter already drops the base of a scalar read ({@see EmitLlvm::baseTempRelease}). */
    private function scalarResult(Node $n): bool
    {
        $rk = $n->type->kind;
        return $rk === Type::KIND_INT || $rk === Type::KIND_FLOAT || $rk === Type::KIND_BOOL
            || $rk === Type::KIND_NULL;
    }

    private function owner(Node $base): StoreLocal
    {
        $name = '__fb_' . (string)$this->counter;
        $this->counter = $this->counter + 1;
        return new StoreLocal($name, $base, $base->type);
    }

    /**
     * A +1 container nobody else holds: a call's result (an object, an array,
     * or a cell carrying one), `new`, `clone`. `$array` asks for an element
     * read's base (an array), else a property read's (an object).
     */
    private function isFreshRcBase(Node $b, bool $array): bool
    {
        $t = $b->type;
        if ($t->kind === Type::KIND_OBJ && !$array) {
            $cls = $t->class ?? '';
            if ($cls === '' || isset($this->notRc[$cls]) || \str_starts_with($cls, '__closure_')) { return false; }
        } elseif ($t->kind === Type::KIND_ARRAY && $array) {
            if (!$t->isVec() && !$t->isAssoc()) { return false; }
        } elseif ($t->kind !== Type::KIND_CELL) {
            return false;
        }
        $k = $b->kind;
        if ($b instanceof Call) { return !isset($this->notOwned[\ltrim($b->function, '\\')]); }
        if ($k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL) { return true; }
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        return $k === Node::KIND_INVOKE || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE;
    }
}
