<?php

/**
 * The mbstring family — step 1 of the Unicode plan (docs/ROADMAP.md): UTF-8 with
 * Zend's exact treatment of malformed input, plus the single-byte encodings
 * ASCII, 8bit and ISO-8859-1. Case mapping and width need Unicode tables and
 * are not here yet.
 *
 * Zend reads malformed UTF-8 three different ways and the difference is
 * observable, so each is reproduced where Zend uses it:
 *   - the DECODER (`__mc_mb_u8_step`): maximal-subpart errors, one per ill-formed
 *     prefix — what mb_strlen counts and what mb_substr/mb_scrub/mb_trim rebuild
 *     through, an error becoming the substitute character;
 *   - the MBLEN TABLE (`__mc_mb_u8_mblen`): the lead byte alone decides the
 *     length — what mb_str_split cuts by and what a search offset walks;
 *   - the FAST COUNT: bytes that are not 10xxxxxx — what a search position is
 *     measured in.
 * A valid string reads the same all three ways.
 *
 * An encoding is carried as a KIND: 0 UTF-8, 1 a single-byte encoding where every
 * byte is a character (8bit, ISO-8859-1), 2 ASCII (a byte past 0x7F is an error).
 */

/**
 * The internal encoding (canonical name). php starts it from `mbstring.internal_encoding`
 * / `default_charset`; a compiled binary has no ini, so it starts at UTF-8.
 */
function __mc_mb_internal(?string $set = null): string
{
    static $name = "UTF-8";
    if ($set !== null) { $name = $set; }
    return $name;
}

/**
 * The substitute setting: mode 0 a character, 1 "none", 2 "long", 3 "entity".
 * `long` and `entity` still emit the last codepoint set for a malformed UTF-8
 * input — php's error markers carry no bytes to spell out. Returns
 * `[mode, codepoint]`.
 * @return int[]
 */
function __mc_mb_subst(int $mode = -1, int $cp = -1): array
{
    static $m = 0;
    static $c = 63;
    if ($mode >= 0) { $m = $mode; }
    if ($cp >= 0) { $c = $cp; }
    return [$m, $c];
}

/** Canonical mbstring name of an encoding this runtime knows, or "". */
function __mc_mb_canon(string $encoding): string
{
    $e = \strtolower($encoding);
    if ($e === "utf-8" || $e === "utf8") { return "UTF-8"; }
    if ($e === "ascii" || $e === "us-ascii" || $e === "ansi_x3.4-1968" || $e === "iso646-us"
        || $e === "ibm367" || $e === "cp367" || $e === "us" || $e === "csascii") {
        return "ASCII";
    }
    if ($e === "8bit" || $e === "binary") { return "8bit"; }
    if ($e === "iso-8859-1" || $e === "iso8859-1" || $e === "iso_8859-1" || $e === "latin1") {
        return "ISO-8859-1";
    }
    return "";
}

/** Kind of a canonical encoding name. */
function __mc_mb_kind_of(string $canon): int
{
    if ($canon === "UTF-8") { return 0; }
    if ($canon === "ASCII") { return 2; }
    return 1;
}

/** Resolve an `$encoding` argument (null = the internal encoding) to its kind; ValueError if unknown. */
function __mc_mb_kind(?string $encoding, string $fn, int $arg): int
{
    if ($encoding === null) { return \__mc_mb_kind_of(\__mc_mb_internal()); }
    $canon = \__mc_mb_canon($encoding);
    if ($canon === "") {
        throw new \ValueError($fn . "(): Argument #" . $arg . " (\$encoding) must be a valid encoding, \"" . $encoding . "\" given");
    }
    return \__mc_mb_kind_of($canon);
}

/**
 * One decoder step from byte `$i` (< `$n`): the offset past a well-formed
 * character, or the NEGATED offset past an ill-formed prefix (always > `$i`, so
 * the sign is free). Maximal subpart: a lead byte followed by a byte outside its
 * allowed range is an error on its own, and the next byte starts afresh.
 */
