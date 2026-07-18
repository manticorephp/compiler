<?php

namespace Analyze\Rules;

use Analyze\Diagnostic;
use Analyze\FlowWalk;
use Analyze\Index;
use Analyze\Infer;
use Analyze\ParsedFile;
use Analyze\Ty;
use Analyze\Units;
use Parser\Ast\CallExpr;
use Parser\Ast\DynamicStaticCall;
use Parser\Ast\Ellipsis;
use Parser\Ast\Expr;
use Parser\Ast\MethodCallExpr;
use Parser\Ast\NamedArg;
use Parser\Ast\NewExpr;
use Parser\Ast\Param;
use Parser\Ast\Spread;
use Parser\Ast\StaticCall;

/**
 * Flags an argument whose PROVABLE type is incompatible with the declared
 * parameter type under strict_types (no scalar juggling; `int`→`float` widening
 * only) — the same boundary phpstan/psalm enforce. Callees resolved to a user
 * declaration: free function, `new` constructor, static method, or an instance
 * method on a receiver whose class the flow scope knows. An unknown/dynamic
 * callee, a named/spread/FCC call list, an unhinted parameter, or an unprovable
 * argument type are all skipped.
 */
final class ArgType
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf, Index $idx): array
    {
        $units = new Units();
        $units->collect($pf->program->statements);
        foreach ($units->units as $u) {
            $flow = new FlowWalk($idx);
            $flow->walkUnit($u);
            $count = \count($flow->exprs);
            $k = 0;
            while ($k < $count) {
                $e = $flow->exprs[$k];
                $infer = new Infer($idx, $flow->scopes[$k]);
                $k = $k + 1;
                if ($e instanceof CallExpr) {
                    if (!$this->countable($e->args)) { continue; }
                    $fn = $idx->resolveFunction($e->function);
                    if ($fn === null) { continue; }
                    $this->verify($pf, $idx, $infer, $e->function . '()', $fn->params, $e->args);
                } elseif ($e instanceof NewExpr) {
                    if (!$this->countable($e->args)) { continue; }
                    $cls = \strtolower($e->class);
                    if ($cls === 'self' || $cls === 'static' || $cls === 'parent') { continue; }
                    if ($idx->findClass($e->class) === null) { continue; }
                    $ctor = $idx->findMethod($e->class, '__construct', 0);
                    if ($ctor === null) { continue; }
                    $this->verify($pf, $idx, $infer, 'new ' . $e->class, $ctor->params, $e->args);
                } elseif ($e instanceof StaticCall) {
                    if (!$this->countable($e->args)) { continue; }
                    $cls = \strtolower($e->class);
                    if ($cls === 'self' || $cls === 'static' || $cls === 'parent') { continue; }
                    if ($idx->findClass($e->class) === null) { continue; }
                    $m = $idx->findMethod($e->class, \strtolower($e->method), 0);
                    if ($m === null) { continue; }
                    $this->verify($pf, $idx, $infer, $e->class . '::' . $e->method . '()', $m->params, $e->args);
                } elseif ($e instanceof MethodCallExpr) {
                    if (!$this->countable($e->args)) { continue; }
                    $recv = $infer->of($e->object);
                    if ($recv->kind !== Ty::KIND_OBJECT || $recv->className === '') { continue; }
                    if ($idx->findClass($recv->className) === null) { continue; }
                    $m = $idx->findMethod($recv->className, \strtolower($e->method), 0);
                    if ($m === null) { continue; }
                    $this->verify($pf, $idx, $infer, $recv->className . '->' . $e->method . '()', $m->params, $e->args);
                } elseif ($e instanceof DynamicStaticCall) {
                    // Dynamic static dispatch `$obj::method()` — resolvable when the
                    // flow scope knows the receiver's class.
                    if (!$this->countable($e->args)) { continue; }
                    $recv = $infer->of($e->receiver);
                    if ($recv->kind !== Ty::KIND_OBJECT || $recv->className === '') { continue; }
                    if ($idx->findClass($recv->className) === null) { continue; }
                    $m = $idx->findMethod($recv->className, \strtolower($e->method), 0);
                    if ($m === null) { continue; }
                    $this->verify($pf, $idx, $infer, $recv->className . '::' . $e->method . '()', $m->params, $e->args);
                }
            }
        }
        return $this->diags;
    }

    /**
     * @param Param[] $params
     * @param Expr[]  $args
     */
    private function verify(ParsedFile $pf, Index $idx, Infer $infer, string $label, array $params, array $args): void
    {
        $nParams = \count($params);
        $ai = 0;
        foreach ($args as $arg) {
            $p = null;
            if ($ai < $nParams && !$params[$ai]->variadic) {
                $p = $params[$ai];
            } elseif ($nParams > 0 && $params[$nParams - 1]->variadic) {
                $p = $params[$nParams - 1];
            }
            $ai = $ai + 1;
            if ($p === null) { break; }

            $target = Ty::fromHint($p->typeHint);
            $src = $infer->of($arg);
            if (Ty::assignable($target, $src, $idx)) { continue; }
            $this->diags[] = Diagnostic::error(
                $pf->path, $arg->span->line, $arg->span->column, 'arg.type',
                'argument ' . (string)$ai . ' of ' . $label . ': ' . $src->display()
                    . ' given, ' . $target->display() . ' expected'
            );
        }
    }

    /** @param Expr[] $args */
    private function countable(array $args): bool
    {
        foreach ($args as $a) {
            if ($a instanceof NamedArg || $a instanceof Spread || $a instanceof Ellipsis) {
                return false;
            }
        }
        return true;
    }
}
