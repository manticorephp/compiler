<?php

/**
 * The mbstring family (docs/ROADMAP.md, "Unicode"). UTF-8 is handled here
 * directly, with Zend's exact treatment of malformed input; every other encoding
 * goes through the codecs in MbstringCodecs.php. Case mapping and width need
 * Unicode tables and are not here yet.
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
 * Each public function resolves its encoding to a canonical name and a KIND:
 * 0 UTF-8; 1 single-byte (length and cuts are byte arithmetic on the raw
 * string); 2 fixed-width UCS-2 / UCS-4 / UTF-32 (the same, per code unit —
 * which is what Zend does, a partial trailing unit included); 3 the rest, which
 * is decoded to marked UTF-8, worked on as UTF-8, and encoded back.
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
 * For malformed INPUT `long` and `entity` still emit the last codepoint set —
 * php's error markers carry no bytes to spell out; an unrepresentable character
 * gets `U+XXXX` / `&#xXXXX;`. Returns `[mode, codepoint]`.
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

/** Resolve an `$encoding` argument (null = the internal encoding) to its canonical name; ValueError if unknown. */
function __mc_mb_enc(?string $encoding, string $fn, int $arg): string
{
    if ($encoding === null) { return \__mc_mb_internal(); }
    $canon = \__mc_mb_canon($encoding);
    if ($canon === "") {
        throw new \ValueError($fn . "(): Argument #" . $arg . " (\$encoding) must be a valid encoding, \"" . $encoding . "\" given");
    }
    return $canon;
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

/** Codepoint of the (generalized) UTF-8 character at `[$i, $next)`; `$kind` 1 reads one raw byte. */
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

/** UTF-8 bytes of a codepoint (a surrogate is spelled too — generalized UTF-8). */
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

/** Whether `$cp` is a Unicode scalar value. */
function __mc_mb_scalar(int $cp): bool
{
    return $cp >= 0 && $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF);
}

/**
 * UTF-8 bytes `[$from, $to)` with every malformed unit replaced — by `$sub`
 * when given (the marker), else by the current substitute.
 */
