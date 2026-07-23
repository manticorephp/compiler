<?php

namespace Compile\Mir;

final class FunctionDef
{
    /** @param Param[] $params */
    public function __construct(
        public readonly string $name,
        public readonly array $params,
        public Type $returnType,
        public Block $body,
        public bool $returnsByRef = false,
        public bool $isPrelude = false,
    ) {}

    /**
     * Aggregate (union) of every node's intrinsic effects, filled by
     * {@see Passes\InferEffects}. Null until that pass runs.
     */
    public ?Effects $effects = null;

    /**
     * FFI binding: when set (from `#[Symbol('cSym')]`), this function is
     * emitted as a thin wrapper forwarding to the C symbol — its PHP body
     * (a stock-PHP fallback) is not lowered. Set by {@see Passes\LowerFromAst}.
     */
    public ?string $ffiSymbol = null;
    /** @var string[] LLVM C types of the extern params, in order. */
    public array $ffiParamCTypes = [];
    /** LLVM C return type of the extern. */
    public string $ffiRetCType = 'i64';
    /**
     * FFI binding with `#[Ffi\Weak]`: emit `declare extern_weak` so a symbol
     * absent on the current target binds to null instead of a link error. The
     * wrapper must only be CALLED behind a runtime OS guard (e.g. epoll_* on
     * a macOS build). Set by {@see Passes\LowerFromAst}.
     */
    public bool $ffiWeak = false;

    /**
     * Signature-only import from the prebuilt stdlib: EmitLlvm emits a bare
     * `declare` (no body) so user code can call it; the definition lives in
     * the linked `stdlib.o`. Set by {@see Passes\LowerFromAst} when a bundled
     * stdlib function is referenced and not shadowed by a user definition or
     * a codegen builtin. The function's body is never lowered.
     */
    public bool $isExtern = false;

    /**
     * A generator — its body contains a `yield`. Set by
     * {@see Passes\LowerFromAst}. EmitLlvm emits it as TWO functions: a
     * creator (`@manticore_<name>` — allocates the frame, returns a
     * Generator) and a resume (`@manticore_<name>$resume(frame*)` — the
     * state machine). The declared body is the resume body.
     */
    public bool $isGenerator = false;
}
