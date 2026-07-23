<?php

namespace Ffi;

use Attribute;

/**
 * Marks an FFI binding whose C symbol may be ABSENT on the current OS/target.
 *
 * The compiler emits `declare extern_weak …` instead of a plain `declare`, so a
 * symbol missing at link time resolves to null rather than an undefined-symbol
 * error. The decorated function must never be CALLED on a target where the
 * symbol is absent (guard the call with a runtime OS branch) — extern_weak only
 * makes the *reference* tolerable, not the call. Used together with
 * {@see Symbol}: e.g. epoll_* (Linux-only) referenced from a macOS build.
 *
 * On Darwin, ld64 still errors on a weak-undefined symbol unless it is allowed
 * with `-Wl,-U,_<sym>` (see the link step in Manticore\Main). GNU ld auto-binds
 * a weak-undefined to 0, so Linux needs no flag.
 */
#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final class Weak
{
}
