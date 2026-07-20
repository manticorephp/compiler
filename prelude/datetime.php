<?php

/**
 * The DateTime class family.
 *
 * These live in the PRELUDE, not in src/Runtime, because the stdlib `.sig`
 * carries FUNCTIONS ONLY: a class declared in the stdlib is invisible to a user
 * program (`instanceof` reads false and properties come back as raw bits — the
 * bug documented on \Resource).
 *
 * All the real work is in the stdlib, reached through a SCALAR-ONLY boundary:
 * every __mc_* call below takes and returns ints and strings. Nothing here
 * hands an array or an object across, which is what keeps the whole family
 * clear of the cell/erasure family — and, because no stdlib signature names a
 * DateTime* class, lets this file stay demand-gated instead of unconditional.
 *
 * Two deliberate shapes:
 *   - A moment is (int $ts UTC seconds, int $us) plus THREE zone scalars. There
 *     is no object-typed property anywhere: a DateTimeZone is rebuilt on demand
 *     rather than stored, which is what dodges the `\Resource|false`-style
 *     field erasure.
 *   - DateTime and DateTimeImmutable DUPLICATE their method bodies instead of
 *     sharing a base with a `static` return type. Late static binding is
 *     exactly the shape that erases to a cell here; every method below has a
 *     concrete return type.
 */

interface DateTimeInterface
{
    public function format(string $format): string;
    public function getTimestamp(): int;
    public function getOffset(): int;
    public function getTimezone(): DateTimeZone;
    public function diff(DateTimeInterface $targetObject, bool $absolute = false): DateInterval;
    public function __mcTs(): int;
    public function __mcUs(): int;
    public function __mcZoneName(): string;
    public function __mcZoneType(): int;
    public function __mcZoneOffset(): int;
}

class DateError extends Error {}
class DateObjectError extends DateError {}
class DateRangeError extends DateError {}
class DateException extends Exception {}
class DateInvalidTimeZoneException extends DateException {}
class DateInvalidOperationException extends DateException {}
class DateMalformedStringException extends DateException {}
class DateMalformedIntervalStringException extends DateException {}
class DateMalformedPeriodStringException extends DateException {}

/**
 * A timezone. Carries only its NAME and an integer handle into the stdlib zone
 * registry — never a parsed transition table.
 *
 * $type mirrors PHP's: 1 a fixed offset, 2 an abbreviation, 3 a tz-database
 * identifier.
 */
class DateTimeZone
{
    private string $tzname = '';
    private int $tztype = 3;
    private int $tzoffset = 0;
    private int $tzid = -1;

    public function __construct(string $timezone = 'UTC')
    {
        $m = [];
        if (\preg_match('/^([+-])(\d{2}):?(\d{2})$/', $timezone, $m) === 1) {
            $off = (int)$m[2] * 3600 + (int)$m[3] * 60;
            $this->tzoffset = $m[1] === '-' ? -$off : $off;
            $this->tztype = 1;
            $this->tzname = $timezone;
            $this->tzid = \__mc_tz_open('UTC');
            return;
        }
        $zid = \__mc_tz_open($timezone);
        if ($zid < 0) {
            throw new DateInvalidTimeZoneException('DateTimeZone::__construct(): Unknown or bad timezone (' . $timezone . ')');
        }
        $this->tzname = $timezone;
        $this->tztype = 3;
        $this->tzid = $zid;
    }

    public function getName(): string
    {
        return $this->tzname;
    }

    public function __mcId(): int
    {
        return $this->tzid;
    }

    public function __mcType(): int
    {
        return $this->tztype;
    }

    public function __mcOffset(): int
    {
        return $this->tzoffset;
    }

    public function getOffset(DateTimeInterface $datetime): int
    {
        if ($this->tztype === 1) {
            return $this->tzoffset;
        }
        return \__mc_tz_offset($this->tzid, $datetime->getTimestamp());
    }

