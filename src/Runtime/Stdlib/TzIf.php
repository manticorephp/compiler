<?php

/**
 * TZif reader and zone registry.
 *
 * PHP needs named zones with real DST and real historical transitions, so this
 * parses the system tz database (`/usr/share/zoneinfo/<Name>`, RFC 8536 TZif
 * v1/v2/v3) directly. No libc `tzset`, no process-global TZ, no per-call env
 * mutation — a zone is opened once, parsed once, and thereafter addressed by an
 * integer handle.
 *
 * THE BOUNDARY RULE: every entry point here takes and returns SCALARS. All
 * state lives in `__mc_tz`'s statics and is read one int at a time. That is
 * what lets the DateTime classes live in the prelude and talk to this file
 * without an array or an object ever crossing the stdlib call boundary.
 *
 * Two consequences worth spelling out:
 *   - Time-zone ABBREVIATIONS are packed base-256 into an i64 ("EEST" ->
 *     0x45455354). They are at most 6 ASCII bytes by the RFC, so the whole
 *     registry stays integer-only and no string table has to cross anything.
 *   - The POSIX TZ footer is parsed to ints at open time, never kept as a
 *     string, and its two rules are appended to the zone's ttinfo table as
 *     ordinary types. A timestamp past the last recorded transition then
 *     resolves through the same code path as any other.
 *
 * BINARY SAFETY: a TZif buffer is full of NUL bytes. `printf("%s")` truncates
 * at the first one, so this file touches buffers only through `strlen()` and
 * `ord()` — never echo, never interpolate, never var_dump one. Debug through
 * `bin2hex()`.
 */

/** Big-endian unsigned byte. */
function __mc_be_u8(string $s, int $o): int
{
    return \ord($s[$o]);
}

/** Big-endian signed 32-bit. */
function __mc_be_i32(string $s, int $o): int
{
    $v = \ord($s[$o]) * 16777216 + \ord($s[$o + 1]) * 65536 + \ord($s[$o + 2]) * 256 + \ord($s[$o + 3]);
    if ($v >= 2147483648) {
        return $v - 4294967296;
    }
    return $v;
}

/**
 * Big-endian signed 64-bit. The high word is sign-extended FIRST and then
 * MULTIPLIED — never shifted. A shift here is the documented path into the
 * int-overflow-to-float wrap, and it would silently corrupt every transition
 * time before 1901.
 */
function __mc_be_i64(string $s, int $o): int
{
    $hi = \__mc_be_i32($s, $o);
    $lo = \ord($s[$o + 4]) * 16777216 + \ord($s[$o + 5]) * 65536 + \ord($s[$o + 6]) * 256 + \ord($s[$o + 7]);
    return $hi * 4294967296 + $lo;
}

/** Pack a NUL-terminated abbreviation at $o into an i64, base 256. */
function __mc_tz_packabbr(string $s, int $o): int
{
    $p = 0;
    $n = \strlen($s);
    for ($i = $o; $i < $n; $i++) {
        $c = \ord($s[$i]);
        if ($c === 0) {
            break;
        }
        $p = $p * 256 + $c;
    }
    return $p;
}

/** Unpack an i64-packed abbreviation back to a string. */
function __mc_tz_unpackabbr(int $p): string
{
    if ($p === 0) {
        return '';
    }
    $out = '';
    while ($p > 0) {
        $out = \chr($p % 256) . $out;
        $p = \intdiv($p, 256);
    }
    return $out;
}

/** A zone name must be a relative path of tzdb-shaped components. */
function __mc_tz_valid_name(string $n): bool
{
    $len = \strlen($n);
    if ($len === 0 || $len > 96) {
        return false;
    }
    if ($n[0] === '/' || \strpos($n, '..') !== false) {
        return false;
    }
    for ($i = 0; $i < $len; $i++) {
        $c = \ord($n[$i]);
        $ok = ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || ($c >= 48 && $c <= 57)
            || $c === 47 || $c === 95 || $c === 45 || $c === 43;
        if (!$ok) {
            return false;
        }
    }
    return true;
}

/**
 * Parse a POSIX TZ offset "[+-]hh[:mm[:ss]]" to seconds, as written. The caller
 * negates it: POSIX states the offset to ADD TO LOCAL to reach UTC, which is
 * the opposite sign from everything else here ("EET-2" means UTC+2).
 */
