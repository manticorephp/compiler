<?php

namespace Analyze;

use Parser\Ast\ArrowFn;
use Parser\Ast\Closure;

/**
 * Collects every independently-scoped {@see Unit} in a file: top-level
 * functions, class/trait methods, and every closure / arrow function nested
 * anywhere. Rules iterate units so each is analyzed under its own scope.
 */
final class Units
{
    /** @var Unit[] */
    public array $units = [];

    /** @param \Parser\Ast\Stmt[] $stmts */
    public function collect(array $stmts): void
    {
        // Top-level executable code is its own scope (no params, no $this). Rules
        // walk it with `stopAtClosures`, so nested decl bodies are not re-entered.
        $this->units[] = new Unit('{main}', [], '', $stmts, null, null);

        $decls = new Decls();
        $decls->collect($stmts);

        foreach ($decls->functions as $fn) {
            if ($fn->body === null) { continue; }
            $this->units[] = new Unit($fn->name . '()', $fn->params, '', $fn->body->statements, null, $fn->returnType);
        }
        $i = 0;
        foreach ($decls->methods as $m) {
            $cls = $decls->methodClasses[$i];
            $i = $i + 1;
            if ($m->body === null) { continue; }
            // A non-static method binds `$this` to its class; a static one does not.
            $thisClass = $m->isStatic ? '' : $cls;
            $this->units[] = new Unit($cls . '::' . $m->name . '()', $m->params, $thisClass, $m->body->statements, null, $m->returnType);
        }

        // Closures / arrow fns anywhere in the file — each its own scope. `$this`
        // binding inside a closure is left unknown (conservative): a captured
        // receiver is not tracked.
        $walk = new AstWalk();
        $walk->stmts($stmts);
        foreach ($walk->exprs as $e) {
            if ($e instanceof Closure) {
                $this->units[] = new Unit('{closure}', $e->params, '', $e->body->statements, null, $e->returnType);
            } elseif ($e instanceof ArrowFn) {
                $this->units[] = new Unit('{closure}', $e->params, '', [], $e->body, $e->returnType);
            }
        }
    }
}