function __mc_mb_scrub_range(int $kind, string $s, int $from, int $to, ?string $sub = null): string
{
    $out = "";
    $run = $from;
    $i = $from;
    $haveSub = $sub !== null;
    while ($i < $to) {
        $next = \__mc_mb_u8_step($s, $i, $to);
        if ($next > 0) { $i = $next; continue; }
        if (!$haveSub) { $sub = \__mc_mb_bad_bytes("UTF-8"); $haveSub = true; }
        $out = $out . \substr($s, $run, $i - $run) . $sub;
        $i = -$next;
        $run = $i;
    }
    if ($run === $from) { return \substr($s, $from, $to - $from); }
    return $out . \substr($s, $run, $to - $run);
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

/** Character count of UTF-8 under the decoder. */
function __mc_mb_u8_len(string $s): int
{
    $n = \strlen($s);
    $i = 0;
    $c = 0;
    while ($i < $n) {
        $next = \__mc_mb_u8_step($s, $i, $n);
        $i = $next < 0 ? -$next : $next;
        $c = $c + 1;
    }
    return $c;
}

/** Unit width for the raw-arithmetic kinds: 1 single-byte, 2/4 fixed-width; 0 = walk UTF-8. */
function __mc_mb_unit_width(string $enc, int $kind): int
{
    if ($kind === 1) { return 1; }
    if ($kind === 2) { return \__mc_mb_width($enc); }
    return 0;
}

/** Character count of `$s` in `$enc`. */
function __mc_mb_len(string $enc, int $kind, string $s): int
{
    if ($kind === 0) { return \__mc_mb_u8_len($s); }
    if ($kind === 3) {
        $u = \__mc_mb_dec8($enc, $s);
        return \__mc_mb_u8_fastlen($u, \strlen($u));
    }
    return \intdiv(\strlen($s), \__mc_mb_unit_width($enc, $kind));
}

/** Byte offset where character `$chars` starts, counting from byte `$from`; `$w` 0 walks UTF-8. */
function __mc_mb_seek(int $w, string $s, int $from, int $chars): int
{
    $n = \strlen($s);
    if ($w > 0) {
        $at = $from + $chars * $w;
        return $at > $n ? $n : $at;
    }
    $i = $from;
    while ($chars > 0 && $i < $n) {
        $next = \__mc_mb_u8_step($s, $i, $n);
        $i = $next < 0 ? -$next : $next;
        $chars = $chars - 1;
    }
    return $i;
}

/**
 * mb_substr's byte range `[from, to)` over `$s` (UTF-8 when `$w` is 0, else
 * units of `$w` bytes); `$length` null = to the end. Empty range = `[0, 0]`.
 * @return int[]
 */
function __mc_mb_range(int $w, string $s, int $start, ?int $length): array
{
    $total = -1;
    if ($start < 0 || ($length !== null && $length < 0)) {
        $total = $w > 0 ? \intdiv(\strlen($s), $w) : \__mc_mb_u8_len($s);
    }
    if ($start < 0) {
        $start = $total + $start;
        if ($start < 0) { $start = 0; }
    }
    $from = \__mc_mb_seek($w, $s, 0, $start);
    $n = \strlen($s);
    if ($from >= $n) { return [0, 0]; }
    $to = $n;
    if ($length !== null) {
        $len = $length;
        if ($len < 0) {
            $len = $total - $start + $len;
            if ($len <= 0) { return [0, 0]; }
        }
        $to = \__mc_mb_seek($w, $s, $from, $len);
    }
    return [$from, $to];
}

/** mb_substr's body for a resolved encoding. */
function __mc_mb_substr(string $enc, int $kind, string $s, int $start, ?int $length): string
{
    if ($kind === 3) {
        $u = \__mc_mb_dec8($enc, $s);
        $r = \__mc_mb_range(0, $u, $start, $length);
        return (string)\__mc_mb_enc8($enc, \substr($u, $r[0], $r[1] - $r[0]));
    }
    $r = \__mc_mb_range(\__mc_mb_unit_width($enc, $kind), $s, $start, $length);
    if ($kind === 0) { return \__mc_mb_scrub_range(0, $s, $r[0], $r[1]); }
    return \substr($s, $r[0], $r[1] - $r[0]);
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
    if (!\__mc_mb_scalar($cp)) {
        throw new \ValueError("mb_substitute_character(): Argument #1 (\$substitute_character) is not a valid codepoint");
    }
    \__mc_mb_subst(0, $cp);
    return true;
}

/** @return string[] */
function mb_list_encodings(): array
{
    return \__mc_mb_list();
}

/** @return string[] */
function mb_encoding_aliases(string $encoding): array
{
    return \__mc_mb_alias_table()[\__mc_mb_enc($encoding, "mb_encoding_aliases", 1)];
}

function mb_preferred_mime_name(string $encoding): string|false
{
    $enc = \__mc_mb_enc($encoding, "mb_preferred_mime_name", 1);
    $mime = \__mc_mb_mime_table()[$enc];
    if ($mime === "") {
        throw new \ValueError("mb_preferred_mime_name(): No MIME preferred name corresponding to \"" . $encoding . "\"");
    }
    return $mime;
}

function mb_strlen(string $string, ?string $encoding = null): int
{
    $enc = \__mc_mb_enc($encoding, "mb_strlen", 2);
    return \__mc_mb_len($enc, \__mc_mb_kind_of($enc), $string);
}

function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string
{
    $enc = \__mc_mb_enc($encoding, "mb_substr", 4);
    return \__mc_mb_substr($enc, \__mc_mb_kind_of($enc), $string, $start, $length);
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
 * the UNSNAPPED start. UTF-8 snaps back over 10xxxxxx bytes; UCS-2 / UTF-16 /
 * UCS-4 / UTF-32 to a multiple of the unit.
 */
function mb_strcut(string $string, int $start, ?int $length = null, ?string $encoding = null): string
{
    $enc = \__mc_mb_enc($encoding, "mb_strcut", 4);
    $kind = \__mc_mb_kind_of($enc);
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
    if ($kind === 1) { return \substr($string, $start, $len); }
    if ($kind !== 0) {
        // Fixed width: start and length each round down to a unit, the end clamps
        // to the last whole unit. UTF-16 instead clamps the length against
        // the UNALIGNED start, aligns start and length each on its own, and drops
        // a trailing high surrogate.
        $utf16 = \str_starts_with($enc, "UTF-16");
        $w = $utf16 ? 2 : \__mc_mb_width($enc);
        if ($w === 0) {
            throw new \Error("mb_strcut(): the \"" . $enc . "\" encoding is not supported yet");
        }
        if (!$utf16) {
            $start = $start - $start % $w;
            $end = $start + $len - $len % $w;
            if ($end > $n) { $end = $n - $n % $w; }
            return $end > $start ? \substr($string, $start, $end - $start) : "";
        }
        // A leading byte-order mark of plain UTF-16 is not part of what is cut.
        $le = \str_ends_with($enc, "LE");
        if ($enc === "UTF-16" && $n >= 2 && ($string[0] . $string[1] === "\xFE\xFF" || $string[0] . $string[1] === "\xFF\xFE")) {
            $le = $string[0] === "\xFF";
            $string = \substr($string, 2);
            $n = $n - 2;
            $start = $start < 2 ? 0 : $start - 2;
        }
        if ($len > $n - $start) { $len = $n - $start; }
        $start = $start - $start % $w;
        $len = $len - $len % $w;
        if ($len < $w) { return ""; }
        if ($utf16) {
            $last = \__mc_mb_unit($string, $start + $len - 2, 2, $le);
            if ($last >= 0xD800 && $last <= 0xDBFF) { $len = $len - 2; }
        }
        return \substr($string, $start, $len);
    }

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
    $enc = \__mc_mb_enc($encoding, "mb_str_split", 3);
    $kind = \__mc_mb_kind_of($enc);
    $out = [];
    if ($kind === 1 || $kind === 2) {
        $step = $length * \__mc_mb_unit_width($enc, $kind);
        $n = \strlen($string);
        $i = 0;
        while ($i < $n) {
            $out[] = \substr($string, $i, $step);
            $i = $i + $step;
        }
        return $out;
    }
    $s = $kind === 3 ? \__mc_mb_dec8($enc, $string) : $string;
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $j = $i;
        $k = $length;
        while ($k > 0 && $j < $n) {
            $j = $j + \__mc_mb_u8_mblen(\ord($s[$j]));
            $k = $k - 1;
        }
        if ($j > $n) { $j = $n; }
        $piece = \substr($s, $i, $j - $i);
        $out[] = $kind === 3 ? (string)\__mc_mb_enc8($enc, $piece) : $piece;
        $i = $j;
    }
    return $out;
}

/**
 * Search position shared by the strpos family; throws on an offset outside the
 * haystack. UTF-8 is searched raw, anything else decoded (marked) first.
 */
function __mc_mb_pos(string $fn, string $enc, string $haystack, string $needle, int $offset, bool $reverse): int
{
    if (\__mc_mb_kind_of($enc) !== 0) {
        $haystack = \__mc_mb_dec8($enc, $haystack);
        $needle = \__mc_mb_dec8($enc, $needle);
    }
    $at = \__mc_mb_find($haystack, $needle, $offset, $reverse);
    if ($at === -2) {
        throw new \ValueError($fn . "(): Argument #3 (\$offset) must be contained in argument #1 (\$haystack)");
    }
    return $at;
}

function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
{
    $at = \__mc_mb_pos("mb_strpos", \__mc_mb_enc($encoding, "mb_strpos", 4), $haystack, $needle, $offset, false);
    return $at < 0 ? false : $at;
}

function mb_strrpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null): int|false
{
    $at = \__mc_mb_pos("mb_strrpos", \__mc_mb_enc($encoding, "mb_strrpos", 4), $haystack, $needle, $offset, true);
    return $at < 0 ? false : $at;
}

