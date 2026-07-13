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
            return $out . $this->preallocateLocals($n->body);
        }
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL
            || $k === Node::KIND_MOD || $k === Node::KIND_CMP) {
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
        return '';
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
            $out .= $this->boxToCell($sl->value->type);
            $boxed = $this->lastValue;
            $out .= '  store i64 ' . $boxed . ', ptr ' . $this->locals->slots[$sl->name] . "\n";
            $this->lastValue = $boxed;
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
        $this->arena->vecAllocated = false;
        $out = $this->emitNode($sl->value);
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
        if ($v->kind === Node::KIND_PROPERTY_ACCESS
            && $v->type->isVec()) {
            $out .= $this->coerceToPtr();
            $src = $this->lastValue;
            $cp = $this->ssa->allocReg();
            $out .= '  ' . $cp . ' = call ptr @__mir_array_copy(ptr ' . $src . ")\n";
            $this->lastValue = $cp;
            $this->lastValueType = 'ptr';
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
        // `$saved = $this->map` — a snapshot of an assoc PROPERTY. Co-own it
        // too (rc>1) so a later `$this->map[$k]=…` copy-on-writes instead of
        // clobbering the snapshot's shared buffer (the InferTypes localTypes
        // snapshot UAF). Restricted to assoc — obj/string property reads have
        // their own retain discipline elsewhere.
        $aliasAssocProp = $v->kind === Node::KIND_PROPERTY_ACCESS
            && $v->type->isAssoc();
        if ($aliasObjStr || $aliasAssocProp) {
            $out .= $this->coerceToI64();
            $aliasV = $this->lastValue;
            $out .= $this->rcRetainByType($v, $aliasV, null, 0);
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
        if ($a->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $a;
            $cls = $pa->object->type->class ?? '';
            if ($cls === '' || !isset($this->classes[$cls])) { return null; }
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
