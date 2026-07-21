<?php

/**
 * strtotime() — a re-implementation of Zend's `parse_date` grammar.
 *
 * That grammar is not a recursive-descent tree; it is a BAG OF LONGEST-MATCH
 * RECOGNIZERS applied repeatedly to a cursor, each mutating a shared
 * accumulator. So this is one loop over the string, trying recognizers in a
 * fixed priority order. Everything lives in locals of a single function: the
 * accumulator is ~25 scalars and never becomes an array, so nothing here can
 * fault across a call boundary.
 *
 * Unset absolute fields carry the sentinel -99999 rather than null — a
 * null-seeded accumulator is a documented mis-boxing hazard.
 *
 * The order in which the accumulated pieces are APPLIED is where parity is won
 * or lost; see __mc_dt_build.
 */

/** Sentinel for "this field was not set by the input". */
function __mc_dt_unset(): int
{
    return -99999;
}

/** Sentinel returned when the input is not a date/time expression at all. */
function __mc_dt_fail(): int
{
    return -9223372036854775807;
}

/**
 * Microseconds carried out of the most recent successful parse. A separate
 * slot rather than a second return value, because the boundary here is one
 * scalar per call — and two functions cannot share a `static`.
 */
function __mc_dt_us(int $op, int $v): int
{
    static $u = 0;
    if ($op === 1) {
        $u = $v;
    }
    return $u;
}

/**
 * The RAW absolute fields of the most recent successful parse — what the input
 * actually said, BEFORE any base timestamp filled the gaps. date_parse reports
 * exactly this, with an unset field as `false`, so the values are stored here
 * (still carrying the -99999 sentinel) rather than after the fill.
 *
 * $idx: 0 y, 1 month, 2 day, 3 hour, 4 minute, 5 second, 6 microsecond,
 *       7 zone offset, 8 "a zone was given". Reads only.
 *
 * WRITES go through __mc_dt_publish, a single 9-argument call. The earlier
 * form took nine separate __mc_dt_slot(1, i, v) calls INSIDE the hot
 * __mc_strtotime_core; that stack pressure tripped a codegen fragility that
 * corrupted a CALLER's local (a base timestamp read back as -99999). Confining
 * every write to one small leaf keeps __mc_strtotime_core's frame unchanged.
 */
function __mc_dt_slot(int $idx): int
{
    return \__mc_dt_slot_store(0, $idx, 0);
}

/** Backing store for the raw parse fields. Written only by __mc_dt_publish. */
function __mc_dt_slot_store(int $op, int $idx, int $v): int
{
    static $s = [0, 0, 0, 0, 0, 0, 0, 0, 0];
    if ($op === 1) {
        $s[$idx] = $v;
        return $v;
    }
    return $s[$idx];
}

/** Publish all nine raw parse fields at once — one leaf call, not nine inline. */
function __mc_dt_publish(int $y, int $mo, int $d, int $h, int $mi, int $sec,
    int $us, int $zOff, int $haveZone): void
{
    \__mc_dt_slot_store(1, 0, $y);
    \__mc_dt_slot_store(1, 1, $mo);
    \__mc_dt_slot_store(1, 2, $d);
    \__mc_dt_slot_store(1, 3, $h);
    \__mc_dt_slot_store(1, 4, $mi);
    \__mc_dt_slot_store(1, 5, $sec);
    \__mc_dt_slot_store(1, 6, $us);
    \__mc_dt_slot_store(1, 7, $zOff);
    \__mc_dt_slot_store(1, 8, $haveZone);
}

/** Month number for a full or 3+ letter English name, else 0. */
function __mc_dt_month(string $w): int
{
    $w = \substr($w, 0, 3);
    if ($w === 'jan') { return 1; }
    if ($w === 'feb') { return 2; }
    if ($w === 'mar') { return 3; }
    if ($w === 'apr') { return 4; }
    if ($w === 'may') { return 5; }
    if ($w === 'jun') { return 6; }
    if ($w === 'jul') { return 7; }
    if ($w === 'aug') { return 8; }
    if ($w === 'sep') { return 9; }
    if ($w === 'oct') { return 10; }
    if ($w === 'nov') { return 11; }
    if ($w === 'dec') { return 12; }
    return 0;
}

