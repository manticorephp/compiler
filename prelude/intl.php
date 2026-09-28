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

// ── grapheme_* (php-src ext/intl/grapheme, transcribed) ─────────────────────

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_open')]
function __mc_icu_ubrk_open(#[\Ffi\CType('int')] int $type, \Ffi\Ptr $locale, \Ffi\Ptr $text,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_setText')]
function __mc_icu_ubrk_setText(\Ffi\Ptr $bi, \Ffi\Ptr $text, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_setUText')]
function __mc_icu_ubrk_setUText(\Ffi\Ptr $bi, \Ffi\Ptr $ut, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_next'), \Ffi\CType('int')]
function __mc_icu_ubrk_next(\Ffi\Ptr $bi): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_previous'), \Ffi\CType('int')]
function __mc_icu_ubrk_previous(\Ffi\Ptr $bi): int { return -1; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_first'), \Ffi\CType('int')]
function __mc_icu_ubrk_first(\Ffi\Ptr $bi): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_last'), \Ffi\CType('int')]
function __mc_icu_ubrk_last(\Ffi\Ptr $bi): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_current'), \Ffi\CType('int')]
function __mc_icu_ubrk_current(\Ffi\Ptr $bi): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_isBoundary'), \Ffi\CType('char')]
function __mc_icu_ubrk_isBoundary(\Ffi\Ptr $bi, #[\Ffi\CType('int')] int $pos): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ubrk_close')]
function __mc_icu_ubrk_close(\Ffi\Ptr $bi): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_openUTF8')]
function __mc_icu_utext_openUTF8(\Ffi\Ptr $ut, string $s, #[\Ffi\CType('longlong')] int $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('utext_close')]
function __mc_icu_utext_close(\Ffi\Ptr $ut): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('usearch_open')]
function __mc_icu_usearch_open(\Ffi\Ptr $pat, #[\Ffi\CType('int')] int $patLen, \Ffi\Ptr $text,
    #[\Ffi\CType('int')] int $textLen, string $locale, \Ffi\Ptr $bi, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('usearch_getCollator')]
function __mc_icu_usearch_getCollator(\Ffi\Ptr $ss): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('usearch_reset')]
function __mc_icu_usearch_reset(\Ffi\Ptr $ss): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('usearch_setOffset')]
function __mc_icu_usearch_setOffset(\Ffi\Ptr $ss, #[\Ffi\CType('int')] int $pos, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('usearch_next'), \Ffi\CType('int')]
function __mc_icu_usearch_next(\Ffi\Ptr $ss, \Ffi\Ptr $err): int { return -1; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('usearch_last'), \Ffi\CType('int')]
function __mc_icu_usearch_last(\Ffi\Ptr $ss, \Ffi\Ptr $err): int { return -1; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('usearch_close')]
function __mc_icu_usearch_close(\Ffi\Ptr $ss): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_open')]
function __mc_icu_ucol_open(string $locale, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_close')]
function __mc_icu_ucol_close(\Ffi\Ptr $coll): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_setAttribute')]
function __mc_icu_ucol_setAttribute(\Ffi\Ptr $coll, #[\Ffi\CType('int')] int $attr,
    #[\Ffi\CType('int')] int $value, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_strcoll'), \Ffi\CType('int')]
function __mc_icu_ucol_strcoll(\Ffi\Ptr $coll, \Ffi\Ptr $s, #[\Ffi\CType('int')] int $sl,
    \Ffi\Ptr $t, #[\Ffi\CType('int')] int $tl): int { return 0; }

/** A fresh UErrorCode cell (4 bytes, zeroed); the caller frees it. */
function __mc_icu_err(): \Ffi\Ptr
{
    $e = \__mc_icu_malloc(8);
    \poke_i32($e, 0, 0);
    return $e;
}

/** A character break iterator (UBRK_CHARACTER, root locale) over nothing yet. */
function __mc_icu_char_breaker(): \Ffi\Ptr
{
    $e = \__mc_icu_err();
    $bi = \__mc_icu_ubrk_open(0, \int_to_ptr(0), \int_to_ptr(0), 0, $e);
    \__mc_icu_free($e);
    return $bi;
}

function __mc_icu_set_text(\Ffi\Ptr $bi, \Ffi\Ptr $text, int $len): void
{
    $e = \__mc_icu_err();
    \__mc_icu_ubrk_setText($bi, $text, $len, $e);
    \__mc_icu_free($e);
}

/** grapheme_ascii_check: ASCII with no CR LF pair (one grapheme of two bytes). */
function __mc_grapheme_is_ascii(string $s, int $len): bool
{
    $i = 0;
    while ($i < $len) {
        if (\ord($s[$i]) > 0x7F) { return false; }
        if ($s[$i] === "\r" && $i + 1 < \strlen($s) && $s[$i + 1] === "\n") { return false; }
        $i = $i + 1;
    }
    return true;
}

function __mc_grapheme_count(\Ffi\Ptr $bi, \Ffi\Ptr $text, int $len): int
{
    \__mc_icu_set_text($bi, $text, $len);
    $n = 0;
    while (\__mc_icu_ubrk_next($bi) !== -1) { $n = $n + 1; }
    return $n;
}

/** grapheme_get_haystack_offset: the UChar position `$offset` graphemes in (from the end when negative), -1 past it. */
function __mc_grapheme_offset(\Ffi\Ptr $bi, int $offset): int
{
    if ($offset === 0) { return 0; }
    $back = $offset < 0;
    if ($back) { \__mc_icu_ubrk_last($bi); }
    $pos = 0;
    while ($pos !== -1 && $offset !== 0) {
        $pos = $back ? \__mc_icu_ubrk_previous($bi) : \__mc_icu_ubrk_next($bi);
        if ($pos !== -1) { $offset = $offset + ($back ? 1 : -1); }
    }
    return $offset !== 0 ? -1 : $pos;
}

/** OUTSIDE_STRING for a grapheme offset argument. */
function __mc_grapheme_outside(int $offset, int $len): bool
{
    return $offset <= -2147483648 || $offset > 2147483647 || ($offset < 0 ? -$offset > $len : $offset > $len);
}

/**
 * grapheme_strpos_utf16: [grapheme position or -1, UChar position of the match or -1].
 * Collation-based (usearch), so canonically equivalent spellings match; with
 * `$icase` at secondary strength.
 * @return int[]
 */
function __mc_grapheme_find(string $fn, string $haystack, string $needle, int $offset, bool $icase, bool $last, string $locale): array
{
    $h = \__mc_icu_to16($haystack);
    $n = $h === null ? null : \__mc_icu_to16($needle);
    if ($h === null || $n === null) {
        if ($h !== null) { \__mc_icu_free($h->buf); }
        return [-1, -1];
    }
    $bi = \__mc_icu_char_breaker();
    \__mc_icu_set_text($bi, $h->buf, $h->len);
    $ret = -1;
    $uchar = -1;
    $ss = null;
    $bad = false;
    if ($n->len === 0) {
        $at = \__mc_grapheme_offset($bi, $offset);
        if ($at === -1) {
            $bad = true;
        } else {
            $ret = \__mc_grapheme_count($bi, $h->buf, $last && $offset >= 0 ? $h->len : $at);
        }
    } else {
        $e = \__mc_icu_err();
        $ss = \__mc_icu_usearch_open($n->buf, $n->len, $h->buf, $h->len, $locale, $bi, $e);
        $ok = \peek_i32($e, 0) <= 0;
        if ($ok && $icase) {
            \poke_i32($e, 0, 0);
            \__mc_icu_ucol_setAttribute(\__mc_icu_usearch_getCollator($ss), 5, 1, $e);
            $ok = \peek_i32($e, 0) <= 0;
            \__mc_icu_usearch_reset($ss);
        }
        $offsetPos = 0;
        if ($ok && $offset !== 0) {
            $offsetPos = \__mc_grapheme_offset($bi, $offset);
            if ($offsetPos === -1) {
                $bad = true;
                $ok = false;
            } else {
                \poke_i32($e, 0, 0);
                \__mc_icu_usearch_setOffset($ss, $last ? 0 : $offsetPos, $e);
                $ok = \peek_i32($e, 0) <= 0;
            }
        }
        if ($ok) {
            $pos = -1;
            if (!$last) {
                $pos = \__mc_icu_usearch_next($ss, $e);
            } elseif ($offset >= 0) {
                $pos = \__mc_icu_usearch_last($ss, $e);
                if ($pos < $offsetPos) { $pos = -1; }
            } else {
                $prev = -1;
                while (true) {
                    $pos = \__mc_icu_usearch_next($ss, $e);
                    if ($pos === -1 || $pos > $offsetPos) {
                        $pos = $prev;
                        break;
                    }
                    $prev = $pos;
                }
            }
            if (\peek_i32($e, 0) <= 0 && $pos !== -1 && (\__mc_icu_ubrk_isBoundary($bi, $pos) & 0xFF) !== 0) {
                $ret = \__mc_grapheme_count($bi, $h->buf, $pos);
                $uchar = $pos;
            }
        }
        \__mc_icu_free($e);
    }
    if ($ss !== null) { \__mc_icu_usearch_close($ss); }
    \__mc_icu_ubrk_close($bi);
    \__mc_icu_free($h->buf);
    \__mc_icu_free($n->buf);
    if ($bad) {
        throw new \ValueError($fn . "(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    return [$ret, $uchar];
}

function grapheme_strlen(string $string): int|false|null
{
    $len = \strlen($string);
    if (\__mc_grapheme_is_ascii($string, $len)) { return $len; }
    $u = \__mc_icu_to16($string);
    if ($u === null) { return null; }
    $bi = \__mc_icu_char_breaker();
    $n = \__mc_grapheme_count($bi, $u->buf, $u->len);
    \__mc_icu_ubrk_close($bi);
    \__mc_icu_free($u->buf);
    return $n;
}

function grapheme_strpos(string $haystack, string $needle, int $offset = 0, string $locale = ""): int|false
{
    $len = \strlen($haystack);
    if (\__mc_grapheme_outside($offset, $len)) {
        throw new \ValueError("grapheme_strpos(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    if ($offset >= 0 && \__mc_grapheme_is_ascii($haystack, $len)) {
        return \strpos($haystack, $needle, $offset);
    }
    $r = \__mc_grapheme_find("grapheme_strpos", $haystack, $needle, $offset, false, false, $locale);
    return $r[0] >= 0 ? $r[0] : false;
}

function grapheme_stripos(string $haystack, string $needle, int $offset = 0, string $locale = ""): int|false
{
    $len = \strlen($haystack);
    if (\__mc_grapheme_outside($offset, $len)) {
        throw new \ValueError("grapheme_stripos(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    if (\__mc_grapheme_is_ascii($haystack, $len)) {
        $found = \strpos(\strtolower($haystack), \strtolower($needle), $offset >= 0 ? $offset : $len + $offset);
        if ($found !== false) { return $found; }
        if (\__mc_grapheme_is_ascii($needle, \strlen($needle))) { return false; }
    }
    $r = \__mc_grapheme_find("grapheme_stripos", $haystack, $needle, $offset, true, false, $locale);
    return $r[0] >= 0 ? $r[0] : false;
}

/** grapheme_strrpos_ascii (php's own strrpos shape). */
function __mc_grapheme_rpos_ascii(string $h, string $n, int $offset): int
{
    $hl = \strlen($h);
    $nl = \strlen($n);
    if ($offset >= 0) {
        $p = $offset;
        $e = $hl - $nl;
    } else {
        $p = 0;
        $e = $nl > -$offset ? $hl - $nl : $hl + $offset;
    }
    while ($e >= $p) {
        if (\substr($h, $e, $nl) === $n) { return $e - $p + ($offset > 0 ? $offset : 0); }
        $e = $e - 1;
    }
    return -1;
}

function grapheme_strrpos(string $haystack, string $needle, int $offset = 0, string $locale = ""): int|false
{
    $len = \strlen($haystack);
    if (\__mc_grapheme_outside($offset, $len)) {
        throw new \ValueError("grapheme_strrpos(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    if (\__mc_grapheme_is_ascii($haystack, $len)) {
        $at = \__mc_grapheme_rpos_ascii($haystack, $needle, $offset);
        if ($at >= 0) { return $at; }
        if (\__mc_grapheme_is_ascii($needle, \strlen($needle))) { return false; }
    }
    $r = \__mc_grapheme_find("grapheme_strrpos", $haystack, $needle, $offset, false, true, $locale);
    return $r[0] >= 0 ? $r[0] : false;
}

function grapheme_strripos(string $haystack, string $needle, int $offset = 0, string $locale = ""): int|false
{
    $len = \strlen($haystack);
    if (\__mc_grapheme_outside($offset, $len)) {
        throw new \ValueError("grapheme_strripos(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    if (\__mc_grapheme_is_ascii($haystack, $len)) {
        $at = \__mc_grapheme_rpos_ascii(\strtolower($haystack), \strtolower($needle), $offset);
        if ($at >= 0) { return $at; }
        if (\__mc_grapheme_is_ascii($needle, \strlen($needle))) { return false; }
    }
    $r = \__mc_grapheme_find("grapheme_strripos", $haystack, $needle, $offset, true, true, $locale);
    return $r[0] >= 0 ? $r[0] : false;
}

function grapheme_substr(string $string, int $offset, ?int $length = null, string $locale = ""): string|false
{
    if ($offset < -2147483648 || $offset > 2147483647) {
        throw new \ValueError("grapheme_substr(): Argument #2 (\$offset) is too large");
    }
    $len = \strlen($string);
    if ($length === null) { $length = $len; }
    if ($length < -2147483648 || $length > 2147483647) {
        throw new \ValueError("grapheme_substr(): Argument #3 (\$length) is too large");
    }
    if (\__mc_grapheme_is_ascii($string, $len)) {
        $f = $offset;
        if ($f < 0) {
            $f = $len + $f;
            if ($f < 0) { $f = 0; }
        } elseif ($f > $len) {
            $f = $len;
        }
        $l = $length;
        if ($l < 0) {
            $l = $len - $f + $l;
            if ($l < 0) { $l = 0; }
        } elseif ($l > $len - $f) {
            $l = $len - $f;
        }
        return \substr($string, $f, $l);
    }
    $u = \__mc_icu_to16($string);
    if ($u === null) { return false; }
    $bi = \__mc_icu_char_breaker();
    \__mc_icu_set_text($bi, $u->buf, $u->len);
    $start = $offset;
    $back = $start < 0;
    if ($back) { \__mc_icu_ubrk_last($bi); }
    $startPos = 0;
    while ($start !== 0) {
        $startPos = $back ? \__mc_icu_ubrk_previous($bi) : \__mc_icu_ubrk_next($bi);
        if ($startPos === -1) { break; }
        $start = $start + ($back ? 1 : -1);
    }
    $out = "";
    $done = false;
    if ($start !== 0) {
        if ($start > 0) {
            $done = true;
        } else {
            $startPos = 0;
            \__mc_icu_ubrk_first($bi);
        }
    }
    if (!$done && $length >= $len) {
        $out = \__mc_icu_to8(\ptr_offset($u->buf, $startPos * 2), $u->len - $startPos);
        $done = true;
    }
    if (!$done && $length === 0) { $done = true; }
    if (!$done) {
        $backL = $length < 0;
        if ($backL) { \__mc_icu_ubrk_last($bi); }
        $endPos = 0;
        $l = $length;
        while ($l !== 0) {
            $endPos = $backL ? \__mc_icu_ubrk_previous($bi) : \__mc_icu_ubrk_next($bi);
            if ($endPos === -1) { break; }
            $l = $l + ($backL ? 1 : -1);
        }
        $empty = false;
        if ($endPos === -1) {
            if ($l < 0) {
                $empty = true;
            } else {
                $endPos = $u->len;
            }
        }
        if (!$empty && $startPos <= $endPos) {
            $out = \__mc_icu_to8(\ptr_offset($u->buf, $startPos * 2), $endPos - $startPos);
        }
    }
    \__mc_icu_ubrk_close($bi);
    \__mc_icu_free($u->buf);
    return $out;
}

/** strstr_common_handler. */
function __mc_grapheme_strstr(string $fn, string $haystack, string $needle, bool $before, bool $icase, string $locale): string|false
{
    if (!$icase) {
        $found = \strpos($haystack, $needle);
        if ($found === false) { return false; }
        if (\__mc_grapheme_is_ascii($haystack, \strlen($haystack))) {
            return $before ? \substr($haystack, 0, $found) : \substr($haystack, $found);
        }
    }
    $r = \__mc_grapheme_find($fn, $haystack, $needle, 0, $icase, false, $locale);
    if ($r[0] < 0) { return false; }
    // U8_FWD_N by the UChar position: php counts code points by UTF-16 units here.
    $pos = 0;
    $k = $r[1];
    $n = \strlen($haystack);
    while ($k > 0 && $pos < $n) {
        $b = \ord($haystack[$pos]);
        $pos = $pos + ($b < 0x80 ? 1 : ($b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : 2)));
        $k = $k - 1;
    }
    if ($pos > $n) { $pos = $n; }
    return $before ? \substr($haystack, 0, $pos) : \substr($haystack, $pos);
}

function grapheme_strstr(string $haystack, string $needle, bool $beforeNeedle = false, string $locale = ""): string|false
{
    return \__mc_grapheme_strstr("grapheme_strstr", $haystack, $needle, $beforeNeedle, false, $locale);
}

function grapheme_stristr(string $haystack, string $needle, bool $beforeNeedle = false, string $locale = ""): string|false
{
    return \__mc_grapheme_strstr("grapheme_stristr", $haystack, $needle, $beforeNeedle, true, $locale);
}

/** A character break iterator over UTF-8 `$s` directly (positions are byte offsets): [iterator, UText]. */
final class __McIcuUtf8Breaker
{
    public function __construct(public \Ffi\Ptr $bi, public \Ffi\Ptr $ut) {}

    public function close(): void
    {
        \__mc_icu_utext_close($this->ut);
        \__mc_icu_ubrk_close($this->bi);
    }
}

function __mc_icu_utf8_breaker(string $s): ?__McIcuUtf8Breaker
{
    $e = \__mc_icu_err();
    $ut = \__mc_icu_utext_openUTF8(\int_to_ptr(0), $s, \strlen($s), $e);
    if (\peek_i32($e, 0) > 0) {
        \__mc_icu_free($e);
        return null;
    }
    $bi = \__mc_icu_char_breaker();
    \__mc_icu_ubrk_setUText($bi, $ut, $e);
    \__mc_icu_free($e);
    return new __McIcuUtf8Breaker($bi, $ut);
}

function grapheme_extract(string $haystack, int $size, int $type = GRAPHEME_EXTR_COUNT, int $offset = 0, &$next = null): string|false
{
    $len = \strlen($haystack);
    if ($offset < 0) { $offset = $offset + $len; }
    $next = $offset;
    if ($type < 0 || $type > 2) {
        throw new \ValueError("grapheme_extract(): Argument #3 (\$type) must be one of GRAPHEME_EXTR_COUNT, GRAPHEME_EXTR_MAXBYTES, or GRAPHEME_EXTR_MAXCHARS");
    }
    if ($offset > 2147483647 || $offset < 0 || $offset >= $len) { return false; }
    if ($size < 0) {
        throw new \ValueError("grapheme_extract(): Argument #2 (\$size) must be greater than or equal to 0");
    }
    if ($size > 2147483647) {
        throw new \ValueError("grapheme_extract(): Argument #2 (\$size) is too large");
    }
    if ($size === 0) { return ""; }
    $start = $offset;
    while ($start < $len && (\ord($haystack[$start]) & 0xC0) === 0x80) { $start = $start + 1; }
    if ($start >= $len) { return false; }
    $rest = \substr($haystack, $start);
    $rlen = \strlen($rest);
    if (\__mc_grapheme_is_ascii($rest, \min($size + 1, $rlen))) {
        $n = \min($size, $rlen);
        $next = $start + $n;
        return \substr($rest, 0, $n);
    }
    $br = \__mc_icu_utf8_breaker($rest);
    if ($br === null) { return false; }
    $ret = 0;
    if ($type === 0) {
        $k = $size;
        while ($k > 0) {
            $p = \__mc_icu_ubrk_next($br->bi);
            if ($p === -1) { break; }
            $ret = $p;
            $k = $k - 1;
        }
    } elseif ($type === 1) {
        while (true) {
            $p = \__mc_icu_ubrk_next($br->bi);
            if ($p === -1 || $p > $size) { break; }
            $ret = $p;
        }
    } else {
        $count = 0;
        $csize = $size;
        while (true) {
            $p = \__mc_icu_ubrk_next($br->bi);
            if ($p === -1) { break; }
            $bp = $ret;
            while ($bp < $p) {
                $count = $count + 1;
                $b = \ord($rest[$bp]);
                $step = $b < 0x80 ? 1 : ($b >= 0xF0 ? 4 : ($b >= 0xE0 ? 3 : ($b >= 0xC0 ? 2 : 0)));
                if ($step === 0 || $bp + $step > $rlen) {
                    $csize = 0;
                    break;
                }
                $bp = $bp + $step;
            }
            if ($count > $csize) { break; }
            $ret = $bp;
        }
    }
    $br->close();
    $next = $start + $ret;
    return \substr($rest, 0, $ret);
}

/** @return string[]|false */
function grapheme_str_split(string $string, int $length = 1): array|false
{
    if ($length <= 0 || $length > 1073741823) {
        throw new \ValueError("grapheme_str_split(): Argument #2 (\$length) must be greater than 0 and less than or equal to 1073741823");
    }
    if ($string === "") { return []; }
    $br = \__mc_icu_utf8_breaker($string);
    if ($br === null) { return false; }
    $out = [];
    $pos = 0;
    $i = 0;
    $current = 0;
    $endLen = 0;
    $end = 0;
    while ($pos !== -1) {
        $endLen = $pos - $current;
        $pos = \__mc_icu_ubrk_next($br->bi);
        if ($i === $length - 1) {
            if ($pos !== -1) {
                $out[] = \substr($string, $current, $pos - $current);
                $end = $pos;
                $i = 0;
                $current = $pos;
            }
        } else {
            $i = $i + 1;
        }
    }
    if ($i !== 0 && $endLen !== 0) { $out[] = \substr($string, $end, $endLen); }
    $br->close();
    return $out;
}

/** Grapheme boundaries of a UTF-16 buffer (start offsets, then the end). @return int[] */
function __mc_grapheme_bounds(\Ffi\Ptr $bi, __McIcuU16 $u): array
{
    \__mc_icu_set_text($bi, $u->buf, $u->len);
    $b = [0];
    while (true) {
        $p = \__mc_icu_ubrk_next($bi);
        if ($p === -1) { break; }
        $b[] = $p;
    }
    return $b;
}

function grapheme_levenshtein(string $string1, string $string2, int $insertion_cost = 1, int $replacement_cost = 1,
    int $deletion_cost = 1, string $locale = ""): int|false
{
    foreach ([[$insertion_cost, 3, "insertion_cost"], [$replacement_cost, 4, "replacement_cost"], [$deletion_cost, 5, "deletion_cost"]] as $c) {
        if ($c[0] <= 0 || $c[0] > 1073741823) {
            throw new \ValueError("grapheme_levenshtein(): Argument #" . $c[1] . " (\$" . $c[2] . ") must be greater than 0 and less than or equal to 1073741823");
        }
    }
    if (\strlen($string1) < \strlen($string2) && $insertion_cost === $replacement_cost && $replacement_cost === $deletion_cost) {
        $t = $string1;
        $string1 = $string2;
        $string2 = $t;
    }
    $u1 = \__mc_icu_to16($string1);
    if ($u1 === null) { return false; }
    $u2 = \__mc_icu_to16($string2);
    if ($u2 === null) {
        \__mc_icu_free($u1->buf);
        return false;
    }
    $bi = \__mc_icu_char_breaker();
    $b1 = \__mc_grapheme_bounds($bi, $u1);
    $b2 = \__mc_grapheme_bounds($bi, $u2);
    \__mc_icu_ubrk_close($bi);
    $n1 = \count($b1) - 1;
    $n2 = \count($b2) - 1;
    $result = 0;
    if ($n1 === 0) {
        $result = $n2 * $insertion_cost;
    } elseif ($n2 === 0) {
        $result = $n1 * $deletion_cost;
    } else {
        $e = \__mc_icu_err();
        $coll = \__mc_icu_ucol_open($locale, $e);
        $failed = \peek_i32($e, 0) > 0;
        \__mc_icu_free($e);
        if ($failed) {
            \__mc_icu_free($u1->buf);
            \__mc_icu_free($u2->buf);
            return false;
        }
        $p1 = [];
        $i2 = 0;
        while ($i2 <= $n2) {
            $p1[] = $i2 * $insertion_cost;
            $i2 = $i2 + 1;
        }
        $i1 = 0;
        while ($i1 < $n1) {
            $p2 = [$p1[0] + $deletion_cost];
            $i2 = 0;
            while ($i2 < $n2) {
                $same = \__mc_icu_ucol_strcoll($coll, \ptr_offset($u1->buf, $b1[$i1] * 2), $b1[$i1 + 1] - $b1[$i1],
                    \ptr_offset($u2->buf, $b2[$i2] * 2), $b2[$i2 + 1] - $b2[$i2]) === 0;
                $c0 = $same ? $p1[$i2] : $p1[$i2] + $replacement_cost;
                $c1 = $p1[$i2 + 1] + $deletion_cost;
                if ($c1 < $c0) { $c0 = $c1; }
                $c2 = $p2[$i2] + $insertion_cost;
                if ($c2 < $c0) { $c0 = $c2; }
                $p2[] = $c0;
                $i2 = $i2 + 1;
            }
            $p1 = $p2;
            $i1 = $i1 + 1;
        }
        $result = $p1[$n2];
        \__mc_icu_ucol_close($coll);
    }
    \__mc_icu_free($u1->buf);
    \__mc_icu_free($u2->buf);
    return $result;
}
