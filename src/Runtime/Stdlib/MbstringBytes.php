<?php

/**
 * mbstring's byte "encodings" — BASE64, Quoted-Printable, UUENCODE and
 * HTML-ENTITIES — transcribed from libmbfl's fast codecs. All four are
 * deprecated in PHP 8.2+ (Zend prints E_DEPRECATED; this runtime stays silent),
 * but `mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8')` in front of
 * DOMDocument::loadHTML is still everywhere.
 *
 * Their decoders produce BYTES (0..255) as codepoints; their encoders read the
 * low bits of whatever codepoint they are given, the malformed-input marker
 * included (0xFFFFFFFF in C) — reproduced, because it is observable.
 */

/** Codepoints of marked UTF-8 for a byte encoder: a marker is C's MBFL_BAD_INPUT. @return int[] */
function __mc_mb_raw_units(string $u): array
{
    $out = [];
    foreach (\__mc_mb_units($u) as $w) { $out[] = $w < 0 ? 0xFFFFFFFF : $w; }
    return $out;
}

/** Marked UTF-8 of a byte string (each byte is the codepoint of that value). */
function __mc_mb_bytes8(string $b): string
{
    $out = "";
    $n = \strlen($b);
    $i = 0;
    while ($i < $n) {
        $out = $out . \__mc_mb_u8_chr(\ord($b[$i]));
        $i = $i + 1;
    }
    return $out;
}

function __mc_mb_base64_val(int $c): int
{
    if ($c >= 0x41 && $c <= 0x5A) { return $c - 65; }
    if ($c >= 0x61 && $c <= 0x7A) { return $c - 71; }
    if ($c >= 0x30 && $c <= 0x39) { return $c + 4; }
    if ($c === 0x2B) { return 62; }
    if ($c === 0x2F) { return 63; }
    return -1;
}

/** BASE64 → marked UTF-8: whitespace and '=' skipped, anything else not Base64 is a marker. */
function __mc_mb_base64_dec8(string $s): string
{
    $out = "";
    $bits = 0;
    $cache = 0;
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = \ord($s[$i]);
        $i = $i + 1;
        if ($c === 0x0D || $c === 0x0A || $c === 0x20 || $c === 0x09 || $c === 0x3D) { continue; }
        $v = \__mc_mb_base64_val($c);
        if ($v < 0) {
            $out = $out . "\xFF";
            continue;
        }
        $bits = $bits + 6;
        $cache = ($cache << 6) | $v;
        if ($bits === 24) {
            $out = $out . \__mc_mb_u8_chr(($cache >> 16) & 0xFF) . \__mc_mb_u8_chr(($cache >> 8) & 0xFF) . \__mc_mb_u8_chr($cache & 0xFF);
            $bits = 0;
            $cache = 0;
        }
    }
    if ($bits === 18) {
        $out = $out . \__mc_mb_u8_chr(($cache >> 10) & 0xFF) . \__mc_mb_u8_chr(($cache >> 2) & 0xFF);
    } elseif ($bits === 12) {
        $out = $out . \__mc_mb_u8_chr(($cache >> 4) & 0xFF);
    }
    return $out;
}

/** Codepoints → BASE64 (low byte of each), CRLF before a group that would pass 76 columns. @param int[] $ws */
function __mc_mb_base64_enc(array $ws): string
{
    $t = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
    $out = "";
    $bits = 0;
    $cache = 0;
    $col = 0;
    foreach ($ws as $w) {
        $cache = ($cache << 8) | ($w & 0xFF);
        $bits = $bits + 8;
        if ($bits === 24) {
            if ($col > 72) {
                $out = $out . "\r\n";
                $col = 0;
            }
            $out = $out . $t[($cache >> 18) & 0x3F] . $t[($cache >> 12) & 0x3F] . $t[($cache >> 6) & 0x3F] . $t[$cache & 0x3F];
            $col = $col + 4;
            $bits = 0;
            $cache = 0;
        }
    }
    if ($bits > 0) {
        if ($col > 72) { $out = $out . "\r\n"; }
        if ($bits === 8) {
            $out = $out . $t[($cache >> 2) & 0x3F] . $t[($cache & 0x3) << 4] . "==";
        } else {
            $out = $out . $t[($cache >> 10) & 0x3F] . $t[($cache >> 4) & 0x3F] . $t[($cache & 0xF) << 2] . "=";
        }
    }
    return $out;
}

