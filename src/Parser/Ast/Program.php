<?php

namespace Parser\Ast;

/**
 * Top-level program — a sequence of statements.
 */
final class Program
{
    /**
     * @param Stmt[] $statements
     * @param array<string, string> $useAliases Short alias → FQN map for
     *     this file's top-level scope. `use Foo\Bar` lands as
     *     `['Bar' => 'Foo\Bar']`. Drives namespace-aware short-name
     *     resolution at compile time so `Codegen\Llvm\Type` and
     *     `Compile\Mir\MType` don't collide when both have short
     *     name `Type` / `MType`.
     */
    public function __construct(
        public readonly array $statements,
        public readonly string $namespace = '',
        public readonly array $useAliases = [],
    ) {}
}
