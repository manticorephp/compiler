<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\MethodInfo;
use Analyze\ParsedFile;
use Parser\Ast\CallExpr;
use Parser\Ast\Ellipsis;
use Parser\Ast\Expr;
use Parser\Ast\NamedArg;
use Parser\Ast\NewExpr;
use Parser\Ast\Param;
use Parser\Ast\Spread;
use Parser\Ast\StaticCall;

/**
 * Flags a call passing too few or too many arguments — but ONLY when the callee
 * is fully resolvable to a user declaration (a free function, a `new` target's
 * constructor, or a static method), so an unknown/builtin/dynamic callee is
 * silently skipped and never mis-reported. Named-arg, spread (`...$a`) and
 * first-class-callable (`f(...)`) call lists are also skipped: their effective
 * arity cannot be counted positionally.
 *
 * Severity split follows the language, not taste. Too FEW required arguments is
 * a hard `ArgumentCountError` at runtime → error. Too MANY positional arguments
 * is silently ACCEPTED by PHP (and by Manticore — the compiler's own source
 * passes extra args, e.g. `new Block([], $span)`), so it is a likely-mistake
 * advisory → warning, never a build-breaking error.
 *
 * Zero false positives is the contract — a wrong report on valid code would
 * poison trust in the whole command.
 */
final class ArgCount
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf, Index $idx): array
    {
        $walk = new AstWalk();
        $walk->stmts($pf->program->statements);
        foreach ($walk->exprs as $e) {
            if ($e instanceof CallExpr) {
                $this->checkCall($pf, $idx, $e);
            } elseif ($e instanceof NewExpr) {
                $this->checkNew($pf, $idx, $e);
            } elseif ($e instanceof StaticCall) {
                $this->checkStatic($pf, $idx, $e);
            }
        }
        return $this->diags;
    }

    private function checkCall(ParsedFile $pf, Index $idx, CallExpr $e): void
    {
        if (!$this->countable($e->args)) { return; }
        $fn = $idx->resolveFunction($e->function);
        if ($fn === null) { return; }
        $this->verify($pf, $e, $e->function . '()', $fn->params, \count($e->args));
    }

    private function checkNew(ParsedFile $pf, Index $idx, NewExpr $e): void
    {
        if (!$this->countable($e->args)) { return; }
        $cls = $e->class;
        $low = \strtolower($cls);
        if ($low === 'self' || $low === 'static' || $low === 'parent') { return; }
        $ci = $idx->findClass($cls);
        if ($ci === null) { return; }
        // An abstract class / interface can't be instantiated — a different
        // diagnostic's job; don't count-check it here.
        if ($ci->isAbstract || $ci->kind !== 'class') { return; }
        $ctor = $idx->findMethod($cls, '__construct', 0);
        if ($ctor === null) {
            // No constructor anywhere we can see. Only conclude "takes zero
            // args" when the whole hierarchy is known — otherwise a builtin
            // base class might declare the real constructor.
            if (!$idx->hierarchyKnown($cls, 0)) { return; }
            $this->verify($pf, $e, 'new ' . $cls, [], \count($e->args));
            return;
        }
        $this->verify($pf, $e, 'new ' . $cls, $ctor->params, \count($e->args));
    }

    private function checkStatic(ParsedFile $pf, Index $idx, StaticCall $e): void
    {
        if (!$this->countable($e->args)) { return; }
        $cls = $e->class;
        $low = \strtolower($cls);
        if ($low === 'self' || $low === 'static' || $low === 'parent') { return; }
        if ($idx->findClass($cls) === null) { return; }
        $m = $idx->findMethod($cls, \strtolower($e->method), 0);
        if ($m === null) { return; }
        $this->verify($pf, $e, $cls . '::' . $e->method . '()', $m->params, \count($e->args));
    }

    /**
     * @param Param[] $params
     */
    private function verify(ParsedFile $pf, Expr $site, string $label, array $params, int $given): void
    {
        $min = 0;
        $variadic = false;
        foreach ($params as $p) {
            if ($p->variadic) { $variadic = true; continue; }
            if ($p->default === null) { $min = $min + 1; }
        }
        $max = \count($params);

        if ($given < $min) {
            $this->diags[] = Diagnostic::error(
                $pf->path, $site->span->line, $site->span->column, 'arg.count',
                'too few arguments to ' . $label . ': ' . (string)$given
                    . ' passed, at least ' . (string)$min . ' expected'
            );
            return;
        }
        if (!$variadic && $given > $max) {
            $this->diags[] = Diagnostic::warning(
                $pf->path, $site->span->line, $site->span->column, 'arg.count',
                'too many arguments to ' . $label . ': ' . (string)$given
                    . ' passed, at most ' . (string)$max . ' expected'
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
