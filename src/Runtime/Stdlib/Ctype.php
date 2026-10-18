<?php

/**
 * PHP `ctype_*` family — character classification predicates.
 *
 * Pure-PHP byte-loop implementation. Matches Zend semantics:
 *   - Empty string → false for every predicate.
 *   - Integer argument in [-128, 255] is treated as a single byte (signed
 *     bytes are wrapped). Integers outside that range are coerced to their
 *     decimal-string representation, as Zend does.
 *   - String argument is scanned byte-by-byte; every byte must satisfy the
 *     predicate, otherwise false.
 *
 * No regex dependency.
 */

/**
 * Coerce a `ctype_*` argument into a byte string, mirroring Zend's
 * permissive int-as-char-code handling.
 */
#[\Manticore\Internal]
function __manticore_ctype_to_bytes(mixed $text): ?string
{
    if (\is_string($text)) {
        return $text;
    }
    if (\is_int($text)) {
        if ($text >= -128 && $text <= 255) {
            $byte = $text < 0 ? $text + 256 : $text;
            return \chr($byte);
        }
        return (string)$text;
    }
    return null;
}

/**
 * Byte classes, one flag byte per code unit (C locale: bytes >= 128 are in no
 * class). Built on first use. Bits: 1 digit, 2 lower, 4 upper, 8 space, 16 punct,
 * 32 xdigit, 64 cntrl, 128 print.
 */
final class __McCtypeTable
{
    public static string $cls = '';
}

#[\Manticore\Internal]
function __manticore_ctype_table(): string
{
    $t = '';
    for ($b = 0; $b < 256; $b = $b + 1) {
        $f = 0;
        if ($b >= 0x30 && $b <= 0x39) { $f = $f | 1 | 32; }
        if ($b >= 0x61 && $b <= 0x7A) { $f = $f | 2; }
        if ($b >= 0x41 && $b <= 0x5A) { $f = $f | 4; }
        if ($b === 0x20 || ($b >= 0x09 && $b <= 0x0D)) { $f = $f | 8; }
        if (($b >= 0x21 && $b <= 0x2F) || ($b >= 0x3A && $b <= 0x40) || ($b >= 0x5B && $b <= 0x60) || ($b >= 0x7B && $b <= 0x7E)) { $f = $f | 16; }
        if (($b >= 0x41 && $b <= 0x46) || ($b >= 0x61 && $b <= 0x66)) { $f = $f | 32; }
        if ($b <= 0x1F || $b === 0x7F) { $f = $f | 64; }
        if ($b >= 0x20 && $b <= 0x7E) { $f = $f | 128; }
        $t .= \chr($f);
    }
    return $t;
}

/**
 * True when `$text` is non-empty and every byte has a flag in `$mask`. Returns
 * false on empty / non-coercible input.
 */
#[\Manticore\Internal]
function __manticore_ctype_all(mixed $text, int $mask): bool
{
    $s = __manticore_ctype_to_bytes($text);
    if ($s === null || $s === '') {
        return false;
    }
    $t = __McCtypeTable::$cls;
    if ($t === '') {
        $t = __manticore_ctype_table();
        __McCtypeTable::$cls = $t;
    }
    $len = \strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if ((\ord($t[\ord($s[$i])]) & $mask) === 0) {
            return false;
        }
    }
    return true;
}

function ctype_alnum(mixed $text): bool { return __manticore_ctype_all($text, 7); }

function ctype_alpha(mixed $text): bool { return __manticore_ctype_all($text, 6); }

function ctype_cntrl(mixed $text): bool { return __manticore_ctype_all($text, 64); }

function ctype_digit(mixed $text): bool { return __manticore_ctype_all($text, 1); }

function ctype_lower(mixed $text): bool { return __manticore_ctype_all($text, 2); }

function ctype_upper(mixed $text): bool { return __manticore_ctype_all($text, 4); }

function ctype_graph(mixed $text): bool { return __manticore_ctype_all($text, 23); }

function ctype_print(mixed $text): bool { return __manticore_ctype_all($text, 128); }

function ctype_punct(mixed $text): bool { return __manticore_ctype_all($text, 16); }

function ctype_space(mixed $text): bool { return __manticore_ctype_all($text, 8); }

function ctype_xdigit(mixed $text): bool { return __manticore_ctype_all($text, 32); }
