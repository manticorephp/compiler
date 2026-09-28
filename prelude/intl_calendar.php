<?php

// ext/intl IntlCalendar / IntlGregorianCalendar (php-src ext/intl/calendar/*.cpp) over ICU's
// C calendar API. Zend holds an icu::Calendar; here a UCalendar handle. The zone bindings
// (ucal_open, ucal_get, ...) live in intl_timezone.php, which this family always rides with.

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_clone')]
function __mc_icu_ucal_clone(\Ffi\Ptr $cal, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getType')]
function __mc_icu_ucal_getType(\Ffi\Ptr $cal, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_set')]
function __mc_icu_ucal_set(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $field, #[\Ffi\CType('int')] int $value): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_clear')]
function __mc_icu_ucal_clear(\Ffi\Ptr $cal): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_clearField')]
function __mc_icu_ucal_clearField(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $field): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_isSet'), \Ffi\CType('char')]
function __mc_icu_ucal_isSet(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $field): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getMillis'), \Ffi\CType('double')]
function __mc_icu_ucal_getMillis(\Ffi\Ptr $cal, \Ffi\Ptr $err): float { return 0.0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_add')]
function __mc_icu_ucal_add(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $field, #[\Ffi\CType('int')] int $amount,
    \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_roll')]
function __mc_icu_ucal_roll(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $field, #[\Ffi\CType('int')] int $amount,
    \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getFieldDifference'), \Ffi\CType('int')]
function __mc_icu_ucal_getFieldDifference(\Ffi\Ptr $cal, #[\Ffi\CType('double')] float $target,
    #[\Ffi\CType('int')] int $field, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getLimit'), \Ffi\CType('int')]
function __mc_icu_ucal_getLimit(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $field, #[\Ffi\CType('int')] int $type,
    \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getDayOfWeekType'), \Ffi\CType('int')]
function __mc_icu_ucal_getDayOfWeekType(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $dow, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getWeekendTransition'), \Ffi\CType('int')]
function __mc_icu_ucal_getWeekendTransition(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $dow, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_isWeekend'), \Ffi\CType('char')]
function __mc_icu_ucal_isWeekend(\Ffi\Ptr $cal, #[\Ffi\CType('double')] float $date, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getAttribute'), \Ffi\CType('int')]
function __mc_icu_ucal_getAttribute(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $attr): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_setAttribute')]
function __mc_icu_ucal_setAttribute(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $attr, #[\Ffi\CType('int')] int $value): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getLocaleByType')]
function __mc_icu_ucal_getLocaleByType(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $type, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_inDaylightTime'), \Ffi\CType('char')]
function __mc_icu_ucal_inDaylightTime(\Ffi\Ptr $cal, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_equivalentTo'), \Ffi\CType('char')]
function __mc_icu_ucal_equivalentTo(\Ffi\Ptr $a, \Ffi\Ptr $b): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_setTimeZone')]
function __mc_icu_ucal_setTimeZone(\Ffi\Ptr $cal, \Ffi\Ptr $zone, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getKeywordValuesForLocale')]
function __mc_icu_ucal_getKeywordValuesForLocale(string $key, string $locale, #[\Ffi\CType('int')] int $common,
    \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_countAvailable'), \Ffi\CType('int')]
function __mc_icu_ucal_countAvailable(): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getAvailable')]
function __mc_icu_ucal_getAvailable(#[\Ffi\CType('int')] int $i): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getGregorianChange'), \Ffi\CType('double')]
function __mc_icu_ucal_getGregorianChange(\Ffi\Ptr $cal, \Ffi\Ptr $err): float { return 0.0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_setGregorianChange')]
function __mc_icu_ucal_setGregorianChange(\Ffi\Ptr $cal, #[\Ffi\CType('double')] float $date, \Ffi\Ptr $err): void {}

/** The php default time zone a calendar gets when none is passed. */
function __mc_intlcal_default_zone(): string
{
    return \date_default_timezone_get();
}

/**
 * timezone_process_timezone_argument: the ICU zone ID for a calendar/formatter
 * argument, or null with the error set. `$obj` receives a conversion error.
 */
function __mc_intlcal_zone(string $fn, IntlTimeZone|DateTimeZone|string|null $tz, ?IntlCalendar $obj): ?string
{
    if ($tz instanceof IntlTimeZone) { return $tz->__mcId(); }
    if ($tz instanceof DateTimeZone) {
        $z = \__mc_intltz_from_dtz($fn, $tz);
        if ($z === null) {
            if ($obj !== null) { $obj->__mcSetError(__McIntlError::$code, \intl_get_error_message()); }
            return null;
        }
        return $z->__mcId();
    }
    $id = $tz ?? \__mc_intlcal_default_zone();
    if (\__mc_icu_to16($id) === null) {
        throw new IntlException($fn . "(): Time zone identifier given is not a valid UTF-8 string");
    }
    $z = IntlTimeZone::__mcOf($id);
    if ($z->__mcId() === "Etc/Unknown") {
        throw new IntlException($fn . "(): No such time zone: \"" . $id . "\"");
    }
    return $z->__mcId();
}

/** A UCalendar of `$type` (0 = the locale's, 1 = Gregorian) on `$zone`; null on failure. */
function __mc_intlcal_open(string $zone, string $locale, int $type): ?\Ffi\Ptr
{
    $u = \__mc_icu_to16($zone);
    $e = \__mc_icu_err();
    $cal = \__mc_icu_ucal_open($u->buf, $u->len, $locale, $type, $e);
    \__mc_icu_free($u->buf);
    __McIcuStatus::$code = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if (__McIcuStatus::$code > 0 || \ptr_to_int($cal) === 0) { return null; }
    return $cal;
}

/** A pending UCalendar the next IntlGregorianCalendar construction adopts. */
final class __McCalAdopt
{
    public static int $ptr = 0;
}

/** Wrap a UCalendar: IntlGregorianCalendar for the Gregorian class, IntlCalendar otherwise. */
function __mc_intlcal_wrap(\Ffi\Ptr $cal): IntlCalendar
{
    $e = \__mc_icu_err();
    $type = \cstr_to_str(\__mc_icu_ucal_getType($cal, $e));
    \__mc_icu_free($e);
    if ($type === "gregorian") {
        __McCalAdopt::$ptr = \ptr_to_int($cal);
        return new IntlGregorianCalendar();
    }
    return IntlCalendar::__mcAdopt($cal);
}

function __mc_intlcal_field_error(string $fn, int $pos, string $name, string $what): \ValueError
{
    return new \ValueError($fn . "(): Argument #" . (string)$pos . " ($" . $name . ") " . $what);
}

class IntlCalendar
{
    public const FIELD_ERA = 0;
    public const FIELD_YEAR = 1;
    public const FIELD_MONTH = 2;
    public const FIELD_WEEK_OF_YEAR = 3;
    public const FIELD_WEEK_OF_MONTH = 4;
    public const FIELD_DATE = 5;
    public const FIELD_DAY_OF_YEAR = 6;
    public const FIELD_DAY_OF_WEEK = 7;
    public const FIELD_DAY_OF_WEEK_IN_MONTH = 8;
    public const FIELD_AM_PM = 9;
    public const FIELD_HOUR = 10;
    public const FIELD_HOUR_OF_DAY = 11;
    public const FIELD_MINUTE = 12;
    public const FIELD_SECOND = 13;
    public const FIELD_MILLISECOND = 14;
    public const FIELD_ZONE_OFFSET = 15;
    public const FIELD_DST_OFFSET = 16;
    public const FIELD_YEAR_WOY = 17;
    public const FIELD_DOW_LOCAL = 18;
    public const FIELD_EXTENDED_YEAR = 19;
    public const FIELD_JULIAN_DAY = 20;
    public const FIELD_MILLISECONDS_IN_DAY = 21;
    public const FIELD_IS_LEAP_MONTH = 22;
    public const FIELD_FIELD_COUNT = 24;
    public const FIELD_DAY_OF_MONTH = 5;
    public const DOW_SUNDAY = 1;
    public const DOW_MONDAY = 2;
    public const DOW_TUESDAY = 3;
    public const DOW_WEDNESDAY = 4;
    public const DOW_THURSDAY = 5;
    public const DOW_FRIDAY = 6;
    public const DOW_SATURDAY = 7;
    public const DOW_TYPE_WEEKDAY = 0;
    public const DOW_TYPE_WEEKEND = 1;
    public const DOW_TYPE_WEEKEND_OFFSET = 2;
    public const DOW_TYPE_WEEKEND_CEASE = 3;
    public const WALLTIME_FIRST = 1;
    public const WALLTIME_LAST = 0;
    public const WALLTIME_NEXT_VALID = 2;

    protected int $__mcCal = 0;
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    private function __construct() {}

    public static function __mcAdopt(\Ffi\Ptr $cal): IntlCalendar
    {
        $c = new IntlCalendar();
        $c->__mcCal = \ptr_to_int($cal);
        return $c;
    }

    public function __mcTakePending(): void
    {
        $this->__mcCal = __McCalAdopt::$ptr;
        __McCalAdopt::$ptr = 0;
    }

    public function __mcPtr(): \Ffi\Ptr
    {
        return \int_to_ptr($this->__mcCal);
    }

    public function __clone()
    {
        $e = \__mc_icu_err();
        $this->__mcCal = \ptr_to_int(\__mc_icu_ucal_clone($this->__mcPtr(), $e));
        \__mc_icu_free($e);
    }

    public function __destruct()
    {
        if ($this->__mcCal !== 0) {
            \__mc_icu_ucal_close($this->__mcPtr());
            $this->__mcCal = 0;
        }
    }

    public function __mcSetError(int $code, string $message): void
    {
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = $message;
    }

    /** CALENDAR_METHOD_INIT_VARS + FETCH_OBJECT: both error states reset. */
    public function __mcReset(): void
    {
        \__mc_intl_reset();
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
    }

    /** INTL_METHOD_CHECK_STATUS: true when `$e` holds a failure, which is then recorded. */
    public function __mcFailed(string $fn, string $what, \Ffi\Ptr $e): bool
    {
        $c = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($c <= 0) { return false; }
        $this->__mcFail($fn, $what, $c);
        return true;
    }

    public function __mcFail(string $fn, string $what, int $code): void
    {
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $cal = $this->__mcPtr();
        $e = \__mc_icu_err();
        $type = \cstr_to_str(\__mc_icu_ucal_getType($cal, $e));
        \poke_i32($e, 0, 0);
        $loc = \__mc_icu_ucal_getLocaleByType($cal, 1, $e);
        $lc = \peek_i32($e, 0);
        \__mc_icu_free($e);
        $out = ["valid" => true, "type" => $type,
            "timeZone" => IntlTimeZone::__mcOf(\__mc_tz_cal_id($cal))->__debugInfo(),
            "locale" => $lc > 0 ? \intl_error_name($lc) : \cstr_to_str($loc)];
        $names = ["era", "year", "month", "week of year", "week of month", "day of year", "day of month",
            "day of week", "day of week in month", "AM/PM", "hour", "hour of day", "minute", "second",
            "millisecond", "zone offset", "DST offset", "year for week of year", "localized day of week",
            "extended year", "julian day", "milliseconds in day", "is leap month"];
        $fieldOf = [0, 1, 2, 3, 4, 6, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22];
        $fields = [];
        $i = 0;
        while ($i < 23) {
            $fe = \__mc_icu_err();
            $v = \__mc_icu_ucal_get($cal, $fieldOf[$i], $fe);
            $fc = \peek_i32($fe, 0);
            \__mc_icu_free($fe);
            $fields[$names[$i]] = $fc > 0 ? \intl_error_name($fc) : $v;
            $i = $i + 1;
        }
        $out["fields"] = $fields;
        return $out;
    }

    public static function createInstance(IntlTimeZone|DateTimeZone|string|null $timezone = null, ?string $locale = null): ?IntlCalendar
    {
        return \__mc_intlcal_create("IntlCalendar::createInstance", $timezone, $locale);
    }

    public function equals(IntlCalendar $other): bool
    {
        return $this->__mcEquals("IntlCalendar::equals", $other);
    }

    public function __mcEquals(string $fn, IntlCalendar $other): bool
    {
        // Calendar::equals compares the instants alone (isEquivalentTo is the settings).
        $this->__mcReset();
        $e = \__mc_icu_err();
        $a = \__mc_icu_ucal_getMillis($this->__mcPtr(), $e);
        $b = \__mc_icu_ucal_getMillis($other->__mcPtr(), $e);
        if ($this->__mcFailed($fn, "error calling ICU Calendar::equals", $e)) { return false; }
        return $a === $b;
    }

    public function fieldDifference(float $timestamp, int $field): int|false
    {
        return $this->__mcFieldDifference("IntlCalendar::fieldDifference", 0, $timestamp, $field);
    }

    public function __mcFieldDifference(string $fn, int $off, float $timestamp, int $field): int|false
    {
        if ($field < 0 || $field >= 24) {
            throw \__mc_intlcal_field_error($fn, 2 + $off, "field", "must be a valid field");
        }
        $this->__mcReset();
        $e = \__mc_icu_err();
        $r = \__mc_icu_ucal_getFieldDifference($this->__mcPtr(), $timestamp, $field, $e);
        if ($this->__mcFailed($fn, "Call to ICU method has failed", $e)) { return false; }
        return $r;
    }

    public function add(int $field, int $value): bool
    {
        return $this->__mcAddRoll("IntlCalendar::add", 0, $field, $value, false);
    }

    public function __mcAddRoll(string $fn, int $off, int $field, int $value, bool $roll): bool
    {
        if ($field < 0 || $field >= 24) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "field", "must be a valid field");
        }
        if ($value < -2147483648 || $value > 2147483647) {
            throw \__mc_intlcal_field_error($fn, 2 + $off, "value", "must be between -2147483648 and 2147483647");
        }
        $this->__mcReset();
        $e = \__mc_icu_err();
        if ($roll) {
            \__mc_icu_ucal_roll($this->__mcPtr(), $field, $value, $e);
            return !$this->__mcFailed($fn, "Error calling ICU Calendar::roll", $e);
        }
        \__mc_icu_ucal_add($this->__mcPtr(), $field, $value, $e);
        return !$this->__mcFailed($fn, "Call to underlying method failed", $e);
    }

    public function after(IntlCalendar $other): bool
    {
        return $this->__mcCompare("IntlCalendar::after", $other) > 0;
    }

    public function before(IntlCalendar $other): bool
    {
        return $this->__mcCompare("IntlCalendar::before", $other) < 0;
    }

    public function __mcCompare(string $fn, IntlCalendar $other): int
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $a = \__mc_icu_ucal_getMillis($this->__mcPtr(), $e);
        $b = \__mc_icu_ucal_getMillis($other->__mcPtr(), $e);
        if ($this->__mcFailed($fn, "Error calling ICU method", $e)) { return 0; }
        return $a <=> $b;
    }

    public function clear(?int $field = null): true
    {
        return $this->__mcClear("IntlCalendar::clear", 0, $field);
    }

    public function __mcClear(string $fn, int $off, ?int $field): true
    {
        $this->__mcReset();
        if ($field === null) {
            \__mc_icu_ucal_clear($this->__mcPtr());
            return true;
        }
        if ($field < 0 || $field >= 24) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "field", "must be a valid field");
        }
        \__mc_icu_ucal_clearField($this->__mcPtr(), $field);
        return true;
    }

    public static function fromDateTime(DateTime|string $datetime, ?string $locale = null): ?IntlCalendar
    {
        return \__mc_intlcal_from_date_time("IntlCalendar::fromDateTime", $datetime, $locale);
    }

    public function get(int $field): int|false
    {
        return $this->__mcFieldInt("IntlCalendar::get", 0, $field, -1);
    }

    /** get / getActual* (limit type 4, 5) / get{Minimum,Maximum,GreatestMinimum,LeastMaximum} (0..3). */
    public function __mcFieldInt(string $fn, int $off, int $field, int $limit): int|false
    {
        if ($field < 0 || $field >= 24) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "field", "must be a valid field");
        }
        $this->__mcReset();
        $e = \__mc_icu_err();
        $r = $limit < 0 ? \__mc_icu_ucal_get($this->__mcPtr(), $field, $e)
            : \__mc_icu_ucal_getLimit($this->__mcPtr(), $field, $limit, $e);
        if ($this->__mcFailed($fn, "Call to ICU method has failed", $e)) { return false; }
        return $r;
    }

    public function getActualMaximum(int $field): int|false
    {
        return $this->__mcFieldInt("IntlCalendar::getActualMaximum", 0, $field, 5);
    }

    public function getActualMinimum(int $field): int|false
    {
        return $this->__mcFieldInt("IntlCalendar::getActualMinimum", 0, $field, 4);
    }

    /** @return string[] */
    public static function getAvailableLocales(): array
    {
        \__mc_intl_reset();
        $out = [];
        $n = \__mc_icu_ucal_countAvailable();
        $i = 0;
        while ($i < $n) {
            $out[] = \cstr_to_str(\__mc_icu_ucal_getAvailable($i));
            $i = $i + 1;
        }
        return $out;
    }

    public function getDayOfWeekType(int $dayOfWeek): int|false
    {
        return $this->__mcDow("IntlCalendar::getDayOfWeekType", 0, $dayOfWeek, false);
    }

    public function __mcDow(string $fn, int $off, int $dow, bool $transition): int|false
    {
        if ($dow < 1 || $dow > 7) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "dayOfWeek", "must be a valid day of the week");
        }
        $this->__mcReset();
        $e = \__mc_icu_err();
        if ($transition) {
            $r = \__mc_icu_ucal_getWeekendTransition($this->__mcPtr(), $dow, $e);
            if ($this->__mcFailed($fn, "Error calling ICU method", $e)) { return false; }
            return $r;
        }
        $r = \__mc_icu_ucal_getDayOfWeekType($this->__mcPtr(), $dow, $e);
        if ($this->__mcFailed($fn, "Call to ICU method has failed", $e)) { return false; }
        return $r;
    }

    public function getErrorCode(): int|false
    {
        \__mc_intl_reset();
        return $this->__mcErrCode;
    }

    public function getErrorMessage(): string|false
    {
        \__mc_intl_reset();
        return $this->__mcErrMessage !== "" ? $this->__mcErrMessage : \intl_error_name($this->__mcErrCode);
    }

    public function getFirstDayOfWeek(): int|false
    {
        $this->__mcReset();
        return \__mc_icu_ucal_getAttribute($this->__mcPtr(), 1);
    }

    public function getGreatestMinimum(int $field): int|false
    {
        return $this->__mcFieldInt("IntlCalendar::getGreatestMinimum", 0, $field, 2);
    }

    public static function getKeywordValuesForLocale(string $keyword, string $locale, bool $onlyCommon): IntlIterator|false
    {
        return \__mc_intlcal_keyword_values("IntlCalendar::getKeywordValuesForLocale", $keyword, $locale, $onlyCommon);
    }

    public function getLeastMaximum(int $field): int|false
    {
        return $this->__mcFieldInt("IntlCalendar::getLeastMaximum", 0, $field, 3);
    }

    public function getLocale(int $type): string|false
    {
        return $this->__mcGetLocale("IntlCalendar::getLocale", 0, $type);
    }

    public function __mcGetLocale(string $fn, int $off, int $type): string|false
    {
        if ($type !== 0 && $type !== 1) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "type", "must be either Locale::ACTUAL_LOCALE or Locale::VALID_LOCALE");
        }
        $this->__mcReset();
        $e = \__mc_icu_err();
        $p = \__mc_icu_ucal_getLocaleByType($this->__mcPtr(), $type, $e);
        if ($this->__mcFailed($fn, "Call to ICU method has failed", $e)) { return false; }
        return \cstr_to_str($p);
    }

    public function getMaximum(int $field): int|false
    {
        return $this->__mcFieldInt("IntlCalendar::getMaximum", 0, $field, 1);
    }

    public function getMinimalDaysInFirstWeek(): int|false
    {
        $this->__mcReset();
        return \__mc_icu_ucal_getAttribute($this->__mcPtr(), 2);
    }

    public function setMinimalDaysInFirstWeek(int $days): true
    {
        return $this->__mcSetMinimalDays("IntlCalendar::setMinimalDaysInFirstWeek", 0, $days);
    }

    public function __mcSetMinimalDays(string $fn, int $off, int $days): true
    {
        if ($days < 1 || $days > 7) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "days", "must be between 1 and 7");
        }
        $this->__mcReset();
        \__mc_icu_ucal_setAttribute($this->__mcPtr(), 2, $days);
        return true;
    }

    public function getMinimum(int $field): int|false
    {
        return $this->__mcFieldInt("IntlCalendar::getMinimum", 0, $field, 0);
    }

    public static function getNow(): float
    {
        \__mc_intl_reset();
        return \__mc_icu_ucal_getNow();
    }

    public function getRepeatedWallTimeOption(): int
    {
        $this->__mcReset();
        return \__mc_icu_ucal_getAttribute($this->__mcPtr(), 3);
    }

    public function getSkippedWallTimeOption(): int
    {
        $this->__mcReset();
        return \__mc_icu_ucal_getAttribute($this->__mcPtr(), 4);
    }

    public function getTime(): float|false
    {
        return $this->__mcGetTime("IntlCalendar::getTime");
    }

    public function __mcGetTime(string $fn): float|false
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $t = \__mc_icu_ucal_getMillis($this->__mcPtr(), $e);
        if ($this->__mcFailed($fn, "error calling ICU Calendar::getTime", $e)) { return false; }
        return $t;
    }

    public function getTimeZone(): IntlTimeZone|false
    {
        $this->__mcReset();
        return IntlTimeZone::__mcOf(\__mc_tz_cal_id($this->__mcPtr()));
    }

    public function getType(): string
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $t = \cstr_to_str(\__mc_icu_ucal_getType($this->__mcPtr(), $e));
        \__mc_icu_free($e);
        return $t;
    }

    public function getWeekendTransition(int $dayOfWeek): int|false
    {
        return $this->__mcDow("IntlCalendar::getWeekendTransition", 0, $dayOfWeek, true);
    }

    public function inDaylightTime(): bool
    {
        return $this->__mcInDaylight("IntlCalendar::inDaylightTime");
    }

    public function __mcInDaylight(string $fn): bool
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $r = \__mc_icu_ucal_inDaylightTime($this->__mcPtr(), $e);
        if ($this->__mcFailed($fn, "Error calling ICU method", $e)) { return false; }
        return $r !== 0;
    }

    public function isEquivalentTo(IntlCalendar $other): bool
    {
        $this->__mcReset();
        return \__mc_icu_ucal_equivalentTo($this->__mcPtr(), $other->__mcPtr()) !== 0;
    }

    public function isLenient(): bool
    {
        $this->__mcReset();
        return \__mc_icu_ucal_getAttribute($this->__mcPtr(), 0) !== 0;
    }

    public function isWeekend(?float $timestamp = null): bool
    {
        return $this->__mcIsWeekend("IntlCalendar::isWeekend", $timestamp);
    }

    public function __mcIsWeekend(string $fn, ?float $timestamp): bool
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        if ($timestamp === null) {
            $t = \__mc_icu_ucal_getMillis($this->__mcPtr(), $e);
            $r = \__mc_icu_ucal_isWeekend($this->__mcPtr(), $t, $e);
            \__mc_icu_free($e);
            return $r !== 0;
        }
        $r = \__mc_icu_ucal_isWeekend($this->__mcPtr(), $timestamp, $e);
        if ($this->__mcFailed($fn, "Error calling ICU method", $e)) { return false; }
        return $r !== 0;
    }

    public function roll(int $field, $value): bool
    {
        return $this->__mcRoll("IntlCalendar::roll", 0, $field, $value);
    }

    public function __mcRoll(string $fn, int $off, int $field, mixed $value): bool
    {
        if ($field < 0 || $field >= 24) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "field", "must be a valid field");
        }
        $v = \is_bool($value) ? ($value ? 1 : -1) : (int)$value;
        return $this->__mcAddRoll($fn, $off, $field, $v, true);
    }

    public function isSet(int $field): bool
    {
        return $this->__mcIsSet("IntlCalendar::isSet", 0, $field);
    }

    public function __mcIsSet(string $fn, int $off, int $field): bool
    {
        if ($field < 0 || $field >= 24) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "field", "must be a valid field");
        }
        $this->__mcReset();
        return \__mc_icu_ucal_isSet($this->__mcPtr(), $field) !== 0;
    }

    public function set(int $year, int $month, int $dayOfMonth = -2147483649, int $hour = -2147483649, int $minute = -2147483649, int $second = -2147483649): true
    {
        return $this->__mcSet("IntlCalendar::set", 0, [$year, $month, $dayOfMonth, $hour, $minute, $second]);
    }

    /** @param int[] $args (an absent trailing argument is -2147483649, outside every int32) */
    public function __mcSet(string $fn, int $off, array $args): true
    {
        $n = 0;
        while ($n < 6 && $args[$n] !== -2147483649) { $n = $n + 1; }
        $names = ["year", "month", "dayOfMonth", "hour", "minute", "second"];
        $i = 0;
        while ($i < $n) {
            if ($args[$i] < -2147483648 || $args[$i] > 2147483647) {
                throw \__mc_intlcal_field_error($fn, $i + 1 + $off, $names[$i], "must be between -2147483648 and 2147483647");
            }
            $i = $i + 1;
        }
        $this->__mcReset();
        $cal = $this->__mcPtr();
        if ($n === 2) {
            if ($args[0] < 0 || $args[0] >= 24) {
                throw \__mc_intlcal_field_error($fn, 1 + $off, "year", "must be a valid field");
            }
            \__mc_icu_ucal_set($cal, $args[0], $args[1]);
            return true;
        }
        if ($n === 4) {
            throw new \ArgumentCountError("IntlCalendar::set() has no variant with exactly 4 parameters");
        }
        $fields = [1, 2, 5, 11, 12, 13];
        $i = 0;
        while ($i < $n) {
            \__mc_icu_ucal_set($cal, $fields[$i], $args[$i]);
            $i = $i + 1;
        }
        return true;
    }

    public function setDate(int $year, int $month, int $dayOfMonth): void
    {
        $this->__mcSetParts("IntlCalendar::setDate", [$year, $month, $dayOfMonth]);
    }

    public function setDateTime(int $year, int $month, int $dayOfMonth, int $hour, int $minute, ?int $second = null): void
    {
        $args = [$year, $month, $dayOfMonth, $hour, $minute];
        if ($second !== null) { $args[] = $second; }
        $this->__mcSetParts("IntlCalendar::setDateTime", $args);
    }

    /** @param int[] $args */
    private function __mcSetParts(string $fn, array $args): void
    {
        $names = ["year", "month", "dayOfMonth", "hour", "minute", "second"];
        $fields = [1, 2, 5, 11, 12, 13];
        $i = 0;
        while ($i < \count($args)) {
            if ($args[$i] < -2147483648 || $args[$i] > 2147483647) {
                throw \__mc_intlcal_field_error($fn, $i + 1, $names[$i], "must be between -2147483648 and 2147483647");
            }
            $i = $i + 1;
        }
        $this->__mcReset();
        $i = 0;
        while ($i < \count($args)) {
            \__mc_icu_ucal_set($this->__mcPtr(), $fields[$i], $args[$i]);
            $i = $i + 1;
        }
    }

    public function setFirstDayOfWeek(int $dayOfWeek): true
    {
        return $this->__mcSetFirstDay("IntlCalendar::setFirstDayOfWeek", 0, $dayOfWeek);
    }

    public function __mcSetFirstDay(string $fn, int $off, int $dow): true
    {
        if ($dow < 1 || $dow > 7) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "dayOfWeek", "must be a valid day of the week");
        }
        $this->__mcReset();
        \__mc_icu_ucal_setAttribute($this->__mcPtr(), 1, $dow);
        return true;
    }

    public function setLenient(bool $lenient): true
    {
        $this->__mcReset();
        \__mc_icu_ucal_setAttribute($this->__mcPtr(), 0, $lenient ? 1 : 0);
        return true;
    }

    public function setRepeatedWallTimeOption(int $option): true
    {
        return $this->__mcSetWallTime("IntlCalendar::setRepeatedWallTimeOption", 0, $option, false);
    }

    public function setSkippedWallTimeOption(int $option): true
    {
        return $this->__mcSetWallTime("IntlCalendar::setSkippedWallTimeOption", 0, $option, true);
    }

    public function __mcSetWallTime(string $fn, int $off, int $option, bool $skipped): true
    {
        if ($skipped && $option !== 0 && $option !== 1 && $option !== 2) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "option", "must be one of IntlCalendar::WALLTIME_FIRST, "
                . "IntlCalendar::WALLTIME_LAST, or IntlCalendar::WALLTIME_NEXT_VALID");
        }
        if (!$skipped && $option !== 0 && $option !== 1) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "option", "must be either IntlCalendar::WALLTIME_FIRST or "
                . "IntlCalendar::WALLTIME_LAST");
        }
        $this->__mcReset();
        \__mc_icu_ucal_setAttribute($this->__mcPtr(), $skipped ? 4 : 3, $option);
        return true;
    }

    public function setTime(float $timestamp): bool
    {
        return $this->__mcSetTime("IntlCalendar::setTime", $timestamp);
    }

    public function __mcSetTime(string $fn, float $timestamp): bool
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        \__mc_icu_ucal_setMillis($this->__mcPtr(), $timestamp, $e);
        return !$this->__mcFailed($fn, "Call to underlying method failed", $e);
    }

    public function setTimeZone(IntlTimeZone|DateTimeZone|string|null $timezone): bool
    {
        return $this->__mcSetTimeZone("IntlCalendar::setTimeZone", $timezone);
    }

    public function __mcSetTimeZone(string $fn, IntlTimeZone|DateTimeZone|string|null $timezone): bool
    {
        $this->__mcReset();
        if ($timezone === null) { return true; }
        $id = \__mc_intlcal_zone($fn, $timezone, $this);
        if ($id === null) { return false; }
        $u = \__mc_icu_to16($id);
        $e = \__mc_icu_err();
        \__mc_icu_ucal_setTimeZone($this->__mcPtr(), $u->buf, $u->len, $e);
        \__mc_icu_free($e);
        \__mc_icu_free($u->buf);
        return true;
    }

    public function toDateTime(): DateTime|false
    {
        return $this->__mcToDateTime("IntlCalendar::toDateTime");
    }

    public function __mcToDateTime(string $fn): DateTime|false
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $ms = \__mc_icu_ucal_getMillis($this->__mcPtr(), $e);
        if ($this->__mcFailed($fn, "Call to ICU method has failed", $e)) { return false; }
        $date = $ms / 1000.0;
        if ($date > 9.2233720368547758E18 || $date < -9.2233720368547758E18) {
            $this->__mcFail($fn, "The calendar date is out of the range for a 64-bit integer", 1);
            return false;
        }
        $tz = IntlTimeZone::__mcOf(\__mc_tz_cal_id($this->__mcPtr()))->__mcToDateTimeZone($fn);
        if ($tz === false) { return false; }
        $dt = new DateTime("@" . (string)(int)$date, $tz);
        $dt->setTimezone($tz);
        return $dt;
    }
}

