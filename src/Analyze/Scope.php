<?php

namespace Analyze;

use Parser\Ast\Assign;
use Parser\Ast\CompoundAssign;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\GlobalStmt;
use Parser\Ast\IfStmt;
use Parser\Ast\IncDec;
use Parser\Ast\RefAssign;
use Parser\Ast\StaticLocalStmt;
use Parser\Ast\Stmt;
use Parser\Ast\SwitchStmt;
use Parser\Ast\TryCatchStmt;
use Parser\Ast\Variable;
use Parser\Ast\WhileStmt;
use Parser\Ast\CallExpr;
use Parser\Ast\StaticCall;

/**
 * Per-{@see Unit} variable→type map. Built flow-INSENSITIVELY: a variable's type
 * is the JOIN of its parameter seed and every plain assignment to it. If those
 * disagree — or the variable is mutated in a way this coarse model can't follow
 * (by-ref, compound-assign, `++`, `foreach` binding, `global`/`static`, a by-ref
 * argument) — the variable degrades to `unknown`, which every rule skips.
 *
 * The point is soundness-for-reporting, not completeness: it never claims a type
 * it might be wrong about, so no rule built on it can raise a false positive from
 * a mistyped variable. Narrowing (`if ($x !== null)`) is intentionally absent —
 * that is a later, flow-sensitive refinement.
 */
final class Scope
{
    /** @var array<string, Ty> */
    public array $vars = [];

    public function typeOf(string $name): Ty
    {
        return $this->vars[$name] ?? Ty::unknown();
    }

    public static function build(Unit $u, Index $idx): Scope
    {
        $s = new Scope();
        foreach ($u->params as $p) {
            $s->vars[$p->name] = $p->variadic ? new Ty(Ty::KIND_ARRAY_) : Ty::fromHint($p->typeHint);
        }
        if ($u->thisClass !== '') {
            $s->vars['this'] = new Ty(Ty::KIND_OBJECT, false, $u->thisClass);
        }

        // Arrow-fn bodies are a single expression with no local declarations —
        // the parameter seed is the whole scope.
        if ($u->exprBody !== null) { return $s; }

        // Infer assignment right-hand sides against the PARAMETER seed only, so
        // the result never depends on statement order.
        $seed = new Scope();
        $seed->vars = $s->vars;
        $infer = new Infer($idx, $seed);

        /** @var array<string, bool> $unreliable */
        $unreliable = [];
        self::scanStmts($u->stmts, $unreliable);

        /** @var array<string, Ty[]> $cand */
        $cand = [];
        $walk = new AstWalk(true);
        $walk->stmts($u->stmts);
        foreach ($walk->exprs as $e) {
            if ($e instanceof Assign && $e->target instanceof Variable) {
                $cand[$e->target->name][] = $infer->of($e->value);
            } elseif ($e instanceof RefAssign && $e->target instanceof Variable) {
                $unreliable[$e->target->name] = true;
            } elseif ($e instanceof CompoundAssign && $e->target instanceof Variable) {
                $unreliable[$e->target->name] = true;
            } elseif ($e instanceof IncDec && $e->operand instanceof Variable) {
                $unreliable[$e->operand->name] = true;
            } elseif ($e instanceof CallExpr) {
                $fn = $idx->resolveFunction($e->function);
                if ($fn !== null) { self::markByRefArgs($fn->params, $e->args, $unreliable); }
            } elseif ($e instanceof StaticCall) {
                $lc = \strtolower($e->class);
                if ($lc !== 'self' && $lc !== 'static' && $lc !== 'parent' && $idx->findClass($e->class) !== null) {
                    $m = $idx->findMethod($e->class, \strtolower($e->method), 0);
                    if ($m !== null) { self::markByRefArgs($m->params, $e->args, $unreliable); }
                }
            }
        }

        foreach ($cand as $name => $types) {
            $joined = $s->vars[$name] ?? null;   // parameter seed, if any
            foreach ($types as $t) {
                $joined = $joined === null ? $t : self::join($joined, $t);
            }
            if ($joined !== null) { $s->vars[$name] = $joined; }
        }
        foreach ($unreliable as $name => $_) {
            $s->vars[$name] = Ty::unknown();
        }
        return $s;
    }

    /**
     * @param \Parser\Ast\Param[] $params
     * @param \Parser\Ast\Expr[]  $args
     * @param array<string, bool> $unreliable
     */
    private static function markByRefArgs(array $params, array $args, array &$unreliable): void
    {
        $n = \count($params);
        $ai = 0;
        foreach ($args as $arg) {
            $p = null;
            if ($ai < $n && !$params[$ai]->variadic) { $p = $params[$ai]; }
            elseif ($n > 0 && $params[$n - 1]->variadic) { $p = $params[$n - 1]; }
            $ai = $ai + 1;
            if ($p === null) { break; }
            if ($p->byRef && $arg instanceof Variable) { $unreliable[$arg->name] = true; }
        }
    }

    /**
     * @param Stmt[]              $stmts
     * @param array<string, bool> $unreliable
     */
    private static function scanStmts(array $stmts, array &$unreliable): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof ForeachStmt) {
                if ($s->value instanceof Variable) { $unreliable[$s->value->name] = true; }
                if ($s->key instanceof Variable) { $unreliable[$s->key->name] = true; }
                self::scanStmts($s->body->statements, $unreliable);
                continue;
            }
            if ($s instanceof GlobalStmt) {
                foreach ($s->names as $nm) { $unreliable[$nm] = true; }
                continue;
            }
            if ($s instanceof StaticLocalStmt) {
                foreach ($s->decls as $d) { $unreliable[$d->name] = true; }
                continue;
            }
            if ($s instanceof IfStmt) {
                self::scanStmts($s->then->statements, $unreliable);
                foreach ($s->elseifs as $arm) { self::scanStmts($arm->body->statements, $unreliable); }
                if ($s->else !== null) { self::scanStmts($s->else->statements, $unreliable); }
                continue;
            }
            if ($s instanceof WhileStmt) { self::scanStmts($s->body->statements, $unreliable); continue; }
            if ($s instanceof DoWhileStmt) { self::scanStmts($s->body->statements, $unreliable); continue; }
            if ($s instanceof ForStmt) { self::scanStmts($s->body->statements, $unreliable); continue; }
            if ($s instanceof TryCatchStmt) {
                self::scanStmts($s->try->statements, $unreliable);
                foreach ($s->catches as $c) { self::scanStmts($c->body->statements, $unreliable); }
                if ($s->finally !== null) { self::scanStmts($s->finally->statements, $unreliable); }
                continue;
            }
            if ($s instanceof SwitchStmt) {
                foreach ($s->cases as $arm) { self::scanStmts($arm->body, $unreliable); }
                continue;
            }
        }
    }

    /** Join two types: same kind (and, for objects, same class) survives; else unknown. */
    private static function join(Ty $a, Ty $b): Ty
    {
        if ($a->kind !== $b->kind) { return Ty::unknown(); }
        if ($a->kind === Ty::KIND_OBJECT && $a->className !== $b->className) { return Ty::unknown(); }
        return new Ty($a->kind, $a->nullable || $b->nullable, $a->className);
    }
}
