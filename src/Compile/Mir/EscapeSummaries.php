<?php

namespace Compile\Mir;

/**
 * What a call may do to a PROPERTY slot while a value read out of that slot is
 * still in use: overwrite it (the store releases the old value), or suspend
 * (another task's store runs while it waits).
 *
 * A property store always releases what it overwrites, so a read that is still
 * held — a call argument, a receiver, an operand with a call to its right, a
 * `foreach` subject — is only safe as a borrow when nothing between the read
 * and its last use can do either. {@see Passes\SpillFreshBases} asks
 * {@see windowMeets} / {@see bodyMeets}; when they answer yes, the read co-owns
 * (spilled to a hidden local that retains it).
 *
 * Every function of the module gets a summary: may it park, which `prop|Class`
 * slots it may write ({@see propKey}), and whether it may write a slot it
 * cannot name. A monotone fixpoint over the call graph's strongly connected
 * components, callees first. A body not in this module, an FFI binding and a
 * generator are never judged; neither is anything the walk cannot see into
 * (a closure call, a dynamic `new`, a hook, `__get` / `__set`). An unjudged
 * callee writes everything and parks — a wrong guess costs a retain / release
 * pair, never a leak or a free.
 *
 * Not modelled: a `__destruct` or `__toString` a release or a conversion runs
 * inside the callee.
 */
final class EscapeSummaries
{
    /** @var array<string, bool> fn → judged (its summary below is meaningful) */
    private array $judged = [];
    /** @var array<string, bool> fn → cannot suspend */
    private array $parkFree = [];
    /** @var array<string, array<string, bool>> fn → the `prop|Class` slots it may write */
    private array $writes = [];
    /** @var array<string, bool> fn → may write a property it cannot name */
    private array $writesAny = [];
    /** @var array<string, string> fn → why it may park / write anything */
    private array $why = [];
    /** @var array<string, bool> fn whose parameter 0 is `this` */
    private array $thisFirst = [];

    /** @var array<string, ClassDef> */
    private array $classes = [];
    /** @var array<string, bool> */
    private array $interfaceNames = [];
    /** @var array<string, string[]> */
    private array $interfaceAncestors = [];
    /** @var array<string, EnumDef> */
    private array $enums = [];
    /** @var array<string, bool[]> fn → per-param by-ref mask */
    private array $refParams = [];

    // Walk scratch — one function (or one window) at a time.
    /** @var array<string, bool> */
    private array $ewWrites = [];
    private bool $ewAny = false;
    private bool $ewPark = true;
    private bool $ewRecording = false;
    private string $ewWhy = '';
    /** @var array<string, bool> summaries the walk read — the call graph's edges */
    private array $ewReads = [];

    /** @var array<string, string[]> `Class->method` → its dispatch targets */
    private array $targetMemo = [];
    /** @var array<string, string[]> method name → every class resolving it */
    private array $byMethodMemo = [];
    /** @var array<string, string[]> class / interface → every class that is-a it */
    private array $isAMemo = [];
    /** @var array<string, bool> */
    private array $pureNames = [];

    public static function fromModule(Module $module): self
    {
        $s = new self();
        $s->classes = $module->classes;
        $s->enums = $module->enums;
        $s->interfaceAncestors = $module->interfaceAncestors;
        foreach ($module->interfaceNames as $in => $unused) { $s->interfaceNames[$in] = true; }
        foreach ($module->functions as $fn) {
            $mask = [];
            foreach ($fn->params as $p) { $mask[] = $p->byRef; }
            $s->refParams[$fn->name] = $mask;
        }
        $s->compute($module);
        return $s;
    }

    /** `prop|Class` — a property slot as far as the receiver's static type pins
     *  it; `prop|` when it does not. */
    public static function propKey(Node $obj, string $prop): string
    {
        $t = $obj->type;
        return $prop . '|' . ($t->kind === Type::KIND_OBJ ? ($t->class ?? '') : '');
    }