class IntlGregorianCalendar extends IntlCalendar
{
    public static function createFromDate(int $year, int $month, int $dayOfMonth): static
    {
        return \__mc_intlgregcal_from_date("IntlGregorianCalendar::createFromDate", [$year, $month, $dayOfMonth]);
    }

    public static function createFromDateTime(int $year, int $month, int $dayOfMonth, int $hour, int $minute, ?int $second = null): static
    {
        $args = [$year, $month, $dayOfMonth, $hour, $minute];
        if ($second !== null) { $args[] = $second; }
        return \__mc_intlgregcal_from_date("IntlGregorianCalendar::createFromDateTime", $args);
    }

    public function __construct($timezoneOrYear = null, $localeOrMonth = null, $day = null, $hour = null, $minute = null, $second = null)
    {
        if (__McCalAdopt::$ptr !== 0) {
            $this->__mcTakePending();
            return;
        }
        \__mc_intl_reset();
        $cal = \__mc_intlgregcal_open("IntlGregorianCalendar::__construct",
            [$timezoneOrYear, $localeOrMonth, $day, $hour, $minute, $second], \func_num_args());
        if ($cal === null) {
            throw new IntlException("Constructor failed");
        }
        __McCalAdopt::$ptr = \ptr_to_int($cal);
        $this->__mcTakePending();
    }