    /**
     * Every DST transition recorded for this zone, preceded by the state in
     * force AT $timestampBegin (php emits that leading pseudo-transition, which
     * is why its count is one higher than the number of real switches).
     *
     * The rows are assembled HERE, in the prelude, from int-returning registry
     * ops — the whole reason the registry speaks scalars.
     *
     * The return type is spelled `array<int, array<string, mixed>>` rather than
     * a bare `array`: the row values are heterogeneous, so the inner element
     * must be a genuine CELL for the caller's `$t['ts']` to read back by tag.
     * A bare `array` erases the inner element and the reads come back as raw
     * tag bits.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTransitions(int $timestampBegin = -2145916800, int $timestampEnd = 2145916800): array
    {
        /** @var array<int, array<string, mixed>> $out */
        $out = [];
        if ($this->tztype !== 3) {
            return $out;
        }
        $ty0 = \__mc_tz(8, $this->tzid, $timestampBegin);
        $out[] = [
            'ts' => $timestampBegin,
            'time' => \gmdate('Y-m-d\TH:i:sP', $timestampBegin),
            'offset' => \__mc_tz(4, $this->tzid, $ty0),
            'isdst' => \__mc_tz(5, $this->tzid, $ty0) === 1,
            'abbr' => \__mc_tz_unpackabbr(\__mc_tz(6, $this->tzid, $ty0)),
        ];
        $n = \__mc_tz(1, $this->tzid, 0);
        for ($i = 0; $i < $n; $i++) {
            $at = \__mc_tz(2, $this->tzid, $i);
            if ($at <= $timestampBegin || $at > $timestampEnd) {
                continue;
            }
            $ty = \__mc_tz(3, $this->tzid, $i);
            $out[] = [
                'ts' => $at,
                'time' => \gmdate('Y-m-d\TH:i:sP', $at),
                'offset' => \__mc_tz(4, $this->tzid, $ty),
                'isdst' => \__mc_tz(5, $this->tzid, $ty) === 1,
                'abbr' => \__mc_tz_unpackabbr(\__mc_tz(6, $this->tzid, $ty)),
            ];
        }
        return $out;
    }

    /**
     * Country and coordinates, or false for a zone with no zone.tab row.
     *
     * @return array<string, mixed>|false
     */
    public function getLocation(): array|false
    {
        if ($this->tztype !== 3) {
            return false;
        }
        return \__mc_tz_location($this->tzname);
    }

    /** @return string[] */
    public static function listIdentifiers(int $timezoneGroup = 2047, ?string $countryCode = null): array
    {
        return \timezone_identifiers_list($timezoneGroup, $countryCode);
    }
}

/**
 * A span of time. `days` is the TOTAL day count and exists only on an interval
 * produced by diff(); on a constructed one PHP reports false. It is modelled as
 * two private scalars plus a __get so the public value can be int|false without
 * a `mixed` property.
 */
class DateInterval
{
    public int $y = 0;
    public int $m = 0;
    public int $d = 0;
    public int $h = 0;
    public int $i = 0;
    public int $s = 0;
    public float $f = 0.0;
    public int $invert = 0;
    private int $daysVal = 0;
    private int $daysSet = 0;

    public function __construct(string $duration = 'PT0S')
    {
        if ($duration === '') {
            return;
        }
        $m = [];
        if (\preg_match('/^P(?:(\d+)Y)?(?:(\d+)M)?(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/', $duration, $m) !== 1) {
            throw new DateMalformedIntervalStringException('DateInterval::__construct(): Unknown or bad format (' . $duration . ')');
        }
        // PCRE omits TRAILING non-participating groups entirely, so these must
        // be isset-guarded: reading past the last captured group walked off the
        // end of the match array (a plain SIGSEGV natively, a warning under the
        // interpreter). "P1Y" captures one group, not seven.
        $g1 = isset($m[1]) ? $m[1] : '';
        $g2 = isset($m[2]) ? $m[2] : '';
        $g3 = isset($m[3]) ? $m[3] : '';
        $g4 = isset($m[4]) ? $m[4] : '';
        $g5 = isset($m[5]) ? $m[5] : '';
        $g6 = isset($m[6]) ? $m[6] : '';
        $g7 = isset($m[7]) ? $m[7] : '';
        $this->y = $g1 === '' ? 0 : (int)$g1;
        $this->m = $g2 === '' ? 0 : (int)$g2;
        // A week count folds into days -- PHP reports P2W as d=14.
        $this->d = ($g3 === '' ? 0 : (int)$g3 * 7) + ($g4 === '' ? 0 : (int)$g4);
        $this->h = $g5 === '' ? 0 : (int)$g5;
        $this->i = $g6 === '' ? 0 : (int)$g6;
        if ($g7 !== '') {
            $this->s = (int)$g7;
            $this->f = (float)$g7 - (float)(int)$g7;
        }
    }