/**
 * Weekday number (0 = Sunday) for an English name, else -1.
 *
 * EXACT tokens only. Matching a 3-letter prefix instead made "month" read as
 * "mon", so "next month" silently became "next Monday".
 */
function __mc_dt_weekday(string $w): int
{
    if ($w === 'sun' || $w === 'sunday') { return 0; }
    if ($w === 'mon' || $w === 'monday') { return 1; }
    if ($w === 'tue' || $w === 'tues' || $w === 'tuesday') { return 2; }
    if ($w === 'wed' || $w === 'weds' || $w === 'wednesday') { return 3; }
    if ($w === 'thu' || $w === 'thur' || $w === 'thurs' || $w === 'thursday') { return 4; }
    if ($w === 'fri' || $w === 'friday') { return 5; }
    if ($w === 'sat' || $w === 'saturday') { return 6; }
    return -1;
}

/** Relative unit id: 1 s, 2 min, 3 h, 4 day, 5 week, 6 fortnight, 7 month, 8 year, 9 weekday. */
function __mc_dt_unit(string $w): int
{
    if (\substr($w, -1) === 's' && \strlen($w) > 3) {
        $w = \substr($w, 0, \strlen($w) - 1);
    }
    if ($w === 'sec' || $w === 'second') { return 1; }
    if ($w === 'min' || $w === 'minute') { return 2; }
    if ($w === 'hour') { return 3; }
    if ($w === 'day') { return 4; }
    if ($w === 'week') { return 5; }
    if ($w === 'fortnight' || $w === 'forthnight') { return 6; }
    if ($w === 'month') { return 7; }
    if ($w === 'year') { return 8; }
    if ($w === 'weekday') { return 9; }
    return 0;
}

/** Ordinal word to a count ("next" 1, "last" -1, "third" 3, ...), sentinel if none. */
function __mc_dt_ordinal(string $w): int
{
    if ($w === 'next') { return 1; }
    if ($w === 'last' || $w === 'previous') { return -1; }
    if ($w === 'this') { return 0; }
    if ($w === 'first') { return 1; }
    if ($w === 'second') { return 2; }
    if ($w === 'third') { return 3; }
    if ($w === 'fourth') { return 4; }
    if ($w === 'fifth') { return 5; }
    if ($w === 'sixth') { return 6; }
    if ($w === 'seventh') { return 7; }
    if ($w === 'eighth') { return 8; }
    if ($w === 'ninth') { return 9; }
    if ($w === 'tenth') { return 10; }
    if ($w === 'eleventh') { return 11; }
    if ($w === 'twelfth') { return 12; }
    return -99999;
}

/** Fixed offset in seconds for a military/common zone abbreviation, sentinel if unknown. */
function __mc_dt_abbroff(string $w): int
{
    if ($w === 'gmt' || $w === 'utc' || $w === 'ut' || $w === 'z' || $w === 'zulu') { return 0; }
    if ($w === 'est') { return -18000; }
    if ($w === 'edt') { return -14400; }
    if ($w === 'cst') { return -21600; }
    if ($w === 'cdt') { return -18000; }
    if ($w === 'mst') { return -25200; }
    if ($w === 'mdt') { return -21600; }
    if ($w === 'pst') { return -28800; }
    if ($w === 'pdt') { return -25200; }
    if ($w === 'bst') { return 3600; }
    if ($w === 'cet') { return 3600; }
    if ($w === 'cest') { return 7200; }
    if ($w === 'eet') { return 7200; }
    if ($w === 'eest') { return 10800; }
    if ($w === 'jst') { return 32400; }
    if ($w === 'msk') { return 10800; }
    return -99999;
}

/** Two-digit year to PHP's window: 00-69 -> 2000s, 70-99 -> 1900s. */
function __mc_dt_y2(int $y): int
{
    if ($y < 70) { return $y + 2000; }
    if ($y < 100) { return $y + 1900; }
    return $y;
}

