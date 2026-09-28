<?php

/**
 * mb_encode_mimeheader / mb_decode_mimeheader (RFC 2047) — a transcription of
 * mbstring.c's mb_mime_header_encode / mb_mime_header_decode. The encoder's
 * line breaking depends on how its 90-codepoint decode buffer is refilled (a
 * word straddling a refill is carried over, or forces MIME encoding), so that
 * buffering is emulated too: the output is Zend's byte for byte.
 */

/** Printable ASCII bytes a Q-encoded word must escape (read back from Zend; bytes >= 0x80 always are). */
function __mc_mb_q_escapes(int $c): bool
{
    $t = "11111111111111111111111111111111101111111100101011111111111111111000000000000000000000000001111110000000000000000000000000011111";
    return $c >= 0x80 || $c === 0x3D || $t[$c] === "1";
}

/** Bytes of `$ws` (C wchars, 0xFFFFFFFF = malformed) in `$enc`, every problem a '?'. @param int[] $ws */
function __mc_mb_mime_bytes(string $enc, array $ws): string
{
    $saved = \__mc_mb_subst();
    \__mc_mb_subst(0, 63);
    $out = (string)\__mc_mb_enc8($enc, \__mc_mb_wchars8($ws));
    \__mc_mb_subst($saved[0], $saved[1]);
    return $out;
}

function __mc_mb_mime_size(string $bytes, bool $base64): int
{
    $n = \strlen($bytes);
    if ($base64) { return \intdiv($n + 2, 3) * 4; }
    $size = 0;
    $i = 0;
    while ($i < $n) {
        $size = $size + (\__mc_mb_q_escapes(\ord($bytes[$i])) ? 3 : 1);
        $i = $i + 1;
    }
    return $size;
}

function __mc_mb_mime_transfer(string $bytes, bool $base64): string
{
    if ($base64) { return \base64_encode($bytes); }
    $hex = "0123456789ABCDEF";
    $out = "";
    $n = \strlen($bytes);
    $i = 0;
    while ($i < $n) {
        $c = \ord($bytes[$i]);
        $out = $out . (\__mc_mb_q_escapes($c) ? "=" . $hex[$c >> 4] . $hex[$c & 0xF] : $bytes[$i]);
        $i = $i + 1;
    }
    return $out;
}

/**
 * The MIME-encoded tail: everything from unit `$p` of the current buffer
 * `$buf` (the rest of the input still at `$pos` of `$w`) as encoded words,
 * breaking lines where Zend does. `$st` = [line_start, indent].
 * @param int[] $w
 * @param int[] $buf
 * @param int[] $st
 */
function __mc_mb_mime_words(array $w, int $pos, array $buf, int $p, string $out, array $st, string $enc, bool $base64, string $lf): string
{
    $total = \count($w);
    $mime = \__mc_mb_mime_table()[$enc];
    $e = \count($buf);
    $refill = 90 - ($e - $p) >= 5;
    while (true) {
        if ($refill) {
            $carry = [];
            $k = $p;
            while ($k < $e) {
                $carry[] = $buf[$k];
                $k = $k + 1;
            }
            $take = \min(90 - \count($carry), $total - $pos);
            $k = 0;
            while ($k < $take) {
                $carry[] = $w[$pos + $k];
                $k = $k + 1;
            }
            $pos = $pos + $take;
            $buf = $carry;
            $p = 0;
            $e = \count($buf);
        }
        $out = $out . "=?" . $mime . "?" . ($base64 ? "B" : "Q") . "?";
        $n = 12;
        $space = 73 - $st[1] - (\strlen($out) - $st[0]);
        $line = [];
        while (true) {
            $n = \min($n, $e - $p);
            $cand = $line;
            $k = 0;
            while ($k < $n) {
                $cand[] = $buf[$p + $k];
                $k = $k + 1;
            }
            $bytes = \__mc_mb_mime_bytes($enc, $cand);
            if (\__mc_mb_mime_size($bytes, $base64) <= $space || ($n === 1 && $line === [])) {
                $p = $p + $n;
                $line = $cand;
                if ($p === $e) { return $out . \__mc_mb_mime_transfer($bytes, $base64) . "?="; }
                continue;
            }
            if ($n > 1) {
                $n = \max($n >> 1, 1);
                continue;
            }
            $out = $out . \__mc_mb_mime_transfer(\__mc_mb_mime_bytes($enc, $line), $base64) . "?=";
            $st[1] = 0;
            if ($pos >= $total && $p >= $e) { return $out; }
            $out = $out . $lf . " ";
            $st[0] = \strlen($out);
            $refill = $pos < $total && 90 - ($e - $p) >= 5;
            break;
        }
    }
}

