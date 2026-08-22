<?php

namespace Parser\Ast;

/**
 * A function/method body kept as source coordinates until lowering needs it.
 * The source string is shared by all lazy bodies from one file and is cleared
 * by Parser::materializeLazyBody() after the body has been parsed.
 */
final class LazyBody
{
    /** @param array<string,string> $useAliases */
    public function __construct(
        public string $source,
        public readonly int $start,
        public readonly int $end,
        public readonly int $line,
        public readonly string $namespace,
        public readonly array $useAliases,
        public readonly string $file,
    ) {}
}

?>
