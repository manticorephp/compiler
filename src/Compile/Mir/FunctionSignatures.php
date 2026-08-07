<?php

namespace Compile\Mir;

/**
 * Call-site signature registry for every function/method in the module, keyed
 * by mangled name. Built once at the top of {@see EmitLlvm::emit()} — a call
 * site looks the callee up here to decide how to pass each argument (by-ref,
 * tagged cell, default-arg padding) and how to receive the result.
 *
 * A name that is absent is not a user function (a builtin, or an extern), so
 * every lookup carries its own fallback at the use site.
 */
final class FunctionSignatures
{
    /** @var array<string, bool[]> fn name → per-param by-ref mask */
    public array $refParams = [];
    /** @var array<string, bool[]> fn name → which params are tagged (cell) */
    public array $taggedParams = [];
    /** @var array<string, bool[]> fn name → which array params are `#[CellArg]`
     *  (element-consuming): a concrete-element array arg is cellified here. */
    public array $cellArgParams = [];
    /** @var array<string, bool[]> fn name → which params carry a DECLARED array
     *  hint. A bare `array` lowers to KIND_UNKNOWN, so the type alone cannot
     *  tell a call site that the callee reads a raw buffer pointer — and a CELL
     *  argument must be stripped to that pointer before it crosses. */
    public array $arrayHintedParams = [];
    /** @var array<string, Type[]> fn name → per-param declared type */
    public array $paramTypes = [];
    /** @var array<string, array<int, ?Node>> fn name → per-param default node */
    public array $paramDefaults = [];
    /** @var array<string, bool> fn name → returns by reference */
    public array $returnsByRef = [];
    /** @var array<string, Type> fn name → declared return type */
    public array $returnType = [];
    /** @var array<string, bool> fn name → body calls the func-args family, so a
     *  call site must push its as-written argument count onto the side channel
     *  ({@see FunctionDef::$usesFuncArgs}). */
    public array $usesFuncArgs = [];

    /**
     * Bit `i` set ⟺ SOME `__closure_N` in this module declares its call slot
     * `i` by reference. Computed by {@see closureRefUnion}.
     *
     * A closure invoked through a `\Closure`-typed value has no statically
     * known callee, so no per-callee mask is available at the call site — the
     * mask has to be recovered at run time. This union is the compile-time
     * GATE on that machinery: when it is 0 for a call's arity, the invoke can
     * be emitted exactly as it always was. It is 0 for every program that
     * never writes a by-ref closure parameter, which is nearly all of them.
     */
    public int $closureRefUnion = 0;

    /**
     * The union above, over every closure in the module.
     *
     * A closure's MIR params are prefixed by its captures ({@see
     * Module::$closureCaptures}) and only the params past that prefix are call
     * slots, so slot `i` is `params[capCnt + i]`. Refuses an arity the 64-bit
     * union cannot express rather than silently dropping the high slots.
     *
     * @param FunctionDef[] $functions
     * @param array<string, int> $closureCaptures
     */
    public static function closureRefUnion(array $functions, array $closureCaptures): int
    {
        $union = 0;
        foreach ($functions as $fn) {
            $capCnt = $closureCaptures[$fn->name] ?? -1;
            if ($capCnt < 0) { continue; }
            $slot = 0;
            $np = \count($fn->params);
            for ($pi = $capCnt; $pi < $np; $pi++) {
                if ($fn->params[$pi]->byRef) {
                    if ($slot > 62) {
                        throw new \RuntimeException(
                            'closure ' . $fn->name . ' declares a by-reference parameter at call slot '
                            . (string)$slot . '; the dynamic-invoke by-ref mask carries 63'
                        );
                    }
                    $union = $union | (1 << $slot);
                }
                $slot = $slot + 1;
            }
        }
        return $union;
    }
}
