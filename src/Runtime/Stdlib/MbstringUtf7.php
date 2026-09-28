<?php

/**
 * UTF-7 (RFC 2152) and UTF7-IMAP (RFC 3501 mailbox names) for mbstring — a
 * transcription of libmbfl's mbfilter_utf7.c / mbfilter_utf7imap.c, because the
 * error behaviour is observable and host iconv has neither exactly (musl has no
 * UTF-7 at all). `$imap` switches the dialect: '&' instead of '+' opens Base64,
 * ',' replaces '/', every Base64 section closes with '-', printable ASCII may
 * not be Base64-encoded.
 *
 * Base64 characters decode to 0..63; the four ways a Base64 section can end
 * decode above that and are ordered: DASH 0xFC, DIRECT 0xFD, ASCII 0xFE, ILLEGAL 0xFF.
 */

/** Value of one Base64 character, or DASH / DIRECT / ASCII / ILLEGAL. */
function __mc_mb_utf7_b64(int $c, bool $imap): int
{
    if ($c >= 0x41 && $c <= 0x5A) { return $c - 65; }
    if ($c >= 0x61 && $c <= 0x7A) { return $c - 71; }
    if ($c >= 0x30 && $c <= 0x39) { return $c + 4; }
    if ($c === 0x2B) { return 62; }
    if ($c === ($imap ? 0x2C : 0x2F)) { return 63; }
    if ($c === 0x2D) { return 0xFC; }
    if ($imap) { return 0xFF; }
    if (\__mc_mb_utf7_can_end($c) || \__mc_mb_utf7_optional($c) || $c === 0) { return 0xFD; }
    return $c <= 0x7F ? 0xFE : 0xFF;
}

/** Characters that end a UTF-7 Base64 section without a '-'. */
function __mc_mb_utf7_can_end(int $c): bool
{
    return $c === 0x20 || $c === 0x09 || $c === 0x0D || $c === 0x0A || $c === 0x27 || $c === 0x28
        || $c === 0x29 || $c === 0x2C || $c === 0x2E || $c === 0x3A || $c === 0x3F;
}

/** UTF-7's "optional direct" characters: allowed either directly or in Base64. */
function __mc_mb_utf7_optional(int $c): bool
{
    return \str_contains("!\"#\$%&*;<=>@[]^_`{|}", \chr($c & 0xFF)) && $c <= 0x7F;
}

/** Characters the UTF-7 encoder writes directly. */
function __mc_mb_utf7_direct(int $c): bool
{
    return ($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A) || ($c >= 0x30 && $c <= 0x39)
        || $c === 0 || $c === 0x2F || $c === 0x2D || \__mc_mb_utf7_can_end($c);
}

/**
 * Marked UTF-8 for one decoded UTF-16 unit, given the pending high surrogate
 * `$sur` (0 = none). The pending state after it is simply `$cp` when `$cp` is a
 * high surrogate and 0 otherwise — the caller keeps that.
 */
function __mc_mb_utf7_unit(int $cp, int $sur, bool $imap): string
{
    $out = "";
    if ($sur !== 0) {
        if ($cp >= 0xDC00 && $cp <= 0xDFFF) { return \__mc_mb_u8_chr((($sur & 0x3FF) << 10) + ($cp & 0x3FF) + 0x10000); }
        $out = "\xFF";
    }
    if ($cp >= 0xD800 && $cp <= 0xDBFF) { return $out; }
    if ($cp >= 0xDC00 && $cp <= 0xDFFF) { return $out . "\xFF"; }
    if ($imap && $cp >= 0x20 && $cp <= 0x7E && $cp !== 0x26) { return $out . "\xFF"; }
    return $out . \__mc_mb_u8_chr($cp);
}

/** The pending high surrogate after unit `$cp`. */
function __mc_mb_utf7_pending(int $cp): int
{
    return $cp >= 0xD800 && $cp <= 0xDBFF ? $cp : 0;
}

/**
 * Marked UTF-8 emitted where a Base64 section ends on end-character `$n`;
 * `$abrupt` = it ended where a unit was incomplete. The pending surrogate is
 * always cleared by it; UTF-7 (not IMAP) also un-consumes a DIRECT / ASCII
 * terminator — the caller does that.
 */
function __mc_mb_utf7_end(int $n, bool $abrupt, int $sur, bool $imap): string
{
    if ($imap) { return $abrupt || $n === 0xFF || $sur !== 0 ? "\xFF" : ""; }
    $out = $abrupt || $sur !== 0 ? "\xFF" : "";
    return $n === 0xFF ? $out . "\xFF" : $out;
}

