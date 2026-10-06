<?php

namespace Compile\Mir;

/**
 * Which functions of the module can NOT unwind: the question a call site with
 * owned locals live across it asks before it pays for a cleanup landing pad
 * ({@see callMayThrow}). Computed once, before emission, on the final MIR.
 *
 * A function is nothrow iff its body holds no node that may raise and every
 * callee is nothrow — a fixpoint over the call graph's strongly connected
 * components ({@see CallGraphScc}), callees first. A node may raise unless it
 * is on an ALLOW list ({@see nodeRaises}): every MIR kind not named there, a
 * listed kind whose operands could run user code (`__toString`, `__get`,
 * an ArrayAccess / Iterator object, a hook) or reach one of the emitter's own
 * throws (`/` `%` by zero, a negative shift, a readonly store, a shape check),
 * and every call the walk cannot pin to judged bodies — an Invoke_, a dynamic
 * `new`, a spread, `__call`, an import, an FFI binding, a generator. A builtin
 * is nothrow only when it is on {@see builtinNothrow} with operands that hold
 * no object; a compiler runtime helper (`__mir_*`, `__mc_*`) is nothrow except
 * the listed throwers ({@see helperThrows}). Unknown ⇒ may throw: a wrong
 * "may throw" costs a landing pad, a wrong "nothrow" a leak on unwind.
 *
 * A release may run a `__destruct`, and a destructor may throw. The fixpoint
 * first assumes no release raises; when every destructor of the module comes
 * out nothrow under that assumption, it is consistent (a throw needs a real
 * origin). Otherwise it is recomputed with every value that may hold an
 * object counted as a raising release ({@see dtorSafe}).
 */
final class NothrowSummary
{
    /** @var array<string, bool> judged fn → cannot unwind */
    private array $nothrow = [];
    /** @var array<string, string> fn → the first reason it may unwind */
    private array $why = [];
    /** @var array<string, bool> every function name the module carries a def of */
    private array $defined = [];
    /** @var array<string, ClassDef> */
    private array $classes = [];
    /** @var array<string, EnumDef> */
    private array $enums = [];
    private ?EscapeSummaries $resolve = null;
    /** Some destructor may throw: a release of an object-holding value raises. */
    private bool $releasesRaise = false;
    /** The destructor that may throw, and why — the stats line's answer. */
    private string $dtorWhy = '';
    /** @var array<string, bool> classes an instance release of may throw ({@see closeUnsafe}) */
    private array $unsafeCls = [];
    /** {@see closeUnsafe} is done: {@see dtorSafe} may memoize. */
    private bool $unsafeFinal = false;
    /** @var array<string, bool> every class a `new` names */
    private array $newCls = [];
    /** The module builds an object by a runtime class name: any class may have an instance. */
    private bool $dynNew = false;
    /** The module calls into the stdlib, which may hand out an instance of any class. */
    private bool $imports = false;
    /** @var array<string, bool> */
    private array $objSafeMemo = [];
    /** @var array<string, bool> */
    private array $interfaceNames = [];
    /** @var array<string, bool> */
    private array $nothrowBuiltins = [];

    // Walk scratch — one function at a time.
    private string $wWhy = '';
    /** The module takes a php function's address for C (`fn_to_ptr`). */
    private bool $sawFnToPtr = false;
    /** @var array<string, bool> FFI bindings — judged here, not by the resolver */
    private array $ffi = [];
    /** @var array<string, bool> */
    private array $wCallees = [];
    /** The function the walk is in. */
    private string $wFn = '';