    public function __get(string $name): mixed
    {
        if ($name === 'days') {
            if ($this->daysSet === 1) {
                return $this->daysVal;
            }
            return false;
        }
        return null;
    }

    public function __mcSetDays(int $days): void
    {
        $this->daysVal = $days;
        $this->daysSet = 1;
    }

    public function format(string $format): string
    {
        $out = '';
        $n = \strlen($format);
        for ($k = 0; $k < $n; $k++) {
            if ($format[$k] !== '%') {
                $out = $out . $format[$k];
                continue;
            }
            $k = $k + 1;
            if ($k >= $n) {
                break;
            }
            $c = $format[$k];
            if ($c === '%') { $out = $out . '%'; }
            elseif ($c === 'y') { $out = $out . $this->y; }
            elseif ($c === 'Y') { $out = $out . \__mc_d2($this->y); }
            elseif ($c === 'm') { $out = $out . $this->m; }
            elseif ($c === 'M') { $out = $out . \__mc_d2($this->m); }
            elseif ($c === 'd') { $out = $out . $this->d; }
            elseif ($c === 'D') { $out = $out . \__mc_d2($this->d); }
            elseif ($c === 'h') { $out = $out . $this->h; }
            elseif ($c === 'H') { $out = $out . \__mc_d2($this->h); }
            elseif ($c === 'i') { $out = $out . $this->i; }
            elseif ($c === 'I') { $out = $out . \__mc_d2($this->i); }
            elseif ($c === 's') { $out = $out . $this->s; }
            elseif ($c === 'S') { $out = $out . \__mc_d2($this->s); }
            elseif ($c === 'f') { $out = $out . (int)($this->f * 1000000.0); }
            elseif ($c === 'F') { $out = $out . \str_pad((string)(int)($this->f * 1000000.0), 6, '0', \STR_PAD_LEFT); }
            elseif ($c === 'R') { $out = $out . ($this->invert === 1 ? '-' : '+'); }
            elseif ($c === 'r') { $out = $out . ($this->invert === 1 ? '-' : ''); }
            elseif ($c === 'a') { $out = $out . ($this->daysSet === 1 ? (string)$this->daysVal : '(unknown)'); }
            else { $out = $out . $c; }
        }
        return $out;
    }

    public static function createFromDateString(string $datetime): DateInterval
    {
        $iv = new DateInterval('PT0S');
        $base = 0;
        $zid = \__mc_tz_open('UTC');
        $t = \__mc_strtotime_core($datetime, $base, $zid);
        if ($t === \__mc_dt_fail()) {
            return $iv;
        }
        // The difference from the epoch IS the relative span the text described.
        $days = \__mc_fdiv($t, 86400);
        $sod = $t - $days * 86400;
        $iv->d = $days;
        $iv->h = \intdiv($sod, 3600);
        $iv->i = \intdiv($sod % 3600, 60);
        $iv->s = $sod % 60;
        return $iv;
    }
}

/** A mutable date and time. */
class DateTime implements DateTimeInterface
{
    public const ATOM = 'Y-m-d\TH:i:sP';
    public const COOKIE = 'l, d-M-Y H:i:s T';
    public const ISO8601 = 'Y-m-d\TH:i:sO';
    public const RFC822 = 'D, d M y H:i:s O';
    public const RFC850 = 'l, d-M-y H:i:s T';
    public const RFC1036 = 'D, d M y H:i:s O';
    public const RFC1123 = 'D, d M Y H:i:s O';
    public const RFC7231 = 'D, d M Y H:i:s \G\M\T';
    public const RFC2822 = 'D, d M Y H:i:s O';
    public const RFC3339 = 'Y-m-d\TH:i:sP';
    public const RFC3339_EXTENDED = 'Y-m-d\TH:i:s.vP';
    public const RSS = 'D, d M Y H:i:s O';
    public const W3C = 'Y-m-d\TH:i:sP';

