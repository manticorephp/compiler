<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\Block;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\BoolConst;
use Compile\Mir\MethodCall_;
use Compile\Mir\NewObj;
use Compile\Mir\Clone_;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StaticCall_;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RuntimeFeatures;
use Compile\Mir\StringPool;
use Compile\Mir\SsaBuilder;
use Compile\Mir\GeneratorContext;
use Compile\Mir\ControlFlow;
use Compile\Mir\FunctionEmitFrame;
use Compile\Mir\FunctionSignatures;
use Compile\Mir\ArenaContext;
use Compile\Mir\LocalSlots;
use Compile\Mir\RuntimeLibrary;
use Compile\Mir\EmitVisitor;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Yield_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\RefCell_;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\While_;
use Compile\Runtime\BareHost;
use Compile\Runtime\UnifiedArrayRuntime;
use Codegen\Llvm\Module as LlvmModule;

/**
 * Locals: their alloca slots, loads and stores, by-ref aliasing, and the
 * static / global-backed indirections. Where a local LIVES is
 * {@see \Compile\Mir\LocalSlots}; this is how it is read and written.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmLocals
{
    /**
     * One local's frame slot. In a function that contains a `try` the slot is
     * PINNED to memory: an empty `asm sideeffect` taking the pointer is a user
     * that is not a load or a store, which is exactly what mem2reg/SROA refuse
     * to promote past — and it assembles to nothing. Without it `-O2` keeps the
     * local in a callee-saved register and `_longjmp` restores that register to
     * its value at the `setjmp`, so the catch path reads whatever the local held
     * BEFORE the try ({@see \Compile\Mir\LocalSlots::$sjljPinAll}).
     */
    private function localSlotAlloca(string $slot): string
    {
        $out = '  ' . $slot . " = alloca i64\n";
        if ($this->locals->sjljPinAll) {
            $out .= '  call void asm sideeffect "", "r"(ptr ' . $slot . ")\n";
        }
        return $out;
    }

    private function preallocateLocals(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) {
            $out = '';
            if (!isset($this->locals->globalBacked[$n->name]) && !isset($this->locals->slots[$n->name])) {
                $slot = $this->ssa->allocReg();
                $this->locals->slots[$n->name] = $slot;
                $out .= $this->localSlotAlloca($slot);
            }
            return $out . $this->preallocateLocals($n->value);
        }
        if ($k === Node::KIND_REF_CELL) {
            // Taking a reference VIVIFIES its target, exactly as php does:
            // `$r = [&$undef];` leaves `$undef` defined and null rather than
            // undefined. Without the slot the promotion below has nothing to
            // box and byRefAddrOf would answer "not addressable", which degrades
            // to a value copy — silently, which is the failure this epic exists
            // to remove. Zeroed, because a fresh php variable is null and not
            // whatever the frame happened to hold.
            // ⚠ Through {@see \Compile\Mir\Walk::children}, NOT a narrowing
            // helper of our own. A `private static function as…(Node): RefCell_`
            // resolves the field offset correctly in a CLASS (Walk, NodeClone,
            // DeadStore all rely on it) and NOT in a TRAIT — and every EmitLlvm*
            // file is a trait on one host. Read here off a trait-local helper,
            // `refSource` came back as garbage and faulted, natively only: Zend
            // resolves the field by NAME, so the whole class of bug is invisible
            // under the fast loop.
            $kids = \Compile\Mir\Walk::children($n);
            $lv = $kids[0];
            if ($lv->kind === Node::KIND_LOAD_LOCAL
                && !isset($this->locals->globalBacked[$lv->name])
                && !isset($this->locals->slots[$lv->name])) {
                $slot = $this->ssa->allocReg();
                $this->locals->slots[$lv->name] = $slot;
                return $this->localSlotAlloca($slot)
                     . '  store i64 ' . (string)\Compile\MemoryAbi::CELL_NULL . ', ptr ' . $slot . "\n";
            }
            return $this->preallocateLocals($lv);
        }
        if ($k === Node::KIND_BLOCK) {
            $out = '';
            foreach ($n->stmts as $s) { $out .= $this->preallocateLocals($s); }
            return $out;
        }
        if ($k === Node::KIND_THROW) {
            return $this->preallocateLocals($n->value);
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $n;
            $out = '';
            foreach ($tc->tryBody as $s) { $out .= $this->preallocateLocals($s); }
            foreach ($tc->catches as $c) {
                $cVar = $this->catchVar($c);
                if ($cVar !== null && !isset($this->locals->slots[$cVar])) {
                    $slot = $this->ssa->allocReg();
                    $this->locals->slots[$cVar] = $slot;
                    $out .= $this->localSlotAlloca($slot);
                }
                foreach ($this->catchBody($c) as $s) { $out .= $this->preallocateLocals($s); }
            }
            foreach ($tc->finallyBody as $s) { $out .= $this->preallocateLocals($s); }
            return $out;
        }
        if ($k === Node::KIND_IF) {
            $out = $this->preallocateLocals($n->cond);
            $out .= $this->preallocateLocals($n->then);
            if ($n->else !== null) { $out .= $this->preallocateLocals($n->else); }
            return $out;
        }
        if ($k === Node::KIND_WHILE) {
            return $this->preallocateLocals($n->cond) . $this->preallocateLocals($n->body);
        }
        if ($k === Node::KIND_FOR) {
            $out = '';
            if ($n->init !== null) { $out .= $this->preallocateLocals($n->init); }
            if ($n->cond !== null) { $out .= $this->preallocateLocals($n->cond); }
            if ($n->step !== null) { $out .= $this->preallocateLocals($n->step); }
            return $out . $this->preallocateLocals($n->body);
        }
        if ($k === Node::KIND_DOWHILE) {
            return $this->preallocateLocals($n->body) . $this->preallocateLocals($n->cond);
        }
        if ($k === Node::KIND_FOREACH) {
            $out = $this->preallocateLocals($n->array);
            // Hoist the value/key slots to entry so a foreach nested in a
            // branch doesn't leave its slot alloca dominating only that
            // branch (two sibling foreaches reusing `$val` then break LLVM).
            if (!isset($this->locals->slots[$n->valueVar])) {
                $vs = $this->ssa->allocReg();
                $this->locals->slots[$n->valueVar] = $vs;
                $out .= $this->localSlotAlloca($vs);
            }
            if ($n->keyVar !== null && !isset($this->locals->slots[$n->keyVar])) {
                $ks = $this->ssa->allocReg();
                $this->locals->slots[$n->keyVar] = $ks;
                $out .= $this->localSlotAlloca($ks);
            }
            // The OBJECT path also holds the iterator in a synthetic local, and
            // that slot needs hoisting for the very same reason — more sharply,
            // in fact: inside a generator the resume switch jumps back into the
            // loop from `entry`, bypassing whatever branch the foreach sits in,
            // so an alloca left there dominates none of the loop's own blocks.
            // Named HERE so emission and preallocation cannot drift apart.
            // Unconditionally, NOT gated on iterClass: InferTypes and the
            // emitter reach the object path through two different predicates
            // and can disagree, so a foreach can take it with iterClass still
            // ''. An unused 8-byte slot costs nothing — LLVM drops it — while a
            // missed hoist is an invalid-IR build failure.
            if ($n->iterName === '') {
                $n->iterName = '@it.' . (string)$this->iterCounter;
                $this->iterCounter = $this->iterCounter + 1;
                $is = $this->ssa->allocReg();
                $this->locals->slots[$n->iterName] = $is;
                $out .= $this->localSlotAlloca($is);
            }
            return $out . $this->preallocateLocals($n->body);
        }
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL
            || $k === Node::KIND_MOD || $k === Node::KIND_CMP
            || $k === Node::KIND_SPACESHIP) {
            return $this->preallocateLocals($this->binLeft($n))
                 . $this->preallocateLocals($this->binRight($n));
        }
        if ($k === Node::KIND_NEG) { return $this->preallocateLocals($n->operand); }
        if ($k === Node::KIND_NOT) { return $this->preallocateLocals($n->operand); }
        if ($k === Node::KIND_BITOP) {
            return $this->preallocateLocals($n->left) . $this->preallocateLocals($n->right);
        }
        if ($k === Node::KIND_BITNOT) { return $this->preallocateLocals($n->operand); }
        if ($k === Node::KIND_CONCAT) {
            return $this->preallocateLocals($n->left) . $this->preallocateLocals($n->right);
        }
        if ($k === Node::KIND_CAST) {
            return $this->preallocateLocals($n->operand);
        }
        if ($k === Node::KIND_NULLCOALESCE) {
            return $this->preallocateLocals($n->left) . $this->preallocateLocals($n->right);
        }
        if ($k === Node::KIND_INVOKE) {
            $out = $this->preallocateLocals($n->callee);
            foreach ($n->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        if ($k === Node::KIND_TERNARY) {
            $out = $this->preallocateLocals($n->cond);
            if ($n->then !== null) { $out .= $this->preallocateLocals($n->then); }
            return $out . $this->preallocateLocals($n->else_);
        }
        if ($k === Node::KIND_SWITCH) {
            $out = $this->preallocateLocals($n->subject);
            foreach ($n->arms as $arm) {
                if ($arm->value !== null) { $out .= $this->preallocateLocals($arm->value); }
                foreach ($arm->body as $s) { $out .= $this->preallocateLocals($s); }
            }
            return $out;
        }
        if ($k === Node::KIND_MATCH) {
            $out = $this->preallocateLocals($n->subject);
            foreach ($n->arms as $arm) {
                $conds = $arm->conds;
                if ($conds !== null) {
                    foreach ($conds as $c) { $out .= $this->preallocateLocals($c); }
                }
                $out .= $this->preallocateLocals($arm->body);
            }
            return $out;
        }
        if ($k === Node::KIND_ECHO) {
            $out = '';
            foreach ($n->exprs as $e) { $out .= $this->preallocateLocals($e); }
            return $out;
        }
        if ($k === Node::KIND_RETURN) {
            $v = $n->value;
            return $v === null ? '' : $this->preallocateLocals($v);
        }
        if ($k === Node::KIND_CALL) {
            $out = '';
            foreach ($n->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        if ($k === Node::KIND_ARRAY_LIT) {
            $out = '';
            foreach ($n->elements as $el) {
                if ($el->key !== null) { $out .= $this->preallocateLocals($el->key); }
                $out .= $this->preallocateLocals($el->value);
            }
            return $out;
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            return $this->preallocateLocals($n->array) . $this->preallocateLocals($n->index);
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            return $this->preallocateLocals($n->array)
                 . $this->preallocateLocals($n->index)
                 . $this->preallocateLocals($n->value);
        }
        if ($k === Node::KIND_NEW_OBJ) {
            $out = '';
            foreach ($n->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        if ($k === Node::KIND_CLONE) {
            $out = $this->preallocateLocals($n->object);
            foreach ($n->withProps as $pair) { $out .= $this->preallocateLocals($pair->value); }
            return $out;
        }
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            return $this->preallocateLocals($n->object);
        }
        if ($k === Node::KIND_STORE_PROPERTY) {
            return $this->preallocateLocals($n->object) . $this->preallocateLocals($n->value);
        }
        if ($k === Node::KIND_METHOD_CALL) {
            $out = $this->preallocateLocals($n->object);
            foreach ($n->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        // A static call's args can hold an assignment (`Helper::x($f = $o->m())`) —
        // recurse so a local FIRST bound inside a static-call arg gets its entry
        // slot (without this its StoreLocal emits `store …, ptr ` with no slot).
        if ($k === Node::KIND_STATIC_CALL) {
            $out = '';
            foreach ($n->args as $a) { $out .= $this->preallocateLocals($a); }
            return $out;
        }
        // Every kind NOT special-cased above recurses over its children
        // generically, so the walk is exhaustive by construction. It was a
        // hand-written list of kinds, and a kind missing from it is not a missed
        // optimisation — it is INVALID IR: the local's StoreLocal emits
        // `store …, ptr ` with an empty operand and clang rejects the module
        // with `expected instruction opcode`, pointing at the NEXT line. The
        // static-call arm right above was one such patch; `isset($v[$param =
        // trim($t[0])])` in symfony/polyfill-intl-messageformatter was the next,
        // and enumerating kinds one bug at a time has no end.
        //
        // Safe against the one case that would be wrong: Walk::children of a
        // CLOSURE yields its CAPTURES, never its body, so a StoreLocal belonging
        // to a nested frame can never take a slot in this one.
        $out = '';
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $out .= $this->preallocateLocals($c);
        }
        return $out;
    }

    /**
     * Is this global-backed slot the `$GLOBALS['x']` VIEW of a top-level variable?
     *
     * Three different things live in `globalBacked`: a superglobal, a STATIC
     * local, and a top-level variable that `$GLOBALS` also names. Only the last
     * one has a second reader that decodes the slot as a cell, so only it may be
     * boxed — boxing a static local instead broke `static $stdout` holding a
     * resource, which is neither a superglobal nor a $GLOBALS name.
     */
    /**
     * Whether a value of type `$t` is boxed into a `$GLOBALS`-viewed slot:
     * every scalar/string/cell ({@see isCellBoxableArg}) plus arrays and
     * tag-trustworthy objects, which {@see boxForViewSlot} boxes flat.
     */
    private function viewSlotBoxes(Type $t): bool
    {
        if ($this->isCellBoxableArg($t)) { return true; }
        // An ERASED word takes the runtime probe (`boxUnknownShallowIr`): a
        // guess, but a raw word under a cell claim is a wrong answer every time.
        if ($t->kind === Type::KIND_UNKNOWN) { return true; }
        if ($t->isArray()) { return true; }
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        $cls = $t->class ?? '';
        if ($cls === '') { return false; }
        if ($this->isClosureClass($cls) || $this->isEnumClass($cls)) { return false; }
        if (isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return false; }
        return true;
    }

    /** Box lastValue for a `$GLOBALS`-viewed slot: arrays and objects FLAT by
     *  pointer (the buffer keeps its own element hint), everything else as
     *  {@see boxToCell} does. */
    private function boxForViewSlot(Type $t, Node $src): string
    {
        if ($t->isArray()) {
            $this->rt->needsTagged = true;
            $out = $this->coerceToPtr();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_array(ptr ' . $this->lastValue . ")\n";
            $this->markCellBoxed($r);
            return $this->finishI64($out, $r);
        }
        if ($t->kind === Type::KIND_OBJ) {
            $this->rt->needsTagged = true;
            $out = $this->coerceToPtr();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_object(ptr ' . $this->lastValue . ")\n";
            $this->markCellBoxed($r);
            return $this->finishI64($out, $r);
        }
        return $this->boxToCell($t, $src);
    }

    private function isGlobalsViewName(string $name): bool
    {
        if ($this->isSuperglobalName($name)) { return false; }
        // The names REACHED through `$GLOBALS['x']`, not every `global $x`.
        // A boxed slot has two readers by construction, and that is exactly
        // what a by-ref argument cannot hand over — it passes the slot ADDRESS
        // and the callee writes a raw word into it. Boxing every `global $x`
        // made `global $g; fill($g)` un-addressable, so it rode the by-VALUE
        // path and the callee dereferenced the array pointer as an address.
        foreach ($this->globalsViewNames as $g) {
            if ($g === $name) { return true; }
        }
        return false;
    }

    private function emitLoadLocal(LoadLocal $n): string
    {
        $ll = $n;
        if (isset($this->locals->globalBacked[$ll->name])) {
            $reg = $this->ssa->allocReg();
            $out = '  ' . $reg . ' = load i64, ptr ' . $this->locals->globalBacked[$ll->name] . "\n";
            // The mirror of the box in emitStoreLocal: the slot is the
            // `$GLOBALS['x']` view's cell, so decode it by THIS local's type.
            if ($this->isGlobalsViewName($ll->name) && $this->viewSlotBoxes($ll->type)) {
                $this->lastValue = $reg;
                $this->lastValueType = 'i64';
                $out .= $this->unboxCellToType($ll->type);
                return $out;
            }
            if ($ll->type->kind === Type::KIND_FLOAT) {
                $regF = $this->ssa->allocReg();
                $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
                $this->lastValue = $regF;
                $this->lastValueType = 'double';
            } else {
                $this->lastValue = $reg;
                $this->lastValueType = 'i64';
                // A global-backed slot (static local, `global $x`) typed cell is
                // a slot read like any other: its stores are the checked sinks.
                if ($ll->type->kind === Type::KIND_CELL) { $this->markCellOpaque($reg); }
            }
            return $out;
        }
        if (!isset($this->locals->slots[$ll->name])) {
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return '';
        }
        $reg = $this->ssa->allocReg();
        if (isset($this->locals->refLocals[$ll->name])) {
            // By-ref: slot holds the address; deref to the value.
            $addr = $this->ssa->allocReg();
            $out = '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$ll->name] . "\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $out .= '  ' . $reg . ' = load i64, ptr ' . $p . "\n";
        } else {
            $out = '  ' . $reg . ' = load i64, ptr ' . $this->locals->slots[$ll->name] . "\n";
        }
        // Slots are uniform i64. Bitcast back to double when the
        // inferred type for this local says it carries a float —
        // gives downstream `fadd` / `fdiv` a usable operand.
        if ($ll->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        } else {
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            // An obj-typed load whose value flow-narrowed from a cell/`mixed`
            // (param, foreach value, or a cell-returning call's result) still
            // carries the NaN tag in its slot — strip it so `->prop` / dispatch
            // gets a clean ptr. The mask is IDENTITY on a real heap pointer
            // (< 2^48), so it's a safe no-op for a genuine object local too.
            if ($ll->type->kind === Type::KIND_OBJ) {
                $masked = $this->ssa->allocReg();
                $out .= '  ' . $masked . ' = and i64 ' . $reg . ", 281474976710655\n";
                $this->lastValue = $masked;
            }
        }
        if ($ll->type->kind === Type::KIND_CELL) {
            $this->markCellOpaque($this->lastValue);
            if ($this->cellAssert) {
                $out .= $this->emitCellAssert($this->lastValue, $ll->type->kind, '$' . $ll->name, $ll->type->toString());
            }
        }
        return $out;
    }


    /**
     * Retain what an ELEMENT READ hands a local, so the local's own release
     * has something to give back. Paired with the ownership verdict in
     * {@see \Compile\Mir\Passes\InsertMemoryOps::isOwnedObj} — both halves
     * ride {@see \Compile\Debug::$rcElemReadOwns}, and shipping one alone is
     * a leak or a double free.
     */
    private function elemReadCoOwn(Node $v, ?Type $slotType = null): string
    {
        if (!\Compile\Debug::$rcElemReadOwns) { return ''; }
        if ($v->kind !== Node::KIND_ARRAY_ACCESS) { return ''; }
        // The SAME predicate the pass half decides on — one condition, two halves.
        if (!InsertMemoryOps::elemReadCoOwns($v->type, $this->enums, $this->classes)) { return ''; }
        $sv = $this->lastValue;
        $st = $this->lastValueType;
        $out = $this->coerceToI64();
        // ⚠ DEPTH FOLLOWS THE DESTINATION, never the value — the rule
        // {@see EmitLlvmMemory::rcRetainByType} states for the property case.
        // The release this pairs with reads the SLOT's type, so a retain taken
        // at the value's depth frees element refs it never took.
        $out .= $this->rcRetainByType($v, $this->lastValue, $slotType);
        $this->lastValue = $sv;
        $this->lastValueType = $st;
        return $out;
    }

    private function emitStoreLocal(StoreLocal $n): string
    {
        $sl = $n;
        // Amortized `.=`: `$s = $s . rhs` on a plain (non-ref, non-global,
        // non-arena) heap string local → in-place append instead of a fresh
        // O(n²) concat. The helper owns the old value's lifetime, so this
        // path deliberately skips the standard release-before-overwrite.
        $sv = $sl->value;
        // NB: no ARENA gate here — a `$s = $s . …` accumulator ESCAPES across a
        // loop back-edge, so even if InferAllocKind confined the concat, it must
        // become a heap str_append: str_append converts the (immortal-rc) arena
        // buffer to a heap copy on the first append, then mutates in place. That
        // also lets the per-iteration arena reset free the small operand temps,
        // so an arena-confined self-concat no longer grows the arena unbounded.
        if ($sv->kind === Node::KIND_CONCAT
            && $sv->type->kind === Type::KIND_STRING
            && !isset($this->locals->refLocals[$sl->name])
            && !isset($this->locals->globalBacked[$sl->name])
            && isset($this->locals->slots[$sl->name])) {
            // Flatten `$s = $s . a . b . …` (left-nested, so the outer concat's
            // left is a nested concat, NOT `$s`) to its leaves; if the first leaf
            // is `$s`, rebuild the suffix `a.b.…` as ONE right-hand concat and
            // reuse emitSelfAppend (str_append of a prebuilt rhs). Without this a
            // multi-way self-concat missed the append fast path AND leaked: the
            // general StoreLocal release-before-overwrite drops only owned obj/vec
            // locals, never a string, so the old accumulator was never freed
            // (O(n²) memory + time — json/sprintf builders).
            $ops = [];
            $this->flattenConcat($sv, $ops);
            $ops = $this->mergeAdjacentStrConsts($ops);
            if (\count($ops) >= 2) {
                $op0 = $ops[0];
                if ($op0->kind === Node::KIND_LOAD_LOCAL
                    && $op0->type->kind === Type::KIND_STRING
                    && $op0->name === $sl->name) {
                    $rest = $ops[1];
                    $k = \count($ops);
                    for ($j = 2; $j < $k; $j = $j + 1) {
                        $rest = new \Compile\Mir\Concat($rest, $ops[$j]);
                    }
                    return $this->emitSelfAppend($sl, new \Compile\Mir\Concat($op0, $rest));
                }
            }
        }
        // Flow-sensitive cell-merge box-back (`$x = box($x)` planted by
        // InferTypes::planMergeShadow at an if/else merge): a store NODE typed
        // cell whose VALUE is concrete. That combo is otherwise impossible
        // (inferStoreLocal always types a store = its value type), so it is a
        // precise signal — box the concrete value into the slot, making it a
        // self-describing cell past the merge. No effect on any genuine cell
        // store (those have a cell value → fall through to the raw path).
        // A GLOBAL-BACKED slot takes it too: a `static $x;` seeded cell
        // ({@see InferScans::scanStaticLocalTypes}) is pinned exactly like a
        // ref-taken local, so its stores arrive as this combo and must box
        // into the module cell — under the module cell's OWN contract
        // ({@see globalCellOwnIr}): the payload is co-owned before the box
        // (a borrowed string / object / cell-array; a fresh producer's +1
        // transfers, a scalar boxes by value) and the predecessor is released
        // at the decl's `cell` flavor. Without the pair the cell held a
        // BORROW past its owner's frame: `static $c; $c = $h->name;` read
        // garbage on the next call once `$h` died.
        $cellDest = $this->locals->globalBacked[$sl->name] ?? $this->locals->slots[$sl->name] ?? '';
        if ($sl->type->kind === Type::KIND_CELL
            && $sl->value->type->kind !== Type::KIND_CELL
            && !isset($this->locals->refLocals[$sl->name])
            && $cellDest !== '') {
            $out = $this->emitNode($sl->value);
            $coOwn = $this->elemReadCoOwn($sl->value, $sl->type);
            $out .= $coOwn;
            $ownsCell = isset($this->locals->globalBacked[$sl->name])
                && !$this->isGlobalsViewName($sl->name);
            if ($ownsCell && $coOwn === '') {
                $out .= $this->retainCellPayload($sl->value);
            }
            $out .= $this->boxToCell($sl->value->type, $sl->value);
            $boxed = $this->lastValue;
            if ($ownsCell) {
                $out .= $this->globalCellOwnIr($sl, $boxed, true);
            }
            $out .= '  store i64 ' . $boxed . ', ptr ' . $cellDest . "\n";
            $this->lastValue = $boxed;
            $this->lastValueType = 'i64';
            return $out;
        }
        // The MIRROR of the box-back above: a store NODE typed a concrete SCALAR
        // whose VALUE is a cell. Same impossible-by-default combo, so the same
        // precise signal — planted by InferNodes::inferStoreLocal for a slot a
        // by-ref callee owns the representation of (`int &$pos`). Unbox into the
        // slot, or the callee writes a raw word where the reader expects a tag.
        if ($this->isCellScalarParam($sl->type)
            && $sl->value->type->kind === Type::KIND_CELL
            && !isset($this->locals->refLocals[$sl->name])
            && !isset($this->locals->globalBacked[$sl->name])
            && isset($this->locals->slots[$sl->name])) {
            $out = $this->emitNode($sl->value);
        $out .= $this->elemReadCoOwn($sl->value, $sl->type);
            $out .= $this->unboxCellToType($sl->type);
            // A float unboxes to a `double`; the slot is an i64, so put the bits
            // back the way the float-slot plant below does.
            if ($this->lastValueType === 'double') {
                $bits = $this->ssa->allocReg();
                $out .= '  ' . $bits . ' = bitcast double ' . $this->lastValue . " to i64\n";
                $this->lastValue = $bits;
                $this->lastValueType = 'i64';
            }
            $out .= $this->coerceToI64();
            $raw = $this->lastValue;
            $out .= '  store i64 ' . $raw . ', ptr ' . $this->locals->slots[$sl->name] . "\n";
            $this->lastValue = $raw;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Float-slot local storing an int/bool value (`$s = 0` init before a
        // float accumulator): convert numerically (sitofp), then bit-store into
        // the i64 slot — else the integer bits land in a slot read as a double.
        // The (float store node, int value) combo is planted by InferTypes'
        // float-slot analysis; a genuine float store has a float value and falls
        // through to the raw path.
        if ($sl->type->kind === Type::KIND_FLOAT
            && ($sl->value->type->kind === Type::KIND_INT || $sl->value->type->kind === Type::KIND_BOOL)
            && !isset($this->locals->refLocals[$sl->name])
            && !isset($this->locals->globalBacked[$sl->name])
            && isset($this->locals->slots[$sl->name])) {
            $out = $this->emitNode($sl->value);
        $out .= $this->elemReadCoOwn($sl->value, $sl->type);
            $out .= $this->coerceToI64();
            $d = $this->ssa->allocReg();
            $out .= '  ' . $d . ' = sitofp i64 ' . $this->lastValue . " to double\n";
            $bits = $this->ssa->allocReg();
            $out .= '  ' . $bits . ' = bitcast double ' . $d . " to i64\n";
            $out .= '  store i64 ' . $bits . ', ptr ' . $this->locals->slots[$sl->name] . "\n";
            $this->lastValue = $bits;
            $this->lastValueType = 'i64';
            return $out;
        }
        // De-cellify: a cell-element array value bound to a CONCRETE-element
        // array slot. The store NODE carries the declared concrete array type
        // (planted by InferTypes::inferStoreLocal for a typed array param/@var);
        // rebuild the value with each element UNBOXED to the slot's repr so a
        // later typed read gets a raw value (uasort's `$arr = $new` writeback
        // restoring the byref param's assoc[string,int] representation). Mirrors
        // the box-back / float-slot plants above.
        if ($this->needsDeCellify($sl->type, $sl->value->type)
            && isset($this->locals->slots[$sl->name])) {
            $out = $this->emitNode($sl->value);
        $out .= $this->elemReadCoOwn($sl->value, $sl->type);
            $out .= $this->emitCellArrayToTyped($sl->type);
            $dv = $this->lastValue;
            if (isset($this->locals->globalBacked[$sl->name])) {
                // The rebuild is a fresh +1 the cell takes outright; only the
                // predecessor is owed ({@see globalCellOwnIr}).
                $out .= $this->globalCellOwnIr($sl, $dv, true);
                $out .= '  store i64 ' . $dv . ', ptr ' . $this->locals->globalBacked[$sl->name] . "\n";
            } elseif (isset($this->locals->refLocals[$sl->name])) {
                $addr = $this->ssa->allocReg();
                $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$sl->name] . "\n";
                $p = $this->ssa->allocReg();
                $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
                $out .= $this->ownedBoxOverwriteIr($sl->name, $addr);
                $out .= '  store i64 ' . $dv . ', ptr ' . $p . "\n";
            } else {
                $out .= '  store i64 ' . $dv . ', ptr ' . $this->locals->slots[$sl->name] . "\n";
            }
            $this->lastValue = $dv;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Forward-cellify a concrete OBJECT-element array written through a BY-REF
        // out-param. The element type is ERASED across the `.sig` (a bare `array &`
        // param encodes no element repr — {@see \Manticore\Sig::encodeType}), so
        // the CALLER reads the slot as an unknown-element array and needs
        // self-describing CELL elements: a raw object pointer reads back as a
        // NaN-double (`instanceof` false, `gettype` "double" — socket_create_pair's
        // `$pair`). Box each element, mirroring the RETURN path's needsCellify /
        // {@see emitCellifyArrayRaw}. Scalar by-ref arrays (`sort(array &$a)` of
        // ints) are deliberately untouched — their elements round-trip raw and the
        // caller reads them raw.
        // A by-ref param the USER declared nullable-scalar (`?int &$n`) is a
        // CELL: the caller's slot is a cell and its address crosses unchanged
        // (`byRefNeedsCellUnbox` deliberately does not divert a cell param), so
        // the callee's write has to be self-describing or `var_dump($n)` reads
        // 42 as `float(2.08E-322)`.
        //
        // ⛔ NOT for a CLOSURE. Its parameters are cell-typed by the uniform
        // closure ABI rather than by any declaration, and a by-ref one points
        // straight at an array's ELEMENT slot: boxing there made
        // `array_walk($m, fn (&$v) => $v = $v * 10)` write NaN-boxed words into
        // the array and print -4222124650659830 for 10.
        if (!$this->frame->isClosure
            && isset($this->locals->refLocals[$sl->name])
            && isset($this->locals->slots[$sl->name])
            && ($this->locals->refParamTypes[$sl->name] ?? null) !== null
            && $this->locals->refParamTypes[$sl->name]->kind === Type::KIND_CELL
            && $sl->value->type->kind !== Type::KIND_CELL
            && $this->viewSlotBoxes($sl->value->type)) {
            $out = $this->emitNode($sl->value);
            $out .= $this->elemReadCoOwn($sl->value, $sl->type);
            // An array goes in FLAT and an object by pointer, as into every other
            // cell slot ({@see boxForViewSlot}): the caller's cell reads the
            // elements through the buffer's hint. `$v = [$v]` through `mixed &$v`
            // handed the caller a bare pointer under a cell claim. The slot
            // co-owns a borrowed buffer/object exactly as a static slot does
            // (a fresh temp transfers, a borrow is retained).
            $vk0 = $sl->value->type->kind;
            if ($sl->value->type->isArray() || $vk0 === Type::KIND_OBJ) {
                $out .= $this->coerceToI64();
                $rawV = $this->lastValue;
                $out .= $this->rcRetainByType($sl->value, $rawV, null, 3);
                $this->lastValue = $rawV;
                $this->lastValueType = 'i64';
            }
            $out .= $this->boxForViewSlot($sl->value->type, $sl->value);
            $dv = $this->lastValue;
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$sl->name] . "\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $out .= $this->ownedBoxOverwriteIr($sl->name, $addr);
            $out .= '  store i64 ' . $dv . ', ptr ' . $p . "\n";
            $this->lastValue = $dv;
            $this->lastValueType = 'i64';
            return $out;
        }
        if (isset($this->locals->refLocals[$sl->name])
            && isset($this->locals->slots[$sl->name])
            && ($this->needsRefOutCellify($sl->value->type)
                || $this->refStoreNeedsCellify($sl->name, $sl->value->type))) {
            $out = $this->emitNode($sl->value);
        $out .= $this->elemReadCoOwn($sl->value, $sl->type);
            $out .= $this->emitCellifyArrayRaw($sl->value->type->element);
            $out .= $this->coerceToI64();
            $dv = $this->lastValue;
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$sl->name] . "\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $out .= $this->ownedBoxOverwriteIr($sl->name, $addr);
            $out .= '  store i64 ' . $dv . ', ptr ' . $p . "\n";
            $this->lastValue = $dv;
            $this->lastValueType = 'i64';
            return $out;
        }
        $this->arena->vecAllocated = false;
        $out = $this->emitNode($sl->value);
        $out .= $this->elemReadCoOwn($sl->value, $sl->type);
        // The value just emitted an arena vec → this local owns it, so
        // its `$x[] =` appends must realloc through the arena.
        if ($this->arena->vecAllocated) {
            $this->arena->vecLocals[$sl->name] = true;
        }
        // PHP arrays are values: `$b = $a` (vec OR assoc) needs an independent
        // copy when either side is later mutated, else a store into one would
        // clobber the other's shared buffer. Read-only aliases share safely
        // (`mutatedVecLocals` only records mutated locals). Objects are by-handle
        // (never copied); strings immutable. __mir_array_copy is mode-agnostic.
        $v = $sl->value;
        // The predicate is {@see \Compile\Mir\VecCopyOnAssign} — one place, so
        // this emitter and {@see InsertMemoryOps} cannot disagree about whether
        // a store COPIES. `$copiedVecLocal` still has to be tracked here: the
        // alias arm below is the OTHER road to the same ownership, and it must
        // not fire on a store that already took the copy road.
        $copiedVecLocal = false;
        if (\Compile\Mir\VecCopyOnAssign::copies($v, $sl->name, $this->frame->mutatedVecLocals)) {
            $out .= $this->coerceToPtr();
            $src = $this->lastValue;
            $cp = $this->ssa->allocReg();
            $out .= '  ' . $cp . ' = call ptr @__mir_array_copy(ptr ' . $src . ")\n";
            // ★ The copy duplicates the element WORDS, not the ownership of what
            // they point at — `__mir_array_copy` is a flat buffer copy. Two
            // buffers then held one ref, and whichever released first freed a
            // value the other still names. It stayed dormant only because
            // nothing ever dropped an element off a live buffer; the element
            // SLOT drop ({@see \Compile\Debug::$rcElemSlotDrop}) does, so
            // `$b = $a; $a['x'] = $new;` read FREED memory out of `$b`.
            // The adopt that takes exactly the element refs the copy's own
            // release gives back is inside `__mir_array_copy` itself now, by the
            // buffer's hint.
            $this->lastValue = $cp;
            $this->lastValueType = 'ptr';
            $copiedVecLocal = true;
            // The copy is heap-owned + independent, so it is no longer an
            // arena vec alias.
            unset($this->arena->vecLocals[$sl->name]);
        }
        // `$saved = $this->vecProp` — snapshot of a vec PROPERTY. PHP value
        // semantics: it must be independent, else a later `$this->vecProp[]=…`
        // (emitVecAppend reallocs the property buffer in place) dangles the
        // snapshot — the property-side analogue of the assoc snapshot UAF and
        // the root of the enum_backed heisenbug. A property read can't be
        // proven unmutated here, so copy unconditionally (matches PHP, which
        // copies on every array assignment).
        //
        // A STATIC property (`$copy = B::$xs`) is the same snapshot through a
        // different node — without it the local ALIASED the static's buffer and
        // `$copy[] = v` mutated `B::$xs` too (`1 2` in php, `2 2` here).
        $copiedVecProp = false;
        if (($v->kind === Node::KIND_PROPERTY_ACCESS || $v->kind === Node::KIND_STATIC_PROP)
            && $v->type->isVec()) {
            $out .= $this->coerceToPtr();
            $src = $this->lastValue;
            $cp = $this->ssa->allocReg();
            $out .= '  ' . $cp . ' = call ptr @__mir_array_copy(ptr ' . $src . ")\n";
            $this->lastValue = $cp;
            $this->lastValueType = 'ptr';
            $copiedVecProp = true;
        }
        // `$m = $obj` / `$b = $s` — a second owner of a by-handle object or
        // string. Retain so the source local's scope-exit release can't free
        // it early. (rcRetainByType no-ops an immortal literal.) NOTE: a local
        // assoc alias (`$b = $a`) is deliberately NOT retained here — the
        // assoc COW snapshot case we need is the PROPERTY one below; blanket-
        // retaining every local assoc alias added a spurious assoc_retain in
        // hot ctors (ClassDef) that, on a value whose buffer abuts a live heap
        // string, wrote rc into the string (the enum backing "int"→"jnt").
        // …and the RETAIN half of {@see \Compile\Mir\AliasOwn}: the release
        // half is {@see InsertMemoryOps::isOwnedObj}, and the two must read
        // the SAME predicate or the value is freed twice or never.
        $aliasObjStr = \Compile\Mir\AliasOwn::coOwns($v) || \Compile\Mir\AliasOwn::strPropCoOwns($v);
        // `$b = $a` on an ARRAY the frame never mutates: no copy fires, so the
        // two names share one buffer and — until now — neither owned it. The
        // pass answered that by BLOCKING the source, which leaks everything it
        // held ({@see \Compile\Mir\Passes\InsertMemoryOps::arrayAliasCoOwns},
        // the one predicate both halves ask). Take the +1 here and the source
        // keeps its release.
        $aliasArrayLocal = !$copiedVecLocal
            && $v->kind === Node::KIND_LOAD_LOCAL
            && \Compile\Mir\Passes\InsertMemoryOps::arrayAliasCoOwns(
                $v->type, $sl->type, $this->enums, $this->classes);
        // `$saved = $this->map` — a snapshot of an array PROPERTY. Co-own it
        // (rc>1) so a later mutation of the property copy-on-writes instead of
        // clobbering the snapshot's shared buffer (the InferTypes localTypes
        // snapshot UAF). Obj/string property reads have their own retain
        // discipline elsewhere.
        //
        // VEC as well as assoc: `$tokens = $this->tokens;` then
        // `array_shift($tokens)` is symfony's ArgvInput::getParameterOption, and
        // with rc stuck at 1 the shift's copy-on-write saw a unique buffer, so
        // it drained the PROPERTY and then freed a buffer the property still
        // held. A refcount-based COW is inert unless the alias co-owns.
        // A bare `array` hint erases to KIND_UNKNOWN, so isArray() alone misses
        // exactly the declaration symfony uses (`private array $tokens = []`) —
        // ask the slot, the same way the store path does.
        $aliasArrayProp = $v->kind === Node::KIND_PROPERTY_ACCESS
            && ($v->type->isArray()
                || $this->slotIsArrayHinted($v->object, $v->property, $v->type));
        // The copied STATIC vec snapshot takes the same adopt as the instance
        // one: the copy is a flat buffer copy, so without it the local's
        // release ({@see InsertMemoryOps::isOwnedObj}, which owns exactly this
        // shape) would give back element refs the copy never took.
        $aliasStaticVecCopy = $copiedVecProp && $v->kind === Node::KIND_STATIC_PROP;
        if ($aliasObjStr || $aliasArrayProp || $aliasArrayLocal || $aliasStaticVecCopy) {
            $out .= $this->coerceToI64();
            $aliasV = $this->lastValue;
            // An array-HINTED slot whose type erased to unknown carries no kind
            // for rcRetainByType to dispatch on, so it emitted nothing at all —
            // name the array explicitly for that case.
            $fallback = null;
            if ($aliasArrayProp && !$v->type->isArray()) {
                $fallback = Type::vec(Type::unknown());
            }
            // A COPIED vec property is already this frame's own rc=1 buffer — its
            // KEYS and ELEMENTS are still the source's, but its BUFFER is not. A
            // full retain there left it at rc 2 against one release, so the copy
            // was never freed: `$stmts = $n->stmts;` — a read to look at the last
            // statement — leaked the whole copied buffer on EVERY call, 16360
            // blocks from `InferTypes::blockDiverges` alone in one front-end run.
            // Adopt takes the element refs the release will give back, and
            // nothing else.
            // A CELL alias retains by TAG: rcRetainByType has no cell arm (it
            // answers '' for a cell with no fallback), and the payload may be an
            // array, a string, an object, or nothing rc'd at all — which is what
            // __mir_cell_retain dispatches on, mirroring the __mir_cell_drop the
            // release half schedules for this slot.
            if ($v->type->kind === Type::KIND_CELL && !$copiedVecProp) {
                // Not a bare retain: an ARRAY payload is COPIED, because the
                // source may be a borrowed `mixed` parameter and a later COW
                // through it would steal a count the caller still relies on
                // ({@see UnifiedArrayRuntime::emitCellOwnAlias}). The slot
                // stores what comes back — a fresh boxed clone, or the same
                // word now co-owned.
                $owned = $this->ssa->allocReg();
                $out .= '  ' . $owned . ' = call i64 @__mir_cell_own_alias(i64 ' . $aliasV . ")\n";
                // A pass-through for the guard: the helper returns the same
                // cell or a boxed clone of its payload, never a raw word.
                $this->propagateCellProvenance($aliasV, $owned);
                $aliasV = $owned;
            } else {
                // A copied vec property adopted inside `__mir_array_copy`;
                // anything else is a second holder.
                if (!$copiedVecProp) {
                    $out .= $this->rcRetainByType($v, $aliasV, $fallback, 0);
                }
            }
            $this->lastValue = $aliasV;
            $this->lastValueType = 'i64';
        }
        // A global cell is ALSO the `$GLOBALS['x']` view, which reads it as a
        // self-describing cell — so this store has to box, exactly as the
        // static-prop store does for a `mixed` slot. One slot, one
        // representation: with the two views disagreeing, `$counter = 7` written
        // here and read through `$GLOBALS['counter']` (or the reverse) answered
        // the double with those bits. An ARRAY is boxed FLAT (`box_array`, the
        // same buffer under a tag — never the cell rebuild): the `global $x`
        // local keeps reading its concrete elements raw off that buffer, and the
        // view reads them through the buffer's hint, so the two agree on one
        // buffer. An object is boxed by pointer for the same reason; closures,
        // enums and structs have no tag a consumer could trust and stay raw.
        if (isset($this->locals->globalBacked[$sl->name])
            && $this->isGlobalsViewName($sl->name)
            && $this->viewSlotBoxes($sl->value->type)) {
            $out .= $this->boxForViewSlot($sl->value->type, $sl->value);
        }
        $val = $this->lastValue;
        // Coerce float values back into the slot's i64 cell with a
        // bitcast. Pointers (strings) ptrtoint similarly so the
        // i64 slot stays the universal carrier.
        if ($this->lastValueType === 'double') {
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = bitcast double ' . $val . " to i64\n";
            $val = $reg;
        } elseif ($this->lastValueType === 'ptr') {
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = ptrtoint ptr ' . $val . " to i64\n";
            $val = $reg;
        }
        if (isset($this->locals->globalBacked[$sl->name])) {
            $elemOwned = \Compile\Debug::$rcElemReadOwns
                && $v->kind === Node::KIND_ARRAY_ACCESS
                && \Compile\Mir\Passes\InsertMemoryOps::elemReadCoOwns($v->type, $this->enums, $this->classes);
            $out .= $this->globalCellOwnIr($sl, $val,
                $copiedVecLocal || $copiedVecProp || $aliasObjStr || $aliasArrayProp
                || $aliasArrayLocal || $elemOwned);
            $out .= '  store i64 ' . $val . ', ptr ' . $this->locals->globalBacked[$sl->name] . "\n";
        } elseif (isset($this->locals->refLocals[$sl->name])) {
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$sl->name] . "\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
            $out .= $this->ownedBoxOverwriteIr($sl->name, $addr);
            $out .= '  store i64 ' . $val . ', ptr ' . $p . "\n";
        } else {
            // Release-before-overwrite: rebinding an owned RcHeap obj/vec
            // local drops its previous value (the slot is null-inited, so
            // the first store releases null = no-op). Frees the per-
            // iteration value in `for (...) { $x = new Foo(); }`.
            if (isset($this->frame->rcObjLocals[$sl->name])
                && !isset($this->frame->transferredLocals[$sl->name])) {
                $out .= $this->rcReleaseSlot($this->locals->slots[$sl->name],
                    $this->rcReleaseFlavor($this->frame->rcObjLocals[$sl->name]));
            }
            $out .= '  store i64 ' . $val . ', ptr ' . $this->locals->slots[$sl->name] . "\n";
        }
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * A reference box OWNS its value, as a module cell does
     * ({@see globalCellOwnIr}): a store through it drops what it held. Only a
     * box this frame made ({@see LocalSlots::$ownedBoxes}) — its flavor is
     * known — and only while the slot points at a box that flavor describes:
     * `$name = &$x` may have rebound the name to other storage. `$addrI64` is
     * the slot's word, the address the store is about to write through. The
     * value being stored already carries its own count, taken by the same
     * conventions as a plain local's store.
     */
    private function ownedBoxOverwriteIr(string $name, string $addrI64): string
    {
        if (!isset($this->locals->ownedBoxes[$name])) { return ''; }
        $flavor = $this->ownedBoxFlavor($name);
        if ($flavor === '') { return ''; }
        $bp = $this->ssa->allocReg();
        $out = '  ' . $bp . ' = inttoptr i64 ' . $addrI64 . " to ptr\n";
        $same = $this->ssa->allocReg();
        if ($flavor === 'cell') {
            // A ref-cell name may have been aliased onto another ref-cell name's
            // box (`$o = &$x`, both boxed — LocalSlots::closeRefCellsOverAliases);
            // every box holds a cell it owns, so any box will do. The magic tells
            // a box from the storage a by-ref param points at.
            $hp = $this->ssa->allocReg();
            $out .= '  ' . $hp . ' = getelementptr inbounds i8, ptr ' . $bp . ", i64 -8\n";
            $hv = $this->ssa->allocReg();
            $out .= '  ' . $hv . ' = load i64, ptr ' . $hp . "\n";
            $out .= '  ' . $same . ' = icmp eq i64 ' . $hv . ', '
                  . (string)\Compile\MemoryAbi::REF_TAG_MAGIC . "\n";
        } else {
            // A capture box holds the local's own representation; only its own.
            $own = $this->ssa->allocReg();
            $out .= '  ' . $own . ' = load ptr, ptr ' . $this->locals->ownedBoxes[$name] . "\n";
            $out .= '  ' . $same . ' = icmp eq ptr ' . $bp . ', ' . $own . "\n";
        }
        $relL = $this->ssa->allocLabel('refbox.ow');
        $contL = $this->ssa->allocLabel('refbox.ow.cont');
        $out .= '  br i1 ' . $same . ', label %' . $relL . ', label %' . $contL . "\n";
        $out .= $relL . ":\n";
        $old = $this->ssa->allocReg();
        $out .= '  ' . $old . ' = load i64, ptr ' . $bp . "\n";
        $out .= $this->rcReleaseReg($old, $flavor);
        $out .= '  br label %' . $contL . "\n";
        $out .= $contL . ":\n";
        return $out;
    }

    /**
     * A module cell — a superglobal or a `static` local — OWNS what it holds:
     * {@see EmitLlvm::collectRcObjLocals} drops the frame's scope-exit release
     * for exactly that reason, a return of the name is retained as a borrow, and
     * `unset()` releases the cell. The store had neither half of that contract:
     * a borrow went in uncounted and the value already held was never released,
     * so every whole store leaked its predecessor — `$_GET = Context::$empty` at
     * the top of each request stranded the previous request's buffer, ~80 B per
     * seeded element per request in a compat server (67 MB at 200k), and a COW
     * behind the next element store trusted a count the cell never took.
     *
     * Retain first, release second: the new value may be the old one. The
     * release dispatches on the DECL's unified type, never the value's — the old
     * value need not share the new one's shape. A `$GLOBALS`-viewed name is left
     * alone: its slot is boxed for a second reader ({@see boxForViewSlot}), and
     * that pairing is not this one.
     */
    private function globalCellOwnIr(StoreLocal $sl, string $val, bool $ownedAlready): string
    {
        if ($this->isGlobalsViewName($sl->name)) { return ''; }
        $cell = $this->locals->globalBacked[$sl->name];
        // The RELEASE needs the decl's flavor and every store's agreement with
        // it ({@see EmitLlvm::scanGlobalCellStores}); a cell without either — a
        // decl typed `int` or `null` by its initialiser, or one some store
        // disagrees with — releases nothing, as before. The RETAIN is taken
        // regardless: it is by the value's own kind (or by tag), so it can never
        // free anything, and without it the cell holds a BORROW past its owner's
        // frame — `$_SESSION = $s->data; $_SESSION['x'] = 1;` then wrote into
        // the property's own buffer (rc 1, so the COW copied nothing).
        $dt = $this->locals->globalBackedType[$sl->name] ?? null;
        $flavor = $dt === null ? '' : $this->discardReleaseFlavor($dt);
        // A closure env carries its own lifetime header, but nothing else in
        // the compiler releases one by slot, so {@see EmitLlvm::discardReleaseFlavor}
        // answers '' for it. A module cell OWNS what it holds, so name the
        // flavor here: `__mir_closure_release` self-guards on the magic, so a
        // slot that disagrees with its decl releases nothing.
        if ($flavor === '' && $dt !== null && $this->isClosureValueType($dt)) { $flavor = 'closure'; }
        if (isset($this->globalCellVeto[$cell])) { $flavor = ''; }
        $out = '';
        $v = $sl->value;
        $vk = $v->type->kind;
        if (!$ownedAlready) {
            if ($vk === Type::KIND_CELL) {
                // The one predicate for "does a cell payload need a co-owner":
                // {@see EmitLlvm::retainCellPayload} looks through `__mir_to_cell`,
                // skips the fresh producers, and retains by tag.
                $sv = $this->lastValue;
                $st = $this->lastValueType;
                $this->lastValue = $val;
                $this->lastValueType = 'i64';
                $out .= $this->retainCellPayload($v);
                $this->lastValue = $sv;
                $this->lastValueType = $st;
            } elseif ($vk === Type::KIND_OBJ || $vk === Type::KIND_ARRAY
                || $vk === Type::KIND_STRING || $vk === Type::KIND_UNION
                || $vk === Type::KIND_CLOSURE) {
                // Depth follows the DECL — the release below reads it, so the
                // retain must co-own to the same depth ({@see arrayRetainFlavor});
                // with no release to pair, the value's own depth.
                $out .= $this->rcRetainByType($v, $val, $flavor === '' ? null : $dt, 3);
            }
        }
        if ($flavor === '') { return $out; }
        return $out . $this->rcReleaseSlot($cell, $flavor);
    }

    /**
     * Emit `$s .= rhs` as an in-place amortized append. Evaluates `rhs`,
     * loads the current accumulator, calls `__mir_str_append`, frees a
     * fresh `rhs` temp, and stores the result WITHOUT a release-before-
     * overwrite (the helper already released the old buffer on the grow
     * path / kept it on the in-place path). See {@see strAppendImpl}.
     */
    private function emitSelfAppend(StoreLocal $sl, Concat $c): string
    {
        $this->rt->needsStrAppend = true;
        $this->rt->needsStrRc = true;
        $this->rt->needsConcat = true; // pulls strlen + the string runtime decls
        $slot = $this->locals->slots[$sl->name];
        // `$acc .= substr($src, $a[, $b])` — a scanner copying a run of its
        // input — appends the range straight out of `$src`: the substr temp
        // (alloc + copy + free per run) was 40% of htmlspecialchars. Never
        // when `$src` may be the accumulator itself (`$s .= substr($s, …)`, or
        // either side a reference): the range pointer is taken before the
        // append, and a grow would move it out from under the copy.
        $r = $c->right;
        if ($r->kind === Node::KIND_CALL && \strtolower(\ltrim($r->function, '\\')) === 'substr'
            && (\count($r->args) === 2 || \count($r->args) === 3)
            && $r->args[0]->type->kind === Type::KIND_STRING
            && $r->args[0]->kind === Node::KIND_LOAD_LOCAL
            && $r->args[0]->name !== $sl->name
            && !isset($this->locals->refLocals[$r->args[0]->name])
            && !isset($this->locals->refLocals[$sl->name])
            && !isset($this->locals->globalBacked[$r->args[0]->name])) {
            $out = $this->emitPtrArg($r->args[0]);
            $src = $this->lastValue;
            $out .= $this->emitIntArg($r->args[1]);
            $start = $this->lastValue;
            $len = '0';
            $haveLen = '0';
            if (\count($r->args) === 3) {
                $out .= $this->emitIntArg($r->args[2]);
                $len = $this->lastValue;
                $haveLen = '1';
            }
            $curI = $this->ssa->allocReg();
            $out .= '  ' . $curI . ' = load i64, ptr ' . $slot . "\n";
            $curP = $this->ssa->allocReg();
            $out .= '  ' . $curP . ' = inttoptr i64 ' . $curI . " to ptr\n";
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call ptr @__mir_str_append_sub(ptr ' . $curP . ', ptr ' . $src
                  . ', i64 ' . $start . ', i64 ' . $len . ', i64 ' . $haveLen . ")\n";
            $out .= $this->freeStrTemp($r->args[0], $src);
            $ri = $this->ssa->allocReg();
            $out .= '  ' . $ri . ' = ptrtoint ptr ' . $reg . " to i64\n";
            $out .= '  store i64 ' . $ri . ', ptr ' . $slot . "\n";
            $this->lastValue = $ri;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->emitNode($c->right);
        $out .= $this->coerceToStr($c->right, false);
        $rp = $this->lastValue;
        $curI = $this->ssa->allocReg();
        $out .= '  ' . $curI . ' = load i64, ptr ' . $slot . "\n";
        $curP = $this->ssa->allocReg();
        $out .= '  ' . $curP . ' = inttoptr i64 ' . $curI . " to ptr\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_append(ptr ' . $curP
              . ', ptr ' . $rp . ")\n";
        // A freshly-produced rhs (coercion temp / nested concat / call) is
        // copied into the accumulator and now dead; a borrow is left alone.
        $out .= $this->concatTempRelease($c->right, $rp);
        $ri = $this->ssa->allocReg();
        $out .= '  ' . $ri . ' = ptrtoint ptr ' . $reg . " to i64\n";
        $out .= '  store i64 ' . $ri . ', ptr ' . $slot . "\n";
        $this->lastValue = $ri;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** php's superglobals, minus `$GLOBALS` (syntax, not a variable). Mirrors
     *  {@see LowerSuperglobals::superglobalNames} — the two must agree, because
     *  what this list stands for HERE is "seeded as `assoc[string, cell]` over
     *  there". */
    private function isSuperglobalName(string $n): bool
    {
        return $n === '_SERVER' || $n === '_ENV' || $n === '_GET' || $n === '_POST'
            || $n === '_COOKIE' || $n === '_FILES' || $n === '_REQUEST' || $n === '_SESSION';
    }

    /**
     * The SUPERGLOBAL cell a local name is backed by — its own (`$_SESSION` →
     * `@g__SESSION`) or the one a reference alias forwards to (`$s = &$_SESSION`
     * copies `globalBacked`, {@see EmitLlvmObjects::emitRefAlias}); '' for
     * every other name, including a plain `global $store` (`@g_store`), which
     * keeps the refusal {@see byRefAddrOf} explains.
     */
    private function superglobalCellOf(string $name): string
    {
        $cell = $this->locals->globalBacked[$name] ?? '';
        if ($cell === '' || !\str_starts_with($cell, '@g_')) { return ''; }
        return $this->isSuperglobalName(\substr($cell, 3)) ? $cell : '';
    }

    /**
     * The module cell a by-ref argument can hand over as its slot ADDRESS —
     * every global-backed name: a superglobal, a plain `global $g`, and a
     * `static $v` local, whose cell is the storage exactly as an alloca is a
     * plain local's.
     *
     * The one exclusion is a `$GLOBALS`-viewed name, whose cell holds the value
     * BOXED for the second reader ({@see boxForViewSlot}); a callee writing a
     * raw word through that address would leave the view's own reads
     * dereferencing an untagged payload, so it keeps the refusal.
     */
    private function byRefGlobalCellOf(string $name): string
    {
        if ($this->isGlobalsViewName($name)) { return ''; }
        return $this->locals->globalBacked[$name] ?? '';
    }

    /**
     * IR computing the by-ref ADDRESS of lvalue `$a` as i64 in
     * `$this->lastValue`; null when `$a` is not addressable. A plain local
     * yields its slot address (a by-ref local already HOLDS an address — it is
     * forwarded); an object property `$obj->prop` yields a GEP to the field
     * slot, so the callee's writes to its `&$p` param mutate the property.
     */
    private function byRefAddrOf(Node $a): ?string
    {
        if ($a->kind === Node::KIND_LOAD_LOCAL) {
            $name = $a->name;
            // A GLOBAL-BACKED local — `global $x`, and every SUPERGLOBAL, which
            // {@see LowerSuperglobals} declares as one — has no alloca: the
            // module cell IS its storage, so the cell's address is the answer.
            // Exactly the static-property arm below, one storage class over.
            // Without it `['session' => &$_SESSION]`
            // (symfony/runtime GenericRuntime.php:162) was refused as "no
            // address", and the tier-4 build stopped there.
            //
            // The predicate is the NAME because the guarantee comes from the
            // seeder, not from this node's static type: at `&$_SESSION` the
            // LoadLocal can still be typed `unknown`, and asking the type here
            // refused the very shape this arm exists for.
            //
            // It was SUPERGLOBALS only for a while: their storage is
            // cell-elemented by construction ({@see LowerSuperglobals::
            // superglobalInit} seeds every one as `assoc[string, cell]`), while
            // a plain `global $store` or a `static $v` is the same STORAGE
            // class with whatever element repr its initialiser inferred. But
            // the refusal was never loud — it fell through to the by-VALUE path
            // and the callee dereferenced the ARRAY POINTER as a slot address:
            // `static $v = []; fill($v)` SIGSEGV'd and `count()` answered an
            // address. The repr question belongs to the ELEMENT channel, which
            // {@see EmitLlvm::byRefNeedsCellBox} / `byRefNeedsCellUnbox`
            // already translate on both sides of the call; the SLOT is a slot.
            $sgCell = $this->byRefGlobalCellOf($name);
            if ($sgCell !== '') {
                $addr = $this->ssa->allocReg();
                $out = '  ' . $addr . ' = ptrtoint ptr ' . $sgCell . " to i64\n";
                $this->lastValue = $addr;
                $this->lastValueType = 'i64';
                return $out;
            }
            if (!isset($this->locals->slots[$name])) { return null; }
            $addr = $this->ssa->allocReg();
            if (isset($this->locals->refLocals[$name])) {
                $out = '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$name] . "\n";
            } else {
                $out = '  ' . $addr . ' = ptrtoint ptr ' . $this->locals->slots[$name] . " to i64\n";
            }
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($a->kind === Node::KIND_STATIC_PROP) {
            // A static property IS an external-linkage global, so its address is
            // the global itself — no receiver to walk and no offset to compute.
            // Without this arm emitRefAddr fell into its "not addressable"
            // branch, which degrades to a VALUE COPY: `$t = &S::$s; $t = 40;`
            // left S::$s at its old value and said nothing.
            $addr = $this->ssa->allocReg();
            $out = '  ' . $addr . ' = ptrtoint ptr ' . $a->global . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($a->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $a;
            // No statically knowable slot — a classless receiver, or a class
            // that declares `$prop` nowhere. Returning null here degraded the
            // bind to a silent VALUE COPY ({@see EmitLlvmObjects::emitRefAddr})
            // and a by-ref RETURN to a by-value one; recover the real slot from
            // the object's class_id instead. Asks the same predicate the offset
            // itself comes from, so the two cannot drift.
            if ($this->propertyOffsetOrNull($pa->object, $pa->property) === null) {
                return $this->emitPropAddrByClassId($pa->object, $pa->property);
            }
            $out = $this->emitNode($pa->object);
            $out .= $this->coerceToPtr();
            $objp = $this->lastValue;
            $off = $this->propertyOffset($pa->object, $pa->property);
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objp
                  . ', i64 ' . (string)$off . "\n";
            // A property some `[&$o->p]` in this module stores a reference to is
            // PROMOTED here, for every `&` to it alike (a storable reference, a
            // `$r = &$o->p`, a by-ref argument): the slot's address dies with the
            // object and a write through it would overwrite the REF cell the
            // promotion left there. The box is the storage from then on.
            if (isset($this->refCellPropNames[$pa->property]) && $pa->type->kind === Type::KIND_CELL) {
                $bx = $this->ssa->allocReg();
                $out .= '  ' . $bx . ' = call ptr @__mir_ref_promote_slot(ptr ' . $g . ")\n";
                $g = $bx;
            }
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = ptrtoint ptr ' . $g . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($a->kind === Node::KIND_ARRAY_ACCESS) {
            $aa = $a;
            // `&$c[]` references the element the append creates.
            $isAppend = $aa->index->kind === Node::KIND_NULL_CONST;
            $keyKind = $isAppend ? 'append' : $this->arrayElemKeyKind($aa->index);
            if ($keyKind === null || !$this->arrayElemAddressable($aa, $isAppend)) { return null; }
            // ptr to the cell holding the array (for COW write-back).
            $this->containerCloseIr = '';
            $out = $this->containerCellPtr($aa->array);
            $close = $this->containerCloseIr;
            $this->containerCloseIr = '';
            if ($out === null) { return null; }
            $slotPtr = $this->lastValue;
            // A `$GLOBALS`-viewed global cell holds the buffer BOXED
            // ({@see boxForViewSlot}); `__mir_array_ref_slot` reads and writes
            // a raw pointer, so it works on a scratch word that is unboxed
            // going in and re-boxed into the cell coming out. The element
            // address it hands back points into the buffer either way.
            $viewCell = $aa->array->kind === Node::KIND_LOAD_LOCAL
                && isset($this->locals->globalBacked[$aa->array->name])
                && $this->isGlobalsViewName($aa->array->name)
                ? $slotPtr : '';
            if ($viewCell !== '') {
                $this->rt->needsTagged = true;
                $w = $this->ssa->allocReg();
                $out .= '  ' . $w . ' = load i64, ptr ' . $viewCell . "\n";
                $rawW = $this->ssa->allocReg();
                $out .= '  ' . $rawW . ' = and i64 ' . $w . ", 281474976710655\n";
                $scr = $this->ssa->allocReg();
                $out .= '  ' . $scr . " = alloca i64\n";
                $out .= '  store i64 ' . $rawW . ', ptr ' . $scr . "\n";
                $slotPtr = $scr;
            }
            $ep = $this->ssa->allocReg();
            if ($keyKind === 'append') {
                // The new element is null — a CELL null on a cell channel.
                $elK = $aa->type->kind;
                $nv = ($elK === Type::KIND_CELL || $elK === Type::KIND_UNKNOWN)
                    ? (string)\Compile\MemoryAbi::CELL_NULL : '0';
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot_append(ptr '
                      . $slotPtr . ', i64 ' . $nv . ")\n";
            } elseif ($keyKind === 'str') {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToPtr();
                $keyReg = $this->lastValue;
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot_str(ptr '
                      . $slotPtr . ', ptr ' . $keyReg . ")\n";
            } elseif ($keyKind === 'cell') {
                $this->rt->needsCellKey = true;
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToI64();
                $keyReg = $this->lastValue;
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot_cell(ptr '
                      . $slotPtr . ', i64 ' . $keyReg . ")\n";
            } else {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToI64();
                $keyReg = $this->lastValue;
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot(ptr '
                      . $slotPtr . ', i64 ' . $keyReg . ")\n";
            }
            if ($viewCell !== '') {
                $nw = $this->ssa->allocReg();
                $out .= '  ' . $nw . ' = load i64, ptr ' . $slotPtr . "\n";
                $np = $this->ssa->allocReg();
                $out .= '  ' . $np . ' = inttoptr i64 ' . $nw . " to ptr\n";
                $nb = $this->ssa->allocReg();
                $out .= '  ' . $nb . ' = call i64 @__manticore_box_array(ptr ' . $np . ")\n";
                $out .= '  store i64 ' . $nb . ', ptr ' . $viewCell . "\n";
            }
            $out .= $close;
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = ptrtoint ptr ' . $ep . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        return null;
    }
}
