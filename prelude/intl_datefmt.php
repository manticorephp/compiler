<?php

// ext/intl IntlDateFormatter + datefmt_* and IntlDatePatternGenerator (php-src
// ext/intl/dateformat/*) over ICU's udat_* / udatpg_*. The calendar and zone plumbing is
// intl_calendar.php's and intl_timezone.php's, which this family always rides with.

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_clone')]
function __mc_icu_udat_clone(\Ffi\Ptr $fmt, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_applyPattern')]
function __mc_icu_udat_applyPattern(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $localized, \Ffi\Ptr $pattern,
    #[\Ffi\CType('int')] int $len): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_toPattern'), \Ffi\CType('int')]
function __mc_icu_udat_toPattern(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $localized, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_getCalendar')]
function __mc_icu_udat_getCalendar(\Ffi\Ptr $fmt): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_setCalendar')]
function __mc_icu_udat_setCalendar(\Ffi\Ptr $fmt, \Ffi\Ptr $cal): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_getLocaleByType')]
function __mc_icu_udat_getLocaleByType(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $type, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_isLenient'), \Ffi\CType('char')]
function __mc_icu_udat_isLenient(\Ffi\Ptr $fmt): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_setLenient')]
function __mc_icu_udat_setLenient(\Ffi\Ptr $fmt, #[\Ffi\CType('int')] int $lenient): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_parse'), \Ffi\CType('double')]
function __mc_icu_udat_parse(\Ffi\Ptr $fmt, \Ffi\Ptr $text, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $pos,
    \Ffi\Ptr $err): float { return 0.0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_parseCalendar')]
function __mc_icu_udat_parseCalendar(\Ffi\Ptr $fmt, \Ffi\Ptr $cal, \Ffi\Ptr $text, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $pos, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_setDateTime')]
function __mc_icu_ucal_setDateTime(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $y, #[\Ffi\CType('int')] int $m,
    #[\Ffi\CType('int')] int $d, #[\Ffi\CType('int')] int $h, #[\Ffi\CType('int')] int $i,
    #[\Ffi\CType('int')] int $s, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getISO3Language')]
function __mc_icu_uloc_getISO3Language(string $locale): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udatpg_open')]
function __mc_icu_udatpg_open(string $locale, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udatpg_clone')]
function __mc_icu_udatpg_clone(\Ffi\Ptr $dtpg, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udatpg_close')]
function __mc_icu_udatpg_close(\Ffi\Ptr $dtpg): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udatpg_getSkeleton'), \Ffi\CType('int')]
function __mc_icu_udatpg_getSkeleton(\Ffi\Ptr $dtpg, \Ffi\Ptr $pattern, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udatpg_getBestPattern'), \Ffi\CType('int')]
function __mc_icu_udatpg_getBestPattern(\Ffi\Ptr $dtpg, \Ffi\Ptr $skeleton, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

/** INTL_UDATE_FMT_OK */
function __mc_datefmt_style_ok(int $s): bool
{
    return ($s >= -2 && $s <= 3) || ($s >= 128 && $s <= 131);
}

/** A formatter's error, reported the intl way (thrown by a constructor). */
final class __McDatefmtError extends \Exception {}

/** intl_zval_to_millis: the instant a format() argument names, or null with the error in `$err`. */
function __mc_datefmt_millis(mixed $v, __McIntlErrorBox $err): ?float
{
    if (\is_int($v)) { return $v * 1000.0; }
    if (\is_float($v)) { return $v * 1000.0; }
    if (\is_string($v)) {
        if (\is_numeric($v)) {
            $t = \trim($v, " \t\n\r\v\f");
            if (\preg_match('/^[+-]?\d+$/', $t) === 1 && \is_int($t + 0)) { return (int)$t * 1000.0; }
            return (float)$v * 1000.0;
        }
        $err->set(1, "string '" . $v . "' is not numeric, which would be required for it to be a valid date");
        return null;
    }
    if ($v instanceof DateTimeInterface) {
        return $v->getTimestamp() * 1000.0 + \intdiv((int)$v->format("u"), 1000);
    }
    if ($v instanceof IntlCalendar) {
        $e = \__mc_icu_err();
        $t = \__mc_icu_ucal_getMillis($v->__mcPtr(), $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            $err->set($c, "call to internal Calendar::getTime() has failed");
            return null;
        }
        return $t;
    }
    if (\is_object($v)) {
        $err->set(1, "invalid object type for date/time (only IntlCalendar and DateTimeInterface permitted)");
        return null;
    }
    $err->set(1, "invalid PHP type for date");
    return null;
}

/** An error code + custom message waiting to be reported under a function name. */
final class __McIntlErrorBox
{
    public int $code = 0;
    public string $what = "";

    public function set(int $code, string $what): void
    {
        if ($this->code > 0) { return; }
        $this->code = $code;
        $this->what = $what;
    }
}

class IntlDateFormatter
{
    public const FULL = 0;
    public const LONG = 1;
    public const MEDIUM = 2;
    public const SHORT = 3;
    public const NONE = -1;
    public const RELATIVE_FULL = 128;
    public const RELATIVE_LONG = 129;
    public const RELATIVE_MEDIUM = 130;
    public const RELATIVE_SHORT = 131;
    public const PATTERN = -2;
    public const GREGORIAN = 1;
    public const TRADITIONAL = 0;

    /** Set while create() builds an object whose constructor must stay inert. */
    public static bool $__mcInert = false;

    private int $__mcFmt = 0;
    private int $__mcDateType = 0;
    private int $__mcTimeType = 0;
    private int $__mcCalendarType = -1;
    private string $__mcRequestedLocale = "";
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    public function __construct(?string $locale, int $dateType = IntlDateFormatter::FULL, int $timeType = IntlDateFormatter::FULL,
        IntlTimeZone|DateTimeZone|string|null $timezone = null, $calendar = null, ?string $pattern = null)
    {
        if (self::$__mcInert) { return; }
        $this->__mcInit("IntlDateFormatter::__construct", true, $locale, $dateType, $timeType, $timezone, $calendar, $pattern);
    }

    public static function __mcCreate(string $fn, ?string $locale, int $dateType, int $timeType,
        IntlTimeZone|DateTimeZone|string|null $timezone, mixed $calendar, ?string $pattern): ?IntlDateFormatter
    {
        self::$__mcInert = true;
        $f = new IntlDateFormatter(null);
        self::$__mcInert = false;
        return $f->__mcInit($fn, false, $locale, $dateType, $timeType, $timezone, $calendar, $pattern) ? $f : null;
    }

    public function __clone()
    {
        if ($this->__mcFmt !== 0) {
            $e = \__mc_icu_err();
            $this->__mcFmt = \ptr_to_int(\__mc_icu_udat_clone(\int_to_ptr($this->__mcFmt), $e));
            \__mc_icu_free($e);
        }
    }

    public function __destruct()
    {
        if ($this->__mcFmt !== 0) {
            \__mc_icu_udat_close(\int_to_ptr($this->__mcFmt));
            $this->__mcFmt = 0;
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    public function __mcPtr(): \Ffi\Ptr
    {
        return \int_to_ptr($this->__mcFmt);
    }

    /** A constructor error: the global error, and an IntlException when `$throw`. */
    private function __mcCtorFail(string $fn, bool $throw, int $code, string $what): bool
    {
        \__mc_intl_fail($fn, $what, $code);
        if ($throw) {
            throw new IntlException($fn . "(): " . $what);
        }
        return false;
    }

    public function __mcReset(): void
    {
        \__mc_intl_reset();
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
    }

    public function __mcFail(string $fn, string $what, int $code): void
    {
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    /**
     * datefmt_process_calendar_arg: a UCalendar the caller owns and hands to udat_setCalendar
     * (then closes), and the int type to remember (-1 for an IntlCalendar), or null with the
     * error in `$err`. An owned calendar opens on `$zone`; a borrowed one keeps its own.
     */
    private static function __mcCalendarArg(mixed $calendar, string $locale, string $zone, __McIntlErrorBox $err, int &$type): ?\Ffi\Ptr
    {
        if ($calendar instanceof IntlCalendar) {
            $type = -1;
            $e = \__mc_icu_err();
            $c = \__mc_icu_ucal_clone($calendar->__mcPtr(), $e);
            \__mc_icu_free($e);
            return $c;
        }
        $t = $calendar === null ? 1 : (int)$calendar;
        if ($calendar !== null && $t !== 0 && $t !== 1) {
            $err->set(1, "Invalid value for calendar type; it must be one of "
                . "IntlDateFormatter::TRADITIONAL (locale's default calendar) or"
                . " IntlDateFormatter::GREGORIAN. Alternatively, it can be an "
                . "IntlCalendar object");
            return null;
        }
        $type = $t;
        $c = \__mc_intlcal_open($zone, $locale, $t);
        if ($c === null) {
            $err->set(1, "Failure instantiating calendar");
        }
        return $c;
    }

    public function __mcInit(string $fn, bool $throw, ?string $locale, int $dateType, int $timeType,
        IntlTimeZone|DateTimeZone|string|null $timezone, mixed $calendar, ?string $pattern): bool
    {
        \__mc_intl_reset();
        if ($calendar !== null && !\is_int($calendar) && !($calendar instanceof IntlCalendar)) {
            throw new \TypeError($fn . "(): Argument #5 (\$calendar) must be of type IntlCalendar|int|null, "
                . \get_debug_type($calendar) . " given");
        }
        if (!\__mc_datefmt_style_ok($dateType)) {
            return $this->__mcCtorFail($fn, $throw, 1, "invalid date format style");
        }
        if (!\__mc_datefmt_style_ok($timeType)) {
            return $this->__mcCtorFail($fn, $throw, 1, "invalid time format style");
        }
        if ($dateType === -2 && $timeType !== -2) {
            return $this->__mcCtorFail($fn, $throw, 1, "datefmt_create: time format must be IntlDateFormatter::PATTERN if date format is IntlDateFormatter::PATTERN");
        }
        $loc = $locale ?? "";
        if (\strlen($loc) > 156) {
            return $this->__mcCtorFail($fn, $throw, 1, "Locale string too long, should be no longer than 156 characters");
        }
        $given = \strlen($loc);
        if ($given === 0) { $loc = \__mc_intl_default_locale(); }
        $canon = \__mc_icu_chars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_uloc_canonicalize($loc, $b, $c, $e));
        $final = ($canon !== null && $canon !== "") ? $canon : $loc;
        if (($given === 1 && $loc !== "C") || ($given > 1 && \cstr_to_str(\__mc_icu_uloc_getISO3Language($final)) === "")) {
            throw new \ValueError($fn . "(): Argument #1 (\$locale) \"" . $loc . "\" is invalid");
        }
        $err = new __McIntlErrorBox();
        $explicit = $timezone !== null;
        $zone = "";
        if ($explicit || !($calendar instanceof IntlCalendar)) {
            $zone = \__mc_intlcal_zone($fn, $timezone, null) ?? "";
            if ($zone === "") {
                // The conversion error is already the global one; the constructor throws it.
                if ($throw) {
                    $m = __McIntlError::$message;
                    throw new IntlException(\substr($m, 0, \strlen($m) - \strlen(\intl_error_name(__McIntlError::$code)) - 2));
                }
                return false;
            }
        }
        $calType = 0;
        $cal = self::__mcCalendarArg($calendar, $final, $zone, $err, $calType);
        if ($cal === null) {
            return $this->__mcCtorFail($fn, $throw, $err->code, $err->what);
        }
        $p16 = null;
        if ($pattern !== null && $pattern !== "") {
            $p16 = \__mc_icu_to16($pattern);
            if ($p16 === null) {
                \__mc_icu_ucal_close($cal);
                return $this->__mcCtorFail($fn, $throw, 10, "error converting pattern to UTF-16");
            }
        }
        $e = \__mc_icu_err();
        $fmt = \__mc_icu_udat_open($timeType, $dateType, $final, \int_to_ptr(0), 0,
            $p16 === null ? \int_to_ptr(0) : $p16->buf, $p16 === null ? 0 : $p16->len, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($p16 !== null && \ptr_to_int($fmt) !== 0) {
            \__mc_icu_udat_applyPattern($fmt, 1, $p16->buf, $p16->len);
        }
        if ($p16 !== null) { \__mc_icu_free($p16->buf); }
        if ($c > 0 || \ptr_to_int($fmt) === 0) {
            \__mc_icu_ucal_close($cal);
            return $this->__mcCtorFail($fn, $throw, $c > 0 ? $c : 1, "date formatter creation failed");
        }
        if ($calendar instanceof IntlCalendar && $explicit) {
            $u = \__mc_icu_to16($zone);
            $ze = \__mc_icu_err();
            \__mc_icu_ucal_setTimeZone($cal, $u->buf, $u->len, $ze);
            \__mc_icu_free($ze);
            \__mc_icu_free($u->buf);
        }
        \__mc_icu_udat_setCalendar($fmt, $cal);
        \__mc_icu_ucal_close($cal);
        $this->__mcFmt = \ptr_to_int($fmt);
        $this->__mcDateType = $dateType;
        $this->__mcTimeType = $timeType;
        $this->__mcCalendarType = $calType;
        $this->__mcRequestedLocale = $final;
        return true;
    }

    public static function create(?string $locale, int $dateType = IntlDateFormatter::FULL, int $timeType = IntlDateFormatter::FULL,
        IntlTimeZone|DateTimeZone|string|null $timezone = null, IntlCalendar|int|null $calendar = null, ?string $pattern = null): ?IntlDateFormatter
    {
        return self::__mcCreate("IntlDateFormatter::create", $locale, $dateType, $timeType, $timezone, $calendar, $pattern);
    }

    public function getDateType(): int|false
    {
        $this->__mcReset();
        return $this->__mcDateType;
    }

    public function getTimeType(): int|false
    {
        $this->__mcReset();
        return $this->__mcTimeType;
    }

    public function getCalendar(): int|false
    {
        $this->__mcReset();
        return $this->__mcCalendarType === -1 ? false : $this->__mcCalendarType;
    }

    public function setCalendar(IntlCalendar|int|null $calendar): bool
    {
        return $this->__mcSetCalendar("IntlDateFormatter::setCalendar", $calendar);
    }

    public function __mcSetCalendar(string $fn, mixed $calendar): bool
    {
        $this->__mcReset();
        $err = new __McIntlErrorBox();
        $type = 0;
        $zone = \__mc_tz_cal_id(\__mc_icu_udat_getCalendar($this->__mcPtr()));
        $cal = self::__mcCalendarArg($calendar, $this->__mcRequestedLocale, $zone, $err, $type);
        if ($cal === null) {
            $this->__mcFail($fn, $err->what, $err->code);
            return false;
        }
        \__mc_icu_udat_setCalendar($this->__mcPtr(), $cal);
        \__mc_icu_ucal_close($cal);
        $this->__mcCalendarType = $type;
        return true;
    }

    public function getTimeZoneId(): string|false
    {
        $this->__mcReset();
        return \__mc_tz_cal_id(\__mc_icu_udat_getCalendar($this->__mcPtr()));
    }

    public function getCalendarObject(): IntlCalendar|false|null
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $c = \__mc_icu_ucal_clone(\__mc_icu_udat_getCalendar($this->__mcPtr()), $e);
        \__mc_icu_free($e);
        return \__mc_intlcal_wrap($c);
    }

    public function getTimeZone(): IntlTimeZone|false
    {
        $this->__mcReset();
        return IntlTimeZone::__mcOf(\__mc_tz_cal_id(\__mc_icu_udat_getCalendar($this->__mcPtr())));
    }

    public function setTimeZone(IntlTimeZone|DateTimeZone|string|null $timezone): bool
    {
        return $this->__mcSetTimeZone("IntlDateFormatter::setTimeZone", $timezone);
    }

    /** DateFormat::adoptTimeZone: the formatter's calendar moves to the zone. */
    public function __mcSetTimeZone(string $fn, IntlTimeZone|DateTimeZone|string|null $timezone): bool
    {
        $this->__mcReset();
        $zone = \__mc_intlcal_zone($fn, $timezone, null);
        if ($zone === null) {
            $this->__mcErrCode = __McIntlError::$code;
            $this->__mcErrMessage = __McIntlError::$message;
            return false;
        }
        $e = \__mc_icu_err();
        $cal = \__mc_icu_ucal_clone(\__mc_icu_udat_getCalendar($this->__mcPtr()), $e);
        $u = \__mc_icu_to16($zone);
        \__mc_icu_ucal_setTimeZone($cal, $u->buf, $u->len, $e);
        \__mc_icu_free($u->buf);
        \__mc_icu_free($e);
        \__mc_icu_udat_setCalendar($this->__mcPtr(), $cal);
        \__mc_icu_ucal_close($cal);
        return true;
    }

    public function setPattern(string $pattern): bool
    {
        return $this->__mcSetPattern("IntlDateFormatter::setPattern", $pattern);
    }

    public function __mcSetPattern(string $fn, string $pattern): bool
    {
        $this->__mcReset();
        $u = \__mc_icu_to16($pattern);
        if ($u === null) {
            $this->__mcFail($fn, "Error converting pattern to UTF-16", 10);
            return false;
        }
        \__mc_icu_udat_applyPattern($this->__mcPtr(), 0, $u->buf, $u->len);
        \__mc_icu_free($u->buf);
        return true;
    }

    public function getPattern(): string|false
    {
        return $this->__mcGetPattern("IntlDateFormatter::getPattern");
    }

    public function __mcGetPattern(string $fn): string|false
    {
        $this->__mcReset();
        $fmt = $this->__mcPtr();
        __McIcuStatus::$code = 0;
        $p = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_udat_toPattern($fmt, 0, $b, $c, $e));
        if ($p === null) {
            $this->__mcFail($fn, "Error getting formatter pattern", __McIcuStatus::$code);
            return false;
        }
        return $p;
    }

    public function getLocale(int $type = ULOC_ACTUAL_LOCALE): string|false
    {
        return $this->__mcGetLocale("IntlDateFormatter::getLocale", $type);
    }

    public function __mcGetLocale(string $fn, int $type): string|false
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $p = \__mc_icu_udat_getLocaleByType($this->__mcPtr(), $type, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            $this->__mcFail($fn, "Error getting locale", $c);
            return false;
        }
        return \cstr_to_str($p);
    }

    public function setLenient(bool $lenient): void
    {
        $this->__mcReset();
        \__mc_icu_udat_setLenient($this->__mcPtr(), $lenient ? 1 : 0);
    }

    public function isLenient(): bool
    {
        $this->__mcReset();
        return \__mc_icu_udat_isLenient($this->__mcPtr()) !== 0;
    }

    public function format($datetime): string|false
    {
        return $this->__mcFormat("IntlDateFormatter::format", $datetime);
    }

    public function __mcFormat(string $fn, mixed $datetime): string|false
    {
        $this->__mcReset();
        $err = new __McIntlErrorBox();
        if (\is_array($datetime)) {
            if (\count($datetime) === 0) { return false; }
            $ms = $this->__mcTmMillis($datetime, $err);
            if ($err->code > 0) {
                $this->__mcFail($fn, "date formatting failed", $err->code);
                return false;
            }
        } else {
            $m = \__mc_datefmt_millis($datetime, $err);
            if ($m === null) {
                $this->__mcFail($fn, $err->what, $err->code);
                return false;
            }
            $ms = $m;
        }
        $fmt = $this->__mcPtr();
        __McIcuStatus::$code = 0;
        $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
            => \__mc_icu_udat_format($fmt, $ms, $b, $c, \int_to_ptr(0), $e));
        if ($out === null) {
            $this->__mcFail($fn, "Date formatting failed", __McIcuStatus::$code);
            return false;
        }
        return $out;
    }

    /** internal_get_timestamp: a localtime()-shaped array on a clone of the formatter's calendar. */
    private function __mcTmMillis(array $tm, __McIntlErrorBox $err): float
    {
        $vals = [];
        foreach (["tm_year", "tm_mon", "tm_hour", "tm_min", "tm_sec", "tm_mday"] as $k) {
            $v = 0;
            if ($err->code === 0 && \array_key_exists($k, $tm)) {
                $x = $tm[$k];
                if (!\is_int($x)) {
                    $err->set(1, "parameter array contains a non-integer element for key '" . $k . "'");
                } elseif ($x > 2147483647 || $x < -2147483648) {
                    $err->set(1, "value " . (string)$x . " is out of bounds for a 32-bit integer in key '" . $k . "'");
                } else {
                    $v = $x;
                }
            }
            $vals[] = $v;
        }
        $e = \__mc_icu_err();
        $cal = \__mc_icu_ucal_clone(\__mc_icu_udat_getCalendar($this->__mcPtr()), $e);
        \__mc_icu_ucal_setDateTime($cal, $vals[0] + 1900, $vals[1], $vals[5], $vals[2], $vals[3], $vals[4], $e);
        $ms = \__mc_icu_ucal_getMillis($cal, $e);
        \__mc_icu_ucal_close($cal);
        \__mc_icu_free($e);
        return $ms;
    }

    public static function formatObject($datetime, $format = null, ?string $locale = null): string|false
    {
        return \__mc_datefmt_format_object("IntlDateFormatter::formatObject", $datetime, $format, $locale);
    }

    public function parse(string $string, &$offset = null): int|float|false
    {
        return $this->__mcParse("IntlDateFormatter::parse", $string, \func_num_args() >= 2, $offset, 0);
    }

    public function parseToCalendar(string $string, &$offset = null): int|float|false
    {
        return $this->__mcParse("IntlDateFormatter::parseToCalendar", $string, \func_num_args() >= 2, $offset, 1);
    }

    public function localtime(string $string, &$offset = null): array|false
    {
        return $this->__mcLocaltime("IntlDateFormatter::localtime", $string, \func_num_args() >= 2, $offset);
    }

    /**
     * The address of a parse-position cell for `$offset`: 0 when none was given, -1 when the
     * offset is out of range (the call answers false). An int, not a `Ptr|false|null` union —
     * a Ptr crossing a cell reaches the FFI call as a tagged word.
     */
    private function __mcPosCell(string $fn, bool $given, mixed $offset, int $len, bool $toCalendar): int
    {
        // php counts any passed variable as given — an undefined one reads as 0.
        if (!$given) { return 0; }
        $p = (int)$offset;
        if ($p > 2147483647 || $p < -2147483648) {
            \__mc_intl_fail($fn, "String index is out of valid range.", 1);
            return -1;
        }
        if (($toCalendar ? $p !== -1 : true) && ($p < 0 || $p > $len)) { return -1; }
        $cell = \__mc_icu_malloc(8);
        \poke_i32($cell, 0, $p);
        return \ptr_to_int($cell);
    }

    /** @param int $mode 0 parse, 1 parseToCalendar */
    public function __mcParse(string $fn, string $string, bool $given, mixed &$offset, int $mode): int|float|false
    {
        $this->__mcReset();
        $pos = $this->__mcPosCell($fn, $given, $offset, \strlen($string), $mode === 1);
        if ($pos === -1) { return false; }
        $u = \__mc_icu_to16($string);
        if ($u === null) {
            if ($pos !== 0) { \__mc_icu_free(\int_to_ptr($pos)); }
            $this->__mcFail($fn, "Error converting timezone to UTF-16", 10);
            return false;
        }
        $e = \__mc_icu_err();
        $pp = \int_to_ptr($pos);
        if ($mode === 1) {
            $cal = \__mc_icu_udat_getCalendar($this->__mcPtr());
            \__mc_icu_udat_parseCalendar($this->__mcPtr(), $cal, $u->buf, $u->len, $pp, $e);
            \__mc_icu_free($u->buf);
            $c = \peek_i32($e, 0);
            if ($c > 0) {
                \__mc_icu_free($e);
                if ($pos !== 0) { $offset = \peek_i32(\int_to_ptr($pos), 0); \__mc_icu_free(\int_to_ptr($pos)); }
                $this->__mcFail($fn, "Calendar parsing failed", $c);
                return false;
            }
            $ms = \__mc_icu_ucal_getMillis($cal, $e);
        } else {
            $ms = \__mc_icu_udat_parse($this->__mcPtr(), $u->buf, $u->len, $pp, $e);
            \__mc_icu_free($u->buf);
        }
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($pos !== 0) {
            $offset = \peek_i32(\int_to_ptr($pos), 0);
            \__mc_icu_free(\int_to_ptr($pos));
        }
        if ($c > 0) {
            $this->__mcFail($fn, "Date parsing failed", $c);
            return false;
        }
        $r = $ms / 1000.0;
        if ($r > 9.2233720368547758E18 || $r < -9.2233720368547758E18) {
            return $r < 0 ? \ceil($r) : \floor($r);
        }
        return (int)$r;
    }

    /** @return array<string, int>|false */
    public function __mcLocaltime(string $fn, string $string, bool $given, mixed &$offset): array|false
    {
        $this->__mcReset();
        $pos = $this->__mcPosCell($fn, $given, $offset, \strlen($string), false);
        if ($pos === -1) { return false; }
        $u = \__mc_icu_to16($string);
        if ($u === null) {
            if ($pos !== 0) { \__mc_icu_free(\int_to_ptr($pos)); }
            $this->__mcFail($fn, "Error converting timezone to UTF-16", 10);
            return false;
        }
        $e = \__mc_icu_err();
        $cal = \__mc_icu_udat_getCalendar($this->__mcPtr());
        \__mc_icu_udat_parseCalendar($this->__mcPtr(), $cal, $u->buf, $u->len, \int_to_ptr($pos), $e);
        \__mc_icu_free($u->buf);
        $c = \peek_i32($e, 0);
        if ($pos !== 0) {
            $offset = \peek_i32(\int_to_ptr($pos), 0);
            \__mc_icu_free(\int_to_ptr($pos));
        }
        if ($c > 0) {
            \__mc_icu_free($e);
            $this->__mcFail($fn, "Date parsing failed", $c);
            return false;
        }
        $out = [];
        foreach (["tm_sec" => 13, "tm_min" => 12, "tm_hour" => 11, "tm_year" => 1, "tm_mday" => 5,
                  "tm_wday" => 7, "tm_yday" => 6, "tm_mon" => 2] as $k => $f) {
            $v = \__mc_icu_ucal_get($cal, $f, $e);
            if ($k === "tm_year") { $v = $v - 1900; }
            if ($k === "tm_wday") { $v = $v - 1; }
            $out[$k] = $v;
        }
        $out["tm_isdst"] = \__mc_icu_ucal_inDaylightTime($cal, $e) === 1 ? 1 : 0;
        \__mc_icu_free($e);
        return $out;
    }

    public function getErrorCode(): int
    {
        \__mc_intl_reset();
        return $this->__mcErrCode;
    }

    public function getErrorMessage(): string
    {
        \__mc_intl_reset();
        return $this->__mcErrMessage !== "" ? $this->__mcErrMessage : \intl_error_name($this->__mcErrCode);
    }
}

/** DateFormat::EStyle values formatObject() accepts (no PATTERN). */
function __mc_datefmt_object_style(mixed $v): bool
{
    return \is_int($v) && (($v >= -1 && $v <= 3) || ($v >= 128 && $v <= 131));
}

function __mc_datefmt_format_object(string $fn, mixed $datetime, mixed $format, ?string $locale): string|false
{
    \__mc_intl_reset();
    if (!\is_object($datetime)) {
        throw new \TypeError($fn . "(): Argument #1 (\$datetime) must be of type object, " . \get_debug_type($datetime) . " given");
    }
    $loc = $locale ?? \__mc_intl_default_locale();
    $dateStyle = 2;
    $timeStyle = 2;
    $pattern = null;
    if ($format === null) {
    } elseif (\is_array($format)) {
        if (\count($format) !== 2) {
            \__mc_intl_fail($fn, "bad format; if array, it must have two elements", 1);
            return false;
        }
        $i = 0;
        foreach ($format as $z) {
            if (!\__mc_datefmt_object_style($z)) {
                \__mc_intl_fail($fn, $i === 0 ? "bad format; the date format (first element of the array) is not valid"
                    : "bad format; the time format (second element of the array) is not valid", 1);
                return false;
            }
            if ($i === 0) { $dateStyle = $z; } else { $timeStyle = $z; }
            $i = $i + 1;
        }
    } elseif (\is_int($format)) {
        if (!\__mc_datefmt_object_style($format)) {
            \__mc_intl_fail($fn, "the date/time format type is invalid", 1);
            return false;
        }
        $dateStyle = $format;
        $timeStyle = $format;
    } else {
        $pattern = (string)$format;
        if ($pattern === "") {
            \__mc_intl_fail($fn, "the format is empty", 1);
            return false;
        }
    }
    if ($timeStyle !== -1) { $timeStyle = $timeStyle & ~128; }
    if ($datetime instanceof IntlCalendar) {
        $e = \__mc_icu_err();
        $ms = \__mc_icu_ucal_getMillis($datetime->__mcPtr(), $e);
        $c = \peek_i32($e, 0);
        if ($c > 0) {
            \__mc_icu_free($e);
            \__mc_intl_fail($fn, "error obtaining instant from IntlCalendar", $c);
            return false;
        }
        $cal = \__mc_icu_ucal_clone($datetime->__mcPtr(), $e);
        \__mc_icu_free($e);
    } elseif ($datetime instanceof DateTimeInterface) {
        $ms = $datetime->getTimestamp() * 1000.0 + \intdiv((int)$datetime->format("u"), 1000);
        $z = \__mc_intltz_from_dtz($fn, $datetime->getTimezone());
        if ($z === null) {
            \__mc_intl_fail($fn, "could not convert DateTime's time zone", 1);
            return false;
        }
        $cal = \__mc_intlcal_open($z->__mcId(), $loc, 1);
        if ($cal === null) {
            \__mc_intl_fail($fn, "could not create GregorianCalendar", __McIcuStatus::$code);
            return false;
        }
    } else {
        \__mc_intl_fail($fn, "the passed object must be an instance of either IntlCalendar or DateTimeInterface", 0);
        return false;
    }
    $e = \__mc_icu_err();
    if ($pattern !== null) {
        $p = \__mc_icu_to16($pattern);
        $fmt = \__mc_icu_udat_open(-2, -2, $loc, \int_to_ptr(0), 0, $p->buf, $p->len, $e);
        \__mc_icu_free($p->buf);
    } else {
        $fmt = \__mc_icu_udat_open($timeStyle, $dateStyle, $loc, \int_to_ptr(0), 0, \int_to_ptr(0), 0, $e);
    }
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($c > 0 || \ptr_to_int($fmt) === 0) {
        \__mc_icu_ucal_close($cal);
        \__mc_intl_fail($fn, $pattern !== null ? "could not create SimpleDateFormat" : "could not create DateFormat", $c);
        return false;
    }
    \__mc_icu_udat_setCalendar($fmt, $cal);
    \__mc_icu_ucal_close($cal);
    $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $cap, \Ffi\Ptr $err): int
        => \__mc_icu_udat_format($fmt, $ms, $b, $cap, \int_to_ptr(0), $err));
    \__mc_icu_udat_close($fmt);
    return $out ?? "";
}

function datefmt_create(?string $locale, int $dateType = IntlDateFormatter::FULL, int $timeType = IntlDateFormatter::FULL,
    IntlTimeZone|DateTimeZone|string|null $timezone = null, IntlCalendar|int|null $calendar = null, ?string $pattern = null): ?IntlDateFormatter
{
    return IntlDateFormatter::__mcCreate("datefmt_create", $locale, $dateType, $timeType, $timezone, $calendar, $pattern);
}

function datefmt_get_datetype(IntlDateFormatter $formatter): int|false
{
    return $formatter->getDateType();
}

function datefmt_get_timetype(IntlDateFormatter $formatter): int|false
{
    return $formatter->getTimeType();
}

function datefmt_get_calendar(IntlDateFormatter $formatter): int|false
{
    return $formatter->getCalendar();
}

function datefmt_set_calendar(IntlDateFormatter $formatter, IntlCalendar|int|null $calendar): bool
{
    return $formatter->__mcSetCalendar("datefmt_set_calendar", $calendar);
}

function datefmt_get_timezone_id(IntlDateFormatter $formatter): string|false
{
    return $formatter->getTimeZoneId();
}

function datefmt_get_calendar_object(IntlDateFormatter $formatter): IntlCalendar|false|null
{
    return $formatter->getCalendarObject();
}

function datefmt_get_timezone(IntlDateFormatter $formatter): IntlTimeZone|false
{
    return $formatter->getTimeZone();
}

function datefmt_set_timezone(IntlDateFormatter $formatter, IntlTimeZone|DateTimeZone|string|null $timezone): bool
{
    return $formatter->__mcSetTimeZone("datefmt_set_timezone", $timezone);
}

function datefmt_set_pattern(IntlDateFormatter $formatter, string $pattern): bool
{
    return $formatter->__mcSetPattern("datefmt_set_pattern", $pattern);
}

function datefmt_get_pattern(IntlDateFormatter $formatter): string|false
{
    return $formatter->__mcGetPattern("datefmt_get_pattern");
}

function datefmt_get_locale(IntlDateFormatter $formatter, int $type = ULOC_ACTUAL_LOCALE): string|false
{
    return $formatter->__mcGetLocale("datefmt_get_locale", $type);
}

function datefmt_set_lenient(IntlDateFormatter $formatter, bool $lenient): void
{
    $formatter->setLenient($lenient);
}

function datefmt_is_lenient(IntlDateFormatter $formatter): bool
{
    return $formatter->isLenient();
}

function datefmt_format(IntlDateFormatter $formatter, $datetime): string|false
{
    return $formatter->__mcFormat("datefmt_format", $datetime);
}

function datefmt_format_object($datetime, $format = null, ?string $locale = null): string|false
{
    return \__mc_datefmt_format_object("datefmt_format_object", $datetime, $format, $locale);
}

function datefmt_parse(IntlDateFormatter $formatter, string $string, &$offset = null): int|float|false
{
    return $formatter->__mcParse("datefmt_parse", $string, \func_num_args() >= 3, $offset, 0);
}

/** @return array<string, int>|false */
function datefmt_localtime(IntlDateFormatter $formatter, string $string, &$offset = null): array|false
{
    return $formatter->__mcLocaltime("datefmt_localtime", $string, \func_num_args() >= 3, $offset);
}

function datefmt_get_error_code(IntlDateFormatter $formatter): int
{
    return $formatter->getErrorCode();
}

function datefmt_get_error_message(IntlDateFormatter $formatter): string
{
    return $formatter->getErrorMessage();
}

class IntlDatePatternGenerator
{
    public static bool $__mcInert = false;
    private int $__mcGen = 0;

    public function __construct(?string $locale = null)
    {
        if (self::$__mcInert) { return; }
        $this->__mcInit("IntlDatePatternGenerator::__construct", true, $locale);
    }

    public static function create(?string $locale = null): ?IntlDatePatternGenerator
    {
        self::$__mcInert = true;
        $g = new IntlDatePatternGenerator();
        self::$__mcInert = false;
        return $g->__mcInit("IntlDatePatternGenerator::create", false, $locale) ? $g : null;
    }

    private function __mcInit(string $fn, bool $throw, ?string $locale): bool
    {
        \__mc_intl_reset();
        $loc = $locale ?? "";
        if (\strlen($loc) > 156) {
            \__mc_intl_fail($fn, "Locale string too long, should be no longer than 156 characters", 1);
            if ($throw) { throw new IntlException($fn . "(): Locale string too long, should be no longer than 156 characters"); }
            return false;
        }
        if ($loc === "") { $loc = \__mc_intl_default_locale(); }
        $e = \__mc_icu_err();
        $g = \__mc_icu_udatpg_open($loc, $e);
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c > 0) {
            \__mc_intl_fail($fn, "Error creating DateTimePatternGenerator", $c);
            if ($throw) { throw new IntlException($fn . "(): Error creating DateTimePatternGenerator"); }
            return false;
        }
        $this->__mcGen = \ptr_to_int($g);
        return true;
    }

    public function __clone()
    {
        if ($this->__mcGen !== 0) {
            $e = \__mc_icu_err();
            $this->__mcGen = \ptr_to_int(\__mc_icu_udatpg_clone(\int_to_ptr($this->__mcGen), $e));
            \__mc_icu_free($e);
        }
    }

    public function __destruct()
    {
        if ($this->__mcGen !== 0) {
            \__mc_icu_udatpg_close(\int_to_ptr($this->__mcGen));
            $this->__mcGen = 0;
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return [];
    }

    public function getBestPattern(string $skeleton): string|false
    {
        $fn = "IntlDatePatternGenerator::getBestPattern";
        \__mc_intl_reset();
        $u = \__mc_icu_to16($skeleton);
        if ($u === null) {
            \__mc_intl_fail($fn, "Skeleton is not a valid UTF-8 string", 10);
            return false;
        }
        $g = \int_to_ptr($this->__mcGen);
        __McIcuStatus::$code = 0;
        $clean = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
            => \__mc_icu_udatpg_getSkeleton(\int_to_ptr(0), $u->buf, $u->len, $b, $c, $e));
        \__mc_icu_free($u->buf);
        if ($clean === null) {
            \__mc_intl_fail($fn, "Error getting cleaned skeleton", __McIcuStatus::$code);
            return false;
        }
        $s = \__mc_icu_to16($clean);
        $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
            => \__mc_icu_udatpg_getBestPattern($g, $s->buf, $s->len, $b, $c, $e));
        \__mc_icu_free($s->buf);
        if ($out === null) {
            \__mc_intl_fail($fn, "Error retrieving pattern", __McIcuStatus::$code);
            return false;
        }
        return $out;
    }
}
