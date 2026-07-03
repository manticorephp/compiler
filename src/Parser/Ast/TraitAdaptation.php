<?php

namespace Parser\Ast;

/**
 * One entry of a `use A, B { … }` trait conflict-resolution block.
 *
 *   insteadof: `A::m insteadof B, C;`  → kind='insteadof', trait='A', method='m',
 *                                        exclude=['B','C']
 *   as:        `[A::]m as [vis] [alias];` → kind='as', trait='A'|'' , method='m',
 *                                        visibility=''|'protected'|…, alias=''|'x'
 */
final class TraitAdaptation
{
    /** @param string[] $exclude losing traits (insteadof) */
    public function __construct(
        public readonly string $kind,
        public readonly string $trait,
        public readonly string $method,
        public readonly array $exclude,
        public readonly string $visibility,
        public readonly string $alias,
    ) {}
}
