<?php

/**
 * The iconv_* family.
 *
 * `iconv()` itself goes through host libiconv ({@see \Runtime\Iconv}) because
 * `//TRANSLIT` is the whole reason callers reach for it. The four `iconv_str*`
 * functions do NOT — they are UTF-8 codepoint walks, and routing them through a
 * conversion descriptor would be both slower and less accurate.
 *
 * php's ini-configurable internal encoding does not exist here (there is no
 * php.ini), so an omitted encoding means UTF-8 rather than
 * `iconv.internal_encoding`.
 */

/** Grow-and-retry conversion. Returns the converted bytes, or false. */
function __mc_iconv_run(string $to, string $from, string $s): mixed
{
    $cd = \Runtime\Iconv\iconv_open($to, $from);
    // iconv_open reports failure as (iconv_t)-1, which arrives here as the
    // unsigned all-ones word: test both spellings rather than assume the
    // pointer width the host chose.
    if ($cd === -1 || $cd === 0 || $cd === 4294967295) { return false; }
    $inLen = \strlen($s);
    if ($inLen === 0) {
        \Runtime\Iconv\iconv_close($cd);
        return "";
    }
    // Four scratch cells for the in/out pointer-and-length pairs, plus the
    // input and output byte buffers.
    $inBuf = \Runtime\Libc\malloc($inLen);
    \__mc_iconv_store($inBuf, $s);
    $outCap = $inLen * 4 + 16;
    $outBuf = \Runtime\Libc\malloc($outCap);
    $pIn = \Runtime\Libc\malloc(8);
    $pInLeft = \Runtime\Libc\malloc(8);
    $pOut = \Runtime\Libc\malloc(8);
    $pOutLeft = \Runtime\Libc\malloc(8);
    \poke_i64($pIn, 0, \ptr_to_int($inBuf));
    \poke_i64($pInLeft, 0, $inLen);
    \poke_i64($pOut, 0, \ptr_to_int($outBuf));
    \poke_i64($pOutLeft, 0, $outCap);
    $r = \Runtime\Iconv\iconv_convert($cd, $pIn, $pInLeft, $pOut, $pOutLeft);
    $left = \peek_i64($pOutLeft, 0);
    $written = $outCap - $left;
    $out = "";
    if ($r !== -1 && $r !== 4294967295 && $written >= 0) {
        $out = \__mc_iconv_read($outBuf, $written);
    }
    $ok = $r !== -1 && $r !== 4294967295;
    \Runtime\Libc\free($pIn);
    \Runtime\Libc\free($pInLeft);
    \Runtime\Libc\free($pOut);
    \Runtime\Libc\free($pOutLeft);
    \Runtime\Libc\free($inBuf);
    \Runtime\Libc\free($outBuf);
    \Runtime\Iconv\iconv_close($cd);
    if (!$ok) { return false; }
    return $out;
}

/** Copy a PHP string's bytes into a raw buffer. */
function __mc_iconv_store(\Ffi\Ptr $dst, string $s): void
{
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        \poke_i8($dst, $i, \ord($s[$i]));
        $i = $i + 1;
    }
}

/** Read `$n` bytes out of a raw buffer as a PHP string. */
function __mc_iconv_read(\Ffi\Ptr $src, int $n): string
{
    $out = "";
    $i = 0;
    while ($i < $n) {
        $out = $out . \chr(\peek_u8($src, $i));
        $i = $i + 1;
    }
    return $out;
}

/**
 * Convert `$string` from one character set to another. The `//TRANSLIT` and
 * `//IGNORE` suffixes are libiconv's own and pass straight through.
 */
function iconv(string $from_encoding, string $to_encoding, string $string): mixed
{
    return \__mc_iconv_run($to_encoding, $from_encoding, $string);
}

/** Byte offset just past the UTF-8 sequence starting at `$i`. */
function __mc_u8_next(string $s, int $i): int
{
    $b = \ord($s[$i]);
    if ($b >= 240) { return $i + 4; }
    if ($b >= 224) { return $i + 3; }
    if ($b >= 192) { return $i + 2; }
    return $i + 1;
}

/** Number of characters, not bytes. */
function iconv_strlen(string $string, ?string $encoding = null): int
{
    $n = \strlen($string);
    $i = 0;
    $c = 0;
    while ($i < $n) {
        $i = \__mc_u8_next($string, $i);
        $c = $c + 1;
    }
    return $c;
}

/** Byte offset of character index `$chars`, or the string length past the end. */
function __mc_u8_offset(string $s, int $chars): int
{
    $n = \strlen($s);
    $i = 0;
    $c = 0;
    while ($i < $n && $c < $chars) {
        $i = \__mc_u8_next($s, $i);
        $c = $c + 1;
    }
    return $i;
}