    public function setGregorianChange(float $timestamp): bool
    {
        return $this->__mcSetGregorianChange("IntlGregorianCalendar::setGregorianChange", $timestamp);
    }

    public function __mcSetGregorianChange(string $fn, float $timestamp): bool
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        \__mc_icu_ucal_setGregorianChange($this->__mcPtr(), $timestamp, $e);
        return !$this->__mcFailed($fn, "error calling ICU method", $e);
    }

    public function getGregorianChange(): float
    {
        $this->__mcReset();
        $e = \__mc_icu_err();
        $t = \__mc_icu_ucal_getGregorianChange($this->__mcPtr(), $e);
        \__mc_icu_free($e);
        return $t;
    }

    public function isLeapYear(int $year): bool
    {
        return $this->__mcIsLeapYear("IntlGregorianCalendar::isLeapYear", 0, $year);
    }

    /** GregorianCalendar::isLeapYear against fGregorianCutoverYear, the cutover's year in the calendar's zone. */
    public function __mcIsLeapYear(string $fn, int $off, int $year): bool
    {
        if ($year < -2147483648 || $year > 2147483647) {
            throw \__mc_intlcal_field_error($fn, 1 + $off, "year", "must be between -2147483648 and 2147483647");
        }
        $this->__mcReset();
        $e = \__mc_icu_err();
        $cut = \__mc_icu_ucal_getGregorianChange($this->__mcPtr(), $e);
        \__mc_icu_free($e);
        $cutYear = \__mc_intlgregcal_cutover_year($this->__mcPtr(), $cut);
        if ($year >= $cutYear) {
            return ($year & 3) === 0 && ($year % 100 !== 0 || $year % 400 === 0);
        }
        return ($year & 3) === 0;
    }
}

