<?php

/**
 * Runtime JSON encoder — a real PHP implementation of json_encode, linked
 * into every compiled binary (replacing the former Rust-runtime stub;
 * extensions/json.php carries only the type signatures). Global namespace
 * so an unqualified `json_encode()` in any compiler namespace resolves
 * here. Mirrors the recursive walker proven in tests/aot/cases/
 * json_roundtrip.php.
 *
 * The matching `json_decode` parser (tests/aot/cases/json_object.php) is
 * NOT here yet: it uses `$o->$key = …` dynamic-property stores, which the
 * AST bootstrap backend can't lower. It lands the moment `bin/compile`
 * flips to the MIR backend (which supports stdClass + dynamic props).
 * See [[mir_selfcompile_migration_2026_06_01]].
 */

/** Encode a value as JSON. */
function json_encode(mixed $value): string {
    return __mc_json_enc($value);
}

/** `$w` lowercase hex digits of `$v` (JSON `\u` escapes are lowercase). */
function __mc_json_hex(int $v, int $w): string {
    $d = "0123456789abcdef";
    $out = "";
    for ($i = $w - 1; $i >= 0; $i = $i - 1) {
        $out = $out . $d[($v >> ($i * 4)) & 15];
    }
    return $out;
}

/**
 * Escape a string for a JSON string literal, matching php json_encode's DEFAULT
 * flags: `"` `\` and `/` are backslash-escaped; the C0 controls with a short
 * form (\b \t \n \f \r) use it and any other control is `\u00XX`; and every
 * NON-ASCII byte is UTF-8-decoded and emitted as `\uXXXX` (a codepoint above the
 * BMP as a `\uD800`-`\uDC00` surrogate pair). A fast-return skips the copy when
 * nothing needs escaping — the common case, plain ASCII words. `.=` is amortized.
 */
function __mc_json_escape(string $s): string {
    $n = \strlen($s);
    $needs = false;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($s[$i]);
        if ($o < 0x20 || $o === 0x22 || $o === 0x5c || $o === 0x2f || $o >= 0x80) {
            $needs = true;
            break;
        }
    }
    if (!$needs) { return $s; }
    $out = "";
    $i = 0;
    while ($i < $n) {
        $o = \ord($s[$i]);
        if ($o === 0x22) { $out = $out . '\\"'; $i = $i + 1; }
        elseif ($o === 0x5c) { $out = $out . '\\\\'; $i = $i + 1; }
        elseif ($o === 0x2f) { $out = $out . '\\/'; $i = $i + 1; }
        elseif ($o === 0x0a) { $out = $out . '\\n'; $i = $i + 1; }
        elseif ($o === 0x09) { $out = $out . '\\t'; $i = $i + 1; }
        elseif ($o === 0x0d) { $out = $out . '\\r'; $i = $i + 1; }
        elseif ($o === 0x08) { $out = $out . '\\b'; $i = $i + 1; }
        elseif ($o === 0x0c) { $out = $out . '\\f'; $i = $i + 1; }
        elseif ($o < 0x20) { $out = $out . '\\u00' . \__mc_json_hex($o, 2); $i = $i + 1; }
        elseif ($o < 0x80) { $out = $out . $s[$i]; $i = $i + 1; }
        else {
            // UTF-8 lead byte → decode the 2/3/4-byte sequence to a codepoint.
            if ($o >= 0xF0) {
                $cp = (($o & 7) << 18) | ((\ord($s[$i + 1]) & 0x3f) << 12)
                    | ((\ord($s[$i + 2]) & 0x3f) << 6) | (\ord($s[$i + 3]) & 0x3f);
                $i = $i + 4;
            } elseif ($o >= 0xE0) {
                $cp = (($o & 0xf) << 12) | ((\ord($s[$i + 1]) & 0x3f) << 6)
                    | (\ord($s[$i + 2]) & 0x3f);
                $i = $i + 3;
            } else {
                $cp = (($o & 0x1f) << 6) | (\ord($s[$i + 1]) & 0x3f);
                $i = $i + 2;
            }
            if ($cp > 0xFFFF) {
                $cp = $cp - 0x10000;
                $out = $out . '\\u' . \__mc_json_hex(0xD800 + ($cp >> 10), 4)
                     . '\\u' . \__mc_json_hex(0xDC00 + ($cp & 0x3FF), 4);
            } else {
                $out = $out . '\\u' . \__mc_json_hex($cp, 4);
            }
        }
    }
    return $out;
}

/**
 * Decode a JSON string. Objects → assoc arrays, arrays → vecs, scalars →
 * int/float/string/bool/null. The `$associative` flag is accepted for PHP
 * compatibility but ignored — we always return arrays (no stdClass), which
 * is what the manifest reader and config consumers want. Delegates to
 * {@see \Runtime\Json\Parser}.
 */