/** Substring by CHARACTER offset and length. */
function iconv_substr(string $string, int $offset, ?int $length = null, ?string $encoding = null): string
{
    $total = \iconv_strlen($string);
    if ($offset < 0) {
        $offset = $total + $offset;
        if ($offset < 0) { $offset = 0; }
    }
    if ($offset >= $total) { return ""; }
    $len = $length === null ? $total - $offset : $length;
    if ($len < 0) {
        $len = $total - $offset + $len;
        if ($len < 0) { $len = 0; }
    }
    $start = \__mc_u8_offset($string, $offset);
    $end = \__mc_u8_offset($string, $offset + $len);
    return \substr($string, $start, $end - $start);
}

/** Character index of the first `$needle`, or false. */
function iconv_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): mixed
{
    $byteStart = $offset === 0 ? 0 : \__mc_u8_offset($haystack, $offset);
    $at = \strpos($haystack, $needle, $byteStart);
    if ($at === false) { return false; }
    return \iconv_strlen(\substr($haystack, 0, $at));
}

/** Character index of the LAST `$needle`, or false. */
function iconv_strrpos(string $haystack, string $needle, ?string $encoding = null): mixed
{
    $at = \strrpos($haystack, $needle);
    if ($at === false) { return false; }
    return \iconv_strlen(\substr($haystack, 0, $at));
}

/**
 * Decode one MIME header value (RFC 2047 `=?charset?B|Q?text?=`).
 *
 * Only the encoded-word forms are handled — that IS the header syntax; a header
 * with no encoded word is returned as-is, which is also what php does.
 */
function iconv_mime_decode(string $string, int $mode = 0, ?string $encoding = null): mixed
{
    $to = $encoding === null ? (string)\__mc_iconv_encoding("internal_encoding") : $encoding;
    $out = "";
    $n = \strlen($string);
    $i = 0;
    while ($i < $n) {
        if (\substr($string, $i, 2) !== "=?") {
            $out = $out . $string[$i];
            $i = $i + 1;
            continue;
        }
        $q1 = \strpos($string, "?", $i + 2);
        if ($q1 === false) { $out = $out . $string[$i]; $i = $i + 1; continue; }
        $q2 = \strpos($string, "?", $q1 + 1);
        if ($q2 === false) { $out = $out . $string[$i]; $i = $i + 1; continue; }
        $end = \strpos($string, "?=", $q2 + 1);
        if ($end === false) { $out = $out . $string[$i]; $i = $i + 1; continue; }
        $charset = \substr($string, $i + 2, $q1 - $i - 2);
        $enc = \strtoupper(\substr($string, $q1 + 1, $q2 - $q1 - 1));
        $text = \substr($string, $q2 + 1, $end - $q2 - 1);
        $raw = "";
        if ($enc === "B") {
            $raw = \base64_decode($text);
        } elseif ($enc === "Q") {
            $raw = \__mc_qp_decode($text);
        } else {
            $out = $out . $string[$i];
            $i = $i + 1;
            continue;
        }
        $conv = \__mc_iconv_run($to, $charset, $raw);
        $out = $out . ($conv === false ? $raw : $conv);
        $i = $end + 2;
    }
    return $out;
}

/** Quoted-printable as used in an RFC 2047 `Q` encoded word (`_` is a space). */
function __mc_qp_decode(string $s): string
{
    $out = "";
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        if ($c === "_") { $out = $out . " "; $i = $i + 1; continue; }
        if ($c === "=" && $i + 2 < $n && \ctype_xdigit($s[$i + 1]) && \ctype_xdigit($s[$i + 2])) {
            $out = $out . \chr(\hexdec(\substr($s, $i + 1, 2)));
            $i = $i + 3;
            continue;
        }
        $out = $out . $c;
        $i = $i + 1;
    }
    return $out;
}

/**
 * The three encoding settings. php reads them from `iconv.*_encoding` in
 * php.ini; a compiled binary has no ini, so they start at UTF-8 and live in
 * this one accessor's statics. `$set` is the internal spelling of the setter —
 * a `?string` because "" is a legitimate (if useless) encoding name.
 */
function __mc_iconv_encoding(string $type, ?string $set = null): mixed
{
    static $input = "UTF-8";
    static $output = "UTF-8";
    static $internal = "UTF-8";
    if ($type === "input_encoding") {
        if ($set !== null) { $input = $set; }
        return $input;
    }
    if ($type === "output_encoding") {
        if ($set !== null) { $output = $set; }
        return $output;
    }
    if ($type === "internal_encoding") {
        if ($set !== null) { $internal = $set; }
        return $internal;
    }
    return false;
}