function mb_strstr(string $haystack, string $needle, bool $before_needle = false, ?string $encoding = null): string|false
{
    $enc = \__mc_mb_enc($encoding, "mb_strstr", 4);
    $at = \__mc_mb_pos("mb_strstr", $enc, $haystack, $needle, 0, false);
    if ($at < 0) { return false; }
    $kind = \__mc_mb_kind_of($enc);
    return $before_needle ? \__mc_mb_substr($enc, $kind, $haystack, 0, $at) : \__mc_mb_substr($enc, $kind, $haystack, $at, null);
}

function mb_strrchr(string $haystack, string $needle, bool $before_needle = false, ?string $encoding = null): string|false
{
    $enc = \__mc_mb_enc($encoding, "mb_strrchr", 4);
    $at = \__mc_mb_pos("mb_strrchr", $enc, $haystack, $needle, 0, true);
    if ($at < 0) { return false; }
    $kind = \__mc_mb_kind_of($enc);
    return $before_needle ? \__mc_mb_substr($enc, $kind, $haystack, 0, $at) : \__mc_mb_substr($enc, $kind, $haystack, $at, null);
}

function mb_substr_count(string $haystack, string $needle, ?string $encoding = null): int
{
    $enc = \__mc_mb_enc($encoding, "mb_substr_count", 3);
    if ($needle === "") {
        throw new \ValueError("mb_substr_count(): Argument #2 (\$needle) must not be empty");
    }
    $n = \__mc_mb_dec8($enc, $needle);
    if ($n === "") {
        throw new \ValueError("mb_substr_count(): Argument #2 (\$needle) must not be empty");
    }
    return \substr_count(\__mc_mb_dec8($enc, $haystack), $n);
}