function __mc_mb_hexval(int $c): int
{
    if ($c >= 0x30 && $c <= 0x39) { return $c - 0x30; }
    if ($c >= 0x41 && $c <= 0x46) { return $c - 55; }
    if ($c >= 0x61 && $c <= 0x66) { return $c - 87; }
    return -1;
}

/** Quoted-Printable → marked UTF-8 (bytes); never malformed. */
function __mc_mb_qprint_dec8(string $s): string
{
    $out = "";
    $n = \strlen($s);
    $p = 0;
    while ($p < $n) {
        $c = \ord($s[$p]);
        $p = $p + 1;
        if ($c !== 0x3D || $p >= $n) {
            $out = $out . \__mc_mb_u8_chr($c);
            continue;
        }
        $c2 = \ord($s[$p]);
        $p = $p + 1;
        if (\__mc_mb_hexval($c2) >= 0 && $p < $n) {
            $c3 = \ord($s[$p]);
            $p = $p + 1;
            if (\__mc_mb_hexval($c3) >= 0) {
                $out = $out . \__mc_mb_u8_chr((\__mc_mb_hexval($c2) << 4) | \__mc_mb_hexval($c3));
            } else {
                $out = $out . "=" . \__mc_mb_u8_chr($c2) . \__mc_mb_u8_chr($c3);
            }
        } elseif ($c2 === 0x0D && $p < $n) {
            $c3 = \ord($s[$p]);
            $p = $p + 1;
            if ($c3 !== 0x0A) { $out = $out . \__mc_mb_u8_chr($c3); }
        } elseif ($c2 !== 0x0A) {
            $out = $out . "=" . \__mc_mb_u8_chr($c2);
        }
    }
    return $out;
}

/** Codepoints (as bytes) → Quoted-Printable. @param int[] $ws */
function __mc_mb_qprint_enc(array $ws): string
{
    $hex = "0123456789ABCDEF";
    $out = "";
    $col = 0;
    foreach ($ws as $w) {
        if ($w === 0) {
            $out = $out . "\x00";
            $col = 0;
            continue;
        }
        if ($w === 0x0A) {
            $out = $out . "\r\n";
            $col = 0;
            continue;
        }
        if ($w === 0x0D) { continue; }
        if ($col >= 72) {
            $out = $out . "=\r\n";
            $col = 0;
        }
        if ($w >= 0x80 || $w === 0x3D) {
            $out = $out . "=" . $hex[($w >> 4) & 0xF] . $hex[$w & 0xF];
            $col = $col + 3;
        } else {
            $out = $out . \chr($w);
            $col = $col + 1;
        }
    }
    return $out;
}

/** UUENCODE → marked UTF-8 (bytes): everything before the "begin " line is skipped. */
function __mc_mb_uuencode_dec8(string $s): string
{
    $out = "";
    $e = \strlen($s);
    $p = 0;
    $state = 0;   // 0 ground, 1 size, 2 data, 3 skip newline
    $size = 0;
    while ($p < $e) {
        $c = \ord($s[$p]);
        $p = $p + 1;
        if ($state === 0) {
            if ($c === 0x62 && $e - $p >= 5 && \substr($s, $p, 5) === "egin ") {
                $p = $p + 5;
                while ($p < $e) {
                    $p = $p + 1;
                    if ($s[$p - 1] === "\n") { break; }
                }
                $state = 1;
            }
        } elseif ($state === 1) {
            $size = ($c - 0x20) & 0x3F;
            $state = 2;
        } elseif ($state === 2) {
            if ($e - $p < 4) {
                $p = $e;
                continue;
            }
            $a = ($c - 0x20) & 0x3F;
            $b = (\ord($s[$p]) - 0x20) & 0x3F;
            $cc = (\ord($s[$p + 1]) - 0x20) & 0x3F;
            $d = (\ord($s[$p + 2]) - 0x20) & 0x3F;
            $p = $p + 3;
            if ($size > 0) { $out = $out . \__mc_mb_u8_chr((($a << 2) | ($b >> 4)) & 0xFF); $size = $size - 1; }
            if ($size > 0) { $out = $out . \__mc_mb_u8_chr((($b << 4) | ($cc >> 2)) & 0xFF); $size = $size - 1; }
            if ($size > 0) { $out = $out . \__mc_mb_u8_chr((($cc << 6) | $d) & 0xFF); $size = $size - 1; }
            $state = $size > 0 ? 2 : 3;
        } else {
            $state = 1;
        }
    }
    return $out;
}

function __mc_mb_uu6(int $bits): string
{
    return $bits === 0 ? "`" : \chr($bits + 32);
}

