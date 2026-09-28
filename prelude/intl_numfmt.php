<?php

/**
 * ext/intl NumberFormatter over ICU's unum_* (php-src ext/intl/formatter,
 * transcribed). Needs prelude/intl.php. Every ICU call that fills a UChar
 * buffer is made twice: measure, then fill.
 */

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_open')]
function __mc_icu_unum_open(#[\Ffi\CType('int')] int $style, \Ffi\Ptr $pattern, #[\Ffi\CType('int')] int $patLen,
    string $locale, \Ffi\Ptr $parseErr, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_close')]
function __mc_icu_unum_close(\Ffi\Ptr $fmt): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_formatInt64'), \Ffi\CType('int')]
function __mc_icu_unum_formatInt64(\Ffi\Ptr $fmt, #[\Ffi\CType('longlong')] int $v, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $pos, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_format'), \Ffi\CType('int')]
function __mc_icu_unum_format(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $v, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $pos, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_formatDouble'), \Ffi\CType('int')]
function __mc_icu_unum_formatDouble(\Ffi\Ptr $fmt, #[\Ffi\CType('double')] float $v, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $pos, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_formatDoubleCurrency'), \Ffi\CType('int')]
function __mc_icu_unum_formatDoubleCurrency(\Ffi\Ptr $fmt, #[\Ffi\CType('double')] float $v, \Ffi\Ptr $currency,
    \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $pos, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_parse'), \Ffi\CType('int')]
function __mc_icu_unum_parse(\Ffi\Ptr $fmt, \Ffi\Ptr $text, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $pos,
    \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_parseInt64'), \Ffi\CType('longlong')]
function __mc_icu_unum_parseInt64(\Ffi\Ptr $fmt, \Ffi\Ptr $text, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $pos,
    \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_parseDouble'), \Ffi\CType('double')]
function __mc_icu_unum_parseDouble(\Ffi\Ptr $fmt, \Ffi\Ptr $text, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $pos,
    \Ffi\Ptr $err): float { return 0.0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_parseDoubleCurrency'), \Ffi\CType('double')]
function __mc_icu_unum_parseDoubleCurrency(\Ffi\Ptr $fmt, \Ffi\Ptr $text, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $pos, \Ffi\Ptr $currency, \Ffi\Ptr $err): float { return 0.0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_getAttribute'), \Ffi\CType('int')]
function __mc_icu_unum_getAttribute(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $attr): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_setAttribute')]
function __mc_icu_unum_setAttribute(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $attr, #[\Ffi\CType('int')] int $v): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_getDoubleAttribute'), \Ffi\CType('double')]
function __mc_icu_unum_getDoubleAttribute(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $attr): float { return 0.0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_setDoubleAttribute')]
function __mc_icu_unum_setDoubleAttribute(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $attr,
    #[\Ffi\CType('double')] float $v): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_getTextAttribute'), \Ffi\CType('int')]
function __mc_icu_unum_getTextAttribute(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $tag, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_setTextAttribute')]
function __mc_icu_unum_setTextAttribute(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $tag, \Ffi\Ptr $v,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_getSymbol'), \Ffi\CType('int')]
function __mc_icu_unum_getSymbol(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $sym, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_setSymbol')]
function __mc_icu_unum_setSymbol(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $sym, \Ffi\Ptr $v,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_toPattern'), \Ffi\CType('int')]
function __mc_icu_unum_toPattern(\Ffi\Ptr $fmt, #[\Ffi\CType('char')] int $localized, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_applyPattern')]
function __mc_icu_unum_applyPattern(\Ffi\Ptr $fmt, #[\Ffi\CType('char')] int $localized, \Ffi\Ptr $pattern,
    #[\Ffi\CType('int')] int $len, \Ffi\Ptr $parseErr, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('unum_getLocaleByType')]
function __mc_icu_unum_getLocaleByType(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $type, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getISO3Language')]
function __mc_icu_uloc_getISO3Language(string $locale): \Ffi\Ptr {}

/** php's canonicalize_locale_string: ICU-canonical form of `$locale`, or `$locale` itself when that fails. */
function __mc_intl_canonical_locale(string $locale): string
{
    $buf = \__mc_icu_malloc(158);
    $e = \__mc_icu_err();
    $n = \__mc_icu_uloc_canonicalize($locale, $buf, 157, $e);
    $ok = \peek_i32($e, 0) <= 0 && $n > 0;
    $out = $ok ? \str_from_buffer($buf, $n) : $locale;
    \__mc_icu_free($e);
    \__mc_icu_free($buf);
    return $out;
}

/**
 * Call `$fill(buf, cap, err)` — an ICU API writing UChars and answering the
 * length — twice (measure, fill); UTF-8 of the result, or null with the ICU
 * error code in __McIcuStatus::$code.
 */
function __mc_icu_uchars(\Closure $fill): ?string
{
    $e = \__mc_icu_err();
    $n = $fill(\int_to_ptr(0), 0, $e);
    $c = \peek_i32($e, 0);
    if ($c > 0 && $c !== 15) {
        \__mc_icu_free($e);
        __McIcuStatus::$code = $c;
        return null;
    }
    \poke_i32($e, 0, 0);
    $buf = \__mc_icu_malloc(($n + 1) * 2);
    $n = $fill($buf, $n + 1, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($c > 0) {
        \__mc_icu_free($buf);
        __McIcuStatus::$code = $c;
        return null;
    }
    $out = \__mc_icu_to8($buf, $n);
    \__mc_icu_free($buf);
    return $out;
}

class NumberFormatter
{
    public const PATTERN_DECIMAL = 0;
    public const DECIMAL = 1;
    public const DECIMAL_COMPACT_SHORT = 14;
    public const DECIMAL_COMPACT_LONG = 15;
    public const CURRENCY = 2;
    public const PERCENT = 3;
    public const SCIENTIFIC = 4;
    public const SPELLOUT = 5;
    public const ORDINAL = 6;
    public const DURATION = 7;
    public const PATTERN_RULEBASED = 9;
    public const IGNORE = 0;
    public const CURRENCY_ISO = 10;
    public const CURRENCY_PLURAL = 11;
    public const CURRENCY_ACCOUNTING = 12;
    public const CASH_CURRENCY = 13;
    public const CURRENCY_STANDARD = 16;
    public const DEFAULT_STYLE = 1;
    public const ROUND_CEILING = 0;
    public const ROUND_FLOOR = 1;
    public const ROUND_DOWN = 2;
    public const ROUND_UP = 3;
    public const ROUND_TOWARD_ZERO = 2;
    public const ROUND_AWAY_FROM_ZERO = 3;
    public const ROUND_HALFEVEN = 4;
    public const ROUND_HALFODD = 8;
    public const ROUND_HALFDOWN = 5;
    public const ROUND_HALFUP = 6;
    public const PAD_BEFORE_PREFIX = 0;
    public const PAD_AFTER_PREFIX = 1;
    public const PAD_BEFORE_SUFFIX = 2;
    public const PAD_AFTER_SUFFIX = 3;
    public const PARSE_INT_ONLY = 0;
    public const GROUPING_USED = 1;
    public const DECIMAL_ALWAYS_SHOWN = 2;
    public const MAX_INTEGER_DIGITS = 3;
    public const MIN_INTEGER_DIGITS = 4;
    public const INTEGER_DIGITS = 5;
    public const MAX_FRACTION_DIGITS = 6;
    public const MIN_FRACTION_DIGITS = 7;
    public const FRACTION_DIGITS = 8;
    public const MULTIPLIER = 9;
    public const GROUPING_SIZE = 10;
    public const ROUNDING_MODE = 11;
    public const ROUNDING_INCREMENT = 12;
    public const FORMAT_WIDTH = 13;
    public const PADDING_POSITION = 14;
    public const SECONDARY_GROUPING_SIZE = 15;
    public const SIGNIFICANT_DIGITS_USED = 16;
    public const MIN_SIGNIFICANT_DIGITS = 17;
    public const MAX_SIGNIFICANT_DIGITS = 18;
    public const LENIENT_PARSE = 19;
    public const POSITIVE_PREFIX = 0;
    public const POSITIVE_SUFFIX = 1;
    public const NEGATIVE_PREFIX = 2;
    public const NEGATIVE_SUFFIX = 3;
    public const PADDING_CHARACTER = 4;
    public const CURRENCY_CODE = 5;
    public const DEFAULT_RULESET = 6;
    public const PUBLIC_RULESETS = 7;
    public const DECIMAL_SEPARATOR_SYMBOL = 0;
    public const GROUPING_SEPARATOR_SYMBOL = 1;
    public const PATTERN_SEPARATOR_SYMBOL = 2;
    public const PERCENT_SYMBOL = 3;
    public const ZERO_DIGIT_SYMBOL = 4;
    public const DIGIT_SYMBOL = 5;
    public const MINUS_SIGN_SYMBOL = 6;
    public const PLUS_SIGN_SYMBOL = 7;
    public const CURRENCY_SYMBOL = 8;
    public const INTL_CURRENCY_SYMBOL = 9;
    public const MONETARY_SEPARATOR_SYMBOL = 10;
    public const EXPONENTIAL_SYMBOL = 11;
    public const PERMILL_SYMBOL = 12;
    public const PAD_ESCAPE_SYMBOL = 13;
    public const INFINITY_SYMBOL = 14;
    public const NAN_SYMBOL = 15;
    public const SIGNIFICANT_DIGIT_SYMBOL = 16;
    public const MONETARY_GROUPING_SEPARATOR_SYMBOL = 17;
    public const TYPE_DEFAULT = 0;
    public const TYPE_INT32 = 1;
    public const TYPE_INT64 = 2;
    public const TYPE_DOUBLE = 3;
    public const TYPE_CURRENCY = 4;

    /** numfmt_create()'s name while it constructs: errors carry it and nothing is thrown. */
    public static string $__mcCreating = "";

    private ?\Ffi\Ptr $fmt = null;
    private int $errCode = 0;
    private string $errMessage = "";

    public function __construct(string $locale, int $style, ?string $pattern = null)
    {
        $creating = self::$__mcCreating;
        $code = $this->__mcOpen($creating !== "" ? $creating : "NumberFormatter::__construct", $locale, $style, $pattern);
        if ($code > 0 && $creating === "") {
            throw new \IntlException("NumberFormatter::__construct(): number formatter creation failed");
        }
    }

    public function __destruct()
    {
        if ($this->fmt !== null) { \__mc_icu_unum_close($this->fmt); }
    }

    /** numfmt_ctor: 0 on success, else the ICU error (the ValueError for a bad locale is thrown here). */
    public function __mcOpen(string $fn, string $locale, int $style, ?string $pattern): int
    {
        \__mc_intl_reset();
        $pat = null;
        if ($pattern !== null && $pattern !== "") {
            $pat = \__mc_icu_to16($pattern);
            if ($pat === null) {
                \__mc_intl_fail($fn, "error converting pattern to UTF-16", 10);
                return 10;
            }
        }
        if ($locale === "") { $locale = \__mc_intl_default_locale(); }
        if (\cstr_to_str(\__mc_icu_uloc_getISO3Language($locale)) === "") {
            if ($pat !== null) { \__mc_icu_free($pat->buf); }
            throw new \ValueError($fn . "(): Argument #1 (\$locale) \"" . $locale . "\" is invalid");
        }
        $e = \__mc_icu_err();
        $this->fmt = \__mc_icu_unum_open($style, $pat === null ? \int_to_ptr(0) : $pat->buf, $pat === null ? 0 : $pat->len,
            \__mc_intl_canonical_locale($locale), \int_to_ptr(0), $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($pat !== null) { \__mc_icu_free($pat->buf); }
        if ($code > 0) {
            $this->fmt = null;
            \__mc_intl_fail($fn, "number formatter creation failed", $code);
        }
        return $code;
    }

    public function __mcOk(): bool
    {
        return $this->fmt !== null;
    }

    public static function create(string $locale, int $style, ?string $pattern = null): ?NumberFormatter
    {
        return \numfmt_create($locale, $style, $pattern);
    }

    private function begin(): \Ffi\Ptr
    {
        $this->errCode = 0;
        $this->errMessage = "";
        \__mc_intl_reset();
        if ($this->fmt === null) { throw new \Error("Found unconstructed NumberFormatter"); }
        return $this->fmt;
    }

    /** INTL_METHOD_CHECK_STATUS: record the failure on the object and globally. */
    private function fail(string $fn, string $what, int $code): bool
    {
        $this->errCode = $code;
        $this->errMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
        return false;
    }

    public function __mcFormat(string $fn, int $argNo, int|float $num, int $type): string|false
    {
        $fmt = $this->begin();
        if ($type === 0) { $type = \is_int($num) ? 2 : 3; }
        if ($type === 4) {
            throw new \ValueError($fn . "(): Argument #" . $argNo . " (\$type) cannot be NumberFormatter::TYPE_CURRENCY constant, "
                . ($argNo === 2 ? "use NumberFormatter::formatCurrency() method instead" : "use numfmt_format_currency() function instead"));
        }
        if ($type < 1 || $type > 3) {
            throw new \ValueError($fn . "(): Argument #" . $argNo . " (\$type) must be a NumberFormatter::TYPE_* constant");
        }
        $out = null;
        if ($type === 1) {
            $v = ((int)$num) & 0xFFFFFFFF;
            if ($v >= 0x80000000) { $v = $v - 0x100000000; }
            $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_format($fmt, $v, $b, $c, \int_to_ptr(0), $e));
        } elseif ($type === 2) {
            $v = (int)$num;
            $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_formatInt64($fmt, $v, $b, $c, \int_to_ptr(0), $e));
        } else {
            $v = (float)$num;
            $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_formatDouble($fmt, $v, $b, $c, \int_to_ptr(0), $e));
        }
        return $out === null ? $this->fail($fn, "Number formatting failed", __McIcuStatus::$code) : $out;
    }

    public function format(int|float $num, int $type = NumberFormatter::TYPE_DEFAULT): string|false
    {
        return $this->__mcFormat("NumberFormatter::format", 2, $num, $type);
    }

    public function __mcFormatCurrency(string $fn, float $amount, string $currency): string|false
    {
        $fmt = $this->begin();
        $cur = \__mc_icu_to16($currency);
        if ($cur === null) { return $this->fail($fn, "Currency conversion to UTF-16 failed", 10); }
        $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_formatDoubleCurrency($fmt, $amount, $cur->buf, $b, $c, \int_to_ptr(0), $e));
        \__mc_icu_free($cur->buf);
        return $out === null ? $this->fail($fn, "Number formatting failed", __McIcuStatus::$code) : $out;
    }

    public function formatCurrency(float $amount, string $currency): string|false
    {
        return $this->__mcFormatCurrency("NumberFormatter::formatCurrency", $amount, $currency);
    }

    /** A UTF-8 byte offset as a UTF-16 index of `$s`; -1 when the prefix is not UTF-8. */
    private static function to16offset(string $s, int $pos): int
    {
        if ($pos < 0 || $pos > \strlen($s)) { return $pos; }
        $u = \__mc_icu_to16(\substr($s, 0, $pos));
        if ($u === null) { return -1; }
        \__mc_icu_free($u->buf);
        return $u->len;
    }

    /** A UTF-16 index of `$u` as a UTF-8 byte offset. */
    private static function to8offset(__McIcuU16 $u, int $pos): int
    {
        if ($pos < 0 || $pos > $u->len) { return $pos; }
        return \strlen(\__mc_icu_to8($u->buf, $pos));
    }

    public function __mcParse(string $fn, int $argNo, string $string, int $type, mixed &$offset): int|float|false
    {
        $fmt = $this->begin();
        $u = \__mc_icu_to16($string);
        if ($u === null) { return $this->fail($fn, "String conversion to UTF-16 failed", 10); }
        $posCell = \int_to_ptr(0);
        if ($offset !== null) {
            $p = self::to16offset($string, (int)$offset);
            if ($p === -1 && (int)$offset !== -1) {
                \__mc_icu_free($u->buf);
                return $this->fail($fn, "Invalid UTF-8 offset", 10);
            }
            $posCell = \__mc_icu_malloc(8);
            \poke_i32($posCell, 0, $p);
        }
        if ($type === 4) {
            \__mc_icu_free($u->buf);
            throw new \ValueError($fn . "(): Argument #" . $argNo . " (\$type) cannot be NumberFormatter::TYPE_CURRENCY constant, "
                . ($argNo === 2 ? "use NumberFormatter::parseCurrency() method instead" : "use numfmt_parse_currency() function instead"));
        }
        if ($type < 1 || $type > 3) {
            \__mc_icu_free($u->buf);
            throw new \ValueError($fn . "(): Argument #" . $argNo . " (\$type) must be a NumberFormatter::TYPE_* constant");
        }
        $e = \__mc_icu_err();
        $val = 0;
        if ($type === 1) {
            $val = \__mc_icu_unum_parse($fmt, $u->buf, $u->len, $posCell, $e);
        } elseif ($type === 2) {
            $val = \__mc_icu_unum_parseInt64($fmt, $u->buf, $u->len, $posCell, $e);
        } else {
            $val = \__mc_icu_unum_parseDouble($fmt, $u->buf, $u->len, $posCell, $e);
        }
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($offset !== null) {
            $offset = self::to8offset($u, \peek_i32($posCell, 0));
            \__mc_icu_free($posCell);
        }
        \__mc_icu_free($u->buf);
        return $code > 0 ? $this->fail($fn, "Number parsing failed", $code) : $val;
    }

    public function parse(string $string, int $type = NumberFormatter::TYPE_DOUBLE, &$offset = null): int|float|false
    {
        return $this->__mcParse("NumberFormatter::parse", 2, $string, $type, $offset);
    }

    public function __mcParseCurrency(string $fn, string $string, mixed &$currency, mixed &$offset): float|false
    {
        $fmt = $this->begin();
        $u = \__mc_icu_to16($string);
        if ($u === null) { return $this->fail($fn, "String conversion to UTF-16 failed", 10); }
        $posCell = \int_to_ptr(0);
        if ($offset !== null) {
            $p = self::to16offset($string, (int)$offset);
            if ($p === -1 && (int)$offset !== -1) {
                \__mc_icu_free($u->buf);
                return $this->fail($fn, "Invalid UTF-8 offset", 10);
            }
            $posCell = \__mc_icu_malloc(8);
            \poke_i32($posCell, 0, $p);
        }
        $cur = \__mc_icu_malloc(16);
        \poke_i64($cur, 0, 0);
        \poke_i64($cur, 8, 0);
        $e = \__mc_icu_err();
        $val = \__mc_icu_unum_parseDoubleCurrency($fmt, $u->buf, $u->len, $posCell, $cur, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($offset !== null) {
            $offset = self::to8offset($u, \peek_i32($posCell, 0));
            \__mc_icu_free($posCell);
        }
        \__mc_icu_free($u->buf);
        if ($code > 0) {
            \__mc_icu_free($cur);
            return $this->fail($fn, "Number parsing failed", $code);
        }
        $n = 0;
        while ($n < 4 && \peek_u16($cur, $n * 2) !== 0) { $n = $n + 1; }
        $currency = \__mc_icu_to8($cur, $n);
        \__mc_icu_free($cur);
        return $val;
    }

    public function parseCurrency(string $string, &$currency, &$offset = null): float|false
    {
        return $this->__mcParseCurrency("NumberFormatter::parseCurrency", $string, $currency, $offset);
    }

    /** The attributes unum_getAttribute answers as an int (every one but ROUNDING_INCREMENT). */
    private static function intAttribute(int $a): bool
    {
        return $a >= 0 && $a <= 19 && $a !== 12;
    }

    public function __mcSetAttribute(string $fn, int $attribute, int|float $value): bool
    {
        $fmt = $this->begin();
        if (self::intAttribute($attribute)) {
            \__mc_icu_unum_setAttribute($fmt, $attribute, (int)$value);
        } elseif ($attribute === 12) {
            \__mc_icu_unum_setDoubleAttribute($fmt, $attribute, (float)$value);
        } else {
            return $this->fail($fn, "Error setting attribute value", 16);
        }
        return true;
    }

    public function setAttribute(int $attribute, int|float $value): bool
    {
        return $this->__mcSetAttribute("NumberFormatter::setAttribute", $attribute, $value);
    }

    public function __mcGetAttribute(string $fn, int $attribute): int|float|false
    {
        $fmt = $this->begin();
        if (self::intAttribute($attribute)) {
            $v = \__mc_icu_unum_getAttribute($fmt, $attribute);
            return $v === -1 ? $this->fail($fn, "Error getting attribute value", 16) : $v;
        }
        if ($attribute === 12) {
            $v = \__mc_icu_unum_getDoubleAttribute($fmt, $attribute);
            return $v === -1.0 ? $this->fail($fn, "Error getting attribute value", 16) : $v;
        }
        return $this->fail($fn, "Error getting attribute value", 16);
    }

    public function getAttribute(int $attribute): int|float|false
    {
        return $this->__mcGetAttribute("NumberFormatter::getAttribute", $attribute);
    }

    public function __mcSetTextAttribute(string $fn, int $attribute, string $value): bool
    {
        $fmt = $this->begin();
        $v = \__mc_icu_to16($value);
        if ($v === null) { return $this->fail($fn, "Error converting attribute value to UTF-16", 10); }
        $e = \__mc_icu_err();
        \__mc_icu_unum_setTextAttribute($fmt, $attribute, $v->buf, $v->len, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        \__mc_icu_free($v->buf);
        return $code > 0 ? $this->fail($fn, "Error setting text attribute", $code) : true;
    }

    public function setTextAttribute(int $attribute, string $value): bool
    {
        return $this->__mcSetTextAttribute("NumberFormatter::setTextAttribute", $attribute, $value);
    }

    public function __mcGetTextAttribute(string $fn, int $attribute): string|false
    {
        $fmt = $this->begin();
        $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_getTextAttribute($fmt, $attribute, $b, $c, $e));
        return $out === null ? $this->fail($fn, "Error getting attribute value", __McIcuStatus::$code) : $out;
    }

    public function getTextAttribute(int $attribute): string|false
    {
        return $this->__mcGetTextAttribute("NumberFormatter::getTextAttribute", $attribute);
    }

    public function __mcSetSymbol(string $fn, int $symbol, string $value): bool
    {
        if ($symbol >= 28 || $symbol < 0) {
            \__mc_intl_reset();
            \__mc_intl_fail($fn, "invalid symbol value", 1);
            return false;
        }
        $fmt = $this->begin();
        $v = \__mc_icu_to16($value);
        if ($v === null) { return $this->fail($fn, "Error converting symbol value to UTF-16", 10); }
        $e = \__mc_icu_err();
        \__mc_icu_unum_setSymbol($fmt, $symbol, $v->buf, $v->len, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        \__mc_icu_free($v->buf);
        return $code > 0 ? $this->fail($fn, "Error setting symbol value", $code) : true;
    }

    public function setSymbol(int $symbol, string $value): bool
    {
        return $this->__mcSetSymbol("NumberFormatter::setSymbol", $symbol, $value);
    }

    public function __mcGetSymbol(string $fn, int $symbol): string|false
    {
        if ($symbol >= 28 || $symbol < 0) {
            \__mc_intl_reset();
            \__mc_intl_fail($fn, "invalid symbol value", 1);
            return false;
        }
        $fmt = $this->begin();
        $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_getSymbol($fmt, $symbol, $b, $c, $e));
        return $out === null ? $this->fail($fn, "Error getting symbol value", __McIcuStatus::$code) : $out;
    }

    public function getSymbol(int $symbol): string|false
    {
        return $this->__mcGetSymbol("NumberFormatter::getSymbol", $symbol);
    }

    public function __mcSetPattern(string $fn, string $pattern): bool
    {
        $fmt = $this->begin();
        $v = \__mc_icu_to16($pattern);
        if ($v === null) { return $this->fail($fn, "Error converting pattern to UTF-16", 10); }
        $pe = \__mc_icu_malloc(72);
        \poke_i32($pe, 0, 0);
        \poke_i32($pe, 4, 0);
        $e = \__mc_icu_err();
        \__mc_icu_unum_applyPattern($fmt, 0, $v->buf, $v->len, $pe, $e);
        $code = \peek_i32($e, 0);
        $line = \peek_i32($pe, 0);
        $off = \peek_i32($pe, 4);
        \__mc_icu_free($e);
        \__mc_icu_free($pe);
        \__mc_icu_free($v->buf);
        if ($code > 0) {
            $this->errCode = $code;
            $this->errMessage = \__mc_intl_message($fn, "Error setting pattern value at line " . $line . ", offset " . $off, $code);
            return false;
        }
        return true;
    }

    public function setPattern(string $pattern): bool
    {
        return $this->__mcSetPattern("NumberFormatter::setPattern", $pattern);
    }

    public function __mcGetPattern(string $fn): string|false
    {
        $fmt = $this->begin();
        $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_unum_toPattern($fmt, 0, $b, $c, $e));
        return $out === null ? $this->fail($fn, "Error getting formatter pattern", __McIcuStatus::$code) : $out;
    }

    public function getPattern(): string|false
    {
        return $this->__mcGetPattern("NumberFormatter::getPattern");
    }

    public function __mcGetLocale(string $fn, int $type): string|false
    {
        $fmt = $this->begin();
        $e = \__mc_icu_err();
        $p = \__mc_icu_unum_getLocaleByType($fmt, $type, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code > 0) { return $this->fail($fn, "Error getting locale", $code); }
        return \cstr_to_str($p);
    }

    public function getLocale(int $type = ULOC_ACTUAL_LOCALE): string|false
    {
        return $this->__mcGetLocale("NumberFormatter::getLocale", $type);
    }

    public function getErrorCode(): int
    {
        return $this->errCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errMessage !== "" ? $this->errMessage : \intl_error_name($this->errCode);
    }
}

function numfmt_create(string $locale, int $style, ?string $pattern = null): ?NumberFormatter
{
    NumberFormatter::$__mcCreating = "numfmt_create";
    try {
        $f = new NumberFormatter($locale, $style, $pattern);
    } finally {
        NumberFormatter::$__mcCreating = "";
    }
    return $f->__mcOk() ? $f : null;
}

function numfmt_format(NumberFormatter $formatter, int|float $num, int $type = NumberFormatter::TYPE_DEFAULT): string|false
{
    return $formatter->__mcFormat("numfmt_format", 3, $num, $type);
}

function numfmt_parse(NumberFormatter $formatter, string $string, int $type = NumberFormatter::TYPE_DOUBLE, &$offset = null): int|float|false
{
    return $formatter->__mcParse("numfmt_parse", 3, $string, $type, $offset);
}

function numfmt_format_currency(NumberFormatter $formatter, float $amount, string $currency): string|false
{
    return $formatter->__mcFormatCurrency("numfmt_format_currency", $amount, $currency);
}

function numfmt_parse_currency(NumberFormatter $formatter, string $string, &$currency, &$offset = null): float|false
{
    return $formatter->__mcParseCurrency("numfmt_parse_currency", $string, $currency, $offset);
}

function numfmt_set_attribute(NumberFormatter $formatter, int $attribute, int|float $value): bool
{
    return $formatter->__mcSetAttribute("numfmt_set_attribute", $attribute, $value);
}

function numfmt_get_attribute(NumberFormatter $formatter, int $attribute): int|float|false
{
    return $formatter->__mcGetAttribute("numfmt_get_attribute", $attribute);
}

function numfmt_set_text_attribute(NumberFormatter $formatter, int $attribute, string $value): bool
{
    return $formatter->__mcSetTextAttribute("numfmt_set_text_attribute", $attribute, $value);
}

function numfmt_get_text_attribute(NumberFormatter $formatter, int $attribute): string|false
{
    return $formatter->__mcGetTextAttribute("numfmt_get_text_attribute", $attribute);
}

function numfmt_set_symbol(NumberFormatter $formatter, int $symbol, string $value): bool
{
    return $formatter->__mcSetSymbol("numfmt_set_symbol", $symbol, $value);
}

function numfmt_get_symbol(NumberFormatter $formatter, int $symbol): string|false
{
    return $formatter->__mcGetSymbol("numfmt_get_symbol", $symbol);
}

function numfmt_set_pattern(NumberFormatter $formatter, string $pattern): bool
{
    return $formatter->__mcSetPattern("numfmt_set_pattern", $pattern);
}

function numfmt_get_pattern(NumberFormatter $formatter): string|false
{
    return $formatter->__mcGetPattern("numfmt_get_pattern");
}

function numfmt_get_locale(NumberFormatter $formatter, int $type = ULOC_ACTUAL_LOCALE): string|false
{
    return $formatter->__mcGetLocale("numfmt_get_locale", $type);
}

function numfmt_get_error_code(NumberFormatter $formatter): int
{
    return $formatter->getErrorCode();
}

function numfmt_get_error_message(NumberFormatter $formatter): string
{
    return $formatter->getErrorMessage();
}
