<?php

namespace Codegen\Llvm;

/**
 * Labeled basic block. A linear sequence of instructions terminated by a
 * branch / return / unreachable.
 *
 * Instructions are appended one at a time; the block tracks its SSA-temp
 * counter so anonymous results get unique names like `t1`, `t2`, ...
 *
 * Plain string concatenation only — no `{$obj->prop}` interpolation,
 * because the current AOT compiler does not reliably expand that form.
 */
final class Block
{
    /** @var string[] */
    private array $lines = [];

    /**
     * Pending phi nodes — formatted lazily because each one's
     * incoming-edge list is filled in after the block has already
     * registered the phi. {@see emit()} interleaves the phis back
     * into the line stream at their original positions.
     *
     * @var PhiNode[]
     */
    private array $phis = [];

    /** @var array<int, int>   $lines index → phi index */
    private array $phiAt = [];

    public function __construct(
        public readonly string $label,
        private readonly FunctionDef $func,
    ) {}

    public function fresh(string $hint = 't'): string
    {
        $this->func->tempCounter = $this->func->tempCounter + 1;
        return $hint . (string)$this->func->tempCounter;
    }

    // ── Memory ───────────────────────────────────────────────────────────────

    public function alloca(Type $type, ?string $name = null): Value
    {
        if ($name === null) {
            $name = $this->fresh('a');
        }
        $this->lines[] = '  %' . $name . ' = alloca ' . $type->text;
        return Value::reg(Type::ptr(), $name);
    }

    public function load(Type $type, Value $ptr, ?string $name = null): Value
    {
        if ($name === null) {
            $name = $this->fresh('l');
        }
        $this->lines[] = '  %' . $name . ' = load ' . $type->text . ', ' . $ptr->typed();
        return Value::reg($type, $name);
    }

    public function store(Value $value, Value $ptr): void
    {
        $this->lines[] = '  store ' . $value->typed() . ', ' . $ptr->typed();
    }

    /** @param Value[] $indices */
    public function gep(Type $elemType, Value $base, array $indices): Value
    {
        $name = $this->fresh('gep');
        $parts = [];
        foreach ($indices as $idx) {
            $parts[] = $idx->typed();
        }
        $this->lines[] = '  %' . $name . ' = getelementptr inbounds ' . $elemType->text
            . ', ' . $base->typed() . ', ' . implode(', ', $parts);
        return Value::reg(Type::ptr(), $name);
    }

    // ── Arithmetic (integer) ─────────────────────────────────────────────────

    private function binop(string $opcode, Value $a, Value $b): Value
    {
        $sp = strpos($opcode, ' ');
        $hint = strtolower($sp === false ? $opcode : substr($opcode, 0, $sp));
        $name = $this->fresh($hint);
        $this->lines[] = '  %' . $name . ' = ' . $opcode . ' ' . $a->type->text
            . ' ' . $a->operand . ', ' . $b->operand;
        return Value::reg($a->type, $name);
    }

    public function add(Value $a, Value $b): Value  { return $this->binop('add nsw', $a, $b); }
    public function sub(Value $a, Value $b): Value  { return $this->binop('sub nsw', $a, $b); }
    public function mul(Value $a, Value $b): Value  { return $this->binop('mul nsw', $a, $b); }
    public function sdiv(Value $a, Value $b): Value { return $this->binop('sdiv', $a, $b); }
    public function srem(Value $a, Value $b): Value { return $this->binop('srem', $a, $b); }
    public function and_(Value $a, Value $b): Value { return $this->binop('and', $a, $b); }
    public function or_(Value $a, Value $b): Value  { return $this->binop('or', $a, $b); }
    public function xor_(Value $a, Value $b): Value { return $this->binop('xor', $a, $b); }
    public function shl(Value $a, Value $b): Value  { return $this->binop('shl', $a, $b); }
    public function lshr(Value $a, Value $b): Value { return $this->binop('lshr', $a, $b); }
    public function ashr(Value $a, Value $b): Value { return $this->binop('ashr', $a, $b); }