/** Whether `$s` has no malformed unit in `$enc`. */
function __mc_mb_valid(string $enc, string $s): bool
{
    if (\str_starts_with($enc, "UCS-4")) { return \strlen($s) % 4 === 0; }
    if (\__mc_mb_kind_of($enc) !== 0) { return !\str_contains(\__mc_mb_dec8($enc, $s), "\xFF"); }
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $i = \__mc_mb_u8_step($s, $i, $n);
        if ($i < 0) { return false; }
    }
    return true;
}

/** @param array<mixed> $value */
function __mc_mb_valid_array(string $enc, array $value): bool
{
    foreach ($value as $k => $v) {
        if (\is_string($k) && !\__mc_mb_valid($enc, $k)) { return false; }
        if (\is_string($v)) {
            if (!\__mc_mb_valid($enc, $v)) { return false; }
        } elseif (\is_array($v)) {
            if (!\__mc_mb_valid_array($enc, $v)) { return false; }
        }
    }
    return true;
}

function mb_check_encoding(array|string|null $value = null, ?string $encoding = null): bool
{
    $enc = \__mc_mb_enc($encoding, "mb_check_encoding", 2);
    if ($value === null) { return true; }
    if (\is_array($value)) { return \__mc_mb_valid_array($enc, $value); }
    return \__mc_mb_valid($enc, (string)$value);
}

/** A single-byte string with only its unmapped bytes substituted — every other byte stays as it is. */
function __mc_mb_sb_scrub(string $enc, string $s): string
{
    $tab = \__mc_mb_sb_tables()[$enc];
    $out = "";
    $n = \strlen($s);
    $run = 0;
    $i = 0;
    while ($i < $n) {
        $b = \ord($s[$i]);
        if ($b >= 0x80 && $tab[$b - 0x80] < 0) {
            $out = $out . \substr($s, $run, $i - $run) . \__mc_mb_bad_bytes($enc);
            $run = $i + 1;
        }
        $i = $i + 1;
    }
    if ($run === 0) { return $s; }
    return $out . \substr($s, $run);
}

function mb_scrub(string $string, ?string $encoding = null): string
{
    $enc = \__mc_mb_enc($encoding, "mb_scrub", 2);
    $kind = \__mc_mb_kind_of($enc);
    if ($kind === 0) { return \__mc_mb_scrub_range(0, $string, 0, \strlen($string)); }
    if ($kind === 1) { return \__mc_mb_sb_scrub($enc, $string); }
    if (\str_starts_with($enc, "UCS-4")) {
        $out = "";
        foreach (\__mc_mb_fixed_units($enc, $string) as $v) {
            $out = $out . ($v < 0 ? \__mc_mb_bad_bytes($enc) : \__mc_mb_pack($v, 4, \str_ends_with($enc, "LE")));
        }
        return $out;
    }
    return (string)\__mc_mb_enc8($enc, \__mc_mb_dec8($enc, $string));
}