    public static function fromModule(Module $module): self
    {
        $s = new self();
        $s->classes = $module->classes;
        $s->enums = $module->enums;
        foreach ($module->interfaceNames as $in => $unused) { $s->interfaceNames[$in] = true; }
        $s->resolve = EscapeSummaries::resolver($module);
        foreach ($module->functions as $fn) {
            $s->defined[$fn->name] = true;
            if ($fn->isExtern) { $s->imports = true; }
            if ($fn->ffiSymbol !== null && !$fn->isGenerator) { $s->ffi[$fn->name] = true; }
        }
        $s->compute($module);
        if (!$s->dtorsNothrow()) {
            $s->releasesRaise = true;
            $s->compute($module);
        }
        if (\Compile\Stats::$on) {
            $n = 0;
            foreach ($s->nothrow as $v) { if ($v) { $n = $n + 1; } }
            \Compile\Stats::line('nothrow: fns=' . (string)\count($s->nothrow) . ' nothrow=' . (string)$n
                . ' releasesRaise=' . ($s->releasesRaise ? '1 (' . $s->dtorWhy . ')' : '0'));
        }
        $want = \getenv('MANTICORE_NOTHROW_TRACE');
        if ($want !== false && $want !== '') {
            foreach ($s->nothrow as $fn => $v) {
                if ($want !== '*' && !\str_contains($fn, $want)) { continue; }
                \error_log('NOTHROW ' . $fn . ' ' . ($v ? 'yes' : 'NO why=' . ($s->why[$fn] ?? '')));
            }
        }
        return $s;
    }

    /** Some destructor may throw: a release that may drop an object may unwind. */
    public function releasesRaise(): bool
    {
        return $this->releasesRaise;
    }

    /**
     * Every judged function that cannot unwind.
     * @return string[]
     */
    public function nothrowNames(): array
    {
        $out = [];
        foreach ($this->nothrow as $fn => $v) {
            if ($v) { $out[] = $fn; }
        }
        return $out;
    }

    /** A judged function that cannot unwind; false for anything not judged. */
    public function nothrow(string $fn): bool
    {
        return $this->nothrow[$fn] ?? false;
    }

    /**
     * May the call `$n` itself — its callee, not its operands, which are
     * their own sites — unwind? Call / MethodCall_ / StaticCall_ / NewObj /
     * Invoke_; anything else answers true.
     */
    public function callMayThrow(Node $n): bool
    {
        $this->wCallees = [];
        if ($this->noteCall($n) !== '') { return true; }
        foreach ($this->wCallees as $c => $unused) {
            if (!($this->nothrow[$c] ?? false)) { return true; }
        }
        return false;
    }

    /**
     * Does an unwind through `$call` need a cleanup landing pad: some local is
     * Own at it ({@see Call::$ownLive}) and its callee may throw.
     */
    public function callNeedsPad(Node $call): bool
    {
        return self::ownLive($call) !== [] && $this->callMayThrow($call);
    }