/**
 * Apply the accumulated absolute/relative pieces and return the UTC instant.
 *
 * THE ORDER BELOW IS LOAD-BEARING — it mirrors Zend's timelib_update_ts, and
 * every step's position is observable:
 *
 *   1. normalize the absolute fields
 *   2. add relative YEAR and MONTH, then NORMALIZE — this is what makes
 *      "2001-01-31 +1 month" land on March 3 rather than February 28
 *   3. add relative DAY/HOUR/MIN/SEC, then normalize
 *   4. apply a relative WEEKDAY
 *   5. apply "first/last day of" AFTER the relative arithmetic, so
 *      "last day of next month" means what it says
 *   6. convert local -> UTC LAST, so "+1 day" across a DST boundary preserves
 *      the WALL CLOCK (24h is not always 86400s)
 */
function __mc_dt_build(int $y, int $mo, int $d, int $h, int $mi, int $sec, int $us,
    int $relY, int $relM, int $relD, int $relH, int $relI, int $relS,
    int $relWd, int $relWdBehavior, int $haveRelWd, int $firstLast,
    int $zid, int $haveZone, int $zOff, int $which): int
{
    // 1. normalize the absolute fields
    $ny = \__mc_norm_ymd($y, $mo, $d, 0);
    $nm = \__mc_norm_ymd($y, $mo, $d, 1);
    $nd = \__mc_norm_ymd($y, $mo, $d, 2);

    // 2. relative year/month, then normalize (the month-overflow spill)
    $ny = $ny + $relY;
    $nm = $nm + $relM;
    if ($firstLast !== 0) {
        // "first/last day of" pins the day INSIDE the target month, so it must
        // be applied BEFORE the day-overflow normalize -- otherwise
        // "last day of next month" from Jan 31 normalizes Feb 31 into March 2
        // and then takes March's last day, a whole month late.
        $m0 = $nm - 1;
        $y2 = $ny + \__mc_fdiv($m0, 12);
        $m2 = \__mc_fmod($m0, 12) + 1;
        $d2 = $firstLast === 1 ? 1 : \__mc_days_in_month($y2, $m2);
    } else {
        $y2 = \__mc_norm_ymd($ny, $nm, $nd, 0);
        $m2 = \__mc_norm_ymd($ny, $nm, $nd, 1);
        $d2 = \__mc_norm_ymd($ny, $nm, $nd, 2);
    }

    // "first/last day of" DISCARDS the relative day component outright:
    // "last day of next month +2 weeks" is just "last day of next month".
    // The relative month/year still apply, and the time of day is untouched.
    if ($firstLast !== 0) {
        $relD = 0;
        $haveRelWd = 0;
    }

    // 3. relative day/time, carried through the day number so any magnitude works
    $days = \__mc_days_from_civil($y2, $m2, $d2) + $relD;
    $tod = $h * 3600 + $mi * 60 + $sec + $relH * 3600 + $relI * 60 + $relS;
    $days = $days + \__mc_fdiv($tod, 86400);
    $tod = $tod - \__mc_fdiv($tod, 86400) * 86400;

    // 4. relative weekday.
    //    behavior  0 — a bare "monday" / "this monday": move FORWARD, but stay
    //                  put when the day already is that weekday
    //    behavior  1 — "next monday" / "first monday": STRICTLY forward
    //    behavior -1 — "last monday" / "previous monday": STRICTLY backward
    //    behavior  2 — "next week" / "this week": snap BACK to that weekday
    //                  inside the current week (PHP's week starts Monday)
    if ($haveRelWd === 1) {
        $cur = \__mc_dow($days);
        $diff = $relWd - $cur;
        if ($relWdBehavior === 1) {
            if ($diff <= 0) { $diff = $diff + 7; }
        } elseif ($relWdBehavior === -1) {
            if ($diff >= 0) { $diff = $diff - 7; }
        } elseif ($relWdBehavior === 2) {
            if ($diff > 0) { $diff = $diff - 7; }
        } elseif ($diff < 0) {
            $diff = $diff + 7;
        }
        $days = $days + $diff;
    }

    // 5. "first/last day of" was already pinned in step 2, where it has to be.

    $local = $days * 86400 + $tod;
    if ($which === 1) {
        return $us;
    }
    // 6. local -> UTC last
    if ($haveZone === 1) {
        return $local - $zOff;
    }
    return \__mc_tz_localtoutc($zid, $local);
}

