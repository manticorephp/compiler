<?php

namespace Analyze;

use Parser\Ast\ClassStmt;
use Parser\Ast\FunctionStmt;
use Parser\Ast\IfStmt;
use Parser\Ast\NamespaceStmt;
use Parser\Ast\Stmt;
use Parser\Ast\TryCatchStmt;

/**
 * The statement kinds that can NEST a declaration, in one place.
 *
 * Two collectors walk the tree looking for declarations — {@see Index} (the
 * symbol universe the closed-world undefined-* rules resolve against) and
 * {@see Decls} (the signature-level rules). They used to carry their own copy
 * of "descend into a namespace body", and both therefore missed the same thing:
 *
 *     if (!function_exists('mb_substr')) { function mb_substr(…) {…} }
 *
 * That is the polyfill idiom, and symfony ships eight polyfill packages built
 * entirely out of it. The compiler expands those (LowerFromAst's
 * flattenConstantIfs plus conditional-declaration support), so the code runs —
 * while the analyzer reported 30 distinct undefined functions (every mb_*,
 * grapheme_*, normalizer_*, and `trigger_deprecation`) against a corpus that
 * compiles byte-exact.
 *
 * Which arm actually executes is a runtime question. For a KNOWN-SYMBOL set the
 * conservative answer is the right one: a declaration seen on any path counts.
 *
 * Keeping the rule here means adding a nesting form fixes both collectors at
 * once — a duplicated copy of this list is what let them drift apart.
 */
final class DeclScopes
{
    /**
     * Nested statement lists to recurse into when collecting declarations.
     *
     * @return array<int, \Parser\Ast\Stmt[]>
     */
    public static function nested(Stmt $s): array
    {
        /** @var array<int, \Parser\Ast\Stmt[]> $out */
        $out = [];
        if ($s instanceof NamespaceStmt) {
            if ($s->body !== null) { $out[] = $s->body->statements; }
            return $out;
        }
        if ($s instanceof IfStmt) {
            $out[] = $s->then->statements;
            foreach ($s->elseifs as $arm) { $out[] = $arm->body->statements; }
            if ($s->else !== null) { $out[] = $s->else->statements; }
            return $out;
        }
        if ($s instanceof TryCatchStmt) {
            $out[] = $s->try->statements;
            foreach ($s->catches as $c) { $out[] = $c->body->statements; }
            if ($s->finally !== null) { $out[] = $s->finally->statements; }
            return $out;
        }
        return $out;
    }

    /** True when this statement IS a declaration (and so not a nesting form). */
    public static function isDeclaration(Stmt $s): bool
    {
        return $s instanceof FunctionStmt || $s instanceof ClassStmt;
    }
}