function __mc_mb_u8_step(string $s, int $i, int $n): int
{
    $b = \ord($s[$i]);
    if ($b < 0x80) { return $i + 1; }
    if ($b < 0xC2 || $b > 0xF4) { return -($i + 1); }
    $lo = 0x80;
    $hi = 0xBF;
    $need = 1;
    if ($b >= 0xF0) {
        $need = 3;
        if ($b === 0xF0) { $lo = 0x90; }
        if ($b === 0xF4) { $hi = 0x8F; }
    } elseif ($b >= 0xE0) {
        $need = 2;
        if ($b === 0xE0) { $lo = 0xA0; }
        if ($b === 0xED) { $hi = 0x9F; }
    }
    $j = $i + 1;
    if ($j >= $n) { return -$j; }
    $c = \ord($s[$j]);
    if ($c < $lo || $c > $hi) { return -$j; }
    $j = $j + 1;
    while ($need > 1) {
        if ($j >= $n) { return -$j; }
        $c = \ord($s[$j]);
        if ($c < 0x80 || $c > 0xBF) { return -$j; }
        $j = $j + 1;
        $need = $need - 1;
    }
    return $j;
}

/** One step for any kind — see `__mc_mb_u8_step` for the sign convention. */
function __mc_mb_step(int $kind, string $s, int $i, int $n): int
{
    if ($kind === 0) { return \__mc_mb_u8_step($s, $i, $n); }
    if ($kind === 2 && \ord($s[$i]) >= 0x80) { return -($i + 1); }
    return $i + 1;
}

/** Codepoint of the well-formed character at `[$i, $next)`. */
function __mc_mb_cp(int $kind, string $s, int $i, int $next): int
{
    $b = \ord($s[$i]);
    if ($kind !== 0 || $next === $i + 1) { return $b; }
    $len = $next - $i;
    $cp = $len === 2 ? $b & 0x1F : ($len === 3 ? $b & 0x0F : $b & 0x07);
    $k = $i + 1;
    while ($k < $next) {
        $cp = ($cp << 6) | (\ord($s[$k]) & 0x3F);
        $k = $k + 1;
    }
    return $cp;
}

/** UTF-8 bytes of a codepoint (caller guarantees it is a scalar value). */
function __mc_mb_u8_chr(int $cp): string
{
    if ($cp < 0x80) { return \chr($cp); }
    if ($cp < 0x800) { return \chr(0xC0 | ($cp >> 6)) . \chr(0x80 | ($cp & 0x3F)); }
    if ($cp < 0x10000) {
        return \chr(0xE0 | ($cp >> 12)) . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
    }
    return \chr(0xF0 | ($cp >> 18)) . \chr(0x80 | (($cp >> 12) & 0x3F))
        . \chr(0x80 | (($cp >> 6) & 0x3F)) . \chr(0x80 | ($cp & 0x3F));
}

/** Whether `$kind` can spell codepoint `$cp`. */
function __mc_mb_representable(int $kind, int $cp): bool
{
    if ($cp < 0) { return false; }
    if ($kind === 0) { return $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF); }
    if ($kind === 2) { return $cp < 0x80; }
    return $cp <= 0xFF;
}

/** Bytes of codepoint `$cp` in `$kind` (caller checked it is representable). */
function __mc_mb_encode_cp(int $kind, int $cp): string
{
    return $kind === 0 ? \__mc_mb_u8_chr($cp) : \chr($cp);
}

/** What one malformed unit becomes in `$kind` under the current substitute setting. */
function __mc_mb_subst_bytes(int $kind): string
{
    $st = \__mc_mb_subst();
    if ($st[0] === 1) { return ""; }
    $cp = $st[1];
    if (!\__mc_mb_representable($kind, $cp)) { return $st[0] === 0 ? "?" : ""; }
    return \__mc_mb_encode_cp($kind, $cp);
}

