<?php

namespace Analyze\Rules;

use Analyze\Diagnostic;
use Analyze\FlowWalk;
use Analyze\Index;
use Analyze\Infer;
use Analyze\ParsedFile;
use Analyze\Ty;
use Analyze\Units;
use Parser\Ast\DynamicStaticCall;
use Parser\Ast\MethodCallExpr;

/**
 * Flags a call to a method that a receiver's class does not have — the dynamic
 * dispatch case the analyzer CAN resolve, because the flow scope knows the
 * receiver's static type (`$this`, a typed parameter, `new Foo()`, a typed
 * local). Covers both `$obj->m()` and the dynamic `$obj::m()`.
 *
 * Self-gating for zero false positives: fires only when the receiver's WHOLE
 * class hierarchy is known (so every inherited method is visible) and no
 * `__call`/`__callStatic` is in play (which would accept any name).
 */
final class UndefinedMethod
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
                if ($e instanceof MethodCallExpr) {
                    $this->check($pf, $idx, $infer->of($e->object), $e->method, '->', $e->span->line, $e->span->column);
                } elseif ($e instanceof DynamicStaticCall) {
                    $this->check($pf, $idx, $infer->of($e->receiver), $e->method, '::', $e->span->line, $e->span->column);
                }
            }
        }
        return $this->diags;
    }

    private function check(ParsedFile $pf, Index $idx, Ty $recv, string $method, string $sep, int $line, int $col): void
    {
        if ($recv->kind !== Ty::KIND_OBJECT || $recv->className === '') { return; }
        $ci = $idx->findClass($recv->className);
        if ($ci === null) { return; }
        // A trait's `$this` gains methods from whatever host class composes it —
        // its own method set is not the whole picture. Abstract classes likewise
        // may call a method a concrete subclass supplies. Neither can be judged.
        if ($ci->kind === 'trait' || $ci->isAbstract) { return; }
        if (!$idx->hierarchyKnown($recv->className, 0)) { return; }
        if ($idx->hasMagicCall($recv->className)) { return; }
        if ($idx->findMethod($recv->className, \strtolower($method), 0) !== null) { return; }
        $this->diags[] = Diagnostic::error(
            $pf->path, $line, $col, 'undefined.method',
            'unknown method ' . $recv->className . $sep . $method . '()'
        );
    }
}
