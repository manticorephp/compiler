<?php

namespace Ffi;

use Attribute;

/**
 * Lifetime / ownership intent for an FFI pointer. Borrowed from
 * Rust's reference rules but spelled in attribute form. The compiler
 * uses these hints to insert the right free / refcount / drop call
 * at the scope boundary once the memory plan lands.
 *
 *   Borrow     — caller still owns; callee must not free.
 *   BorrowMut  — caller still owns; callee may write through but not free.
 *   Take       — callee takes ownership; will free at its own discretion.
 *   Give       — callee returns an owned ptr; caller must free.
 *   StaticPtr  — returns a static / global ptr; nobody frees.
 *
 * Today these are advisory metadata — the runtime memory plan is still
 * being designed. Once we have one, `Take` and `Give` lower to the
 * chosen free / refcount call automatically.
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

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class Give
{
}

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class StaticPtr
{
}
