<?php

/**
 * setlocale — a direct libc binding, because the process locale is libc's
 * state and not ours.
 *
 * pack/unpack used to live here too and moved to prelude/binary.php: pack is
 * VARIADIC, and a variadic cannot cross the stdlib.o boundary (the .sig carries
 * no variadic-ness, so the callee read its arguments from the wrong place and
 * returned garbage).
 */

/** Read a NUL-terminated C string out of a pointer libc handed back. */
function __mc_cstr(\Ffi\Ptr $p): string
{
    if (\ptr_to_int($p) === 0) { return ""; }
    $out = "";
    $i = 0;
    while ($i < 4096) {
        $b = \peek_u8($p, $i);
        if ($b === 0) { break; }
        $out = $out . \chr($b);
        $i = $i + 1;
    }
    return $out;
}

/**
 * php's setlocale. Only the (category, locale-string) form is supported — the
 * variadic "try each of these" form and the array form are not used by any
 * caller here and would need a different signature.
 *
 * `setlocale(LC_CTYPE, 0)` (or "0") is php's spelling for "just tell me the
 * current one"; symfony/string uses exactly that to save and restore around a
 * transliteration, so the QUERY form is as load-bearing as the set form.
 *
 * Returns the resulting locale name, or false when libc rejects it.
 */
function setlocale(int $category, mixed $locales = null): mixed
{
    if ($locales === null || $locales === 0 || $locales === "0") {
        $r = \Runtime\Libc\sys_setlocale_query($category, \int_to_ptr(0));
        if (\ptr_to_int($r) === 0) { return false; }
        return __mc_cstr($r);
    }
    $name = (string)$locales;
    $r = \Runtime\Libc\sys_setlocale($category, $name);
    if (\ptr_to_int($r) === 0) { return false; }
    return __mc_cstr($r);
}