/** fGregorianCutoverYear: the (era-normalized) year of `$cut` on a clone of `$cal`. */
function __mc_intlgregcal_cutover_year(\Ffi\Ptr $cal, float $cut): int
{
    if ($cut <= -184303902528000000.0) { return -2147483648; }
    if ($cut >= 183882168921600000.0) { return 2147483647; }
    $e = \__mc_icu_err();
    $c = \__mc_icu_ucal_clone($cal, $e);
    \__mc_icu_ucal_setMillis($c, $cut, $e);
    $y = \__mc_icu_ucal_get($c, 1, $e);
    $era = \__mc_icu_ucal_get($c, 0, $e);
    \__mc_icu_ucal_close($c);
    \__mc_icu_free($e);
    return $era === 0 ? 1 - $y : $y;
}

/**
 * _php_intlgregcal_constructor_body: the (tz, locale) variant or the (y, m, d[, h, i[, s]])
 * one, picked by the argument count less trailing NULLs. Null when the ICU object failed.
 *
 * @param mixed[] $args
 */
function __mc_intlgregcal_open(string $fn, array $args, int $given): ?\Ffi\Ptr
{
    if ($given > 6) {
        throw new \ArgumentCountError("Too many arguments");
    }
    $variant = $given;
    while ($variant > 0 && $args[$variant - 1] === null) { $variant = $variant - 1; }
    if ($variant === 4) {
        throw new \ArgumentCountError("No variant with 4 arguments (excluding trailing NULLs)");
    }
    if ($variant <= 2) {
        $tz = $args[0];
        $loc = $args[1];
        if ($tz !== null && !\is_string($tz) && !($tz instanceof IntlTimeZone) && !($tz instanceof DateTimeZone)) {
            throw new \TypeError($fn . "(): Argument #1 (\$timezoneOrYear) must be of type IntlTimeZone|DateTimeZone|string|null, "
                . \get_debug_type($tz) . " given");
        }
        $zone = \__mc_intlcal_zone($fn, $tz, null);
        if ($zone === null) { return null; }
        $cal = \__mc_intlcal_open($zone, $loc === null ? \__mc_intl_default_locale() : (string)$loc, 1);
        if ($cal === null) {
            \__mc_intl_fail($fn, "error creating ICU GregorianCalendar from time zone and locale", __McIcuStatus::$code);
        }
        return $cal;
    }
    $names = ["timezoneOrYear", "localeOrMonth", "day", "hour", "minute", "second"];
    $ints = [];
    $i = 0;
    while ($i < $variant) {
        $v = $args[$i];
        if ($v === null && $i >= 3) { $v = 0; }
        if (!\is_int($v)) {
            if (\is_numeric($v)) { $v = (int)$v; }
            else {
                throw new \TypeError($fn . "(): Argument #" . (string)($i + 1) . " (\$" . $names[$i] . ") must be of type int, "
                    . \get_debug_type($v) . " given");
            }
        }
        if ($v < -2147483648 || $v > 2147483647) {
            throw \__mc_intlcal_field_error($fn, $i + 1, $names[$i], "must be between -2147483648 and 2147483647");
        }
        $ints[] = $v;
        $i = $i + 1;
    }
    return \__mc_intlgregcal_date_cal($fn, $ints);
}