    /** May `$call` (its callee, not its arguments) write `$cls::$prop`? */
    public function callWritesProp(Node $call, string $cls, string $prop): bool
    {
        $this->resetScratch();
        $this->noteCall($call);
        if ($this->ewAny) { return true; }
        return $this->keysMeet([$prop . '|' . $cls => true], $this->ewWrites);
    }

    /** May `$call` (its callee, not its arguments) suspend? */
    public function callMayPark(Node $call): bool
    {
        $this->resetScratch();
        $this->noteCall($call);
        return !$this->ewPark;
    }

    /**
     * Can anything in `$window` (evaluated whole), or the call `$consumer`
     * itself (its callee only — its operands are in the window), park, write
     * a property it cannot name, or write one of the `$held` slots?
     *
     * @param Node[] $window
     * @param array<string, bool> $held
     */
    public function windowMeets(array $window, ?Node $consumer, array $held): bool
    {
        $this->resetScratch();
        foreach ($window as $w) { $this->walk($w); }
        if ($consumer !== null) { $this->noteConsumer($consumer); }
        if (!$this->ewPark || $this->ewAny) { return true; }
        return $this->keysMeet($held, $this->ewWrites);
    }

    /**
     * The same question for a `foreach` subject held across its body.
     * @param array<string, bool> $held
     */
    public function bodyMeets(Node $body, array $held): bool
    {
        return $this->windowMeets([$body], null, $held);
    }

