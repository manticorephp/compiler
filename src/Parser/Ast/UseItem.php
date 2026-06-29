<?php

namespace Parser\Ast;

/**
 * One item in a `use Foo\Bar;` or `use Foo\Bar as Baz;` declaration.
 */
final class UseItem
{
    public function __construct(
        public readonly string $fqn,
        public readonly ?string $alias,
        /** 'class' | 'function' | 'const' */
        public readonly string $kind,
    ) {}
}
