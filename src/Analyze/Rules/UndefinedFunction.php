<?php

namespace Analyze\Rules;

use Analyze\AstWalk;
use Analyze\Builtins;
use Analyze\Diagnostic;
use Analyze\Index;
use Analyze\ParsedFile;
use Parser\Ast\CallExpr;

/**
 * Flags a call to a free function defined nowhere the analyzer can resolve —
 * a typo or a function the target doesn't provide. The callable universe is:
 * user + prelude functions ({@see Index::resolveFunction}), stdlib functions
 * (the `.o.sig` loaded into {@see Index::$externFunctions}), and the compiler's
 * codegen builtins + language constructs ({@see Builtins::isKnownFunction}).
 *
 * CLOSED-WORLD, like {@see UndefinedClass}: only runs in whole-project mode.
 * Method calls (`$o->m()`) and dynamic calls (`$f()`) are out of scope.
 */
final class UndefinedFunction
{
    /** @var Diagnostic[] */
    public array $diags = [];

    /** @return Diagnostic[] */
    public function run(ParsedFile $pf, Index $idx): array
    {
        $walk = new AstWalk();
        $walk->stmts($pf->program->statements);
        foreach ($walk->exprs as $e) {
            if (!($e instanceof CallExpr)) { continue; }
            $name = $e->function;
            if ($name === '' || \strpos($name, '$') !== false) { continue; }
            $low = \strtolower(\ltrim($name, '\\'));
            if ($idx->resolveFunction($name) !== null) { continue; }
            if ($idx->hasExternFunction($low)) { continue; }
            // Global-namespace fallback for the stdlib set too (`N\strlen` → `strlen`).
            $bs = \strrpos($low, '\\');
            if ($bs !== false && $idx->hasExternFunction(\substr($low, $bs + 1))) { continue; }
            if (Builtins::isKnownFunction($low)) { continue; }
            if ($bs !== false && Builtins::isKnownFunction(\substr($low, $bs + 1))) { continue; }
            $this->diags[] = Diagnostic::error(
                $pf->path, $e->span->line, $e->span->column, 'undefined.function',
                'unknown function ' . \ltrim($name, '\\') . '()'
            );
        }
        return $this->diags;
    }
}