/**
 * GregorianCalendar(y, m, d[, h, i[, s]]): the ICU default zone and locale, cleared, the fields
 * set in constructor order — then php adopts its own default zone.
 *
 * @param int[] $ints
 */
function __mc_intlgregcal_date_cal(string $fn, array $ints): ?\Ffi\Ptr
{
    $zone = \__mc_intlcal_default_zone();
    $cal = \__mc_intlcal_open($zone, \cstr_to_str(\__mc_icu_uloc_getDefault()), 1);
    if ($cal === null) {
        \__mc_intl_fail($fn, "Error creating ICU GregorianCalendar from date", __McIcuStatus::$code);
        return null;
    }
    \__mc_icu_ucal_clear($cal);
    \__mc_icu_ucal_set($cal, 0, 1);
    $fields = [1, 2, 5, 11, 12, 13];
    $i = 0;
    while ($i < \count($ints)) {
        \__mc_icu_ucal_set($cal, $fields[$i], $ints[$i]);
        $i = $i + 1;
    }
    return $cal;
}

/** @param int[] $ints */
function __mc_intlgregcal_from_date(string $fn, array $ints): IntlGregorianCalendar
{
    \__mc_intl_reset();
    $names = ["year", "month", "dayOfMonth", "hour", "minute", "second"];
    $i = 0;
    while ($i < \count($ints)) {
        if ($ints[$i] < -2147483648 || $ints[$i] > 2147483647) {
            throw \__mc_intlcal_field_error($fn, $i + 1, $names[$i], "must be between -2147483648 and 2147483647");
        }
        $i = $i + 1;
    }
    $cal = \__mc_intlgregcal_date_cal($fn, $ints);
    __McCalAdopt::$ptr = \ptr_to_int($cal);
    return new IntlGregorianCalendar();
}