    private int $ts = 0;
    private int $us = 0;
    private int $zid = 0;
    private string $zname = 'UTC';
    private int $ztype = 3;
    private int $zoff = 0;

    public function __construct(string $datetime = 'now', ?DateTimeZone $timezone = null)
    {
        if ($timezone === null) {
            $this->zname = \date_default_timezone_get();
            $this->zid = \__mc_tz_open($this->zname);
            $this->ztype = 3;
            $this->zoff = 0;
        } else {
            $this->zname = $timezone->getName();
            $this->zid = $timezone->__mcId();
            $this->ztype = $timezone->__mcType();
            $this->zoff = $timezone->__mcOffset();
        }
        // An "@epoch" literal is zone-INDEPENDENT: php forces a fixed +00:00
        // zone regardless of the one passed, so 'T' reads GMT+0000 and 'e'
        // reads +00:00 rather than the constructor argument.
        if ($datetime !== '' && $datetime[0] === '@') {
            $this->zname = '+00:00';
            $this->ztype = 1;
            $this->zoff = 0;
            $this->zid = \__mc_tz_open('UTC');
        }
        $t = \__mc_strtotime_core($datetime, \time(), $this->zid);
        if ($t === \__mc_dt_fail()) {
            throw new DateMalformedStringException('DateTime::__construct(): Failed to parse time string (' . $datetime . ')');
        }
        $this->ts = $t;
        $this->us = \__mc_dt_us(0, 0);
    }

    public function __mcTs(): int { return $this->ts; }
    public function __mcUs(): int { return $this->us; }
    public function __mcZoneName(): string { return $this->zname; }
    public function __mcZoneType(): int { return $this->ztype; }
    public function __mcZoneOffset(): int { return $this->zoff; }

    public function __mcSet(int $ts, int $us): void
    {
        $this->ts = $ts;
        $this->us = $us;
    }

    public function format(string $format): string
    {
        return \__mc_date_fmt($format, $this->ts, $this->us, $this->zid, $this->zname, $this->ztype, $this->zoff);
    }

    public function getTimestamp(): int
    {
        return $this->ts;
    }

    public function setTimestamp(int $timestamp): DateTime
    {
        $this->ts = $timestamp;
        $this->us = 0;
        return $this;
    }

    public function getOffset(): int
    {
        if ($this->ztype === 3) {
            return \__mc_tz_offset($this->zid, $this->ts);
        }
        return $this->zoff;
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone($this->zname);
    }

    public function setTimezone(DateTimeZone $timezone): DateTime
    {
        $this->zname = $timezone->getName();
        $this->zid = $timezone->__mcId();
        $this->ztype = $timezone->__mcType();
        $this->zoff = $timezone->__mcOffset();
        return $this;
    }

    public function setDate(int $year, int $month, int $day): DateTime
    {
        $this->ts = \__mc_dt_setdate($this->ts, $this->zid, $this->ztype, $this->zoff, $year, $month, $day);
        return $this;
    }

    public function setISODate(int $year, int $week, int $dayOfWeek = 1): DateTime
    {
        $this->ts = \__mc_dt_setisodate($this->ts, $this->zid, $this->ztype, $this->zoff, $year, $week, $dayOfWeek);
        return $this;
    }

