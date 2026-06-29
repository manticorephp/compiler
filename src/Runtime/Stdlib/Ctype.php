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
 * No regex dependency. Replaces `extensions/ctype.php` and the now-deleted
 * `crates/manticore-runtime/src/builtins/ctype.rs`.
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
 * Byte-by-byte predicate runner. Returns false on empty / non-coercible
 * input; true only when every byte satisfies `$pred`.
 *
 * @param callable(int):bool $pred
 */
#[\Manticore\Internal]
function __manticore_ctype_all(mixed $text, callable $pred): bool
{
    $s = __manticore_ctype_to_bytes($text);
    if ($s === null || $s === '') {
        return false;
    }
    $len = \strlen($s);
    for ($i = 0; $i < $len; $i++) {
        if (!$pred(\ord($s[$i]))) {
            return false;
        }
    }
    return true;
}

function ctype_alnum(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => ($b >= 0x30 && $b <= 0x39)
            || ($b >= 0x41 && $b <= 0x5A)
            || ($b >= 0x61 && $b <= 0x7A),
    );
}

function ctype_alpha(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => ($b >= 0x41 && $b <= 0x5A) || ($b >= 0x61 && $b <= 0x7A),
    );
}

function ctype_cntrl(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => $b <= 0x1F || $b === 0x7F,
    );
}

function ctype_digit(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => $b >= 0x30 && $b <= 0x39,
    );
}

function ctype_lower(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => $b >= 0x61 && $b <= 0x7A,
    );
}

function ctype_upper(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => $b >= 0x41 && $b <= 0x5A,
    );
}

function ctype_graph(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => $b >= 0x21 && $b <= 0x7E,
    );
}

function ctype_print(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => $b >= 0x20 && $b <= 0x7E,
    );
}

function ctype_punct(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => ($b >= 0x21 && $b <= 0x2F)
            || ($b >= 0x3A && $b <= 0x40)
            || ($b >= 0x5B && $b <= 0x60)
            || ($b >= 0x7B && $b <= 0x7E),
    );
}

function ctype_space(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => $b === 0x20
            || $b === 0x09
            || $b === 0x0A
            || $b === 0x0B
            || $b === 0x0C
            || $b === 0x0D,
    );
}

function ctype_xdigit(mixed $text): bool
{
    return __manticore_ctype_all(
        $text,
        static fn(int $b): bool => ($b >= 0x30 && $b <= 0x39)
            || ($b >= 0x41 && $b <= 0x46)
            || ($b >= 0x61 && $b <= 0x66),
    );
}
