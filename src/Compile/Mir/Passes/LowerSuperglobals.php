<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ArrayLit;
use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\IntConst;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\Type;

/**
 * PHP's superglobals — the ONE rule that defines them: a superglobal is visible
 * in EVERY scope with no `global` declaration. That is exactly what `global $x`
 * already lowers to (a {@see StaticLocalDecl_} binding the name to the shared
 * module cell `@g_<name>`), so a superglobal is nothing but an implicit `global`
 * decl, injected into every function body that mentions the name. Writes,
 * element stores and array values all ride the proven cell path.
 *
 * The cells are SEEDED in `__main`, demand-gated over the WHOLE module: a
 * function may read `$_SERVER` while top-level code never mentions it, so the
 * demand scan cannot look at `__main`'s statements alone.
 *
 * `$GLOBALS` is not a variable but syntax: `$GLOBALS['x']` names the top-level
 * `$x`, in any scope. With a literal key that is precisely the cell `@g_x` —
 * a {@see StaticProp_} read / {@see StoreStaticProp_} write, no local binding
 * needed. Registering the name as a global var also re-routes `__main`'s own
 * `$x` to the same cell. A non-literal key would need a runtime name→cell table
 * (every top-level var pinned to a cell forever); it is a hard error instead.
 */
trait LowerSuperglobals
{
    /**
     * The superglobal names, minus `$GLOBALS` (syntax, handled separately).
     * `$_SESSION` is here for scope-visibility only — nothing seeds it, exactly
     * as in PHP before session_start().
     * @return string[]
     */
    private function superglobalNames(): array
    {
        return [
            '_SERVER', '_ENV', '_GET', '_POST',
            '_COOKIE', '_FILES', '_REQUEST', '_SESSION',
        ];
    }

    /**
     * Bind every superglobal a body touches to its module cell, and seed the
     * demanded ones at the top of `__main`. Runs once `$module` holds ALL
     * functions (user functions, methods, closures and `__main`).
     */
    private function injectSuperglobals(Module $module): void
    {
        $names = $this->superglobalNames();
        foreach ($module->functions as $fn) {
            $used = [];
            foreach ($names as $sg) {
                if ($this->nodeReadsLocal($fn->body, $sg)
                    || $this->nodeWritesLocal($fn->body, $sg)) {
                    $used[] = $sg;
                }
            }
            if ($used === []) { continue; }
            $pre = [];
            foreach ($used as $sg) {
                $cell = '@g_' . $sg;
                $module->addGlobalCell($cell, new IntConst(0, Type::int_()));
                $pre[] = new StaticLocalDecl_($sg, $cell, '', null, Type::int_());
                // `__main` seeds the cell; every other scope only binds to it.
                if ($fn->name === '__main') {
                    $pre[] = new StoreLocal($sg, $this->superglobalInit($sg), Type::assoc(Type::string_(), Type::cell()));
                }
            }
            foreach ($fn->body->stmts as $s) { $pre[] = $s; }
            $fn->body = new Block($pre, Type::void());
        }
    }

    /**
     * The value a superglobal starts with. The CLI SAPI populates $_SERVER (and,
     * unlike stock php's default variables_order, $_ENV — the environment is the
     * only thing a native CLI binary has, so hiding it behind an ini setting we
     * do not have would be a footgun). The request-scoped ones exist but stay
     * empty, which is what php CLI hands back too.
     */
    private function superglobalInit(string $sg): Node
    {
        $t = Type::assoc(Type::string_(), Type::cell());
        if ($sg === '_SERVER') { return new Call('__mc_server', [], $t); }
        if ($sg === '_ENV') { return new Call('__mc_env', [], Type::assoc(Type::string_(), Type::string_())); }
        return new ArrayLit([], $t);
    }

    /**
     * `$GLOBALS['x']` → the module cell backing the top-level `$x`, or null if
     * this access is not a `$GLOBALS` one. Throws on a non-literal key.
     */
    private function globalsCell(\Parser\Ast\ArrayAccess $expr): ?string
    {
        $base = $expr->array;
        if ($base->kind !== 'Variable') { return null; }
        if ($this->varName($base) !== 'GLOBALS') { return null; }
        $idx = $expr->index;
        if ($idx === null || $idx->kind !== 'StringLiteral') {
            throw new \RuntimeException(
                'MIR.lower: $GLOBALS needs a literal string key '
                . '(a dynamic one would need a runtime name table)'
            );
        }
        $name = $this->strLitValue($idx);
        $cell = '@g_' . $name;
        $this->module->addGlobalCell($cell, new IntConst(0, Type::int_()));
        // Also route `__main`'s own `$name` to this cell, so the top-level
        // variable and the $GLOBALS view are one storage location.
        $this->module->addGlobalVarName($name);
        return $cell;
    }

    /** `$GLOBALS['x']` as an rvalue, or null if `$expr` is not one. */
    private function lowerGlobalsRead(\Parser\Ast\ArrayAccess $expr): ?Node
    {
        $cell = $this->globalsCell($expr);
        if ($cell === null) { return null; }
        return new StaticProp_($cell, Type::cell());
    }

    /** `$GLOBALS['x'] = v`, or null if `$target` is not one. */
    private function storeToGlobals(\Parser\Ast\ArrayAccess $target, Node $value): ?Node
    {
        $cell = $this->globalsCell($target);
        if ($cell === null) { return null; }
        return new StoreStaticProp_($cell, $value, $value->type);
    }
}