/**
 * Codepoints → UUENCODE (one call, `end`): header, 45 bytes a line, each line
 * led by its length. Zend calls the encoder whenever the INPUT was non-empty, so
 * an empty list still writes the header — callers skip an empty input themselves.
 * @param int[] $ws
 */
function __mc_mb_uuencode_enc(array $ws): string
{
    $len = \count($ws);
    $out = "begin 0644 filename\n" . \chr(\min($len, 45) + 32);
    $done = 0;
    $i = 0;
    while ($i < $len) {
        $w = $ws[$i];
        $w2 = $i + 1 < $len ? $ws[$i + 1] : 0;
        $w3 = $i + 2 < $len ? $ws[$i + 2] : 0;
        $i = $i + 3;
        $out = $out . \__mc_mb_uu6(($w >> 2) & 0x3F) . \__mc_mb_uu6((($w & 0x3) << 4) + (($w2 >> 4) & 0xF))
            . \__mc_mb_uu6((($w2 & 0xF) << 2) + (($w3 >> 6) & 0x3)) . \__mc_mb_uu6($w3 & 0x3F);
        $done = $done + 3;
        if ($done >= 45) {
            $out = $out . "\n";
            $left = $len - $i;
            if ($left > 0) { $out = $out . \chr(\min($left, 45) + 32); }
            $done = 0;
        }
    }
    if ($done > 0) { $out = $out . "\n"; }
    return $out;
}

function __mc_mb_entity_char(int $c): bool
{
    return ($c >= 0x30 && $c <= 0x39) || ($c >= 0x41 && $c <= 0x5A) || ($c >= 0x61 && $c <= 0x7A) || $c === 0x23;
}

/** HTML-ENTITIES → marked UTF-8: numeric and known named entities decoded, everything else literal. */
function __mc_mb_htmlent_dec8(string $s): string
{
    $names = \__mc_mb_html_entities();
    $out = "";
    $e = \strlen($s);
    $p = 0;
    while ($p < $e) {
        $c = \ord($s[$p]);
        $p = $p + 1;
        if ($c !== 0x26) {
            $out = $out . \__mc_mb_u8_chr($c);
            continue;
        }
        $t = $p;
        while ($t < $e && \__mc_mb_entity_char(\ord($s[$t]))) { $t = $t + 1; }
        if ($t < $e && $s[$t] === ";") {
            if ($s[$p] === "#" && $e - $p >= 2) {
                $d = $p + 1;
                $hex = $s[$d] === "x" || $s[$d] === "X";
                if ($hex) { $d = $d + 1; }
                $ok = $d !== $t;
                $value = 0;
                while ($ok && $d < $t) {
                    $dc = \ord($s[$d]);
                    $v = $hex ? \__mc_mb_hexval($dc) : ($dc >= 0x30 && $dc <= 0x39 ? $dc - 48 : -1);
                    if ($v < 0) { $ok = false; }
                    $value = $value * ($hex ? 16 : 10) + $v;
                    if ($value > 0xFFFFFFFF) { $value = $value & 0xFFFFFFFF; }
                    $d = $d + 1;
                }
                if ($ok && $value <= 0x10FFFF) {
                    $out = $out . \__mc_mb_u8_chr($value);
                    $p = $t + 1;
                    continue;
                }
            } elseif ($t > $p) {
                $name = \substr($s, $p, $t - $p);
                if (isset($names[$name])) {
                    $out = $out . \__mc_mb_u8_chr($names[$name]);
                    $p = $t + 1;
                    continue;
                }
            }
        }
        $out = $out . "&" . \substr($s, $p, $t - $p);
        $p = $t;
        if ($t < $e && $s[$t] === ";") {
            $out = $out . ";";
            $p = $p + 1;
        }
    }
    return $out;
}

/** Codepoints → HTML-ENTITIES: ASCII as itself, the rest as the first named entity or `&#decimal;`. @param int[] $ws */
function __mc_mb_htmlent_enc(array $ws): string
{
    static $byCode = [];
    if ($byCode === []) {
        foreach (\__mc_mb_html_entities() as $name => $code) {
            if (!isset($byCode[$code])) { $byCode[$code] = $name; }
        }
    }
    $out = "";
    foreach ($ws as $w) {
        if ($w < 0x80) {
            $out = $out . \chr($w);
        } elseif (isset($byCode[$w])) {
            $out = $out . "&" . $byCode[$w] . ";";
        } else {
            $out = $out . "&#" . $w . ";";
        }
    }
    return $out;
}