    public function setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0): DateTime
    {
        $this->ts = \__mc_dt_settime($this->ts, $this->zid, $this->ztype, $this->zoff, $hour, $minute, $second);
        $this->us = $microsecond;
        return $this;
    }

    public function modify(string $modifier): DateTime
    {
        $t = \__mc_dt_modify($this->ts, $this->zid, $this->ztype, $this->zoff, $modifier);
        if ($t === \__mc_dt_fail()) {
            throw new DateMalformedStringException('DateTime::modify(): Failed to parse time string (' . $modifier . ')');
        }
        $this->ts = $t;
        return $this;
    }

    public function add(DateInterval $interval): DateTime
    {
        $this->ts = \__mc_dt_shift($this->ts, $this->zid, $this->ztype, $this->zoff,
            $interval->y, $interval->m, $interval->d, $interval->h, $interval->i, $interval->s,
            $interval->invert === 1 ? -1 : 1);
        return $this;
    }

    public function sub(DateInterval $interval): DateTime
    {
        $this->ts = \__mc_dt_shift($this->ts, $this->zid, $this->ztype, $this->zoff,
            $interval->y, $interval->m, $interval->d, $interval->h, $interval->i, $interval->s,
            $interval->invert === 1 ? 1 : -1);
        return $this;
    }

    public function diff(DateTimeInterface $targetObject, bool $absolute = false): DateInterval
    {
        // Assembled HERE: a DateInterval is an object, and an object must never
        // cross the stdlib boundary. The stdlib answers one int at a time.
        $b = $targetObject->getTimestamp();
        $iv = new DateInterval('PT0S');
        $iv->y = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 0);
        $iv->m = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 1);
        $iv->d = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 2);
        $iv->h = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 3);
        $iv->i = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 4);
        $iv->s = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 5);
        $iv->__mcSetDays(\__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 6));
        $iv->invert = $absolute ? 0 : \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 7);
        return $iv;
    }

    public static function createFromFormat(string $format, string $datetime, ?DateTimeZone $timezone = null): DateTime|false
    {
        $t = \__mc_dt_from_format($format, $datetime);
        if ($t === \__mc_dt_fail()) {
            return false;
        }
        $d = new DateTime('@' . $t, $timezone);
        if ($timezone !== null) {
            $d->setTimezone($timezone);
        }
        return $d;
    }

    public static function createFromImmutable(DateTimeImmutable $object): DateTime
    {
        $d = new DateTime('@' . $object->getTimestamp(), new DateTimeZone($object->__mcZoneName()));
        $d->setTimezone(new DateTimeZone($object->__mcZoneName()));
        $d->__mcSet($object->getTimestamp(), $object->__mcUs());
        return $d;
    }

    public static function createFromInterface(DateTimeInterface $object): DateTime
    {
        $d = new DateTime('@' . $object->getTimestamp(), new DateTimeZone($object->__mcZoneName()));
        $d->setTimezone(new DateTimeZone($object->__mcZoneName()));
        $d->__mcSet($object->getTimestamp(), $object->__mcUs());
        return $d;
    }
}

/** An immutable date and time: every mutator returns a NEW instance. */
class DateTimeImmutable implements DateTimeInterface
{
    public const ATOM = 'Y-m-d\TH:i:sP';
    public const COOKIE = 'l, d-M-Y H:i:s T';
    public const ISO8601 = 'Y-m-d\TH:i:sO';
    public const RFC822 = 'D, d M y H:i:s O';
    public const RFC850 = 'l, d-M-y H:i:s T';
    public const RFC1036 = 'D, d M y H:i:s O';
    public const RFC1123 = 'D, d M Y H:i:s O';
    public const RFC7231 = 'D, d M Y H:i:s \G\M\T';
    public const RFC2822 = 'D, d M Y H:i:s O';
    public const RFC3339 = 'Y-m-d\TH:i:sP';
    public const RFC3339_EXTENDED = 'Y-m-d\TH:i:s.vP';
    public const RSS = 'D, d M Y H:i:s O';
    public const W3C = 'Y-m-d\TH:i:sP';

    private int $ts = 0;
    private int $us = 0;
    private int $zid = 0;
    private string $zname = 'UTC';
    private int $ztype = 3;
    private int $zoff = 0;

    public function __construct(string $datetime = 'now', ?DateTimeZone $timezone = null)
    {
        if ($timezone === null) {
            $this->zname = \date_default_timezone_get();
            $this->zid = \__mc_tz_open($this->zname);
            $this->ztype = 3;
            $this->zoff = 0;
        } else {
            $this->zname = $timezone->getName();
            $this->zid = $timezone->__mcId();
            $this->ztype = $timezone->__mcType();
            $this->zoff = $timezone->__mcOffset();
        }
        // An "@epoch" literal is zone-INDEPENDENT: php forces a fixed +00:00
        // zone regardless of the one passed, so 'T' reads GMT+0000 and 'e'
        // reads +00:00 rather than the constructor argument.
        if ($datetime !== '' && $datetime[0] === '@') {
            $this->zname = '+00:00';
            $this->ztype = 1;
            $this->zoff = 0;
            $this->zid = \__mc_tz_open('UTC');
        }
        $t = \__mc_strtotime_core($datetime, \time(), $this->zid);
        if ($t === \__mc_dt_fail()) {
            throw new DateMalformedStringException('DateTimeImmutable::__construct(): Failed to parse time string (' . $datetime . ')');
        }
        $this->ts = $t;
        $this->us = \__mc_dt_us(0, 0);
    }