function json_decode(string $json, bool $associative = true): mixed {
    $parser = new \Runtime\Json\Parser($json);
    return $parser->parse();
}

/**
 * Shortest round-tripping decimal for a double, formatted as PHP's json /
 * serialize_precision=-1 does it (Ryu, Ulf Adams). Replaces the `%.14g`
 * snprintf that was both slow (locale + dtoa per call) and WRONG (14 sig
 * digits, not shortest). The 128-bit power-of-five math is the `__ryu_msp`
 * codegen builtin; everything here is 64-bit and reads as the reference d2s.c.
 */
function __mc_dtoa(float $value): string {
    return \__mc_dtoa_bits(\__float_bits($value));
}

/** As {@see __mc_dtoa} but from the raw IEEE-754 i64 bit pattern — the json
 *  encoder already holds a float cell (bits == cell for a non-tagged double),
 *  so it calls this directly and skips the re-bitcast. json_encode cannot
 *  represent INF/NAN and emits 0; otherwise it is the shared shortest formatter
 *  with a lowercase `e` and no trailing `.0`. */
function __mc_dtoa_bits(int $bits): string {
    $ieeeExponent = ($bits >> 52) & 2047;
    if ($ieeeExponent === 2047) { return "0"; }    // INF / NAN → 0 for json
    return \__mc_dtoa_core($bits, 0, 0);
}

/**
 * Scalar half of the shortest-decimal core, for a caller that formats the
 * digits itself (the native json float emitter `__mir_json_double`): runs the
 * Ryu loop and returns ONE scalar — `$which === 0` the decimal digits
 * (`$output`, always >= 1 for a finite nonzero double), else
 * `(($exp + 1024) << 1) | $sign`. A non-finite or ±0 input returns -1 (both
 * calls) and the caller falls back to the string path. Two calls run the
 * integer core twice — that is still far cheaper than the old per-float
 * string tail (temp substr/concat/str_repeat allocations per value); the
 * string tail in {@see __mc_dtoa_core} now unpacks these same two scalars, so
 * the Ryu math lives in exactly one place.
 */
