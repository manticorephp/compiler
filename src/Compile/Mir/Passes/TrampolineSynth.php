<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ClassDef;
use Compile\Mir\MethodMeta;

/**
 * Reflection Ф2 — synthesizes an invoke TRAMPOLINE per (class, method) and per
 * class constructor, as ordinary PHP source lowered through the normal pipeline.
 *
 * Why source, not hand-emitted LLVM: a trampoline must reuse the WHOLE method
 * call path — the class_id virtual-dispatch switch, per-param coercion
 * (`unboxCellArg` / `taggedParams` / `refParams`), default padding, LSB and
 * Monomorphize. Re-implementing that at emit time is ~150 lines of subtlety;
 * lowering a `return $t->m($a[0], $a[1]);` gets it for free.
 *
 * ABI (uniform across every trampoline, so ONE indirect-call signature works):
 *   __mc_rtramp_<C>__<m>(i64 recv, ptr args) -> i64 cell
 * The first param is the object for an instance method (an untagged pointer,
 * declared `\C $t`) and is IGNORED for a static method / constructor (declared
 * `int $t`). The second is the boxed `mixed[]` argument array. The body reads a
 * legal-arity arm per `count($args)`, so normal lowering fills each arm's
 * defaults / packs nothing (variadics are excluded — Ф2c).
 *
 * Keyed by the DECLARING class: `Dog` inheriting `Animal::feed` reuses
 * `__mc_rtramp_Animal__feed` (its `$t->feed()` still dispatches virtually to
 * Dog's body). One trampoline per declared method, not per inheritor.
 *
 * NOT invokable (no trampoline; the rmeta row's tramp stays null): abstract and
 * interface methods, a method with a by-ref or variadic parameter. Ф2 does not
 * forward by-ref through the boxed array, and a variadic needs unbounded arms.
 */
final class TrampolineSynth
{
    /**
     * Forces the args param to `vec[cell]` so `$a[$i]` reads a tagged cell that
     * `$t->m(...)` unboxes to the method's concrete param type. Load-bearing: a
     * trampoline has NO direct MIR call site (only the indirect `__mc_refl_invoke`
     * builtin), so neither InferScans nor Monomorphize can reconcile the arg repr
     * the prelude passes with a body-usage guess — the param must be pinned to the
     * universal boxed repr, and the prelude must pass a boxed array.
     */
    private const ARGS_HINT = "/** @param mixed[] \$a */\n";

    /**
     * The backslash-free symbol base shared by the synthesized FunctionDef name
     * and the emitter's rmeta reference ({@see
     * \Compile\Mir\Passes\EmitLlvmRuntime::methodTrampField}) — a PHP function
     * name cannot contain `\` (it would read as a namespace), so the class part
     * is flattened here identically on both sides.
     */
    public static function symBase(string $class, string $method): string
    {
        $c = \str_replace('\\', '_', \ltrim($class, '\\'));
        return '__mc_rtramp_' . $c . '__' . $method;
    }

    /**
     * True for any reflection-synthesized function whose `mixed` return shares
     * the trampoline's uniform cell-return ABI — a scalar slot must box on
     * return (else a caller reading it as a cell decodes garbage), and
     * NarrowReturns must not strip the box (these have no direct MIR caller).
     * The invoke trampolines plus {@see ReflectSynth}'s accessors / factories.
     */
    public static function isSynthReturn(string $name): bool
    {
        return \str_contains($name, '__mc_rtramp_')
            || \str_contains($name, '__mc_fntramp_')
            || \str_contains($name, '__mc_pget_')
            || \str_contains($name, '__mc_pset_')
            || \str_contains($name, '__mc_attr_args_')
            || \str_contains($name, '__mc_attr_new_')
            || \str_contains($name, '__mc_consts_')
            || \str_contains($name, '__mc_enum_cases_');
    }

    /** The free-function invoke-trampoline symbol (Ф5, ReflectionFunction).
     *  Backslash-free, matched on the synthesis + emission sides. */
    public static function fnTrampBase(string $fn): string
    {
        return '__mc_fntramp_' . \str_replace('\\', '_', \ltrim($fn, '\\'));
    }

