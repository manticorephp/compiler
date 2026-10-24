<?php

namespace Ffi;

/**
 * An opaque C pointer: a raw address with no automatic free.
 *
 * Use it for C handles that PHP holds but never dereferences (a `FILE*`, a
 * library cookie), as a parameter or return type of an FFI binding. The caller
 * owns the lifetime of whatever the address refers to, and the runtime does not
 * check it: using a dangling or out-of-bounds address is undefined behaviour.
 *
 * @see Symbol
 * @see Library
 */
// Implementation notes (previous docblock, kept verbatim):
// Opaque pointer wrapper. Holds a raw address. No automatic free — the
// caller owns the lifetime of whatever the pointer refers to.
//
// Reading through this object is unsafe in the conventional sense:
// out-of-bounds reads will not be caught by the runtime.

final class Ptr
{
    /**
     * Wrap a raw address.
     *
     * @param int $address  the address; 0 is the null pointer
     */
    public function __construct(public readonly int $address) {}

    /**
     * The null pointer (address 0).
     *
     * @return self  a pointer for which {@see isNull} is true
     */
    public static function null(): self
    {
        return new self(0);
    }

    /**
     * Whether the address is 0.
     *
     * @return bool  true for the null pointer
     */
    public function isNull(): bool
    {
        return $this->address === 0;
    }

    /**
     * A new pointer `$bytes` bytes past this one (negative moves back). No bounds are checked.
     *
     * @param int $bytes  byte displacement
     * @return self  the displaced pointer
     */
    public function offset(int $bytes): self
    {
        return new self($this->address + $bytes);
    }
}