    /**
     * What `$n` itself runs once its operands are evaluated: a call's callee;
     * a property store's `set` hook or `__set` (the slot write itself is not
     * the question — the store retains the new value before it releases the
     * old); an element read or store on anything but an array, which may be
     * an ArrayAccess object's `offsetGet` / `offsetSet`.
     */
    private function noteConsumer(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_PROPERTY) {
            $saved = $this->ewWrites;
            $this->noteStoreProperty(self::asStoreProperty($n));
            $this->ewWrites = $saved;
            return;
        }
        if ($k === Node::KIND_STORE_ELEMENT || $k === Node::KIND_ARRAY_ACCESS) {
            $base = $k === Node::KIND_STORE_ELEMENT ? self::asStoreElement($n)->array : self::asArrayAccess($n)->array;
            $bk = $base->type->kind;
            if ($bk !== Type::KIND_ARRAY && $bk !== Type::KIND_STRING && !$this->arrayHintedSlot($base)) {
                if ($this->ewWhy === '') { $this->ewWhy = 'offset access on ' . $base->type->toString(); }
                $this->ewPark = false;
                $this->ewAny = true;
            }
            return;
        }
        $this->noteCall($n);
    }

    private function resetScratch(): void
    {
        $this->ewWrites = [];
        $this->ewAny = false;
        $this->ewPark = true;
        $this->ewWhy = '';
    }

    private function compute(Module $module): void
    {
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
            // overwrite the slot the value came from.
            if ($fn->isExtern || $fn->ffiSymbol !== null || $fn->isGenerator) { continue; }
            $byName[$fn->name] = $fn;
            $names[] = $fn->name;
            $this->judged[$fn->name] = true;
            if ($fn->params !== [] && $fn->params[0]->name === 'this') { $this->thisFirst[$fn->name] = true; }
            $this->parkFree[$fn->name] = true;
            $this->writes[$fn->name] = [];
            $this->writesAny[$fn->name] = false;
        }
        // 1. LOCAL effects and the call edges, every body once. With every
        //    summary still empty a judged callee contributes nothing, so what
        //    the walk collects is the body's own.
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
        foreach ($names as $name) {
            $this->resetScratch();
            $this->ewReads = [];
            $this->ewRecording = true;
            $this->walk($byName[$name]->body);
            $this->ewRecording = false;
            $callees[$name] = \array_keys($this->ewReads);
            $localPark[$name] = $this->ewPark;
            $localAny[$name] = $this->ewAny;
            $localWrites[$name] = $this->ewWrites;
            $localWhy[$name] = $this->ewWhy;
        }
        // 2. Strongly connected components, callees first. Every member of a
        //    component reaches every other, so its answer is ONE: the members'
        //    own effects and everything its outside callees (already final) do.
        $sccs = self::sccs($names, $callees);
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
                if ((!$localPark[$m] || $localAny[$m]) && $why === '') { $why = $localWhy[$m]; }
                if (!$localPark[$m]) { $park = false; }
                if ($localAny[$m]) { $any = true; }
                foreach ($localWrites[$m] as $wk => $unused) { $writes[$wk] = true; }
                foreach ($callees[$m] as $c) {
                    if (isset($inScc[$c])) { continue; }
                    if ((!$this->parkFree[$c] || $this->writesAny[$c]) && $why === '') { $why = 'via ' . $c; }
                    if (!$this->parkFree[$c]) { $park = false; }
                    if ($this->writesAny[$c]) { $any = true; }
                    foreach ($this->writes[$c] as $wk => $unused) { $writes[$wk] = true; }
                }
            }
            foreach ($scc as $m) {
                $this->parkFree[$m] = $park;
                $this->writesAny[$m] = $any;
                $this->writes[$m] = $writes;
                if (!$park || $any) { $this->why[$m] = $why; }
            }
        }
        \Compile\Stats::line('escape: fns=' . (string)\count($names) . ' sccs=' . (string)\count($sccs));
        $this->ewReads = [];
        $this->ewWrites = [];
        $want = \getenv('MANTICORE_KEEPS_TRACE');
        if ($want !== false && $want !== '') {
            foreach ($this->judged as $kn => $unused) {
                if (!\str_contains($kn, $want)) { continue; }
                \error_log('ESCAPE ' . $kn . ' park=' . ($this->parkFree[$kn] ? 'free' : 'MAY')
                    . ' writes=' . ($this->writesAny[$kn] ? '*' : \implode(',', \array_keys($this->writes[$kn])))
                    . ' why=' . ($this->why[$kn] ?? ''));
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
    private static function sccs(array $names, array $callees): array
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

    private function walk(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_PROPERTY) {
            $this->noteStoreProperty(self::asStoreProperty($n));
        } elseif ($k === Node::KIND_STORE_ELEMENT) {
            $this->noteWriteTarget(self::asStoreElement($n)->array);
        } elseif ($k === Node::KIND_REF_ADDR) {
            // `$x = &$this->p` aliases the slot: every later write through `$x`
            // is a write of `p` this walk cannot see.
            $this->noteWriteTarget(self::asRefAddr($n)->lvalue);
        } elseif ($k === Node::KIND_REF_CELL) {
            $this->noteWriteTarget(self::asRefCell($n)->refSource);
        } elseif ($k === Node::KIND_UNSET) {
            foreach (self::asUnset($n)->targets as $t) { $this->noteWriteTarget($t); }
        } elseif ($k === Node::KIND_STORE_DYN_PROP || $k === Node::KIND_DYN_PROP) {
            // A dynamic name may be any property, and a class without it runs
            // `__get` / `__set`.
            $this->ewAny = true;
            $this->ewPark = false;
        } elseif ($k === Node::KIND_PROPERTY_ACCESS) {
            if (!self::plainPropRead(self::asPropertyAccess($n), $this->classes)) { $this->ewPark = false; }
        } elseif ($k === Node::KIND_FOREACH) {
            // A subject that may be an OBJECT resumes user code — a Generator,
            // an Iterator — and that code can park.
            $fe = self::asForeach($n);
            $bt = $fe->array->type;
            if (!self::neverObject($bt) && !$this->arrayHintedSlot($fe->array)) {
                if ($this->ewWhy === '') { $this->ewWhy = 'foreach over ' . $bt->toString(); }
                $this->ewPark = false;
            }
            if ($fe->byRef) { $this->noteWriteTarget($fe->array); }
        } elseif ($k === Node::KIND_CLONE || $k === Node::KIND_NEW_DYN_OBJ
            || $k === Node::KIND_YIELD || $k === Node::KIND_REF_BIND) {
            $this->ewPark = false;
            $this->ewAny = true;
        } elseif ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_NEW_OBJ
            || $k === Node::KIND_INVOKE) {
            $this->noteCall($n);
        }
        if ((!$this->ewPark || $this->ewAny) && $this->ewWhy === '') { $this->ewWhy = 'node ' . $k; }
        foreach (Walk::children($n) as $c) { $this->walk($c); }
    }

    /** A read of a property DECLARED `array` / `?array`: the slot may be a
     *  cell, but php's type check never lets an object into it. */
    private function arrayHintedSlot(Node $e): bool
    {
        if ($e->kind !== Node::KIND_PROPERTY_ACCESS) { return false; }
        $pa = self::asPropertyAccess($e);
        $cd = $this->propHolder($pa->object, $pa->property);
        if ($cd === null) { return false; }
        $pt = $cd->propertyTypes[$pa->property] ?? null;
        if ($pt !== null && $pt->isArray()) { return true; }
        return ($cd->propertyArrayHinted[$pa->property] ?? false)
            || ($cd->propertyNeverObject[$pa->property] ?? false);
    }

    /** The class declaring `$obj->$prop`'s slot: the static class, or a subclass. */
    private function propHolder(Node $obj, string $prop): ?ClassDef
    {
        $cls = $obj->type->class ?? '';
        if ($cls === '' || !isset($this->classes[$cls])) { return null; }
        if ($this->classes[$cls]->propertyOffset($prop) >= 0) { return $this->classes[$cls]; }
        foreach ($this->classes as $cd) {
            if ($cd->name !== $cls && $cd->propertyOffset($prop) >= 0 && $this->extendsClass($cd->name, $cls)) {
                return $cd;
            }
        }
        return null;
    }

    private function extendsClass(string $name, string $base): bool
    {
        return self::extendsIn($this->classes, $name, $base);
    }

    /** @param array<string, ClassDef> $classes */
    private static function extendsIn(array $classes, string $name, string $base): bool
    {
        $cur = $name;
        $guard = 0;
        while ($cur !== '' && isset($classes[$cur]) && $guard < 256) {
            $p = $classes[$cur]->parent;
            if ($p === $base) { return true; }
            $cur = $p;
            $guard = $guard + 1;
        }
        return false;
    }

    /** Does a SUBCLASS of `$cls` declare `$prop` — a write through an
     *  imprecise static type rather than a dynamic property? */
    /** @param array<string, ClassDef> $classes */
    private static function subclassDeclares(array $classes, string $cls, string $prop): bool
    {
        foreach ($classes as $cd) {
            if ($cd->name !== $cls && $cd->propertyOffset($prop) !== -1 && self::extendsIn($classes, $cd->name, $cls)) {
                return true;
            }
        }
        return false;
    }

    /** An array, a null, or a union / cell whose every atom is one of those. */
    private static function neverObject(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_ARRAY || $k === Type::KIND_NULL) { return true; }
        if (($k === Type::KIND_UNION || $k === Type::KIND_CELL) && $t->atoms !== []) {
            foreach ($t->atoms as $at) {
                if (!self::neverObject($at)) { return false; }
            }
            return true;
        }
        return false;
    }

    /**
     * A declared, hook-free property of a known class: reading it runs no code.
     * @param array<string, ClassDef> $classes
     */
    public static function plainPropRead(PropertyAccess_ $pa, array $classes): bool
    {
        $cls = $pa->object->type->class ?? '';
        if ($pa->object->type->kind !== Type::KIND_OBJ || $cls === '' || !isset($classes[$cls])) {
            // A receiver whose class is not pinned reads through the bag or a
            // union dispatch; neither runs user code unless the name is magic.
            return true;
        }
        $cd = $classes[$cls];
        if (($cd->propHooks[$pa->property]['get'] ?? '') !== '') { return false; }
        if ($cd->propertyOffset($pa->property) === -1 && !self::subclassDeclares($classes, $cls, $pa->property)
            && self::resolveMethodIn($classes, $cls, '__get') !== '') {
            return false;
        }
        return true;
    }

    private function noteStoreProperty(StoreProperty $n): void
    {
        $this->ewWrites[self::propKey($n->object, $n->property)] = true;
        $cls = $n->object->type->class ?? '';
        if ($n->object->type->kind !== Type::KIND_OBJ || $cls === '' || !isset($this->classes[$cls])) { return; }
        $cd = $this->classes[$cls];
        if (!$n->bypassHook && ($cd->propHooks[$n->property]['set'] ?? '') !== '') {
            $this->ewPark = false;
            $this->ewAny = true;
            return;
        }
        if ($cd->propertyOffset($n->property) === -1 && !self::subclassDeclares($this->classes, $cls, $n->property)
            && $this->resolveMethodClass($cls, '__set') !== '') {
            $this->ewPark = false;
            $this->ewAny = true;
        }
    }

    /** Record every property NAME along an lvalue chain (`$this->a->b[$k]`)
     *  as written: the store writes the last, and may free what the others hold. */
    private function noteWriteTarget(Node $t): void
    {
        if ($t->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = self::asPropertyAccess($t);
            $this->ewWrites[self::propKey($pa->object, $pa->property)] = true;
            $this->noteWriteTarget($pa->object);
        } elseif ($t->kind === Node::KIND_ARRAY_ACCESS) {
            $this->noteWriteTarget(self::asArrayAccess($t)->array);
        } elseif ($t->kind === Node::KIND_DYN_PROP) {
            $this->ewAny = true;
        } elseif ($t->kind === Node::KIND_STORE_LOCAL) {
            $this->noteWriteTarget(self::asStoreLocal($t)->value);
        }
    }

    /**
     * A call's argument list, read under each call kind's own test.
     * @return Node[]
     */
    private static function callArgs(Node $n): array
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL) { return self::asCall($n)->args; }
        if ($k === Node::KIND_METHOD_CALL) { return self::asMethodCall($n)->args; }
        if ($k === Node::KIND_STATIC_CALL) { return self::asStaticCall($n)->args; }
        if ($k === Node::KIND_NEW_OBJ) { return self::asNewObj($n)->args; }
        if ($k === Node::KIND_INVOKE) { return self::asInvoke($n)->args; }
        return [];
    }

    /** Fold one call's CALLEE into the walk (its operands are walked on their own). */
    private function noteCall(Node $n): void
    {
        $args = self::callArgs($n);
        // A GLOBAL name on the list is the builtin even where the module
        // carries its same-named PHP body (the bootstrap pair: `emitBuiltin`
        // shadows `strpos`'s stdlib body); a namespaced one is the user's.
        if ($n->kind === Node::KIND_CALL) {
            $fname = self::asCall($n)->function;
            if ((!isset($this->judged[$fname]) || !\str_contains($fname, \chr(92)))
                && $this->builtinEffectFree($fname)) {
                // The by-reference mutators (`array_push`, `sort`, …) write the
                // slot their first argument names.
                if ($args !== [] && VecCopyOnAssign::mutatesArg0($fname)) { $this->noteWriteTarget($args[0]); }
                return;
            }
        }
        if ($n->kind === Node::KIND_STATIC_CALL && $this->enumFromKeepsNoArg(self::asStaticCall($n))) { return; }
        $targets = $n->kind === Node::KIND_INVOKE ? [] : $this->targets($n);
        if ($targets === [] || self::hasSpread($args)) {
            if ($this->ewWhy === '') { $this->ewWhy = 'call ' . self::callName($n); }
            $this->ewPark = false;
            $this->ewAny = true;
            return;
        }
        foreach ($targets as $t) {
            if ($this->ewRecording) { $this->ewReads[$t] = true; }
            if ((!$this->parkFree[$t] || $this->writesAny[$t]) && $this->ewWhy === '') { $this->ewWhy = 'via ' . $t; }
            if (!$this->parkFree[$t]) { $this->ewPark = false; }
            if ($this->writesAny[$t]) { $this->ewAny = true; }
            foreach ($this->writes[$t] as $prop => $unused) { $this->ewWrites[$prop] = true; }
            $off = isset($this->thisFirst[$t]) ? 1 : 0;
            $refs = $this->refParams[$t] ?? [];
            $i = 0;
            foreach ($args as $a) {
                if ($refs[$i + $off] ?? false) { $this->noteWriteTarget($a); }
                $i = $i + 1;
            }
        }
    }

    /** A call's callee, for the trace. */
    private static function callName(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL) { return self::asCall($n)->function; }
        if ($k === Node::KIND_METHOD_CALL) {
            $mc = self::asMethodCall($n);
            return ($mc->object->type->class ?? '?') . '->' . $mc->method;
        }
        if ($k === Node::KIND_STATIC_CALL) {
            $sc = self::asStaticCall($n);
            return $sc->class . '::' . $sc->method;
        }
        if ($k === Node::KIND_NEW_OBJ) { return 'new ' . self::asNewObj($n)->class; }
        return $k;
    }

    /** @param Node[] $args */
    private static function hasSpread(array $args): bool
    {
        foreach ($args as $a) {
            if ($a->kind === Node::KIND_SPREAD) { return true; }
        }
        return false;
    }

    /** A backed enum's `from` / `tryFrom`: an unrolled compare against the case
     *  values that yields a singleton and runs no user code. */
    private function enumFromKeepsNoArg(StaticCall_ $n): bool
    {
        return isset($this->enums[$n->class]) && \count($n->args) === 1
            && ($n->method === 'from' || $n->method === 'tryFrom');
    }

    /**
     * Whether this builtin READS its arguments and keeps nothing. A NAME list
     * on purpose: the contract is php's, not our implementation's. `fwrite` /
     * `fputs` are NOT here — their stdlib body parks on back-pressure.
     */
    public static function keepsNoArg(string $fn): bool
    {
        $p = \strrpos($fn, \chr(92));
        $bare = $p === false ? $fn : \substr($fn, $p + 1);
        foreach ([
            'strlen', 'mb_strlen', 'count', 'sizeof', 'ord', 'trim', 'ltrim', 'rtrim',
            'strtolower', 'strtoupper', 'ucfirst', 'lcfirst', 'strrev', 'md5', 'sha1',
            'crc32', 'intval', 'floatval', 'boolval', 'strval', 'is_string', 'is_int',
            'is_float', 'is_bool', 'is_array', 'is_object', 'is_null', 'is_numeric',
            'is_callable', 'is_iterable', 'is_scalar', 'strpos', 'stripos', 'strrpos',
            'str_contains', 'str_starts_with', 'str_ends_with', 'substr_count',
            'number_format', 'dechex', 'hexdec', 'decbin', 'bindec', 'decoct', 'octdec',
            'abs', 'floor', 'ceil', 'round', 'sqrt', 'intdiv', 'json_last_error',
            'substr', 'mb_substr', 'str_repeat', 'explode',
            'array_shift', 'array_pop', 'array_push', 'array_unshift',
            'array_key_exists', 'array_key_first', 'array_key_last',
            'sort', 'rsort', 'ksort', 'krsort', 'asort', 'arsort',
            '__str_byte_at', '__mc_ser_val', '__mir_var_export',
        ] as $n) {
            if ($n === $bare) { return true; }
        }
        return false;
    }

    /**
     * A builtin that cannot SUSPEND, runs no user code and writes no property
     * (bar its by-reference first argument). An ALLOW list: anything taking a
     * callback, anything that runs user code through a magic method
     * (json_encode, serialize), and every stream / socket / sleep / process
     * call stays out.
     */
    private function builtinEffectFree(string $fn): bool
    {
        if (self::keepsNoArg($fn)) { return true; }
        if ($this->pureNames === []) {
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
                $this->pureNames[$nm] = true;
            }
        }
        $p = \strrpos($fn, \chr(92));
        $bare = $p === false ? $fn : \substr($fn, $p + 1);
        return isset($this->pureNames[$bare]);
    }

    /** The class whose body `$class`'s `$method` resolves to, or ''. */
    private function resolveMethodClass(string $class, string $method): string
    {
        return self::resolveMethodIn($this->classes, $class, $method);
    }

    /** @param array<string, ClassDef> $classes */
    private static function resolveMethodIn(array $classes, string $class, string $method): string
    {
        $c = $class;
        $guard = 0;
        while ($c !== '' && isset($classes[$c]) && $guard < 256) {
            $cd = $classes[$c];
            if (isset($cd->methodNames[$method])) { return $c; }
            $c = $cd->parent;
            $guard = $guard + 1;
        }
        return '';
    }

    /**
     * Every class that is-a `$target` — itself, a descendant, an implementer
     * (through a parent interface too), or a reified specialization of one.
     * Over-inclusive is safe: a spare target only makes a call less judged.
     * @return string[]
     */
    private function isAClasses(string $target): array
    {
        if (isset($this->isAMemo[$target])) { return $this->isAMemo[$target]; }
        $out = [];
        foreach ($this->classes as $cd) {
            if ($this->isA($cd->name, $target)) { $out[] = $cd->name; }
        }
        $this->isAMemo[$target] = $out;
        return $out;
    }

    private function isA(string $name, string $target): bool
    {
        /** @var array<string, bool> $seen */
        $seen = [];
        /** @var string[] $stack */
        $stack = [$name];
        while ($stack !== []) {
            $c = \array_pop($stack);
            if ($c === '' || isset($seen[$c])) { continue; }
            $seen[$c] = true;
            if ($c === $target) { return true; }
            if (!isset($this->classes[$c])) {
                foreach ($this->interfaceAncestors[$c] ?? [] as $ia) { $stack[] = $ia; }
                continue;
            }
            $cd = $this->classes[$c];
            $stack[] = $cd->parent;
            $stack[] = $cd->originClass;
            // `ClassDef::$interfaces` is a bare `array`: its elements arrive erased.
            foreach ($cd->interfaces as $i) { $stack[] = (string)$i; }
        }
        return false;
    }

    /**
     * Every concrete class a method NAME can dispatch to, or [] when that is
     * unknowable: a class with `__call`, or a name a Closure answers.
     * @return string[]
     */
    private function classesWithMethod(string $m): array
    {
        if (isset($this->byMethodMemo[$m])) { return $this->byMethodMemo[$m]; }
        $out = [];
        if ($m !== '__invoke' && $m !== 'call' && $m !== 'bind' && $m !== 'bindTo'
            && $m !== 'fromCallable') {
            foreach ($this->classes as $cd) {
                if ($this->resolveMethodClass($cd->name, '__call') !== '') { $out = []; break; }
                if ($this->resolveMethodClass($cd->name, $m) !== '') { $out[] = $cd->name; }
            }
        }
        $this->byMethodMemo[$m] = $out;
        return $out;
    }

    /**
     * Every judged body a call can reach, or [] when one of them is not
     * judged (a builtin, an import, `__call`, an interface without a known
     * implementer, a closure).
     * @return string[]
     */
    private function targets(Node $n): array
    {
        if ($n->kind === Node::KIND_METHOD_CALL) {
            $mc = self::asMethodCall($n);
            $rt = $mc->object->type;
            $mk = ($rt->kind === Type::KIND_OBJ ? ($rt->class ?? '') : '') . '->' . $mc->method;
            if (!isset($this->targetMemo[$mk])) { $this->targetMemo[$mk] = $this->targetsOf($n); }
            return $this->targetMemo[$mk];
        }
        return $this->targetsOf($n);
    }

    /** @return string[] */
    private function targetsOf(Node $n): array
    {
        $out = [];
        $k = $n->kind;
        if ($k === Node::KIND_CALL) {
            $out[] = self::asCall($n)->function;
        } elseif ($k === Node::KIND_NEW_OBJ) {
            $no = self::asNewObj($n);
            if ($no->bare) { return []; }
            $cls = $no->class;
            if ($cls === '' || !isset($this->classes[$cls])) { return []; }
            $owner = $this->resolveMethodClass($cls, '__construct');
            if ($owner === '') { return []; }
            $out[] = $owner . '____construct';
        } elseif ($k === Node::KIND_STATIC_CALL) {
            $sc = self::asStaticCall($n);
            if (!isset($this->classes[$sc->class])) { return []; }
            $owner = $this->resolveMethodClass($sc->class, $sc->method);
            if ($owner === '') { return []; }
            $out[] = $owner . '__' . $sc->method;
        } elseif ($k === Node::KIND_METHOD_CALL) {
            $mc = self::asMethodCall($n);
            $t = $mc->object->type;
            $cls = $t->class ?? '';
            if ($t->kind !== Type::KIND_OBJ || $cls === '') {
                $cands = $this->classesWithMethod($mc->method);
            } elseif (isset($this->classes[$cls]) || isset($this->interfaceNames[$cls])) {
                $cands = $this->isAClasses($cls);
            } else {
                return [];
            }
            /** @var array<string, bool> $seen */
            $seen = [];
            foreach ($cands as $d) {
                // No object is ever exactly an abstract class, and its abstract
                // methods have no body to judge.
                if (!isset($this->classes[$d]) || $this->classes[$d]->isAbstract) { continue; }
                $owner = $this->resolveMethodClass($d, $mc->method);
                if ($owner === '') { return []; }
                $name = $owner . '__' . $mc->method;
                if (isset($seen[$name])) { continue; }
                $seen[$name] = true;
                $out[] = $name;
            }
        } else {
            return [];
        }
        foreach ($out as $name) {
            if (!isset($this->judged[$name])) { return []; }
        }
        return $out;
    }

    /**
     * Can any slot of `$held` be one of `$written`? Same property name, and
     * receivers that can be the same object: one class, one the other's
     * ancestor, or either unpinned — an interface, a trait, an unknown class.
     * @param array<string, bool> $held
     * @param array<string, bool> $written
     */
    private function keysMeet(array $held, array $written): bool
    {
        foreach ($held as $hk => $unused) {
            $hp = \strpos($hk, '|');
            $hProp = \substr($hk, 0, $hp);
            $hCls = \substr($hk, $hp + 1);
            foreach ($written as $wk => $unused2) {
                $wp = \strpos($wk, '|');
                if (\substr($wk, 0, $wp) !== $hProp) { continue; }
                if ($this->classesMeet($hCls, \substr($wk, $wp + 1))) { return true; }
            }
        }
        return false;
    }

    private function classesMeet(string $a, string $b): bool
    {
        if ($a === '' || $b === '' || $a === $b) { return true; }
        if (!isset($this->classes[$a]) || !isset($this->classes[$b])) { return true; }
        return $this->isA($a, $b) || $this->isA($b, $a);
    }

    // Typed reads — a base-typed `$n` resolves fields by OFFSET under self-host.
    private static function asStoreProperty(Node $n): StoreProperty { return $n; }
    private static function asStoreElement(Node $n): StoreElement { return $n; }
    private static function asStoreLocal(Node $n): StoreLocal { return $n; }
    private static function asRefAddr(Node $n): RefAddr_ { return $n; }
    private static function asRefCell(Node $n): RefCell_ { return $n; }
    private static function asUnset(Node $n): Unset_ { return $n; }
    private static function asPropertyAccess(Node $n): PropertyAccess_ { return $n; }
    private static function asArrayAccess(Node $n): ArrayAccess_ { return $n; }
    private static function asForeach(Node $n): Foreach_ { return $n; }
    private static function asCall(Node $n): Call { return $n; }
    private static function asMethodCall(Node $n): MethodCall_ { return $n; }
    private static function asStaticCall(Node $n): StaticCall_ { return $n; }
    private static function asNewObj(Node $n): NewObj { return $n; }
    private static function asInvoke(Node $n): Invoke_ { return $n; }
}