function __mc_dtoa_scal(int $bits, int $which): int {
    $sign = ($bits >> 63) & 1;
    $ieeeMantissa = $bits & 4503599627370495;      // (1<<52)-1
    $ieeeExponent = ($bits >> 52) & 2047;          // 0x7FF
    if (($bits & 9223372036854775807) === 0) { return -1; }
    if ($ieeeExponent === 2047) { return -1; }

    if ($ieeeExponent === 0) {
        $e2 = 1 - 1023 - 52 - 2;
        $m2 = $ieeeMantissa;
    } else {
        $e2 = $ieeeExponent - 1023 - 52 - 2;
        $m2 = 4503599627370496 | $ieeeMantissa;    // (1<<52) | mantissa
    }
    $even = ($m2 & 1) === 0;
    $acceptBounds = $even;
    $mv = 4 * $m2;
    $mmShift = ($ieeeMantissa !== 0 || $ieeeExponent <= 1) ? 1 : 0;

    $vmIsTrailingZeros = false;
    $vrIsTrailingZeros = false;
    if ($e2 >= 0) {
        $q = \__mc_log10pow2($e2) - ($e2 > 3 ? 1 : 0);
        $e10 = $q;
        $k = 125 + \__mc_pow5bits($q) - 1;
        $i = -$e2 + $q + $k;
        $vr = \__ryu_msp(4 * $m2, $q, $i, 1);
        $vp = \__ryu_msp(4 * $m2 + 2, $q, $i, 1);
        $vm = \__ryu_msp(4 * $m2 - 1 - $mmShift, $q, $i, 1);
        if ($q <= 21) {
            if (($mv % 5) === 0) {
                $vrIsTrailingZeros = \__mc_multiple_pow5($mv, $q);
            } elseif ($acceptBounds) {
                $vmIsTrailingZeros = \__mc_multiple_pow5($mv - 1 - $mmShift, $q);
            } else {
                if (\__mc_multiple_pow5($mv + 2, $q)) { $vp = $vp - 1; }
            }
        }
    } else {
        $q = \__mc_log10pow5(-$e2) - (-$e2 > 1 ? 1 : 0);
        $e10 = $q + $e2;
        $i = -$e2 - $q;
        $k = \__mc_pow5bits($i) - 125;
        $j = $q - $k;
        $vr = \__ryu_msp(4 * $m2, $i, $j, 0);
        $vp = \__ryu_msp(4 * $m2 + 2, $i, $j, 0);
        $vm = \__ryu_msp(4 * $m2 - 1 - $mmShift, $i, $j, 0);
        if ($q <= 1) {
            $vrIsTrailingZeros = true;
            if ($acceptBounds) {
                $vmIsTrailingZeros = $mmShift === 1;
            } else {
                $vp = $vp - 1;
            }
        } elseif ($q < 63) {
            $vrIsTrailingZeros = ($mv & ((1 << $q) - 1)) === 0;
        }
    }

    $removed = 0;
    $lastRemovedDigit = 0;
    if ($vmIsTrailingZeros || $vrIsTrailingZeros) {
        while (true) {
            $vpDiv10 = \intdiv($vp, 10);
            $vmDiv10 = \intdiv($vm, 10);
            if ($vpDiv10 <= $vmDiv10) { break; }
            $vmMod10 = $vm - 10 * $vmDiv10;
            $vrDiv10 = \intdiv($vr, 10);
            $vrMod10 = $vr - 10 * $vrDiv10;
            $vmIsTrailingZeros = $vmIsTrailingZeros && $vmMod10 === 0;
            $vrIsTrailingZeros = $vrIsTrailingZeros && $lastRemovedDigit === 0;
            $lastRemovedDigit = $vrMod10;
            $vr = $vrDiv10;
            $vp = $vpDiv10;
            $vm = $vmDiv10;
            $removed = $removed + 1;
        }
        if ($vmIsTrailingZeros) {
            while (true) {
                $vmDiv10 = \intdiv($vm, 10);
                $vmMod10 = $vm - 10 * $vmDiv10;
                if ($vmMod10 !== 0) { break; }
                $vpDiv10 = \intdiv($vp, 10);
                $vrDiv10 = \intdiv($vr, 10);
                $vrMod10 = $vr - 10 * $vrDiv10;
                $vrIsTrailingZeros = $vrIsTrailingZeros && $lastRemovedDigit === 0;
                $lastRemovedDigit = $vrMod10;
                $vr = $vrDiv10;
                $vp = $vpDiv10;
                $vm = $vmDiv10;
                $removed = $removed + 1;
            }
        }
        if ($vrIsTrailingZeros && $lastRemovedDigit === 5 && ($vr % 2) === 0) {
            $lastRemovedDigit = 4;
        }
        $roundUp = ($vr === $vm && (!$acceptBounds || !$vmIsTrailingZeros)) || $lastRemovedDigit >= 5;
        $output = $vr + ($roundUp ? 1 : 0);
    } else {
        $roundUp = false;
        $vpDiv100 = \intdiv($vp, 100);
        $vmDiv100 = \intdiv($vm, 100);
        if ($vpDiv100 > $vmDiv100) {
            $vrDiv100 = \intdiv($vr, 100);
            $vrMod100 = $vr - 100 * $vrDiv100;
            $roundUp = $vrMod100 >= 50;
            $vr = $vrDiv100;
            $vp = $vpDiv100;
            $vm = $vmDiv100;
            $removed = $removed + 2;
        }
        while (true) {
            $vpDiv10 = \intdiv($vp, 10);
            $vmDiv10 = \intdiv($vm, 10);
            if ($vpDiv10 <= $vmDiv10) { break; }
            $vrDiv10 = \intdiv($vr, 10);
            $vrMod10 = $vr - 10 * $vrDiv10;
            $roundUp = $vrMod10 >= 5;
            $vr = $vrDiv10;
            $vp = $vpDiv10;
            $vm = $vmDiv10;
            $removed = $removed + 1;
        }
        $output = $vr + (($vr === $vm || $roundUp) ? 1 : 0);
    }
    $exp = $e10 + $removed;
    if ($which === 0) { return $output; }
    if ($which === 1) { return (($exp + 1024) << 1) | $sign; }
    // $which === 2: ONE-CALL packed form — digits<<13 | (exp+1024)<<2 |
    // sign<<1 | 0. Digits above 51 bits (rare 16-17-digit shortest outputs)
    // return 1 (flag bit): caller re-runs via which=0/1.
    if ($output >= 2251799813685248) { return 1; }             // 1<<51
    return ($output << 13) | (($exp + 1024) << 2) | ($sign << 1);
}

/**
 * Shared shortest-decimal core. `$upperE` picks `E` (var_dump / var_export)
 * over `e` (json); `$forceDot` appends `.0` to an integer-valued decimal
 * (var_export, which must round-trip as a float) where var_dump / json leave it
 * bare. Non-finite renders as INF / -INF / NAN (what var_dump / var_export
 * want; the json entry point intercepts those first). The digit math is
 * {@see __mc_dtoa_scal}; this is only the string tail.
 */
