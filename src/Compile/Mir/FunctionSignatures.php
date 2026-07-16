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
    /** @var array<string, Type[]> fn name → per-param declared type */
    public array $paramTypes = [];
    /** @var array<string, array<int, ?Node>> fn name → per-param default node */
    public array $paramDefaults = [];
    /** @var array<string, bool> fn name → returns by reference */
    public array $returnsByRef = [];
    /** @var array<string, Type> fn name → declared return type */
    public array $returnType = [];
}