function __mc_intlcal_create(string $fn, IntlTimeZone|DateTimeZone|string|null $timezone, ?string $locale): ?IntlCalendar
{
    \__mc_intl_reset();
    $zone = \__mc_intlcal_zone($fn, $timezone, null);
    if ($zone === null) { return null; }
    $cal = \__mc_intlcal_open($zone, $locale ?? \__mc_intl_default_locale(), 0);
    if ($cal === null) {
        \__mc_intl_fail($fn, "Error creating ICU Calendar object", __McIcuStatus::$code);
        return null;
    }
    return \__mc_intlcal_wrap($cal);
}

function __mc_intlcal_from_date_time(string $fn, DateTime|string $datetime, ?string $locale): ?IntlCalendar
{
    \__mc_intl_reset();
    $dt = \is_string($datetime) ? new DateTime($datetime) : $datetime;
    $z = \__mc_intltz_from_dtz($fn, $dt->getTimezone());
    if ($z === null) { return null; }
    $cal = \__mc_intlcal_open($z->__mcId(), $locale ?? \__mc_intl_default_locale(), 0);
    if ($cal === null) {
        \__mc_intl_fail($fn, "error creating ICU Calendar object", __McIcuStatus::$code);
        return null;
    }
    $e = \__mc_icu_err();
    \__mc_icu_ucal_setMillis($cal, $dt->getTimestamp() * 1000.0, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($c > 0) {
        \__mc_icu_ucal_close($cal);
        \__mc_intl_fail($fn, "error creating ICU Calendar::setTime()", $c);
        return null;
    }
    return \__mc_intlcal_wrap($cal);
}

function __mc_intlcal_keyword_values(string $fn, string $keyword, string $locale, bool $common): IntlIterator|false
{
    \__mc_intl_reset();
    $e = \__mc_icu_err();
    $en = \__mc_icu_ucal_getKeywordValuesForLocale($keyword, $locale, $common ? 1 : 0, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if (\ptr_to_int($en) === 0) {
        \__mc_intl_fail($fn, "error calling underlying method", $c);
        return false;
    }
    return IntlIterator::__mcOf(\__mc_icu_enum_strings($en));
}

function intlcal_create_instance(IntlTimeZone|DateTimeZone|string|null $timezone = null, ?string $locale = null): ?IntlCalendar
{
    return \__mc_intlcal_create("intlcal_create_instance", $timezone, $locale);
}

function intlcal_get_keyword_values_for_locale(string $keyword, string $locale, bool $onlyCommon): IntlIterator|false
{
    return \__mc_intlcal_keyword_values("intlcal_get_keyword_values_for_locale", $keyword, $locale, $onlyCommon);
}

function intlcal_get_now(): float
{
    return IntlCalendar::getNow();
}

/** @return string[] */
function intlcal_get_available_locales(): array
{
    return IntlCalendar::getAvailableLocales();
}

function intlcal_get(IntlCalendar $calendar, int $field): int|false
{
    return $calendar->__mcFieldInt("intlcal_get", 1, $field, -1);
}

function intlcal_get_time(IntlCalendar $calendar): float|false
{
    return $calendar->__mcGetTime("intlcal_get_time");
}

function intlcal_set_time(IntlCalendar $calendar, float $timestamp): bool
{
    return $calendar->__mcSetTime("intlcal_set_time", $timestamp);
}

function intlcal_add(IntlCalendar $calendar, int $field, int $value): bool
{
    return $calendar->__mcAddRoll("intlcal_add", 1, $field, $value, false);
}

function intlcal_set_time_zone(IntlCalendar $calendar, IntlTimeZone|DateTimeZone|string|null $timezone): bool
{
    return $calendar->__mcSetTimeZone("intlcal_set_time_zone", $timezone);
}

function intlcal_after(IntlCalendar $calendar, IntlCalendar $other): bool
{
    return $calendar->__mcCompare("intlcal_after", $other) > 0;
}

function intlcal_before(IntlCalendar $calendar, IntlCalendar $other): bool
{
    return $calendar->__mcCompare("intlcal_before", $other) < 0;
}

function intlcal_set(IntlCalendar $calendar, int $year, int $month, int $dayOfMonth = -2147483649, int $hour = -2147483649, int $minute = -2147483649, int $second = -2147483649): true
{
    return $calendar->__mcSet("intlcal_set", 1, [$year, $month, $dayOfMonth, $hour, $minute, $second]);
}

function intlcal_roll(IntlCalendar $calendar, int $field, $value): bool
{
    return $calendar->__mcRoll("intlcal_roll", 1, $field, $value);
}

function intlcal_clear(IntlCalendar $calendar, ?int $field = null): true
{
    return $calendar->__mcClear("intlcal_clear", 1, $field);
}

function intlcal_field_difference(IntlCalendar $calendar, float $timestamp, int $field): int|false
{
    return $calendar->__mcFieldDifference("intlcal_field_difference", 1, $timestamp, $field);
}

function intlcal_get_actual_maximum(IntlCalendar $calendar, int $field): int|false
{
    return $calendar->__mcFieldInt("intlcal_get_actual_maximum", 1, $field, 5);
}

function intlcal_get_actual_minimum(IntlCalendar $calendar, int $field): int|false
{
    return $calendar->__mcFieldInt("intlcal_get_actual_minimum", 1, $field, 4);
}

function intlcal_get_day_of_week_type(IntlCalendar $calendar, int $dayOfWeek): int|false
{
    return $calendar->__mcDow("intlcal_get_day_of_week_type", 1, $dayOfWeek, false);
}

function intlcal_get_first_day_of_week(IntlCalendar $calendar): int|false
{
    return $calendar->getFirstDayOfWeek();
}

function intlcal_get_least_maximum(IntlCalendar $calendar, int $field): int|false
{
    return $calendar->__mcFieldInt("intlcal_get_least_maximum", 1, $field, 3);
}

function intlcal_get_greatest_minimum(IntlCalendar $calendar, int $field): int|false
{
    return $calendar->__mcFieldInt("intlcal_get_greatest_minimum", 1, $field, 2);
}

function intlcal_get_locale(IntlCalendar $calendar, int $type): string|false
{
    return $calendar->__mcGetLocale("intlcal_get_locale", 1, $type);
}

function intlcal_get_maximum(IntlCalendar $calendar, int $field): int|false
{
    return $calendar->__mcFieldInt("intlcal_get_maximum", 1, $field, 1);
}

function intlcal_get_minimal_days_in_first_week(IntlCalendar $calendar): int|false
{
    return $calendar->getMinimalDaysInFirstWeek();
}

function intlcal_set_minimal_days_in_first_week(IntlCalendar $calendar, int $days): true
{
    return $calendar->__mcSetMinimalDays("intlcal_set_minimal_days_in_first_week", 1, $days);
}

function intlcal_get_minimum(IntlCalendar $calendar, int $field): int|false
{
    return $calendar->__mcFieldInt("intlcal_get_minimum", 1, $field, 0);
}

function intlcal_get_time_zone(IntlCalendar $calendar): IntlTimeZone|false
{
    return $calendar->getTimeZone();
}

function intlcal_get_type(IntlCalendar $calendar): string
{
    return $calendar->getType();
}

function intlcal_get_weekend_transition(IntlCalendar $calendar, int $dayOfWeek): int|false
{
    return $calendar->__mcDow("intlcal_get_weekend_transition", 1, $dayOfWeek, true);
}

function intlcal_in_daylight_time(IntlCalendar $calendar): bool
{
    return $calendar->__mcInDaylight("intlcal_in_daylight_time");
}

function intlcal_is_lenient(IntlCalendar $calendar): bool
{
    return $calendar->isLenient();
}

function intlcal_is_set(IntlCalendar $calendar, int $field): bool
{
    return $calendar->__mcIsSet("intlcal_is_set", 1, $field);
}

function intlcal_is_equivalent_to(IntlCalendar $calendar, IntlCalendar $other): bool
{
    return $calendar->isEquivalentTo($other);
}

function intlcal_is_weekend(IntlCalendar $calendar, ?float $timestamp = null): bool
{
    return $calendar->__mcIsWeekend("intlcal_is_weekend", $timestamp);
}

function intlcal_set_first_day_of_week(IntlCalendar $calendar, int $dayOfWeek): true
{
    return $calendar->__mcSetFirstDay("intlcal_set_first_day_of_week", 1, $dayOfWeek);
}

function intlcal_set_lenient(IntlCalendar $calendar, bool $lenient): true
{
    return $calendar->setLenient($lenient);
}

function intlcal_get_repeated_wall_time_option(IntlCalendar $calendar): int
{
    return $calendar->getRepeatedWallTimeOption();
}

function intlcal_equals(IntlCalendar $calendar, IntlCalendar $other): bool
{
    return $calendar->__mcEquals("intlcal_equals", $other);
}

function intlcal_get_skipped_wall_time_option(IntlCalendar $calendar): int
{
    return $calendar->getSkippedWallTimeOption();
}

function intlcal_set_repeated_wall_time_option(IntlCalendar $calendar, int $option): true
{
    return $calendar->__mcSetWallTime("intlcal_set_repeated_wall_time_option", 1, $option, false);
}

function intlcal_set_skipped_wall_time_option(IntlCalendar $calendar, int $option): true
{
    return $calendar->__mcSetWallTime("intlcal_set_skipped_wall_time_option", 1, $option, true);
}

function intlcal_from_date_time(DateTime|string $datetime, ?string $locale = null): ?IntlCalendar
{
    return \__mc_intlcal_from_date_time("intlcal_from_date_time", $datetime, $locale);
}

function intlcal_to_date_time(IntlCalendar $calendar): DateTime|false
{
    return $calendar->__mcToDateTime("intlcal_to_date_time");
}

function intlcal_get_error_code(IntlCalendar $calendar): int|false
{
    return $calendar->getErrorCode();
}

function intlcal_get_error_message(IntlCalendar $calendar): string|false
{
    return $calendar->getErrorMessage();
}

function intlgregcal_create_instance($timezoneOrYear = null, $localeOrMonth = null, $day = null, $hour = null, $minute = null, $second = null): ?IntlGregorianCalendar
{
    \__mc_intl_reset();
    $cal = \__mc_intlgregcal_open("intlgregcal_create_instance",
        [$timezoneOrYear, $localeOrMonth, $day, $hour, $minute, $second], \func_num_args());
    if ($cal === null) { return null; }
    __McCalAdopt::$ptr = \ptr_to_int($cal);
    return new IntlGregorianCalendar();
}

function intlgregcal_set_gregorian_change(IntlGregorianCalendar $calendar, float $timestamp): bool
{
    return $calendar->__mcSetGregorianChange("intlgregcal_set_gregorian_change", $timestamp);
}

function intlgregcal_get_gregorian_change(IntlGregorianCalendar $calendar): float
{
    return $calendar->getGregorianChange();
}

function intlgregcal_is_leap_year(IntlGregorianCalendar $calendar, int $year): bool
{
    return $calendar->__mcIsLeapYear("intlgregcal_is_leap_year", 1, $year);
}
