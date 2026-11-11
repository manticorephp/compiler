<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ArrayLit;
use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\IntConst;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\NullConst;
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
 * `$x` to the same cell. A non-literal key (`$GLOBALS[$name] = $v`) goes through
 * `__mc_globals`, generated once lowering is done ({@see dynGlobalsSrc}): a
 * switch over every name that IS a global cell, and a table of its own for a
 * name the program never spells.
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
        // Demand is MODULE-WIDE and must be computed before anything is
        // injected: the seed lives in `__main`, but a function may read
        // `$_SERVER` while top-level code never mentions it. Keying the seed off
        // __main's own statements — as this did — meant a program whose entry
        // file never names a superglobal never initialised the cell at all, so
        // every function read an uninitialised `int`. symfony's ArgvInput reads
        // `$_SERVER['argv']` and no console entry point mentions it, so argv was
        // empty and every command fell through to the application default.
        $want = [];
        foreach ($names as $sg) { $want[$sg] = true; }
        $demanded = [];
        /** @var array<int, array<string, bool>> $touchedBy */
        $touchedBy = [];
        foreach ($module->functions as $i => $fn) {
            $t = [];
            $this->collectTouchedLocals($fn->body, $want, true, $t);
            if ($t === []) { continue; }
            $touchedBy[$i] = $t;
            foreach ($t as $sg => $_) { $demanded[$sg] = true; }
        }
        if ($demanded === []) { return; }
        foreach ($module->functions as $i => $fn) {
            $isMain = $fn->name === '__main';
            $t = $touchedBy[$i] ?? [];
            $used = [];
            foreach ($names as $sg) {
                if (!isset($demanded[$sg])) { continue; }
                // __main binds every DEMANDED name so it can seed it; every
                // other scope binds only what it actually touches.
                if ($isMain || isset($t[$sg])) { $used[] = $sg; }
            }
            if ($used === []) { continue; }
            $pre = [];
            foreach ($used as $sg) {
                $cell = '@g_' . $sg;
                $module->addGlobalCell($cell, new IntConst(0, Type::int_()), false, $module->isLibraryModule);
                // A superglobal IS an implicit `global $x`, so register the name
                // as one. Without it scanGlobalTypes bails on its
                // `globalVarNames === []` guard and never unifies the type, so
                // every scope kept the decl's hard-lowered `int` and read the
                // seeded array as an integer.
                $module->addGlobalVarName($sg);
                $pre[] = new StaticLocalDecl_($sg, $cell, '', null, Type::int_());
                // `__main` seeds the cell; every other scope only binds to it.
                if ($isMain) {
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
        if ($sg === '_ENV') { return new Call('__mc_env_mixed', [], $t); }
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
        if ($idx === null) {
            $this->lowerFail('lower.unsupported', '$GLOBALS[] needs a key', $expr->span,
                'MIR.lower: $GLOBALS[] needs a key');
            return 'GLOBALS';
        }
        if ($idx->kind !== 'StringLiteral') { return null; }
        $name = $this->strLitValue($idx);
        $cell = '@g_' . $name;
        $this->module->addGlobalCell($cell, new IntConst(0, Type::int_()));
        // Also route `__main`'s own `$name` to this cell, so the top-level
        // variable and the $GLOBALS view are one storage location — and record
        // the name as a VIEWED one, which is what makes its slot carry a boxed
        // word for both readers.
        $this->module->addGlobalVarName($name);
        $this->module->addGlobalsViewName($name);
        return $cell;
    }

    /** `$GLOBALS['x']` as an rvalue, or null if `$expr` is not one. */
    private function lowerGlobalsRead(\Parser\Ast\ArrayAccess $expr): ?Node
    {
        $dyn = $this->dynGlobalsKey($expr);
        if ($dyn !== null) { return $this->dynGlobalsCall(0, $dyn, null); }
        $cell = $this->globalsCell($expr);
        if ($cell === null) { return null; }
        return new StaticProp_($cell, Type::cell());
    }

    /** `$GLOBALS['x'] = v`, or null if `$target` is not one. */
    private function storeToGlobals(\Parser\Ast\ArrayAccess $target, Node $value): ?Node
    {
        $dyn = $this->dynGlobalsKey($target);
        if ($dyn !== null) { return $this->dynGlobalsCall(1, $dyn, $value); }
        $base = $target->array;
        if ($base->kind === 'ArrayAccess' && $this->dynGlobalsKey($base) !== null) {
            throw new \RuntimeException('MIR.lower: writing into an element of $GLOBALS[$name] with a computed name is unsupported; '
                . 'read it, change the copy and store it back with $GLOBALS[$name] = $value');
        }
        $cell = $this->globalsCell($target);
        if ($cell === null) { return null; }
        // DECLARED cell, because the READ side is: `lowerGlobalsRead` hands back
        // `StaticProp_($cell, Type::cell())`, so the slot is a self-describing
        // channel and the store has to NaN-box. Without the declared type the
        // emitter stored the raw word and every reader decoded it as the double
        // with those bits — `$GLOBALS['g'] = 7` came back as 3.5E-323.
        return new StoreStaticProp_($cell, $value, $value->type, Type::cell());
    }

    /** Is `$expr` the bare `$GLOBALS` variable (not a `$GLOBALS[…]` access)? */
    private function isGlobalsVar(\Parser\Ast\Expr $expr): bool
    {
        return $expr->kind === 'Variable' && $this->varName($expr) === 'GLOBALS';
    }

    /**
     * PHP 8.1 (RFC "Restrict $GLOBALS usage") made $GLOBALS a read-only view:
     * `$GLOBALS[$k] = v` is the ONE write syntax, and re-assigning the array as a
     * whole (`$GLOBALS = […]`, `+=`, an `&` alias, a by-ref argument) is a
     * COMPILE-TIME fatal. Reject it with php's own wording — silently accepting
     * it wrote a bogus local named GLOBALS and left the real globals untouched.
     */
    private function rejectGlobalsWrite(\Parser\Ast\Span $span): void
    {
        $this->lowerFail('lower.unsupported',
            '$GLOBALS can only be modified using the $GLOBALS[$name] = $value syntax', $span,
            'MIR.lower: $GLOBALS can only be modified using the '
            . '$GLOBALS[$name] = $value syntax');
    }

    /**
     * A whole-$GLOBALS READ (`foreach ($GLOBALS …)`, `count($GLOBALS)`, passing it
     * on) is legal PHP but has nothing to read here: the globals are individual
     * module cells, and materialising them as an array needs the runtime name
     * table this design deliberately does not carry. An honest error beats a
     * silently empty array.
     */
    private function rejectGlobalsRead(\Parser\Ast\Span $span): void
    {
        $this->lowerFail('lower.unsupported',
            '$GLOBALS as a whole array is unsupported (it would need a runtime name table); use $GLOBALS[\'name\']', $span,
            'MIR.lower: $GLOBALS as a whole array is unsupported '
            . '(it would need a runtime name table); use $GLOBALS[\'name\']');
    }

    /** Is `$expr` a `$GLOBALS[…]` access? */
    private function isGlobalsAccess(\Parser\Ast\Expr $expr): bool
    {
        if ($expr->kind !== 'ArrayAccess') { return false; }
        return $this->isGlobalsVar($this->arrayAccessBase($expr));
    }

    /**
     * `unset($GLOBALS['x'])` → NULL the cell, so a later `isset($x)` is false.
     * The cell itself cannot go away (it is a module global), but PHP's own
     * observable effect of unsetting a global IS that it stops being set — and
     * the plain `Unset_` node silently no-ops on a module cell, which left the
     * variable stubbornly set.
     */
    private function unsetGlobalsCell(\Parser\Ast\ArrayAccess $target): Node
    {
        $dyn = $this->dynGlobalsKey($target);
        if ($dyn !== null) { return $this->dynGlobalsCall(3, $dyn, null); }
        $cell = $this->globalsCell($target);
        return new StoreStaticProp_($cell, new NullConst(Type::null_()), Type::null_());
    }

    /** The computed key of a `$GLOBALS[$expr]` access, or null for any other access. */
    private function dynGlobalsKey(\Parser\Ast\ArrayAccess $expr): ?\Parser\Ast\Expr
    {
        if (!$this->isGlobalsVar($expr->array)) { return null; }
        $idx = $expr->index;
        if ($idx === null || $idx->kind === 'StringLiteral') { return null; }
        return $idx;
    }

    /** `__mc_globals(op, (string) key, value)` — op 0 read, 1 write, 2 isset, 3 unset. */
    private function dynGlobalsCall(int $op, \Parser\Ast\Expr $key, ?Node $value): Node
    {
        $this->sawDynGlobals = true;
        $k = $this->lowerExpr(\Parser\Ast\Expr::call('strval', [$key], $key->span));
        $args = [new IntConst($op, Type::int_()), $k];
        if ($value !== null) { $args[] = $value; }
        return new Call('__mc_globals', $args, $op === 2 ? Type::bool_() : Type::cell());
    }

    /**
     * The by-name table behind {@see dynGlobalsCall}: one arm per name that is a
     * global cell, so a computed name and a spelled one reach the same storage,
     * and a static table for a name nothing spells.
     *
     * ponytail: a top-level variable no scope declares `global` and no
     * `$GLOBALS['x']` spells stays a `__main` local, invisible to a computed name.
     * Pin every top-level variable to a cell if a program needs that.
     */
    private function dynGlobalsSrc(Module $module): string
    {
        $arms = '';
        foreach ($module->globalVarNames as $name) {
            if (\preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1 || $name === 'GLOBALS' || \str_starts_with($name, '__mc_')) { continue; }
            // `global $x`, not `$GLOBALS['x']`: a name reached through the
            // `$GLOBALS` view keeps a boxed slot that cannot be passed by
            // reference, and this table names every global of the program.
            // Unsetting writes NULL — `unset()` of a `global` binding would
            // only drop the local alias.
            $arms .= "        case '" . $name . "':\n"
                . "            global \$" . $name . ";\n"
                . "            if (\$__mc_op === 0) { return \$" . $name . " ?? null; }\n"
                . "            if (\$__mc_op === 1) { \$" . $name . " = \$__mc_v; return null; }\n"
                . "            if (\$__mc_op === 2) { return isset(\$" . $name . "); }\n"
                . "            \$" . $name . " = null;\n"
                . "            return null;\n";
        }
        return "function __mc_globals(int \$__mc_op, string \$__mc_n, mixed \$__mc_v = null): mixed\n{\n"
            . "    static \$__mc_dyn = [];\n"
            . ($arms === '' ? '' : "    switch (\$__mc_n) {\n" . $arms . "    }\n")
            . "    if (\$__mc_op === 0) { return \$__mc_dyn[\$__mc_n] ?? null; }\n"
            . "    if (\$__mc_op === 1) { \$__mc_dyn[\$__mc_n] = \$__mc_v; return null; }\n"
            . "    if (\$__mc_op === 2) { return isset(\$__mc_dyn[\$__mc_n]); }\n"
            . "    unset(\$__mc_dyn[\$__mc_n]);\n"
            . "    return null;\n}\n";
    }

    /** The indexed expression of an ArrayAccess, read through a TYPE-PINNED param. */
    private function arrayAccessBase(\Parser\Ast\ArrayAccess $e): \Parser\Ast\Expr
    {
        return $e->array;
    }
}
