<?php

namespace Compile\Mir\Passes;

use Compile\Mir\LoadLocal;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\Unset_;
use Compile\Mir\Walk;

/**
 * A fresh container a read or a write goes through gets an owner.
 *
 * `f()->data`, `(new M())->name`, `$c->receive()->data`, `rows()[0]`: the call
 * hands back a +1 object or array, the read takes one word out of it — and the
 * container has no owner. For a scalar result the emitter drops the base right
 * there ({@see EmitLlvm::baseTempRelease}), because the word it keeps is a copy.
 * For a string, an array, an object or a cell it cannot: the value it hands out
 * is BORROWED from the base, so freeing the base would free the value the read
 * just returned. So nothing freed it: one object (and everything it held) per
 * read, ~96 B for `$c->receive()->data`.
 *
 * READS: the base is stored into a hidden local in place — `($__fb_N =
 * f())->data`, the shape {@see InsertMemoryOps} already handles for a
 * user-written `($m = f())->data` (release before the next store, and at scope
 * exit).
 *
 * WRITES and REFERENCES (`f()->arr[] = x`, `f()->n++`, `unset(f()->a[0])`,
 * `$r = &f()->arr`, `foreach (f()->arr as &$v)`, a by-ref argument) must not
 * get that in-place store: a write-back re-evaluates the base expression
 * ({@see EmitLlvmBuiltins::vecWriteBack}), so the store ran twice and the
 * second released the object the first write was still going through
 * (`mk()->arr[] = 3` aborted). Their chains are pinned structurally first
 * ({@see pinWrites}). When the chain is evaluated unconditionally by a
 * statement, its fresh base is HOISTED instead — `$__fb_N = f();` before the
 * statement, `$__fb_N` in the chain — so any number of evaluations reads the
 * same object, and it is evaluated once, as php does. A chain under a
 * conditional, or on a CELL-typed base, keeps the old behaviour (a leak, never
 * a double free).
 *
 * Every hidden local is `unset` right after the statement that made it, which
 * is where php has destroyed the temporary by the next statement; a statement
 * that leaves the block (`return`, `throw`, …) and a reference that outlives
 * the statement (`$r = &f()->arr`) leave it to the scope-exit release. php
 * frees a temporary right after the read, INSIDE the statement, so
 * `echo f()->name` still runs a `__destruct` after the echo, not before it.
 *
 * Runs after the last type inference (the gate reads the result's type) and
 * before {@see InsertMemoryOps}, which must see the new stores.
 */
final class SpillFreshBases
{
    public const NAME = 'spill-fresh-bases';

    private int $counter = 0;

    /** @var array<string, bool> functions whose result is not a +1 (FFI, by-ref) */
    private array $notOwned = [];

    /** @var array<string, bool> classes whose instances are not rc-managed */
    private array $notRc = [];

    /** @var array<string, array<int, bool>> fn name → per-param by-ref mask */
    private array $refMasks = [];

    /** @var array<string, bool> fn name → its LAST param is a by-ref variadic */
    private array $refVariadic = [];

    /** @var array<string, string> class → parent */
    private array $parents = [];

    /** @var array<string, int> closure fn name → capture count (its call args start after them) */
    private array $closureCaptures = [];

    /** @var array<int, bool> spl_object_id of every node on a write / reference chain */
    private array $pinned = [];

    /** @var array<int, StoreLocal> read spills made in the statement being visited */
    private array $pending = [];

    /** @var array<int, StoreLocal> hoisted bases of the statement being visited */
    private array $hoisted = [];

    /** @var array<string, bool> hoisted names a reference keeps alive past the statement */
    private array $keepAlive = [];

