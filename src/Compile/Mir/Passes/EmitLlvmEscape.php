<?php

namespace Compile\Mir\Passes;

use Compile\Mir\FunctionDef;
use Compile\Mir\Node;
use Compile\Mir\Type;

/**
 * May a call hold a borrowed PROPERTY operand without the slot's release
 * pulling it out from under the callee?
 *
 * A property read hands the callee the slot's own word, uncounted. That is
 * safe exactly when nothing can release the slot's value before the callee is
 * done with it, and three things can:
 *
 *   KEEPS   the callee stores or returns the argument, so it outlives the call;
 *   WRITES  the callee (or an argument evaluated next to it) overwrites,
 *           mutates or unsets that property slot ({@see escKeysMeet}) — the
 *           release of the old value frees what it was handed
 *           (`$this->f($this->lt)` where `f` assigns `$this->lt`);
 *   PARKS   the callee may suspend, and another task's store to the slot runs
 *           while it waits.
 *
 * So every function of the module gets a summary of all three, per PARAMETER
 * for the first: `mergeLocals($a, $b)` calling `unionTypes($x, $y)`, which
 * returns its argument, keeps a TYPE, never `$b` — the one-verdict-per-function
 * summary this replaces called that "keeps", and vetoed the release of
 * `InferTypes::localTypes` for the whole compiler: ~1M dead maps, 1.9 GB.
 *
 * The summaries are a monotone fixpoint, started optimistic and solved by a
 * worklist over the callers that READ a summary: park-free → parks, a kept
 * parameter or a written name only ever added. A body not in this module, an
 * FFI binding and a generator are never optimistic; neither is anything the
 * walk cannot see into (a closure call, a dynamic `new`, a hook, `__get`).
 *
 * Not modelled, as before: a `__destruct` or `__toString` a release or a
 * conversion runs inside the callee.
 */
trait EmitLlvmEscape
{
    /** @var array<string, bool> fn → judged (its summary below is meaningful) */
    private array $escJudged = [];
    /** @var array<string, bool> fn → cannot suspend */
    private array $escParkFree = [];
    /** @var array<string, int> fn → bitmask of PARAMETER indices that escape */
    private array $escKeepMask = [];
    /** @var array<string, array<string, bool>> fn → the `prop|Class` slots it may write ({@see escPropKey}) */
    private array $escWrites = [];
    /** @var array<string, bool> fn → may write a property it cannot name */
    private array $escWritesAny = [];

    // Walk scratch — one function (or one call site) at a time.
    /** @var array<string, int> local → the parameter bits it may alias */
    private array $ewTaint = [];
    private int $ewKeep = 0;
    /** @var array<string, bool> */
    private array $ewWrites = [];
    private bool $ewAny = false;
    private bool $ewPark = true;
    private bool $ewTaintGrew = false;
    private bool $ewRecording = false;
    private int $ewPasses = 0;
    /** The first reason the walk lost park-freedom or a nameable write set —
     *  for MANTICORE_KEEPS_TRACE, which is how a summary is debugged. */
    private string $ewWhy = '';
    /** @var array<string, string> fn → why it may park / write anything */
    private array $escWhy = [];
    /** @var array<string, bool> summaries the walk read — the worklist's edges */
    private array $ewReads = [];