    public function icmp(string $pred, Value $a, Value $b): Value
    {
        $name = $this->fresh('cmp');
        $this->lines[] = '  %' . $name . ' = icmp ' . $pred . ' ' . $a->type->text
            . ' ' . $a->operand . ', ' . $b->operand;
        return Value::reg(Type::i1(), $name);
    }

    // ── Arithmetic (floating point) ──────────────────────────────────────────

    private function fbinop(string $opcode, Value $a, Value $b): Value
    {
        $name = $this->fresh($opcode);
        $this->lines[] = '  %' . $name . ' = ' . $opcode . ' ' . $a->type->text
            . ' ' . $a->operand . ', ' . $b->operand;
        return Value::reg($a->type, $name);
    }

    public function fadd(Value $a, Value $b): Value { return $this->fbinop('fadd', $a, $b); }
    public function fsub(Value $a, Value $b): Value { return $this->fbinop('fsub', $a, $b); }
    public function fmul(Value $a, Value $b): Value { return $this->fbinop('fmul', $a, $b); }
    public function fdiv(Value $a, Value $b): Value { return $this->fbinop('fdiv', $a, $b); }
    public function frem(Value $a, Value $b): Value { return $this->fbinop('frem', $a, $b); }

    /** fcmp <pred> <ty> <a>, <b> — pred: oeq, one, olt, ole, ogt, oge, ord, uno, ueq, une, etc. */
    public function fcmp(string $pred, Value $a, Value $b): Value
    {
        $name = $this->fresh('fcmp');
        $this->lines[] = '  %' . $name . ' = fcmp ' . $pred . ' ' . $a->type->text
            . ' ' . $a->operand . ', ' . $b->operand;
        return Value::reg(Type::i1(), $name);
    }

    // ── Conversions ──────────────────────────────────────────────────────────

    private function castOp(string $opcode, Value $value, Type $to): Value
    {
        $name = $this->fresh($opcode);
        $this->lines[] = '  %' . $name . ' = ' . $opcode . ' ' . $value->type->text
            . ' ' . $value->operand . ' to ' . $to->text;
        return Value::reg($to, $name);
    }

    public function zext(Value $v, Type $to): Value      { return $this->castOp('zext', $v, $to); }
    public function sext(Value $v, Type $to): Value      { return $this->castOp('sext', $v, $to); }
    public function trunc(Value $v, Type $to): Value     { return $this->castOp('trunc', $v, $to); }
    public function sitofp(Value $v, Type $to): Value    { return $this->castOp('sitofp', $v, $to); }
    public function uitofp(Value $v, Type $to): Value    { return $this->castOp('uitofp', $v, $to); }
    public function fptosi(Value $v, Type $to): Value    { return $this->castOp('fptosi', $v, $to); }
    public function fptoui(Value $v, Type $to): Value    { return $this->castOp('fptoui', $v, $to); }
    public function fpext(Value $v, Type $to): Value     { return $this->castOp('fpext', $v, $to); }
    public function fptrunc(Value $v, Type $to): Value   { return $this->castOp('fptrunc', $v, $to); }
    public function bitcast(Value $v, Type $to): Value   { return $this->castOp('bitcast', $v, $to); }
    public function ptrtoint(Value $v, Type $to): Value  { return $this->castOp('ptrtoint', $v, $to); }
    public function inttoptr(Value $v, Type $to): Value  { return $this->castOp('inttoptr', $v, $to); }

    // ── Phi / select ─────────────────────────────────────────────────────────