function mb_ord(string $string, ?string $encoding = null): int|false
{
    $enc = \__mc_mb_enc($encoding, "mb_ord", 2);
    if ($string === "") {
        throw new \ValueError("mb_ord(): Argument #1 (\$string) must not be empty");
    }
    if (\__mc_mb_kind_of($enc) === 0) {
        $next = \__mc_mb_u8_step($string, 0, \strlen($string));
        return $next < 0 ? false : \__mc_mb_cp(0, $string, 0, $next);
    }
    $units = \str_starts_with($enc, "UCS-4") ? \__mc_mb_fixed_units($enc, $string) : \__mc_mb_units(\__mc_mb_dec8($enc, $string));
    if ($units === [] || $units[0] < 0) { return false; }
    return $units[0];
}

function mb_chr(int $codepoint, ?string $encoding = null): string|false
{
    $enc = \__mc_mb_enc($encoding, "mb_chr", 2);
    if (\__mc_mb_kind_of($enc) === 0) { return \__mc_mb_scalar($codepoint) ? \__mc_mb_u8_chr($codepoint) : false; }
    // Past UTF-8 a surrogate is just a codepoint: UTF-16 and UCS-2 spell it, UTF-32 refuses it.
    if ($codepoint < 0 || $codepoint > 0x10FFFF) { return false; }
    $u = \__mc_mb_u8_chr($codepoint);
    $b = \__mc_mb_enc8($enc, $u, true);
    return $b === null ? false : $b;
}

function mb_str_pad(string $string, int $length, string $pad_string = " ", int $pad_type = STR_PAD_RIGHT, ?string $encoding = null): string
{
    if ($pad_string === "") {
        throw new \ValueError("mb_str_pad(): Argument #3 (\$pad_string) must not be empty");
    }
    if ($pad_type !== STR_PAD_LEFT && $pad_type !== STR_PAD_RIGHT && $pad_type !== STR_PAD_BOTH) {
        throw new \ValueError("mb_str_pad(): Argument #4 (\$pad_type) must be STR_PAD_LEFT, STR_PAD_RIGHT, or STR_PAD_BOTH");
    }
    $enc = \__mc_mb_enc($encoding, "mb_str_pad", 5);
    $kind = \__mc_mb_kind_of($enc);
    $have = \__mc_mb_len($enc, $kind, $string);
    if ($length <= $have) { return $string; }
    $fill = $length - $have;
    $left = 0;
    if ($pad_type === STR_PAD_LEFT) {
        $left = $fill;
    } elseif ($pad_type === STR_PAD_BOTH) {
        $left = \intdiv($fill, 2);
    }
    $padLen = \__mc_mb_len($enc, $kind, $pad_string);
    if ($padLen === 0) {
        throw new \ValueError("mb_str_pad(): Argument #3 (\$pad_string) must not be empty");
    }
    return \__mc_mb_pad_run($enc, $kind, $pad_string, $padLen, $left) . $string
        . \__mc_mb_pad_run($enc, $kind, $pad_string, $padLen, $fill - $left);
}

/** `$chars` characters of the pad string repeated. */
function __mc_mb_pad_run(string $enc, int $kind, string $pad, int $padLen, int $chars): string
{
    if ($chars <= 0 || $padLen <= 0) { return ""; }
    $out = \str_repeat($pad, \intdiv($chars, $padLen));
    $rest = $chars % $padLen;
    if ($rest > 0) { $out = $out . \__mc_mb_substr($enc, $kind, $pad, 0, $rest); }
    return $out;
}

/**
 * The trim set as a codepoint => true map: `$characters` decoded, or php's
 * default (ASCII whitespace + NUL + the Unicode spaces).
 * @return array<int,bool>
 */
