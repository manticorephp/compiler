<?php

namespace Analyze\Rules;

use Analyze\Builtins;
use Analyze\Diagnostic;
use Analyze\FlowWalk;
use Analyze\Index;
use Analyze\Infer;
use Analyze\ParsedFile;
use Analyze\Ty;
use Analyze\Units;
use Parser\Ast\CallExpr;
use Parser\Ast\Expr;
use Parser\Ast\MethodCallExpr;
use Parser\Ast\NamedArg;
use Parser\Ast\NewExpr;
use Parser\Ast\Param;
use Parser\Ast\Spread;
use Parser\Ast\StaticCall;
use Parser\Ast\StringLiteral;

/**
 * Warns when a string literal that names no known class is passed to a parameter documented
 * `class-string` / `class-string<T>`. Dynamic strings are not reported. Whole-project mode only
 * (the class may live in a file the run did not see).
 */
final class ClassStringArg
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
                    $fn = $idx->resolveFunction($e->function);
                    if ($fn !== null) { $this->verify($pf, $idx, $fn->params, $fn->paramDocs, $fn->tpl, $e->args); }
                } elseif ($e instanceof NewExpr) {
                    $cls = \strtolower($e->class);
                    if ($cls === 'self' || $cls === 'static' || $cls === 'parent') { continue; }
                    $m = $idx->findClass($e->class) === null ? null : $idx->findMethod($e->class, '__construct', 0);
                    if ($m !== null) { $this->verify($pf, $idx, $m->params, $m->paramDocs, $m->tpl, $e->args); }
                } elseif ($e instanceof StaticCall) {
                    $cls = \strtolower($e->class);
                    if ($cls === 'self' || $cls === 'static' || $cls === 'parent') { continue; }
                    $m = $idx->findClass($e->class) === null ? null : $idx->findMethod($e->class, \strtolower($e->method), 0);
                    if ($m !== null) { $this->verify($pf, $idx, $m->params, $m->paramDocs, $m->tpl, $e->args); }
                } elseif ($e instanceof MethodCallExpr) {
                    $recv = $infer->of($e->object);
                    if ($recv->kind !== Ty::KIND_OBJECT || $recv->className === '' || $idx->findClass($recv->className) === null) { continue; }
                    $m = $idx->findMethod($recv->className, \strtolower($e->method), 0);
                    if ($m !== null) { $this->verify($pf, $idx, $m->params, $m->paramDocs, $m->tpl, $e->args); }
                }
            }
        }
        return $this->diags;
    }

    /**
     * @param Param[] $params
     * @param array<string, string> $docs
     * @param Expr[] $args
     */
    private function verify(ParsedFile $pf, Index $idx, array $params, array $docs, ?\Analyze\TemplateSig $tpl, array $args): void
    {
        foreach ($args as $i => $arg) {
            if ($arg instanceof Spread) { return; }
            $name = $arg instanceof NamedArg ? $arg->name : (isset($params[$i]) ? $params[$i]->name : null);
            $val = $arg instanceof NamedArg ? $arg->value : $arg;
            if ($name === null || !($val instanceof StringLiteral)) { continue; }
            $doc = $docs[$name] ?? ($tpl === null ? null : ($tpl->params[$name] ?? null));
            if ($doc === null || \preg_match('/^\??class-string(<.*>)?$/is', $doc) !== 1) { continue; }
            $cls = \ltrim($val->value, '\\');
            if ($cls === '' || $idx->findClass($cls) !== null || Builtins::isKnownClass(\strtolower($cls))) { continue; }
            $this->diags[] = Diagnostic::at(
                $pf->path, $val->span, Diagnostic::SEV_WARNING, 'type.class-string',
                'argument names no known class: \'' . $cls . '\' passed to class-string parameter $' . $name
            );
        }
    }
}
