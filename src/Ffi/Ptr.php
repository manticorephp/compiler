<?php

namespace Ffi;

/**
 * Opaque pointer wrapper. Holds a raw address. No automatic free — the
 * caller owns the lifetime of whatever the pointer refers to.
 *
 * Reading through this object is unsafe in the conventional sense:
 * out-of-bounds reads will not be caught by the runtime.
 */
final class Ptr
{
    public function __construct(public readonly int $address) {}

    public static function null(): self
    {
        return new self(0);
    }

    public function isNull(): bool
    {
        return $this->address === 0;
    }

    public function offset(int $bytes): self
    {
        return new self($this->address + $bytes);
    }
}