/** Bytes `[$from, $to)` with every malformed unit replaced by the substitute. */
function __mc_mb_scrub_range(int $kind, string $s, int $from, int $to, ?string $sub = null): string
{
    if ($kind === 1) { return \substr($s, $from, $to - $from); }
    $out = "";
    $run = $from;
    $i = $from;
    $haveSub = $sub !== null;
    while ($i < $to) {
        $next = \__mc_mb_step($kind, $s, $i, $to);
        if ($next > 0) { $i = $next; continue; }
        if (!$haveSub) { $sub = \__mc_mb_subst_bytes($kind); $haveSub = true; }
        $out = $out . \substr($s, $run, $i - $run) . $sub;
        $i = -$next;
        $run = $i;
    }
    if ($run === $from) { return \substr($s, $from, $to - $from); }
    return $out . \substr($s, $run, $to - $run);
}

/**
 * Decoded codepoints, -1 for each malformed unit.
 * @return int[]
 */
function __mc_mb_decode(int $kind, string $s): array
{
    $out = [];
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $next = \__mc_mb_step($kind, $s, $i, $n);
        if ($next < 0) {
            $out[] = -1;
            $i = -$next;
            continue;
        }
        $out[] = \__mc_mb_cp($kind, $s, $i, $next);
        $i = $next;
    }
    return $out;
}

/**
 * Encode codepoints (`$from` inclusive to `$to` exclusive); -1 or an
 * unrepresentable codepoint becomes the substitute.
 * @param int[] $cps
 */
function __mc_mb_encode(int $kind, array $cps, int $from, int $to): string
{
    $out = "";
    $k = $from;
    while ($k < $to) {
        $cp = $cps[$k];
        $out = $out . (\__mc_mb_representable($kind, $cp) ? \__mc_mb_encode_cp($kind, $cp) : \__mc_mb_subst_bytes($kind));
        $k = $k + 1;
    }
    return $out;
}

/**
 * The string as UTF-8, malformed units substituted — what Zend counts substrings
 * in, and searches in for a non-UTF-8 encoding (a UTF-8 search runs on the raw bytes).
 * `$marker`: a malformed unit becomes "\xFF" instead — never valid UTF-8, so it
 * matches only another malformed unit, which is how Zend's decoded units compare.
 */
function __mc_mb_to_u8(int $kind, string $s, bool $marker = false): string
{
    if ($kind === 0) { return \__mc_mb_scrub_range(0, $s, 0, \strlen($s), $marker ? "\xFF" : null); }
    $n = \strlen($s);
    $i = 0;
    while ($i < $n && \ord($s[$i]) < 0x80) { $i = $i + 1; }
    if ($i === $n) { return $s; }
    $out = \substr($s, 0, $i);
    while ($i < $n) {
        $b = \ord($s[$i]);
        if ($b < 0x80) {
            $out = $out . $s[$i];
        } elseif ($kind === 2) {
            $out = $out . ($marker ? "\xFF" : \__mc_mb_subst_bytes(0));
        } else {
            $out = $out . \__mc_mb_u8_chr($b);
        }
        $i = $i + 1;
    }
    return $out;
}

/** mblen table length of the UTF-8 sequence led by byte `$b`. */
function __mc_mb_u8_mblen(int $b): int
{
    if ($b < 0xC2) { return 1; }
    if ($b < 0xE0) { return 2; }
    if ($b < 0xF0) { return 3; }
    if ($b < 0xF5) { return 4; }
    return 1;
}

/** Characters under the fast count: bytes of `[0, $to)` that are not 10xxxxxx. */
function __mc_mb_u8_fastlen(string $s, int $to): int
{
    $c = 0;
    $i = 0;
    while ($i < $to) {
        if ((\ord($s[$i]) & 0xC0) !== 0x80) { $c = $c + 1; }
        $i = $i + 1;
    }
    return $c;
}

/**
 * Byte offset of character `$offset` of a UTF-8 string the way Zend's search
 * walks it: forward by the mblen table, backward (negative) by the fast count.
 * -1 when the offset falls outside the string.
 */
function __mc_mb_u8_offset(string $s, int $offset): int
{
    $n = \strlen($s);
    if ($offset < 0) {
        $pos = $n;
        while ($offset < 0) {
            if ($pos <= 0) { return -1; }
            $pos = $pos - 1;
            if ((\ord($s[$pos]) & 0xC0) !== 0x80) { $offset = $offset + 1; }
        }
        return $pos;
    }
    $pos = 0;
    while ($offset > 0) {
        if ($pos >= $n) { return -1; }
        $pos = $pos + \__mc_mb_u8_mblen(\ord($s[$pos]));
        $offset = $offset - 1;
    }
    return $pos > $n ? $n : $pos;
}