function __mc_dtoa_core(int $bits, int $upperE, int $forceDot): string {
    $sig = \__mc_dtoa_scal($bits, 0);
    if ($sig < 0) {
        // ±0 / INF / NAN — the only -1 producers.
        $sign = ($bits >> 63) & 1;
        $ieeeMantissa = $bits & 4503599627370495;
        if (($bits & 9223372036854775807) === 0) {
            $z = $forceDot === 1 ? "0.0" : "0";
            return $sign === 1 ? ("-" . $z) : $z;
        }
        if ($ieeeMantissa !== 0) { return "NAN"; }
        return $sign === 1 ? "-INF" : "INF";
    }
    $meta = \__mc_dtoa_scal($bits, 1);
    $sign = $meta & 1;
    $exp = ($meta >> 1) - 1024;
    $output = $sig;

    // ── format like PHP: shortest digits placed decimal or scientific ──
    $digits = (string)$output;
    $olen = \strlen($digits);
    $eSci = $exp + $olen - 1;
    $neg = $sign === 1 ? "-" : "";

    $eChar = $upperE === 1 ? "E" : "e";
    if ($eSci < -4 || $eSci > 16) {
        $mant = $olen > 1 ? ($digits[0] . "." . \substr($digits, 1)) : ($digits . ".0");
        $es = $eSci >= 0 ? ($eChar . "+" . (string)$eSci) : ($eChar . "-" . (string)(-$eSci));
        return $neg . $mant . $es;
    }
    if ($exp >= 0) {
        // integer-valued; var_export appends `.0` so it round-trips as a float.
        $tail = $forceDot === 1 ? ".0" : "";
        return $neg . $digits . \str_repeat("0", $exp) . $tail;
    }
    $dp = $olen + $exp;                                    // digits before '.'
    if ($dp <= 0) {
        return $neg . "0." . \str_repeat("0", -$dp) . $digits;
    }
    return $neg . \substr($digits, 0, $dp) . "." . \substr($digits, $dp);
}

/** pow5bits(e) = ((e * 1217359) >> 19) + 1. */
function __mc_pow5bits(int $e): int {
    return (($e * 1217359) >> 19) + 1;
}

/** log10Pow2(e) = (e * 78913) >> 18. */
function __mc_log10pow2(int $e): int {
    return ($e * 78913) >> 18;
}

/** log10Pow5(e) = (e * 732923) >> 20. */
function __mc_log10pow5(int $e): int {
    return ($e * 732923) >> 20;
}

/** multipleOfPowerOf5(value, p): value is divisible by 5^p (Ryu pow5Factor). */
function __mc_multiple_pow5(int $value, int $p): bool {
    // 5 * m_inv_5 == 1 (mod 2^64); m_inv_5 as its signed-i64 bit pattern.
    $mInv5 = -3689348814741910323;
    $nDiv5 = 3689348814741910323;              // 2^64 / 5
    $count = 0;
    while (true) {
        $value = $value * $mInv5;              // wraps mod 2^64 (native)
        if (\__ugt($value, $nDiv5)) { break; } // unsigned compare
        $count = $count + 1;
    }
    return $count >= $p;
}

function __mc_json_enc(mixed $v): string {
    if (is_null($v)) { return "null"; }
    if (is_bool($v)) { return $v ? "true" : "false"; }
    if (is_int($v)) { return (string)$v; }
    if (is_float($v)) { return (string)$v; }
    if (is_string($v)) { return '"' . __mc_json_escape($v) . '"'; }
    if (is_object($v)) {
        $out = "{";
        $first = true;
        foreach ((array)$v as $k => $val) {
            if (!$first) { $out = $out . ","; }
            $first = false;
            $ks = is_string($k) ? $k : (string)$k;
            $out = $out . '"' . __mc_json_escape($ks) . '":' . __mc_json_enc($val);
        }
        return $out . "}";
    }
    // An array encodes as a JSON list `[...]` only when its keys are
    // 0..n-1 in order; otherwise as a JSON object `{...}` with every key
    // stringified (PHP semantics).
    if (array_is_list($v)) {
        $out = "[";
        $first = true;
        foreach ($v as $val) {
            if (!$first) { $out = $out . ","; }
            $first = false;
            $out = $out . __mc_json_enc($val);
        }
        return $out . "]";
    }
    $out = "{";
    $first = true;
    foreach ($v as $k => $val) {
        if (!$first) { $out = $out . ","; }
        $first = false;
        $ks = is_string($k) ? $k : (string)$k;
        $out = $out . '"' . __mc_json_escape($ks) . '":' . __mc_json_enc($val);
    }
    return $out . "}";
}
