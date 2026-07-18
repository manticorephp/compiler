<?php

namespace Analyze\Rules;

use Analyze\Diagnostic;
use Analyze\FlowWalk;
use Analyze\Index;
use Analyze\Infer;
use Analyze\ParsedFile;
use Analyze\Ty;
use Analyze\Units;
use Parser\Ast\DynamicStaticAccess;
use Parser\Ast\StaticAccess;

/**
 * Flags `Foo::BAR` (or the dynamic `$obj::BAR`) where the class is known but the
 * constant / enum-case `BAR` is declared nowhere in its hierarchy (parents,
 * interfaces, traits all inherit constants).
 *
 * Skips: `Foo::class` (the magic name), `Foo::$prop` (a static property, not a
 * constant — its name carries a leading `$`), `self`/`static`/`parent`, and any
 * class not in the index (a built-in whose constants we don't model). Runs in
 * whole-project mode only.
 */
final class UndefinedClassConst
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
                $scopeIdx = $k;
                $k = $k + 1;
                if ($e instanceof StaticAccess) {
                    $low = \strtolower($e->class);
                    if ($low === 'self' || $low === 'static' || $low === 'parent') { continue; }
                    $ci = $idx->findClass($e->class);
                    if ($ci === null) { continue; }
                    $this->check($pf, $idx, $ci->fqn, $e->name, $e->span->line, $e->span->column);
                } elseif ($e instanceof DynamicStaticAccess) {
                    $infer = new Infer($idx, $flow->scopes[$scopeIdx]);
                    $recv = $infer->of($e->receiver);
                    if ($recv->kind !== Ty::KIND_OBJECT || $recv->className === '') { continue; }
                    if ($idx->findClass($recv->className) === null) { continue; }
                    $this->check($pf, $idx, $recv->className, $e->name, $e->span->line, $e->span->column);
                }
            }
        }
        return $this->diags;
    }

    private function check(ParsedFile $pf, Index $idx, string $class, string $name, int $line, int $col): void
    {
        if ($name === 'class' || $name === '') { return; }
        if (\substr($name, 0, 1) === '$') { return; }   // static property, not a constant
        // Only conclude "undefined" when the whole hierarchy is visible: a const
        // may be inherited from a built-in base we don't model.
        if (!$idx->fullHierarchyKnown($class, 0)) { return; }
        if ($idx->constExists($class, $name, 0)) { return; }
        $this->diags[] = Diagnostic::error(
            $pf->path, $line, $col, 'undefined.constant',
            'unknown constant ' . $class . '::' . $name
        );
    }
}