/**
 * Zend's mb_find_strpos over UTF-8 haystack/needle: character position of the
 * first (or last) match, -1 not found, -2 offset outside the haystack.
 */
function __mc_mb_find(string $h, string $nd, int $offset, bool $reverse): int
{
    $start = \__mc_mb_u8_offset($h, $offset);
    if ($start < 0) { return -2; }
    $n = \strlen($h);
    $m = \strlen($nd);
    if ($n < $m) { return -1; }
    $at = false;
    if (!$reverse) {
        $at = \strpos($h, $nd, $start);
    } elseif ($offset >= 0) {
        $at = \strrpos(\substr($h, $start), $nd);
        if ($at !== false) { $at = $at + $start; }
    } else {
        $end = $start;
        $k = \__mc_mb_u8_fastlen($nd, $m);
        while ($k > 0 && $end < $n) {
            $end = $end + \__mc_mb_u8_mblen(\ord($h[$end]));
            $k = $k - 1;
        }
        if ($end > $n) { $end = $n; }
        $at = \strrpos(\substr($h, 0, $end), $nd);
    }
    if ($at === false) { return -1; }
    return \__mc_mb_u8_fastlen($h, $at);
}

/** Character count under the decoder. */
function __mc_mb_len(int $kind, string $s): int
{
    $n = \strlen($s);
    if ($kind !== 0) { return $n; }
    $i = 0;
    $c = 0;
    while ($i < $n) {
        $next = \__mc_mb_step($kind, $s, $i, $n);
        $i = $next < 0 ? -$next : $next;
        $c = $c + 1;
    }
    return $c;
}

/** Byte offset where decoded character `$chars` starts (`$from` = byte offset of character 0). */
function __mc_mb_seek(int $kind, string $s, int $from, int $chars): int
{
    if ($kind !== 0) { return $from + $chars; }
    $n = \strlen($s);
    $i = $from;
    while ($chars > 0 && $i < $n) {
        $next = \__mc_mb_step($kind, $s, $i, $n);
        $i = $next < 0 ? -$next : $next;
        $chars = $chars - 1;
    }
    return $i;
}

/** mb_substr's body for a resolved kind; `$length` null = to the end. */
function __mc_mb_substr(int $kind, string $s, int $start, ?int $length): string
{
    $total = -1;
    if ($start < 0 || ($length !== null && $length < 0)) { $total = \__mc_mb_len($kind, $s); }
    if ($start < 0) {
        $start = $total + $start;
        if ($start < 0) { $start = 0; }
    }
    $from = \__mc_mb_seek($kind, $s, 0, $start);
    $n = \strlen($s);
    if ($from >= $n) { return ""; }
    $to = $n;
    if ($length !== null) {
        $len = $length;
        if ($len < 0) {
            $len = $total - $start + $len;
            if ($len <= 0) { return ""; }
        }
        $to = \__mc_mb_seek($kind, $s, $from, $len);
    }
    return \__mc_mb_scrub_range($kind === 2 ? 1 : $kind, $s, $from, $to);
}

function mb_internal_encoding(?string $encoding = null): string|bool
{
    if ($encoding === null) { return \__mc_mb_internal(); }
    $canon = \__mc_mb_canon($encoding);
    if ($canon === "") {
        throw new \ValueError("mb_internal_encoding(): Argument #1 (\$encoding) must be a valid encoding, \"" . $encoding . "\" given");
    }
    \__mc_mb_internal($canon);
    return true;
}