/** Decode UTF-7 / UTF7-IMAP to marked UTF-8. */
function __mc_mb_utf7_dec8(string $s, bool $imap): string
{
    $e = \strlen($s);
    $out = "";
    $p = 0;
    $b64 = false;
    $sur = 0;
    while ($p < $e) {
        if (!$b64) {
            $c = \ord($s[$p]);
            $p = $p + 1;
            $open = $imap ? 0x26 : 0x2B;
            if ($c === $open) {
                if ($p < $e && $s[$p] === "-") {
                    $out = $out . \chr($open);
                    $p = $p + 1;
                } elseif ($p < $e || $imap) {
                    $b64 = true;
                }
            } elseif ($imap ? ($c >= 0x20 && $c <= 0x7E) : $c <= 0x7F) {
                $out = $out . \chr($c);
            } else {
                $out = $out . "\xFF";
            }
            continue;
        }
        // One Base64 group: up to 8 characters carrying three 16-bit units. Each
        // step reads a character and may end the section; `$abrupt` says whether
        // ending there leaves a unit incomplete.
        $n = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        $k = 1;
        $ended = false;
        while ($k <= 8) {
            $n[$k] = \__mc_mb_utf7_b64(\ord($s[$p]), $imap);
            $p = $p + 1;
            $abrupt = $k !== 1 && $k !== 4 && $k !== 7;
            if ($k === 4) { $abrupt = ($n[3] & 0x3) !== 0; }
            if ($k === 7) { $abrupt = ($n[6] & 0xF) !== 0; }
            $atEnd = $p === $e && $k !== 3 && $k !== 6 && $k !== 8;
            if ($n[$k] >= 0xFC || $atEnd) {
                $out = $out . \__mc_mb_utf7_end($n[$k], $n[$k] >= 0xFC ? $abrupt : true, $sur, $imap);
                $sur = 0;
                $b64 = false;
                if (!$imap && ($n[$k] === 0xFD || $n[$k] === 0xFE)) { $p = $p - 1; }
                $ended = true;
                break;
            }
            $unit = -1;
            if ($k === 3) { $unit = (($n[1] << 10) | ($n[2] << 4) | (($n[3] & 0x3C) >> 2)) & 0xFFFF; }
            if ($k === 6) { $unit = (($n[3] << 14) | ($n[4] << 8) | ($n[5] << 2) | (($n[6] & 0x30) >> 4)) & 0xFFFF; }
            if ($k === 8) { $unit = (($n[6] << 12) | ($n[7] << 6) | $n[8]) & 0xFFFF; }
            if ($unit >= 0) {
                $out = $out . \__mc_mb_utf7_unit($unit, $sur, $imap);
                $sur = \__mc_mb_utf7_pending($unit);
                if ($k !== 8 && $p === $e) {
                    $pad = $k === 3 ? $n[3] & 0x3 : $n[6] & 0xF;
                    if ($pad !== 0 || $sur !== 0) {
                        $out = $out . "\xFF";
                        if (!$imap) { $sur = 0; }
                    }
                    $ended = true;
                    break;
                }
            }
            $k = $k + 1;
        }
        if ($ended && $p === $e) { break; }
    }
    if ($imap ? $b64 : $sur !== 0) { $out = $out . "\xFF"; }
    return $out;
}

/** One Base64 character of the dialect. */
function __mc_mb_utf7_b64chr(int $v, bool $imap): string
{
    if ($v < 26) { return \chr(65 + $v); }
    if ($v < 52) { return \chr(71 + $v); }
    if ($v < 62) { return \chr($v - 4); }
    if ($v === 62) { return "+"; }
    return $imap ? "," : "/";
}

/**
 * Encode codepoints (-1 = bad input) as UTF-7 / UTF7-IMAP. A unit it cannot
 * spell (bad input, or past U+10FFFF) is replaced IN-STREAM by its substitute
 * characters, as Zend's recursive error call does; `$strict` answers null instead.
 * @param int[] $cps
 */
function __mc_mb_utf7_enc(array $cps, bool $imap, bool $strict): ?string
{
    $out = "";
    $b64 = false;
    $nbits = 0;
    $cache = 0;
    $queue = $cps;
    $i = 0;
    while ($i < \count($queue)) {
        $w = $queue[$i];
        if ($w < 0 || $w >= 0x110000) {
            if ($strict) { return null; }
            \array_splice($queue, $i, 1, \__mc_mb_subst_cps($w));
            continue;
        }
        if ($b64) {
            $ends = $imap ? ($w >= 0x20 && $w <= 0x7E) : \__mc_mb_utf7_direct($w);
            if ($ends) {
                $b64 = false;
                if ($nbits > 0) { $out = $out . \__mc_mb_utf7_b64chr(($cache << (6 - $nbits)) & 0x3F, $imap); }
                $nbits = 0;
                $cache = 0;
                if ($imap || !\__mc_mb_utf7_can_end($w)) { $out = $out . "-"; }
                continue;
            }
            if ($w >= 0x10000) {
                $v = $w - 0x10000;
                $bits = ($cache << 32) | 0xD800DC00 | (($v & 0xFFC00) << 6) | ($v & 0x3FF);
                $nbits = $nbits + 32;
            } else {
                $bits = ($cache << 16) | $w;
                $nbits = $nbits + 16;
            }
            while ($nbits >= 6) {
                $out = $out . \__mc_mb_utf7_b64chr(($bits >> ($nbits - 6)) & 0x3F, $imap);
                $nbits = $nbits - 6;
            }
            $cache = $bits & 0xFF;
            $i = $i + 1;
            continue;
        }
        if ($imap && $w === 0x26) {
            $out = $out . "&-";
        } elseif ($imap ? ($w >= 0x20 && $w <= 0x7E) : \__mc_mb_utf7_direct($w)) {
            $out = $out . \chr($w);
        } else {
            $out = $out . ($imap ? "&" : "+");
            $b64 = true;
            continue;
        }
        $i = $i + 1;
    }
    if ($nbits > 0) { $out = $out . \__mc_mb_utf7_b64chr(($cache << (6 - $nbits)) & 0x3F, $imap); }
    if ($b64) { $out = $out . "-"; }
    return $out;
}

