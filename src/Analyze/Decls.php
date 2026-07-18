<?php

namespace Analyze;

use Parser\Ast\ClassStmt;
use Parser\Ast\FunctionDecl;
use Parser\Ast\FunctionStmt;
use Parser\Ast\MethodDecl;
use Parser\Ast\NamespaceStmt;
use Parser\Ast\PropertyDecl;

/**
 * Collects the declarations a signature-level rule iterates over: free
 * functions, and every method / property paired with the FQN of its owning
 * class (for diagnostic labels). Class names arrive already namespace-qualified
 * from the parser. Parallel arrays rather than tuples — the self-host build does
 * not destructure mixed-type pairs reliably.
 */
final class Decls
{
    /** @var FunctionDecl[] */
    public array $functions = [];

    /** @var MethodDecl[] */
    public array $methods = [];
    /** @var string[] owning-class FQN, parallel to $methods */
    public array $methodClasses = [];

    /** @var PropertyDecl[] */
    public array $properties = [];
    /** @var string[] owning-class FQN, parallel to $properties */
    public array $propertyClasses = [];

    /** @param \Parser\Ast\Stmt[] $stmts */
    public function collect(array $stmts): void
    {
        foreach ($stmts as $s) {
            if ($s instanceof FunctionStmt) {
                $this->functions[] = $s->decl;
            } elseif ($s instanceof ClassStmt) {
                $cls = $s->decl->name;
                foreach ($s->decl->methods as $m) {
                    $this->methods[] = $m;
                    $this->methodClasses[] = $cls;
                }
                foreach ($s->decl->properties as $p) {
                    $this->properties[] = $p;
                    $this->propertyClasses[] = $cls;
                }
            } elseif ($s instanceof NamespaceStmt && $s->body !== null) {
                $this->collect($s->body->statements);
            }
        }
    }
}