function mb_substitute_character(string|int|null $substitute_character = null): string|int|bool
{
    if ($substitute_character === null) {
        $st = \__mc_mb_subst();
        if ($st[0] === 1) { return "none"; }
        if ($st[0] === 2) { return "long"; }
        if ($st[0] === 3) { return "entity"; }
        return $st[1];
    }
    if (\is_string($substitute_character)) {
        $v = \strtolower($substitute_character);
        $mode = $v === "none" ? 1 : ($v === "long" ? 2 : ($v === "entity" ? 3 : -1));
        if ($mode < 0) {
            throw new \ValueError("mb_substitute_character(): Argument #1 (\$substitute_character) must be \"none\", \"long\", \"entity\" or a valid codepoint");
        }
        \__mc_mb_subst($mode);
        return true;
    }
    $cp = (int)$substitute_character;
    if (!\__mc_mb_representable(0, $cp)) {
        throw new \ValueError("mb_substitute_character(): Argument #1 (\$substitute_character) is not a valid codepoint");
    }
    \__mc_mb_subst(0, $cp);
    return true;
}

function mb_strlen(string $string, ?string $encoding = null): int
{
    return \__mc_mb_len(\__mc_mb_kind($encoding, "mb_strlen", 2), $string);
}

function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
{
    return \__mc_mb_substr(\__mc_mb_kind($encoding, "mb_substr", 4), $string, $start, $length);
}

/**
 * `mb_strcut($string, $start, $length, $encoding)` — substr measured in BYTES,
 * then pulled back to character boundaries so a cut never splits a multibyte
 * sequence. That is the whole difference from mb_substr, which counts
 * characters: mb_strcut is what you want when a byte budget is the constraint
 * (a column width, a protocol field) and a mangled tail is not acceptable.
 *
 * php's order of operations is load-bearing and reproduced here: snap the START
 * down to a boundary FIRST, and only then measure $length from the snapped
 * offset — `mb_strcut("aä中b", 2, 4)` is two bytes, not five, because the
 * length runs from 1 rather than from 2. A negative $length is resolved against
 * the UNSNAPPED start. Snapping steps back over 10xxxxxx bytes only.
 */
function mb_strcut(string $string, int $start, ?int $length = null, ?string $encoding = null): string
{
    $kind = \__mc_mb_kind($encoding, "mb_strcut", 4);
    $n = \strlen($string);
    if ($start < 0) {
        $start = $n + $start;
        if ($start < 0) { $start = 0; }
    }
    if ($start > $n) { return ''; }
    $len = $n;
    if ($length !== null) {
        $len = $length;
        if ($len < 0) {
            $len = $n - $start + $len;
            if ($len < 0) { $len = 0; }
        }
    }
    if ($kind !== 0) { return \substr($string, $start, $len); }

    while ($start > 0 && $start < $n && (\ord($string[$start]) & 0xC0) === 0x80) { $start = $start - 1; }
    $end = $start + $len;
    if ($end > $n) { $end = $n; }
    while ($end > $start && $end < $n && (\ord($string[$end]) & 0xC0) === 0x80) { $end = $end - 1; }

    return \substr($string, $start, $end - $start);
}

/** @return string[] */
function mb_str_split(string $string, int $length = 1, ?string $encoding = null): array
{
    if ($length < 1) {
        throw new \ValueError("mb_str_split(): Argument #2 (\$length) must be greater than 0");
    }
    $kind = \__mc_mb_kind($encoding, "mb_str_split", 3);
    $n = \strlen($string);
    $out = [];
    if ($kind !== 0) {
        $i = 0;
        while ($i < $n) {
            $out[] = \substr($string, $i, $length);
            $i = $i + $length;
        }
        return $out;
    }
    $i = 0;
    while ($i < $n) {
        $j = $i;
        $k = $length;
        while ($k > 0 && $j < $n) {
            $j = $j + \__mc_mb_u8_mblen(\ord($string[$j]));
            $k = $k - 1;
        }
        if ($j > $n) { $j = $n; }
        $out[] = \substr($string, $i, $j - $i);
        $i = $j;
    }
    return $out;
}

