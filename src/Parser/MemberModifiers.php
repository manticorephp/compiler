<?php

namespace Parser;

/**
 * Class-member modifier set (visibility + static/final/abstract/readonly).
 *
 * A typed value object rather than a string-keyed assoc: the booleans live
 * in real object slots (clean `0`/`1`), so reading `$mods->isStatic` yields
 * a plain bool. A heterogeneous `['static' => false, ...]` map would box its
 * values into tagged cells, and a boxed `false` reads truthy through a
 * `bool` consumer — the trap this type sidesteps.
 */
final class MemberModifiers
{
    public function __construct(
        public readonly string $visibility,
        public readonly bool $isStatic,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly bool $isReadonly,
    ) {}
}
