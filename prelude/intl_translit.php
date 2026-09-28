<?php

/**
 * ext/intl Transliterator over ICU's utrans_* (php-src ext/intl/transliterator,
 * transcribed). Needs prelude/intl.php.
 *
 * transliterator_transliterate() given an ID it cannot open: Zend warns and
 * answers false; here it throws IntlException (the project's "throw where Zend
 * warns" rule).
 */

#[\Ffi\Library('icui18n'), \Ffi\Symbol('utrans_openU')]
function __mc_icu_utrans_openU(\Ffi\Ptr $id, #[\Ffi\CType('int')] int $idLen, #[\Ffi\CType('int')] int $dir,
    \Ffi\Ptr $rules, #[\Ffi\CType('int')] int $rulesLen, \Ffi\Ptr $parseErr, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('utrans_openInverse')]
function __mc_icu_utrans_openInverse(\Ffi\Ptr $trans, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('utrans_close')]
function __mc_icu_utrans_close(\Ffi\Ptr $trans): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('utrans_getUnicodeID')]
function __mc_icu_utrans_getUnicodeID(\Ffi\Ptr $trans, \Ffi\Ptr $len): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('utrans_transUChars')]
function __mc_icu_utrans_transUChars(\Ffi\Ptr $trans, \Ffi\Ptr $text, \Ffi\Ptr $textLen, #[\Ffi\CType('int')] int $cap,
    #[\Ffi\CType('int')] int $start, \Ffi\Ptr $limit, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('utrans_openIDs')]
function __mc_icu_utrans_openIDs(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uenum_unext')]
function __mc_icu_uenum_unext(\Ffi\Ptr $en, \Ffi\Ptr $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('memcpy')]
function __mc_icu_memcpy(\Ffi\Ptr $dst, \Ffi\Ptr $src, #[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}

/**
 * intl_parse_error_to_string: "parse error on line L, offset O after "PRE" before
 * "POST"" (each part only when present). `$pe` is a UParseError.
 */
function __mc_icu_parse_error_string(\Ffi\Ptr $pe): string
{
    $line = \peek_i32($pe, 0);
    $off = \peek_i32($pe, 4);
    $pre = \__mc_icu_uchars_z(\ptr_offset($pe, 8), 16);
    $post = \__mc_icu_uchars_z(\ptr_offset($pe, 40), 16);
    $out = "parse error ";
    $any = false;
    if ($line > 0) {
        $out = $out . "on line " . $line;
        $any = true;
    }
    if ($off >= 0) {
        $out = $out . ($any ? ", " : "at ") . "offset " . $off;
        $any = true;
    }
    if ($pre !== "") {
        $out = $out . ($any ? ", " : "") . "after \"" . $pre . "\"";
        $any = true;
    }
    if ($post !== "") {
        $out = $out . ($any ? ", " : "") . "before or at \"" . $post . "\"";
        $any = true;
    }
    return $any ? $out : "no parse error";
}

/** UTF-8 of a NUL-terminated UChar run of at most `$max` units at `$p`. */
function __mc_icu_uchars_z(\Ffi\Ptr $p, int $max): string
{
    $n = 0;
    while ($n < $max && \peek_u16($p, $n * 2) !== 0) { $n = $n + 1; }
    return \__mc_icu_to8($p, $n);
}

class Transliterator
{
    public const FORWARD = 0;
    public const REVERSE = 1;

    public readonly string $id;

    private ?\Ffi\Ptr $trans = null;
    private int $errCode = 0;
    private string $errMessage = "";

    final private function __construct() {}

    public function __destruct()
    {
        if ($this->trans !== null) { \__mc_icu_utrans_close($this->trans); }
    }

    /** transliterator_object_construct: take ownership of `$t` and read its ID. */
    private static function wrap(\Ffi\Ptr $t): Transliterator
    {
        $o = new Transliterator();
        $o->trans = $t;
        $lenCell = \__mc_icu_malloc(8);
        \poke_i32($lenCell, 0, 0);
        $id = \__mc_icu_utrans_getUnicodeID($t, $lenCell);
        $o->id = \__mc_icu_to8($id, \peek_i32($lenCell, 0));
        \__mc_icu_free($lenCell);
        return $o;
    }

    private static function checkDirection(int $direction): void
    {
        if ($direction !== 0 && $direction !== 1) {
            throw new \ValueError(\__McIcuFn::$name . "(): Argument #2 (\$direction) must be either Transliterator::FORWARD or Transliterator::REVERSE");
        }
    }

    public static function __mcCreate(string $fn, string $id, int $direction): ?Transliterator
    {
        \__mc_intl_reset();
        __McIcuFn::$name = $fn;
        self::checkDirection($direction);
        $u = \__mc_icu_to16($id);
        if ($u === null) {
            \__mc_intl_fail($fn, "String conversion of id to UTF-16 failed", 10);
            return null;
        }
        $pe = \__mc_icu_malloc(72);
        $e = \__mc_icu_err();
        $t = \__mc_icu_utrans_openU($u->buf, $u->len, $direction, \int_to_ptr(0), -1, $pe, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        \__mc_icu_free($pe);
        \__mc_icu_free($u->buf);
        if ($code > 0) {
            \__mc_intl_fail($fn, "unable to open ICU transliterator with id \"" . $id . "\"", $code);
            return null;
        }
        return self::wrap($t);
    }

    public static function create(string $id, int $direction = Transliterator::FORWARD): ?Transliterator
    {
        return self::__mcCreate("Transliterator::create", $id, $direction);
    }

    public static function __mcCreateFromRules(string $fn, string $rules, int $direction): ?Transliterator
    {
        \__mc_intl_reset();
        __McIcuFn::$name = $fn;
        self::checkDirection($direction);
        $u = \__mc_icu_to16($rules);
        if ($u === null) {
            \__mc_intl_fail($fn, "String conversion of rules to UTF-16 failed", 10);
            return null;
        }
        $id = \__mc_icu_to16("RulesTransPHP");
        $pe = \__mc_icu_malloc(72);
        \poke_i32($pe, 0, 0);
        \poke_i32($pe, 4, 0);
        \poke_i64($pe, 8, 0);
        \poke_i64($pe, 40, 0);
        $e = \__mc_icu_err();
        $t = \__mc_icu_utrans_openU($id->buf, $id->len, $direction, $u->buf, $u->len, $pe, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        \__mc_icu_free($u->buf);
        \__mc_icu_free($id->buf);
        if ($code > 0) {
            $msg = "unable to create ICU transliterator from rules (" . \__mc_icu_parse_error_string($pe) . ")";
            \__mc_icu_free($pe);
            \__mc_intl_fail($fn, $msg, $code);
            return null;
        }
        \__mc_icu_free($pe);
        return self::wrap($t);
    }

    public static function createFromRules(string $rules, int $direction = Transliterator::FORWARD): ?Transliterator
    {
        return self::__mcCreateFromRules("Transliterator::createFromRules", $rules, $direction);
    }

    public function __mcCreateInverse(string $fn): ?Transliterator
    {
        \__mc_intl_reset();
        $e = \__mc_icu_err();
        $t = \__mc_icu_utrans_openInverse($this->trans, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code > 0) {
            \__mc_intl_fail($fn, "could not create inverse ICU transliterator", $code);
            return null;
        }
        return self::wrap($t);
    }

    public function createInverse(): ?Transliterator
    {
        return $this->__mcCreateInverse("Transliterator::createInverse");
    }

    /** @return string[]|false */
    public static function listIDs(): array|false
    {
        return \transliterator_list_ids();
    }

    public function __mcTransliterate(string $fn, bool $method, string $string, int $start, int $end): string|false
    {
        $this->errCode = 0;
        $this->errMessage = "";
        \__mc_intl_reset();
        $base = $method ? 1 : 2;
        if ($end < -1) {
            throw new \ValueError($fn . "(): Argument #" . ($base + 2) . " (\$end) must be greater than or equal to -1");
        }
        if ($start < 0) {
            throw new \ValueError($fn . "(): Argument #" . ($base + 1) . " (\$start) must be greater than or equal to 0");
        }
        if ($end !== -1 && $start > $end) {
            throw new \ValueError($fn . "(): Argument #" . ($base + 1) . " (\$start) must be less than or equal to argument #" . ($base + 2) . " (\$end)");
        }
        $u = \__mc_icu_to16($string);
        if ($u === null) {
            $this->errCode = 10;
            $this->errMessage = \__mc_intl_message($fn, "String conversion of string to UTF-16 failed", 10);
            \__mc_intl_fail($fn, "String conversion of string to UTF-16 failed", 10);
            return false;
        }
        if ($start > $u->len || ($end !== -1 && $end > $u->len)) {
            $what = "Neither \"start\" nor the \"end\" arguments can exceed the number of UTF-16 code units (in this case, " . $u->len . ")";
            $this->errCode = 1;
            $this->errMessage = \__mc_intl_message($fn, $what, 1);
            \__mc_intl_fail($fn, $what, 1);
            \__mc_icu_free($u->buf);
            return false;
        }
        $cap = $u->len + 1;
        $cells = \__mc_icu_malloc(16);
        while (true) {
            $buf = \__mc_icu_malloc($cap * 2);
            \__mc_icu_memcpy($buf, $u->buf, $u->len * 2);
            \poke_i32($cells, 0, $u->len);
            \poke_i32($cells, 8, $end === -1 ? $u->len : $end);
            $e = \__mc_icu_err();
            \__mc_icu_utrans_transUChars($this->trans, $buf, $cells, $cap, $start, \ptr_offset($cells, 8), $e);
            $code = \peek_i32($e, 0);
            \__mc_icu_free($e);
            $len = \peek_i32($cells, 0);
            if ($code === 15) {
                \__mc_icu_free($buf);
                $cap = $len + 1;
                continue;
            }
            if ($code > 0) {
                \__mc_icu_free($buf);
                \__mc_icu_free($cells);
                \__mc_icu_free($u->buf);
                $this->errCode = $code;
                $this->errMessage = \__mc_intl_message($fn, "transliteration failed", $code);
                __McIntlError::$code = $code;
                return false;
            }
            $out = \__mc_icu_to8($buf, $len);
            \__mc_icu_free($buf);
            \__mc_icu_free($cells);
            \__mc_icu_free($u->buf);
            return $out;
        }
    }

    public function transliterate(string $string, int $start = 0, int $end = -1): string|false
    {
        return $this->__mcTransliterate("Transliterator::transliterate", true, $string, $start, $end);
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

/** The function name a static helper's argument errors should carry. */
final class __McIcuFn
{
    public static string $name = "";
}

function transliterator_create(string $id, int $direction = Transliterator::FORWARD): ?Transliterator
{
    return Transliterator::__mcCreate("transliterator_create", $id, $direction);
}

function transliterator_create_from_rules(string $rules, int $direction = Transliterator::FORWARD): ?Transliterator
{
    return Transliterator::__mcCreateFromRules("transliterator_create_from_rules", $rules, $direction);
}

function transliterator_create_inverse(Transliterator $transliterator): ?Transliterator
{
    return $transliterator->__mcCreateInverse("transliterator_create_inverse");
}

/** @return string[]|false */
function transliterator_list_ids(): array|false
{
    \__mc_intl_reset();
    $e = \__mc_icu_err();
    $en = \__mc_icu_utrans_openIDs($e);
    if (\peek_i32($e, 0) > 0) {
        \__mc_intl_fail("transliterator_list_ids", "Failed to obtain registered transliterators", \peek_i32($e, 0));
        \__mc_icu_free($e);
        return false;
    }
    $lenCell = \__mc_icu_malloc(8);
    $out = [];
    while (true) {
        \poke_i32($lenCell, 0, 0);
        $p = \__mc_icu_uenum_unext($en, $lenCell, $e);
        if (\ptr_to_int($p) === 0) { break; }
        $out[] = \__mc_icu_to8($p, \peek_i32($lenCell, 0));
    }
    \__mc_icu_uenum_close($en);
    \__mc_icu_free($lenCell);
    \__mc_icu_free($e);
    return $out;
}

function transliterator_transliterate(Transliterator|string $transliterator, string $string, int $start = 0, int $end = -1): string|false
{
    if (\is_string($transliterator)) {
        $t = Transliterator::__mcCreate("transliterator_transliterate", $transliterator, Transliterator::FORWARD);
        if ($t === null) {
            throw new \IntlException("transliterator_transliterate(): Could not create transliterator with ID \""
                . $transliterator . "\" (" . \intl_get_error_message() . ")");
        }
        $transliterator = $t;
    }
    return $transliterator->__mcTransliterate("transliterator_transliterate", false, $string, $start, $end);
}

function transliterator_get_error_code(Transliterator $transliterator): int
{
    return $transliterator->getErrorCode();
}

function transliterator_get_error_message(Transliterator $transliterator): string
{
    return $transliterator->getErrorMessage();
}
