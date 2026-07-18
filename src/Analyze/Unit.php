<?php

namespace Analyze;

use Parser\Ast\Expr;

/**
 * An independently-scoped body: a function, a method, a closure, or an arrow
 * function. Each gets its own {@see Scope} (parameters, `$this`, locals) so a
 * variable never carries a type across a scope boundary — the reason closures
 * are their own units rather than being flattened into the enclosing function.
 *
 * `$stmts` is the body for a block-bodied unit; `$exprBody` is the single
 * expression of an arrow function (its `$stmts` is empty).
 */
final class Unit
{
    /**
     * @param \Parser\Ast\Param[] $params
     * @param \Parser\Ast\Stmt[]  $stmts
     */
    public function __construct(
        public string $label,
        public array $params,
        public string $thisClass,
        public array $stmts,
        public ?Expr $exprBody,
        public ?string $returnType,
    ) {}
}
