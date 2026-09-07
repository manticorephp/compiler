<?php

namespace Compile\Mir;

/**
 * The site-specific half of a `new $cls(...)`: everything the shared comparison
 * chain ({@see Passes\EmitLlvmObjects::emitNewDynFns}) cannot read off its own
 * parameters. Two sites with the same shape share one body.
 */
final class NewDynShape
{
    /**
     * @param string[] $kinds LLVM kind of each argument AT THE SITE — 'i64',
     *     'ptr' or 'double'. The body takes all-i64 parameters and materialises
     *     each one back into its kind, because the per-arm boxing reads it.
     * @param Node[] $args the argument NODES, for their declared types (the arm
     *     boxes or unboxes against the candidate constructor's parameter types).
     */
    public function __construct(
        public readonly array $kinds,
        public readonly array $args,
        public readonly int $srcArgc,
        public readonly bool $boxResult,
    ) {}
}