    public function __mcTs(): int { return $this->ts; }
    public function __mcUs(): int { return $this->us; }
    public function __mcZoneName(): string { return $this->zname; }
    public function __mcZoneType(): int { return $this->ztype; }
    public function __mcZoneOffset(): int { return $this->zoff; }

    public function __mcSet(int $ts, int $us): void
    {
        $this->ts = $ts;
        $this->us = $us;
    }

    public function format(string $format): string
    {
        return \__mc_date_fmt($format, $this->ts, $this->us, $this->zid, $this->zname, $this->ztype, $this->zoff);
    }

    public function getTimestamp(): int
    {
        return $this->ts;
    }

    public function setTimestamp(int $timestamp): DateTimeImmutable
    {
        $c = clone $this;
        $c->__mcSet($timestamp, 0);
        return $c;
    }

    public function getOffset(): int
    {
        if ($this->ztype === 3) {
            return \__mc_tz_offset($this->zid, $this->ts);
        }
        return $this->zoff;
    }

    public function getTimezone(): DateTimeZone
    {
        return new DateTimeZone($this->zname);
    }

    public function setTimezone(DateTimeZone $timezone): DateTimeImmutable
    {
        $c = clone $this;
        $c->__mcSetZone($timezone->getName(), $timezone->__mcId(), $timezone->__mcType(), $timezone->__mcOffset());
        return $c;
    }

    public function __mcSetZone(string $name, int $zid, int $type, int $off): void
    {
        $this->zname = $name;
        $this->zid = $zid;
        $this->ztype = $type;
        $this->zoff = $off;
    }

    public function setDate(int $year, int $month, int $day): DateTimeImmutable
    {
        $c = clone $this;
        $c->__mcSet(\__mc_dt_setdate($this->ts, $this->zid, $this->ztype, $this->zoff, $year, $month, $day), $this->us);
        return $c;
    }

    public function setISODate(int $year, int $week, int $dayOfWeek = 1): DateTimeImmutable
    {
        $c = clone $this;
        $c->__mcSet(\__mc_dt_setisodate($this->ts, $this->zid, $this->ztype, $this->zoff, $year, $week, $dayOfWeek), $this->us);
        return $c;
    }

    public function setTime(int $hour, int $minute, int $second = 0, int $microsecond = 0): DateTimeImmutable
    {
        $c = clone $this;
        $c->__mcSet(\__mc_dt_settime($this->ts, $this->zid, $this->ztype, $this->zoff, $hour, $minute, $second), $microsecond);
        return $c;
    }

    public function modify(string $modifier): DateTimeImmutable
    {
        $t = \__mc_dt_modify($this->ts, $this->zid, $this->ztype, $this->zoff, $modifier);
        if ($t === \__mc_dt_fail()) {
            throw new DateMalformedStringException('DateTimeImmutable::modify(): Failed to parse time string (' . $modifier . ')');
        }
        $c = clone $this;
        $c->__mcSet($t, $this->us);
        return $c;
    }

    public function add(DateInterval $interval): DateTimeImmutable
    {
        $c = clone $this;
        $c->__mcSet(\__mc_dt_shift($this->ts, $this->zid, $this->ztype, $this->zoff,
            $interval->y, $interval->m, $interval->d, $interval->h, $interval->i, $interval->s,
            $interval->invert === 1 ? -1 : 1), $this->us);
        return $c;
    }

    public function sub(DateInterval $interval): DateTimeImmutable
    {
        $c = clone $this;
        $c->__mcSet(\__mc_dt_shift($this->ts, $this->zid, $this->ztype, $this->zoff,
            $interval->y, $interval->m, $interval->d, $interval->h, $interval->i, $interval->s,
            $interval->invert === 1 ? 1 : -1), $this->us);
        return $c;
    }