    private function computeKeepsNoArg(\Compile\Mir\Module $module): void
    {
        $this->escJudged = [];
        $this->escParkFree = [];
        $this->escKeepMask = [];
        $this->escWrites = [];
        $this->escWritesAny = [];
        $this->escThisFirst = [];
        $this->escWhy = [];
        $this->escImplMemo = [];
        $this->escByMethodMemo = [];
        $this->escTargetMemo = [];
        if (!\Compile\Debug::$propBorrowEscape) { return; }
        /** @var array<string, FunctionDef> $byName */
        $byName = [];
        /** @var string[] $names */
        $names = [];
        foreach ($module->functions as $fn) {
            // A body that is not HERE cannot be judged: a signature-only stdlib
            // import (`fwrite` — whose real body parks on back-pressure) and an
            // FFI binding both carry an empty block. A GENERATOR parks by
            // construction: every `yield` hands control to a caller that may
            // overwrite the slot the argument came from.
            if ($fn->isExtern || $fn->ffiSymbol !== null || $fn->isGenerator) { continue; }
            $byName[$fn->name] = $fn;
            $names[] = $fn->name;
            $this->escJudged[$fn->name] = true;
            if ($fn->params !== [] && $fn->params[0]->name === 'this') { $this->escThisFirst[$fn->name] = true; }
            $this->escParkFree[$fn->name] = true;
            $this->escKeepMask[$fn->name] = 0;
            $this->escWrites[$fn->name] = [];
            $this->escWritesAny[$fn->name] = false;
        }
        // 1. LOCAL effects and the call edges, every body once. With every
        //    summary still empty a judged callee contributes nothing, so what
        //    the walk collects is the body's own: its unjudged calls, its
        //    stores, its foreach subjects.
        /** @var array<string, string[]> $callees */
        $callees = [];
        /** @var array<string, bool> $localPark */
        $localPark = [];
        /** @var array<string, bool> $localAny */
        $localAny = [];
        /** @var array<string, array<string, bool>> $localWrites */
        $localWrites = [];
        /** @var array<string, string> $localWhy */
        $localWhy = [];
        $walks = 0;
        foreach ($names as $name) {
            $this->ewReads = [];
            $this->ewRecording = true;
            $this->escWalkFunction($byName[$name]);
            $this->ewRecording = false;
            $walks = $walks + $this->ewPasses;
            $callees[$name] = \array_keys($this->ewReads);
            $localPark[$name] = $this->ewPark;
            $localAny[$name] = $this->ewAny;
            $localWrites[$name] = $this->ewWrites;
            $localWhy[$name] = $this->ewWhy;
        }
        // 2. Strongly connected components, callees first (Tarjan, iterative:
        //    the call graph is thousands deep and native frames are not free).
        $sccs = $this->escSccs($names, $callees);
        // 3. Every member of a component reaches every other, so park, `any`
        //    and the written slots are ONE answer for the component: its
        //    members' own effects and everything its outside callees (already
        //    final) do. Only the per-parameter keep masks need a fixpoint, and
        //    only inside the component.
        $evals = 0;
        foreach ($sccs as $scc) {
            /** @var array<string, bool> $inScc */
            $inScc = [];
            foreach ($scc as $m) { $inScc[$m] = true; }
            $park = true;
            $any = false;
            /** @var array<string, bool> $writes */
            $writes = [];
            $why = '';
            foreach ($scc as $m) {
                if (!$localPark[$m] || $localAny[$m]) {
                    if ($why === '') { $why = $localWhy[$m]; }
                }
                if (!$localPark[$m]) { $park = false; }
                if ($localAny[$m]) { $any = true; }
                foreach ($localWrites[$m] as $wk => $unused) { $writes[$wk] = true; }
                foreach ($callees[$m] as $c) {
                    if (isset($inScc[$c])) { continue; }
                    if ((!$this->escParkFree[$c] || $this->escWritesAny[$c]) && $why === '') { $why = 'via ' . $c; }
                    if (!$this->escParkFree[$c]) { $park = false; }
                    if ($this->escWritesAny[$c]) { $any = true; }
                    foreach ($this->escWrites[$c] as $wk => $unused) { $writes[$wk] = true; }
                }
            }
            foreach ($scc as $m) {
                $this->escParkFree[$m] = $park;
                $this->escWritesAny[$m] = $any;
                $this->escWrites[$m] = $writes;
                if (!$park || $any) { $this->escWhy[$m] = $why; }
            }
            $changed = true;
            while ($changed) {
                $changed = false;
                foreach ($scc as $m) {
                    $this->escWalkFunction($byName[$m]);
                    $evals = $evals + 1;
                    $walks = $walks + $this->ewPasses;
                    $keep = $this->escKeepMask[$m] | $this->ewKeep;
                    if ($keep !== $this->escKeepMask[$m]) {
                        $this->escKeepMask[$m] = $keep;
                        // A singleton that does not call itself reads no
                        // keep mask of its own component: one pass is final.
                        if (\count($scc) > 1 || \in_array($m, $callees[$m], true)) { $changed = true; }
                    }
                }
            }
        }
        \Compile\Stats::line('escape: evals=' . (string)$evals . ' walks=' . (string)$walks
            . ' sccs=' . (string)\count($sccs));
        $this->ewReads = [];
        $this->ewTaint = [];
        $this->ewWrites = [];
        $want = \getenv('MANTICORE_KEEPS_TRACE');
        if ($want !== false && $want !== '') {
            foreach ($this->escJudged as $kn => $unused) {
                if (!\str_contains($kn, $want)) { continue; }
                \error_log('KEEPS ' . $kn . ' park=' . ($this->escParkFree[$kn] ? 'free' : 'MAY')
                    . ' keep=' . \decbin($this->escKeepMask[$kn])
                    . ' writes=' . ($this->escWritesAny[$kn] ? '*' : \implode(',', \array_keys($this->escWrites[$kn])))
                    . ' why=' . ($this->escWhy[$kn] ?? ''));
            }
        }
    }

