<?php

// ext/intl Spoofchecker (php-src ext/intl/spoofchecker/*) over ICU's uspoof_* C API. Where
// Zend emits an E_WARNING and carries on, this answers the same value silently (the
// warning → exception taxonomy is its own epic; nothing here prints).

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_open')]
function __mc_icu_uspoof_open(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_clone')]
function __mc_icu_uspoof_clone(\Ffi\Ptr $sc, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_close')]
function __mc_icu_uspoof_close(\Ffi\Ptr $sc): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_setRestrictionLevel')]
function __mc_icu_uspoof_setRestrictionLevel(\Ffi\Ptr $sc, #[\Ffi\CType('int')] int $level): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_openCheckResult')]
function __mc_icu_uspoof_openCheckResult(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_closeCheckResult')]
function __mc_icu_uspoof_closeCheckResult(\Ffi\Ptr $r): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_check2UTF8'), \Ffi\CType('int')]
function __mc_icu_uspoof_check2UTF8(\Ffi\Ptr $sc, string $text, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $res,
    \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_areConfusableUTF8'), \Ffi\CType('int')]
function __mc_icu_uspoof_areConfusableUTF8(\Ffi\Ptr $sc, string $a, #[\Ffi\CType('int')] int $al, string $b,
    #[\Ffi\CType('int')] int $bl, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_setAllowedLocales')]
function __mc_icu_uspoof_setAllowedLocales(\Ffi\Ptr $sc, string $locales, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_setChecks')]
function __mc_icu_uspoof_setChecks(\Ffi\Ptr $sc, #[\Ffi\CType('int')] int $checks, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('uspoof_setAllowedChars')]
function __mc_icu_uspoof_setAllowedChars(\Ffi\Ptr $sc, \Ffi\Ptr $set, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uset_openEmpty')]
function __mc_icu_uset_openEmpty(): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uset_applyPattern'), \Ffi\CType('int')]
function __mc_icu_uset_applyPattern(\Ffi\Ptr $set, \Ffi\Ptr $pattern, #[\Ffi\CType('int')] int $len,
    #[\Ffi\CType('uint')] int $options, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uset_compact')]
function __mc_icu_uset_compact(\Ffi\Ptr $set): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uset_close')]
function __mc_icu_uset_close(\Ffi\Ptr $set): void {}

class Spoofchecker
{
    public const SINGLE_SCRIPT_CONFUSABLE = 1;
    public const MIXED_SCRIPT_CONFUSABLE = 2;
    public const WHOLE_SCRIPT_CONFUSABLE = 4;
    public const ANY_CASE = 8;
    public const SINGLE_SCRIPT = 16;
    public const INVISIBLE = 32;
    public const CHAR_LIMIT = 64;
    public const ASCII = 268435456;
    public const HIGHLY_RESTRICTIVE = 805306368;
    public const MODERATELY_RESTRICTIVE = 1073741824;
    public const MINIMALLY_RESTRICTIVE = 1342177280;
    public const UNRESTRICTIVE = 1610612736;
    public const SINGLE_SCRIPT_RESTRICTIVE = 536870912;
    public const MIXED_NUMBERS = 128;
    public const HIDDEN_OVERLAY = 256;
    public const IGNORE_SPACE = 1;
    public const CASE_INSENSITIVE = 2;
    public const ADD_CASE_MAPPINGS = 4;
    public const SIMPLE_CASE_INSENSITIVE = 6;

    private int $__mcSc = 0;
    private int $__mcRes = 0;

    public function __construct()
    {
        if ($this->__mcSc !== 0) {
            throw new \Error("Spoofchecker object is already constructed");
        }
        $e = \__mc_icu_err();
        $sc = \__mc_icu_uspoof_open($e);
        if (\peek_i32($e, 0) > 0) {
            \__mc_icu_free($e);
            throw new IntlException("Spoofchecker::__construct(): unable to open ICU Spoof Checker");
        }
        \__mc_icu_uspoof_setRestrictionLevel($sc, self::HIGHLY_RESTRICTIVE);
        $res = \__mc_icu_uspoof_openCheckResult($e);
        \__mc_icu_free($e);
        $this->__mcSc = \ptr_to_int($sc);
        $this->__mcRes = \ptr_to_int($res);
    }

    public function __clone()
    {
        $e = \__mc_icu_err();
        $this->__mcSc = \ptr_to_int(\__mc_icu_uspoof_clone(\int_to_ptr($this->__mcSc), $e));
        $this->__mcRes = \ptr_to_int(\__mc_icu_uspoof_openCheckResult($e));
        \__mc_icu_free($e);
    }

    public function __destruct()
    {
        if ($this->__mcRes !== 0) { \__mc_icu_uspoof_closeCheckResult(\int_to_ptr($this->__mcRes)); $this->__mcRes = 0; }
        if ($this->__mcSc !== 0) { \__mc_icu_uspoof_close(\int_to_ptr($this->__mcSc)); $this->__mcSc = 0; }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    public function isSuspicious(string $string, &$errorCode = null): bool
    {
        $e = \__mc_icu_err();
        $r = \__mc_icu_uspoof_check2UTF8(\int_to_ptr($this->__mcSc), $string, \strlen($string), \int_to_ptr($this->__mcRes), $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) { return true; }
        if (\func_num_args() >= 2) { $errorCode = $r; }
        return $r !== 0;
    }

    public function areConfusable(string $string1, string $string2, &$errorCode = null): bool
    {
        $e = \__mc_icu_err();
        $r = \__mc_icu_uspoof_areConfusableUTF8(\int_to_ptr($this->__mcSc), $string1, \strlen($string1), $string2, \strlen($string2), $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) { return true; }
        if (\func_num_args() >= 3) { $errorCode = $r; }
        return $r !== 0;
    }

    public function setAllowedLocales(string $locales): void
    {
        $e = \__mc_icu_err();
        \__mc_icu_uspoof_setAllowedLocales(\int_to_ptr($this->__mcSc), $locales, $e);
        \__mc_icu_free($e);
    }

    public function setChecks(int $checks): void
    {
        $e = \__mc_icu_err();
        \__mc_icu_uspoof_setChecks(\int_to_ptr($this->__mcSc), (($checks & 0xFFFFFFFF) ^ 0x80000000) - 0x80000000, $e);
        \__mc_icu_free($e);
    }

    public function setRestrictionLevel(int $level): void
    {
        if ($level !== self::ASCII && $level !== self::SINGLE_SCRIPT_RESTRICTIVE && $level !== self::HIGHLY_RESTRICTIVE
            && $level !== self::MODERATELY_RESTRICTIVE && $level !== self::MINIMALLY_RESTRICTIVE && $level !== self::UNRESTRICTIVE) {
            throw new \ValueError("Spoofchecker::setRestrictionLevel(): Argument #1 (\$level) must be one of Spoofchecker::ASCII, "
                . "Spoofchecker::SINGLE_SCRIPT_RESTRICTIVE, Spoofchecker::HIGHLY_RESTRICTIVE, Spoofchecker::MODERATELY_RESTRICTIVE, "
                . "Spoofchecker::MINIMALLY_RESTRICTIVE, or Spoofchecker::UNRESTRICTIVE");
        }
        \__mc_icu_uspoof_setRestrictionLevel(\int_to_ptr($this->__mcSc), $level);
    }

    public function setAllowedChars(string $pattern, int $patternOptions = 0): void
    {
        $fn = "Spoofchecker::setAllowedChars";
        $n = \strlen($pattern);
        if ($n === 0 || $pattern[0] !== "[" || $pattern[$n - 1] !== "]") {
            throw new \ValueError($fn . "(): Argument #1 (\$pattern) must be a valid regular expression character set pattern");
        }
        $u = \__mc_icu_to16($pattern);
        if ($u === null) {
            throw new \ValueError($fn . "(): Argument #1 (\$pattern) string conversion to unicode encoding failed (10) U_INVALID_CHAR_FOUND");
        }
        if ($patternOptions !== 0 && $patternOptions !== 1 && $patternOptions !== 7 && $patternOptions !== 3 && $patternOptions !== 5) {
            \__mc_icu_free($u->buf);
            throw new \ValueError($fn . "(): Argument #2 (\$patternOptions) must be a valid pattern option, 0 or (SpoofChecker::IGNORE_SPACE|"
                . "(<none> or SpoofChecker::CASE_INSENSITIVE or SpoofChecker::ADD_CASE_MAPPINGS or SpoofChecker::SIMPLE_CASE_INSENSITIVE))");
        }
        $set = \__mc_icu_uset_openEmpty();
        $e = \__mc_icu_err();
        \__mc_icu_uset_applyPattern($set, $u->buf, $u->len, $patternOptions, $e);
        \__mc_icu_free($u->buf);
        $c = \peek_i32($e, 0);
        if ($c > 0) {
            \__mc_icu_free($e);
            \__mc_icu_uset_close($set);
            throw new \ValueError($fn . "(): Argument #1 (\$pattern) must be a valid regular expression character set pattern ("
                . (string)$c . ") " . \intl_error_name($c));
        }
        \__mc_icu_uset_compact($set);
        \__mc_icu_uspoof_setAllowedChars(\int_to_ptr($this->__mcSc), $set, $e);
        \__mc_icu_uset_close($set);
        \__mc_icu_free($e);
    }
}
