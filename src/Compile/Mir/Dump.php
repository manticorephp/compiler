<?php

namespace Compile\Mir;

/**
 * Pretty-printer for MIR. Emits stable, diff-friendly text. Each
 * `visit*` returns the formatted chunk; callers concat the pieces.
 *
 * Why no `string &$out` accumulator: self-host pre-scan currently
 * doesn't propagate by-ref string writes back through method calls,
 * so the only reliable accumulator is the return value.
 *
 * Why {@see EmitVisitor} and not a `kind ===` chain: the chain was a
 * second, unenforced dispatch table living alongside the visitor, and
 * it drifted — 31 of the 67 kinds fell through to a `throw`, so
 * `dump-mir` died on any program containing `&&`, `||` or `?->` (all
 * three lower to {@see Ternary}). Implementing the interface makes the
 * compiler reject an unhandled kind at class-load, which is the whole
 * point of it. Do not reintroduce a `kind ===` dispatch here.
 *
 * Two invariants the visitor shape does not enforce — hold them by hand:
 *
 * 1. `$lastSlot` is a SINGLE mutable cell, not a per-node map
 *    (`spl_object_id` isn't stable under self-host yet). So every
 *    `accept()` must be followed IMMEDIATELY by its `$x = $this->lastSlot`
 *    read, before any other `accept()` runs. Never collapse the temps
 *    into one expression. `allocSlot()` clobbers it too, so a visit
 *    method allocates its own result slot LAST.
 *
 * 2. `$indent` is instance state (the interface has no room for a
 *    parameter). Save it into a local `$outer` before bumping, restore
 *    before emitting the closer. Recursion supplies the stack.
 *
 * The dump prints the TREE — ternary arms, `ref_addr` lvalues and other
 * conditionally-evaluated or never-loaded operands all appear as ordinary
 * lines. It does not model evaluation order or reachability. That is
 * already true of the long-standing kinds (`store_property` emits its
 * object) and is the right call for a golden snapshot.
 */
final class Dump implements EmitVisitor
{
    public static function module(Module $m, bool $includePrelude = false, bool $showEffects = false): string
    {
        $out = '';
        $passList = '';
        $first = true;
        foreach ($m->passesApplied as $name => $_) {
            if (!$first) { $passList .= ', '; }
            $first = false;
            $passList .= $name;
        }
        if ($passList !== '') {
            $out .= '; passes: ' . $passList . "\n";
        }
        foreach ($m->functions as $fn) {
            // Hide the built-in Exception/Error hierarchy unless asked —
            // golden snapshots track user lowering, not fixed boilerplate.
            if ($fn->isPrelude && !$includePrelude) { continue; }
            $out .= self::function_($fn, $showEffects);
        }
        return $out;
    }

    private static function function_(FunctionDef $fn, bool $showEffects): string
    {
        $paramStr = '';
        $first = true;
        foreach ($fn->params as $p) {
            if (!$first) { $paramStr .= ', '; }
            $first = false;
            $paramStr .= $p->type->toString() . ' %' . $p->name;
        }
        $out = "\nfn " . $fn->name . '(' . $paramStr . ') -> '
             . $fn->returnType->toString() . " {\n";
        if ($showEffects && $fn->effects !== null) {
            $agg = $fn->effects->toString();
            $out .= '  ; effects: ' . ($agg === '' ? '(none)' : $agg) . "\n";
        }
        $printer = new self();
        $printer->showEffects = $showEffects;
        $printer->indent = '  ';
        $body = $fn->body;
        $out .= $body->accept($printer);
        $out .= "}\n";
        return $out;
    }

    private bool $showEffects = false;

    private string $indent = '';

    /**
     * Per-op effect annotation: `  ; eff: alloc,throw` when effects are
     * shown and the node carries a non-empty set; empty string otherwise.
     */
    private function eff(Node $n): string
    {
        if (!$this->showEffects) { return ''; }
        $e = $n->effects;
        if ($e === null) { return ''; }
        $s = $e->toString();
        $kind = $n->allocKind;
        if ($s === '' && $kind === null) { return ''; }
        $out = '  ; eff: ' . $s;
        if ($kind !== null) { $out .= ' alloc=' . $kind; }
        return $out;
    }

    private int $nextId = 0;