    /**
     * Tarjan's strongly connected components of the call graph, in the order
     * they complete — every component after all the components it calls.
     * Iterative, with the DFS stack as two parallel lists.
     *
     * @param string[] $names
     * @param array<string, string[]> $callees
     * @return string[][]
     */
    private function escSccs(array $names, array $callees): array
    {
        /** @var array<string, int> $index */
        $index = [];
        /** @var array<string, int> $low */
        $low = [];
        /** @var array<string, bool> $onStack */
        $onStack = [];
        /** @var string[] $stack */
        $stack = [];
        /** @var string[][] $out */
        $out = [];
        $next = 0;
        foreach ($names as $root) {
            if (isset($index[$root])) { continue; }
            /** @var string[] $workNode */
            $workNode = [$root];
            /** @var int[] $workPos */
            $workPos = [0];
            $index[$root] = $next;
            $low[$root] = $next;
            $next = $next + 1;
            $stack[] = $root;
            $onStack[$root] = true;
            while ($workNode !== []) {
                $top = \count($workNode) - 1;
                $u = $workNode[$top];
                $i = $workPos[$top];
                $succ = $callees[$u];
                if ($i < \count($succ)) {
                    $workPos[$top] = $i + 1;
                    $w = $succ[$i];
                    if (!isset($index[$w])) {
                        $index[$w] = $next;
                        $low[$w] = $next;
                        $next = $next + 1;
                        $stack[] = $w;
                        $onStack[$w] = true;
                        $workNode[] = $w;
                        $workPos[] = 0;
                    } elseif (isset($onStack[$w]) && $index[$w] < $low[$u]) {
                        $low[$u] = $index[$w];
                    }
                    continue;
                }
                \array_pop($workNode);
                \array_pop($workPos);
                if ($workNode !== []) {
                    $p = $workNode[\count($workNode) - 1];
                    if ($low[$u] < $low[$p]) { $low[$p] = $low[$u]; }
                }
                if ($low[$u] === $index[$u]) {
                    /** @var string[] $scc */
                    $scc = [];
                    while (true) {
                        $w = \array_pop($stack);
                        unset($onStack[$w]);
                        $scc[] = $w;
                        if ($w === $u) { break; }
                    }
                    $out[] = $scc;
                }
            }
        }
        return $out;
    }

    /** One body against the current summaries; the result lands in the `ew*` scratch. */
    private function escWalkFunction(FunctionDef $fn): void
    {
        $this->ewTaint = [];
        $this->ewKeep = 0;
        $this->ewWrites = [];
        $this->ewAny = false;
        $this->ewPark = true;
        $this->ewWhy = '';
        $isClosure = isset($this->closureCaptures[$fn->name]);
        $i = 0;
        foreach ($fn->params as $p) {
            $bit = $this->escBit($i);
            $i = $i + 1;
            // A by-ref param holds the caller's ADDRESS: whatever the body does
            // with it happens to the caller's slot. Treated as kept.
            if ($p->byRef) { $this->ewKeep = $this->ewKeep | $bit; continue; }
            // The receiver is not an argument, and a scalar slot never holds a
            // pointer to keep. A param the prologue COPIES holds the frame's
            // private buffer — returning or storing it hands over the copy.
            if ($p->name === 'this' || (!$p->variadic && $this->paramNeverPointer($p->type))) { continue; }
            if (\Compile\Mir\VecCopyOnAssign::paramCopiedOnEntry($fn, $p, $isClosure)) { continue; }
            $this->ewTaint[$p->name] = ($this->ewTaint[$p->name] ?? 0) | $bit;
        }
        // Flow-insensitive: `$y = $p` late in a loop taints a use earlier in
        // it, so walk until the aliases stop growing.
        $this->ewPasses = 0;
        do {
            $this->ewTaintGrew = false;
            $this->escWalk($fn->body);
            $this->ewPasses = $this->ewPasses + 1;
        } while ($this->ewTaintGrew);
    }

    /** The mask bit of parameter `$i`; the tail past 61 shares the last bit. */
    private function escBit(int $i): int
    {
        return 1 << ($i < 62 ? $i : 62);
    }