    /**
     * A free function's invoke trampoline — the static-method shape with the
     * receiver ignored: `__mc_fntramp_<f>(int $t, array $a): mixed { return
     * \f($a[0], …); }`. Arms from required..total so lowering fills defaults.
     */
    public static function functionTramp(string $fn, int $req, int $tot, bool $void): string
    {
        $fqn = '\\' . \ltrim($fn, '\\');
        $mk = fn (string $args): string => $fqn . '(' . $args . ')';
        $body = self::arms($req, $tot, $mk, $void);
        return self::ARGS_HINT . 'function ' . self::fnTrampBase($fn)
             . "(int \$t, array \$a): mixed {\n" . $body . "}\n";
    }

    /** True when a method can carry a uniform invoke trampoline. */
    public static function invokable(MethodMeta $mm): bool
    {
        if ($mm->isAbstract) { return false; }
        foreach ($mm->params as $p) {
            if ($p->byRef || $p->variadic) { return false; }
        }
        return true;
    }

    /**
     * PHP source (no `<?php`) for every trampoline this class contributes: its
     * constructor (unless abstract) plus its OWN invokable methods. '' when it
     * contributes none.
     */
    public static function sourceFor(ClassDef $cls): string
    {
        if ($cls->isStruct || $cls->isPreludeClass) { return ''; }
        $out = '';
        if (!$cls->isAbstract) {
            $ctor = $cls->methodMeta['__construct'] ?? null;
            $out .= self::ctorTramp($cls->name, $ctor);
        }
        foreach ($cls->methodMeta as $mn => $mm) {
            if ($mn === '__construct') { continue; }
            $decl = $mm->declaringClass !== '' ? $mm->declaringClass : $cls->name;
            if ($decl !== $cls->name) { continue; }   // emitted by the declaring class
            if (!self::invokable($mm)) { continue; }
            $out .= self::methodTramp($cls->name, $mn, $mm);
        }
        return $out;
    }

    /** `new \C(args)` — recv ignored. Arms from the ctor's required..total. */
    private static function ctorTramp(string $class, ?MethodMeta $ctor): string
    {
        $fqn = '\\' . \ltrim($class, '\\');
        $req = $ctor === null ? 0 : $ctor->requiredParams();
        $tot = $ctor === null ? 0 : \count($ctor->params);
        $name = self::symBase($class, '__construct');
        $body = self::arms($req, $tot, fn (string $args): string => 'new ' . $fqn . '(' . $args . ')', false);
        return self::ARGS_HINT . 'function ' . $name . "(int \$t, array \$a): mixed {\n" . $body . "}\n";
    }

    /** `$t->m(args)` (instance) or `\C::m(args)` (static). */
    private static function methodTramp(string $class, string $method, MethodMeta $mm): string
    {
        $fqn = '\\' . \ltrim($class, '\\');
        $req = $mm->requiredParams();
        $tot = \count($mm->params);
        $void = \strtolower($mm->returnType) === 'void';
        if ($mm->isStatic) {
            $recv = 'int $t';
            $mk = fn (string $args): string => $fqn . '::' . $method . '(' . $args . ')';
        } else {
            $recv = $fqn . ' $t';
            $mk = fn (string $args): string => '$t->' . $method . '(' . $args . ')';
        }
        $name = self::symBase($class, $method);
        $body = self::arms($req, $tot, $mk, $void);
        return self::ARGS_HINT . 'function ' . $name . '(' . $recv . ", array \$a): mixed {\n" . $body . "}\n";
    }

    /**
     * One arm per legal arity R..T, each a literal-arity call so lowering fills
     * that arm's defaults. `$mk(argsExpr)` builds the call expression from a
     * comma list `$a[0], $a[1], …`. A void call returns null (an undefined i64
     * result otherwise). When R==T there is a single tail call, no count().
     */
    private static function arms(int $req, int $tot, callable $mk, bool $void): string
    {
        $stmt = function (string $call) use ($void): string {
            return $void ? ('  ' . $call . "; return null;\n") : ('  return ' . $call . ";\n");
        };
        $argsUpTo = function (int $k): string {
            $parts = [];
            for ($i = 0; $i < $k; $i = $i + 1) { $parts[] = '$a[' . (string)$i . ']'; }
            return \implode(', ', $parts);
        };
        if ($req >= $tot) {
            return $stmt($mk($argsUpTo($tot)));
        }
        $out = "  \$n = count(\$a);\n";
        for ($k = $req; $k < $tot; $k = $k + 1) {
            $out .= '  if ($n <= ' . (string)$k . ") {\n  " . $stmt($mk($argsUpTo($k))) . "  }\n";
        }
        $out .= $stmt($mk($argsUpTo($tot)));
        return $out;
    }
}