/** A decoded UTF-16 unit is acceptable to the validator (after a high surrogate: only a low one). */
function __mc_mb_utf7_unit_ok(int $cp, bool $sur, bool $imap): bool
{
    if ($sur) { return $cp >= 0xDC00 && $cp <= 0xDFFF; }
    if ($cp >= 0xDC00 && $cp <= 0xDFFF) { return false; }
    return !($imap && $cp >= 0x20 && $cp <= 0x7E && $cp !== 0x26);
}

/** Zend's mb_check_utf7 / mb_check_utf7imap — stricter than the decoder. */
function __mc_mb_utf7_check(string $s, bool $imap): bool
{
    $e = \strlen($s);
    $p = 0;
    $b64 = false;
    $sur = false;
    while ($p < $e) {
        if ($b64) {
            $n1 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n1 >= 0xFC) {
                if ($sur || $n1 === 0xFE || $n1 === 0xFF) { return false; }
                $b64 = false;
                continue;
            }
            if ($p === $e) { return false; }
            $n2 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n2 >= 0xFC || $p === $e) { return false; }
            $n3 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n3 >= 0xFC) { return false; }
            $cp = (($n1 << 10) | ($n2 << 4) | (($n3 & 0x3C) >> 2)) & 0xFFFF;
            if (!\__mc_mb_utf7_unit_ok($cp, $sur, $imap)) { return false; }
            $sur = !$sur && $cp >= 0xD800 && $cp <= 0xDBFF;
            if ($p === $e) { return $imap ? false : !(($n3 & 0x3) !== 0 || $sur); }
            $n4 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n4 >= 0xFC) {
                if (($n3 & 0x3) !== 0 || $sur || $n4 === 0xFE || $n4 === 0xFF) { return false; }
                $b64 = false;
                continue;
            }
            if ($p === $e) { return false; }
            $n5 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n5 >= 0xFC || $p === $e) { return false; }
            $n6 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n6 >= 0xFC) { return false; }
            $cp = (($n3 << 14) | ($n4 << 8) | ($n5 << 2) | (($n6 & 0x30) >> 4)) & 0xFFFF;
            if (!\__mc_mb_utf7_unit_ok($cp, $sur, $imap)) { return false; }
            $sur = !$sur && $cp >= 0xD800 && $cp <= 0xDBFF;
            if ($p === $e) { return $imap ? false : !(($n6 & 0xF) !== 0 || $sur); }
            $n7 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n7 >= 0xFC) {
                if (($n6 & 0xF) !== 0 || $sur || $n7 === 0xFE || $n7 === 0xFF) { return false; }
                $b64 = false;
                continue;
            }
            if ($p === $e) { return false; }
            $n8 = \__mc_mb_utf7_b64(\ord($s[$p++]), $imap);
            if ($n8 >= 0xFC) { return false; }
            $cp = (($n6 << 12) | ($n7 << 6) | $n8) & 0xFFFF;
            if (!\__mc_mb_utf7_unit_ok($cp, $sur, $imap)) { return false; }
            $sur = !$sur && $cp >= 0xD800 && $cp <= 0xDBFF;
            continue;
        }
        $c = \ord($s[$p++]);
        if ($c === ($imap ? 0x26 : 0x2B)) {
            if ($p === $e) {
                if ($imap) { return false; }
                return !$sur;
            }
            $n = \__mc_mb_utf7_b64(\ord($s[$p]), $imap);
            if ($n === 0xFC) {
                $p = $p + 1;
            } elseif ($imap ? $n === 0xFF : $n > 0xFC) {
                return false;
            } else {
                $b64 = true;
            }
        } elseif ($imap ? ($c >= 0x20 && $c <= 0x7E) : (\__mc_mb_utf7_direct($c) || \__mc_mb_utf7_optional($c))) {
            continue;
        } else {
            return false;
        }
    }
    return $imap ? !$b64 : !$sur;
}