function mb_encode_mimeheader(string $string, ?string $charset = null, ?string $transfer_encoding = null, string $newline = "\r\n", int $indent = 0): string
{
    $enc = "UTF-8";
    $base64 = true;
    if ($charset !== null) {
        $enc = \__mc_mb_canon($charset);
        if ($enc === "") {
            throw new \ValueError("mb_encode_mimeheader(): Argument #2 (\$charset) must be a valid encoding, \"" . $charset . "\" given");
        }
        if (\__mc_mb_mime_table()[$enc] === "" || $enc === "Quoted-Printable") {
            throw new \ValueError("mb_encode_mimeheader(): Argument #2 (\$charset) \"" . $charset . "\" cannot be used for MIME header encoding");
        }
    }
    if ($transfer_encoding !== null && $transfer_encoding !== "" && ($transfer_encoding[0] === "Q" || $transfer_encoding[0] === "q")) {
        $base64 = false;
    }
    if ($string === "") { return ""; }
    if ($indent < 0 || $indent >= 74) { $indent = 0; }
    $lf = \substr($newline, 0, 8);
    $nul = \strpos($lf, "\x00");
    if ($nul !== false) { $lf = \substr($lf, 0, $nul); }

    $w = \__mc_mb_raw_units(\__mc_mb_dec8(\__mc_mb_internal(), $string));
    $total = \count($w);

    // Pass through unchanged: printable ASCII after any leading spaces, no = ? _.
    $i = 0;
    while ($i < $total && $w[$i] === 0x20) { $i = $i + 1; }
    $plain = true;
    while ($i < $total) {
        $c = $w[$i];
        if ($c < 0x21 || $c > 0x7E || $c === 0x3D || $c === 0x3F || $c === 0x5F) {
            $plain = false;
            break;
        }
        $i = $i + 1;
    }
    if ($plain) { return $string; }

    $mimeLen = \strlen(\__mc_mb_mime_table()[$enc]);
    $out = "";
    $lineStart = 0;
    $pos = 0;
    $buf = [];
    while ($pos < $total) {
        $take = \min(90 - \count($buf), $total - $pos);
        $k = 0;
        while ($k < $take) {
            $buf[] = $w[$pos + $k];
            $k = $k + 1;
        }
        $pos = $pos + $take;
        $more = $pos < $total;
        $e = \count($buf);
        $p = 0;
        $ws = 0;
        while ($p < $e && $buf[$p] === 0x20 && $p - $ws <= 74) { $p = $p + 1; }
        $mimeAt = -1;
        while ($p < $e) {
            $c = $buf[$p];
            $p = $p + 1;
            if ($c < 0x20 || $c > 0x7E || $c === 0x3F || $c === 0x3D || $c === 0x5F || ($c === 0x20 && $p - $ws > 74)) {
                $mimeAt = $ws;
                break;
            }
            if ($c === 0x20) {
                if (\strlen($out) - $lineStart + ($p - $ws) + $indent > 75) {
                    $out = $out . $lf . " ";
                    $indent = 0;
                    $lineStart = \strlen($out);
                } elseif ($out !== "") {
                    $out = $out . " ";
                }
                while ($ws < $p - 1) {
                    $out = $out . \chr($buf[$ws] & 0xFF);
                    $ws = $ws + 1;
                }
                $ws = $ws + 1;
                while ($p < $e && $buf[$p] === 0x20) { $p = $p + 1; }
            }
        }
        if ($mimeAt < 0 && $more && $ws < 5) { $mimeAt = $ws; }
        if ($mimeAt >= 0) {
            if (\strlen($out) - $lineStart + $indent + $mimeLen > 55) {
                $out = $out . $lf . " ";
                $indent = 0;
                $lineStart = \strlen($out);
            } elseif ($out !== "") {
                $out = $out . " ";
            }
            return \__mc_mb_mime_words($w, $pos, $buf, $mimeAt, $out, [$lineStart, $indent], $enc, $base64, $lf);
        }
        if ($more) {
            $carry = [];
            $k = $ws;
            while ($k < $e) {
                $carry[] = $buf[$k];
                $k = $k + 1;
            }
            $buf = $carry;
            continue;
        }
        if ($ws < $e && $out !== "") {
            if (\strlen($out) - $lineStart + ($p - $ws) + $indent > 74) {
                $out = $out . $lf . " ";
            } else {
                $out = $out . " ";
            }
        }
        while ($ws < $e) {
            $out = $out . \chr($buf[$ws] & 0xFF);
            $ws = $ws + 1;
        }
    }
    return $out;
}

/**
 * Bytes of one RFC 2047 encoded word starting at `$p` ("=?"), appended as
 * codepoints to `$ws` via the return value [end offset, codepoints...]; null
 * when it is not one Zend accepts.
 * @return int[]|null
 */
