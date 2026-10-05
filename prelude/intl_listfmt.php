<?php
/** ext/intl IntlListFormatter (php 8.5) over ICU's ulistfmt_*. */

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ulistfmt_openForType')]
function __mc_icu_ulistfmt_openForType(string $locale, #[\Ffi\CType('int')] int $type,
    #[\Ffi\CType('int')] int $width, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ulistfmt_close')]
function __mc_icu_ulistfmt_close(\Ffi\Ptr $fmt): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ulistfmt_format'), \Ffi\CType('int')]
function __mc_icu_ulistfmt_format(\Ffi\Ptr $fmt, \Ffi\Ptr $items, \Ffi\Ptr $lengths,
    #[\Ffi\CType('int')] int $count, \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

final class IntlListFormatter
{
    public const TYPE_AND = 0;
    public const TYPE_OR = 1;
    public const TYPE_UNITS = 2;
    public const WIDTH_WIDE = 0;
    public const WIDTH_SHORT = 1;
    public const WIDTH_NARROW = 2;

    private int $__mcFmt = 0;
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    public function __construct(string $locale, int $type = IntlListFormatter::TYPE_AND, int $width = IntlListFormatter::WIDTH_WIDE)
    {
        $fn = "IntlListFormatter::__construct";
        $this->__mcReset();
        if ($this->__mcFmt !== 0) {
            throw new \Error("IntlListFormatter object is already constructed");
        }
        if ($locale === "") { $locale = \__mc_intl_default_locale(); }
        if (\strlen($locale) > 156) {
            throw new \ValueError($fn . "(): Argument #1 (\$locale) must be less than or equal to 156 characters");
        }
        if (\cstr_to_str(\__mc_icu_uloc_getISO3Language($locale)) === "") {
            throw new \ValueError($fn . "(): Argument #1 (\$locale) \"" . $locale . "\" is invalid");
        }
        if ($type !== self::TYPE_AND && $type !== self::TYPE_OR && $type !== self::TYPE_UNITS) {
            throw new \ValueError($fn . "(): Argument #2 (\$type) must be one of IntlListFormatter::TYPE_AND, IntlListFormatter::TYPE_OR, or IntlListFormatter::TYPE_UNITS");
        }
        if ($width !== self::WIDTH_WIDE && $width !== self::WIDTH_SHORT && $width !== self::WIDTH_NARROW) {
            throw new \ValueError($fn . "(): Argument #3 (\$width) must be one of IntlListFormatter::WIDTH_WIDE, IntlListFormatter::WIDTH_SHORT, or IntlListFormatter::WIDTH_NARROW");
        }
        $e = \__mc_icu_err();
        $f = \__mc_icu_ulistfmt_openForType($locale, $type, $width, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code > 0) {
            \__mc_intl_fail($fn, "Constructor failed", $code);
            throw new IntlException("Constructor failed");
        }
        $this->__mcFmt = \ptr_to_int($f);
    }

    public function __clone()
    {
        $this->__mcFmt = 0;
        throw new \Error("Trying to clone an uncloneable object of class IntlListFormatter");
    }

    public function __destruct()
    {
        if ($this->__mcFmt !== 0) {
            \__mc_icu_ulistfmt_close(\int_to_ptr($this->__mcFmt));
            $this->__mcFmt = 0;
        }
    }

    private function __mcReset(): void
    {
        \__mc_intl_reset();
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
    }

    private function __mcFail(string $what, int $code): void
    {
        $fn = "IntlListFormatter::format";
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    public function format(array $strings): string|false
    {
        $this->__mcReset();
        $n = \count($strings);
        if ($n === 0) { return ""; }
        $items = \__mc_icu_malloc($n * 8);
        $lens = \__mc_icu_malloc($n * 4);
        /** @var \Ffi\Ptr[] $bufs */
        $bufs = [];
        $i = 0;
        $bad = false;
        foreach ($strings as $v) {
            $u = \__mc_icu_to16(\is_array($v) ? "Array" : (string)$v);
            if ($u === null) {
                $bad = true;
                break;
            }
            $bufs[] = $u->buf;
            \poke_i64($items, $i * 8, \ptr_to_int($u->buf));
            \poke_i32($lens, $i * 4, $u->len);
            $i = $i + 1;
        }
        $out = null;
        if (!$bad) {
            $fmt = \int_to_ptr($this->__mcFmt);
            $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
                => \__mc_icu_ulistfmt_format($fmt, $items, $lens, $n, $b, $c, $e));
        }
        foreach ($bufs as $b) { \__mc_icu_free($b); }
        \__mc_icu_free($items);
        \__mc_icu_free($lens);
        if ($bad) {
            $this->__mcFail("Failed to convert string to UTF-16", 10);
            return false;
        }
        if ($out === null) {
            $this->__mcFail("Failed to format list", __McIcuStatus::$code);
            return false;
        }
        return $out;
    }

    public function getErrorCode(): int
    {
        return $this->__mcErrCode;
    }

    public function getErrorMessage(): string
    {
        return $this->__mcErrMessage !== "" ? $this->__mcErrMessage : \intl_error_name($this->__mcErrCode);
    }
}