function __mc_tz_poff(string $s): int
{
    $n = \strlen($s);
    if ($n === 0) {
        return 0;
    }
    $i = 0;
    $sign = 1;
    if ($s[0] === '-') {
        $sign = -1;
        $i = 1;
    } elseif ($s[0] === '+') {
        $i = 1;
    }
    $part = 0;
    $val = 0;
    $acc = 0;
    $seen = false;
    for (; $i <= $n; $i++) {
        $c = $i < $n ? \ord($s[$i]) : 58;
        if ($c >= 48 && $c <= 57) {
            $acc = $acc * 10 + ($c - 48);
            $seen = true;
            continue;
        }
        if ($seen) {
            if ($part === 0) {
                $val = $val + $acc * 3600;
            } elseif ($part === 1) {
                $val = $val + $acc * 60;
            } else {
                $val = $val + $acc;
            }
        }
        $acc = 0;
        $seen = false;
        $part = $part + 1;
        if ($c !== 58 || $part > 2) {
            break;
        }
    }
    return $sign * $val;
}

/** Length of the abbreviation token at $p — either <...> or a run of letters. */
function __mc_tz_abbrlen(string $s, int $p): int
{
    $n = \strlen($s);
    if ($p >= $n) {
        return 0;
    }
    if ($s[$p] === '<') {
        $i = $p + 1;
        while ($i < $n && $s[$i] !== '>') {
            $i++;
        }
        return $i - $p + 1;
    }
    $i = $p;
    while ($i < $n) {
        $c = \ord($s[$i]);
        if (($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122)) {
            $i++;
            continue;
        }
        break;
    }
    return $i - $p;
}

/** Strip the <> from a quoted POSIX abbreviation. */
function __mc_tz_abbrtext(string $tok): string
{
    if (\strlen($tok) >= 2 && $tok[0] === '<') {
        return \substr($tok, 1, \strlen($tok) - 2);
    }
    return $tok;
}

/**
 * UTC instant of a POSIX DST rule firing in year $y.
 *
 * $mode 0 = "Mm.w.d" (month, week 1-5 where 5 means LAST, weekday 0=Sunday),
 * 1 = "Jn" (1-based day of year, February 29 never counted), 2 = "n" (0-based
 * day of year, February 29 counted). $secs is the local wall-clock time of day;
 * $gmtoff is the offset in force BEFORE the transition.
 */
function __mc_tz_ruleutc(int $y, int $mode, int $m, int $w, int $d, int $secs, int $gmtoff): int
{
    if ($mode === 1) {
        $doy = $m;
        if ($doy > 59 && \__mc_is_leap($y)) {
            $doy = $doy + 1;
        }
        $z = \__mc_days_from_civil($y, 1, 1) + ($doy - 1);
    } elseif ($mode === 2) {
        $z = \__mc_days_from_civil($y, 1, 1) + $m;
    } else {
        $first = \__mc_days_from_civil($y, $m, 1);
        $shift = \__mc_fmod($d - \__mc_dow($first), 7);
        $z = $first + $shift + ($w - 1) * 7;
        if ($w === 5) {
            // "Last" — back off a week at a time until inside the month.
            $last = $first + \__mc_days_in_month($y, $m) - 1;
            while ($z > $last) {
                $z = $z - 7;
            }
        }
    }
    return $z * 86400 + $secs - $gmtoff;
}

/**
 * Zone registry. All ops take and return ints; $name is used by op 0 only.
 *
 *   op  0  open($name)                -> zid, or -1 if unknown
 *   op  1  transition count($a=zid)
 *   op  2  transition time  (zid=$a, i=$b)
 *   op  3  transition type  (zid=$a, i=$b)
 *   op  4  ttinfo gmtoff    (zid=$a, t=$b)
 *   op  5  ttinfo isdst     (zid=$a, t=$b)
 *   op  6  ttinfo abbrev PACKED (zid=$a, t=$b)
 *   op  7  posix field      (zid=$a, k=$b)
 *   op  8  type for UTC instant   (zid=$a, ts=$b)
 *   op  9  type for LOCAL instant (zid=$a, ts=$b)
 *   op 10  gmtoff for UTC instant  (zid=$a, ts=$b)   -- fused 8+4
 *   op 11  ttinfo count($a=zid)
 */
