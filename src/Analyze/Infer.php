<?php

namespace Analyze;

use Parser\Ast\ArrayLit;
use Parser\Ast\BinaryOp;
use Parser\Ast\BoolLiteral;
use Parser\Ast\CallExpr;
use Parser\Ast\Cast;
use Parser\Ast\Expr;
use Parser\Ast\FloatLiteral;
use Parser\Ast\IntLiteral;
use Parser\Ast\MethodCallExpr;
use Parser\Ast\NewExpr;
use Parser\Ast\NullLiteral;
use Parser\Ast\PropertyAccess;
use Parser\Ast\StaticCall;
use Parser\Ast\StringLiteral;
use Parser\Ast\UnaryOp;
use Parser\Ast\Variable;

/**
 * Scope-free expression typing: infers ONLY what is decidable from the
 * expression itself (literals, casts, concatenation, `new`, and calls whose
 * callee is a known user declaration). Everything that would need flow /
 * variable tracking — a `Variable`, a property read, a method call — returns
 * {@see Ty::unknown()}, which every rule treats as "skip".
 *
 * Not tracking variables is a deliberate zero-false-positive choice: it removes
 * closure-capture, shadowing and narrowing from the picture entirely. It costs
 * recall (a mismatch only visible through a typed variable is missed), which a
 * later flow-aware phase can add.
 */
final class Infer
{
    public function __construct(private Index $idx, private ?Scope $scope = null) {}

    public function of(Expr $e): Ty
    {
        if ($e instanceof Variable) {
            return $this->scope === null ? Ty::unknown() : $this->scope->typeOf($e->name);
        }
        if ($e instanceof PropertyAccess) { return $this->propertyTy($e); }
        if ($e instanceof MethodCallExpr) { return $this->methodTy($e); }
        if ($e instanceof IntLiteral) { return new Ty(Ty::KIND_INT); }
        if ($e instanceof FloatLiteral) { return new Ty(Ty::KIND_FLOAT); }
        if ($e instanceof StringLiteral) { return new Ty(Ty::KIND_STRING); }
        if ($e instanceof BoolLiteral) { return new Ty(Ty::KIND_BOOL); }
        if ($e instanceof NullLiteral) { return new Ty(Ty::KIND_NULL, true); }
        if ($e instanceof ArrayLit) { return new Ty(Ty::KIND_ARRAY_); }
        if ($e instanceof Cast) { return $this->castTy($e->cast); }
        if ($e instanceof NewExpr) {
            $c = \strtolower($e->class);
            if ($c === 'self' || $c === 'static' || $c === 'parent') { return Ty::unknown(); }
            return new Ty(Ty::KIND_OBJECT, false, $e->class);
        }
        if ($e instanceof CallExpr) {
            $fn = $this->idx->resolveFunction($e->function);
            return $fn === null ? Ty::unknown() : Ty::fromHint($fn->returnType);
        }
        if ($e instanceof StaticCall) {
            $c = \strtolower($e->class);
            if ($c === 'self' || $c === 'static' || $c === 'parent') { return Ty::unknown(); }
            if ($this->idx->findClass($e->class) === null) { return Ty::unknown(); }
            $m = $this->idx->findMethod($e->class, \strtolower($e->method), 0);
            return $m === null ? Ty::unknown() : Ty::fromHint($m->returnType);
        }
        if ($e instanceof BinaryOp) { return $this->binaryTy($e); }
        if ($e instanceof UnaryOp) { return $this->unaryTy($e); }
        return Ty::unknown();
    }

    private function propertyTy(PropertyAccess $e): Ty
    {
        $recv = $this->of($e->object);
        if ($recv->kind !== Ty::KIND_OBJECT || $recv->className === '') { return Ty::unknown(); }
        $r = $this->idx->findPropertyType($recv->className, $e->property, 0);
        if ($r === null) { return Ty::unknown(); }
        return Ty::fromHint($r->hint);
    }

    private function methodTy(MethodCallExpr $e): Ty
    {
        $recv = $this->of($e->object);
        if ($recv->kind !== Ty::KIND_OBJECT || $recv->className === '') { return Ty::unknown(); }
        if ($this->idx->findClass($recv->className) === null) { return Ty::unknown(); }
        $m = $this->idx->findMethod($recv->className, \strtolower($e->method), 0);
        return $m === null ? Ty::unknown() : Ty::fromHint($m->returnType);
    }

    private function castTy(string $cast): Ty
    {
        $c = \strtolower($cast);
        if ($c === 'int' || $c === 'integer') { return new Ty(Ty::KIND_INT); }
        if ($c === 'float' || $c === 'double') { return new Ty(Ty::KIND_FLOAT); }
        if ($c === 'string' || $c === 'binary') { return new Ty(Ty::KIND_STRING); }
        if ($c === 'bool' || $c === 'boolean') { return new Ty(Ty::KIND_BOOL); }
        if ($c === 'array') { return new Ty(Ty::KIND_ARRAY_); }
        return Ty::unknown();
    }

    private function binaryTy(BinaryOp $e): Ty
    {
        $op = $e->op;
        if ($op === '.') { return new Ty(Ty::KIND_STRING); }
        if ($op === '%' || $op === '&' || $op === '|' || $op === '^'
            || $op === '<<' || $op === '>>') {
            return new Ty(Ty::KIND_INT);
        }
        if ($op === '==' || $op === '===' || $op === '!=' || $op === '!=='
            || $op === '<' || $op === '>' || $op === '<=' || $op === '>='
            || $op === '&&' || $op === '||' || $op === 'and' || $op === 'or'
            || $op === 'xor') {
            return new Ty(Ty::KIND_BOOL);
        }
        if ($op === '+' || $op === '-' || $op === '*') {
            $l = $this->of($e->left);
            $r = $this->of($e->right);
            // `+` on two arrays is the array-union operator — not numeric.
            if ($l->kind === Ty::KIND_ARRAY_ || $r->kind === Ty::KIND_ARRAY_) { return Ty::unknown(); }
            if ($l->kind === Ty::KIND_INT && $r->kind === Ty::KIND_INT) { return new Ty(Ty::KIND_INT); }
            $lNum = $l->kind === Ty::KIND_INT || $l->kind === Ty::KIND_FLOAT;
            $rNum = $r->kind === Ty::KIND_INT || $r->kind === Ty::KIND_FLOAT;
            if ($lNum && $rNum) { return new Ty(Ty::KIND_FLOAT); }
            return Ty::unknown();
        }
        return Ty::unknown();
    }

    private function unaryTy(UnaryOp $e): Ty
    {
        if ($e->op === '!') { return new Ty(Ty::KIND_BOOL); }
        if ($e->op === '~') { return new Ty(Ty::KIND_INT); }
        if ($e->op === '-' || $e->op === '+') {
            $o = $this->of($e->operand);
            if ($o->kind === Ty::KIND_INT || $o->kind === Ty::KIND_FLOAT) { return $o; }
        }
        return Ty::unknown();
    }
}