    private function escWalk(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) {
            $m = $this->escAlias($n->value);
            if ($m !== 0) {
                $old = $this->ewTaint[$n->name] ?? 0;
                if (($old | $m) !== $old) {
                    $this->ewTaint[$n->name] = $old | $m;
                    $this->ewTaintGrew = true;
                }
            }
        } elseif ($k === Node::KIND_RETURN) {
            if ($n->value !== null) { $this->ewKeep = $this->ewKeep | $this->escAlias($n->value); }
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            $this->escNoteStoreProperty($n);
            // A property store that RETAINS keeps a reference of its own, not
            // the caller's — the promoted `$this->parent = $parent` of every
            // constructor.
            if (!$this->propStoreCoOwns($n)) { $this->ewKeep = $this->ewKeep | $this->escAlias($n->value); }
        } elseif ($k === Node::KIND_STORE_ELEMENT) {
            $this->escNoteWriteTarget($n->array);
            foreach (\Compile\Mir\Walk::children($n) as $c) { $this->ewKeep = $this->ewKeep | $this->escAlias($c); }
        } elseif ($k === Node::KIND_STORE_STATIC_PROP || $k === Node::KIND_ARRAY_LIT
            || $k === Node::KIND_CLOSURE) {
            foreach (\Compile\Mir\Walk::children($n) as $c) { $this->ewKeep = $this->ewKeep | $this->escAlias($c); }
        } elseif ($k === Node::KIND_REF_ADDR) {
            // `$x = &$this->p` aliases the slot: every later write through `$x`
            // is a write of `p` this walk cannot see.
            $this->escNoteWriteTarget($n->lvalue);
            $this->ewKeep = $this->ewKeep | $this->escAlias($n->lvalue);
        } elseif ($k === Node::KIND_REF_CELL) {
            $this->escNoteWriteTarget($n->refSource);
            $this->ewKeep = $this->ewKeep | $this->escAlias($n->refSource);
        } elseif ($k === Node::KIND_UNSET) {
            foreach ($n->targets as $t) { $this->escNoteWriteTarget($t); }
        } elseif ($k === Node::KIND_STORE_DYN_PROP || $k === Node::KIND_DYN_PROP) {
            // A dynamic name may be any property, and a class without it runs
            // `__get` / `__set`.
            $this->ewAny = true;
            $this->ewPark = false;
        } elseif ($k === Node::KIND_PROPERTY_ACCESS) {
            if (!$this->escPlainPropRead($n)) { $this->ewPark = false; }
        } elseif ($k === Node::KIND_FOREACH) {
            // A subject that may be an OBJECT resumes user code — a Generator,
            // an Iterator — and that code can park.
            $bt = $n->array->type;
            if (!$this->escNeverObject($bt) && !$this->escArrayHintedSlot($n->array)) {
                if ($this->ewWhy === '') { $this->ewWhy = 'foreach over ' . $bt->toString(); }
                $this->ewPark = false;
            }
            if ($n->byRef) { $this->escNoteWriteTarget($n->array); }
        } elseif ($k === Node::KIND_CLONE || $k === Node::KIND_NEW_DYN_OBJ
            || $k === Node::KIND_YIELD || $k === Node::KIND_REF_BIND) {
            $this->ewPark = false;
            $this->ewAny = true;
            foreach (\Compile\Mir\Walk::children($n) as $c) { $this->ewKeep = $this->ewKeep | $this->escAlias($c); }
        } elseif ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_NEW_OBJ
            || $k === Node::KIND_INVOKE) {
            $this->escNoteCall($n);
        }
        if ((!$this->ewPark || $this->ewAny) && $this->ewWhy === '') { $this->ewWhy = 'node ' . $k; }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->escWalk($c); }
    }

    /** A call's callee, for the trace. */
    private function escCallName(Node $n): string
    {
        if ($n->kind === Node::KIND_CALL) { return $n->function; }
        if ($n->kind === Node::KIND_METHOD_CALL) { return ($n->object->type->class ?? '?') . '->' . $n->method; }
        if ($n->kind === Node::KIND_STATIC_CALL) { return $n->class . '::' . $n->method; }
        if ($n->kind === Node::KIND_NEW_OBJ) { return 'new ' . $n->class; }
        return (string)$n->kind;
    }

    /** A read of a property DECLARED `array` / `?array`: the slot may be a
     *  cell, but php's type check never lets an object into it. */
    private function escArrayHintedSlot(Node $e): bool
    {
        if ($e->kind === Node::KIND_PROPERTY_ACCESS) { return $this->escArrayHintedProp($e); }
        return false;
    }

    private function escArrayHintedProp(\Compile\Mir\PropertyAccess_ $pa): bool
    {
        if ($this->slotIsArrayHinted($pa->object, $pa->property, null)) { return true; }
        $cd = $this->slotHolder($pa->object, $pa->property);
        return $cd !== null && ($cd->propertyNeverObject[$pa->property] ?? false);
    }

    /** An array, a null, or a union / cell whose every atom is one of those. */
    private function escNeverObject(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_ARRAY || $k === Type::KIND_NULL) { return true; }
        if (($k === Type::KIND_UNION || $k === Type::KIND_CELL) && $t->atoms !== []) {
            foreach ($t->atoms as $at) {
                if (!$this->escNeverObject($at)) { return false; }
            }
            return true;
        }
        return false;
    }

    /** A declared, hook-free property of a known class: reading it runs no code. */
    private function escPlainPropRead(\Compile\Mir\PropertyAccess_ $pa): bool
    {
        $cls = $pa->object->type->class ?? '';
        if ($pa->object->type->kind !== Type::KIND_OBJ || $cls === '' || !isset($this->classes[$cls])) {
            // A receiver whose class is not pinned reads through the bag or a
            // union dispatch; neither runs user code unless the name is magic.
            return true;
        }
        $cd = $this->classes[$cls];
        if (($cd->propHooks[$pa->property]['get'] ?? '') !== '') { return false; }
        if ($cd->propertyOffset($pa->property) === -1 && !$this->subclassDeclares($cls, $pa->property)
            && $this->resolveMethodClass($cls, '__get') !== '') {
            return false;
        }
        return true;
    }

    private function escNoteStoreProperty(\Compile\Mir\StoreProperty $n): void
    {
        $this->ewWrites[$this->escPropKey($n->object, $n->property)] = true;
        $cls = $n->object->type->class ?? '';
        if ($n->object->type->kind !== Type::KIND_OBJ || $cls === '' || !isset($this->classes[$cls])) { return; }
        $cd = $this->classes[$cls];
        if (!$n->bypassHook && ($cd->propHooks[$n->property]['set'] ?? '') !== '') {
            $this->ewPark = false;
            $this->ewAny = true;
            return;
        }
        if ($cd->propertyOffset($n->property) === -1 && !$this->subclassDeclares($cls, $n->property)
            && $this->resolveMethodClass($cls, '__set') !== '') {
            $this->ewPark = false;
            $this->ewAny = true;
        }
    }

    /** Record every property NAME along an lvalue chain (`$this->a->b[$k]`)
     *  as written: the store writes the last, and may free what the others hold. */
    private function escNoteWriteTarget(Node $t): void
    {
        // Each field read under its own `kind` test: that is what narrows a
        // `Node` natively.
        if ($t->kind === Node::KIND_PROPERTY_ACCESS) {
            $this->ewWrites[$this->escPropKey($t->object, $t->property)] = true;
            $this->escNoteWriteTarget($t->object);
        } elseif ($t->kind === Node::KIND_ARRAY_ACCESS) {
            $this->escNoteWriteTarget($t->array);
        } elseif ($t->kind === Node::KIND_DYN_PROP) {
            $this->ewAny = true;
        }
    }

    /**
     * A call's argument list, read under each call kind's own test — the
     * field sits at a different offset in every one of them.
     * @return Node[]
     */
    private function escCallArgs(Node $n): array
    {
        if ($n->kind === Node::KIND_CALL) { return $n->args; }
        if ($n->kind === Node::KIND_METHOD_CALL) { return $n->args; }
        if ($n->kind === Node::KIND_STATIC_CALL) { return $n->args; }
        if ($n->kind === Node::KIND_NEW_OBJ) { return $n->args; }
        if ($n->kind === Node::KIND_INVOKE) { return $n->args; }
        return [];
    }

    /** The parameter bits `$n` hands over a REFERENCE to — a direct read, or a
     *  conditional the emitter normalizes over such reads. A concat, a cast and
     *  every builtin result are fresh buffers. */
    private function escAlias(Node $n): int
    {
        $k = $n->kind;
        if ($k === Node::KIND_LOAD_LOCAL) { return $this->ewTaint[$n->name] ?? 0; }
        $m = 0;
        if (\Compile\Mir\CondOwn::isConditional($n)) {
            foreach (\Compile\Mir\CondOwn::arms($n) as $arm) { $m = $m | $this->escAlias($arm); }
        }
        return $m;
    }

    /** Fold one call into the walk: its callee's summary, and which tainted
     *  arguments that callee keeps. */
    private function escNoteCall(Node $n): void
    {
        // A GLOBAL name on the list is the builtin even where the module
        // carries its same-named PHP body (the bootstrap pair: `emitBuiltin`
        // shadows `strpos`'s stdlib body); a namespaced one is the user's.
        if ($n->kind === Node::KIND_CALL
            && (!isset($this->escJudged[$n->function]) || !\str_contains($n->function, \chr(92)))
            && $this->escBuiltinEffectFree($n->function)) {
            // The by-reference mutators (`array_push`, `sort`, …) write the
            // slot their first argument names.
            if ($n->args !== [] && \Compile\Mir\VecCopyOnAssign::mutatesArg0($n->function)) {
                $this->escNoteWriteTarget($n->args[0]);
            }
            return;
        }
        if ($n->kind === Node::KIND_STATIC_CALL && $this->enumFromKeepsNoArg($n)) { return; }
        $args = $this->escCallArgs($n);
        $targets = $n->kind === Node::KIND_INVOKE ? [] : $this->escTargets($n);
        if ($n->kind === Node::KIND_METHOD_CALL) {
            // The callee's own judgement never taints `this`, so a tainted
            // receiver may be kept by it.
            $this->ewKeep = $this->ewKeep | $this->escAlias($n->object);
        }
        if ($targets === [] || $this->escHasSpread($args)) {
            if ($this->ewWhy === '') { $this->ewWhy = 'call ' . $this->escCallName($n); }
            $this->ewPark = false;
            $this->ewAny = true;
            foreach ($args as $a) { $this->ewKeep = $this->ewKeep | $this->escAlias($a); }
            return;
        }
        foreach ($targets as $t) {
            if ($this->ewRecording) { $this->ewReads[$t] = true; }
            if ((!$this->escParkFree[$t] || $this->escWritesAny[$t]) && $this->ewWhy === '') { $this->ewWhy = 'via ' . $t; }
            if (!$this->escParkFree[$t]) { $this->ewPark = false; }
            if ($this->escWritesAny[$t]) { $this->ewAny = true; }
            foreach ($this->escWrites[$t] as $prop => $unused) { $this->ewWrites[$prop] = true; }
            $off = $this->escParamOffset($t);
            $refs = $this->sigs->refParams[$t] ?? [];
            $keep = $this->escKeepMask[$t];
            $i = 0;
            foreach ($args as $a) {
                $pi = $i + $off;
                $i = $i + 1;
                $byRef = $refs[$pi] ?? false;
                if ($byRef) { $this->escNoteWriteTarget($a); }
                $m = $this->escAlias($a);
                if ($m === 0) { continue; }
                if ($byRef || ($keep & $this->escBit($pi)) !== 0) { $this->ewKeep = $this->ewKeep | $m; }
            }
        }
    }

    /** @param Node[] $args */
    private function escHasSpread(array $args): bool
    {
        foreach ($args as $a) {
            if ($a->kind === Node::KIND_SPREAD) { return true; }
        }
        return false;
    }

    /** 1 when the callee's first parameter is the receiver `this`. */
    private function escParamOffset(string $fn): int
    {
        return isset($this->escThisFirst[$fn]) ? 1 : 0;
    }

    /** @var array<string, bool> fn whose parameter 0 is `this` */
    private array $escThisFirst = [];

    /**
     * A builtin that cannot SUSPEND, runs no user code and writes no property
     * (bar its by-reference first argument, {@see VecCopyOnAssign::mutatesArg0})
     * — what a body may call and stay judgeable. An ALLOW list, because the
     * cost of a missing name is a leak and the cost of a wrong one is a free:
     * anything taking a callback, anything that runs user code through a magic
     * method (json_encode, serialize), and every stream / socket / sleep /
     * process call stays out. {@see callKeepsNoArg}'s names all qualify.
     */
    private function escBuiltinEffectFree(string $fn): bool
    {
        if ($this->callKeepsNoArg($fn)) { return true; }
        if ($this->escPureNames === []) {
            foreach ([
                'implode', 'join', 'chr', 'sprintf', 'vsprintf', 'getenv', 'str_replace',
                'str_ireplace', 'str_pad', 'str_split', 'substr_replace', 'strtr', 'ucwords',
                'wordwrap', 'addslashes', 'stripslashes', 'htmlspecialchars', 'strcmp',
                'strcasecmp', 'strncmp', 'strncasecmp', 'strnatcmp', 'strspn', 'strcspn',
                'strstr', 'stristr', 'strrchr', 'strpbrk', 'ctype_digit', 'ctype_alpha',
                'ctype_alnum', 'ctype_space', 'ctype_upper', 'ctype_lower', 'ctype_xdigit',
                'ctype_punct', 'bin2hex', 'hex2bin', 'base64_encode', 'base64_decode',
                'urlencode', 'rawurlencode', 'urldecode', 'rawurldecode', 'pack', 'unpack',
                'array_keys', 'array_values', 'array_merge', 'array_slice', 'array_flip',
                'array_reverse', 'array_unique', 'array_combine', 'array_fill',
                'array_fill_keys', 'array_pad', 'array_diff', 'array_diff_key',
                'array_intersect', 'array_intersect_key', 'array_column', 'array_sum',
                'array_product', 'array_count_values', 'array_is_list', 'array_search',
                'in_array', 'range', 'min', 'max', 'preg_match', 'preg_match_all',
                'preg_replace', 'preg_split', 'preg_quote', 'preg_last_error', 'dirname',
                'basename', 'pathinfo', 'microtime', 'hrtime', 'time', 'spl_object_id',
                'spl_object_hash', 'get_class', 'get_parent_class', 'get_debug_type',
                'gettype', 'method_exists', 'property_exists', 'class_exists',
                'function_exists', 'is_a', 'is_subclass_of', 'fmod', 'pow', 'log', 'exp',
                'is_nan', 'is_infinite', 'is_finite', 'mb_strtolower', 'mb_strtoupper',
                'mb_strpos', 'mb_str_split', 'mb_strwidth', 'ucfirst', 'error_log',
                'json_decode', 'array_key_exists', 'key', 'current', 'reset', 'end',
                'next', 'prev', 'manticore_raw_str_bytes', '__ryu_msp', '__mir_clock_ns', '__ugt',
            ] as $nm) {
                $this->escPureNames[$nm] = true;
            }
        }
        $p = \strrpos($fn, \chr(92));
        $bare = $p === false ? $fn : \substr($fn, $p + 1);
        return isset($this->escPureNames[$bare]);
    }

    /** @var array<string, bool> */
    private array $escPureNames = [];

    /** @var array<string, string[]> method name → every class resolving it, memoized per module */
    private array $escByMethodMemo = [];

    /**
     * Every concrete class a method NAME can dispatch to, or [] when that is
     * unknowable: a class with `__call`, or a name a Closure answers.
     * @return string[]
     */
    private function escClassesWithMethod(string $m): array
    {
        if (isset($this->escByMethodMemo[$m])) { return $this->escByMethodMemo[$m]; }
        $out = [];
        if ($m !== '__invoke' && $m !== 'call' && $m !== 'bind' && $m !== 'bindTo'
            && $m !== 'fromCallable') {
            foreach ($this->classes as $cd) {
                if ($this->resolveMethodClass($cd->name, '__call') !== '') { $out = []; break; }
                if ($this->resolveMethodClass($cd->name, $m) !== '') { $out[] = $cd->name; }
            }
        }
        $this->escByMethodMemo[$m] = $out;
        return $out;
    }

    /** @var array<string, string[]> interface → its implementing classes, memoized per module */
    private array $escImplMemo = [];

    /** @return string[] */
    private function escImplementers(string $iface): array
    {
        if (!isset($this->escImplMemo[$iface])) { $this->escImplMemo[$iface] = $this->interfaceImplementers($iface); }
        return $this->escImplMemo[$iface];
    }

    /**
     * Every judged body a call can reach, or [] when one of them is not
     * judged (a builtin, an import, `__call`, an interface receiver, a
     * late-static-bound target).
     * @return string[]
     */
    private function escTargets(Node $n): array
    {
        // Memoized per receiver class + method: a dispatch set walks every
        // descendant, and the fixpoint asks again on each re-evaluation.
        if ($n->kind === Node::KIND_METHOD_CALL) {
            $rt = $n->object->type;
            $mk = ($rt->kind === Type::KIND_OBJ ? ($rt->class ?? '') : '') . '->' . $n->method;
            if (!isset($this->escTargetMemo[$mk])) { $this->escTargetMemo[$mk] = $this->escTargetsOf($n); }
            return $this->escTargetMemo[$mk];
        }
        return $this->escTargetsOf($n);
    }

    /** @var array<string, string[]> `Class->method` → its dispatch targets */
    private array $escTargetMemo = [];

    /** @return string[] */
    private function escTargetsOf(Node $n): array
    {
        $out = [];
        if ($n->kind === Node::KIND_CALL) {
            $out[] = $n->function;
        } elseif ($n->kind === Node::KIND_NEW_OBJ) {
            if ($n->bare) { return []; }
            $cls = $n->class;
            if ($cls === '' || !isset($this->classes[$cls])) { return []; }
            $owner = $this->resolveMethodClass($cls, '__construct');
            if ($owner === '') { return []; }
            $out[] = $owner . '____construct';
        } elseif ($n->kind === Node::KIND_STATIC_CALL) {
            // The body {@see EmitLlvmObjects::emitStaticCall} calls: the class's
            // resolved method. Late static binding was settled at lowering
            // (`$staticClass` is not read past it), so `parent::__construct()`
            // lands on exactly this body.
            if (!isset($this->classes[$n->class])) { return []; }
            $owner = $this->resolveMethodClass($n->class, $n->method);
            if ($owner === '') { return []; }
            $out[] = $owner . '__' . $n->method;
        } elseif ($n->kind === Node::KIND_METHOD_CALL) {
            $t = $n->object->type;
            $cls = $t->class ?? '';
            if ($t->kind !== Type::KIND_OBJ || $cls === '') {
                // A receiver the types do not pin reaches any class that has
                // the method — unless some class answers every name (`__call`)
                // or it is a Closure's own method.
                $cands = $this->escClassesWithMethod($n->method);
            } elseif (isset($this->classes[$cls])) {
                $cands = $this->selfAndDescendants($cls);
            } elseif (isset($this->interfaceNames[$cls])) {
                $cands = $this->escImplementers($cls);
            } else {
                return [];
            }
            /** @var array<string, bool> $seen */
            $seen = [];
            foreach ($cands as $d) {
                // No object is ever exactly an abstract class, and its abstract
                // methods have no body to judge.
                if (!isset($this->classes[$d]) || $this->classes[$d]->isAbstract) { continue; }
                $owner = $this->resolveMethodClass($d, $n->method);
                if ($owner === '') { return []; }
                $name = $owner . '__' . $n->method;
                if (isset($seen[$name])) { continue; }
                $seen[$name] = true;
                $out[] = $name;
            }
        } else {
            return [];
        }
        foreach ($out as $name) {
            if (!isset($this->escJudged[$name])) { return []; }
        }
        return $out;
    }

    /**
     * May `$call` hold `$operand` — a property read, or an element read of
     * one, passed as an ARGUMENT — as the uncounted borrow it arrives as?
     * The receiver is never covered.
     */
    private function operandHeldSafely(Node $call, Node $operand): bool
    {
        if (!\Compile\Debug::$propBorrowEscape) { return false; }
        // A scalar word is a copy, not a borrow of anything.
        if ($this->paramNeverPointer($operand->type)) { return true; }
        if ($call->kind !== Node::KIND_CALL && $call->kind !== Node::KIND_METHOD_CALL
            && $call->kind !== Node::KIND_STATIC_CALL && $call->kind !== Node::KIND_NEW_OBJ) {
            return false;
        }
        $args = $this->escCallArgs($call);
        if ($this->escHasSpread($args)) { return false; }
        $idx = -1;
        $i = 0;
        foreach ($args as $a) {
            if ($a === $operand) { $idx = $i; break; }
            $i = $i + 1;
        }
        if ($idx < 0) { return false; }
        /** @var array<string, bool> $names */
        $names = [];
        if (!$this->escOperandNames($operand, $names)) { return false; }
        // What the OTHER arguments do runs while the operand's word is already
        // loaded: `f($this->lt, $this->reset())`.
        $this->ewTaint = [];
        $this->ewKeep = 0;
        $this->ewWrites = [];
        $this->ewAny = false;
        $this->ewPark = true;
        foreach ($args as $a) { $this->escWalk($a); }
        if (!$this->ewPark || $this->ewAny) { return false; }
        if ($this->escKeysMeet($names, $this->ewWrites)) { return false; }
        // A builtin that provably reads its argument and keeps nothing, and a
        // backed enum's `from` — php's contract, a NAME list.
        if ($call->kind === Node::KIND_CALL && $this->callKeepsNoArg($call->function)) { return true; }
        if ($call->kind === Node::KIND_STATIC_CALL && $this->enumFromKeepsNoArg($call)) { return true; }
        $targets = $this->escTargets($call);
        if ($targets === []) { return false; }
        foreach ($targets as $t) {
            if (!$this->escParkFree[$t] || $this->escWritesAny[$t]) { return false; }
            if ($this->escKeysMeet($names, $this->escWrites[$t])) { return false; }
            $pi = $idx + $this->escParamOffset($t);
            if (($this->escKeepMask[$t] & $this->escBit($pi)) !== 0) { return false; }
            if ($this->sigs->refParams[$t][$pi] ?? false) { return false; }
        }
        return true;
    }

    /**
     * May a by-value `foreach` walk its property SUBJECT as a borrow? Only
     * when its body can neither suspend nor write any slot the subject hangs
     * from — `foreach ($this->m as …) { $this->m = []; }` frees the buffer
     * under the walk.
     */
    private function foreachSubjectHeldSafely(\Compile\Mir\Foreach_ $fe): bool
    {
        if (!\Compile\Debug::$propBorrowEscape || $fe->byRef) { return false; }
        /** @var array<string, bool> $names */
        $names = [];
        if (!$this->escOperandNames($fe->array, $names)) { return false; }
        $this->ewTaint = [];
        $this->ewKeep = 0;
        $this->ewWrites = [];
        $this->ewAny = false;
        $this->ewPark = true;
        $this->escWalk($fe->body);
        if (!$this->ewPark || $this->ewAny) { return false; }
        return !$this->escKeysMeet($names, $this->ewWrites);
    }

    /**
     * The property names an operand's value hangs from — `$this->a->b[$k]` is
     * held by `b`, and by `a`, whose release frees the object holding `b`.
     * False when the chain goes through anything but locals, properties and
     * subscripts.
     * @param array<string, bool> $names
     */
    /**
     * `prop|Class` — a property slot as far as the receiver's static type pins
     * it; `prop|` when it does not.
     */
    private function escPropKey(Node $obj, string $prop): string
    {
        $t = $obj->type;
        return $prop . '|' . ($t->kind === Type::KIND_OBJ ? ($t->class ?? '') : '');
    }

    /**
     * Can any slot of `$held` be one of `$written`? Same property name, and
     * receivers that can be the same object: one class, one the other's
     * ancestor, or either unpinned — an interface, a trait, an unknown class.
     * A constructor filling `$this->method` of a fresh Request is not the
     * parser's `method` it was handed.
     * @param array<string, bool> $held
     * @param array<string, bool> $written
     */
    private function escKeysMeet(array $held, array $written): bool
    {
        foreach ($held as $hk => $unused) {
            $hp = \strpos($hk, '|');
            $hProp = \substr($hk, 0, $hp);
            $hCls = \substr($hk, $hp + 1);
            foreach ($written as $wk => $unused2) {
                $wp = \strpos($wk, '|');
                if (\substr($wk, 0, $wp) !== $hProp) { continue; }
                if ($this->escClassesMeet($hCls, \substr($wk, $wp + 1))) { return true; }
            }
        }
        return false;
    }

    private function escClassesMeet(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) { return true; }
        if (!isset($this->classes[$a]) || !isset($this->classes[$b])) { return true; }
        return $this->classIsA($a, $b) || $this->classIsA($b, $a);
    }

    private function escOperandNames(Node $t, array &$names): bool
    {
        if ($t->kind === Node::KIND_PROPERTY_ACCESS) {
            $names[$this->escPropKey($t->object, $t->property)] = true;
            return $this->escOperandNames($t->object, $names);
        }
        if ($t->kind === Node::KIND_ARRAY_ACCESS) { return $this->escOperandNames($t->array, $names); }
        return $t->kind === Node::KIND_LOAD_LOCAL && $names !== [];
    }
}
