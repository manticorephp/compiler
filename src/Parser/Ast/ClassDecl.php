<?php

namespace Parser\Ast;

/**
 * Class / interface / trait / enum declaration.
 *
 * `kind` distinguishes the four forms:
 *   'class' | 'interface' | 'trait' | 'enum'
 *
 * For an enum, `enumBackingType` is non-null iff the enum is backed
 * (`enum Color: string`). `cases` lists enum cases; properties/methods/
 * consts work as for classes.
 */
final class ClassDecl
{
    /**
     * @param string[]        $implements        FQN list (empty for interface/trait)
     * @param string[]        $extends           FQN list (interfaces extend many, classes at most one)
     * @param AttributeNode[] $attributes
     * @param PropertyDecl[]  $properties
     * @param MethodDecl[]    $methods
     * @param ConstDecl[]     $consts
     * @param EnumCase[] $cases  enum case name + optional value
     * @param string[]        $uses              names of traits the class mixes in
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name,
        public readonly array $extends,
        public readonly array $implements,
        public readonly array $attributes,
        public readonly array $properties,
        public readonly array $methods,
        public readonly array $consts,
        public readonly array $cases,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly bool $isReadonly,
        public readonly ?string $enumBackingType,
        public readonly Span $span,
        public readonly array $uses = [],
    ) {}
}
