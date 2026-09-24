<?php

namespace Manticore\Attr;

/**
 * Declare a function as a typed OVERLOAD of another: a call to `$of` whose
 * arguments statically fit this function's parameters is compiled as a call
 * to this one instead ({@see \Compile\Mir\Passes\ResolveOverloads}).
 *
 *   function preg_replace(array|string $pattern, array|string $replacement,
 *                         array|string $subject, …): array|string|null { … }
 *
 *   #[Manticore\Attr\Overload('preg_replace')]
 *   function preg_replace__str(string $pattern, string $replacement,
 *                              string $subject, …): string { … }
 *
 * The canonical function stays the php-faithful one — it is what an erased or
 * mixed argument reaches, and what a function-name string calls. An overload
 * only buys PRECISION: a return type the union signature cannot state, and a
 * body that skips the runtime dispatch. It must answer exactly what the
 * canonical function answers for the same arguments.
 */
#[\Attribute(\Attribute::TARGET_FUNCTION)]
final class Overload
{
    public function __construct(public readonly string $of) {}
}
