<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Call;
use Compile\Mir\FunctionDef;
use Compile\Mir\MethodCall_;
use Compile\Mir\Module;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * Gated compile-time type checker (`MANTICORE_TYPECHECK=1`). Reports
 * GENUINELY-incompatible type uses — the kind PHP rejects even with
 * strict_types off — at two boundaries:
 *   - a call argument vs the callee parameter type, and
 *   - a `return` value vs the declared return type.
 *
 * Conservative on purpose (no false positives on a loosely-typed corpus or the
 * self-host build): a check fires only when BOTH sides are a concrete kind
 * (int/float/string/bool/array/obj) and they disagree on array-ness or
 * object-ness. Anything `unknown` / `cell` (mixed) / `null` / `void` / closure
 * on either side is skipped, as is obj↔obj (subtyping not modelled) and
 * scalar↔scalar (PHP coerces). Off by default — the driver runs it only when
 * the env flag is set and decides fatality from {@see $errors}.
 */
final class TypeCheck
{
    /** @var string[] collected, human-readable type errors */
    public array $errors = [];

    /** @var array<string, \Compile\Mir\Param[]> fn / `Class__method` name → params */
    private array $paramsByFn = [];

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->paramsByFn[$fn->name] = $fn->params;
        }
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $this->checkReturns($fn);
            $this->checkNode($fn->body, $fn->name);
        }
        return $module;
    }

    private function checkNode(Node $n, string $inFn): void
    {
        // $recv = 1 when the receiver (`this`) is IMPLICIT (not in args) so arg
        // index i maps to param i+1. A free function has no receiver; a static
        // call (incl. `parent::__construct`) already passes `this` explicitly in
        // args, so both align at offset 0.
        if ($n->kind === Node::KIND_ADD || $n->kind === Node::KIND_SUB || $n->kind === Node::KIND_MUL
            || $n->kind === Node::KIND_DIV || $n->kind === Node::KIND_MOD) {
            $this->checkArith($n, $inFn);
        }
        if ($n->kind === Node::KIND_CALL) {
            $this->checkArgs($n->function, $n->function, $n->args, $inFn, 0);
        } elseif ($n->kind === Node::KIND_NEW_OBJ) {
            $this->checkArgs($n->class . '____construct', 'new ' . $n->class, $n->args, $inFn, 1);
        } elseif ($n->kind === Node::KIND_STATIC_CALL) {
            $this->checkArgs($n->class . '__' . $n->method, $n->class . '::' . $n->method, $n->args, $inFn, 0);
        } elseif ($n->kind === Node::KIND_METHOD_CALL) {
            $cls = $n->object->type->class ?? '';
            if ($cls !== '') {
                $this->checkArgs($cls . '__' . $n->method, $cls . '->' . $n->method, $n->args, $inFn, 1);
            }
        }
        foreach (Walk::children($n) as $c) { $this->checkNode($c, $inFn); }
    }

    /**
     * @param Node[] $args
     *
     * Map each positional arg to its declared param. An implicit leading
     * `this` param (instance methods / ctors) is skipped via an offset so the
     * arg/param indices line up. An unresolved callee (builtin, dynamic,
     * inherited method on a parent class) is skipped — no callee, no check.
     */
    private function checkArgs(string $fnName, string $label, array $args, string $inFn, int $recv): void
    {
        $params = $this->paramsByFn[$fnName] ?? null;
        if ($params === null) { return; }
        // Apply the implicit-receiver offset only when the callee actually has a
        // leading `this` param (guards a malformed resolution).
        $offset = 0;
        if ($recv === 1 && \count($params) > 0 && $params[0]->name === 'this') { $offset = 1; }
        foreach ($args as $ai => $arg) {
            $pi = $ai + $offset;
            if (!isset($params[$pi])) { break; }
            $p = $params[$pi];
            if ($p->variadic) { break; }
            if ($this->incompatible($arg->type, $p->type)) {
                $this->errors[] = $this->at($arg) . $inFn . '(): argument ' . (string)($ai + 1) . ' to '
                    . $label . '() — ' . $arg->type->toString()
                    . ' given, ' . $p->type->toString() . ' expected';
            }
        }
    }

    /**
     * Strict arithmetic: `+ - * / %` on a DEFINITELY-string operand is rejected
     * (Manticore follows strict_types and a bit beyond — a numeric string is not
     * silently coerced to a number; cast explicitly). Only fires when an operand
     * is statically KIND_STRING — a `cell`/`unknown`/scalar operand is left
     * alone, so the self-host corpus (which never does string arithmetic) is
     * unaffected. The `.` concat operator is the string path and is not checked.
     */
    // Add/Sub/Mul/Div/Mod share the (left, right) layout — typing the param as
    // Add (the load-bearing-subclass idiom; identical offsets) lets the field
    // reads resolve. The dispatch only ever calls this for the five arith kinds.
    private function checkArith(\Compile\Mir\Add $n, string $inFn): void
    {
        $bad = $n->left->type->kind === Type::KIND_STRING
            || $n->right->type->kind === Type::KIND_STRING;
        if ($bad) {
            $op = $this->arithOp($n->kind);
            $this->errors[] = $this->at($n) . 'arithmetic (`' . $op
                . '`) on a string operand in ' . $inFn
                . '() — cast explicitly ((int)/(float)) to compute on it';
        }
    }

    private function arithOp(string $kind): string
    {
        if ($kind === Node::KIND_ADD) { return '+'; }
        if ($kind === Node::KIND_SUB) { return '-'; }
        if ($kind === Node::KIND_MUL) { return '*'; }
        if ($kind === Node::KIND_DIV) { return '/'; }
        return '%';
    }

    /** `line N: error: ` prefix from a node's stamped source line (0 → ''). */
    private function at(Node $n): string
    {
        return $n->line > 0 ? 'line ' . (string)$n->line . ': error: ' : 'error: ';
    }

    private function checkReturns(FunctionDef $fn): void
    {
        // A generator's `return X` sets the FINAL value (getReturn), not the
        // declared `Generator` return type — never a mismatch.
        if ($fn->isGenerator) { return; }
        $rt = $fn->returnType;
        if (!$this->concrete($rt)) { return; }
        $this->checkReturnNodes($fn->body, $rt, $fn->name);
    }

    private function checkReturnNodes(Node $n, Type $rt, string $fnName): void
    {
        if ($n->kind === Node::KIND_RETURN) {
            if ($n->value !== null && $this->incompatible($n->value->type, $rt)) {
                $this->errors[] = $this->at($n) . $fnName . '(): return ' . $n->value->type->toString()
                    . ' incompatible with declared ' . $rt->toString();
            }
        }
        foreach (Walk::children($n) as $c) { $this->checkReturnNodes($c, $rt, $fnName); }
    }

    /**
     * True when both types are concrete and disagree on ARRAY-ness (a compound
     * where a scalar/object is expected, or vice versa) — the reliably-inferred
     * incompatibility. Object↔scalar is intentionally NOT flagged yet: our
     * inference is too imprecise on polymorphic AST field reads (a node's
     * `->value` is int for one subclass, an Expr for another → spurious
     * `int given, obj expected`) and FFI deliberately passes `Ffi\Ptr` as a
     * string. Scalar↔scalar is always allowed (PHP coerces, non-strict).
     */
    private function incompatible(Type $a, Type $b): bool
    {
        if (!$this->concrete($a) || !$this->concrete($b)) { return false; }
        $aArr = $a->kind === Type::KIND_ARRAY;
        $bArr = $b->kind === Type::KIND_ARRAY;
        return $aArr !== $bArr;
    }

    /** A kind we can reason about (excludes unknown / cell / null / void / closure). */
    private function concrete(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL
            || $k === Type::KIND_ARRAY || $k === Type::KIND_OBJ;
    }
}