function __mc_mb_mime_word(string $s, int $p, int $e): ?array
{
    if ($e - $p < 6) { return null; }
    $cs = $p + 2;
    $csEnd = \strpos($s, "?", $cs);
    if ($csEnd === false || $csEnd >= $e) { return null; }
    $encAt = $csEnd + 1;
    $q = $encAt + 1;
    if ($q >= $e || $s[$q] !== "?") { return null; }
    $q = $q + 1;
    $incode = \__mc_mb_canon(\substr($s, $cs, $csEnd - $cs));
    if ($incode === "") { return null; }
    $end = \strpos(\substr($s, 0, $e), "?=", $q);
    $stop = $e;
    if ($end !== false) {
        $stop = $end;
    } elseif ($q < $e && $s[$e - 1] === "?") {
        $stop = $e - 1;
    }
    $kind = $s[$encAt];
    $bytes = "";
    if ($kind === "Q" || $kind === "q") {
        while ($q < $stop) {
            $c = $s[$q];
            $q = $q + 1;
            if ($c === "_") {
                $bytes = $bytes . " ";
                continue;
            }
            if ($c === "=" && $stop - $q >= 2) {
                $c2 = $s[$q];
                $c3 = $s[$q + 1];
                $q = $q + 2;
                $h2 = \__mc_mb_hexval(\ord($c2));
                $h3 = \__mc_mb_hexval(\ord($c3));
                if ($h2 >= 0 && $h3 >= 0) {
                    $bytes = $bytes . \chr(($h2 << 4) | $h3);
                    continue;
                }
                if ($c2 === "\r") {
                    if ($c3 !== "\n") { $q = $q - 1; }
                    continue;
                }
                if ($c2 === "\n") {
                    $q = $q - 1;
                    continue;
                }
            }
            $bytes = $bytes . $c;
        }
    } elseif ($kind === "B" || $kind === "b") {
        $bits = 0;
        $cache = 0;
        while ($q < $stop) {
            $c = \ord($s[$q]);
            $q = $q + 1;
            if ($c === 0x0D || $c === 0x0A || $c === 0x20 || $c === 0x09 || $c === 0x3D) { continue; }
            $v = \__mc_mb_base64_val($c);
            if ($v < 0) {
                $bytes = $bytes . "?";
                continue;
            }
            $bits = $bits + 6;
            $cache = ($cache << 6) | $v;
            if ($bits === 24) {
                $bytes = $bytes . \chr(($cache >> 16) & 0xFF) . \chr(($cache >> 8) & 0xFF) . \chr($cache & 0xFF);
                $bits = 0;
                $cache = 0;
            }
        }
        if ($bits === 18) {
            $bytes = $bytes . \chr(($cache >> 10) & 0xFF) . \chr(($cache >> 2) & 0xFF);
        } elseif ($bits === 12) {
            $bytes = $bytes . \chr(($cache >> 4) & 0xFF);
        }
    } else {
        return null;
    }
    $out = [$stop + 2];
    foreach (\__mc_mb_raw_units(\__mc_mb_dec8($incode, $bytes, true)) as $w) { $out[] = $w; }
    return $out;
}

function mb_decode_mimeheader(string $string): string
{
    $e = \strlen($string);
    $ws = [];
    $p = 0;
    $pending = false;
    while ($p < $e) {
        $c = $string[$p];
        if ($c === "=" && $p + 1 < $e && $string[$p + 1] === "?" && $e - $p >= 6) {
            $qm = \strpos($string, "?", $p + 2);
            if ($qm !== false && $e - $qm >= 3) {
                $word = \__mc_mb_mime_word($string, $p, $e);
                if ($word !== null) {
                    $p = $word[0];
                    $k = 1;
                    $n = \count($word);
                    while ($k < $n) {
                        $ws[] = $word[$k];
                        $k = $k + 1;
                    }
                    if ($p < $e && \str_contains("\n\r\t ", $string[$p])) {
                        while ($p < $e && \str_contains("\n\r\t ", $string[$p])) { $p = $p + 1; }
                        $pending = true;
                    } else {
                        $pending = false;
                    }
                    continue;
                }
            }
        }
        if ($pending) {
            $ws[] = 0x20;
            $pending = false;
        }
        if ($c !== "\n" && $c !== "\r") {
            $end = $p + 1;
            while ($end < $e && $string[$end] !== "=" && $string[$end] !== "\n" && $string[$end] !== "\r") { $end = $end + 1; }
            while ($p < $end) {
                $b = \ord($string[$p]);
                $ws[] = $b < 0x80 ? $b : 0xFFFFFFFF;
                $p = $p + 1;
            }
        }
        if ($p < $e && ($string[$p] === "\n" || $string[$p] === "\r")) {
            while ($p < $e && \str_contains("\n\r\t ", $string[$p])) { $p = $p + 1; }
            if ($p < $e) { $ws[] = 0x20; }
        }
    }
    return \__mc_mb_mime_bytes(\__mc_mb_internal(), $ws);
}
