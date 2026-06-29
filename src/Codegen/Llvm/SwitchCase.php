<?php

namespace Codegen\Llvm;

/**
 * One arm of an LLVM `switch` — a case value paired with the block to
 * jump to on a match. Lifted out of the old `[Value, Block]` tuple
 * form so type tracking through `$cases` chains works.
 */
final class SwitchCase
{
    public function __construct(
        public readonly Value $value,
        public readonly Block $dest,
    ) {}
}