function __mc_mb_trim_set(string $enc, ?string $characters): array
{
    $set = [];
    if ($characters !== null) {
        $units = \__mc_mb_kind_of($enc) === 2 ? \__mc_mb_fixed_units($enc, $characters) : \__mc_mb_units(\__mc_mb_dec8($enc, $characters));
        foreach ($units as $cp) { $set[$cp] = true; }
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

/**
 * Shared trim body: `$side` 1 left, 2 right, 3 both. Zend's shape, kept because
 * it is observable: count the leading and trailing trimmable characters in one
 * pass, then answer mb_substr(left, total - left - right). When BOTH sides
 * swallow everything that length goes negative (a size_t underflow in C) and
 * the cut runs to the end of the string; for a fixed-width encoding the counts
 * are of units after any byte-order mark while the cut is raw, mark included.
 */
function __mc_mb_trim(string $fn, int $side, string $string, ?string $characters, ?string $encoding): string
{
    $enc = \__mc_mb_enc($encoding, $fn, 3);
    $set = \__mc_mb_trim_set($enc, $characters);
    $kind = \__mc_mb_kind_of($enc);
    $cps = $kind === 2 ? \__mc_mb_fixed_units($enc, $string) : \__mc_mb_units(\__mc_mb_dec8($enc, $string));
    $left = 0;
    $right = 0;
    $leading = ($side & 1) !== 0;
    foreach ($cps as $cp) {
        if (isset($set[$cp])) {
            if ($leading) { $left = $left + 1; }
            if (($side & 2) !== 0) { $right = $right + 1; }
        } else {
            $leading = false;
            $right = 0;
        }
    }
    if ($left === 0 && $right === 0) { return $string; }
    $len = \count($cps) - $left - $right;
    return \__mc_mb_substr($enc, $kind, $string, $left, $len < 0 ? null : $len);
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

/**
 * The candidate source encodings of mb_convert_encoding's third argument.
 * @param string[]|string|null $from
 * @return string[]
 */
function __mc_mb_from_list(array|string|null $from): array
{
    $names = [];
    if ($from === null) {
        $names = [\__mc_mb_internal()];
    } elseif (\is_string($from)) {
        foreach (\explode(",", $from) as $part) {
            $part = \trim($part, " \t");
            if ($part !== "") { $names[] = $part; }
        }
    } else {
        foreach ($from as $part) { $names[] = (string)$part; }
    }
    if ($names === []) {
        throw new \ValueError("mb_convert_encoding(): Argument #3 (\$from_encoding) must specify at least one encoding");
    }
    $out = [];
    foreach ($names as $name) {
        $canon = \__mc_mb_canon($name);
        if ($canon === "") {
            throw new \ValueError("mb_convert_encoding(): Argument #3 (\$from_encoding) contains invalid encoding \"" . $name . "\"");
        }
        $out[] = $canon;
    }
    return $out;
}

/**
 * Convert one string. With several candidates the first one the string is valid
 * in wins, else the first — Zend scores candidates instead (step 2b of the plan).
 * @param string[] $froms
 */
function __mc_mb_convert(string $s, string $to, array $froms): string
{
    $from = $froms[0];
    if (\count($froms) > 1) {
        foreach ($froms as $cand) {
            if (\__mc_mb_valid($cand, $s)) {
                $from = $cand;
                break;
            }
        }
    }
    if ($from === $to && \__mc_mb_kind_of($to) === 1) { return \__mc_mb_sb_scrub($to, $s); }
    return (string)\__mc_mb_enc8($to, \__mc_mb_dec8($from, $s, true));
}

/**
 * @param array<mixed> $a
 * @param string[] $froms
 * @return array<mixed>
 */
function __mc_mb_convert_array(array $a, string $to, array $froms): array
{
    $out = [];
    foreach ($a as $k => $v) {
        $key = \is_string($k) ? \__mc_mb_convert($k, $to, $froms) : $k;
        if (\is_string($v)) {
            $out[$key] = \__mc_mb_convert($v, $to, $froms);
        } elseif (\is_array($v)) {
            $out[$key] = \__mc_mb_convert_array($v, $to, $froms);
        } else {
            $out[$key] = $v;
        }
    }
    return $out;
}

function mb_convert_encoding(array|string $string, string $to_encoding, array|string|null $from_encoding = null): array|string|false
{
    $to = \__mc_mb_canon($to_encoding);
    if ($to === "") {
        throw new \ValueError("mb_convert_encoding(): Argument #2 (\$to_encoding) must be a valid encoding, \"" . $to_encoding . "\" given");
    }
    $froms = \__mc_mb_from_list($from_encoding);
    if (\is_array($string)) { return \__mc_mb_convert_array($string, $to, $froms); }
    return \__mc_mb_convert($string, $to, $froms);
}