/** Search position shared by the strpos family; throws on an offset outside the haystack. */
function __mc_mb_pos(string $fn, int $kind, string $haystack, string $needle, int $offset, bool $reverse): int
{
    if ($kind !== 0) {
        $haystack = \__mc_mb_to_u8($kind, $haystack, $kind === 2);
        $needle = \__mc_mb_to_u8($kind, $needle, $kind === 2);
    }
    $at = \__mc_mb_find($haystack, $needle, $offset, $reverse);
    if ($at === -2) {
        throw new \ValueError($fn . "(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    return $at;
}

function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
{
    $at = \__mc_mb_pos("mb_strpos", \__mc_mb_kind($encoding, "mb_strpos", 4), $haystack, $needle, $offset, false);
    return $at < 0 ? false : $at;
}

function mb_strrpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
{
    $at = \__mc_mb_pos("mb_strrpos", \__mc_mb_kind($encoding, "mb_strrpos", 4), $haystack, $needle, $offset, true);
    return $at < 0 ? false : $at;
}

function mb_strstr(string $haystack, string $needle, bool $before_needle = false, ?string $encoding = null): string|false
{
    $kind = \__mc_mb_kind($encoding, "mb_strstr", 4);
    $at = \__mc_mb_pos("mb_strstr", $kind, $haystack, $needle, 0, false);
    if ($at < 0) { return false; }
    return $before_needle ? \__mc_mb_substr($kind, $haystack, 0, $at) : \__mc_mb_substr($kind, $haystack, $at, null);
}

function mb_strrchr(string $haystack, string $needle, bool $before_needle = false, ?string $encoding = null): string|false
{
    $kind = \__mc_mb_kind($encoding, "mb_strrchr", 4);
    $at = \__mc_mb_pos("mb_strrchr", $kind, $haystack, $needle, 0, true);
    if ($at < 0) { return false; }
    return $before_needle ? \__mc_mb_substr($kind, $haystack, 0, $at) : \__mc_mb_substr($kind, $haystack, $at, null);
}

function mb_substr_count(string $haystack, string $needle, ?string $encoding = null): int
{
    $kind = \__mc_mb_kind($encoding, "mb_substr_count", 3);
    if ($needle === "") {
        throw new \ValueError("mb_substr_count(): Argument #2 (\$needle) must not be empty");
    }
    return \substr_count(\__mc_mb_to_u8($kind, $haystack, true), \__mc_mb_to_u8($kind, $needle, true));
}

/** Whether `$s` has no malformed unit in `$kind`. */
function __mc_mb_valid(int $kind, string $s): bool
{
    if ($kind === 1) { return true; }
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $i = \__mc_mb_step($kind, $s, $i, $n);
        if ($i < 0) { return false; }
    }
    return true;
}

/** @param array<mixed> $value */
function __mc_mb_valid_array(int $kind, array $value): bool
{
    foreach ($value as $k => $v) {
        if (\is_string($k) && !\__mc_mb_valid($kind, $k)) { return false; }
        if (\is_string($v)) {
            if (!\__mc_mb_valid($kind, $v)) { return false; }
        } elseif (\is_array($v)) {
            if (!\__mc_mb_valid_array($kind, $v)) { return false; }
        }
    }
    return true;
}

function mb_check_encoding(array|string|null $value = null, ?string $encoding = null): bool
{
    $kind = \__mc_mb_kind($encoding, "mb_check_encoding", 2);
    if ($value === null) { return true; }
    if (\is_array($value)) { return \__mc_mb_valid_array($kind, $value); }
    return \__mc_mb_valid($kind, (string)$value);
}

function mb_scrub(string $string, ?string $encoding = null): string
{
    $kind = \__mc_mb_kind($encoding, "mb_scrub", 2);
    return \__mc_mb_scrub_range($kind, $string, 0, \strlen($string));
}

function mb_ord(string $string, ?string $encoding = null): int|false
{
    $kind = \__mc_mb_kind($encoding, "mb_ord", 2);
    if ($string === "") {
        throw new \ValueError("mb_ord(): Argument #1 (\$string) must not be empty");
    }
    $next = \__mc_mb_step($kind, $string, 0, \strlen($string));
    if ($next < 0) { return false; }
    return \__mc_mb_cp($kind, $string, 0, $next);
}

function mb_chr(int $codepoint, ?string $encoding = null): string|false
{
    $kind = \__mc_mb_kind($encoding, "mb_chr", 2);
    if (!\__mc_mb_representable($kind, $codepoint)) { return false; }
    return \__mc_mb_encode_cp($kind, $codepoint);
}

function mb_str_pad(string $string, int $length, string $pad_string = " ", int $pad_type = STR_PAD_RIGHT, ?string $encoding = null): string
{
    if ($pad_string === "") {
        throw new \ValueError("mb_str_pad(): Argument #3 (\$pad_string) must not be empty");
    }
    if ($pad_type !== STR_PAD_LEFT && $pad_type !== STR_PAD_RIGHT && $pad_type !== STR_PAD_BOTH) {
        throw new \ValueError("mb_str_pad(): Argument #4 (\$pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH");
    }
    $kind = \__mc_mb_kind($encoding, "mb_str_pad", 5);
    $have = \__mc_mb_len($kind, $string);
    if ($length <= $have) { return $string; }
    $fill = $length - $have;
    $left = 0;
    if ($pad_type === STR_PAD_LEFT) {
        $left = $fill;
    } elseif ($pad_type === STR_PAD_BOTH) {
        $left = \intdiv($fill, 2);
    }
    $padLen = \__mc_mb_len($kind, $pad_string);
    return \__mc_mb_pad_run($kind, $pad_string, $padLen, $left) . $string
        . \__mc_mb_pad_run($kind, $pad_string, $padLen, $fill - $left);
}

/** `$chars` characters of the pad string repeated. */
function __mc_mb_pad_run(int $kind, string $pad, int $padLen, int $chars): string
{
    if ($chars <= 0) { return ""; }
    $out = \str_repeat($pad, \intdiv($chars, $padLen));
    $rest = $chars % $padLen;
    if ($rest > 0) { $out = $out . \__mc_mb_substr($kind, $pad, 0, $rest); }
    return $out;
}

/**
 * The trim set as a codepoint => true map: `$characters` decoded, or php's
 * default (ASCII whitespace + NUL + the Unicode spaces).
 * @return array<int,bool>
 */
function __mc_mb_trim_set(int $kind, ?string $characters): array
{
    $set = [];
    if ($characters !== null) {
        foreach (\__mc_mb_decode($kind, $characters) as $cp) { $set[$cp] = true; }
        return $set;
    }
    foreach ([0x20, 0x0C, 0x0A, 0x0D, 0x09, 0x0B, 0x00, 0xA0, 0x1680, 0x2028, 0x2029, 0x202F, 0x205F,
        0x3000, 0x85, 0x180E] as $cp) {
        $set[$cp] = true;
    }
    $cp = 0x2000;
    while ($cp <= 0x200A) {
        $set[$cp] = true;
        $cp = $cp + 1;
    }
    return $set;
}

/** Shared trim body: `$side` 1 left, 2 right, 3 both. */
function __mc_mb_trim(string $fn, int $side, string $string, ?string $characters, ?string $encoding): string
{
    $kind = \__mc_mb_kind($encoding, $fn, 3);
    $set = \__mc_mb_trim_set($kind, $characters);
    $cps = \__mc_mb_decode($kind, $string);
    $from = 0;
    $to = \count($cps);
    if (($side & 1) !== 0) {
        while ($from < $to && isset($set[$cps[$from]])) { $from = $from + 1; }
    }
    if (($side & 2) !== 0) {
        while ($to > $from && isset($set[$cps[$to - 1]])) { $to = $to - 1; }
    }
    if ($from === 0 && $to === \count($cps)) { return $string; }
    if ($kind !== 0) { return \substr($string, $from, $to - $from); }
    return \__mc_mb_encode($kind, $cps, $from, $to);
}

function mb_trim(string $string, ?string $characters = null, ?string $encoding = null): string
{
    return \__mc_mb_trim("mb_trim", 3, $string, $characters, $encoding);
}

function mb_ltrim(string $string, ?string $characters = null, ?string $encoding = null): string
{
    return \__mc_mb_trim("mb_ltrim", 1, $string, $characters, $encoding);
}

function mb_rtrim(string $string, ?string $characters = null, ?string $encoding = null): string
{
    return \__mc_mb_trim("mb_rtrim", 2, $string, $characters, $encoding);
}
