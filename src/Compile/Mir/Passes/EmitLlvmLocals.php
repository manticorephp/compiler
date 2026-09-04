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
    private function preallocateLocals(Node $n): string
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) {
            $out = '';
            if (!isset($this->locals->globalBacked[$n->name]) && !isset($this->locals->slots[$n->name])) {
                $slot = $this->ssa->allocReg();
                $this->locals->slots[$n->name] = $slot;
                $out .= '  ' . $slot . " = alloca i64\n";
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
                return '  ' . $slot . " = alloca i64\n"
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
                    $out .= '  ' . $slot . " = alloca i64\n";
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
                $out .= '  ' . $vs . " = alloca i64\n";
            }
            if ($n->keyVar !== null && !isset($this->locals->slots[$n->keyVar])) {
                $ks = $this->ssa->allocReg();
                $this->locals->slots[$n->keyVar] = $ks;
                $out .= '  ' . $ks . " = alloca i64\n";
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
                $out .= '  ' . $is . " = alloca i64\n";
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

    private function emitLoadLocal(LoadLocal $n): string
    {
        $ll = $n;
        if (isset($this->locals->globalBacked[$ll->name])) {
            $reg = $this->ssa->allocReg();
            $out = '  ' . $reg . ' = load i64, ptr ' . $this->locals->globalBacked[$ll->name] . "\n";
            if ($ll->type->kind === Type::KIND_FLOAT) {
                $regF = $this->ssa->allocReg();
                $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
                $this->lastValue = $regF;
                $this->lastValueType = 'double';
            } else {
                $this->lastValue = $reg;
                $this->lastValueType = 'i64';
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
        if ($sl->type->kind === Type::KIND_CELL
            && $sl->value->type->kind !== Type::KIND_CELL
            && !isset($this->locals->refLocals[$sl->name])
            && !isset($this->locals->globalBacked[$sl->name])
            && isset($this->locals->slots[$sl->name])) {
            $out = $this->emitNode($sl->value);
        $out .= $this->elemReadCoOwn($sl->value, $sl->type);
            $out .= $this->boxToCell($sl->value->type, $sl->value);
            $boxed = $this->lastValue;
            $out .= '  store i64 ' . $boxed . ', ptr ' . $this->locals->slots[$sl->name] . "\n";
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
                $out .= '  store i64 ' . $dv . ', ptr ' . $this->locals->globalBacked[$sl->name] . "\n";
            } elseif (isset($this->locals->refLocals[$sl->name])) {
                $addr = $this->ssa->allocReg();
                $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$sl->name] . "\n";
                $p = $this->ssa->allocReg();
                $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
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
            && $this->isCellBoxableArg($sl->value->type)) {
            $out = $this->emitNode($sl->value);
        $out .= $this->elemReadCoOwn($sl->value, $sl->type);
            $out .= $this->boxToCell($sl->value->type);
            $dv = $this->lastValue;
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$sl->name] . "\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
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
        if ($v->kind === Node::KIND_LOAD_LOCAL
            && $v->type->isArray()
            && (isset($this->frame->mutatedVecLocals[$v->name])
                || isset($this->frame->mutatedVecLocals[$sl->name]))) {
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
            // Adopt takes exactly the element refs the copy's own release gives
            // back — the same pairing the copied vec PROPERTY below already has.
            $ci = $this->ssa->allocReg();
            $out .= '  ' . $ci . ' = ptrtoint ptr ' . $cp . " to i64\n";
            $out .= $this->arrayAdoptIr($ci, $this->arrayRetainFlavor($v, $sl->type));
            $this->lastValue = $cp;
            $this->lastValueType = 'ptr';
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
        $aliasObjStr = $v->kind === Node::KIND_LOAD_LOCAL
            && ($v->type->kind === Type::KIND_OBJ || $v->type->kind === Type::KIND_STRING);
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
        if ($aliasObjStr || $aliasArrayProp) {
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
            $out .= $copiedVecProp
                ? $this->arrayAdoptIr($aliasV, $this->arrayRetainFlavor($v, $fallback))
                : $this->rcRetainByType($v, $aliasV, $fallback, 0);
            $this->lastValue = $aliasV;
            $this->lastValueType = 'i64';
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
            $out .= '  store i64 ' . $val . ', ptr ' . $this->locals->globalBacked[$sl->name] . "\n";
        } elseif (isset($this->locals->refLocals[$sl->name])) {
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$sl->name] . "\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
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
            // ⚠ SUPERGLOBALS only. A reference writes THROUGH the cell channel,
            // and a superglobal's storage is cell-elemented by construction —
            // {@see LowerSuperglobals::superglobalInit} seeds every one of them
            // as `assoc[string, cell]`. A plain `global $store` is the same
            // STORAGE class with a different element repr
            // (`$store = ['x' => 1]` → assoc[string,int]), and handing that one
            // an address made `$store['y']` read back a denormal: the write
            // boxed, the owner's own read did not. It keeps the loud refusal
            // below rather than becoming a silent wrong answer — closing it is
            // the ref-taken-slot-is-CELL-for-its-lifetime rule
            // (docs/design/reference-cells.md) applied to global storage.
            //
            // The predicate is the NAME because the guarantee comes from the
            // seeder, not from this node's static type: at `&$_SESSION` the
            // LoadLocal can still be typed `unknown`, and asking the type here
            // refused the very shape this arm exists for.
            if ($this->isSuperglobalName($name) && isset($this->locals->globalBacked[$name])) {
                $addr = $this->ssa->allocReg();
                $out = '  ' . $addr . ' = ptrtoint ptr '
                     . $this->locals->globalBacked[$name] . " to i64\n";
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
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = ptrtoint ptr ' . $g . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        if ($a->kind === Node::KIND_ARRAY_ACCESS) {
            $aa = $a;
            $keyKind = $this->arrayElemKeyKind($aa->index);
            if ($keyKind === null || !$this->arrayElemAddressable($aa)) { return null; }
            // ptr to the cell holding the array (for COW write-back).
            $out = $this->containerCellPtr($aa->array);
            if ($out === null) { return null; }
            $slotPtr = $this->lastValue;
            $ep = $this->ssa->allocReg();
            if ($keyKind === 'str') {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToPtr();
                $keyReg = $this->lastValue;
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot_str(ptr '
                      . $slotPtr . ', ptr ' . $keyReg . ")\n";
            } else {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToI64();
                $keyReg = $this->lastValue;
                $out .= '  ' . $ep . ' = call ptr @__mir_array_ref_slot(ptr '
                      . $slotPtr . ', i64 ' . $keyReg . ")\n";
            }
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = ptrtoint ptr ' . $ep . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        return null;
    }
}
