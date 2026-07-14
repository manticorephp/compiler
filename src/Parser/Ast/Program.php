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
        /**
         * Every doc comment in the file, in source order. A generic BINDING
         * (`@var Box<float> $b`) is only ever written in a docblock, and the
         * one on a local statement is attached too deep in the tree for a
         * cheap walk — reification pre-scans this flat list instead.
         * @var string[]
         */
        public readonly array $docComments = [],
    ) {}
}