    /**
     * A call node's {@see Call::$ownLive}; [] for any other node.
     * @return array<string, MemoryOp_>
     */
    public static function ownLive(Node $n): array
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL) { return self::asCall($n)->ownLive; }
        if ($k === Node::KIND_METHOD_CALL) { return self::asMethodCall($n)->ownLive; }
        if ($k === Node::KIND_STATIC_CALL) { return self::asStaticCall($n)->ownLive; }
        if ($k === Node::KIND_NEW_OBJ) { return self::asNewObj($n)->ownLive; }
        if ($k === Node::KIND_INVOKE) { return self::asInvoke($n)->ownLive; }
        return [];
    }

    /** `MANTICORE_STATS`: call sites, those with an Own local live, those needing a pad. */
    public function reportSites(Module $module): void
    {
        $sites = 0;
        $live = 0;
        $pads = 0;
        /** @var Node[] $stack */
        $stack = [];
        foreach ($module->functions as $fn) { $stack[] = $fn->body; }
        while ($stack !== []) {
            $n = \array_pop($stack);
            $k = $n->kind;
            if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
                || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_INVOKE) {
                $sites = $sites + 1;
                if (self::ownLive($n) !== []) {
                    $live = $live + 1;
                    if ($this->callMayThrow($n)) { $pads = $pads + 1; }
                }
            }
            foreach (Walk::children($n) as $c) { $stack[] = $c; }
        }
        \Compile\Stats::line('nothrow: call sites=' . (string)$sites . ' ownLive=' . (string)$live
            . ' needPad=' . (string)$pads);
    }

    private function compute(Module $module): void
    {
        $this->nothrow = [];
        $this->why = [];
        /** @var string[] $names */
        $names = [];
        /** @var array<string, string[]> $callees */
        $callees = [];
        /** @var array<string, string> $localWhy */
        $localWhy = [];
        $this->sawFnToPtr = false;
        foreach ($module->functions as $fn) {
            if (!EscapeSummaries::judgeable($fn)) { continue; }
            $names[] = $fn->name;
            $this->wFn = $fn->name;
            $this->wWhy = '';
            $this->wCallees = [];
            $this->walk($fn->body);
            $localWhy[$fn->name] = $this->wWhy;
            $callees[$fn->name] = \array_keys($this->wCallees);
        }
        // An FFI binding runs C, which cannot raise a php exception — unless C
        // calls back into php ({@see docs/ffi.md}: `fn_to_ptr`), which the
        // contract forbids to throw but nothing enforces: once the module
        // takes a function's address, a binding passed anything that may be
        // a pointer may unwind.
        foreach ($module->functions as $fn) {
            if ($fn->ffiSymbol === null || $fn->isGenerator) { continue; }
            $names[] = $fn->name;
            $callees[$fn->name] = [];
            $why = '';
            if ($this->sawFnToPtr) {
                foreach ($fn->params as $p) {
                    $pk = $p->type->kind;
                    if ($pk !== Type::KIND_INT && $pk !== Type::KIND_FLOAT && $pk !== Type::KIND_BOOL
                        && $pk !== Type::KIND_STRING) {
                        $why = 'ffi callback';
                    }
                }
            }
            $localWhy[$fn->name] = $why;
        }
        $this->wCallees = [];
        foreach (CallGraphScc::components($names, $callees) as $scc) {
            /** @var array<string, bool> $inScc */
            $inScc = [];
            foreach ($scc as $m) { $inScc[$m] = true; }
            $why = '';
            foreach ($scc as $m) {
                if ($why === '' && $localWhy[$m] !== '') { $why = $localWhy[$m]; }
                foreach ($callees[$m] as $c) {
                    if ($why !== '') { break; }
                    if (isset($inScc[$c])) { continue; }
                    if (!$this->nothrow[$c]) { $why = 'via ' . $c; }
                }
            }
            foreach ($scc as $m) {
                $this->nothrow[$m] = $why === '';
                if ($why !== '') { $this->why[$m] = $why; }
            }
        }
    }

    /**
     * Every class INSTANTIATED here has a judged nothrow destructor. A class
     * never named by a `new` cannot have an instance, unless the module builds
     * objects by a runtime class name ({@see $dynNew}) or imports from the
     * stdlib ({@see $imports}: `socket_create()` hands out a `Socket` the module
     * never `new`s). Collects the classes whose destructor may throw, and
     * closes the unsafe class set over them ({@see closeUnsafe}). A class the
     * module carries no def of has no destructor to judge here: a throw out of
     * one is a missed pad (a leak on that unwind), never a wrong unwind — a
     * call with no landing pad unwinds on.
     */
    private function dtorsNothrow(): bool
    {
        $ok = true;
        foreach ($this->classes as $cd) {
            if (!$this->dynNew && !$this->imports && !isset($this->newCls[$cd->name])) { continue; }
            $owner = EscapeSummaries::resolveMethodIn($this->classes, $cd->name, '__destruct');
            if ($owner === '') { continue; }
            if (!($this->nothrow[$owner . '____destruct'] ?? false)) {
                if ($ok) { $this->dtorWhy = $owner . ': ' . ($this->why[$owner . '____destruct'] ?? 'not judged'); }
                $this->unsafeCls[$cd->name] = true;
                $ok = false;
            }
        }
        if (!$ok) { $this->closeUnsafe(); }
        return $ok;
    }

    /**
     * A class is UNSAFE when releasing an instance may run a destructor that
     * throws: its own (resolved) destructor may, it carries a dynamic-property
     * bag, or a declared property's type may hold an unsafe value. A
     * fixpoint over the classes, grown from the throwing destructors.
     */
    private function closeUnsafe(): void
    {
        foreach ($this->classes as $cd) {
            if ($cd->hasBag) { $this->unsafeCls[$cd->name] = true; }
        }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($this->classes as $cd) {
                if (isset($this->unsafeCls[$cd->name])) { continue; }
                foreach ($cd->propertyNames as $p) {
                    $pt = $cd->propertyTypes[$p] ?? null;
                    if ($pt === null || !$this->dtorSafe($pt)) {
                        $this->unsafeCls[$cd->name] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }
        $this->unsafeFinal = true;
    }

    /** A value of this type holds nothing whose release may run a throwing destructor. */
    private function dtorSafe(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_VOID || $k === Type::KIND_NULL || $k === Type::KIND_BOOL
            || $k === Type::KIND_INT || $k === Type::KIND_FLOAT || $k === Type::KIND_STRING) {
            return true;
        }
        if ($k === Type::KIND_ARRAY) {
            $el = $t->element;
            return $el !== null && $this->dtorSafe($el);
        }
        if ($k === Type::KIND_UNION) {
            foreach ($t->atoms as $a) {
                if (!$this->dtorSafe($a)) { return false; }
            }
            return true;
        }
        if ($k !== Type::KIND_OBJ) { return false; }
        $cls = $t->class ?? '';
        if ($cls === '') { return false; }
        if ($this->unsafeFinal && isset($this->objSafeMemo[$cls])) { return $this->objSafeMemo[$cls]; }
        $safe = isset($this->classes[$cls]) || isset($this->interfaceNames[$cls]);
        if ($safe) {
            foreach ($this->resolve->classesIsA($cls) as $d) {
                if (isset($this->unsafeCls[$d])) { $safe = false; break; }
            }
        }
        if ($this->unsafeFinal) { $this->objSafeMemo[$cls] = $safe; }
        return $safe;
    }

    /** The whole body, past the first reason too: a `fn_to_ptr` anywhere matters. */
    private function walk(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_NEW_OBJ) {
            $this->newCls[self::asNewObj($n)->class] = true;
        } elseif ($k === Node::KIND_NEW_DYN_OBJ) {
            $this->dynNew = true;
        } elseif ($k === Node::KIND_CALL && \str_starts_with(self::asCall($n)->function, '__mc_refl_')) {
            $this->dynNew = true;
        }
        $r = $this->nodeRaises($n);
        if ($r !== '' && $this->wWhy === '') { $this->wWhy = $r . ' (line ' . (string)$n->line . ')'; }
        if ($this->releasesRaise && $this->wWhy === '') {
            $t = $this->releasedType($n);
            if ($t !== null && !$this->dtorSafe($t)) {
                $this->wWhy = 'release of ' . $t->toString() . ' (line ' . (string)$n->line . ')';
            }
        }
        foreach (Walk::children($n) as $c) { $this->walk($c); }
    }

    /**
     * The type of what `$n` may release, or null: a `drop`, a store's old
     * value (the slot holds the stored value's type), a return's drops, a
     * foreach binding's drop, and a call's / `new`'s result (a discarded
     * temporary is released at the statement). A parameter arrives borrowed
     * and a read borrows: neither releases.
     */
    private function releasedType(Node $n): ?Type
    {
        $k = $n->kind;
        if ($k === Node::KIND_MEMORY_OP) {
            $mo = self::asMemoryOp($n);
            return $mo->op === 'drop' && $mo->target !== null ? $mo->target->type : null;
        }
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = self::asStoreLocal($n);
            return $sl->ownOld !== null ? $sl->value->type : null;
        }
        if ($k === Node::KIND_RETURN) {
            foreach (self::asReturn($n)->ownDrops as $d) {
                $dt = $d->target;
                if ($dt !== null && !$this->dtorSafe($dt->type)) { return $dt->type; }
            }
            return null;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            if ($fe->ownDropValue === null && $fe->ownDropKey === null) { return null; }
            return $fe->array->type->element ?? Type::unknown();
        }
        if ($k === Node::KIND_STORE_ELEMENT) { return self::asStoreElement($n)->value->type; }
        if ($k === Node::KIND_STORE_PROPERTY) { return self::asStoreProperty($n)->value->type; }
        if ($k === Node::KIND_UNSET) { return Type::unknown(); }
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_INVOKE) {
            return $n->type;
        }
        return null;
    }

    /** Why `$n` itself may raise, or '' — its children are judged on their own. */
    private function nodeRaises(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_INT_CONST || $k === Node::KIND_FLOAT_CONST || $k === Node::KIND_STRING_CONST
            || $k === Node::KIND_BOOL_CONST || $k === Node::KIND_NULL_CONST || $k === Node::KIND_LOAD_LOCAL
            || $k === Node::KIND_STORE_LOCAL || $k === Node::KIND_NOT || $k === Node::KIND_TERNARY
            || $k === Node::KIND_NULLCOALESCE || $k === Node::KIND_INSTANCEOF || $k === Node::KIND_ISSET
            || $k === Node::KIND_UNSET || $k === Node::KIND_GOTO || $k === Node::KIND_LABEL
            || $k === Node::KIND_BLOCK || $k === Node::KIND_IF || $k === Node::KIND_WHILE
            || $k === Node::KIND_FOR || $k === Node::KIND_DOWHILE || $k === Node::KIND_BREAK
            || $k === Node::KIND_CONTINUE || $k === Node::KIND_RETURN || $k === Node::KIND_ARRAY_LIT
            || $k === Node::KIND_MEMORY_OP || $k === Node::KIND_CAUGHT_VALUE || $k === Node::KIND_TRY_CATCH
            || $k === Node::KIND_STATIC_LOCAL_DECL || $k === Node::KIND_STATIC_PROP || $k === Node::KIND_CLOSURE) {
            return '';
        }
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL) {
            $l = Walk::children($n);
            if ($k === Node::KIND_ADD && $l[0]->type->kind === Type::KIND_ARRAY && $l[1]->type->kind === Type::KIND_ARRAY) {
                return '';
            }
            return self::numeric($l[0]->type) && self::numeric($l[1]->type) ? '' : 'arith on ' . $l[0]->type->toString();
        }
        if ($k === Node::KIND_DIV || $k === Node::KIND_MOD) {
            $l = Walk::children($n);
            $r = $l[1];
            $nonZero = ($r->kind === Node::KIND_INT_CONST && self::asIntConst($r)->value !== 0)
                || ($r->kind === Node::KIND_FLOAT_CONST && self::asFloatConst($r)->value !== 0.0);
            return $nonZero && self::numeric($l[0]->type) ? '' : 'division';
        }
        if ($k === Node::KIND_NEG || $k === Node::KIND_BITNOT || $k === Node::KIND_INCDEC) {
            return self::numeric($n->type) ? '' : 'unary on ' . $n->type->toString();
        }
        if ($k === Node::KIND_BITOP) {
            $b = self::asBitOp($n);
            if (!self::numeric($b->left->type) || !self::numeric($b->right->type)) { return 'bitop operand'; }
            if ($b->op === 'shl' || $b->op === 'shr') {
                $r = $b->right;
                return $r->kind === Node::KIND_INT_CONST && self::asIntConst($r)->value >= 0 ? '' : 'shift';
            }
            return '';
        }
        if ($k === Node::KIND_CMP) {
            // `===` / `!==` compare identity and tags; a compare with null
            // answers from the tag. Neither runs user code.
            $c = self::asCmp($n);
            if ($c->op === '===' || $c->op === '!==' || $c->left->type->kind === Type::KIND_NULL
                || $c->right->type->kind === Type::KIND_NULL) {
                return '';
            }
        }
        if ($k === Node::KIND_CONCAT || $k === Node::KIND_CMP || $k === Node::KIND_SPACESHIP) {
            $l = Walk::children($n);
            return self::scalar($l[0]->type) && self::scalar($l[1]->type) ? '' : 'compare/concat of ' . $l[0]->type->toString();
        }
        if ($k === Node::KIND_ECHO) {
            foreach (Walk::children($n) as $e) {
                if (!self::scalar($e->type)) { return 'echo of ' . $e->type->toString(); }
            }
            return '';
        }
        if ($k === Node::KIND_CAST) {
            $c = self::asCast($n);
            $t = $c->target;
            if ($t !== 'int' && $t !== 'float' && $t !== 'bool' && $t !== 'string') { return 'cast ' . $t; }
            return self::scalar($c->operand->type) ? '' : 'cast of ' . $c->operand->type->toString();
        }
        if ($k === Node::KIND_SWITCH) {
            return self::scalar(self::asSwitch($n)->subject->type) ? '' : 'switch subject';
        }
        if ($k === Node::KIND_FOREACH) {
            return self::asForeach($n)->array->type->kind === Type::KIND_ARRAY ? '' : 'foreach over non-array';
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $a = self::asArrayAccess($n);
            if ($a->shapeCheck !== 0) { return 'shape check'; }
            return $a->array->type->kind === Type::KIND_ARRAY && self::key($a->index->type) ? '' : 'offset read';
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            $s = self::asStoreElement($n);
            return $s->array->type->kind === Type::KIND_ARRAY && self::key($s->index->type) ? '' : 'offset write';
        }
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            $p = self::asPropertyAccess($n);
            if ($p->byRefTypeErrorHead !== '') { return 'by-ref type error'; }
            $cd = $this->declaringClass($p->object, $p->property);
            if ($cd === null) { return 'property read'; }
            return ($cd->propHooks[$p->property]['get'] ?? '') === '' ? '' : 'get hook';
        }
        if ($k === Node::KIND_STORE_PROPERTY) {
            $s = self::asStoreProperty($n);
            $cd = $this->declaringClass($s->object, $s->property);
            if ($cd === null) { return 'property write'; }
            if (!$s->bypassHook && ($cd->propHooks[$s->property]['set'] ?? '') !== '') { return 'set hook'; }
            $c = $cd->name;
            $guard = 0;
            while ($c !== '' && isset($this->classes[$c]) && $guard < 256) {
                // {@see Passes\EmitLlvmObjects::emitStoreProperty}: a store outside
                // the declaring class's own methods throws.
                if (($this->classes[$c]->propertyReadonly[$s->property] ?? false)
                    && !\str_starts_with($this->wFn, $c . '__') && !\str_starts_with($this->wFn, '__mc_unser_set')) {
                    return 'readonly store';
                }
                $c = $this->classes[$c]->parent;
                $guard = $guard + 1;
            }
            return '';
        }
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_INVOKE) {
            return $this->noteCall($n);
        }
        return 'node ' . $k;
    }

    /** The class a property access on `$obj` reads a DECLARED slot of, or null. */
    private function declaringClass(Node $obj, string $prop): ?ClassDef
    {
        $t = $obj->type;
        $cls = $t->class ?? '';
        if ($t->kind !== Type::KIND_OBJ || $cls === '' || !isset($this->classes[$cls])) { return null; }
        $cd = $this->classes[$cls];
        return $cd->propertyOffset($prop) === -1 ? null : $cd;
    }

    /** Fold one call's callee in: its judged targets into `wCallees`; the reason it may raise regardless, or ''. */
    private function noteCall(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_INVOKE) { return 'invoke'; }
        $args = self::callArgs($n);
        foreach ($args as $a) {
            if ($a->kind === Node::KIND_SPREAD) { return 'spread'; }
        }
        if ($k === Node::KIND_CALL) {
            $fname = self::asCall($n)->function;
            if (self::helperThrows($fname)) { return 'call ' . $fname; }
            $global = !\str_contains($fname, \chr(92));
            if ($global && $this->builtinNothrow($fname, $args)) { return ''; }
            if ($fname === 'fn_to_ptr') { $this->sawFnToPtr = true; return ''; }
            if (isset($this->ffi[$fname])) { $this->wCallees[$fname] = true; return ''; }
        } elseif ($k === Node::KIND_NEW_OBJ) {
            $no = self::asNewObj($n);
            if ($no->bare) { return ''; }
            $cd = $this->classes[$no->class] ?? null;
            if ($cd !== null && !$cd->isAbstract
                && EscapeSummaries::resolveMethodIn($this->classes, $no->class, '__construct') === '') {
                return '';
            }
        } elseif ($k === Node::KIND_STATIC_CALL) {
            $sc = self::asStaticCall($n);
            if (isset($this->enums[$sc->class]) && ($sc->method === 'tryFrom' || $sc->method === 'cases')) { return ''; }
        }
        $targets = $this->resolve->callTargets($n);
        if ($targets !== []) {
            foreach ($targets as $t) { $this->wCallees[$t] = true; }
            return '';
        }
        if ($k === Node::KIND_CALL) {
            $fname = self::asCall($n)->function;
            // A compiler runtime helper with no PHP body anywhere is a codegen
            // builtin ({@see Passes\EmitLlvmBuiltins}): nothrow unless listed.
            if (\str_starts_with($fname, '__') && !isset($this->defined[$fname])) { return ''; }
            return 'call ' . $fname;
        }
        return 'call ' . $k;
    }

    /**
     * The codegen builtins and runtime helpers that raise: `__mir_throw_error`
     * (an `Error` at use), reflection's invoke / construct / property-set /
     * attribute paths (they run user code), the fiber switch (the resumed side
     * may deliver a throw), output (an `ob_start` callback runs user code),
     * `require` of a value and the offload pool start.
     */
    private static function helperThrows(string $fn): bool
    {
        if (\str_starts_with($fn, '__mc_refl_')) { return true; }
        return $fn === '__mir_throw_error' || $fn === '__mir_fiber_jump' || $fn === '__mir_out_write_str'
            || $fn === '__mc_require_value' || $fn === '__mc_pool_start' || $fn === '__mc_weak_arm';
    }

    /**
     * A php builtin that raises nothing — no ValueError, no TypeError, no
     * warning-turned-exception, no user code — for operands that hold no
     * object. A NAME list on purpose: the contract is php's. Builtins with an
     * argument-dependent throw (`strpos`' offset, `str_repeat`'s count,
     * `explode`'s separator) are admitted only for the safe constant.
     *
     * @param Node[] $args
     */
    private function builtinNothrow(string $fn, array $args): bool
    {
        if ($this->nothrowBuiltins === []) {
            foreach ([
                'strlen', 'ord', 'chr', 'abs', 'floor', 'ceil', 'round', 'sqrt', 'is_string', 'is_int',
                'is_integer', 'is_long', 'is_float', 'is_double', 'is_bool', 'is_array', 'is_null',
                'is_numeric', 'is_scalar', 'is_iterable', 'intval', 'floatval', 'boolval', 'strtolower',
                'strtoupper', 'ucfirst', 'lcfirst', 'ucwords', 'trim', 'ltrim', 'rtrim', 'strrev',
                'str_contains', 'str_starts_with', 'str_ends_with', 'strcmp', 'strcasecmp', 'strnatcmp',
                'array_key_exists', 'array_key_first', 'array_key_last', 'array_values', 'array_push',
                'array_pop', 'array_shift', 'array_unshift', 'array_merge', 'array_reverse', 'array_flip',
                'array_is_list', 'array_slice', 'md5', 'sha1', 'crc32', 'bin2hex', 'dechex', 'decbin',
                'decoct', 'gettype', 'get_debug_type', 'microtime', 'hrtime', 'time', 'substr', 'strtr',
                'addslashes', 'stripslashes', 'base64_encode', 'urlencode', 'rawurlencode', 'preg_quote',
                'basename', 'ctype_digit', 'ctype_alpha', 'ctype_alnum', 'ctype_space', 'ctype_upper',
                'ctype_lower', 'ctype_xdigit', 'ctype_punct', 'htmlspecialchars', 'strspn', 'strcspn',
                'is_nan', 'is_infinite', 'is_finite', '__str_byte_at',
            ] as $nm) {
                $this->nothrowBuiltins[$nm] = true;
            }
        }
        // A type predicate reads the tag of anything, object included; an FFI
        // memory primitive (a codegen builtin) is a raw load / store / cast
        // of an `\Ffi\Ptr`.
        foreach (['is_string', 'is_int', 'is_integer', 'is_long', 'is_float', 'is_double', 'is_bool',
            'is_array', 'is_null', 'is_numeric', 'is_scalar', 'is_iterable', 'is_object', 'is_callable',
            'gettype', 'get_debug_type', 'int_to_ptr', 'ptr_to_int', 'ptr_offset', 'peek_f64', 'peek_i16',
            'peek_i32', 'peek_i64', 'peek_i8', 'peek_u16', 'peek_u32', 'peek_u8', 'poke_f64', 'poke_i16',
            'poke_i32', 'poke_i64', 'poke_i8'] as $pred) {
            if ($pred === $fn) { return true; }
        }
        if ($fn === 'spl_object_id' || $fn === 'spl_object_hash' || $fn === 'get_class') {
            return \count($args) === 1 && $args[0]->type->kind === Type::KIND_OBJ;
        }
        if ($fn === 'count' || $fn === 'sizeof') {
            return \count($args) >= 1 && $args[0]->type->kind === Type::KIND_ARRAY
                && (\count($args) === 1 || $args[1]->kind === Node::KIND_INT_CONST);
        }
        if ($fn === 'strpos' || $fn === 'stripos' || $fn === 'strrpos') {
            if (\count($args) > 2 && !($args[2]->kind === Node::KIND_INT_CONST && self::asIntConst($args[2])->value === 0)) {
                return false;
            }
        } elseif ($fn === 'str_repeat') {
            if (\count($args) !== 2 || $args[1]->kind !== Node::KIND_INT_CONST || self::asIntConst($args[1])->value < 0) {
                return false;
            }
        } elseif ($fn === 'explode') {
            if (\count($args) < 2 || $args[0]->kind !== Node::KIND_STRING_CONST || self::asStringConst($args[0])->value === '') {
                return false;
            }
            if (\count($args) > 2 && $args[2]->kind !== Node::KIND_INT_CONST) { return false; }
        } elseif (!isset($this->nothrowBuiltins[$fn])) {
            return false;
        }
        foreach ($args as $a) {
            if (!self::scalar($a->type) && $a->type->kind !== Type::KIND_ARRAY) { return false; }
        }
        return true;
    }

    /** int / float / bool / null: arithmetic on it cannot raise. */
    private static function numeric(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT || $k === Type::KIND_BOOL || $k === Type::KIND_NULL;
    }

    /** A scalar or a string: conversions and comparisons of it run no user code. */
    private static function scalar(Type $t): bool
    {
        return self::numeric($t) || $t->kind === Type::KIND_STRING;
    }

    private static function key(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_STRING || $k === Type::KIND_NULL || $k === Type::KIND_BOOL;
    }

    /** @return Node[] */
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

    private static function asCall(Node $n): Call { return $n; }
    private static function asCmp(Node $n): Cmp { return $n; }
    private static function asMethodCall(Node $n): MethodCall_ { return $n; }
    private static function asStaticCall(Node $n): StaticCall_ { return $n; }
    private static function asNewObj(Node $n): NewObj { return $n; }
    private static function asInvoke(Node $n): Invoke_ { return $n; }
    private static function asIntConst(Node $n): IntConst { return $n; }
    private static function asFloatConst(Node $n): FloatConst { return $n; }
    private static function asStringConst(Node $n): StringConst { return $n; }
    private static function asBitOp(Node $n): BitOp { return $n; }
    private static function asCast(Node $n): Cast { return $n; }
    private static function asSwitch(Node $n): Switch_ { return $n; }
    private static function asForeach(Node $n): Foreach_ { return $n; }
    private static function asArrayAccess(Node $n): ArrayAccess_ { return $n; }
    private static function asStoreElement(Node $n): StoreElement { return $n; }
    private static function asPropertyAccess(Node $n): PropertyAccess_ { return $n; }
    private static function asStoreProperty(Node $n): StoreProperty { return $n; }
    private static function asMemoryOp(Node $n): MemoryOp_ { return $n; }
    private static function asStoreLocal(Node $n): StoreLocal { return $n; }
    private static function asReturn(Node $n): Return_ { return $n; }
}
