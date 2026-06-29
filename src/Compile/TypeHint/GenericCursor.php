<?php

namespace Compile\TypeHint;

/**
 * Mutable scan cursor used by {@see GenericType}. Kept as a tiny
 * class instead of an array tuple so the parser code stays
 * self-host-friendly — assoc-array writes through array keys
 * still upgrade buffers reliably, but per-`$cursor->pos = …`
 * field stores are stable.
 */
final class GenericCursor
{
    public function __construct(
        public readonly string $raw,
        public int $pos,
        public readonly int $len,
    ) {}
}