/**
 * Read one encoding setting, or all three as an array.
 * @return array<string,string>|string|false
 */
function iconv_get_encoding(string $type = "all"): mixed
{
    if ($type === "all") {
        return [
            "input_encoding" => \__mc_iconv_encoding("input_encoding"),
            "output_encoding" => \__mc_iconv_encoding("output_encoding"),
            "internal_encoding" => \__mc_iconv_encoding("internal_encoding"),
        ];
    }
    return \__mc_iconv_encoding($type);
}

/** Set one encoding setting. False for an unknown setting name. */
function iconv_set_encoding(string $type, string $encoding): bool
{
    if ($type !== "input_encoding" && $type !== "output_encoding"
        && $type !== "internal_encoding") {
        return false;
    }
    \__mc_iconv_encoding($type, $encoding);
    return true;
}

/**
 * Compose one MIME header field as an RFC 2047 encoded word.
 *
 * Options follow php: `scheme` ("B" default, or "Q"), `input-charset`,
 * `output-charset`. php always encodes — even pure ASCII comes back as an
 * encoded word — and this matches that rather than "optimising" it away.
 *
 * NOT implemented: `line-length` / `line-break-chars` folding. A folded header
 * needs the continuation rules to be right to be worth anything, and no caller
 * here composes one long enough to fold.
 *
 * `#[CellArg]` makes the CALL SITE box the elements: a stdlib extern's array
 * param erases its element type, so a raw `["scheme" => "Q"]` read back as
 * cells gave garbage and every call silently took the B branch.
 *
 * @param array<string,mixed> $options
 */
function iconv_mime_encode(string $field_name, string $field_value, #[\Manticore\Attr\CellArg] array $options = []): mixed
{
    $scheme = "B";
    if (isset($options["scheme"])) { $scheme = \strtoupper((string)$options["scheme"]); }
    // php defaults both charsets to iconv.internal_encoding, which here is
    // whatever iconv_set_encoding last stored.
    $def = (string)\__mc_iconv_encoding("internal_encoding");
    $in = $def;
    if (isset($options["input-charset"])) { $in = (string)$options["input-charset"]; }
    $out = $def;
    if (isset($options["output-charset"])) { $out = (string)$options["output-charset"]; }
    $body = $field_value;
    if ($in !== $out) {
        $conv = \__mc_iconv_run($out, $in, $field_value);
        if ($conv === false) { return false; }
        $body = $conv;
    }
    if ($scheme === "Q") {
        return $field_name . ": =?" . $out . "?Q?" . \__mc_qp_encode($body) . "?=";
    }
    return $field_name . ": =?" . $out . "?B?" . \base64_encode($body) . "?=";
}

/** RFC 2047 `Q` encoding: space becomes `_`, non-token bytes become `=HH`. */
function __mc_qp_encode(string $s): string
{
    $out = "";
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        $o = \ord($c);
        if ($c === " ") {
            $out = $out . "_";
        } elseif (($o >= 48 && $o <= 57) || ($o >= 65 && $o <= 90) || ($o >= 97 && $o <= 122)) {
            $out = $out . $c;
        } else {
            $out = $out . "=" . \strtoupper(\bin2hex($c));
        }
        $i = $i + 1;
    }
    return $out;
}

/**
 * Decode a whole header block into name => value.
 *
 * A header repeated more than once collapses to the LAST value here; php
 * returns an array of values for that case. Nothing in this corpus reads a
 * repeated header, and the divergence is named rather than hidden.
 *
 * @return array<string,string>|false
 */
function iconv_mime_decode_headers(string $headers, int $mode = 0, ?string $encoding = null): mixed
{
    $out = [];
    $lines = \explode("\n", \str_replace("\r\n", "\n", $headers));
    $name = "";
    $value = "";
    foreach ($lines as $line) {
        $l = \rtrim($line, "\r");
        if ($l === "") { continue; }
        // A leading space or tab continues the previous header (RFC 5322
        // folding), so it must not be read as a new field.
        if ($l[0] === " " || $l[0] === "\t") {
            if ($name !== "") { $value = $value . " " . \ltrim($l, " \t"); }
            continue;
        }
        if ($name !== "") {
            $out[$name] = \iconv_mime_decode($value, $mode, $encoding);
        }
        $colon = \strpos($l, ":");
        if ($colon === false) {
            $name = "";
            $value = "";
            continue;
        }
        $name = \substr($l, 0, $colon);
        $value = \ltrim(\substr($l, $colon + 1), " ");
    }
    if ($name !== "") {
        $out[$name] = \iconv_mime_decode($value, $mode, $encoding);
    }
    return $out;
}
