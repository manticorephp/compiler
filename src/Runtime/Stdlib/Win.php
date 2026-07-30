<?php

/**
 * Windows-only SAPI functions — bodies that exist so a provably-dead Windows
 * branch LINKS, never so it runs.
 *
 * Every one of these is guarded in real code by `\function_exists(...)` or
 * `'\\' === \DIRECTORY_SEPARATOR`, and the folder drops those branches. What it
 * cannot drop is a guard carried in a VALUE — symfony/console's Terminal does
 *
 *     $cp = \function_exists('sapi_windows_cp_set') ? sapi_windows_cp_get() : 0;
 *     ...
 *     if ($cp) { sapi_windows_cp_set($cp); }
 *
 * where the second guard is `$cp`, not a predicate. The call survives lowering,
 * so the symbol must resolve. It is paired with `LowerFromAst::HIDDEN_FNS`,
 * which keeps `function_exists` answering FALSE for these names — otherwise
 * providing them would flip programs onto the Windows path they were avoiding.
 * The returns below are the values that path treats as "no code page / no
 * VT100", so even a reached call degrades to the unix behaviour.
 *
 * These move to the per-OS symbol table when the target-abi work lands; on a
 * Windows target they become real bindings and leave the hidden set.
 */

/** Console/codepage identifier. 0 = none, which every caller reads as "skip". */
function sapi_windows_cp_get(string $kind = ''): int
{
    return 0;
}

/** Set the console codepage. Always fails off Windows. */
function sapi_windows_cp_set(int $codepage): bool
{
    return false;
}

/** Codepage conversion — nothing to convert, so the subject passes through. */
function sapi_windows_cp_conv(mixed $in_codepage, mixed $out_codepage, string $subject): ?string
{
    return $subject;
}

/** VT100 support is a Windows-console question; unix terminals answer it
 *  through TERM, which the callers check separately. */
function sapi_windows_vt100_support(mixed $stream, ?bool $enable = null): bool
{
    return false;
}
