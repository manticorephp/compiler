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

    /**
     * Run ONLY the array-representation conflict check (see
     * {@see arrayReprConflict}) and skip every other rule.
     *
     * That check is exempt from the "off by default" reasoning that gates the
     * rest of this pass: it mirrors the codegen's own key-reader choice, so a
     * hit is not a style opinion but a buffer read at the wrong type — a silent
     * SIGSEGV. Measured at ZERO hits across the whole 571-case AOT corpus and
     * the self-host build, so it costs the existing corpus nothing.
     */
    public bool $reprOnly = false;

    /** @var array<string, \Compile\Mir\Param[]> fn / `Class__method` name → params */
    private array $paramsByFn = [];

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->paramsByFn[$fn->name] = $fn->params;
        }
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            if (!$this->reprOnly) { $this->checkReturns($fn); }
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
            if (!$this->reprOnly) { $this->checkArith($n, $inFn); }
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
            if (!$this->reprOnly && $this->incompatible($arg->type, $p->type)) {
                $this->errors[] = $this->at($arg) . $inFn . '(): argument ' . (string)($ai + 1) . ' to '
                    . $label . '() — ' . $arg->type->toString()
                    . ' given, ' . $p->type->toString() . ' expected';
                continue;
            }
            $why = $this->arrayReprConflict($arg->type, $p->type);
            if ($why !== null) {
                $this->errors[] = $this->at($arg) . $inFn . '(): argument ' . (string)($ai + 1) . ' to '
                    . $label . '() — ' . $why . ' (' . $arg->type->toString()
                    . ' given, ' . $p->type->toString() . ' expected)';
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

    /**
     * Array-vs-array REPRESENTATION conflict at a call boundary, or null.
     *
     * The declared param type decides how the CALLEE reads the buffer, so a
     * disagreement is not a style nit — it is a wild read. A packed (int-keyed)
     * array handed to a param declared `array<string,V>` makes the callee walk
     * an int as a string pointer: a silent SIGSEGV, the exact trap that cost a
     * session on `http_build_query`. Same for the element repr.
     *
     * Deliberately narrow — it fires only when BOTH sides are informative:
     * a cell/unknown on either side is the erased case the call site already
     * coerces (`emitCellifyArrayRaw`) or is simply not known well enough here.
     */
    private function arrayReprConflict(Type $arg, Type $param): ?string
    {
        if ($arg->kind !== Type::KIND_ARRAY || $param->kind !== Type::KIND_ARRAY) { return null; }
        $ak = $this->keyKindOf($arg);
        $pk = $this->keyKindOf($param);
        if ($ak !== null && $pk !== null && $ak !== $pk) {
            return 'array KEY repr conflict — a ' . $this->kindName($ak)
                . '-keyed array read as ' . $this->kindName($pk) . '-keyed walks the key as the wrong type';
        }
        // `concrete()`, not `reprConcrete()`: a UNION element (`obj<A>|obj<B>`)
        // has its own kind but the same pointer repr as either arm, so it must
        // not read as a conflict — the self-host parser legitimately passes
        // `vec[obj<Ellipsis>|obj<Expr>]` to a `vec[obj<Expr>]` param. Same
        // reason obj↔obj is skipped by `incompatible()`: subtyping is not modelled.
        $ae = $arg->element;
        $pe = $param->element;
        if ($ae !== null && $pe !== null
            && $this->concrete($ae) && $this->concrete($pe)
            && $ae->kind !== $pe->kind) {
            return 'array ELEMENT repr conflict — ' . $this->kindName($ae->kind)
                . ' elements read as ' . $this->kindName($pe->kind);
        }
        return null;
    }

    /**
     * The key KIND an array is READ with, or null when the read is TAG-DISPATCHED
     * (and so accepts either kind).
     *
     * This must mirror the codegen's key-reader choice exactly — `emitForeach`
     * (EmitLlvmControl) picking `__mir_array_key_cell_at` over
     * `__mir_array_key_at`, and its inference twin in `InferNodes::inferForeach`.
     * That choice keys off the ELEMENT, not the declared key: a vec whose
     * element is cell/unknown reads keys tag-aware, which is precisely why
     * `array<mixed,mixed>` (which lowers to vec[cell], NOT a cell-keyed assoc —
     * `isAssoc()` means a STRING key) accepts an int-keyed array at all.
     *
     * So only two shapes are genuinely committed to one key repr: a vec with a
     * CONCRETE element (packed, implicit 0..n-1) and a string-keyed assoc.
     */
    private function keyKindOf(Type $t): ?string
    {
        if (!$t->isArray()) { return null; }
        $e = $t->element;
        if ($e === null || !$this->reprConcrete($e)) { return null; }
        if (!$t->isAssoc()) { return Type::KIND_INT; }
        $k = $t->key;
        if ($k === null || !$this->reprConcrete($k)) { return null; }
        return $k->kind;
    }

    /** A repr we can hold the call site to (cell/unknown are the coerced cases). */
    private function reprConcrete(Type $t): bool
    {
        return $t->kind !== Type::KIND_CELL && $t->kind !== Type::KIND_UNKNOWN
            && $t->kind !== Type::KIND_NULL;
    }

    private function kindName(string $k): string
    {
        if ($k === Type::KIND_INT) { return 'int'; }
        if ($k === Type::KIND_STRING) { return 'string'; }
        if ($k === Type::KIND_FLOAT) { return 'float'; }
        if ($k === Type::KIND_BOOL) { return 'bool'; }
        if ($k === Type::KIND_ARRAY) { return 'array'; }
        if ($k === Type::KIND_OBJ) { return 'object'; }
        return 'value';
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