/**
 * Parse $str relative to the UTC instant $base, in zone $zid.
 * Returns the UTC timestamp, or __mc_dt_fail() when nothing parsed.
 */
function __mc_strtotime_core(string $str, int $base, int $zid, int $publish): int
{
    $s = \strtolower(\trim($str));
    if ($s === '') {
        return \__mc_dt_fail();
    }

    $UN = -99999;
    $y = $UN; $mo = $UN; $d = $UN;
    $h = $UN; $mi = $UN; $sec = $UN; $us = 0;
    $relY = 0; $relM = 0; $relD = 0; $relH = 0; $relI = 0; $relS = 0;
    $relWd = 0; $relWdBehavior = 0; $haveRelWd = 0;
    $firstLast = 0;
    $zOff = 0; $haveZone = 0; $useZid = $zid;
    $haveDate = 0; $haveTime = 0; $matched = 0; $ago = 0; $isEpoch = 0;
    $pendingOrd = $UN;

    $pos = 0;
    $n = \strlen($s);
    $guard = 0;
    while ($pos < $n) {
        $guard = $guard + 1;
        if ($guard > 400) {
            return \__mc_dt_fail();
        }
        $rest = \substr($s, $pos);
        $m = [];

        // separators, plus the ISO 8601 'T' that joins a date to a time
        if (\preg_match('/^[\s,()\[\]]+/', $rest, $m) === 1) {
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        if ($haveDate === 1 && $haveTime === 0 && \preg_match('/^t(?=\d)/', $rest, $m) === 1) {
            $pos = $pos + 1;
            continue;
        }

        // @<epoch>[.frac] — an absolute UTC instant, zone-independent
        if (\preg_match('/^@(-?\d+)(?:\.(\d+))?/', $rest, $m) === 1) {
            $ts = (int)$m[1];
            $p2 = \__mc_civil_from_days(\__mc_fdiv($ts, 86400));
            $y = \__mc_civ_y($p2); $mo = \__mc_civ_m($p2); $d = \__mc_civ_d($p2);
            $sod = $ts - \__mc_fdiv($ts, 86400) * 86400;
            $h = \intdiv($sod, 3600); $mi = \intdiv($sod % 3600, 60); $sec = $sod % 60;
            if (isset($m[2]) && $m[2] !== '') {
                $us = (int)\substr($m[2] . '000000', 0, 6);
            }
            $haveZone = 1; $zOff = 0; $haveDate = 1; $haveTime = 1; $matched = 1;
            $isEpoch = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }

        // ISO 8601 date, optionally with a time and a zone
        if (\preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $rest, $m) === 1) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // ISO week date: 2017-W28-5
        if (\preg_match('/^(\d{4})-?w(\d{2})(?:-?(\d))?/', $rest, $m) === 1) {
            $iy = (int)$m[1]; $iw = (int)$m[2];
            $idow = (isset($m[3]) && $m[3] !== '') ? (int)$m[3] : 1;
            // ISO week 1 contains the first Thursday, i.e. Jan 4.
            $jan4 = \__mc_days_from_civil($iy, 1, 4);
            $dow4 = \__mc_dow($jan4);
            $isoDow4 = $dow4 === 0 ? 7 : $dow4;
            $week1Mon = $jan4 - ($isoDow4 - 1);
            $z = $week1Mon + ($iw - 1) * 7 + ($idow - 1);
            $p2 = \__mc_civil_from_days($z);
            $y = \__mc_civ_y($p2); $mo = \__mc_civ_m($p2); $d = \__mc_civ_d($p2);
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // yyyy/mm/dd
        if (\preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})/', $rest, $m) === 1) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // mm/dd[/yyyy] — the SLASH selects US ordering. The same digits with
        // dashes are ISO/European; this is a real PHP rule and a parity trap.
        if (\preg_match('/^(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?/', $rest, $m) === 1) {
            $mo = (int)$m[1]; $d = (int)$m[2];
            if (isset($m[3]) && $m[3] !== '') { $y = \__mc_dt_y2((int)$m[3]); }
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // dd.mm.yyyy — European, dot-separated
        if (\preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})/', $rest, $m) === 1) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // dd-mm-yyyy
        if (\preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})/', $rest, $m) === 1) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // "14 July 2017" / "14 jul" / "14th July 2017". No "of" form: php
        // rejects "14th of July", so accepting it would be a divergence.
        if (\preg_match('/^(\d{1,2})(?:st|nd|rd|th)?[\s-]*(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?(?:[\s,-]+(\d{4}))?/', $rest, $m) === 1) {
            $d = (int)$m[1]; $mo = \__mc_dt_month($m[2]);
            if (isset($m[3]) && $m[3] !== '') { $y = (int)$m[3]; }
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // "July 14, 2017" / "jul 14". The (?!\d) is load-bearing: without it
        // "july 2017" matched with day=20 and left a dangling "17".
        if (\preg_match('/^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?[\s.-]*(\d{1,2})(?!\d)(?:st|nd|rd|th)?(?:[\s,-]+(\d{4}))?/', $rest, $m) === 1) {
            $mo = \__mc_dt_month($m[1]); $d = (int)$m[2];
            if (isset($m[3]) && $m[3] !== '') { $y = (int)$m[3]; }
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // "July 2017" / "january 2018" / bare "july" — a month with no day means
        // the FIRST of that month, at midnight.
        if (\preg_match('/^(jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?(?:[\s,-]+(\d{4}))?/', $rest, $m) === 1) {
            $mo = \__mc_dt_month($m[1]);
            if (isset($m[2]) && $m[2] !== '') { $y = (int)$m[2]; }
            $d = 1;
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // time: 16:01[:07][.frac] [am|pm]
        if (\preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.(\d+))?\s*(am|pm)?/', $rest, $m) === 1) {
            $h = (int)$m[1]; $mi = (int)$m[2];
            $sec = (isset($m[3]) && $m[3] !== '') ? (int)$m[3] : 0;
            if (isset($m[4]) && $m[4] !== '') {
                $us = (int)\substr($m[4] . '000000', 0, 6);
            }
            if (isset($m[5]) && $m[5] !== '') {
                if ($m[5] === 'pm' && $h < 12) { $h = $h + 12; }
                if ($m[5] === 'am' && $h === 12) { $h = 0; }
            }
            $haveTime = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // "4pm" / "4 am"
        if (\preg_match('/^(\d{1,2})\s*(am|pm)/', $rest, $m) === 1) {
            $h = (int)$m[1]; $mi = 0; $sec = 0;
            if ($m[2] === 'pm' && $h < 12) { $h = $h + 12; }
            if ($m[2] === 'am' && $h === 12) { $h = 0; }
            $haveTime = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // zone offset: +02:00 / +0200 / +02
        if (\preg_match('/^([+-])(\d{2}):?(\d{2})/', $rest, $m) === 1 && ($haveTime === 1 || $haveDate === 1)) {
            $o = (int)$m[2] * 3600 + (int)$m[3] * 60;
            $zOff = $m[1] === '-' ? -$o : $o;
            $haveZone = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // named zone: europe/kyiv
        if (\preg_match('/^([a-z_]+\/[a-z_+-]+(?:\/[a-z_]+)?)/', $rest, $m) === 1) {
            $z2 = \__mc_tz_open_ci($m[1]);
            if ($z2 >= 0) {
                $useZid = $z2; $haveZone = 0; $matched = 1;
                $pos = $pos + \strlen($m[0]);
                continue;
            }
        }
        // relative: "+1 week", "3 days", "-2 months"
        if (\preg_match('/^([+-]?\d+)\s*(sec|secs|second|seconds|min|mins|minute|minutes|hour|hours|day|days|week|weeks|fortnight|fortnights|month|months|year|years|weekday|weekdays)\b/', $rest, $m) === 1) {
            $cnt = (int)$m[1];
            $u = \__mc_dt_unit($m[2]);
            if ($u === 1) { $relS = $relS + $cnt; }
            elseif ($u === 2) { $relI = $relI + $cnt; }
            elseif ($u === 3) { $relH = $relH + $cnt; }
            elseif ($u === 4) { $relD = $relD + $cnt; }
            elseif ($u === 5) { $relD = $relD + $cnt * 7; }
            elseif ($u === 6) { $relD = $relD + $cnt * 14; }
            elseif ($u === 7) { $relM = $relM + $cnt; }
            elseif ($u === 8) { $relY = $relY + $cnt; }
            $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // "first day of" / "last day of"
        if (\preg_match('/^(first|last)\s+day\s+of\b/', $rest, $m) === 1) {
            $firstLast = $m[1] === 'first' ? 1 : 2;
            $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // ordinal / next / last / this, either of a unit or of a weekday
        if (\preg_match('/^(next|last|previous|this|first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|eleventh|twelfth)\s+(sec|second|seconds|min|minute|minutes|hour|hours|day|days|week|weeks|fortnight|month|months|year|years|weekday|weekdays|sun|sunday|mon|monday|tue|tuesday|wed|wednesday|thu|thursday|fri|friday|sat|saturday)\b/', $rest, $m) === 1) {
            $cnt = \__mc_dt_ordinal($m[1]);
            $wd = \__mc_dt_weekday($m[2]);
            $isNext = $m[1] === 'next';
            $isLast = $m[1] === 'last' || $m[1] === 'previous';
            $isThis = $m[1] === 'this';
            if ($wd >= 0) {
                $relWd = $wd;
                $haveRelWd = 1;
                // "next monday" moves forward even when today IS Monday;
                // "last monday" strictly back; "this monday" stays. A numeric
                // ordinal ("first monday", "third tuesday") behaves like "next"
                // and then adds whole weeks.
                $relWdBehavior = ($isLast) ? -1 : (($isThis) ? 0 : 1);
                if (!$isNext && !$isLast && !$isThis) {
                    $relD = $relD + ($cnt - 1) * 7;
                }
                $h = $h === $UN ? 0 : $h; $mi = $mi === $UN ? 0 : $mi; $sec = $sec === $UN ? 0 : $sec;
            } else {
                $u = \__mc_dt_unit($m[2]);
                if ($u === 1) { $relS = $relS + $cnt; }
                elseif ($u === 2) { $relI = $relI + $cnt; }
                elseif ($u === 3) { $relH = $relH + $cnt; }
                elseif ($u === 4) { $relD = $relD + $cnt; }
                elseif ($u === 5) {
                    // Only next/last/previous/this pair with "week"; php
                    // rejects "first week" and friends outright.
                    if (!$isNext && !$isLast && !$isThis) {
                        return \__mc_dt_fail();
                    }
                    // "next week" is not simply +7 days: it also snaps to the
                    // MONDAY of the week it lands in (behavior 2), while
                    // leaving the time of day alone.
                    $relD = $relD + $cnt * 7;
                    $relWd = 1; $haveRelWd = 1; $relWdBehavior = 2;
                }
                elseif ($u === 6) { $relD = $relD + $cnt * 14; }
                elseif ($u === 7) { $relM = $relM + $cnt; }
                elseif ($u === 8) { $relY = $relY + $cnt; }
            }
            $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // bare weekday: "monday" — move forward to it, staying if already there
        if (\preg_match('/^(sunday|sun|monday|mon|tuesday|tues|tue|wednesday|wed|thursday|thurs|thur|thu|friday|fri|saturday|sat)\b/', $rest, $m) === 1) {
            $relWd = \__mc_dt_weekday($m[1]);
            $haveRelWd = 1; $relWdBehavior = 0;
            if ($h === $UN) { $h = 0; $mi = 0; $sec = 0; }
            $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // specials
        if (\preg_match('/^(now|today|midnight|noon|yesterday|tomorrow)\b/', $rest, $m) === 1) {
            $w = $m[1];
            if ($w === 'today' || $w === 'midnight') { $h = 0; $mi = 0; $sec = 0; $us = 0; }
            elseif ($w === 'noon') { $h = 12; $mi = 0; $sec = 0; $us = 0; }
            elseif ($w === 'yesterday') { $relD = $relD - 1; $h = 0; $mi = 0; $sec = 0; $us = 0; }
            elseif ($w === 'tomorrow') { $relD = $relD + 1; $h = 0; $mi = 0; $sec = 0; $us = 0; }
            $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // "ago" negates EVERY relative field accumulated so far
        if (\preg_match('/^ago\b/', $rest, $m) === 1) {
            $relY = -$relY; $relM = -$relM; $relD = -$relD;
            $relH = -$relH; $relI = -$relI; $relS = -$relS;
            $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // zone abbreviation / utc / gmt
        if (\preg_match('/^([a-z]{1,5})\b/', $rest, $m) === 1) {
            $o = \__mc_dt_abbroff($m[1]);
            if ($o !== $UN) {
                $zOff = $o; $haveZone = 1; $matched = 1;
                $pos = $pos + \strlen($m[0]);
                continue;
            }
        }
        // yyyymmdd, only as a whole token
        if (\preg_match('/^(\d{4})(\d{2})(\d{2})\b/', $rest, $m) === 1) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            $haveDate = 1; $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        // a bare 4-digit year, once a month is known
        if (\preg_match('/^(\d{4})\b/', $rest, $m) === 1 && $mo !== $UN && $y === $UN) {
            $y = (int)$m[1];
            $matched = 1;
            $pos = $pos + \strlen($m[0]);
            continue;
        }
        return \__mc_dt_fail();
    }

    if ($matched === 0) {
        return \__mc_dt_fail();
    }

    // Publish the RAW fields before the base fills them in — date_parse needs
    // to distinguish "the input said 2017" from "the base supplied 2017". An
    // "@epoch" is a RELATIVE offset from the epoch as far as php's date_parse
    // is concerned: it reports 1970-01-01 00:00:00 and carries the seconds
    // separately, so the epoch fields are corrected back to the epoch base.
    // One leaf call, not nine inline stores (see __mc_dt_publish).
    if ($publish === 1) {
        if ($isEpoch === 1) {
            \__mc_dt_publish(1970, 1, 1, 0, 0, 0, $us, $zOff, $haveZone);
        } else {
            \__mc_dt_publish($y, $mo, $d, $h, $mi, $sec, $us, $zOff, $haveZone);
        }
    }

    // Fill the unset fields from the base instant, in the zone that applies.
    $bl = $base + ($haveZone === 1 ? $zOff : \__mc_tz_offset($useZid, $base));
    $bdays = \__mc_fdiv($bl, 86400);
    $bsod = $bl - $bdays * 86400;
    $bp = \__mc_civil_from_days($bdays);
    if ($y === $UN) { $y = \__mc_civ_y($bp); }
    if ($mo === $UN) { $mo = \__mc_civ_m($bp); }
    if ($d === $UN) { $d = \__mc_civ_d($bp); }
    // A DATE with no time means midnight; a time-only or purely relative
    // expression keeps the base's time of day.
    if ($h === $UN) {
        if ($haveDate === 1) { $h = 0; $mi = 0; $sec = 0; }
        else { $h = \intdiv($bsod, 3600); $mi = \intdiv($bsod % 3600, 60); $sec = $bsod % 60; }
    }
    if ($mi === $UN) { $mi = 0; }
    if ($sec === $UN) { $sec = 0; }

    \__mc_dt_us(1, $us);
    return \__mc_dt_build($y, $mo, $d, $h, $mi, $sec, $us,
        $relY, $relM, $relD, $relH, $relI, $relS,
        $relWd, $relWdBehavior, $haveRelWd, $firstLast,
        $useZid, $haveZone, $zOff, 0);
}

/** Zone lookup that tolerates the lowercased text a parser sees. */
function __mc_tz_open_ci(string $name): int
{
    $z = \__mc_tz_open($name);
    if ($z >= 0) {
        return $z;
    }
    // "europe/kyiv" -> "Europe/Kyiv"
    $parts = \explode('/', $name);
    $out = '';
    foreach ($parts as $p) {
        if ($out !== '') { $out = $out . '/'; }
        $out = $out . \ucfirst($p);
    }
    return \__mc_tz_open($out);
}

/**
 * Parse an English textual datetime into a Unix timestamp, or false.
 */
function strtotime(string $datetime, ?int $baseTimestamp = null): int|false
{
    $base = $baseTimestamp === null ? \time() : $baseTimestamp;
    $zid = \__mc_tz_open(\date_default_timezone_get());
    $r = \__mc_strtotime_core($datetime, $base, $zid, 0);
    if ($r === \__mc_dt_fail()) {
        return false;
    }
    return $r;
}