    public function visitIntConst(IntConst $n): string
    {
        $name = $this->allocSlot();
        return $this->indent . $name . ' = int_const ' . (string)$n->value
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitFloatConst(FloatConst $n): string
    {
        $name = $this->allocSlot();
        return $this->indent . $name . ' = float_const ' . (string)$n->value
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitStringConst(StringConst $n): string
    {
        $name = $this->allocSlot();
        $escaped = $this->escapeForDump($n->value);
        return $this->indent . $name . ' = string_const "' . $escaped . '"'
             . ' : ' . $n->type->toString() . "\n";
    }

    /**
     * Minimal C-style escape — covers what we emit into the dump.
     * Avoids `addslashes` which isn't part of the self-host
     * stdlib surface yet (referenced in the known-stdlib list but
     * never implemented). Kept tight: backslash, dquote, newline,
     * tab, CR. Anything else passes through.
     */
    private function escapeForDump(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i = $i + 1) {
            // Self-host `$s[$i]` returns the byte *value* (int)
            // rather than a 1-char string. `substr` gets the
            // expected string form.
            $c = \substr($s, $i, 1);
            if ($c === '\\') { $out .= '\\\\'; continue; }
            if ($c === '"')  { $out .= '\\"';  continue; }
            if ($c === "\n") { $out .= '\\n';  continue; }
            if ($c === "\t") { $out .= '\\t';  continue; }
            if ($c === "\r") { $out .= '\\r';  continue; }
            $out .= $c;
        }
        return $out;
    }

    public function visitBoolConst(BoolConst $n): string
    {
        $name = $this->allocSlot();
        $lit = $n->value ? 'true' : 'false';
        return $this->indent . $name . ' = bool_const ' . $lit
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitNullConst(NullConst $n): string
    {
        $name = $this->allocSlot();
        return $this->indent . $name . ' = null_const : ' . $n->type->toString() . "\n";
    }

    public function visitLoadLocal(LoadLocal $n): string
    {
        $name = $this->allocSlot();
        return $this->indent . $name . ' = load_local ' . $n->name
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitStoreLocal(StoreLocal $n): string
    {
        $value = $n->value;
        $valChunk = $value->accept($this);
        $valName = $this->lastSlot;
        $name = $this->allocSlot();
        return $valChunk . $this->indent . $name . ' = store_local ' . $n->name
             . ' <- ' . $valName . ' : ' . $n->type->toString() . "\n";
    }

    public function visitAdd(Add $n): string { return $this->bin($n, $n->left, $n->right, $n->type, 'add'); }
    public function visitSub(Sub $n): string { return $this->bin($n, $n->left, $n->right, $n->type, 'sub'); }
    public function visitMul(Mul $n): string { return $this->bin($n, $n->left, $n->right, $n->type, 'mul'); }
    public function visitDiv(Div $n): string { return $this->bin($n, $n->left, $n->right, $n->type, 'div'); }
    public function visitMod(Mod $n): string { return $this->bin($n, $n->left, $n->right, $n->type, 'mod'); }
    public function visitConcat(Concat $n): string { return $this->bin($n, $n->left, $n->right, $n->type, 'concat'); }

    private function bin(Node $node, Node $left, Node $right, Type $type, string $op): string
    {
        $lChunk = $left->accept($this);
        $lName = $this->lastSlot;
        $rChunk = $right->accept($this);
        $rName = $this->lastSlot;
        $name = $this->allocSlot();
        return $lChunk . $rChunk . $this->indent . $name . ' = ' . $op . ' '
             . $lName . ', ' . $rName . ' : ' . $type->toString()
             . $this->eff($node) . "\n";
    }

    public function visitEcho(Echo_ $n): string
    {
        $out = '';
        $line = $this->indent . 'echo ';
        $first = true;
        foreach ($n->exprs as $e) {
            $out .= $e->accept($this);
            if (!$first) { $line .= ', '; }
            $first = false;
            $line .= $this->lastSlot;
        }
        return $out . $line . "\n";
    }

    public function visitReturn(Return_ $n): string
    {
        $value = $n->value;
        if ($value === null) {
            return $this->indent . "return\n";
        }
        $chunk = $value->accept($this);
        return $chunk . $this->indent . 'return ' . $this->lastSlot . $this->eff($n) . "\n";
    }

    public function visitCall(Call $n): string
    {
        $out = '';
        $argLine = '';
        $first = true;
        foreach ($n->args as $a) {
            $out .= $a->accept($this);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $out . $this->indent . $name . ' = call ' . $n->function
             . '(' . $argLine . ')'
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitBlock(Block $n): string
    {
        $out = '';
        foreach ($n->stmts as $s) {
            $out .= $s->accept($this);
        }
        return $out;
    }

    public function visitMemoryOp(MemoryOp_ $n): string
    {
        // Whole-frame arena scope carries no flavor / target.
        $line = $this->indent . 'mem_' . $n->op;
        if ($n->flavor !== '') {
            $line .= ' ' . $n->flavor;
        }
        $target = $n->target;
        if ($target !== null && $target->kind === Node::KIND_LOAD_LOCAL) {
            $line .= ' ' . $this->asLoadLocalName($target);
        }
        return $line . "\n";
    }

    private function asLoadLocalName(Node $n): string
    {
        $ll = $this->castLoadLocal($n);
        return $ll->name;
    }

    private function castLoadLocal(Node $n): LoadLocal { return $n; }

    public function visitArrayLit(ArrayLit $n): string
    {
        $out = '';
        $parts = '';
        $first = true;
        foreach ($n->elements as $el) {
            if ($el->key !== null) {
                $out .= $el->key->accept($this);
                $kName = $this->lastSlot;
            } else {
                $kName = '_';
            }
            $out .= $el->value->accept($this);
            $vName = $this->lastSlot;
            if (!$first) { $parts .= ', '; }
            $first = false;
            $parts .= $kName . ' => ' . $vName;
        }
        $name = $this->allocSlot();
        return $out . $this->indent . $name . ' = array_lit [' . $parts . ']'
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitArrayAccess(ArrayAccess_ $n): string
    {
        $aChunk = $n->array->accept($this);
        $aName  = $this->lastSlot;
        $iChunk = $n->index->accept($this);
        $iName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $aChunk . $iChunk . $this->indent . $name . ' = array_access '
             . $aName . '[' . $iName . ']'
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitStoreElement(StoreElement $n): string
    {
        $aChunk = $n->array->accept($this);
        $aName  = $this->lastSlot;
        $iChunk = $n->index->accept($this);
        $iName  = $this->lastSlot;
        $vChunk = $n->value->accept($this);
        $vName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $aChunk . $iChunk . $vChunk . $this->indent . $name . ' = store_element '
             . $aName . '[' . $iName . '] <- ' . $vName
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitNewObj(NewObj $n): string
    {
        $out = '';
        $argLine = '';
        $first = true;
        foreach ($n->args as $a) {
            $out .= $a->accept($this);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $out . $this->indent . $name . ' = new ' . $n->class
             . '(' . $argLine . ')'
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitPropertyAccess(PropertyAccess_ $n): string
    {
        $oChunk = $n->object->accept($this);
        $oName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $oChunk . $this->indent . $name . ' = property_access ' . $oName
             . '->' . $n->property
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitStoreProperty(StoreProperty $n): string
    {
        $oChunk = $n->object->accept($this);
        $oName  = $this->lastSlot;
        $vChunk = $n->value->accept($this);
        $vName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $oChunk . $vChunk . $this->indent . $name . ' = store_property '
             . $oName . '->' . $n->property . ' <- ' . $vName
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitMethodCall(MethodCall_ $n): string
    {
        $oChunk = $n->object->accept($this);
        $oName  = $this->lastSlot;
        $argOut = '';
        $argLine = '';
        $first = true;
        foreach ($n->args as $a) {
            $argOut .= $a->accept($this);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $oChunk . $argOut . $this->indent . $name . ' = method_call '
             . $oName . '->' . $n->method . '(' . $argLine . ')'
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitStaticCall(StaticCall_ $n): string
    {
        $argOut = '';
        $argLine = '';
        $first = true;
        foreach ($n->args as $a) {
            $argOut .= $a->accept($this);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $argOut . $this->indent . $name . ' = static_call '
             . $n->class . '::' . $n->method . '(' . $argLine . ')'
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitNeg(Neg $n): string
    {
        $chunk = $n->operand->accept($this);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $this->indent . $name . ' = neg ' . $opName
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitNot(Not_ $n): string
    {
        $chunk = $n->operand->accept($this);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $this->indent . $name . ' = not ' . $opName
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitBitOp(BitOp $n): string
    {
        $lChunk = $n->left->accept($this);
        $lName  = $this->lastSlot;
        $rChunk = $n->right->accept($this);
        $rName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $lChunk . $rChunk . $this->indent . $name . ' = ' . $n->op . ' '
             . $lName . ', ' . $rName . ' : ' . $n->type->toString() . "\n";
    }

    public function visitBitNot(BitNot_ $n): string
    {
        $chunk = $n->operand->accept($this);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $this->indent . $name . ' = bitnot ' . $opName
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitSpaceship(\Compile\Mir\Spaceship $n): string
    {
        $lChunk = $n->left->accept($this);
        $lName  = $this->lastSlot;
        $rChunk = $n->right->accept($this);
        $rName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $lChunk . $rChunk . $this->indent . $name . ' = spaceship '
             . $lName . ', ' . $rName . ' : ' . $n->type->toString() . "\n";
    }

    public function visitCmp(Cmp $n): string
    {
        $lChunk = $n->left->accept($this);
        $lName  = $this->lastSlot;
        $rChunk = $n->right->accept($this);
        $rName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $lChunk . $rChunk . $this->indent . $name . ' = cmp ' . $n->op . ' '
             . $lName . ', ' . $rName . ' : ' . $n->type->toString() . "\n";
    }

    public function visitIf(If_ $n): string
    {
        $condChunk = $n->cond->accept($this);
        $condName  = $this->lastSlot;
        $outer = $this->indent;
        $out = $condChunk . $outer . 'if ' . $condName . " then {\n";
        $this->indent = $outer . '  ';
        $out .= $n->then->accept($this);
        $this->indent = $outer;
        $out .= $outer . "}";
        if ($n->else !== null) {
            $out .= " else {\n";
            $this->indent = $outer . '  ';
            $out .= $n->else->accept($this);
            $this->indent = $outer;
            $out .= $outer . "}";
        }
        return $out . "\n";
    }

    public function visitWhile(While_ $n): string
    {
        // The condition is loop-carried: it belongs INSIDE the block, at
        // the inner indent, named by `check`.
        $outer = $this->indent;
        $inner = $outer . '  ';
        $this->indent = $inner;
        $condChunk = $n->cond->accept($this);
        $condName  = $this->lastSlot;
        $out = $outer . "while {\n";
        $out .= $condChunk . $inner . 'check ' . $condName . "\n";
        $out .= $n->body->accept($this);
        $this->indent = $outer;
        $out .= $outer . "}\n";
        return $out;
    }

    public function visitBreak(Break_ $n): string { return $this->indent . "break\n"; }

    public function visitContinue(Continue_ $n): string { return $this->indent . "continue\n"; }

    // ---------------------------------------------------------------
    // Not yet formatted. These 31 kinds have never been dumpable; the
    // visitor now names each one explicitly so the gap is a list rather
    // than a silent fall-through. Formats land next.
    // ---------------------------------------------------------------

    private function todo(Node $n): string
    {
        throw new \RuntimeException('MIR.dump: node kind not yet formatted: ' . $n->kind);
    }

    public function visitIncDec(IncDec $n): string { return $this->todo($n); }
    public function visitTernary(Ternary $n): string { return $this->todo($n); }
    public function visitCast(Cast $n): string { return $this->todo($n); }
    public function visitInstanceof(Instanceof_ $n): string { return $this->todo($n); }
    public function visitNullCoalesce(NullCoalesce_ $n): string { return $this->todo($n); }
    public function visitClosure(Closure_ $n): string { return $this->todo($n); }
    public function visitInvoke(Invoke_ $n): string { return $this->todo($n); }
    public function visitStaticProp(StaticProp_ $n): string { return $this->todo($n); }
    public function visitStoreStaticProp(StoreStaticProp_ $n): string { return $this->todo($n); }
    public function visitStaticLocalDecl(StaticLocalDecl_ $n): string { return $this->todo($n); }
    public function visitIsset(Isset_ $n): string { return $this->todo($n); }
    public function visitUnset(Unset_ $n): string { return $this->todo($n); }
    public function visitClassName(ClassName_ $n): string { return $this->todo($n); }
    public function visitRefAlias(RefAlias_ $n): string { return $this->todo($n); }
    public function visitRefBind(RefBind_ $n): string { return $this->todo($n); }
    public function visitRefAddr(RefAddr_ $n): string { return $this->todo($n); }
    public function visitGoto(Goto_ $n): string { return $this->todo($n); }
    public function visitLabel(Label_ $n): string { return $this->todo($n); }
    public function visitThrow(Throw_ $n): string { return $this->todo($n); }
    public function visitTryCatch(TryCatch_ $n): string { return $this->todo($n); }
    public function visitYield(Yield_ $n): string { return $this->todo($n); }
    public function visitFor(For_ $n): string { return $this->todo($n); }
    public function visitDoWhile(DoWhile_ $n): string { return $this->todo($n); }
    public function visitSwitch(Switch_ $n): string { return $this->todo($n); }
    public function visitMatch(Match_ $n): string { return $this->todo($n); }
    public function visitForeach(Foreach_ $n): string { return $this->todo($n); }
    public function visitSpread(Spread_ $n): string { return $this->todo($n); }
    public function visitNewDynObj(NewDynObj $n): string { return $this->todo($n); }
    public function visitClone(Clone_ $n): string { return $this->todo($n); }
    public function visitDynProp(DynProp_ $n): string { return $this->todo($n); }
    public function visitStoreDynProp(StoreDynProp_ $n): string { return $this->todo($n); }

    private string $lastSlot = '%?';

    /**
     * Allocate a fresh slot, cache it as `$lastSlot`, return its
     * spelling. Each visit* returns its chunk and stashes the
     * produced slot in `$lastSlot` so the parent can reference it
     * without needing a stable object-id keyed lookup (`spl_object_id`
     * is not reliably stable under self-host yet).
     */
    private function allocSlot(): string
    {
        $slot = '%' . (string)$this->nextId;
        $this->nextId = $this->nextId + 1;
        $this->lastSlot = $slot;
        return $slot;
    }
}
