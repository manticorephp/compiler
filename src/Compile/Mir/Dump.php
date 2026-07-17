<?php

namespace Compile\Mir;

/**
 * Pretty-printer for MIR. Emits stable, diff-friendly text. Each
 * `emit*` returns the formatted chunk; callers concat the pieces.
 *
 * Why no `string &$out` accumulator: self-host pre-scan currently
 * doesn't propagate by-ref string writes back through method calls,
 * so the only reliable accumulator is the return value.
 *
 * Why kind-only dispatch (no `$node instanceof X` guards): self-host
 * mis-resolves bare short class names when they collide across
 * namespaces (e.g. our `Compile\Mir\Block` vs `Codegen\Llvm\Block`).
 * Typed parameter on the per-kind helper does the type-narrowing.
 */
final class Dump
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
        $body = $fn->body;
        $out .= $printer->emitNode($body, '  ');
        $out .= "}\n";
        return $out;
    }

    private bool $showEffects = false;

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

    private function emitNode(Node $node, string $indent): string
    {
        $kind = $node->kind;
        if ($kind === Node::KIND_INT_CONST)    { return $this->emitIntConst($node, $indent); }
        if ($kind === Node::KIND_FLOAT_CONST)  { return $this->emitFloatConst($node, $indent); }
        if ($kind === Node::KIND_STRING_CONST) { return $this->emitStringConst($node, $indent); }
        if ($kind === Node::KIND_BOOL_CONST)   { return $this->emitBoolConst($node, $indent); }
        if ($kind === Node::KIND_NULL_CONST)   { return $this->emitNullConst($node, $indent); }
        if ($kind === Node::KIND_LOAD_LOCAL)   { return $this->emitLoadLocal($node, $indent); }
        if ($kind === Node::KIND_STORE_LOCAL)  { return $this->emitStoreLocal($node, $indent); }
        if ($kind === Node::KIND_ADD)          { return $this->emitAdd($node, $indent); }
        if ($kind === Node::KIND_SUB)          { return $this->emitSub($node, $indent); }
        if ($kind === Node::KIND_MUL)          { return $this->emitMul($node, $indent); }
        if ($kind === Node::KIND_DIV)          { return $this->emitDiv($node, $indent); }
        if ($kind === Node::KIND_MOD)          { return $this->emitMod($node, $indent); }
        if ($kind === Node::KIND_NEG)          { return $this->emitNeg($node, $indent); }
        if ($kind === Node::KIND_NOT)          { return $this->emitNot($node, $indent); }
        if ($kind === Node::KIND_BITOP)        { return $this->emitBitOp($node, $indent); }
        if ($kind === Node::KIND_BITNOT)       { return $this->emitBitNot($node, $indent); }
        if ($kind === Node::KIND_CONCAT)       { return $this->emitConcat($node, $indent); }
        if ($kind === Node::KIND_CMP)          { return $this->emitCmp($node, $indent); }
        if ($kind === Node::KIND_SPACESHIP)    { return $this->emitSpaceship($node, $indent); }
        if ($kind === Node::KIND_ECHO)         { return $this->emitEcho($node, $indent); }
        if ($kind === Node::KIND_RETURN)       { return $this->emitReturn($node, $indent); }
        if ($kind === Node::KIND_CALL)         { return $this->emitCall($node, $indent); }
        if ($kind === Node::KIND_IF)           { return $this->emitIf($node, $indent); }
        if ($kind === Node::KIND_WHILE)        { return $this->emitWhile($node, $indent); }
        if ($kind === Node::KIND_BREAK)        { return $indent . "break\n"; }
        if ($kind === Node::KIND_CONTINUE)     { return $indent . "continue\n"; }
        if ($kind === Node::KIND_ARRAY_LIT)       { return $this->emitArrayLit($node, $indent); }
        if ($kind === Node::KIND_ARRAY_ACCESS)    { return $this->emitArrayAccess($node, $indent); }
        if ($kind === Node::KIND_STORE_ELEMENT)   { return $this->emitStoreElement($node, $indent); }
        if ($kind === Node::KIND_NEW_OBJ)         { return $this->emitNewObj($node, $indent); }
        if ($kind === Node::KIND_PROPERTY_ACCESS) { return $this->emitPropertyAccess($node, $indent); }
        if ($kind === Node::KIND_STORE_PROPERTY)  { return $this->emitStoreProperty($node, $indent); }
        if ($kind === Node::KIND_METHOD_CALL)     { return $this->emitMethodCall($node, $indent); }
        if ($kind === Node::KIND_STATIC_CALL)     { return $this->emitStaticCall($node, $indent); }
        if ($kind === Node::KIND_BLOCK)        { return $this->emitBlock($node, $indent); }
        if ($kind === Node::KIND_MEMORY_OP)    { return $this->emitMemoryOp($node, $indent); }
        throw new \RuntimeException('MIR.dump: unsupported node kind ' . $kind);
    }

    private function emitIntConst(IntConst $node, string $indent): string
    {
        $name = $this->slotFor($node);
        return $indent . $name . ' = int_const ' . (string)$node->value
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitFloatConst(FloatConst $node, string $indent): string
    {
        $name = $this->slotFor($node);
        return $indent . $name . ' = float_const ' . (string)$node->value
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitStringConst(StringConst $node, string $indent): string
    {
        $name = $this->slotFor($node);
        $escaped = $this->escapeForDump($node->value);
        return $indent . $name . ' = string_const "' . $escaped . '"'
             . ' : ' . $node->type->toString() . "\n";
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

    private function emitBoolConst(BoolConst $node, string $indent): string
    {
        $name = $this->slotFor($node);
        $lit = $node->value ? 'true' : 'false';
        return $indent . $name . ' = bool_const ' . $lit
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitNullConst(NullConst $node, string $indent): string
    {
        $name = $this->slotFor($node);
        return $indent . $name . ' = null_const : ' . $node->type->toString() . "\n";
    }

    private function emitLoadLocal(LoadLocal $node, string $indent): string
    {
        $name = $this->slotFor($node);
        return $indent . $name . ' = load_local ' . $node->name
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitStoreLocal(StoreLocal $node, string $indent): string
    {
        $value = $node->value;
        $valChunk = $this->emitNode($value, $indent);
        $valName = $this->lastSlot;
        $name = $this->allocSlot();
        return $valChunk . $indent . $name . ' = store_local ' . $node->name
             . ' <- ' . $valName . ' : ' . $node->type->toString() . "\n";
    }

    private function emitAdd(Add $node, string $indent): string { return $this->emitBin($node, $node->left, $node->right, $node->type, 'add', $indent); }
    private function emitSub(Sub $node, string $indent): string { return $this->emitBin($node, $node->left, $node->right, $node->type, 'sub', $indent); }
    private function emitMul(Mul $node, string $indent): string { return $this->emitBin($node, $node->left, $node->right, $node->type, 'mul', $indent); }
    private function emitDiv(Div $node, string $indent): string { return $this->emitBin($node, $node->left, $node->right, $node->type, 'div', $indent); }
    private function emitMod(Mod $node, string $indent): string { return $this->emitBin($node, $node->left, $node->right, $node->type, 'mod', $indent); }
    private function emitConcat(Concat $node, string $indent): string { return $this->emitBin($node, $node->left, $node->right, $node->type, 'concat', $indent); }

    private function emitBin(Node $node, Node $left, Node $right, Type $type, string $op, string $indent): string
    {
        $lChunk = $this->emitNode($left, $indent);
        $lName = $this->lastSlot;
        $rChunk = $this->emitNode($right, $indent);
        $rName = $this->lastSlot;
        $name = $this->allocSlot();
        return $lChunk . $rChunk . $indent . $name . ' = ' . $op . ' '
             . $lName . ', ' . $rName . ' : ' . $type->toString()
             . $this->eff($node) . "\n";
    }

    private function emitEcho(Echo_ $node, string $indent): string
    {
        $out = '';
        $line = $indent . 'echo ';
        $first = true;
        foreach ($node->exprs as $e) {
            $out .= $this->emitNode($e, $indent);
            if (!$first) { $line .= ', '; }
            $first = false;
            $line .= $this->lastSlot;
        }
        return $out . $line . "\n";
    }

    private function emitReturn(Return_ $node, string $indent): string
    {
        $value = $node->value;
        if ($value === null) {
            return $indent . "return\n";
        }
        $chunk = $this->emitNode($value, $indent);
        return $chunk . $indent . 'return ' . $this->lastSlot . $this->eff($node) . "\n";
    }

    private function emitCall(Call $node, string $indent): string
    {
        $out = '';
        $argLine = '';
        $first = true;
        foreach ($node->args as $a) {
            $out .= $this->emitNode($a, $indent);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $out . $indent . $name . ' = call ' . $node->function
             . '(' . $argLine . ')'
             . ' : ' . $node->type->toString() . $this->eff($node) . "\n";
    }

    private function emitBlock(Block $node, string $indent): string
    {
        $out = '';
        foreach ($node->stmts as $s) {
            $out .= $this->emitNode($s, $indent);
        }
        return $out;
    }

    private function emitMemoryOp(MemoryOp_ $node, string $indent): string
    {
        // Whole-frame arena scope carries no flavor / target.
        $line = $indent . 'mem_' . $node->op;
        if ($node->flavor !== '') {
            $line .= ' ' . $node->flavor;
        }
        $target = $node->target;
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

    private function emitArrayLit(ArrayLit $node, string $indent): string
    {
        $out = '';
        $parts = '';
        $first = true;
        foreach ($node->elements as $el) {
            if ($el->key !== null) {
                $out .= $this->emitNode($el->key, $indent);
                $kName = $this->lastSlot;
            } else {
                $kName = '_';
            }
            $out .= $this->emitNode($el->value, $indent);
            $vName = $this->lastSlot;
            if (!$first) { $parts .= ', '; }
            $first = false;
            $parts .= $kName . ' => ' . $vName;
        }
        $name = $this->allocSlot();
        return $out . $indent . $name . ' = array_lit [' . $parts . ']'
             . ' : ' . $node->type->toString() . $this->eff($node) . "\n";
    }

    private function emitArrayAccess(ArrayAccess_ $node, string $indent): string
    {
        $aChunk = $this->emitNode($node->array, $indent);
        $aName  = $this->lastSlot;
        $iChunk = $this->emitNode($node->index, $indent);
        $iName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $aChunk . $iChunk . $indent . $name . ' = array_access '
             . $aName . '[' . $iName . ']'
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitStoreElement(StoreElement $node, string $indent): string
    {
        $aChunk = $this->emitNode($node->array, $indent);
        $aName  = $this->lastSlot;
        $iChunk = $this->emitNode($node->index, $indent);
        $iName  = $this->lastSlot;
        $vChunk = $this->emitNode($node->value, $indent);
        $vName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $aChunk . $iChunk . $vChunk . $indent . $name . ' = store_element '
             . $aName . '[' . $iName . '] <- ' . $vName
             . ' : ' . $node->type->toString() . $this->eff($node) . "\n";
    }

    private function emitNewObj(NewObj $node, string $indent): string
    {
        $out = '';
        $argLine = '';
        $first = true;
        foreach ($node->args as $a) {
            $out .= $this->emitNode($a, $indent);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $out . $indent . $name . ' = new ' . $node->class
             . '(' . $argLine . ')'
             . ' : ' . $node->type->toString() . $this->eff($node) . "\n";
    }

    private function emitPropertyAccess(PropertyAccess_ $node, string $indent): string
    {
        $oChunk = $this->emitNode($node->object, $indent);
        $oName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $oChunk . $indent . $name . ' = property_access ' . $oName
             . '->' . $node->property
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitStoreProperty(StoreProperty $node, string $indent): string
    {
        $oChunk = $this->emitNode($node->object, $indent);
        $oName  = $this->lastSlot;
        $vChunk = $this->emitNode($node->value, $indent);
        $vName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $oChunk . $vChunk . $indent . $name . ' = store_property '
             . $oName . '->' . $node->property . ' <- ' . $vName
             . ' : ' . $node->type->toString() . $this->eff($node) . "\n";
    }

    private function emitMethodCall(MethodCall_ $node, string $indent): string
    {
        $oChunk = $this->emitNode($node->object, $indent);
        $oName  = $this->lastSlot;
        $argOut = '';
        $argLine = '';
        $first = true;
        foreach ($node->args as $a) {
            $argOut .= $this->emitNode($a, $indent);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $oChunk . $argOut . $indent . $name . ' = method_call '
             . $oName . '->' . $node->method . '(' . $argLine . ')'
             . ' : ' . $node->type->toString() . $this->eff($node) . "\n";
    }

    private function emitStaticCall(StaticCall_ $node, string $indent): string
    {
        $argOut = '';
        $argLine = '';
        $first = true;
        foreach ($node->args as $a) {
            $argOut .= $this->emitNode($a, $indent);
            if (!$first) { $argLine .= ', '; }
            $first = false;
            $argLine .= $this->lastSlot;
        }
        $name = $this->allocSlot();
        return $argOut . $indent . $name . ' = static_call '
             . $node->class . '::' . $node->method . '(' . $argLine . ')'
             . ' : ' . $node->type->toString() . $this->eff($node) . "\n";
    }

    private function emitNeg(Neg $node, string $indent): string
    {
        $chunk = $this->emitNode($node->operand, $indent);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $indent . $name . ' = neg ' . $opName
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitNot(Not_ $node, string $indent): string
    {
        $chunk = $this->emitNode($node->operand, $indent);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $indent . $name . ' = not ' . $opName
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitBitOp(BitOp $node, string $indent): string
    {
        $lChunk = $this->emitNode($node->left, $indent);
        $lName  = $this->lastSlot;
        $rChunk = $this->emitNode($node->right, $indent);
        $rName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $lChunk . $rChunk . $indent . $name . ' = ' . $node->op . ' '
             . $lName . ', ' . $rName . ' : ' . $node->type->toString() . "\n";
    }

    private function emitBitNot(BitNot_ $node, string $indent): string
    {
        $chunk = $this->emitNode($node->operand, $indent);
        $opName = $this->lastSlot;
        $name = $this->allocSlot();
        return $chunk . $indent . $name . ' = bitnot ' . $opName
             . ' : ' . $node->type->toString() . "\n";
    }

    private function emitSpaceship(\Compile\Mir\Spaceship $node, string $indent): string
    {
        $lChunk = $this->emitNode($node->left, $indent);
        $lName  = $this->lastSlot;
        $rChunk = $this->emitNode($node->right, $indent);
        $rName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $lChunk . $rChunk . $indent . $name . ' = spaceship '
             . $lName . ', ' . $rName . ' : ' . $node->type->toString() . "\n";
    }

    private function emitCmp(Cmp $node, string $indent): string
    {
        $lChunk = $this->emitNode($node->left, $indent);
        $lName  = $this->lastSlot;
        $rChunk = $this->emitNode($node->right, $indent);
        $rName  = $this->lastSlot;
        $name   = $this->allocSlot();
        return $lChunk . $rChunk . $indent . $name . ' = cmp ' . $node->op . ' '
             . $lName . ', ' . $rName . ' : ' . $node->type->toString() . "\n";
    }

    private function emitIf(If_ $node, string $indent): string
    {
        $condChunk = $this->emitNode($node->cond, $indent);
        $condName  = $this->lastSlot;
        $inner = $indent . '  ';
        $out = $condChunk . $indent . 'if ' . $condName . " then {\n";
        $out .= $this->emitNode($node->then, $inner);
        $out .= $indent . "}";
        if ($node->else !== null) {
            $out .= " else {\n";
            $out .= $this->emitNode($node->else, $inner);
            $out .= $indent . "}";
        }
        return $out . "\n";
    }

    private function emitWhile(While_ $node, string $indent): string
    {
        $inner = $indent . '  ';
        $condChunk = $this->emitNode($node->cond, $inner);
        $condName  = $this->lastSlot;
        $out = $indent . "while {\n";
        $out .= $condChunk . $inner . 'check ' . $condName . "\n";
        $out .= $this->emitNode($node->body, $inner);
        $out .= $indent . "}\n";
        return $out;
    }

    private string $lastSlot = '%?';

    /**
     * Allocate a fresh slot, cache it as `$lastSlot`, return its
     * spelling. Each emit* returns its chunk and stashes the
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

    private function slotFor(Node $node): string
    {
        // Retained for emit* helpers that produce a single output slot
        // tied to the current node — all of them allocate via
        // {@see allocSlot()} now, so this just forwards.
        return $this->allocSlot();
    }
}
