<?php

/**
 * ext/intl over the host ICU, bound through FFI and linked DYNAMICALLY (the
 * system libicu, like -lcurl / -lsqlite3). Demand-gated: a program that never
 * names an intl class or function does not link ICU at all.
 *
 * The bindings carry ICU's plain C names; ICU renames its API with the major
 * version (`unorm2_normalize_78`) and the FFI emitter appends the host's suffix
 * for every `#[Library('icu…')]` binding (Main.php `icu_symbol_suffix`).
 *
 * ICU's C API speaks UTF-16. Strings cross as a malloc'd UChar buffer: the
 * UTF-8 → UTF-16 conversion is strict (an ill-formed input is an error, as
 * php's intl_convert_utf8_to_utf16 makes it), and every API that writes a
 * buffer is called twice — once to measure (U_BUFFER_OVERFLOW_ERROR), once to
 * fill — rather than guessing a capacity.
 */

#[\Ffi\Library('icuuc'), \Ffi\Symbol('u_strFromUTF8')]
function __mc_icu_u_strFromUTF8(\Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $destLen,
    string $src, #[\Ffi\CType('int')] int $srcLen, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('u_strToUTF8')]
function __mc_icu_u_strToUTF8(\Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $destLen,
    \Ffi\Ptr $src, #[\Ffi\CType('int')] int $srcLen, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('unorm2_getNFCInstance')]
function __mc_icu_unorm2_getNFCInstance(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('unorm2_getNFDInstance')]
function __mc_icu_unorm2_getNFDInstance(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('unorm2_getNFKCInstance')]
function __mc_icu_unorm2_getNFKCInstance(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('unorm2_getNFKDInstance')]
function __mc_icu_unorm2_getNFKDInstance(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('unorm2_getNFKCCasefoldInstance')]
function __mc_icu_unorm2_getNFKCCasefoldInstance(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('unorm2_normalize'), \Ffi\CType('int')]
function __mc_icu_unorm2_normalize(\Ffi\Ptr $norm, \Ffi\Ptr $src, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('unorm2_isNormalized'), \Ffi\CType('char')]
function __mc_icu_unorm2_isNormalized(\Ffi\Ptr $norm, \Ffi\Ptr $src, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('c'), \Ffi\Symbol('malloc')]
function __mc_icu_malloc(#[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function __mc_icu_free(\Ffi\Ptr $p): void {}

/** A malloc'd UTF-16 buffer and its length in UChars; the holder frees `$buf`. */
final class __McIcuU16
{
    public function __construct(public \Ffi\Ptr $buf, public int $len) {}
}

/** A UTF-16 copy of `$s`, or null when `$s` is not well-formed UTF-8. */
function __mc_icu_to16(string $s): ?__McIcuU16
{
    $n = \strlen($s);
    $buf = \__mc_icu_malloc(($n + 1) * 2);
    $cells = \__mc_icu_malloc(16);
    \poke_i32($cells, 0, 0);
    \poke_i32($cells, 8, 0);
    \__mc_icu_u_strFromUTF8($buf, $n + 1, $cells, $s, $n, \ptr_offset($cells, 8));
    $err = \peek_i32($cells, 8);
    $len = \peek_i32($cells, 0);
    \__mc_icu_free($cells);
    if ($err > 0) {
        \__mc_icu_free($buf);
        return null;
    }
    return new __McIcuU16($buf, $len);
}

/** UTF-8 of `$len` UChars at `$src`. */
function __mc_icu_to8(\Ffi\Ptr $src, int $len): string
{
    $cells = \__mc_icu_malloc(16);
    \poke_i32($cells, 0, 0);
    \poke_i32($cells, 8, 0);
    $cap = $len * 3 + 1;
    $buf = \__mc_icu_malloc($cap);
    \__mc_icu_u_strToUTF8($buf, $cap, $cells, $src, $len, \ptr_offset($cells, 8));
    $out = \str_from_buffer($buf, \peek_i32($cells, 0));
    \__mc_icu_free($buf);
    \__mc_icu_free($cells);
    return $out;
}

class Normalizer
{
    public const FORM_D = 4;
    public const NFD = 4;
    public const FORM_KD = 8;
    public const NFKD = 8;
    public const FORM_C = 16;
    public const NFC = 16;
    public const FORM_KC = 32;
    public const NFKC = 32;
    public const FORM_KC_CF = 48;
    public const NFKC_CF = 48;

    public static function normalize(string $string, int $form = self::FORM_C): string|false
    {
        return \normalizer_normalize($string, $form);
    }

    public static function isNormalized(string $string, int $form = self::FORM_C): bool
    {
        return \normalizer_is_normalized($string, $form);
    }
}

/** ICU's normalizer for a Normalizer::FORM_*; ValueError for anything else. */
function __mc_icu_normalizer(int $form, string $fn): \Ffi\Ptr
{
    $err = \__mc_icu_malloc(8);
    \poke_i32($err, 0, 0);
    $n = null;
    if ($form === 16) {
        $n = \__mc_icu_unorm2_getNFCInstance($err);
    } elseif ($form === 4) {
        $n = \__mc_icu_unorm2_getNFDInstance($err);
    } elseif ($form === 32) {
        $n = \__mc_icu_unorm2_getNFKCInstance($err);
    } elseif ($form === 8) {
        $n = \__mc_icu_unorm2_getNFKDInstance($err);
    } elseif ($form === 48) {
        $n = \__mc_icu_unorm2_getNFKCCasefoldInstance($err);
    }
    \__mc_icu_free($err);
    if ($n === null) {
        throw new \ValueError($fn . "(): Argument #2 (\$form) must be a a valid normalization form");
    }
    return $n;
}

function normalizer_normalize(string $string, int $form = Normalizer::FORM_C): string|false
{
    $norm = \__mc_icu_normalizer($form, "normalizer_normalize");
    $u = \__mc_icu_to16($string);
    if ($u === null) { return false; }
    $err = \__mc_icu_malloc(8);
    \poke_i32($err, 0, 0);
    $need = \__mc_icu_unorm2_normalize($norm, $u->buf, $u->len, \int_to_ptr(0), 0, $err);
    \poke_i32($err, 0, 0);
    $dest = \__mc_icu_malloc(($need + 1) * 2);
    $got = \__mc_icu_unorm2_normalize($norm, $u->buf, $u->len, $dest, $need + 1, $err);
    $failed = \peek_i32($err, 0) > 0;
    \__mc_icu_free($err);
    \__mc_icu_free($u->buf);
    if ($failed) {
        \__mc_icu_free($dest);
        return false;
    }
    $out = \__mc_icu_to8($dest, $got);
    \__mc_icu_free($dest);
    return $out;
}

function normalizer_is_normalized(string $string, int $form = Normalizer::FORM_C): bool
{
    $norm = \__mc_icu_normalizer($form, "normalizer_is_normalized");
    $u = \__mc_icu_to16($string);
    if ($u === null) { return false; }
    $err = \__mc_icu_malloc(8);
    \poke_i32($err, 0, 0);
    $yes = \__mc_icu_unorm2_isNormalized($norm, $u->buf, $u->len, $err) & 0xFF;
    $failed = \peek_i32($err, 0) > 0;
    \__mc_icu_free($err);
    \__mc_icu_free($u->buf);
    return !$failed && $yes !== 0;
}
