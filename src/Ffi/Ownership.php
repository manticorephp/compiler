<?php

namespace Ffi;

use Attribute;

/**
 * Lifetime / ownership intent for an FFI pointer. Borrowed from Rust's
 * reference rules but spelled in attribute form.
 *
 *   Borrow     — caller still owns; callee must not free.
 *   BorrowMut  — caller still owns; callee may write through but not free.
 *   Take       — callee takes ownership; will free at its own discretion.
 *   Give       — callee returns an owned ptr; caller must free.
 *   StaticPtr  — returns a static / global ptr; nobody frees.
 *
 * ⚠ These are CHECKED, not lowered. NOTHING IS FREED ON YOUR BEHALF — the pass
 * that reads them ({@see \Compile\Mir\Passes\LowerAttrChecks::checkFfiAttrs})
 * emits no code at all. What it enforces:
 *
 *   - every `Ffi\` attribute needs a `#[Ffi\Symbol]` on the same declaration
 *   - `Give` and `StaticPtr` are mutually exclusive
 *   - at most one of `Borrow` / `BorrowMut` / `Take` per parameter
 *   - ownership only on a pointer-carrying parameter / return
 *   - `Take` never on a `string`, `Give` never on a `string` return
 *
 * The last rule is memory safety, not tidiness. A PHP string is REFCOUNT-owned
 * (its rc word sits before the bytes), so handing one to C's `free()` corrupts
 * the allocator's metadata; a C buffer is the mirror image, with no rc header
 * for `rc_release` to find. Declare either as `\Ffi\Ptr` and copy across the
 * boundary.
 *
 * Should a boundary memory plan ever land, `Take` and `Give` are where it would
 * hook in — but until then the checks are the whole of it.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Borrow
{
    public function __construct(public readonly ?string $lifetime = null) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class BorrowMut
{
    public function __construct(public readonly ?string $lifetime = null) {}
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Take
{
}

#[Attribute(Attribute::TARGET_FUNCTION)]
final class Give
{
}

#[Attribute(Attribute::TARGET_FUNCTION)]
final class StaticPtr
{
}