    public function run(Module $module): Module
    {
        $this->notOwned = ['__mir_fiber_current' => true];
        $this->refMasks = [];
        $this->refVariadic = [];
        foreach ($module->functions as $fn) {
            if ($fn->ffiSymbol !== null || $fn->returnsByRef) { $this->notOwned[$fn->name] = true; }
            $mask = [];
            $lastVariadicRef = false;
            foreach ($fn->params as $p) {
                $mask[] = $p->byRef;
                $lastVariadicRef = $p->variadic && $p->byRef;
            }
            $this->refMasks[$fn->name] = $mask;
            $this->refVariadic[$fn->name] = $lastVariadicRef;
        }
        $this->notRc = ['Ffi\\Ptr' => true, 'Closure' => true];
        $this->parents = [];
        foreach ($module->classes as $name => $cd) {
            if ($cd->isStruct) { $this->notRc[$name] = true; }
            $this->parents[$name] = $cd->parent;
        }
        foreach ($module->enums as $name => $unused) { $this->notRc[$name] = true; }
        foreach ($module->typeDefs as $name => $unused) { $this->notRc[$name] = true; }
        $this->closureCaptures = $module->closureCaptures;
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $this->pinned = [];
            $this->pinWrites($fn->body, $fn->returnsByRef);
            $this->pending = [];
            $this->hoisted = [];
            $fn->body->stmts = $this->stmtList($fn->body->stmts);
        }
        $this->pinned = [];
        return $module;
    }

    // ── Write / reference chains ────────────────────────────────────────────

    private function pinWrites(Node $n, bool $refReturn): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_ELEMENT) {
            $this->pinChain($this->asStoreElement($n)->array);
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            $this->pinChain($this->asStoreProperty($n)->object);
        } elseif ($k === Node::KIND_STORE_DYN_PROP) {
            $this->pinChain($this->asStoreDynProp($n)->object);
        } elseif ($k === Node::KIND_UNSET) {
            foreach ($this->asUnset($n)->targets as $t) { $this->pinChain($t); }
        } elseif ($k === Node::KIND_REF_ADDR) {
            $this->pinChain($this->asRefAddr($n)->lvalue);
        } elseif ($k === Node::KIND_REF_CELL) {
            $this->pinChain($this->asRefCell($n)->refSource);
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            if ($fe->byRef) { $this->pinChain($fe->array); }
        } elseif ($k === Node::KIND_RETURN) {
            $rv = $this->asReturn($n)->value;
            if ($refReturn && $rv !== null) { $this->pinChain($rv); }
        } else {
            $args = $this->callArgs($n);
            foreach ($this->refArgIndexes($n) as $i) { $this->pinChain($args[$i]); }
        }
        foreach (Walk::children($n) as $c) { $this->pinWrites($c, $refReturn); }
    }

    /** @return Node[] the argument list of a call-shaped node, else [] */
    private function callArgs(Node $n): array
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL) { return $this->asCall($n)->args; }
        if ($k === Node::KIND_STATIC_CALL) { return $this->asStaticCall($n)->args; }
        if ($k === Node::KIND_NEW_OBJ) { return $this->asNewObj($n)->args; }
        if ($k === Node::KIND_METHOD_CALL) { return $this->asMethodCall($n)->args; }
        if ($k === Node::KIND_INVOKE) { return $this->asInvoke($n)->args; }
        return [];
    }

    /** @param Node[] $args */
    private function withArgs(Node $n, array $args): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL) { $this->asCall($n)->args = $args; }
        elseif ($k === Node::KIND_STATIC_CALL) { $this->asStaticCall($n)->args = $args; }
        elseif ($k === Node::KIND_NEW_OBJ) { $this->asNewObj($n)->args = $args; }
        elseif ($k === Node::KIND_METHOD_CALL) { $this->asMethodCall($n)->args = $args; }
        elseif ($k === Node::KIND_INVOKE) { $this->asInvoke($n)->args = $args; }
    }

    /**
     * The argument positions a call takes by reference. A callee with no mask
     * — a method on an erased receiver, an erased closure — answers every
     * argument that could be one of php's by-ref arrays (an array, a cell, an
     * erased value): the cost of a false pin is a leak, of a miss a double free.
     *
     * @return int[]
     */
    private function refArgIndexes(Node $n): array
    {
        $k = $n->kind;
        $fn = '';
        $offset = 0;
        $args = [];
        $builtin = false;
        if ($k === Node::KIND_CALL) {
            $c = $this->asCall($n);
            $fn = \ltrim($c->function, '\\');
            $args = $c->args;
            $builtin = true;
        } elseif ($k === Node::KIND_STATIC_CALL) {
            $sc = $this->asStaticCall($n);
            $fn = $this->resolveMethod($sc->class, $sc->method);
            $args = $sc->args;
        } elseif ($k === Node::KIND_NEW_OBJ) {
            $no = $this->asNewObj($n);
            $fn = $this->resolveMethod($no->class, '__construct');
            $args = $no->args;
            $offset = 1;
        } elseif ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->asMethodCall($n);
            $recv = $mc->object->type->class ?? '';
            $fn = $recv === '' ? '' : $this->resolveMethod($recv, $mc->method);
            $args = $mc->args;
            $offset = 1;
        } elseif ($k === Node::KIND_INVOKE) {
            $iv = $this->asInvoke($n);
            $fn = $iv->callee->type->class ?? '';
            $args = $iv->args;
            $offset = $this->closureCaptures[$fn] ?? 0;
        } else {
            return [];
        }
        $out = [];
        if (!isset($this->refMasks[$fn])) {
            if ($builtin) {
                // A codegen builtin: only the write-back ones take a reference.
                if ($this->writesBackFirstArg($fn) && \count($args) > 0) { $out[] = 0; }
                return $out;
            }
            $i = 0;
            foreach ($args as $a) {
                $ak = $a->type->kind;
                if ($ak === Type::KIND_ARRAY || $ak === Type::KIND_CELL || $ak === Type::KIND_UNKNOWN) {
                    $out[] = $i;
                }
                $i = $i + 1;
            }
            return $out;
        }
        $mask = $this->refMasks[$fn];
        $cnt = \count($mask);
        $variadicRef = $this->refVariadic[$fn] ?? false;
        $i = 0;
        foreach ($args as $a) {
            $p = $i + $offset;
            $byRef = $p < $cnt ? $mask[$p] : false;
            if (!$byRef && $variadicRef && $p >= $cnt - 1) { $byRef = true; }
            if ($byRef) { $out[] = $i; }
            $i = $i + 1;
        }
        return $out;
    }

    /**
     * The codegen builtins that write their FIRST argument back
     * ({@see EmitLlvmBuiltins::biArrayCursor}, biArrayPop, biArrayShift,
     * biArrayUnshift). Every other by-ref callee is a module function with a
     * by-ref mask.
     */
    private function writesBackFirstArg(string $fn): bool
    {
        return $fn === 'current' || $fn === 'pos' || $fn === 'key' || $fn === 'next'
            || $fn === 'prev' || $fn === 'reset' || $fn === 'end' || $fn === 'array_pop'
            || $fn === 'array_shift' || $fn === 'array_unshift';
    }

    private function pinChain(Node $n): void
    {
        $cur = $n;
        while (true) {
            $this->pinned[\spl_object_id($cur)] = true;
            $k = $cur->kind;
            if ($k === Node::KIND_PROPERTY_ACCESS) {
                $cur = $this->asPropertyAccess($cur)->object;
            } elseif ($k === Node::KIND_ARRAY_ACCESS) {
                $cur = $this->asArrayAccess($cur)->array;
            } elseif ($k === Node::KIND_DYN_PROP) {
                $cur = $this->asDynProp($cur)->object;
            } else {
                return;
            }
        }
    }

    private function resolveMethod(string $class, string $method): string
    {
        $cur = $class;
        $guard = 0;
        while ($cur !== '' && $guard < 64) {
            $cand = $cur . '__' . $method;
            if (isset($this->refMasks[$cand])) { return $cand; }
            $cur = $this->parents[$cur] ?? '';
            $guard = $guard + 1;
        }
        return '';
    }

    // ── Hoisting the fresh base of an unconditional write chain ─────────────

    /**
     * Walk the part of a statement that runs unconditionally, hoisting the
     * fresh base of every write / reference chain rooted there. A conditional
     * (ternary, `??`, match) and a nested statement block are not entered.
     */
    private function hoistIn(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_TERNARY || $k === Node::KIND_NULLCOALESCE || $k === Node::KIND_MATCH
            || $k === Node::KIND_CLOSURE || $k === Node::KIND_BLOCK
            || $k === Node::KIND_IF || $k === Node::KIND_WHILE || $k === Node::KIND_DOWHILE
            || $k === Node::KIND_FOR || $k === Node::KIND_SWITCH || $k === Node::KIND_TRY_CATCH) {
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            if ($fe->byRef) { $fe->array = $this->hoistBottom($fe->array, false); }
            return;
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            $se->array = $this->hoistBottom($se->array, false);
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            $sp = $this->asStoreProperty($n);
            $sp->object = $this->hoistBottom($sp->object, false);
        } elseif ($k === Node::KIND_STORE_DYN_PROP) {
            $sd = $this->asStoreDynProp($n);
            $sd->object = $this->hoistBottom($sd->object, false);
        } elseif ($k === Node::KIND_UNSET) {
            $u = $this->asUnset($n);
            $ts = [];
            foreach ($u->targets as $t) { $ts[] = $this->hoistBottom($t, false); }
            $u->targets = $ts;
        } elseif ($k === Node::KIND_REF_ADDR) {
            $ra = $this->asRefAddr($n);
            $ra->lvalue = $this->hoistBottom($ra->lvalue, true);
        } else {
            $idx = $this->refArgIndexes($n);
            if (\count($idx) > 0) {
                $args = $this->callArgs($n);
                foreach ($idx as $i) { $args[$i] = $this->hoistBottom($args[$i], false); }
                $this->withArgs($n, $args);
            }
        }
        foreach (Walk::children($n) as $c) { $this->hoistIn($c); }
    }

    /** Replace the fresh base at the bottom of an access chain by a hoisted local. */
    private function hoistBottom(Node $n, bool $keep): Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->asPropertyAccess($n);
            $pa->object = $this->hoistBottom($pa->object, $keep);
            return $n;
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $aa = $this->asArrayAccess($n);
            $aa->array = $this->hoistBottom($aa->array, $keep);
            return $n;
        }
        if ($k === Node::KIND_DYN_PROP) {
            $dp = $this->asDynProp($n);
            $dp->object = $this->hoistBottom($dp->object, $keep);
            return $n;
        }
        // Not a CELL: a write into an array property through a mixed-typed
        // LOCAL is itself miscompiled (a class drop handed a NaN-boxed word —
        // `$o = mo(); $o->arr[] = 1;` SIGSEGVs with no temporary involved), so
        // such a chain keeps evaluating its base in place, as before.
        if ($n->type->kind === Type::KIND_CELL || !$this->isFreshContainer($n, 2)) { return $n; }
        $name = $this->nextName();
        $this->hoisted[] = new StoreLocal($name, $n, $n->type);
        if ($keep) { $this->keepAlive[$name] = true; }
        return new LoadLocal($name, $n->type);
    }

    // ── Rewrite, statement by statement ─────────────────────────────────────

    /**
     * @param Node[] $stmts
     * @return Node[]
     */
    private function stmtList(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $s) {
            if ($s->kind === Node::KIND_BLOCK) {
                // A block IN a statement list is a statement group (a lowered
                // `[$a->x, $b->y] = …`), never a conditional's arm.
                $blk = $this->asBlock($s);
                $blk->stmts = $this->stmtList($blk->stmts);
                $out[] = $s;
                continue;
            }
            $savedPending = $this->pending;
            $savedHoisted = $this->hoisted;
            $this->pending = [];
            $this->hoisted = [];
            $this->hoistIn($s);
            $pre = $this->hoisted;
            $this->hoisted = $savedHoisted;
            $this->visit($s);
            $mine = $this->pending;
            $this->pending = $savedPending;
            foreach ($pre as $h) { $out[] = $h; }
            $out[] = $s;
            if ($this->leavesBlock($s)) { continue; }
            $targets = [];
            foreach ($pre as $h) {
                if (isset($this->keepAlive[$h->name])) { continue; }
                $targets[] = new LoadLocal($h->name, $h->type);
            }
            foreach ($mine as $sl) { $targets[] = new LoadLocal($sl->name, $sl->type); }
            if (\count($targets) > 0) { $out[] = new Unset_($targets, Type::void()); }
        }
        return $out;
    }

    private function leavesBlock(Node $s): bool
    {
        $k = $s->kind;
        return $k === Node::KIND_RETURN || $k === Node::KIND_THROW || $k === Node::KIND_BREAK
            || $k === Node::KIND_CONTINUE || $k === Node::KIND_GOTO;
    }

    /** Statement blocks get their own statement-end releases; everything else is an expression. */
    private function visit(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_IF) {
            $i = $this->asIf($n);
            $this->visit($i->cond);
            $i->then->stmts = $this->stmtList($i->then->stmts);
            $else = $i->else;
            if ($else !== null) { $else->stmts = $this->stmtList($else->stmts); }
            return;
        }
        if ($k === Node::KIND_WHILE) {
            $w = $this->asWhile($n);
            $this->visit($w->cond);
            $w->body->stmts = $this->stmtList($w->body->stmts);
            return;
        }
        if ($k === Node::KIND_DOWHILE) {
            $d = $this->asDoWhile($n);
            $d->body->stmts = $this->stmtList($d->body->stmts);
            $this->visit($d->cond);
            return;
        }
        if ($k === Node::KIND_FOR) {
            $fo = $this->asFor($n);
            if ($fo->init !== null) { $this->visit($fo->init); }
            if ($fo->cond !== null) { $this->visit($fo->cond); }
            if ($fo->step !== null) { $this->visit($fo->step); }
            $fo->body->stmts = $this->stmtList($fo->body->stmts);
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            $this->visit($fe->array);
            $fe->body->stmts = $this->stmtList($fe->body->stmts);
            return;
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $this->asTryCatch($n);
            $tc->tryBody = $this->stmtList($tc->tryBody);
            foreach ($tc->catches as $c) {
                $mc = $this->asCatch($c);
                $mc->body = $this->stmtList($mc->body);
            }
            $tc->finallyBody = $this->stmtList($tc->finallyBody);
            return;
        }
        if ($k === Node::KIND_SWITCH) {
            $sw = $this->asSwitch($n);
            $this->visit($sw->subject);
            foreach ($sw->arms as $a) {
                $arm = $this->asSwitchArm($a);
                if ($arm->value !== null) { $this->visit($arm->value); }
                $arm->body = $this->stmtList($arm->body);
            }
            return;
        }
        // Anything else — including a Block in EXPRESSION position (a
        // conditional's arm), whose spills belong to the enclosing statement.
        foreach (Walk::children($n) as $c) { $this->visit($c); }
        $this->rewriteRead($n);
    }

    private function rewriteRead(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            if (isset($this->pinned[\spl_object_id($n)])) { return; }
            $pa = $this->asPropertyAccess($n);
            if ($this->scalarResult($n) || !$this->isFreshContainer($pa->object, 0)) { return; }
            $pa->object = $this->spill($pa->object);
        } elseif ($k === Node::KIND_ARRAY_ACCESS) {
            if (isset($this->pinned[\spl_object_id($n)])) { return; }
            $aa = $this->asArrayAccess($n);
            if ($this->scalarResult($n) || !$this->isFreshContainer($aa->array, 1)) { return; }
            $aa->array = $this->spill($aa->array);
        }
    }

    /** The emitter already drops the base of a scalar read ({@see EmitLlvm::baseTempRelease}). */
    private function scalarResult(Node $n): bool
    {
        $rk = $n->type->kind;
        return $rk === Type::KIND_INT || $rk === Type::KIND_FLOAT || $rk === Type::KIND_BOOL
            || $rk === Type::KIND_NULL;
    }

    private function spill(Node $base): StoreLocal
    {
        $sl = new StoreLocal($this->nextName(), $base, $base->type);
        $this->pending[] = $sl;
        return $sl;
    }

    private function nextName(): string
    {
        $name = '__fb_' . (string)$this->counter;
        $this->counter = $this->counter + 1;
        return $name;
    }

    /**
     * A +1 container nobody else holds: a call's result (an object, an array,
     * or a cell carrying one), `new`, `clone`. `$want`: 0 an object (a
     * property read's base), 1 an array (an element read's), 2 either.
     */
    private function isFreshContainer(Node $b, int $want): bool
    {
        $t = $b->type;
        if ($t->kind === Type::KIND_OBJ && $want !== 1) {
            $cls = $t->class ?? '';
            if ($cls === '' || isset($this->notRc[$cls]) || \str_starts_with($cls, '__closure_')) { return false; }
        } elseif ($t->kind === Type::KIND_ARRAY && $want !== 0) {
            if (!$t->isVec() && !$t->isAssoc()) { return false; }
        } elseif ($t->kind !== Type::KIND_CELL) {
            return false;
        }
        $k = $b->kind;
        if ($k === Node::KIND_CALL) { return !isset($this->notOwned[\ltrim($this->asCall($b)->function, '\\')]); }
        if ($k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL) { return true; }
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        return $k === Node::KIND_INVOKE || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE;
    }

    // Typed reads — a base-typed `$n` resolves fields by OFFSET under
    // self-host and would pick the wrong slot ({@see VivifyRefArgs}).
    private function asStoreElement(Node $n): \Compile\Mir\StoreElement { return $n; }
    private function asStoreProperty(Node $n): \Compile\Mir\StoreProperty { return $n; }
    private function asStoreDynProp(Node $n): \Compile\Mir\StoreDynProp_ { return $n; }
    private function asUnset(Node $n): \Compile\Mir\Unset_ { return $n; }
    private function asRefAddr(Node $n): \Compile\Mir\RefAddr_ { return $n; }
    private function asRefCell(Node $n): \Compile\Mir\RefCell_ { return $n; }
    private function asForeach(Node $n): \Compile\Mir\Foreach_ { return $n; }
    private function asReturn(Node $n): \Compile\Mir\Return_ { return $n; }
    private function asCall(Node $n): \Compile\Mir\Call { return $n; }
    private function asStaticCall(Node $n): \Compile\Mir\StaticCall_ { return $n; }
    private function asNewObj(Node $n): \Compile\Mir\NewObj { return $n; }
    private function asMethodCall(Node $n): \Compile\Mir\MethodCall_ { return $n; }
    private function asInvoke(Node $n): \Compile\Mir\Invoke_ { return $n; }
    private function asPropertyAccess(Node $n): \Compile\Mir\PropertyAccess_ { return $n; }
    private function asArrayAccess(Node $n): \Compile\Mir\ArrayAccess_ { return $n; }
    private function asDynProp(Node $n): \Compile\Mir\DynProp_ { return $n; }
    private function asIf(Node $n): \Compile\Mir\If_ { return $n; }
    private function asWhile(Node $n): \Compile\Mir\While_ { return $n; }
    private function asDoWhile(Node $n): \Compile\Mir\DoWhile_ { return $n; }
    private function asFor(Node $n): \Compile\Mir\For_ { return $n; }
    private function asTryCatch(Node $n): \Compile\Mir\TryCatch_ { return $n; }
    private function asSwitch(Node $n): \Compile\Mir\Switch_ { return $n; }
    private function asCatch(mixed $n): \Compile\Mir\MirCatch { return $n; }
    private function asSwitchArm(mixed $n): \Compile\Mir\SwitchArm_ { return $n; }
    private function asBlock(Node $n): \Compile\Mir\Block { return $n; }
}
