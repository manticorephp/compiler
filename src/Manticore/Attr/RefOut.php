<?php

namespace Manticore\Attr;

/**
 * Mark named by-ref (`&$x`) parameters as pure OUTPUT: the callee assigns
 * them fresh and never reads the incoming value. Core compiler semantics —
 * not FFI-specific.
 *
 *   #[Manticore\Attr\RefOut('matches')]
 *   function preg_match(string $p, string $s, array &$matches = []): int
 *
 * Unlike a plain by-ref param (IN-OUT, e.g. `sort()`), a `#[RefOut]` arg is
 * safe for the caller to auto-vivify: an undefined variable passed to it is
 * defined with the parameter's element type before the call, so
 * `preg_match($p, $s, $m)` needs no prior `$m = []` and a read-back
 * (`$m[0]`) carries the right static type instead of erasing to `unknown`.
 *
 * Two spellings, both honoured:
 *   #[RefOut] array &$matches                    — on the parameter
 *   #[RefOut('matches')] ... array &$matches     — on the function, by name
 * The parameter form is the natural one; the function form is handy when a
 * signature is generated or several out-params are marked at once.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]
final class RefOut
{
    /** @param string ...$params names of pure-output by-ref params (function form) */
    public function __construct(public readonly string ...$params) {}
}