    public function diff(DateTimeInterface $targetObject, bool $absolute = false): DateInterval
    {
        // Assembled HERE: a DateInterval is an object, and an object must never
        // cross the stdlib boundary. The stdlib answers one int at a time.
        $b = $targetObject->getTimestamp();
        $iv = new DateInterval('PT0S');
        $iv->y = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 0);
        $iv->m = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 1);
        $iv->d = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 2);
        $iv->h = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 3);
        $iv->i = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 4);
        $iv->s = \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 5);
        $iv->__mcSetDays(\__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 6));
        $iv->invert = $absolute ? 0 : \__mc_dt_diff($this->ts, $b, $this->zid, $this->ztype, $this->zoff, 7);
        return $iv;
    }

    public static function createFromFormat(string $format, string $datetime, ?DateTimeZone $timezone = null): DateTimeImmutable|false
    {
        $t = \__mc_dt_from_format($format, $datetime);
        if ($t === \__mc_dt_fail()) {
            return false;
        }
        $d = new DateTimeImmutable('@' . $t, $timezone);
        if ($timezone !== null) {
            return $d->setTimezone($timezone);
        }
        return $d;
    }

    public static function createFromMutable(DateTime $object): DateTimeImmutable
    {
        $d = new DateTimeImmutable('@' . $object->getTimestamp(), new DateTimeZone($object->__mcZoneName()));
        $d->__mcSetZone($object->__mcZoneName(), \__mc_tz_open($object->__mcZoneName()), $object->__mcZoneType(), $object->__mcZoneOffset());
        $d->__mcSet($object->getTimestamp(), $object->__mcUs());
        return $d;
    }

    public static function createFromInterface(DateTimeInterface $object): DateTimeImmutable
    {
        $d = new DateTimeImmutable('@' . $object->getTimestamp(), new DateTimeZone($object->__mcZoneName()));
        $d->__mcSetZone($object->__mcZoneName(), \__mc_tz_open($object->__mcZoneName()), $object->__mcZoneType(), $object->__mcZoneOffset());
        $d->__mcSet($object->getTimestamp(), $object->__mcUs());
        return $d;
    }
}

/**
 * A recurring interval.
 *
 * Implements Iterator DIRECTLY rather than yielding DateTime objects from a
 * Generator. A generator hands its value back through the uniform closure ABI,
 * which boxes scalars and passes objects raw; a yielded DateTime came back
 * erased and `->format()` on it returned an integer. Iterator's `current()`
 * has a CONCRETE return type, so nothing is erased. (Generators themselves are
 * fine — it is objects crossing that boundary that are not.)
 */
class DatePeriod implements Iterator
{
    public const EXCLUDE_START_DATE = 1;
    public const INCLUDE_END_DATE = 2;

    private int $startTs = 0;
    private int $endTs = 0;
    private int $recurrences = 0;
    private int $useEnd = 0;
    private int $options = 0;
    private string $zname = 'UTC';
    private int $ivY = 0;
    private int $ivM = 0;
    private int $ivD = 0;
    private int $ivH = 0;
    private int $ivI = 0;
    private int $ivS = 0;

    public function __construct(DateTimeInterface $start, DateInterval $interval, DateTimeInterface|int $end, int $options = 0)
    {
        $this->startTs = $start->getTimestamp();
        $this->zname = $start->__mcZoneName();
        $this->ivY = $interval->y;
        $this->ivM = $interval->m;
        $this->ivD = $interval->d;
        $this->ivH = $interval->h;
        $this->ivI = $interval->i;
        $this->ivS = $interval->s;
        $this->options = $options;
        // is_int FIRST: `$end instanceof DateTimeInterface` with an int operand
        // answered true against an INTERFACE, which sent a recurrence count
        // down the end-date path and produced an empty period.
        if (\is_int($end)) {
            $this->recurrences = $end;
            $this->useEnd = 0;
        } else {
            $this->endTs = $end->getTimestamp();
            $this->useEnd = 1;
        }
    }

    private int $curTs = 0;
    private int $idx = 0;
    private int $step = 0;

    private function advance(): void
    {
        $this->curTs = \__mc_dt_shift($this->curTs, \__mc_tz_open($this->zname), 3, 0,
            $this->ivY, $this->ivM, $this->ivD, $this->ivH, $this->ivI, $this->ivS, 1);
        $this->step = $this->step + 1;
    }