    /**
     * Open a phi node. Returns a {@see PhiNode} the caller can grow with
     * `addIncoming()` until the block is emitted.
     *
     * Use {@see PhiNode::value()} to get the SSA-result operand for use
     * in later instructions.
     */
    public function phi(Type $type, ?string $name = null): PhiNode
    {
        if ($name === null) {
            $name = $this->fresh('phi');
        }
        $node = new PhiNode($name, $type);
        $this->phis[] = $node;
        // Embed a sentinel directly into the line stream. emit() swaps
        // it for the freshly-rendered phi text. Avoids relying on
        // sparse-int-keyed assoc arrays which mis-behave under
        // self-host (`isset($map[$i])` returns false on vec-backed
        // sparse keys).
        $idx = count($this->phis) - 1;
        $this->lines[] = "__PHI__:" . (string)$idx;
        return $node;
    }

    /**
     * `select i1 <cond>, <ty> <ifTrue>, <ty> <ifFalse>`
     */
    public function select(Value $cond, Value $ifTrue, Value $ifFalse, ?string $name = null): Value
    {
        if ($name === null) {
            $name = $this->fresh('sel');
        }
        $this->lines[] = '  %' . $name . ' = select i1 ' . $cond->operand
            . ', ' . $ifTrue->typed() . ', ' . $ifFalse->typed();
        return Value::reg($ifTrue->type, $name);
    }

    // ── Control flow ─────────────────────────────────────────────────────────

    public function br(Block $target): void
    {
        $this->lines[] = '  br label %' . $target->label;
    }

    /**
     * Replace this block's terminator (`br label %X`) with a jump to
     * `$newTarget`. Used by ternary-arm coercion when an arm has
     * already branched to the end block but we need to route through
     * a fresh "convert" block first.
     */
    public function retargetTerminator(Block $newTarget): void
    {
        $count = count($this->lines);
        if ($count === 0) {
            throw new \RuntimeException('Block::retargetTerminator: block is empty');
        }
        // Indexed access — self-host's `array_pop` returns garbage for
        // string-typed vec elements. Manual pop via index + truncation.
        $last = $this->lines[$count - 1];
        if (!\str_starts_with(\ltrim($last), 'br ')) {
            throw new \RuntimeException(
                'Block::retargetTerminator: last instruction is not a branch (got: ' . $last . ')'
            );
        }
        $this->lines[$count - 1] = '  br label %' . $newTarget->label;
    }

    public function brIf(Value $cond, Block $thenB, Block $elseB): void
    {
        $this->lines[] = '  br i1 ' . $cond->operand
            . ', label %' . $thenB->label
            . ', label %' . $elseB->label;
    }

    public function ret(Value $value): void
    {
        $this->lines[] = '  ret ' . $value->typed();
    }

    public function retVoid(): void
    {
        $this->lines[] = '  ret void';
    }

    public function unreachable(): void
    {
        $this->lines[] = '  unreachable';
    }

    /**
     * `switch <ty> <value>, label %<default> [ <ty> <case>, label %<dest> ... ]`.
     *
     * @param SwitchCase[] $cases
     */
    public function switch_(Value $value, Block $default, array $cases): void
    {
        $parts = [];
        foreach ($cases as $c) {
            $parts[] = $c->value->typed() . ', label %' . $c->dest->label;
        }
        // LLVM IR grammar requires `[ ]` even for empty case lists.
        $body = ' [ ' . implode(' ', $parts) . ' ]';
        $this->lines[] = '  switch ' . $value->typed() . ', label %' . $default->label . $body;
    }

    // ── Exception handling ───────────────────────────────────────────────────

    /**
     * `invoke <ret> @<name>(<args>) to label %<normal> unwind label %<unwind>`.
     * Use for calls that may throw; pair with a landing pad in `$unwind`.
     */
    /** @param Value[] $args */
    public function invoke(
        string $globalName,
        Type $retType,
        array $args,
        Block $normal,
        Block $unwind,
        ?string $name = null,
    ): Value {
        $parts = [];
        foreach ($args as $v) {
            $parts[] = $v->typed();
        }
        $argList = implode(', ', $parts);
        if ($retType->text === 'void') {
            $this->lines[] = '  invoke void @' . $globalName . '(' . $argList
                . ') to label %' . $normal->label . ' unwind label %' . $unwind->label;
            return Value::reg(Type::void(), '');
        }
        if ($name === null) {
            $name = $this->fresh('invk');
        }
        $this->lines[] = '  %' . $name . ' = invoke ' . $retType->text
            . ' @' . $globalName . '(' . $argList . ')'
            . ' to label %' . $normal->label . ' unwind label %' . $unwind->label;
        return Value::reg($retType, $name);
    }

