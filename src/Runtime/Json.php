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

/**
 * Escape a string for a JSON string literal: `"` and `\` are backslash-escaped,
 * and the C0 control chars that JSON forbids raw (\b \t \n \f \r) use their short
 * form. A fast-return skips the copy when no byte needs escaping (the common
 * case — plain ASCII words). Amortized `.=` builds the escaped copy.
 */
function __mc_json_escape(string $s): string {
    $n = \strlen($s);
    $needs = false;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $s[$i];
        if ($c === '"' || $c === '\\' || $c < ' ') { $needs = true; break; }
    }
    if (!$needs) { return $s; }
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $s[$i];
        if ($c === '"') { $out = $out . '\\"'; }
        else if ($c === '\\') { $out = $out . '\\\\'; }
        else if ($c === "\n") { $out = $out . '\\n'; }
        else if ($c === "\t") { $out = $out . '\\t'; }
        else if ($c === "\r") { $out = $out . '\\r'; }
        else if ($c === "\x08") { $out = $out . '\\b'; }
        else if ($c === "\x0C") { $out = $out . '\\f'; }
        else { $out = $out . $c; }
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
 *  so it calls this directly and skips the re-bitcast. */
function __mc_dtoa_bits(int $bits): string {
    $sign = ($bits >> 63) & 1;
    $ieeeMantissa = $bits & 4503599627370495;      // (1<<52)-1
    $ieeeExponent = ($bits >> 52) & 2047;          // 0x7FF
    // Zero (either sign) and the non-finite encodings don't go through Ryu.
    if (($bits & 9223372036854775807) === 0) {     // mask off sign
        return $sign === 1 ? "-0" : "0";
    }
    if ($ieeeExponent === 2047) {
        // PHP json_encode can't represent INF/NAN; it emits 0.
        return "0";
    }

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

    // ── format like PHP: shortest digits placed decimal or scientific ──
    $digits = (string)$output;
    $olen = \strlen($digits);
    $eSci = $exp + $olen - 1;
    $neg = $sign === 1 ? "-" : "";

    if ($eSci < -4 || $eSci > 16) {
        $mant = $olen > 1 ? ($digits[0] . "." . \substr($digits, 1)) : ($digits . ".0");
        $es = $eSci >= 0 ? ("e+" . (string)$eSci) : ("e-" . (string)(-$eSci));
        return $neg . $mant . $es;
    }
    if ($exp >= 0) {
        return $neg . $digits . \str_repeat("0", $exp);   // integer-valued
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