function __mc_tz(int $op, int $a, int $b, string $name = ''): int
{
    static $byName = [];
    static $zTrBase = [];
    static $zTrN = [];
    static $zTtBase = [];
    static $zTtN = [];
    static $zPxBase = [];
    static $trAt = [];
    static $trTy = [];
    static $ttOff = [];
    static $ttDst = [];
    static $ttAbbr = [];
    static $px = [];

    if ($op === 0) {
        if (isset($byName[$name])) {
            return $byName[$name];
        }
        if (!\__mc_tz_valid_name($name)) {
            return -1;
        }
        $buf = '';
        $path = \__mc_tzdir() . '/' . $name;
        // file_get_contents is string|false; settle it before anything else
        // touches the value, so no cell-typed local ever reaches the parser.
        $raw = \file_get_contents($path);
        if ($raw === false) {
            return -1;
        }
        $buf = $raw;
        $len = \strlen($buf);
        if ($len < 44 || $buf[0] !== 'T' || $buf[1] !== 'Z' || $buf[2] !== 'i' || $buf[3] !== 'f') {
            return -1;
        }

        $ver = \ord($buf[4]);
        $o = 0;
        $wide = 4;
        if ($ver >= 50) {
            // Version '2' or later: the v1 block is a 32-bit compatibility copy.
            // Skip it whole and read the 64-bit block that follows.
            $isutcnt = \__mc_be_i32($buf, 20);
            $isstdcnt = \__mc_be_i32($buf, 24);
            $leapcnt = \__mc_be_i32($buf, 28);
            $timecnt = \__mc_be_i32($buf, 32);
            $typecnt = \__mc_be_i32($buf, 36);
            $charcnt = \__mc_be_i32($buf, 40);
            $o = 44 + $timecnt * 5 + $typecnt * 6 + $charcnt + $leapcnt * 8 + $isstdcnt + $isutcnt;
            if ($o + 44 > $len) {
                return -1;
            }
            $wide = 8;
        }

        $isutcnt = \__mc_be_i32($buf, $o + 20);
        $isstdcnt = \__mc_be_i32($buf, $o + 24);
        $leapcnt = \__mc_be_i32($buf, $o + 28);
        $timecnt = \__mc_be_i32($buf, $o + 32);
        $typecnt = \__mc_be_i32($buf, $o + 36);
        $charcnt = \__mc_be_i32($buf, $o + 40);
        if ($typecnt < 1 || $timecnt < 0 || $charcnt < 0) {
            return -1;
        }

        $p = $o + 44;
        $trBase = \count($trAt);
        for ($i = 0; $i < $timecnt; $i++) {
            $trAt[] = $wide === 8 ? \__mc_be_i64($buf, $p) : \__mc_be_i32($buf, $p);
            $p = $p + $wide;
        }
        for ($i = 0; $i < $timecnt; $i++) {
            $trTy[] = \ord($buf[$p]);
            $p = $p + 1;
        }
        $ttBase = \count($ttOff);
        $typeP = $p;
        $charP = $p + $typecnt * 6;
        for ($i = 0; $i < $typecnt; $i++) {
            $ttOff[] = \__mc_be_i32($buf, $typeP);
            $ttDst[] = \ord($buf[$typeP + 4]);
            $ttAbbr[] = \__mc_tz_packabbr($buf, $charP + \ord($buf[$typeP + 5]));
            $typeP = $typeP + 6;
        }
        $ttN = $typecnt;

        // ---- POSIX footer. Present on v2+ only; governs everything past the
        // last recorded transition, which is what makes a query in 2040 right.
        $pxBase = \count($px);
        for ($i = 0; $i < 16; $i++) {
            $px[] = 0;
        }
        if ($wide === 8) {
            $fp = $charP + $charcnt + $leapcnt * 12 + $isstdcnt + $isutcnt;
            if ($fp < $len && $buf[$fp] === "\n") {
                $fe = \strpos($buf, "\n", $fp + 1);
                if ($fe !== false) {
                    $spec = \substr($buf, $fp + 1, $fe - $fp - 1);
                    if (\strlen($spec) > 0) {
                        // "EET-2EEST,M3.5.0/3,M10.5.0/4"
                        $cut1 = \strpos($spec, ',');
                        $head = $cut1 === false ? $spec : \substr($spec, 0, $cut1);
                        $q = 0;
                        $stdTok = \substr($head, $q, \__mc_tz_abbrlen($head, $q));
                        $q = $q + \strlen($stdTok);
                        $ns = \strlen($head);
                        $os = $q;
                        while ($q < $ns) {
                            $c = \ord($head[$q]);
                            if (($c >= 48 && $c <= 57) || $c === 43 || $c === 45 || $c === 58) {
                                $q++;
                                continue;
                            }
                            break;
                        }
                        // POSIX sign is inverted relative to gmtoff.
                        $stdOff = -\__mc_tz_poff(\substr($head, $os, $q - $os));
                        $dstTok = '';
                        $dstOff = $stdOff + 3600;
                        if ($q < $ns) {
                            $dstTok = \substr($head, $q, \__mc_tz_abbrlen($head, $q));
                            $q = $q + \strlen($dstTok);
                            $od = $q;
                            while ($q < $ns) {
                                $c = \ord($head[$q]);
                                if (($c >= 48 && $c <= 57) || $c === 43 || $c === 45 || $c === 58) {
                                    $q++;
                                    continue;
                                }
                                break;
                            }
                            if ($q > $od) {
                                $dstOff = -\__mc_tz_poff(\substr($head, $od, $q - $od));
                            }
                        }

                        $ttOff[] = $stdOff;
                        $ttDst[] = 0;
                        $ttAbbr[] = \__mc_tz_packabbr(\__mc_tz_abbrtext($stdTok) . "\0", 0);
                        $stdIdx = $ttN;
                        $ttN = $ttN + 1;
                        $dstIdx = -1;
                        if ($dstTok !== '') {
                            $ttOff[] = $dstOff;
                            $ttDst[] = 1;
                            $ttAbbr[] = \__mc_tz_packabbr(\__mc_tz_abbrtext($dstTok) . "\0", 0);
                            $dstIdx = $ttN;
                            $ttN = $ttN + 1;
                        }

                        $px[$pxBase] = $dstTok === '' ? 1 : 2;
                        $px[$pxBase + 1] = $stdOff;
                        $px[$pxBase + 2] = $dstOff;
                        $px[$pxBase + 3] = $stdIdx;
                        $px[$pxBase + 4] = $dstIdx;
                        // Defaults if the rules are omitted: US practice.
                        $px[$pxBase + 5] = 0;
                        $px[$pxBase + 6] = 3;
                        $px[$pxBase + 7] = 2;
                        $px[$pxBase + 8] = 0;
                        $px[$pxBase + 9] = 7200;
                        $px[$pxBase + 10] = 0;
                        $px[$pxBase + 11] = 11;
                        $px[$pxBase + 12] = 1;
                        $px[$pxBase + 13] = 0;
                        $px[$pxBase + 14] = 7200;
                        if ($cut1 !== false) {
                            $rest = \substr($spec, $cut1 + 1);
                            $cut2 = \strpos($rest, ',');
                            $r1 = $cut2 === false ? $rest : \substr($rest, 0, $cut2);
                            $r2 = $cut2 === false ? '' : \substr($rest, $cut2 + 1);
                            for ($k = 0; $k < 2; $k++) {
                                $r = $k === 0 ? $r1 : $r2;
                                if ($r === '') {
                                    continue;
                                }
                                $slash = \strpos($r, '/');
                                $spec2 = $slash === false ? $r : \substr($r, 0, $slash);
                                $tsec = 7200;
                                if ($slash !== false) {
                                    $tsec = \__mc_tz_poff(\substr($r, $slash + 1));
                                }
                                $mode = 0;
                                $mm = 0;
                                $ww = 0;
                                $dd = 0;
                                if ($spec2[0] === 'M') {
                                    $body = \substr($spec2, 1);
                                    $d1 = \strpos($body, '.');
                                    $mm = (int)\substr($body, 0, $d1);
                                    $tail = \substr($body, $d1 + 1);
                                    $d2 = \strpos($tail, '.');
                                    $ww = (int)\substr($tail, 0, $d2);
                                    $dd = (int)\substr($tail, $d2 + 1);
                                } elseif ($spec2[0] === 'J') {
                                    $mode = 1;
                                    $mm = (int)\substr($spec2, 1);
                                } else {
                                    $mode = 2;
                                    $mm = (int)$spec2;
                                }
                                $base = $pxBase + ($k === 0 ? 5 : 10);
                                $px[$base] = $mode;
                                $px[$base + 1] = $mm;
                                $px[$base + 2] = $ww;
                                $px[$base + 3] = $dd;
                                $px[$base + 4] = $tsec;
                            }
                        }
                    }
                }
            }
        }

        $zid = \count($zTrBase);
        $zTrBase[] = $trBase;
        $zTrN[] = $timecnt;
        $zTtBase[] = $ttBase;
        $zTtN[] = $ttN;
        $zPxBase[] = $pxBase;
        $byName[$name] = $zid;
        return $zid;
    }

    if ($a < 0 || $a >= \count($zTrBase)) {
        return 0;
    }

    if ($op === 1) {
        return $zTrN[$a];
    }
    if ($op === 2) {
        return $trAt[$zTrBase[$a] + $b];
    }
    if ($op === 3) {
        return $trTy[$zTrBase[$a] + $b];
    }
    if ($op === 4) {
        return $ttOff[$zTtBase[$a] + $b];
    }
    if ($op === 5) {
        return $ttDst[$zTtBase[$a] + $b];
    }
    if ($op === 6) {
        return $ttAbbr[$zTtBase[$a] + $b];
    }
    if ($op === 7) {
        return $px[$zPxBase[$a] + $b];
    }
    if ($op === 11) {
        return $zTtN[$a];
    }

    if ($op === 8 || $op === 10) {
        $n = $zTrN[$a];
        $base = $zTrBase[$a];
        $pxb = $zPxBase[$a];
        $ty = -1;
        if ($n === 0 || $b < $trAt[$base]) {
            // Before the first recorded transition: tzcode uses the first
            // non-DST type, falling back to type 0.
            $ty = 0;
            $tn = $zTtN[$a];
            for ($i = 0; $i < $tn; $i++) {
                if ($ttDst[$zTtBase[$a] + $i] === 0) {
                    $ty = $i;
                    break;
                }
            }
        } elseif ($px[$pxb] !== 0 && $b >= $trAt[$base + $n - 1]) {
            // Past the last recorded transition: the POSIX footer rules.
            $ty = \__mc_tz_posixtype($a, $b);
        } else {
            $lo = 0;
            $hi = $n - 1;
            while ($lo < $hi) {
                $mid = \intdiv($lo + $hi + 1, 2);
                if ($trAt[$base + $mid] <= $b) {
                    $lo = $mid;
                } else {
                    $hi = $mid - 1;
                }
            }
            $ty = $trTy[$base + $lo];
        }
        if ($op === 10) {
            return $ttOff[$zTtBase[$a] + $ty];
        }
        return $ty;
    }

    if ($op === 9) {
        // Local wall clock -> type. A candidate type t is consistent when
        // resolving (local - gmtoff(t)) as UTC lands back on t.
        $t1 = \__mc_tz(8, $a, $b - \__mc_tz(10, $a, $b));
        $c1 = $b - $ttOff[$zTtBase[$a] + $t1];
        $ok1 = \__mc_tz(8, $a, $c1) === $t1;
        $t2 = \__mc_tz(8, $a, $c1);
        $c2 = $b - $ttOff[$zTtBase[$a] + $t2];
        $ok2 = \__mc_tz(8, $a, $c2) === $t2;
        if ($ok1 && $ok2 && $t1 !== $t2) {
            // Ambiguous (fall-back fold): PHP takes the FIRST occurrence,
            // i.e. the one still on DST.
            if ($ttDst[$zTtBase[$a] + $t1] === 1) {
                return $t1;
            }
            return $t2;
        }
        if ($ok2) {
            return $t2;
        }
        if ($ok1) {
            return $t1;
        }
        // Non-existent (spring-forward gap): neither candidate is consistent.
        // The offset in force BEFORE the transition wins, which pushes the wall
        // clock forward past the gap. A gap always means the offset steps UP,
        // so the pre-transition type is the one with the SMALLER gmtoff.
        if ($ttOff[$zTtBase[$a] + $t2] < $ttOff[$zTtBase[$a] + $t1]) {
            return $t2;
        }
        return $t1;
    }

    return 0;
}