    /**
     * `landingpad { ptr, i32 } catch ptr null`. Returns a `{ ptr, i32 }`
     * struct-typed value representing the unwind info.
     *
     * For MVP we emit a catch-all (`catch ptr null`). Richer clauses (typed
     * catch, filter) come later.
     */
    public function landingpad(?string $name = null): Value
    {
        if ($name === null) {
            $name = $this->fresh('lp');
        }
        $this->lines[] = '  %' . $name . ' = landingpad { ptr, i32 } catch ptr null';
        return Value::reg(Type::raw('{ ptr, i32 }'), $name);
    }

    /**
     * `resume { ptr, i32 } <value>` — rethrow an unwind in progress.
     */
    public function resume(Value $value): void
    {
        $this->lines[] = '  resume ' . $value->typed();
    }

    // ── Calls ────────────────────────────────────────────────────────────────

    /**
     * `call <ret> @<name>(<args>...)`. Set `$signature` to the inline
     * function-type string (`'(ptr, ...)'`) for variadic targets. For
     * non-variadic calls leave it null and LLVM infers from the declare.
     */
    /** @param Value[] $args */
    public function call(
        string $globalName,
        Type $retType,
        array $args,
        ?string $name = null,
        ?string $signature = null,
    ): Value {
        $parts = [];
        foreach ($args as $v) {
            $parts[] = $v->typed();
        }
        $argList = implode(', ', $parts);
        $sig = '';
        if ($signature !== null) {
            $sig = ' ' . $signature;
        }
        if ($retType->text === 'void') {
            $this->lines[] = '  call void' . $sig . ' @' . $globalName . '(' . $argList . ')';
            return Value::reg(Type::void(), '');
        }
        if ($name === null) {
            $name = $this->fresh('call');
        }
        $this->lines[] = '  %' . $name . ' = call ' . $retType->text . $sig
            . ' @' . $globalName . '(' . $argList . ')';
        return Value::reg($retType, $name);
    }

    /**
     * @param Type[]  $argTypes
     * @param Value[] $args
     */
    public function callIndirect(Value $fnPtr, Type $retType, array $argTypes, array $args, ?string $name = null): Value
    {
        $tps = [];
        foreach ($argTypes as $t) {
            $tps[] = $t->text;
        }
        $sigParams = implode(', ', $tps);
        $argParts = [];
        foreach ($args as $v) {
            $argParts[] = $v->typed();
        }
        $argList = implode(', ', $argParts);
        if ($retType->text === 'void') {
            $this->lines[] = '  call ' . $retType->text . ' (' . $sigParams . ') '
                . $fnPtr->operand . '(' . $argList . ')';
            return Value::reg(Type::void(), '');
        }
        if ($name === null) {
            $name = $this->fresh('icall');
        }
        $this->lines[] = '  %' . $name . ' = call ' . $retType->text . ' (' . $sigParams . ') '
            . $fnPtr->operand . '(' . $argList . ')';
        return Value::reg($retType, $name);
    }

    // ── Misc ─────────────────────────────────────────────────────────────────

    public function raw(string $line): void
    {
        $this->lines[] = '  ' . ltrim($line);
    }

    public function emit(): string
    {
        $out = $this->label . ":\n";
        foreach ($this->lines as $line) {
            if (\str_starts_with($line, '__PHI__:')) {
                $idx = (int)\substr($line, 8);
                $out = $out . $this->phis[$idx]->emit() . "\n";
                continue;
            }
            $out = $out . $line . "\n";
        }
        return $out;
    }
}
