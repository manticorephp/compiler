<?php

namespace Parser\Ast;

/**
 * One `case Name = expr;` line inside an `enum` declaration.
 * `$value` is null for unbacked enum cases.
 *
 * Carrying the pair as a real object beats a `[name, value]` tuple —
 * the self-host compiler cannot reliably destructure tuples whose
 * elements have different LLVM types (string + ptr).
 */
final class EnumCase
{
    public function __construct(
        public readonly string $name,
        public readonly ?Expr $value,
    ) {}
}