/** POSIX-footer type for a UTC instant past the last recorded transition. */
function __mc_tz_posixtype(int $zid, int $ts): int
{
    $has = \__mc_tz(7, $zid, 0);
    $stdIdx = \__mc_tz(7, $zid, 3);
    if ($has < 2) {
        return $stdIdx;
    }
    $dstIdx = \__mc_tz(7, $zid, 4);
    $stdOff = \__mc_tz(7, $zid, 1);
    $dstOff = \__mc_tz(7, $zid, 2);
    $p = \__mc_civil_from_days(\__mc_fdiv($ts, 86400));
    $y = \__mc_civ_y($p);
    $start = \__mc_tz_ruleutc($y, \__mc_tz(7, $zid, 5), \__mc_tz(7, $zid, 6), \__mc_tz(7, $zid, 7),
        \__mc_tz(7, $zid, 8), \__mc_tz(7, $zid, 9), $stdOff);
    $end = \__mc_tz_ruleutc($y, \__mc_tz(7, $zid, 10), \__mc_tz(7, $zid, 11), \__mc_tz(7, $zid, 12),
        \__mc_tz(7, $zid, 13), \__mc_tz(7, $zid, 14), $dstOff);
    if ($start <= $end) {
        // Northern hemisphere: DST is the interval inside the year.
        if ($ts >= $start && $ts < $end) {
            return $dstIdx;
        }
        return $stdIdx;
    }
    // Southern hemisphere: DST wraps the new year.
    if ($ts >= $start || $ts < $end) {
        return $dstIdx;
    }
    return $stdIdx;
}
