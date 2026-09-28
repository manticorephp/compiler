<?php

// ext/intl IntlTimeZone (php-src ext/intl/timezone/*.cpp) over ICU's C API. Zend holds an
// icu::TimeZone; C has no zone object, so an IntlTimeZone holds its ID and a UCalendar opened
// on it (ucal_* answers offsets, names, transitions). What only C++ exposes — equivalent IDs,
// the region, hasSameRules, OlsonTimeZone::useDaylightTime / getDSTSavings — is read from
// the same zoneinfo64 resource ICU builds its OlsonTimeZone from (ures_*).

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_open')]
function __mc_icu_ucal_open(\Ffi\Ptr $zone, #[\Ffi\CType('int')] int $len, string $locale,
    #[\Ffi\CType('int')] int $type, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_close')]
function __mc_icu_ucal_close(\Ffi\Ptr $cal): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getTimeZoneID'), \Ffi\CType('int')]
function __mc_icu_ucal_getTimeZoneID(\Ffi\Ptr $cal, \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap,
    \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_setMillis')]
function __mc_icu_ucal_setMillis(\Ffi\Ptr $cal, #[\Ffi\CType('double')] float $ms, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_get'), \Ffi\CType('int')]
function __mc_icu_ucal_get(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $field, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getNow'), \Ffi\CType('double')]
function __mc_icu_ucal_getNow(): float { return 0.0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getTimeZoneOffsetFromLocal')]
function __mc_icu_ucal_getTimeZoneOffsetFromLocal(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $nonExisting,
    #[\Ffi\CType('int')] int $duplicated, \Ffi\Ptr $raw, \Ffi\Ptr $dst, \Ffi\Ptr $err): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getTimeZoneDisplayName'), \Ffi\CType('int')]
function __mc_icu_ucal_getTimeZoneDisplayName(\Ffi\Ptr $cal, #[\Ffi\CType('int')] int $type, string $locale,
    \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getCanonicalTimeZoneID'), \Ffi\CType('int')]
function __mc_icu_ucal_getCanonicalTimeZoneID(\Ffi\Ptr $id, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $isSystem, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getIanaTimeZoneID'), \Ffi\CType('int')]
function __mc_icu_ucal_getIanaTimeZoneID(\Ffi\Ptr $id, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getWindowsTimeZoneID'), \Ffi\CType('int')]
function __mc_icu_ucal_getWindowsTimeZoneID(\Ffi\Ptr $id, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getTimeZoneIDForWindowsID'), \Ffi\CType('int')]
function __mc_icu_ucal_getTimeZoneIDForWindowsID(\Ffi\Ptr $id, #[\Ffi\CType('int')] int $len, \Ffi\Ptr $region,
    \Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getTZDataVersion')]
function __mc_icu_ucal_getTZDataVersion(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_getDefaultTimeZone'), \Ffi\CType('int')]
function __mc_icu_ucal_getDefaultTimeZone(\Ffi\Ptr $out, #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_openTimeZones')]
function __mc_icu_ucal_openTimeZones(\Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_openCountryTimeZones')]
function __mc_icu_ucal_openCountryTimeZones(string $country, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucal_openTimeZoneIDEnumeration')]
function __mc_icu_ucal_openTimeZoneIDEnumeration(#[\Ffi\CType('int')] int $type, \Ffi\Ptr $region,
    \Ffi\Ptr $rawOffset, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_open')]
function __mc_icu_udat_open(#[\Ffi\CType('int')] int $timeStyle, #[\Ffi\CType('int')] int $dateStyle,
    string $locale, \Ffi\Ptr $tz, #[\Ffi\CType('int')] int $tzLen, \Ffi\Ptr $pattern,
    #[\Ffi\CType('int')] int $patLen, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_close')]
function __mc_icu_udat_close(\Ffi\Ptr $fmt): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('udat_format'), \Ffi\CType('int')]
function __mc_icu_udat_format(\Ffi\Ptr $fmt, #[\Ffi\CType('double')] float $date, \Ffi\Ptr $out,
    #[\Ffi\CType('int')] int $cap, \Ffi\Ptr $pos, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_openDirect')]
function __mc_icu_ures_openDirect(\Ffi\Ptr $package, string $table, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getByKey')]
function __mc_icu_ures_getByKey(\Ffi\Ptr $res, string $key, \Ffi\Ptr $fill, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getByIndex')]
function __mc_icu_ures_getByIndex(\Ffi\Ptr $res, #[\Ffi\CType('int')] int $i, \Ffi\Ptr $fill, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getType'), \Ffi\CType('int')]
function __mc_icu_ures_getType(\Ffi\Ptr $res): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getSize'), \Ffi\CType('int')]
function __mc_icu_ures_getSize(\Ffi\Ptr $res): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getKey')]
function __mc_icu_ures_getKey(\Ffi\Ptr $res): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getInt'), \Ffi\CType('int')]
function __mc_icu_ures_getInt(\Ffi\Ptr $res, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getIntVector')]
function __mc_icu_ures_getIntVector(\Ffi\Ptr $res, \Ffi\Ptr $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getBinary')]
function __mc_icu_ures_getBinary(\Ffi\Ptr $res, \Ffi\Ptr $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getString')]
function __mc_icu_ures_getString(\Ffi\Ptr $res, \Ffi\Ptr $len, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_getStringByIndex')]
function __mc_icu_ures_getStringByIndex(\Ffi\Ptr $res, #[\Ffi\CType('int')] int $i, \Ffi\Ptr $len,
    \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icuuc'), \Ffi\Symbol('ures_close')]
function __mc_icu_ures_close(\Ffi\Ptr $res): void {}

// ── zoneinfo64 ──────────────────────────────────────────────────────────────

/** The zoneinfo64 bundle, opened once: its Names (ID → index) and Regions. */
final class __McTzData
{
    public static int $top = 0;
    /** @var array<string, int> */
    public static array $names = [];
    /** @var string[] */
    public static array $regions = [];
}

/** A zoneinfo64 string array as UTF-8. @return string[] */
function __mc_tz_strings(\Ffi\Ptr $top, string $key): array
{
    $out = [];
    $e = \__mc_icu_err();
    $len = \__mc_icu_malloc(8);
    $arr = \__mc_icu_ures_getByKey($top, $key, \int_to_ptr(0), $e);
    if (\peek_i32($e, 0) <= 0) {
        $n = \__mc_icu_ures_getSize($arr);
        $i = 0;
        while ($i < $n) {
            $p = \__mc_icu_ures_getStringByIndex($arr, $i, $len, $e);
            $out[] = \__mc_icu_to8($p, \peek_i32($len, 0));
            $i = $i + 1;
        }
        \__mc_icu_ures_close($arr);
    }
    \__mc_icu_free($len);
    \__mc_icu_free($e);
    return $out;
}

function __mc_tz_top(): \Ffi\Ptr
{
    if (__McTzData::$top === 0) {
        $e = \__mc_icu_err();
        $top = \__mc_icu_ures_openDirect(\int_to_ptr(0), "zoneinfo64", $e);
        \__mc_icu_free($e);
        __McTzData::$top = \ptr_to_int($top);
        $i = 0;
        foreach (\__mc_tz_strings($top, "Names") as $name) {
            __McTzData::$names[$name] = $i;
            $i = $i + 1;
        }
        __McTzData::$regions = \__mc_tz_strings($top, "Regions");
    }
    return \int_to_ptr(__McTzData::$top);
}

/** findInStringArray over Names: the index, or -1. */
function __mc_tz_index(string $id): int
{
    \__mc_tz_top();
    return __McTzData::$names[$id] ?? -1;
}

/** openOlsonResource: the Zones entry of Names[$idx], a link followed. The caller closes it. */
function __mc_tz_zone(int $idx): \Ffi\Ptr
{
    $top = \__mc_tz_top();
    $e = \__mc_icu_err();
    $zones = \__mc_icu_ures_getByKey($top, "Zones", \int_to_ptr(0), $e);
    $z = \__mc_icu_ures_getByIndex($zones, $idx, \int_to_ptr(0), $e);
    if (\__mc_icu_ures_getType($z) === 7) {
        $deref = \__mc_icu_ures_getInt($z, $e);
        \__mc_icu_ures_close($z);
        $z = \__mc_icu_ures_getByIndex($zones, $deref, \int_to_ptr(0), $e);
    }
    \__mc_icu_ures_close($zones);
    \__mc_icu_free($e);
    return $z;
}

/** An int vector member of a zone resource (empty when absent). @return int[] */
function __mc_tz_ints(\Ffi\Ptr $res, string $key): array
{
    $out = [];
    $e = \__mc_icu_err();
    $len = \__mc_icu_malloc(8);
    \poke_i32($len, 0, 0);
    $r = \__mc_icu_ures_getByKey($res, $key, \int_to_ptr(0), $e);
    if (\peek_i32($e, 0) <= 0) {
        $p = \__mc_icu_ures_getIntVector($r, $len, $e);
        $n = \peek_i32($len, 0);
        if (\peek_i32($e, 0) <= 0) {
            $i = 0;
            while ($i < $n) {
                $out[] = \peek_i32($p, $i * 4);
                $i = $i + 1;
            }
        }
        \__mc_icu_ures_close($r);
    }
    \__mc_icu_free($len);
    \__mc_icu_free($e);
    return $out;
}

/** An int member (or $default when absent). */
function __mc_tz_int(\Ffi\Ptr $res, string $key, int $default): int
{
    $e = \__mc_icu_err();
    $r = \__mc_icu_ures_getByKey($res, $key, \int_to_ptr(0), $e);
    $v = $default;
    if (\peek_i32($e, 0) <= 0) {
        $v = \__mc_icu_ures_getInt($r, $e);
        \__mc_icu_ures_close($r);
    }
    \__mc_icu_free($e);
    return $v;
}

/** A string member (or "" when absent). */
function __mc_tz_string(\Ffi\Ptr $res, string $key): string
{
    $e = \__mc_icu_err();
    $len = \__mc_icu_malloc(8);
    $r = \__mc_icu_ures_getByKey($res, $key, \int_to_ptr(0), $e);
    $v = "";
    if (\peek_i32($e, 0) <= 0) {
        $p = \__mc_icu_ures_getString($r, $len, $e);
        $v = \__mc_icu_to8($p, \peek_i32($len, 0));
        \__mc_icu_ures_close($r);
    }
    \__mc_icu_free($len);
    \__mc_icu_free($e);
    return $v;
}

/** The binary typeMap (one type index per transition). @return int[] */
function __mc_tz_typemap(\Ffi\Ptr $res): array
{
    $out = [];
    $e = \__mc_icu_err();
    $len = \__mc_icu_malloc(8);
    $r = \__mc_icu_ures_getByKey($res, "typeMap", \int_to_ptr(0), $e);
    if (\peek_i32($e, 0) <= 0) {
        $p = \__mc_icu_ures_getBinary($r, $len, $e);
        $n = \peek_i32($len, 0);
        $i = 0;
        while ($i < $n) {
            $out[] = \peek_u8($p, $i);
            $i = $i + 1;
        }
        \__mc_icu_ures_close($r);
    }
    \__mc_icu_free($len);
    \__mc_icu_free($e);
    return $out;
}

/** The SimpleTimeZone rule a zone's finalRule names (11 ints), or [] for none. @return int[] */
function __mc_tz_final_rule(\Ffi\Ptr $zone): array
{
    $name = \__mc_tz_string($zone, "finalRule");
    if ($name === "") { return []; }
    $top = \__mc_tz_top();
    $e = \__mc_icu_err();
    $rules = \__mc_icu_ures_getByKey($top, "Rules", \int_to_ptr(0), $e);
    \__mc_icu_free($e);
    $out = \__mc_tz_ints($rules, $name);
    \__mc_icu_ures_close($rules);
    return $out;
}

/** OlsonTimeZone's data, as hasSameRules / useDaylightTime / getDSTSavings read it. */
final class __McTzOlson
{
    /** @var int[] */
    public array $transitions = [];
    /** @var int[] */
    public array $typeOffsets = [];
    /** @var int[] */
    public array $typeMap = [];
    /** @var int[] */
    public array $finalRule = [];
    public int $finalRaw = 0;
    public int $finalYear = 0;

    public static function load(int $idx): __McTzOlson
    {
        $o = new __McTzOlson();
        $z = \__mc_tz_zone($idx);
        $pre = \__mc_tz_ints($z, "transPre32");
        $i = 0;
        while ($i + 1 < \count($pre)) {
            $o->transitions[] = ($pre[$i] << 32) | ($pre[$i + 1] & 0xFFFFFFFF);
            $i = $i + 2;
        }
        foreach (\__mc_tz_ints($z, "trans") as $t) { $o->transitions[] = $t; }
        $post = \__mc_tz_ints($z, "transPost32");
        $i = 0;
        while ($i + 1 < \count($post)) {
            $o->transitions[] = ($post[$i] << 32) | ($post[$i + 1] & 0xFFFFFFFF);
            $i = $i + 2;
        }
        $o->typeOffsets = \__mc_tz_ints($z, "typeOffsets");
        $o->typeMap = \__mc_tz_typemap($z);
        $o->finalRule = \__mc_tz_final_rule($z);
        $o->finalRaw = \__mc_tz_int($z, "finalRaw", 0);
        $o->finalYear = \__mc_tz_int($z, "finalYear", 0);
        \__mc_icu_ures_close($z);
        return $o;
    }

    public function dstOffsetAt(int $i): int
    {
        return $this->typeOffsets[$this->typeMap[$i] * 2 + 1] ?? 0;
    }

    /** Grego::fieldsToDay(year, 0, 1) — days since 1970-01-01 of Jan 1st. */
    public static function yearStartDay(int $year): int
    {
        $y = $year - 1;
        $julian = 365 * $y + \intdiv($y, 4) - \intdiv($y, 100) + \intdiv($y, 400) + 1721426 - 1;
        if ($y < 0) {
            $julian = 365 * $y + (int)\floor($y / 4) - (int)\floor($y / 100) + (int)\floor($y / 400) + 1721426 - 1;
        }
        return $julian + 1 - 2440588;
    }

    public function useDaylightTime(float $nowMs): bool
    {
        if ($this->finalRule !== [] && $nowMs >= self::yearStartDay($this->finalYear) * 86400000.0) {
            return true;
        }
        $year = (int)\gmdate("Y", (int)\floor($nowMs / 1000));
        $start = self::yearStartDay($year) * 86400;
        $limit = self::yearStartDay($year + 1) * 86400;
        $i = 0;
        $n = \count($this->transitions);
        while ($i < $n) {
            $t = $this->transitions[$i];
            if ($t >= $limit) { break; }
            if (($t >= $start && $this->dstOffsetAt($i) !== 0)
                || ($t > $start && $i > 0 && $this->dstOffsetAt($i - 1) !== 0)) {
                return true;
            }
            $i = $i + 1;
        }
        return false;
    }

    public function dstSavings(float $nowMs): int
    {
        if ($this->finalRule !== []) { return ($this->finalRule[10] ?? 0) * 1000; }
        return $this->useDaylightTime($nowMs) ? 3600000 : 0;
    }

    public function sameRules(__McTzOlson $o): bool
    {
        // OlsonTimeZone compares typeMapData POINTERS first: two zones without
        // transitions both hold nullptr, so every fixed zone "has the same rules".
        if ($this->typeMap === [] && $o->typeMap === []) { return true; }
        return $this->transitions === $o->transitions && $this->typeOffsets === $o->typeOffsets
            && $this->typeMap === $o->typeMap && $this->finalRule === $o->finalRule
            && $this->finalRaw === $o->finalRaw && ($this->finalRule === [] || $this->finalYear === $o->finalYear);
    }
}

// ── calendars on a zone ID ──────────────────────────────────────────────────

/** A Gregorian UCalendar on `$id` (ICU resolves an unknown ID to Etc/Unknown). */
function __mc_tz_cal(string $id): \Ffi\Ptr
{
    $u = \__mc_icu_to16($id);
    if ($u === null) { $u = \__mc_icu_to16("Etc/Unknown"); }
    $e = \__mc_icu_err();
    $cal = \__mc_icu_ucal_open($u->buf, $u->len, "", 1, $e);
    \__mc_icu_free($e);
    \__mc_icu_free($u->buf);
    return $cal;
}

function __mc_tz_cal_id(\Ffi\Ptr $cal): string
{
    return \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int => \__mc_icu_ucal_getTimeZoneID($cal, $b, $c, $e)) ?? "";
}

/** raw and dst offsets at `$ms` (UTC, or wall time when `$local`); null on an ICU failure. */
function __mc_tz_offsets(\Ffi\Ptr $cal, float $ms, bool $local): ?array
{
    $e = \__mc_icu_err();
    \__mc_icu_ucal_setMillis($cal, $ms, $e);
    if (\peek_i32($e, 0) > 0) {
        __McIcuStatus::$code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        return null;
    }
    if ($local) {
        $cells = \__mc_icu_malloc(8);
        \__mc_icu_ucal_getTimeZoneOffsetFromLocal($cal, 0x04, 0x0C, $cells, \ptr_offset($cells, 4), $e);
        $raw = \peek_i32($cells, 0);
        $dst = \peek_i32($cells, 4);
        \__mc_icu_free($cells);
    } else {
        $raw = \__mc_icu_ucal_get($cal, 15, $e);
        $dst = \__mc_icu_ucal_get($cal, 16, $e);
    }
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($c > 0) {
        __McIcuStatus::$code = $c;
        return null;
    }
    return [$raw, $dst];
}

/** One udat pattern formatted for `$zoneId` at `$ms`. */
function __mc_tz_udat(string $zoneId, string $pattern, string $locale, float $ms): string
{
    $z = \__mc_icu_to16($zoneId);
    $p = \__mc_icu_to16($pattern);
    $e = \__mc_icu_err();
    $fmt = \__mc_icu_udat_open(-2, -2, $locale, $z->buf, $z->len, $p->buf, $p->len, $e);
    \__mc_icu_free($e);
    \__mc_icu_free($z->buf);
    \__mc_icu_free($p->buf);
    $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $err): int
        => \__mc_icu_udat_format($fmt, $ms, $b, $c, \int_to_ptr(0), $err)) ?? "";
    \__mc_icu_udat_close($fmt);
    return $out;
}

/** A custom zone ID for a fixed offset — what TimeZoneFormat formats as that offset. */
function __mc_tz_offset_id(int $ms): string
{
    $sign = $ms < 0 ? "-" : "+";
    $s = \intdiv(\abs($ms), 1000);
    $id = "GMT" . $sign . \sprintf("%02d:%02d", \intdiv($s, 3600), \intdiv($s % 3600, 60));
    return $s % 60 !== 0 ? $id . \sprintf(":%02d", $s % 60) : $id;
}

class IntlTimeZone
{
    public const DISPLAY_SHORT = 1;
    public const DISPLAY_LONG = 2;
    public const DISPLAY_SHORT_GENERIC = 3;
    public const DISPLAY_LONG_GENERIC = 4;
    public const DISPLAY_SHORT_GMT = 5;
    public const DISPLAY_LONG_GMT = 6;
    public const DISPLAY_SHORT_COMMONLY_USED = 7;
    public const DISPLAY_GENERIC_LOCATION = 8;
    public const TYPE_ANY = 0;
    public const TYPE_CANONICAL = 1;
    public const TYPE_CANONICAL_LOCATION = 2;

    private string $__mcId = "";
    /** An OlsonTimeZone (a zoneinfo64 system zone); otherwise a SimpleTimeZone. */
    private bool $__mcOlson = false;
    private int $__mcCal = 0;
    private int $__mcErrCode = 0;
    private string $__mcErrMessage = "";

    private function __construct() {}

    /** TimeZone::createTimeZone: a system zone, else a custom GMT offset, else Etc/Unknown. */
    public static function __mcOf(string $id): IntlTimeZone
    {
        $tz = new IntlTimeZone();
        $cal = \__mc_tz_cal($id);
        $tz->__mcCal = \ptr_to_int($cal);
        $tz->__mcId = \__mc_tz_cal_id($cal);
        // createSystemTimeZone looks the REQUESTED id up; "GMT+00:00" is a custom zone named GMT.
        $tz->__mcOlson = $id !== "Etc/Unknown" && \__mc_tz_index($id) >= 0;
        return $tz;
    }

    /** TimeZone::getGMT(): a SimpleTimeZone named GMT. */
    public static function __mcSimple(string $id): IntlTimeZone
    {
        $tz = self::__mcOf($id);
        $tz->__mcOlson = false;
        return $tz;
    }

    public function __mcId(): string
    {
        return $this->__mcId;
    }

    public function __mcCalendar(): \Ffi\Ptr
    {
        return \int_to_ptr($this->__mcCal);
    }

    public function __clone()
    {
        $this->__mcCal = \ptr_to_int(\__mc_tz_cal($this->__mcId));
    }

    public function __destruct()
    {
        if ($this->__mcCal !== 0) {
            \__mc_icu_ucal_close(\int_to_ptr($this->__mcCal));
            $this->__mcCal = 0;
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        $o = \__mc_tz_offsets($this->__mcCalendar(), \__mc_icu_ucal_getNow(), false);
        if ($o === null) { return ["valid" => true, "id" => $this->__mcId]; }
        return ["valid" => true, "id" => $this->__mcId, "rawOffset" => $o[0], "currentOffset" => $o[0] + $o[1]];
    }

    private function __mcFail(string $fn, string $what, int $code): void
    {
        $this->__mcErrCode = $code;
        $this->__mcErrMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    private function __mcReset(): void
    {
        \__mc_intl_reset();
        $this->__mcErrCode = 0;
        $this->__mcErrMessage = "";
    }

    public function __mcOlsonData(): ?__McTzOlson
    {
        return $this->__mcOlson ? __McTzOlson::load(\__mc_tz_index($this->__mcId)) : null;
    }

    public static function countEquivalentIDs(string $timezoneId): int|false
    {
        return \__mc_intltz_count_equivalent_ids("IntlTimeZone::countEquivalentIDs", $timezoneId);
    }

    public static function createDefault(): IntlTimeZone
    {
        \__mc_intl_reset();
        return self::__mcOf(\__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
            => \__mc_icu_ucal_getDefaultTimeZone($b, $c, $e)) ?? "Etc/Unknown");
    }

    public static function createEnumeration(string|int|null $countryOrRawOffset = null): IntlIterator|false
    {
        return \__mc_intltz_create_enumeration("IntlTimeZone::createEnumeration", $countryOrRawOffset);
    }

    public static function createTimeZone(string $timezoneId): ?IntlTimeZone
    {
        return \__mc_intltz_create_time_zone("IntlTimeZone::createTimeZone", $timezoneId);
    }

    public static function createTimeZoneIDEnumeration(int $type, ?string $region = null, ?int $rawOffset = null): IntlIterator|false
    {
        return \__mc_intltz_create_id_enumeration("IntlTimeZone::createTimeZoneIDEnumeration", $type, $region, $rawOffset);
    }

    public static function fromDateTimeZone(DateTimeZone $timezone): ?IntlTimeZone
    {
        \__mc_intl_reset();
        return \__mc_intltz_from_dtz("IntlTimeZone::fromDateTimeZone", $timezone);
    }

    public static function getCanonicalID(string $timezoneId, &$isSystemId = null): string|false
    {
        return \__mc_intltz_get_canonical_id("IntlTimeZone::getCanonicalID", $timezoneId, $isSystemId);
    }

    public function getDisplayName(bool $dst = false, int $style = IntlTimeZone::DISPLAY_LONG, ?string $locale = null): string|false
    {
        return $this->__mcDisplayName("IntlTimeZone::getDisplayName", $dst, $style, $locale);
    }

    public function __mcDisplayName(string $fn, bool $dst, int $style, ?string $locale): string|false
    {
        $this->__mcReset();
        if ($style < 1 || $style > 8) {
            $this->__mcFail($fn, "wrong display type", 1);
            return false;
        }
        $loc = $locale ?? \__mc_intl_default_locale();
        $cal = $this->__mcCalendar();
        $now = \__mc_icu_ucal_getNow();
        if ($style === 1 || $style === 2 || $style === 7) {
            $type = $style === 2 ? ($dst ? 2 : 0) : ($dst ? 3 : 1);
            return \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
                => \__mc_icu_ucal_getTimeZoneDisplayName($cal, $type, $loc, $b, $c, $e)) ?? "";
        }
        $o = \__mc_tz_offsets($cal, $now, false) ?? [0, 0];
        if ($style === 5 || $style === 6) {
            $offset = $dst && $this->__mcUseDaylight($now) ? $o[0] + $this->__mcDstSavings($now) : $o[0];
            return \__mc_tz_udat(\__mc_tz_offset_id($offset), $style === 6 ? "OOOO" : "xxxx", $loc, 0.0);
        }
        $pattern = $style === 8 ? "VVVV" : ($style === 4 ? "vvvv" : "v");
        $gmt = $style === 3 ? "O" : "OOOO";
        $name = \__mc_tz_udat($this->__mcId, $pattern, $loc, $now);
        // TimeZoneFormat fell back to the localized GMT of the current offset; that
        // offset's DST state disagreeing with the request re-renders it.
        if ($name === \__mc_tz_udat($this->__mcId, $gmt, $loc, $now) && $dst !== ($o[1] !== 0)) {
            $offset = $dst ? $o[0] + $this->__mcDstSavings($now) : $o[0];
            $name = \__mc_tz_udat(\__mc_tz_offset_id($offset), $gmt, $loc, 0.0);
        }
        return $name;
    }

    public function __mcUseDaylight(float $now): bool
    {
        $d = $this->__mcOlsonData();
        return $d !== null && $d->useDaylightTime($now);
    }

    public function __mcDstSavings(float $now): int
    {
        $d = $this->__mcOlsonData();
        return $d === null ? 3600000 : $d->dstSavings($now);
    }

    public function getDSTSavings(): int
    {
        $this->__mcReset();
        return $this->__mcDstSavings(\__mc_icu_ucal_getNow());
    }

    public static function getEquivalentID(string $timezoneId, int $offset): string|false
    {
        return \__mc_intltz_get_equivalent_id("IntlTimeZone::getEquivalentID", $timezoneId, $offset);
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

    public static function getGMT(): IntlTimeZone
    {
        \__mc_intl_reset();
        return self::__mcSimple("GMT");
    }

    public static function getIanaID(string $timezoneId): string|false
    {
        return \__mc_intltz_get_iana_id("IntlTimeZone::getIanaID", $timezoneId);
    }

    public function getID(): string|false
    {
        $this->__mcReset();
        return $this->__mcId;
    }

    public function getOffset(float $timestamp, bool $local, &$rawOffset, &$dstOffset): bool
    {
        return $this->__mcGetOffset("IntlTimeZone::getOffset", $timestamp, $local, $rawOffset, $dstOffset);
    }

    public function __mcGetOffset(string $fn, float $timestamp, bool $local, mixed &$rawOffset, mixed &$dstOffset): bool
    {
        $this->__mcReset();
        $o = \__mc_tz_offsets($this->__mcCalendar(), $timestamp, $local);
        if ($o === null) {
            $this->__mcFail($fn, "error obtaining offset", __McIcuStatus::$code);
            return false;
        }
        $rawOffset = $o[0];
        $dstOffset = $o[1];
        return true;
    }

    public function getRawOffset(): int
    {
        $this->__mcReset();
        $o = \__mc_tz_offsets($this->__mcCalendar(), \__mc_icu_ucal_getNow(), false);
        return $o === null ? 0 : $o[0];
    }

    public static function getRegion(string $timezoneId): string|false
    {
        return \__mc_intltz_get_region("IntlTimeZone::getRegion", $timezoneId);
    }

    public static function getTZDataVersion(): string|false
    {
        return \__mc_intltz_get_tz_data_version("IntlTimeZone::getTZDataVersion");
    }

    public static function getUnknown(): IntlTimeZone
    {
        \__mc_intl_reset();
        return self::__mcSimple("Etc/Unknown");
    }

    public static function getWindowsID(string $timezoneId): string|false
    {
        return \__mc_intltz_get_windows_id("IntlTimeZone::getWindowsID", $timezoneId);
    }

    public static function getIDForWindowsID(string $timezoneId, ?string $region = null): string|false
    {
        return \__mc_intltz_get_id_for_windows_id("IntlTimeZone::getIDForWindowsID", $timezoneId, $region);
    }

    public function hasSameRules(IntlTimeZone $other): bool
    {
        $this->__mcReset();
        if ($this === $other) { return true; }
        $a = $this->__mcOlsonData();
        $b = $other->__mcOlsonData();
        if ($a === null || $b === null) {
            if ($a !== null || $b !== null) { return false; }
            return $this->getRawOffset() === $other->getRawOffset();
        }
        return \__mc_tz_index($this->__mcId) === \__mc_tz_index($other->__mcId)
            || \__mc_tz_zone_index($this->__mcId) === \__mc_tz_zone_index($other->__mcId)
            || $a->sameRules($b);
    }

    public function toDateTimeZone(): DateTimeZone|false
    {
        return $this->__mcToDateTimeZone("IntlTimeZone::toDateTimeZone");
    }

    public function __mcToDateTimeZone(string $fn): DateTimeZone|false
    {
        $this->__mcReset();
        if (\str_starts_with($this->__mcId, "GMT")) {
            $s = \intdiv($this->getRawOffset(), 1000);
            $a = \abs($s);
            return new DateTimeZone(($s < 0 ? "-" : "+") . \sprintf("%02d:%02d", \intdiv($a, 3600), \intdiv($a % 3600, 60)));
        }
        try {
            return new DateTimeZone($this->__mcId);
        } catch (\Exception $e) {
            throw new IntlException("DateTimeZone constructor threw exception", 0, $e);
        }
    }

    public function useDaylightTime(): bool
    {
        $this->__mcReset();
        return $this->__mcUseDaylight(\__mc_icu_ucal_getNow());
    }
}

/** The canonical zone a Names entry resolves to (a link followed), or -1. */
function __mc_tz_zone_index(string $id): int
{
    $idx = \__mc_tz_index($id);
    if ($idx < 0) { return -1; }
    $top = \__mc_tz_top();
    $e = \__mc_icu_err();
    $zones = \__mc_icu_ures_getByKey($top, "Zones", \int_to_ptr(0), $e);
    $z = \__mc_icu_ures_getByIndex($zones, $idx, \int_to_ptr(0), $e);
    if (\__mc_icu_ures_getType($z) === 7) { $idx = \__mc_icu_ures_getInt($z, $e); }
    \__mc_icu_ures_close($z);
    \__mc_icu_ures_close($zones);
    \__mc_icu_free($e);
    return $idx;
}

/** A malloc'd NUL-terminated copy of `$s`; the caller frees it. */
function __mc_icu_cstr(string $s): \Ffi\Ptr
{
    $n = \strlen($s);
    $p = \__mc_icu_malloc($n + 1);
    $i = 0;
    while ($i < $n) {
        \poke_i8($p, $i, \ord($s[$i]));
        $i = $i + 1;
    }
    \poke_i8($p, $n, 0);
    return $p;
}

/** UTF-16 of `$s`, or null with the "could not convert" error set. */
function __mc_intltz_u16(string $fn, string $s): ?__McIcuU16
{
    $u = \__mc_icu_to16($s);
    if ($u === null) {
        \__mc_intl_fail($fn, "could not convert time zone id to UTF-16", 10);
    }
    return $u;
}

function __mc_intltz_create_time_zone(string $fn, string $id): ?IntlTimeZone
{
    \__mc_intl_reset();
    if (\__mc_icu_to16($id) === null) {
        \__mc_intl_fail($fn, "could not convert time zone id to UTF-16", 10);
        return null;
    }
    return IntlTimeZone::__mcOf($id);
}

function __mc_intltz_from_dtz(string $fn, DateTimeZone $tz): ?IntlTimeZone
{
    if ($tz->__mcType() === 1) {
        $mins = \intdiv($tz->__mcOffset(), 60);
        $hours = \intdiv($mins, 60);
        $m = \abs($mins - $hours * 60);
        if ($mins <= -24 * 60 || $mins >= 24 * 60) {
            \__mc_intl_fail($fn, "object has an time zone offset that's too large", 1);
            return null;
        }
        $id = "GMT" . \sprintf("%+03d:%02d", $hours, $m);
    } else {
        $id = $tz->getName();
    }
    $out = IntlTimeZone::__mcOf($id);
    if ($out->__mcId() === "Etc/Unknown") {
        \__mc_intl_fail($fn, "time zone id '" . $id . "' extracted from ext/date DateTimeZone not recognized", 1);
        return null;
    }
    return $out;
}

function __mc_intltz_create_enumeration(string $fn, string|int|null $arg): IntlIterator|false
{
    \__mc_intl_reset();
    $e = \__mc_icu_err();
    if ($arg === null) {
        $en = \__mc_icu_ucal_openTimeZones($e);
    } elseif (\is_string($arg)) {
        $en = \__mc_icu_ucal_openCountryTimeZones($arg, $e);
    } else {
        if ($arg < -2147483648 || $arg > 2147483647) {
            \__mc_icu_free($e);
            throw new \ValueError($fn . "(): Argument #1 (\$countryOrRawOffset) must be between -2147483648 and 2147483647");
        }
        $off = \__mc_icu_malloc(8);
        \poke_i32($off, 0, $arg);
        $en = \__mc_icu_ucal_openTimeZoneIDEnumeration(0, \int_to_ptr(0), $off, $e);
        \__mc_icu_free($off);
    }
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($c > 0 || \ptr_to_int($en) === 0) {
        \__mc_intl_fail($fn, "error obtaining enumeration", 1);
        return false;
    }
    return IntlIterator::__mcOf(\__mc_icu_enum_strings($en));
}

function __mc_intltz_create_id_enumeration(string $fn, int $type, ?string $region, ?int $rawOffset): IntlIterator|false
{
    \__mc_intl_reset();
    if ($type !== 0 && $type !== 1 && $type !== 2) {
        throw new \ValueError($fn . "(): Argument #1 (\$type) must be one of IntlTimeZone::TYPE_ANY,"
            . " IntlTimeZone::TYPE_CANONICAL, or IntlTimeZone::TYPE_CANONICAL_LOCATION");
    }
    $off = \int_to_ptr(0);
    if ($rawOffset !== null) {
        if ($rawOffset < -2147483648 || $rawOffset > 2147483647) {
            throw new \ValueError($fn . "(): Argument #3 (\$rawOffset) must be between -2147483648 and 2147483647");
        }
        $off = \__mc_icu_malloc(8);
        \poke_i32($off, 0, $rawOffset);
    }
    $reg = \int_to_ptr(0);
    if ($region !== null) {
        $reg = \__mc_icu_cstr($region);
    }
    $e = \__mc_icu_err();
    $en = \__mc_icu_ucal_openTimeZoneIDEnumeration($type, $reg, $off, $e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($region !== null) { \__mc_icu_free($reg); }
    if ($rawOffset !== null) { \__mc_icu_free($off); }
    if ($c > 0) {
        \__mc_intl_fail($fn, "error obtaining time zone id enumeration", $c);
        return false;
    }
    return IntlIterator::__mcOf(\__mc_icu_enum_strings($en));
}

function __mc_intltz_count_equivalent_ids(string $fn, string $id): int|false
{
    \__mc_intl_reset();
    if (\__mc_intltz_u16($fn, $id) === null) { return false; }
    $idx = \__mc_tz_index($id);
    if ($idx < 0) { return 0; }
    $z = \__mc_tz_zone($idx);
    $n = \count(\__mc_tz_ints($z, "links"));
    \__mc_icu_ures_close($z);
    return $n;
}

function __mc_intltz_get_equivalent_id(string $fn, string $id, int $index): string|false
{
    \__mc_intl_reset();
    if ($index < -2147483648 || $index > 2147483647) {
        throw new \ValueError($fn . "(): Argument #2 (\$offset) must be between -2147483648 and 2147483647");
    }
    if (\__mc_intltz_u16($fn, $id) === null) { return false; }
    $idx = \__mc_tz_index($id);
    if ($idx < 0) { return ""; }
    $z = \__mc_tz_zone($idx);
    $links = \__mc_tz_ints($z, "links");
    \__mc_icu_ures_close($z);
    if ($index < 0 || $index >= \count($links)) { return ""; }
    foreach (__McTzData::$names as $name => $i) {
        if ($i === $links[$index]) { return $name; }
    }
    return "";
}

function __mc_intltz_get_canonical_id(string $fn, string $id, mixed &$isSystemId): string|false
{
    \__mc_intl_reset();
    $u = \__mc_intltz_u16($fn, $id);
    if ($u === null) { return false; }
    $sys = \__mc_icu_malloc(8);
    \poke_i32($sys, 0, 0);
    __McIcuStatus::$code = 0;
    $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
        => \__mc_icu_ucal_getCanonicalTimeZoneID($u->buf, $u->len, $b, $c, $sys, $e));
    $isSys = \peek_u8($sys, 0) !== 0;
    \__mc_icu_free($sys);
    \__mc_icu_free($u->buf);
    if ($out === null) {
        \__mc_intl_fail($fn, "error obtaining canonical ID", __McIcuStatus::$code);
        return false;
    }
    $isSystemId = $isSys;
    return $out;
}

function __mc_intltz_get_iana_id(string $fn, string $id): string|false
{
    \__mc_intl_reset();
    $u = \__mc_intltz_u16($fn, $id);
    if ($u === null) { return false; }
    __McIcuStatus::$code = 0;
    $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
        => \__mc_icu_ucal_getIanaTimeZoneID($u->buf, $u->len, $b, $c, $e));
    \__mc_icu_free($u->buf);
    if ($out === null) {
        \__mc_intl_fail($fn, "error obtaining IANA ID", __McIcuStatus::$code);
        return false;
    }
    return $out;
}

function __mc_intltz_get_region(string $fn, string $id): string|false
{
    \__mc_intl_reset();
    if (\__mc_intltz_u16($fn, $id) === null) { return false; }
    $idx = $id === "Etc/Unknown" ? -1 : \__mc_tz_index($id);
    if ($idx < 0) {
        \__mc_intl_fail($fn, "error obtaining region", 1);
        return false;
    }
    return __McTzData::$regions[$idx] ?? "";
}

function __mc_intltz_get_tz_data_version(string $fn): string|false
{
    \__mc_intl_reset();
    $e = \__mc_icu_err();
    $p = \__mc_icu_ucal_getTZDataVersion($e);
    $c = \peek_i32($e, 0);
    \__mc_icu_free($e);
    if ($c > 0) {
        \__mc_intl_fail($fn, "error obtaining time zone data version", $c);
        return false;
    }
    return \cstr_to_str($p);
}

function __mc_intltz_get_windows_id(string $fn, string $id): string|false
{
    \__mc_intl_reset();
    $u = \__mc_intltz_u16($fn, $id);
    if ($u === null) { return false; }
    __McIcuStatus::$code = 0;
    $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
        => \__mc_icu_ucal_getWindowsTimeZoneID($u->buf, $u->len, $b, $c, $e));
    \__mc_icu_free($u->buf);
    if ($out === null) {
        \__mc_intl_fail($fn, "Unable to get timezone from windows ID", __McIcuStatus::$code);
        return false;
    }
    if ($out === "") {
        \__mc_intl_fail($fn, "unknown system timezone", 1);
        return false;
    }
    return $out;
}

function __mc_intltz_get_id_for_windows_id(string $fn, string $winId, ?string $region): string|false
{
    \__mc_intl_reset();
    $u = \__mc_intltz_u16($fn, $winId);
    if ($u === null) { return false; }
    $reg = \int_to_ptr(0);
    if ($region !== null) {
        $reg = \__mc_icu_cstr($region);
    }
    __McIcuStatus::$code = 0;
    $out = \__mc_icu_uchars(fn(\Ffi\Ptr $b, int $c, \Ffi\Ptr $e): int
        => \__mc_icu_ucal_getTimeZoneIDForWindowsID($u->buf, $u->len, $reg, $b, $c, $e));
    \__mc_icu_free($u->buf);
    if ($region !== null) { \__mc_icu_free($reg); }
    if ($out === null) {
        \__mc_intl_fail($fn, "unable to get windows ID for timezone", __McIcuStatus::$code);
        return false;
    }
    if ($out === "") {
        \__mc_intl_fail($fn, "unknown windows timezone", 1);
        return false;
    }
    return $out;
}

function intltz_count_equivalent_ids(string $timezoneId): int|false
{
    return \__mc_intltz_count_equivalent_ids("intltz_count_equivalent_ids", $timezoneId);
}

function intltz_create_default(): IntlTimeZone
{
    return IntlTimeZone::createDefault();
}

function intltz_create_enumeration(string|int|null $countryOrRawOffset = null): IntlIterator|false
{
    return \__mc_intltz_create_enumeration("intltz_create_enumeration", $countryOrRawOffset);
}

function intltz_create_time_zone(string $timezoneId): ?IntlTimeZone
{
    return \__mc_intltz_create_time_zone("intltz_create_time_zone", $timezoneId);
}

function intltz_create_time_zone_id_enumeration(int $type, ?string $region = null, ?int $rawOffset = null): IntlIterator|false
{
    return \__mc_intltz_create_id_enumeration("intltz_create_time_zone_id_enumeration", $type, $region, $rawOffset);
}

function intltz_from_date_time_zone(DateTimeZone $timezone): ?IntlTimeZone
{
    \__mc_intl_reset();
    return \__mc_intltz_from_dtz("intltz_from_date_time_zone", $timezone);
}

function intltz_get_canonical_id(string $timezoneId, &$isSystemId = null): string|false
{
    return \__mc_intltz_get_canonical_id("intltz_get_canonical_id", $timezoneId, $isSystemId);
}

function intltz_get_display_name(IntlTimeZone $timezone, bool $dst = false, int $style = IntlTimeZone::DISPLAY_LONG, ?string $locale = null): string|false
{
    return $timezone->__mcDisplayName("intltz_get_display_name", $dst, $style, $locale);
}

function intltz_get_dst_savings(IntlTimeZone $timezone): int
{
    return $timezone->getDSTSavings();
}

function intltz_get_equivalent_id(string $timezoneId, int $offset): string|false
{
    return \__mc_intltz_get_equivalent_id("intltz_get_equivalent_id", $timezoneId, $offset);
}

function intltz_get_error_code(IntlTimeZone $timezone): int|false
{
    return $timezone->getErrorCode();
}

function intltz_get_error_message(IntlTimeZone $timezone): string|false
{
    return $timezone->getErrorMessage();
}

function intltz_get_gmt(): IntlTimeZone
{
    return IntlTimeZone::getGMT();
}

function intltz_get_iana_id(string $timezoneId): string|false
{
    return \__mc_intltz_get_iana_id("intltz_get_iana_id", $timezoneId);
}

function intltz_get_id(IntlTimeZone $timezone): string|false
{
    return $timezone->getID();
}

function intltz_get_offset(IntlTimeZone $timezone, float $timestamp, bool $local, &$rawOffset, &$dstOffset): bool
{
    return $timezone->__mcGetOffset("intltz_get_offset", $timestamp, $local, $rawOffset, $dstOffset);
}

function intltz_get_raw_offset(IntlTimeZone $timezone): int
{
    return $timezone->getRawOffset();
}

function intltz_get_region(string $timezoneId): string|false
{
    return \__mc_intltz_get_region("intltz_get_region", $timezoneId);
}

function intltz_get_tz_data_version(): string|false
{
    return \__mc_intltz_get_tz_data_version("intltz_get_tz_data_version");
}

function intltz_get_unknown(): IntlTimeZone
{
    return IntlTimeZone::getUnknown();
}

function intltz_get_windows_id(string $timezoneId): string|false
{
    return \__mc_intltz_get_windows_id("intltz_get_windows_id", $timezoneId);
}

function intltz_get_id_for_windows_id(string $timezoneId, ?string $region = null): string|false
{
    return \__mc_intltz_get_id_for_windows_id("intltz_get_id_for_windows_id", $timezoneId, $region);
}

function intltz_has_same_rules(IntlTimeZone $timezone, IntlTimeZone $other): bool
{
    return $timezone->hasSameRules($other);
}

function intltz_to_date_time_zone(IntlTimeZone $timezone): DateTimeZone|false
{
    return $timezone->__mcToDateTimeZone("intltz_to_date_time_zone");
}

function intltz_use_daylight_time(IntlTimeZone $timezone): bool
{
    return $timezone->useDaylightTime();
}
