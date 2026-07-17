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

    public function visitBreak(Break_ $n): string
    {
        $lvl = $n->level !== 1 ? (' ' . (string)$n->level) : '';
        return $this->indent . 'break' . $lvl . "\n";
    }

    public function visitContinue(Continue_ $n): string
    {
        $lvl = $n->level !== 1 ? (' ' . (string)$n->level) : '';
        return $this->indent . 'continue' . $lvl . "\n";
    }

    // ── Conditionally-evaluated value nodes ───────────────────────────
    // Their arms only run on the taken branch, so a flat `%c ? %t : %e`
    // would misrepresent evaluation. They print like `if`: a block per
    // arm, the arm result named `-> %N`, and the node's own result slot
    // allocated LAST so slot numbers stay monotonic.

    public function visitTernary(Ternary $n): string
    {
        $condChunk = $n->cond->accept($this);
        $condName  = $this->lastSlot;
        $outer = $this->indent;
        $inner = $outer . '  ';
        $out = $condChunk . $outer . 'ternary ' . $condName;
        if ($n->then !== null) {
            $out .= " then {\n";
            $this->indent = $inner;
            $out .= $n->then->accept($this);
            $out .= $inner . '-> ' . $this->lastSlot . "\n";
            $this->indent = $outer;
            $out .= $outer . '}';
        } else {
            $out .= ' ?:';
        }
        $out .= " else {\n";
        $this->indent = $inner;
        $out .= $n->else_->accept($this);
        $out .= $inner . '-> ' . $this->lastSlot . "\n";
        $this->indent = $outer;
        $out .= $outer . '}';
        $name = $this->allocSlot();
        return $out . ' => ' . $name . ' : ' . $n->type->toString()
             . ($n->nullable ? ' nullable' : '') . "\n";
    }

    public function visitNullCoalesce(NullCoalesce_ $n): string
    {
        $lChunk = $n->left->accept($this);
        $lName  = $this->lastSlot;
        $outer = $this->indent;
        $inner = $outer . '  ';
        $out = $lChunk . $outer . 'nullcoalesce ' . $lName . " else {\n";
        $this->indent = $inner;
        $out .= $n->right->accept($this);
        $out .= $inner . '-> ' . $this->lastSlot . "\n";
        $this->indent = $outer;
        $out .= $outer . '}';
        $name = $this->allocSlot();
        return $out . ' => ' . $name . ' : ' . $n->type->toString() . "\n";
    }

    public function visitMatch(Match_ $n): string
    {
        $subjChunk = $n->subject->accept($this);
        $subjName  = $this->lastSlot;
        $outer = $this->indent;
        $inner = $outer . '  ';
        $armInner = $inner . '  ';
        $out = $subjChunk . $outer . 'match ' . $subjName . " {\n";
        foreach ($n->arms as $arm) {
            $out .= $inner . ($arm->conds === null ? "arm default {\n" : "arm {\n");
            $this->indent = $armInner;
            if ($arm->conds !== null) {
                $condLine = '';
                $first = true;
                foreach ($arm->conds as $c) {
                    $out .= $this->operand($c);
                    if (!$first) { $condLine .= ', '; }
                    $first = false;
                    $condLine .= $this->lastSlot;
                }
                $out .= $armInner . 'when ' . $condLine . "\n";
            }
            $out .= $arm->body->accept($this);
            $out .= $armInner . '-> ' . $this->lastSlot . "\n";
            $this->indent = $inner;
            $out .= $inner . "}\n";
        }
        $this->indent = $outer;
        $out .= $outer . "}";
        $name = $this->allocSlot();
        return $out . ' => ' . $name . ' : ' . $n->type->toString() . "\n";
    }

    // ── Control-flow statements with bodies ───────────────────────────

    public function visitSwitch(Switch_ $n): string
    {
        $subjChunk = $n->subject->accept($this);
        $subjName  = $this->lastSlot;
        $outer = $this->indent;
        $inner = $outer . '  ';
        $armInner = $inner . '  ';
        $out = $subjChunk . $outer . 'switch ' . $subjName . " {\n";
        foreach ($n->arms as $arm) {
            $this->indent = $armInner;
            if ($arm->value === null) {
                $out .= $inner . "arm default {\n";
            } else {
                $out .= $inner . "arm {\n";
                $out .= $arm->value->accept($this);
                $out .= $armInner . 'on ' . $this->lastSlot . "\n";
            }
            $out .= $this->body($arm->body);
            $this->indent = $inner;
            $out .= $inner . "}\n";
        }
        $this->indent = $outer;
        return $out . $outer . "}\n";
    }

    public function visitForeach(Foreach_ $n): string
    {
        $arrChunk = $n->array->accept($this);
        $arrName  = $this->lastSlot;
        $outer = $this->indent;
        $inner = $outer . '  ';
        $head = $outer . 'foreach ' . $arrName . ' as ';
        if ($n->keyVar !== null) { $head .= $n->keyVar . ' => '; }
        if ($n->byRef) { $head .= '&'; }
        $head .= $n->valueVar;
        if ($n->iterClass !== '') {
            $head .= ' : iter ' . $n->iterClass;
            if ($n->iterAggregate) { $head .= ' aggregate'; }
        }
        $out = $arrChunk . $head . " {\n";
        $this->indent = $inner;
        $out .= $n->body->accept($this);
        $this->indent = $outer;
        return $out . $outer . "}\n";
    }

    public function visitFor(For_ $n): string
    {
        $outer = $this->indent;
        $inner = $outer . '  ';
        $sect = $inner . '  ';
        $out = $outer . "for {\n";
        if ($n->init !== null) {
            $out .= $inner . "init {\n";
            $this->indent = $sect;
            $out .= $n->init->accept($this);
            $this->indent = $inner;
            $out .= $inner . "}\n";
        }
        if ($n->cond !== null) {
            $out .= $inner . "cond {\n";
            $this->indent = $sect;
            $out .= $n->cond->accept($this);
            $out .= $sect . 'check ' . $this->lastSlot . "\n";
            $this->indent = $inner;
            $out .= $inner . "}\n";
        }
        if ($n->step !== null) {
            $out .= $inner . "step {\n";
            $this->indent = $sect;
            $out .= $n->step->accept($this);
            $this->indent = $inner;
            $out .= $inner . "}\n";
        }
        $out .= $inner . "body {\n";
        $this->indent = $sect;
        $out .= $n->body->accept($this);
        $this->indent = $inner;
        $out .= $inner . "}\n";
        $this->indent = $outer;
        return $out . $outer . "}\n";
    }

    public function visitDoWhile(DoWhile_ $n): string
    {
        $outer = $this->indent;
        $inner = $outer . '  ';
        $out = $outer . "dowhile {\n";
        $this->indent = $inner;
        $out .= $n->body->accept($this);
        $condChunk = $n->cond->accept($this);
        $out .= $condChunk . $inner . 'check ' . $this->lastSlot . "\n";
        $this->indent = $outer;
        return $out . $outer . "}\n";
    }

    public function visitTryCatch(TryCatch_ $n): string
    {
        $outer = $this->indent;
        $inner = $outer . '  ';
        $out = '';
        // Generator frame slots (set by EmitLlvm, which never runs on this
        // path) are -1 here; print only if a future pass populates them.
        if ($n->genDepthSlot !== -1 || $n->genOuterSlot !== -1 || $n->genPendSlot !== -1) {
            $out .= $outer . '; gen: depth=' . (string)$n->genDepthSlot
                  . ' outer=' . (string)$n->genOuterSlot
                  . ' pend=' . (string)$n->genPendSlot . "\n";
        }
        $out .= $outer . "try {\n";
        $this->indent = $inner;
        $out .= $this->body($n->tryBody);
        $this->indent = $outer;
        $out .= $outer . "}";
        foreach ($n->catches as $c) {
            $out .= ' catch (' . \implode(' | ', $c->types);
            if ($c->var !== null) { $out .= ' ' . $c->var; }
            $out .= ") {\n";
            $this->indent = $inner;
            $out .= $this->body($c->body);
            $this->indent = $outer;
            $out .= $outer . "}";
        }
        if ($n->hasFinally) {
            $out .= " finally {\n";
            $this->indent = $inner;
            $out .= $this->body($n->finallyBody);
            $this->indent = $outer;
            $out .= $outer . "}";
        }
        return $out . "\n";
    }

    /** @param Node[] $ns */
    private function body(array $ns): string
    {
        $out = '';
        foreach ($ns as $s) {
            $out .= $this->operand($s);
        }
        return $out;
    }

    /**
     * Visit one node reached by iterating an array. A value pulled out of an
     * untyped / nullable array (`MatchArm_::$conds` is `?array`, `Isset_::$targets`
     * is `array`) is cell-typed at the call site, and `$c->accept($this)` on a
     * cell receiver mis-dispatches under self-host (it hands back the object
     * handle as an int instead of the visit result). Funnelling through a
     * `Node`-typed parameter narrows the receiver first — the same reason the
     * LLVM emitter walks children via `emitNode(Node)` rather than `->accept()`.
     */
    private function operand(Node $c): string
    {
        return $c->accept($this);
    }

    // ── Simple value / statement nodes ────────────────────────────────

    public function visitIncDec(IncDec $n): string
    {
        $sym  = $n->op === '+' ? '++' : '--';
        $form = $n->prefix ? ($sym . $n->name) : ($n->name . $sym);
        $name = $this->allocSlot();
        return $this->indent . $name . ' = incdec ' . $form
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitCast(Cast $n): string
    {
        $chunk = $n->operand->accept($this);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $this->indent . $name . ' = cast ' . $n->target . ' ' . $opName
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitInstanceof(Instanceof_ $n): string
    {
        $chunk = $n->operand->accept($this);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $this->indent . $name . ' = instanceof ' . $opName . ', ' . $n->class
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitClassName(ClassName_ $n): string
    {
        $chunk = $n->operand->accept($this);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $this->indent . $name . ' = class_name ' . $opName
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitSpread(Spread_ $n): string
    {
        $chunk = $n->operand->accept($this);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $this->indent . $name . ' = spread ' . $opName
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitIsset(Isset_ $n): string
    {
        $out = '';
        $line = '';
        $first = true;
        foreach ($n->targets as $t) {
            $out .= $this->operand($t);
            if (!$first) { $line .= ', '; }
            $first = false;
            $line .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $out . $this->indent . $name . ' = isset ' . $line
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitUnset(Unset_ $n): string
    {
        $out = '';
        $line = '';
        $first = true;
        foreach ($n->targets as $t) {
            $out .= $this->operand($t);
            if (!$first) { $line .= ', '; }
            $first = false;
            $line .= $this->lastSlot;
        }
        return $out . $this->indent . 'unset ' . $line . "\n";
    }

    public function visitInvoke(Invoke_ $n): string
    {
        $cChunk = $n->callee->accept($this);
        $cName  = $this->lastSlot;
        $argOut = '';
        $argLine = '';
        $first = true;
        foreach ($n->args as $a) {
            $argOut .= $this->operand($a);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $cChunk . $argOut . $this->indent . $name . ' = invoke ' . $cName
             . '(' . $argLine . ')' . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitClosure(Closure_ $n): string
    {
        $out = '';
        $useLine = '';
        $first = true;
        foreach ($n->captures as $i => $cap) {
            $out .= $this->operand($cap);
            if (!$first) { $useLine .= ', '; }
            $first = false;
            $byRef = isset($n->captureByRef[$i]) && $n->captureByRef[$i];
            $useLine .= ($byRef ? '&' : '') . $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $out . $this->indent . $name . ' = closure #' . (string)$n->id
             . ' use (' . $useLine . ')' . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitStaticProp(StaticProp_ $n): string
    {
        $name = $this->allocSlot();
        return $this->indent . $name . ' = static_prop ' . $n->global
             . ' : ' . $n->type->toString() . "\n";
    }

    public function visitStoreStaticProp(StoreStaticProp_ $n): string
    {
        $vChunk = $n->value->accept($this);
        $vName  = $this->lastSlot;
        $name = $this->allocSlot();
        return $vChunk . $this->indent . $name . ' = store_static_prop ' . $n->global
             . ' <- ' . $vName . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitStaticLocalDecl(StaticLocalDecl_ $n): string
    {
        $out = '';
        $initName = '';
        if ($n->init !== null) {
            $out .= $n->init->accept($this);
            $initName = $this->lastSlot;
        }
        $name = $this->allocSlot();
        $line = $this->indent . $name . ' = static_local_decl ' . $n->name . ' cell=' . $n->cell;
        if ($n->guard !== '') { $line .= ' guard=' . $n->guard; }
        if ($n->init !== null) { $line .= ' <- ' . $initName; }
        return $out . $line . ' : ' . $n->type->toString() . "\n";
    }

    public function visitThrow(Throw_ $n): string
    {
        $chunk = $n->value->accept($this);
        return $chunk . $this->indent . 'throw ' . $this->lastSlot . $this->eff($n) . "\n";
    }

    public function visitYield(Yield_ $n): string
    {
        $out = '';
        $mid = 'yield';
        if ($n->from) { $mid .= ' from'; }
        if ($n->key !== null) {
            $out .= $n->key->accept($this);
            $kName = $this->lastSlot;
            $out .= $n->value->accept($this);
            $vName = $this->lastSlot;
            $mid .= ' ' . $kName . ' => ' . $vName;
        } elseif ($n->value !== null) {
            $out .= $n->value->accept($this);
            $mid .= ' ' . $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $out . $this->indent . $name . ' = ' . $mid
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitGoto(Goto_ $n): string
    {
        return $this->indent . 'goto ' . $n->label . "\n";
    }

    public function visitLabel(Label_ $n): string
    {
        return $this->indent . 'label ' . $n->name . "\n";
    }

    public function visitRefAlias(RefAlias_ $n): string
    {
        return $this->indent . 'ref_alias ' . $n->target . ' = &' . $n->source . "\n";
    }

    public function visitRefBind(RefBind_ $n): string
    {
        $chunk = $n->call->accept($this);
        return $chunk . $this->indent . 'ref_bind ' . $n->target . ' = &' . $this->lastSlot . "\n";
    }

    public function visitRefAddr(RefAddr_ $n): string
    {
        $chunk = $n->lvalue->accept($this);
        return $chunk . $this->indent . 'ref_addr ' . $n->target . ' = &' . $this->lastSlot . "\n";
    }

    public function visitNewDynObj(NewDynObj $n): string
    {
        $cChunk = $n->classExpr->accept($this);
        $cName  = $this->lastSlot;
        $argOut = '';
        $argLine = '';
        $first = true;
        foreach ($n->args as $a) {
            $argOut .= $this->operand($a);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $cChunk . $argOut . $this->indent . $name . ' = new_dyn ' . $cName
             . '(' . $argLine . ')' . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitClone(Clone_ $n): string
    {
        $oChunk = $n->object->accept($this);
        $oName  = $this->lastSlot;
        $withOut = '';
        $withLine = '';
        $first = true;
        foreach ($n->withProps as $w) {
            $withOut .= $this->operand($w->value);
            if (!$first) { $withLine .= ', '; }
            $first = false;
            $withLine .= $w->name . ': ' . $this->lastSlot;
        }
        $name = $this->allocSlot();
        $with = \count($n->withProps) > 0 ? (' with (' . $withLine . ')') : '';
        return $oChunk . $withOut . $this->indent . $name . ' = clone ' . $oName . $with
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

    public function visitDynProp(DynProp_ $n): string
    {
        $oChunk = $n->object->accept($this);
        $oName  = $this->lastSlot;
        $nChunk = $n->name->accept($this);
        $nName  = $this->lastSlot;
        $name = $this->allocSlot();
        return $oChunk . $nChunk . $this->indent . $name . ' = dyn_prop ' . $oName
             . '->{' . $nName . '}' . ' : ' . $n->type->toString() . "\n";
    }

    public function visitStoreDynProp(StoreDynProp_ $n): string
    {
        $oChunk = $n->object->accept($this);
        $oName  = $this->lastSlot;
        $nChunk = $n->name->accept($this);
        $nName  = $this->lastSlot;
        $vChunk = $n->value->accept($this);
        $vName  = $this->lastSlot;
        $name = $this->allocSlot();
        return $oChunk . $nChunk . $vChunk . $this->indent . $name . ' = store_dyn_prop '
             . $oName . '->{' . $nName . '} <- ' . $vName
             . ' : ' . $n->type->toString() . $this->eff($n) . "\n";
    }

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
