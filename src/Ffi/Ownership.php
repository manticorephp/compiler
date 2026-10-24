<?php

namespace Ffi;

use Attribute;

/**
 * Parameter attribute: the caller keeps ownership of the pointer and the callee must not free it.
 *
 * The ownership attributes (`Borrow`, `BorrowMut`, `Take`, `Give`, `StaticPtr`)
 * document who owns a pointer across an FFI call. They are checked at compile
 * time, not lowered: nothing is freed on your behalf. They require a
 * {@see Symbol} on the same declaration, apply only to pointer-carrying
 * parameters or returns, and `Give` and `StaticPtr` exclude each other. At most
 * one of `Borrow`, `BorrowMut` and `Take` may sit on a parameter.
 * `Take` on a `string` and `Give` on a `string` return are rejected, because a
 * PHP string is reference counted and must never reach C's `free()`.
 *
 * ```php
 * #[Library('c'), Symbol('strlen')]
 * function strlen(#[Borrow] string $s): int { return 0; }
 * ```
 *
 * @see Symbol
 */
// Implementation notes (previous docblock, kept verbatim):
// Lifetime / ownership intent for an FFI pointer. Borrowed from Rust's
// reference rules but spelled in attribute form.
//
//   Borrow     — caller still owns; callee must not free.
//   BorrowMut  — caller still owns; callee may write through but not free.
//   Take       — callee takes ownership; will free at its own discretion.
//   Give       — callee returns an owned ptr; caller must free.
//   StaticPtr  — returns a static / global ptr; nobody frees.
//
// ⚠ These are CHECKED, not lowered. NOTHING IS FREED ON YOUR BEHALF — the pass
// that reads them ({@see \Compile\Mir\Passes\LowerAttrChecks::checkFfiAttrs})
// emits no code at all. What it enforces:
//
//   - every `Ffi\` attribute needs a `#[Ffi\Symbol]` on the same declaration
//   - `Give` and `StaticPtr` are mutually exclusive
//   - at most one of `Borrow` / `BorrowMut` / `Take` per parameter
//   - ownership only on a pointer-carrying parameter / return
//   - `Take` never on a `string`, `Give` never on a `string` return
//
// The last rule is memory safety, not tidiness. A PHP string is REFCOUNT-owned
// (its rc word sits before the bytes), so handing one to C's `free()` corrupts
// the allocator's metadata; a C buffer is the mirror image, with no rc header
// for `rc_release` to find. Declare either as `\Ffi\Ptr` and copy across the
// boundary.
//
// Should a boundary memory plan ever land, `Take` and `Give` are where it would
// hook in — but until then the checks are the whole of it.

#[Attribute(Attribute::TARGET_PARAMETER)]
final class Borrow
{
    /**
     * Create the attribute.
     *
     * @param string|null $lifetime  optional lifetime label for documentation; it is not checked
     */
    public function __construct(public readonly ?string $lifetime = null) {}
}

/**
 * Parameter attribute: the caller keeps ownership; the callee may write through the pointer but must not free it.
 *
 * @see Borrow
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class BorrowMut
{
    /**
     * Create the attribute.
     *
     * @param string|null $lifetime  optional lifetime label for documentation; it is not checked
     */
    public function __construct(public readonly ?string $lifetime = null) {}
}

/**
 * Parameter attribute: the callee takes ownership of the pointer and frees it at its own discretion.
 *
 * @see Borrow
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Take
{
}

/**
 * Function attribute: the callee returns an owned pointer that the caller must free.
 *
 * Mutually exclusive with {@see StaticPtr}.
 *
 * @see Borrow
 */
#[Attribute(Attribute::TARGET_FUNCTION)]
final class Give
{
}

/**
 * Function attribute: the returned pointer refers to static or global memory that nobody frees.
 *
 * Mutually exclusive with {@see Give}.
 *
 * @see Borrow
 */
#[Attribute(Attribute::TARGET_FUNCTION)]
final class StaticPtr
{
}