    public function rewind(): void
    {
        $this->curTs = $this->startTs;
        $this->idx = 0;
        $this->step = 0;
        if (($this->options & 1) === 1) {
            $this->advance();
        }
    }

    public function valid(): bool
    {
        if ($this->useEnd === 1) {
            return $this->curTs < $this->endTs;
        }
        return $this->step <= $this->recurrences;
    }

    public function current(): DateTime
    {
        $d = new DateTime('@' . $this->curTs, new DateTimeZone($this->zname));
        return $d->setTimezone(new DateTimeZone($this->zname));
    }

    public function key(): int
    {
        return $this->idx;
    }

    public function next(): void
    {
        $this->advance();
        $this->idx = $this->idx + 1;
    }
}

// ---------------------------------------------------------------------------
// Procedural aliases.
//
// These live in the PRELUDE, beside the classes, and NOT in the stdlib: a
// stdlib signature naming DateTimeZone / DateTimeInterface would pull the class
// into the .sig, and a class in the .sig has to be registered in every module —
// exactly what forces \Resource to be unconditional. Main.php's gate therefore
// also fires on a CALL to any of these, not only on a class MENTION.
// ---------------------------------------------------------------------------

function date_create(string $datetime = 'now', ?DateTimeZone $timezone = null): DateTime|false
{
    return new DateTime($datetime, $timezone);
}

function date_create_immutable(string $datetime = 'now', ?DateTimeZone $timezone = null): DateTimeImmutable|false
{
    return new DateTimeImmutable($datetime, $timezone);
}

function date_create_from_format(string $format, string $datetime, ?DateTimeZone $timezone = null): DateTime|false
{
    return DateTime::createFromFormat($format, $datetime, $timezone);
}

function date_format(DateTimeInterface $object, string $format): string
{
    return $object->format($format);
}

function date_timestamp_get(DateTimeInterface $object): int
{
    return $object->getTimestamp();
}

function date_timestamp_set(DateTime $object, int $timestamp): DateTime
{
    return $object->setTimestamp($timestamp);
}

function date_offset_get(DateTimeInterface $object): int
{
    return $object->getOffset();
}

function date_timezone_get(DateTimeInterface $object): DateTimeZone
{
    return $object->getTimezone();
}

function date_timezone_set(DateTime $object, DateTimeZone $timezone): DateTime
{
    return $object->setTimezone($timezone);
}

function date_modify(DateTime $object, string $modifier): DateTime
{
    return $object->modify($modifier);
}

function date_add(DateTime $object, DateInterval $interval): DateTime
{
    return $object->add($interval);
}

function date_sub(DateTime $object, DateInterval $interval): DateTime
{
    return $object->sub($interval);
}

function date_diff(DateTimeInterface $baseObject, DateTimeInterface $targetObject, bool $absolute = false): DateInterval
{
    return $baseObject->diff($targetObject, $absolute);
}

function date_date_set(DateTime $object, int $year, int $month, int $day): DateTime
{
    return $object->setDate($year, $month, $day);
}

function date_time_set(DateTime $object, int $hour, int $minute, int $second = 0, int $microsecond = 0): DateTime
{
    return $object->setTime($hour, $minute, $second, $microsecond);
}

function date_isodate_set(DateTime $object, int $year, int $week, int $dayOfWeek = 1): DateTime
{
    return $object->setISODate($year, $week, $dayOfWeek);
}

function date_interval_format(DateInterval $object, string $format): string
{
    return $object->format($format);
}

function date_interval_create_from_date_string(string $datetime): DateInterval
{
    return DateInterval::createFromDateString($datetime);
}

function timezone_open(string $timezone): DateTimeZone|false
{
    return new DateTimeZone($timezone);
}

function timezone_name_get(DateTimeZone $object): string
{
    return $object->getName();
}

function timezone_offset_get(DateTimeZone $object, DateTimeInterface $datetime): int
{
    return $object->getOffset($datetime);
}

function timezone_transitions_get(DateTimeZone $object, int $timestampBegin = -2145916800, int $timestampEnd = 2145916800): array
{
    return $object->getTransitions($timestampBegin, $timestampEnd);
}

function timezone_location_get(DateTimeZone $object): array|false
{
    return $object->getLocation();
}
