<?php

/**
 * Additional PHP string functions, pure-PHP over the compiler's inline string
 * primitives. Global namespace so `stripos(...)` etc. resolve directly. These
 * live in the string domain only (no array-value re-storing, no callbacks), so
 * they are safe as stdlib externs — unlike the array helpers, whose element type
 * erases across the stdlib boundary and which live in the prelude.
 */

/** Case-insensitive {@see strpos}. Returns the offset or false. A direct call is the
 *  `__mir_stripos` codegen builtin (scans from the offset, no lowercased copy);
 *  this body is the callable-string path and the bootstrap fallback. */
function stripos(string $haystack, string $needle, int $offset = 0): int|false
{
    return \strpos(\strtolower($haystack), \strtolower($needle), $offset);
}

/**
 * Case-insensitive {@see str_replace} (PHP `str_ireplace`). Matches are found
 * case-insensitively; the ORIGINAL casing of the non-matched text is preserved.
 * An array search applies each pair in order, exactly as `str_replace` does, and
 * `$count` accumulates over the pairs.
 *
 * @param array|string $search
 * @param array|string $replace
 */
function str_ireplace(array|string $search, array|string $replace, array|string $subject,
                      #[\Manticore\Attr\RefOut] int &$count = 0): array|string
{
    if (!\is_array($subject)) { return \str_ireplace__str($search, $replace, $subject, $count); }
    $count = 0;
    $out = [];
    foreach ($subject as $k => $one) {
        $c = 0;
        $out[$k] = \str_ireplace__str($search, $replace, (string)$one, $c);
        $count = $count + $c;
    }
    return $out;
}

/**
 * One string subject — the typed overload a statically-string subject reaches.
 * An array search applies each pair in ITERATION order (keys are irrelevant);
 * an array replace is positional in the same order, a missing one is ''.
 *
 * @param array|string $search
 * @param array|string $replace
 */
#[\Manticore\Attr\Overload('str_ireplace')]
function str_ireplace__str(array|string $search, array|string $replace, string $subject,
                           #[\Manticore\Attr\RefOut] int &$count = 0): string
{
    $count = 0;
    if (is_array($search)) {
        $out = $subject;
        /** @var string[] $needles */
        $needles = [];
        foreach ($search as $sv) { $needles[] = (string)$sv; }
        $n = \count($needles);
        $repIsArr = is_array($replace);
        /** @var string[] $reps */
        $reps = [];
        if ($repIsArr) { foreach ($replace as $rv) { $reps[] = (string)$rv; } }
        $i = 0;
        while ($i < $n) {
            $rep = $repIsArr ? ($reps[$i] ?? '') : (string)$replace;
            $hits = 0;
            $out = __mir_str_ireplace_one($needles[$i], $rep, $out, $hits);
            $count = $count + $hits;
            $i = $i + 1;
        }
        return $out;
    }
    return __mir_str_ireplace_one((string)$search, (string)$replace, $subject, $count);
}

/** Native codegen builtin (`__mir_str_ireplace_one`); this body is the bootstrap fallback. */
function __mc_str_ireplace_one(string $search, string $replace, string $subject): string
{
    if ($search === '') { return $subject; }
    $ls = \strtolower($subject);
    $ln = \strtolower($search);
    $sl = \strlen($search);
    $out = "";
    $pos = 0;
    while (true) {
        $hit = \strpos($ls, $ln, $pos);
        if ($hit === false) { $out = $out . \substr($subject, $pos); break; }
        $h = (int)$hit;
        $out = $out . \substr($subject, $pos, $h - $pos) . $replace;
        $pos = $h + $sl;
    }
    return $out;
}

/** Single case-insensitive search/replace pair; `$hits` receives the number of
 *  replacements it made (from the length delta, or a stripos walk when the
 *  replacement is as long as the search). */
function __mir_str_ireplace_one(string $search, string $replace, string $subject,
                                int &$hits): string
{
    $hits = 0;
    if ($search === '') { return $subject; }
    $d = \strlen($replace) - \strlen($search);
    if ($d === 0) {
        $sl = \strlen($search);
        $pos = \stripos($subject, $search, 0);
        while ($pos !== false) {
            $hits = $hits + 1;
            $pos = \stripos($subject, $search, $pos + $sl);
        }
        return \__mc_str_ireplace_one($search, $replace, $subject);
    }
    $out = \__mc_str_ireplace_one($search, $replace, $subject);
    $hits = \intdiv(\strlen($out) - \strlen($subject), $d);
    return $out;
}

/**
 * Wrap `$string` to lines of at most `$width` bytes, breaking at spaces with
 * `$break` (PHP `wordwrap`). With `$cut`, a word longer than `$width` is split;
 * otherwise it overflows the line. Existing `$break`/newline runs are respected.
 */
function wordwrap(string $string, int $width = 75, string $break = "\n", bool $cut = false): string
{
    if ($width < 1) { return $string; }
    $n = \strlen($string);
    $out = "";
    $lineStart = 0;
    $lastSpace = -1;
    $i = 0;
    while ($i < $n) {
        $c = $string[$i];
        if ($c === "\n") {
            $out = $out . \substr($string, $lineStart, $i - $lineStart + 1);
            $lineStart = $i + 1;
            $lastSpace = -1;
            $i = $i + 1;
            continue;
        }
        if ($c === " ") { $lastSpace = $i; }
        if ($i - $lineStart >= $width) {
            if ($lastSpace >= $lineStart) {
                $out = $out . \substr($string, $lineStart, $lastSpace - $lineStart) . $break;
                $lineStart = $lastSpace + 1;
                $lastSpace = -1;
            } elseif ($cut) {
                $out = $out . \substr($string, $lineStart, $width) . $break;
                $lineStart = $lineStart + $width;
                $lastSpace = -1;
            }
        }
        $i = $i + 1;
    }
    return $out . \substr($string, $lineStart);
}

/**
 * Number of matching characters between `$string1` and `$string2` (PHP
 * `similar_text`, 2-arg form): the longest common substring plus, recursively,
 * the same over the segments to its left and right.
 */
function similar_text(string $string1, string $string2,
                      #[\Manticore\Attr\RefOut] float &$percent = 0.0): int
{
    // The recursion has to stay in its own worker: php computes `$percent` once,
    // from the OUTER pair of lengths, and a `&$percent` threaded through the
    // recursive calls would be overwritten by the last segment to return.
    $sim = __mir_similar_text($string1, $string2);
    $total = \strlen($string1) + \strlen($string2);
    $percent = $total === 0 ? 0.0 : (float)$sim * 200.0 / (float)$total;
    return $sim;
}

/** The recursive half of {@see similar_text}: longest common substring plus the
 *  same over the segments left and right of it. */
function __mir_similar_text(string $string1, string $string2): int
{
    $la = \strlen($string1);
    $lb = \strlen($string2);
    if ($la === 0 || $lb === 0) { return 0; }
    $max = 0;
    $pa = 0;
    $pb = 0;
    for ($i = 0; $i < $la - $max; $i = $i + 1) {
        $c = \ord($string1[$i]);
        $room = $la - $i;
        for ($j = 0; $j < $lb - $max; $j = $j + 1) {
            if (\ord($string2[$j]) !== $c) { continue; }
            $lim = $lb - $j;
            if ($room < $lim) { $lim = $room; }
            $k = 1;
            while ($k < $lim && \ord($string1[$i + $k]) === \ord($string2[$j + $k])) {
                $k = $k + 1;
            }
            if ($k > $max) { $max = $k; $pa = $i; $pb = $j; }
        }
    }
    if ($max === 0) { return 0; }
    $sum = $max;
    $sum = $sum + __mir_similar_text(\substr($string1, 0, $pa), \substr($string2, 0, $pb));
    $sum = $sum + __mir_similar_text(\substr($string1, $pa + $max), \substr($string2, $pb + $max));
    return $sum;
}

/** Levenshtein edit distance between `$string1` and `$string2`, each insertion, replacement and
 *  deletion priced by its cost. One DP row in a malloc'd int64 buffer, updated in place
 *  (`$diag` carries the cell up-left of `$j`): raw loads and stores, no array. */
function levenshtein(string $string1, string $string2, int $insertion_cost = 1, int $replacement_cost = 1, int $deletion_cost = 1): int
{
    $la = \strlen($string1);
    $lb = \strlen($string2);
    if ($la === 0) { return $lb * $insertion_cost; }
    if ($lb === 0) { return $la * $deletion_cost; }
    $row = \Runtime\Libc\malloc(($lb + 1) * 8);
    for ($j = 0; $j <= $lb; $j = $j + 1) { \poke_i64($row, $j * 8, $j * $insertion_cost); }
    for ($i = 0; $i < $la; $i = $i + 1) {
        $c = \ord($string1[$i]);
        $diag = \peek_i64($row, 0);
        $left = $diag + $deletion_cost;
        \poke_i64($row, 0, $left);
        for ($j = 1; $j <= $lb; $j = $j + 1) {
            $up = \peek_i64($row, $j * 8);
            $m = $diag + (\ord($string2[$j - 1]) === $c ? 0 : $replacement_cost);
            $d = $up + $deletion_cost;
            if ($d < $m) { $m = $d; }
            $d = $left + $insertion_cost;
            if ($d < $m) { $m = $d; }
            \poke_i64($row, $j * 8, $m);
            $left = $m;
            $diag = $up;
        }
    }
    $r = \peek_i64($row, $lb * 8);
    \Runtime\Libc\free($row);
    return $r;
}

/** Case-insensitive {@see strrpos}. */
function strripos(string $haystack, string $needle, int $offset = 0): int|false
{
    return \strrpos(\strtolower($haystack), \strtolower($needle), $offset);
}

/** Case-insensitive {@see strstr}: the haystack from the first match of
 *  `$needle` (or before it when `$before_needle`), or false. */
function stristr(string $haystack, string $needle, bool $before_needle = false): string|false
{
    $p = \strpos(\strtolower($haystack), \strtolower($needle));
    if ($p === false) { return false; }
    if ($before_needle) { return \substr($haystack, 0, $p); }
    return \substr($haystack, $p);
}

/** The `$string` from the first byte that occurs in `$characters` to the end,
 *  or false when none does (PHP `strpbrk`). */
function strpbrk(string $string, string $characters): string|false
{
    $n = \strlen($string);
    for ($i = 0; $i < $n; $i = $i + 1) {
        if (__mask_has_byte($characters, \ord($string[$i]))) {
            return \substr($string, $i);
        }
    }
    return false;
}

/** Length of the initial segment of `$subject` consisting only of bytes in
 *  `$mask` (PHP `strspn`; the complement of the `strcspn` builtin). */
function strspn(string $subject, string $mask, int $offset = 0): int
{
    $n = \strlen($subject);
    if ($offset < 0) { $offset = $n + $offset; }
    if ($offset < 0) { $offset = 0; }
    $i = $offset;
    while ($i < $n) {
        if (!__mask_has_byte($mask, \ord($subject[$i]))) { break; }
        $i = $i + 1;
    }
    return $i - $offset;
}

/**
 * Replace the substring of `$string` at `$offset` (length `$length`, default to
 * the end) with `$replace` (PHP `substr_replace`, scalar form). A negative
 * offset/length counts from the end.
 */
function substr_replace(string $string, string $replace, int $offset, int $length = \PHP_INT_MAX): string
{
    $n = \strlen($string);
    if ($offset < 0) { $offset = $n + $offset; if ($offset < 0) { $offset = 0; } }
    elseif ($offset > $n) { $offset = $n; }
    $rest = $n - $offset;
    if ($length < 0) {
        $len = $rest + $length;
        if ($len < 0) { $len = 0; }
    } elseif ($length > $rest) {
        $len = $rest;                 // default PHP_INT_MAX or an overlong span → to end
    } else {
        $len = $length;
    }
    return \substr($string, 0, $offset) . $replace . \substr($string, $offset + $len);
}

/** Split `$string` into `$length`-byte chunks each followed by `$separator`
 *  (PHP `chunk_split`). */
function chunk_split(string $string, int $length = 76, string $separator = "\r\n"): string
{
    if ($length < 1) { $length = 1; }
    $n = \strlen($string);
    $out = "";
    $i = 0;
    while ($i < $n) {
        $out = $out . \substr($string, $i, $length) . $separator;
        $i = $i + $length;
    }
    return $out;
}

/** Backslash-escape the regex metacharacters `. \ + * ? [ ^ ] $ ( )` (PHP
 *  `quotemeta`). */
function quotemeta(string $string): string
{
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $string[$i];
        if ($c === '.' || $c === '\\' || $c === '+' || $c === '*' || $c === '?'
            || $c === '[' || $c === '^' || $c === ']' || $c === '$'
            || $c === '(' || $c === ')') {
            $out = $out . '\\';
        }
        $out = $out . $c;
    }
    return $out;
}

/** Lowercase-hex encoding of each byte (PHP `bin2hex`). */
function bin2hex(string $string): string
{
    return \__mc_bin2hex($string);
}

/** Native codegen builtin; this body is the bootstrap fallback. */
function __mc_bin2hex(string $string): string
{
    $d = "0123456789abcdef";
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($string[$i]);
        $out = $out . $d[($o >> 4) & 15] . $d[$o & 15];
    }
    return $out;
}

/** Decode a hex string back to bytes (PHP `hex2bin`). */
function hex2bin(string $string): string
{
    $n = \strlen($string);
    if (($n & 1) === 1) {
        throw new \ValueError('hex2bin(): Hexadecimal input string must have an even length');
    }
    if (\__mc_hex_ok($string) === 0) {
        throw new \ValueError('hex2bin(): Input string must be hexadecimal string');
    }
    return \__mc_hex2bin($string);
}

/** Native codegen builtin; this body is the bootstrap fallback. 1 when every byte is a hex digit. */
function __mc_hex_ok(string $string): int
{
    return \strspn($string, '0123456789abcdefABCDEF') === \strlen($string) ? 1 : 0;
}

/** Native codegen builtin; this body is the bootstrap fallback. */
function __mc_hex2bin(string $string): string
{
    $n = \strlen($string);
    $out = "";
    $i = 0;
    while ($i + 1 < $n) {
        $out = $out . \chr((__mc_hexval($string[$i]) << 4) | __mc_hexval($string[$i + 1]));
        $i = $i + 2;
    }
    return $out;
}

/** Hex-digit value of a single byte, 0 for a non-digit. */
function __mc_hexval(string $c): int
{
    $o = \ord($c);
    if ($o >= 48 && $o <= 57) { return $o - 48; }
    if ($o >= 97 && $o <= 102) { return $o - 87; }
    if ($o >= 65 && $o <= 70) { return $o - 55; }
    return 0;
}

/** ROT13 the ASCII letters, pass everything else through (PHP `str_rot13`). */
function str_rot13(string $string): string
{
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $o = \ord($string[$i]);
        if ($o >= 65 && $o <= 90) { $o = ($o - 65 + 13) % 26 + 65; }
        elseif ($o >= 97 && $o <= 122) { $o = ($o - 97 + 13) % 26 + 97; }
        $out = $out . \chr($o);
    }
    return $out;
}

/** Un-escape a backslash-quoted string (PHP `stripslashes`): `\\`→`\`, and a
 *  backslash before any other byte is dropped, keeping that byte. */
function stripslashes(string $string): string
{
    $n = \strlen($string);
    $out = "";
    $i = 0;
    while ($i < $n) {
        if ($string[$i] === '\\' && $i + 1 < $n) {
            $i = $i + 1;
        }
        $out = $out . $string[$i];
        $i = $i + 1;
    }
    return $out;
}

/**
 * `strtr($string, $from, $to)` / `strtr($string, $replace_pairs)` — php's
 * signature: `$from` is a byte map (with `$to`) or a pairs array (without it).
 * The typed forms are overloads ({@see \Manticore\Attr\Overload}), so a call
 * whose arguments are statically known skips this dispatch.
 */
function strtr(string $string, array|string $from, ?string $to = null): string
{
    if (\is_array($from)) {
        if ($to !== null) {
            throw new \TypeError('strtr(): Argument #2 ($from) must be of type string, array given');
        }
        return strtr__pairs($string, $from);
    }
    if ($to === null) {
        throw new \TypeError('strtr(): Argument #2 ($from) must be of type array, string given');
    }
    return strtr__bytes($string, $from, $to);
}

/**
 * The byte form: each byte of `$from` becomes the byte at the same index in
 * `$to`, up to the shorter of the two; for a byte `$from` repeats, the LAST
 * index wins. Untouched runs are skipped with one `strcspn` and copied whole,
 * so a string with nothing to translate (the common case: a lower-case header
 * name against an upper-case map) costs one scan and no allocation.
 */
#[\Manticore\Attr\Overload('strtr')]
function strtr__bytes(string $string, string $from, string $to): string
{
    $m = \strlen($from);
    $mt = \strlen($to);
    if ($mt < $m) { $m = $mt; }
    if ($m === 0) { return $string; }
    if (\strlen($from) > $m) { $from = \substr($from, 0, $m); }
    $n = \strlen($string);
    $i = \strcspn($string, $from);
    if ($i >= $n) { return $string; }
    $out = '';
    $run = 0;
    while ($i < $n) {
        $b = \ord($string[$i]);
        $r = \ord($to[\strrpos($from, \chr($b))]);
        if ($r !== $b) {
            $out = $out . \substr($string, $run, $i - $run) . \chr($r);
            $run = $i + 1;
        }
        $i = $i + 1;
        if ($i >= $n) { break; }
        $i = $i + \strcspn($string, $from, $i);
    }
    if ($run === 0) { return $string; }
    return $out . \substr($string, $run);
}

/**
 * The pairs form: at each position the LONGEST matching key wins, and replaced
 * text is never searched again. An empty key is a ValueError (php warns and skips).
 * Keys are bucketed by first byte, each bucket longest-first; the pairs are
 * copied into two vecs so a numeric-string key (which php stores as an int)
 * never reaches a string-keyed map.
 */
#[\Manticore\Attr\Overload('strtr')]
function strtr__pairs(string $string, array $replace_pairs): string
{
    /** @var string[] $keys */
    $keys = [];
    /** @var string[] $vals */
    $vals = [];
    foreach ($replace_pairs as $k => $v) {
        $key = (string)$k;
        if ($key === '') {
            throw new \ValueError('strtr(): Argument #2 ($from) must not contain an empty string key');
        }
        $keys[] = $key;
        $vals[] = (string)$v;
    }
    $np = \count($keys);
    if ($np === 0) { return $string; }
    $n = \strlen($string);
    /** @var int[] $klen */
    $klen = [];
    for ($p = 0; $p < $np; $p = $p + 1) { $klen[] = \strlen($keys[$p]); }
    $out = '';
    $run = 0;
    $i = 0;
    if ($np <= 8) {
        // Few keys: each one's next occurrence comes from strpos (a native
        // search), re-found only once the cursor has passed it. The earliest
        // occurrence wins, the longest key on a tie.
        /** @var int[] $nextAt */
        $nextAt = [];
        for ($p = 0; $p < $np; $p = $p + 1) { $nextAt[] = -1; }
        while (true) {
            $best = -1;
            $bestAt = $n + 1;
            for ($p = 0; $p < $np; $p = $p + 1) {
                $at = $nextAt[$p];
                if ($at < $i) {
                    $f = \strpos($string, $keys[$p], $i);
                    if ($f === false) { $at = $n + 1; } else { $at = (int)$f; }
                    $nextAt[$p] = $at;
                }
                if ($at < $bestAt || ($at === $bestAt && $best >= 0 && $klen[$p] > $klen[$best])) {
                    $best = $p;
                    $bestAt = $at;
                }
            }
            if ($best < 0) { break; }
            $out .= \substr($string, $run, $bestAt - $run);
            $out .= $vals[$best];
            $i = $bestAt + $klen[$best];
            $run = $i;
        }
        if ($run === 0) { return $string; }
        return $out . \substr($string, $run);
    }
    /** @var array<int, int[]> $buckets first byte → pair indices, longest key first */
    $buckets = [];
    for ($p = 0; $p < $np; $p = $p + 1) {
        $b = \ord($keys[$p][0]);
        $list = $buckets[$b] ?? [];
        $len = $klen[$p];
        $at = \count($list);
        while ($at > 0 && $klen[$list[$at - 1]] < $len) { $at = $at - 1; }
        \array_splice($list, $at, 0, [$p]);
        $buckets[$b] = $list;
    }
    $firsts = '';
    for ($p = 0; $p < $np; $p = $p + 1) { $firsts = $firsts . $keys[$p][0]; }
    while ($i < $n) {
        $i = $i + \strcspn($string, $firsts, $i);
        if ($i >= $n) { break; }
        $hit = -1;
        $cands = $buckets[\ord($string[$i])] ?? null;
        if ($cands !== null) {
            foreach ($cands as $p) {
                $kl = $klen[$p];
                if ($kl > $n - $i) { continue; }
                $key = $keys[$p];
                $j = 1;
                while ($j < $kl && \ord($string[$i + $j]) === \ord($key[$j])) { $j = $j + 1; }
                if ($j === $kl) { $hit = $p; break; }
            }
        }
        if ($hit < 0) { $i = $i + 1; continue; }
        $out .= \substr($string, $run, $i - $run);
        $out .= $vals[$hit];
        $i = $i + $klen[$hit];
        $run = $i;
    }
    if ($run === 0) { return $string; }
    return $out . \substr($string, $run);
}

/**
 * HTML-escape with php's flag surface: quote flags (ENT_COMPAT=2 / ENT_QUOTES=3
 * / ENT_NOQUOTES=0), the doc type (ENT_HTML401=0 / ENT_XML1=16 / ENT_XHTML=32 /
 * ENT_HTML5=48 — `'` is `&#039;` under HTML401 and `&apos;` elsewhere), the
 * invalid-UTF-8 policy (ENT_IGNORE=4 drops the sequence, ENT_SUBSTITUTE=8 puts
 * U+FFFD, neither ⇒ the whole result is ""), and `$double_encode=false`, which
 * leaves an `&` alone when it opens an entity the doc type knows. The default
 * flags are php 8.1's ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401 = 11 (a literal:
 * the builder is a generation behind and may not know the names yet).
 *
 * The hot loop reads BYTES (`ord($s[$i])` is a bounds-checked load, no
 * allocation) and copies whole untouched runs with one substr — the previous
 * body minted a 1-char string per byte and appended it, and ran 2× behind php on
 * plain text. A string with nothing to escape is returned as-is, as php does.
 * Only UTF-8 is validated; a single-byte encoding passes every byte through.
 * ENT_DISALLOWED is not honoured (TODO).
 */
function htmlspecialchars(string $string, int $flags = 11, ?string $encoding = null, bool $double_encode = true): string
{
    $n = \strlen($string);
    $doc = $flags & 48;
    $apos = ($flags & 1) !== 0 ? ($doc === 0 ? '&#039;' : '&apos;') : '';
    $quot = ($flags & 2) !== 0 ? '&quot;' : '';
    $utf8 = $encoding === null || $encoding === 'UTF-8' || $encoding === ''
        || \strcasecmp($encoding, 'UTF-8') === 0 || \strcasecmp($encoding, 'UTF8') === 0;
    $out = '';
    $start = 0;
    $i = 0;
    while ($i < $n) {
        $b = \ord($string[$i]);
        if ($b > 62) {
            if ($b < 128 || !$utf8) { $i = $i + 1; continue; }
            $len = __mc_utf8_seq_len($string, $i, $n, $b);
            if ($len > 0) { $i = $i + $len; continue; }
            // Invalid sequence: -$len bytes are consumed, php's own advance.
            if (($flags & 12) === 0) { return ''; }
            if ($i > $start) { $out = $out . \substr($string, $start, $i - $start); }
            if (($flags & 12) === 8) { $out = $out . "\xEF\xBF\xBD"; }
            $i = $i - $len;
            $start = $i;
            continue;
        }
        if ($b < 34 || ($b > 39 && $b < 60) || $b === 61) { $i = $i + 1; continue; }
        $rep = '';
        if ($b === 38) {
            if (!$double_encode && __mc_hs_entity_end($string, $i, $n, $doc) > 0) { $i = $i + 1; continue; }
            $rep = '&amp;';
        }
        elseif ($b === 60) { $rep = '&lt;'; }
        elseif ($b === 62) { $rep = '&gt;'; }
        elseif ($b === 34) { $rep = $quot; }
        elseif ($b === 39) { $rep = $apos; }
        if ($rep === '') { $i = $i + 1; continue; }
        if ($i > $start) { $out = $out . \substr($string, $start, $i - $start); }
        $out = $out . $rep;
        $i = $i + 1;
        $start = $i;
    }
    if ($start === 0) { return $string; }
    if ($start < $n) { $out = $out . \substr($string, $start); }
    return $out;
}

/**
 * Length of the UTF-8 sequence led by byte $b at $i, or MINUS the number of
 * bytes php's decoder skips when it is malformed (a lone lead skips 1; a
 * truncated sequence skips up to the byte that could start a new one; an
 * overlong form, a surrogate and a code point past U+10FFFF skip the whole
 * sequence) — ext/standard/html.c `get_next_char`, UTF-8 arm.
 */
function __mc_utf8_seq_len(string $s, int $i, int $n, int $b): int
{
    if ($b >= 0xC2 && $b <= 0xDF) {
        if ($i + 1 >= $n) { return -1; }
        $b1 = \ord($s[$i + 1]);
        if ($b1 >= 0x80 && $b1 <= 0xBF) { return 2; }
        return __mc_utf8_lead($b1) ? -1 : -2;
    }
    if ($b >= 0xE0 && $b <= 0xEF) {
        $b1 = $i + 1 < $n ? \ord($s[$i + 1]) : 0;
        $b2 = $i + 2 < $n ? \ord($s[$i + 2]) : 0;
        if ($b1 >= 0x80 && $b1 <= 0xBF && $b2 >= 0x80 && $b2 <= 0xBF) {
            $cp = (($b & 0x0F) << 12) | (($b1 & 0x3F) << 6) | ($b2 & 0x3F);
            return ($cp < 0x800 || ($cp >= 0xD800 && $cp <= 0xDFFF)) ? -3 : 3;
        }
        if ($i + 2 > $n || __mc_utf8_lead($b1)) { return -1; }
        if ($i + 3 > $n || __mc_utf8_lead($b2)) { return -2; }
        return -3;
    }
    if ($b >= 0xF0 && $b <= 0xF4) {
        $b1 = $i + 1 < $n ? \ord($s[$i + 1]) : 0;
        $b2 = $i + 2 < $n ? \ord($s[$i + 2]) : 0;
        $b3 = $i + 3 < $n ? \ord($s[$i + 3]) : 0;
        if ($b1 >= 0x80 && $b1 <= 0xBF && $b2 >= 0x80 && $b2 <= 0xBF && $b3 >= 0x80 && $b3 <= 0xBF) {
            $cp = (($b & 0x07) << 18) | (($b1 & 0x3F) << 12) | (($b2 & 0x3F) << 6) | ($b3 & 0x3F);
            return ($cp < 0x10000 || $cp > 0x10FFFF) ? -4 : 4;
        }
        if ($i + 2 > $n || __mc_utf8_lead($b1)) { return -1; }
        if ($i + 3 > $n || __mc_utf8_lead($b2)) { return -2; }
        if ($i + 4 > $n || __mc_utf8_lead($b3)) { return -3; }
        return -4;
    }
    return -1;
}

/** A byte that can open a UTF-8 sequence (ASCII or a valid lead byte). */
function __mc_utf8_lead(int $b): bool
{
    return $b < 0x80 || ($b >= 0xC2 && $b <= 0xF4);
}

/**
 * Position after the `;` when the `&` at $i opens an entity the doc type
 * accepts (a numeric reference to a code point ≤ U+10FFFF — non-zero outside
 * HTML401 — or a named one from the HTML 4.01 table, plus `apos` outside
 * HTML401), else 0. The `$double_encode=false` test.
 */
function __mc_hs_entity_end(string $s, int $i, int $n, int $doc): int
{
    $j = $i + 1;
    if ($j >= $n) { return 0; }
    if (\ord($s[$j]) === 35) {
        $j = $j + 1;
        $hex = $j < $n && (\ord($s[$j]) | 32) === 120;
        if ($hex) { $j = $j + 1; }
        $cp = 0;
        $digits = 0;
        while ($j < $n) {
            $d = \ord($s[$j]);
            if ($d >= 48 && $d <= 57) { $d = $d - 48; }
            elseif ($hex && $d >= 97 && $d <= 102) { $d = $d - 87; }
            elseif ($hex && $d >= 65 && $d <= 70) { $d = $d - 55; }
            else { break; }
            if ($cp <= 0x10FFFF) { $cp = $cp * ($hex ? 16 : 10) + $d; }
            $digits = $digits + 1;
            $j = $j + 1;
        }
        if ($digits === 0 || $j >= $n || \ord($s[$j]) !== 59) { return 0; }
        if ($cp > 0x10FFFF || ($doc !== 0 && $cp === 0)) { return 0; }
        return $j + 1;
    }
    $k = $j;
    while ($k < $n) {
        $d = \ord($s[$k]);
        if (($d >= 48 && $d <= 57) || ($d >= 65 && $d <= 90) || ($d >= 97 && $d <= 122)) { $k = $k + 1; continue; }
        break;
    }
    if ($k === $j || $k >= $n || \ord($s[$k]) !== 59) { return 0; }
    $name = \substr($s, $j, $k - $j);
    if ($name === 'apos') { return $doc !== 0 ? $k + 1 : 0; }
    $tab = __mc_html_entities_dec();
    return isset($tab[$name]) ? $k + 1 : 0;
}

/**
 * Rewrite C-style printf exponents (`1.5e+03`) to PHP style (`1.5e+3`): strip
 * leading zeros from the exponent digits, keeping at least one. Called by the
 * sprintf/printf codegen builtin when the format has an `%e`/`%E`/`%g`/`%G`
 * conversion (C always pads the exponent to two digits; PHP uses the minimum).
 */
function __mc_fix_exp(string $s): string
{
    $out = '';
    $n = strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = $s[$i];
        $out = $out . $c;
        $i = $i + 1;
        if ($c === 'e' || $c === 'E') {
            if ($i < $n && ($s[$i] === '+' || $s[$i] === '-')) {
                $out = $out . $s[$i];
                $i = $i + 1;
                // Drop a leading '0' only while another digit still follows,
                // so a lone `e+0` keeps its zero.
                while ($i + 1 < $n && $s[$i] === '0'
                    && $s[$i + 1] >= '0' && $s[$i + 1] <= '9') {
                    $i = $i + 1;
                }
            }
        }
    }
    return $out;
}

/**
 * addcslashes — backslash-escape every byte of $string that appears in the
 * $characters set. The `"a..z"` range syntax is expanded; control bytes are
 * NOT rendered as `\nnn` octal (they escape literally), which covers symfony's
 * quoting use — the full octal form is not modelled.
 */
function addcslashes(string $string, string $characters): string
{
    /** @var array<string, bool> $set */
    $set = [];
    $cl = \strlen($characters);
    $i = 0;
    while ($i < $cl) {
        // `x..y` inclusive byte range.
        if ($i + 3 < $cl && $characters[$i + 1] === '.' && $characters[$i + 2] === '.') {
            $lo = \ord($characters[$i]);
            $hi = \ord($characters[$i + 3]);
            if ($lo <= $hi) {
                for ($b = $lo; $b <= $hi; $b = $b + 1) { $set[\chr($b)] = true; }
            }
            $i = $i + 4;
            continue;
        }
        $set[$characters[$i]] = true;
        $i = $i + 1;
    }
    $out = '';
    $n = \strlen($string);
    for ($j = 0; $j < $n; $j = $j + 1) {
        $c = $string[$j];
        if (!isset($set[$c])) { $out .= $c; continue; }
        // php does NOT merely prefix a backslash: a byte outside printable
        // ASCII is rendered as its C ESCAPE — \n \t \r \a \v \b \f, or a
        // three-digit octal. Emitting `\` + the raw control byte instead made
        // the output the same LENGTH but a different string, which is invisible
        // until something diffs it.
        $b = \ord($c);
        if ($b < 32 || $b > 126) {
            $out .= '\\';
            if ($b === 10) { $out .= 'n'; continue; }
            if ($b === 9) { $out .= 't'; continue; }
            if ($b === 13) { $out .= 'r'; continue; }
            if ($b === 7) { $out .= 'a'; continue; }
            if ($b === 11) { $out .= 'v'; continue; }
            if ($b === 8) { $out .= 'b'; continue; }
            if ($b === 12) { $out .= 'f'; continue; }
            $out .= \sprintf('%03o', $b);
            continue;
        }
        $out .= '\\';
        $out .= $c;
    }
    return $out;
}

/**
 * `escapeshellarg($arg)` — the POSIX form: wrap in single quotes, and close /
 * escape / reopen around every embedded single quote. php's Windows variant
 * does not apply; this toolchain targets POSIX shells. A NUL cannot survive an
 * argv entry, so php drops it and so does this.
 */
function escapeshellarg(string $arg): string
{
    $clean = \str_replace("\0", '', $arg);

    return "'" . \str_replace("'", "'\\''", $clean) . "'";
}

/**
 * `escapeshellcmd($cmd)` — backslash-escape every shell metacharacter, quotes
 * included (php escapes unpaired ones; escaping both is what it does for the
 * shapes that matter here).
 */
function escapeshellcmd(string $cmd): string
{
    // php's own set. WHITESPACE IS NOT IN IT — `escapeshellcmd('ls -l; rm *')`
    // is `ls -l\; rm \*`, spaces untouched (the command still has to word-split).
    $meta = "#&;`|*?~<>^()[]{}$\\\n\xFF\"'";
    $out = '';
    $n = \strlen($cmd);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $cmd[$i];
        if ($c === "\0") { continue; }
        if (\strpos($meta, $c) !== false) { $out .= '\\'; }
        $out .= $c;
    }

    return $out;
}

/**
 * Reverse of addcslashes: interpret C-style escapes. Octal (`\101`), hex
 * (`\x41`), the named set, and `\<other>` → `<other>`, exactly as php does.
 */
function stripcslashes(string $string): string
{
    $out = "";
    $n = \strlen($string);
    $i = 0;
    while ($i < $n) {
        $c = $string[$i];
        if ($c !== "\\" || $i + 1 >= $n) {
            $out = $out . $c;
            $i = $i + 1;
            continue;
        }
        $d = $string[$i + 1];
        if ($d === "n") { $out = $out . "\n"; $i = $i + 2; continue; }
        if ($d === "t") { $out = $out . "\t"; $i = $i + 2; continue; }
        if ($d === "r") { $out = $out . "\r"; $i = $i + 2; continue; }
        if ($d === "a") { $out = $out . "\x07"; $i = $i + 2; continue; }
        if ($d === "v") { $out = $out . "\x0B"; $i = $i + 2; continue; }
        if ($d === "b") { $out = $out . "\x08"; $i = $i + 2; continue; }
        if ($d === "f") { $out = $out . "\x0C"; $i = $i + 2; continue; }
        if ($d === "x") {
            $j = $i + 2;
            $hex = "";
            while ($j < $n && \strlen($hex) < 2 && \ctype_xdigit($string[$j])) {
                $hex = $hex . $string[$j];
                $j = $j + 1;
            }
            if ($hex === "") { $out = $out . "x"; $i = $i + 2; continue; }
            $out = $out . \chr(\hexdec($hex));
            $i = $j;
            continue;
        }
        if ($d >= "0" && $d <= "7") {
            $j = $i + 1;
            $oct = "";
            while ($j < $n && \strlen($oct) < 3 && $string[$j] >= "0" && $string[$j] <= "7") {
                $oct = $oct . $string[$j];
                $j = $j + 1;
            }
            $out = $out . \chr(\octdec($oct) & 255);
            $i = $j;
            continue;
        }
        $out = $out . $d;
        $i = $i + 2;
    }
    return $out;
}

/**
 * Binary-safe comparison of a slice of `$haystack` against `$needle`.
 * Returns <0 / 0 / >0. `$length` null means "to the end of the longer of the
 * two", which is what symfony's startsWith/endsWith helpers rely on.
 */
function substr_compare(string $haystack, string $needle, int $offset, ?int $length = null, bool $case_insensitive = false): int
{
    if ($length !== null) {
        return \substr_compare__len($haystack, $needle, $offset, $length, $case_insensitive);
    }
    $hl = \strlen($haystack);
    $offset = \__mc_substr_cmp_offset($hl, $offset);
    $rest = $hl - $offset;
    $nl = \strlen($needle);
    return \__mc_substr_cmp($haystack, $needle, $offset, $rest > $nl ? $rest : $nl, $case_insensitive);
}

/** The form with an explicit length: no nullable parameter to unbox per call. */
#[\Manticore\Attr\Overload('substr_compare')]
function substr_compare__len(string $haystack, string $needle, int $offset, int $length, bool $case_insensitive = false): int
{
    if ($length <= 0) {
        if ($length === 0) { return 0; }
        throw new \ValueError('substr_compare(): Argument #4 ($length) must be greater than or equal to 0');
    }
    $offset = \__mc_substr_cmp_offset(\strlen($haystack), $offset);
    return \__mc_substr_cmp($haystack, $needle, $offset, $length, $case_insensitive);
}

function __mc_substr_cmp_offset(int $hl, int $offset): int
{
    if ($offset < 0) {
        $offset = $hl + $offset;
        if ($offset < 0) { $offset = 0; }
    }
    if ($offset > $hl) {
        throw new \ValueError('substr_compare(): Argument #3 ($offset) must be contained in argument #1 ($main_str)');
    }
    return $offset;
}

/** Compares in place (no substr copies); php returns the strcmp DIFFERENCE, which symfony tests against 0. */
function __mc_substr_cmp(string $haystack, string $needle, int $offset, int $len, bool $case_insensitive): int
{
    $rest = \strlen($haystack) - $offset;
    $nl = \strlen($needle);
    $la = $rest < $len ? $rest : $len;
    $lb = $nl < $len ? $nl : $len;
    $m = $la < $lb ? $la : $lb;
    $i = 0;
    if ($case_insensitive) {
        while ($i < $m) {
            $da = \ord($haystack[$offset + $i]);
            $db = \ord($needle[$i]);
            if ($da >= 65 && $da <= 90) { $da = $da + 32; }
            if ($db >= 65 && $db <= 90) { $db = $db + 32; }
            if ($da !== $db) { return $da - $db; }
            $i = $i + 1;
        }
        return $la - $lb;
    }
    while ($i < $m) {
        $da = \ord($haystack[$offset + $i]);
        $db = \ord($needle[$i]);
        if ($da !== $db) { return $da - $db; }
        $i = $i + 1;
    }
    return $la - $lb;
}

/**
 * php's canonicalize_version: every `-`, `_`, `+` and other non-alphanumeric
 * byte becomes a single `.`, and a `.` is inserted at each digit/non-digit
 * boundary. The first byte is copied as it is.
 */
function __mc_version_canon(string $v): string
{
    $n = \strlen($v);
    if ($n === 0) { return ""; }
    // Plain dotted numbers ("1.2.3") are already canonical.
    $plain = \ord($v[0]) !== 46 && \ord($v[$n - 1]) !== 46;
    $prevDot = false;
    for ($i = 0; $plain && $i < $n; $i = $i + 1) {
        $c = \ord($v[$i]);
        if ($c === 46) {
            if ($prevDot) { $plain = false; }
            $prevDot = true;
        } elseif ($c >= 48 && $c <= 57) {
            $prevDot = false;
        } else {
            $plain = false;
        }
    }
    if ($plain) { return $v; }
    $lp = \ord($v[0]);
    $out = $v[0];
    $lqDot = $lp === 46;
    $lpDig = $lp >= 48 && $lp <= 57;
    $lpNdig = !$lpDig && $lp !== 46;
    for ($i = 1; $i < $n; $i = $i + 1) {
        $c = \ord($v[$i]);
        $dig = $c >= 48 && $c <= 57;
        if ($c === 45 || $c === 95 || $c === 43) {
            if (!$lqDot) { $out .= "."; $lqDot = true; }
        } elseif (($lpNdig && $dig) || ($lpDig && !$dig && $c !== 46)) {
            if (!$lqDot) { $out .= "."; }
            $out .= $v[$i];
            $lqDot = false;
        } elseif (!$dig && !(($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122))) {
            if (!$lqDot) { $out .= "."; $lqDot = true; }
        } else {
            $out .= $v[$i];
            $lqDot = false;
        }
        $lp = $c;
        $lpDig = $dig;
        $lpNdig = !$dig && $c !== 46;
    }
    // A trailing separator is no part at all in php's ordering ("1." == "1").
    if ($lqDot && \strlen($out) > 1) { $out = \substr($out, 0, -1); }
    return $out;
}

/** Rank of a special version form: the first table entry that is a PREFIX of the part at `$p`, else -1. */
function __mc_version_rank(string $c, int $p): int
{
    $b = \ord($c[$p]);
    if ($b === 100) { return \substr_compare($c, "dev", $p, 3) === 0 ? 0 : -1; }
    if ($b === 97) { return 1; }
    if ($b === 98) { return 2; }
    if ($b === 82) { return \substr_compare($c, "RC", $p, 2) === 0 ? 3 : -1; }
    if ($b === 114) { return \substr_compare($c, "rc", $p, 2) === 0 ? 3 : -1; }
    if ($b === 35) { return 4; }
    if ($b === 112) { return 5; }
    return -1;
}

/** php_version_compare over two already-canonical strings. */
function __mc_version_cmp(string $c1, string $c2): int
{
    $l1 = \strlen($c1);
    $l2 = \strlen($c2);
    if ($l1 === 0 || $l2 === 0) {
        if ($l1 === 0 && $l2 === 0) { return 0; }
        return $l1 > 0 ? 1 : -1;
    }
    $p1 = 0;
    $p2 = 0;
    $n1 = 0;
    $n2 = 0;
    $cmp = 0;
    while ($p1 < $l1 && $p2 < $l2 && $n1 >= 0 && $n2 >= 0) {
        $f = \strpos($c1, ".", $p1);
        if ($f === false) { $n1 = -1; } else { $n1 = (int)$f; }
        $f = \strpos($c2, ".", $p2);
        if ($f === false) { $n2 = -1; } else { $n2 = (int)$f; }
        $b1 = \ord($c1[$p1]);
        $b2 = \ord($c2[$p2]);
        $d1 = $b1 >= 48 && $b1 <= 57;
        $d2 = $b2 >= 48 && $b2 <= 57;
        if ($d1 && $d2) {
            $e1 = $n1 < 0 ? $l1 : $n1;
            $e2 = $n2 < 0 ? $l2 : $n2;
            if ($e1 - $p1 <= 18 && $e2 - $p2 <= 18) {
                $x1 = 0;
                for ($q = $p1; $q < $e1; $q = $q + 1) { $x1 = $x1 * 10 + \ord($c1[$q]) - 48; }
                $x2 = 0;
                for ($q = $p2; $q < $e2; $q = $q + 1) { $x2 = $x2 * 10 + \ord($c2[$q]) - 48; }
                $cmp = $x1 < $x2 ? -1 : ($x1 > $x2 ? 1 : 0);
                if ($cmp !== 0) { break; }
                if ($n1 >= 0) { $p1 = $n1 + 1; }
                if ($n2 >= 0) { $p2 = $n2 + 1; }
                continue;
            }
            // strtol: leading zeros dropped, saturating at PHP_INT_MAX.
            $s1 = \ltrim(\substr($c1, $p1, $e1 - $p1), "0");
            $s2 = \ltrim(\substr($c2, $p2, $e2 - $p2), "0");
            if (\strlen($s1) > 19 || (\strlen($s1) === 19 && \strcmp($s1, "9223372036854775807") > 0)) { $s1 = "9223372036854775807"; }
            if (\strlen($s2) > 19 || (\strlen($s2) === 19 && \strcmp($s2, "9223372036854775807") > 0)) { $s2 = "9223372036854775807"; }
            $k = \strlen($s1) - \strlen($s2);
            if ($k === 0) { $k = \strcmp($s1, $s2); }
            $cmp = $k < 0 ? -1 : ($k > 0 ? 1 : 0);
        } else {
            $r1 = $d1 ? 4 : ($b1 === 46 ? -1 : \__mc_version_rank($c1, $p1));
            $r2 = $d2 ? 4 : ($b2 === 46 ? -1 : \__mc_version_rank($c2, $p2));
            $cmp = $r1 < $r2 ? -1 : ($r1 > $r2 ? 1 : 0);
        }
        if ($cmp !== 0) { break; }
        if ($n1 >= 0) { $p1 = $n1 + 1; }
        if ($n2 >= 0) { $p2 = $n2 + 1; }
    }
    if ($cmp === 0) {
        if ($n1 >= 0) {
            if ($p1 < $l1 && \ord($c1[$p1]) >= 48 && \ord($c1[$p1]) <= 57) { return 1; }
            return \__mc_version_cmp(\substr($c1, $p1), "#N");
        }
        if ($n2 >= 0) {
            if ($p2 < $l2 && \ord($c2[$p2]) >= 48 && \ord($c2[$p2]) <= 57) { return -1; }
            return \__mc_version_cmp("#N", \substr($c2, $p2));
        }
    }
    return $cmp;
}

/**
 * php's version_compare. With `$operator` it returns a bool; without, -1/0/1.
 * The `$operator` form is what symfony/console's CompleteCommand uses.
 */
function version_compare(string $version1, string $version2, ?string $operator = null): mixed
{
    $r = \__mc_version_cmp(\__mc_version_canon($version1), \__mc_version_canon($version2));
    if ($operator === null) { return $r; }
    if ($operator === "<" || $operator === "lt") { return $r < 0; }
    if ($operator === "<=" || $operator === "le") { return $r <= 0; }
    if ($operator === ">" || $operator === "gt") { return $r > 0; }
    if ($operator === ">=" || $operator === "ge") { return $r >= 0; }
    if ($operator === "==" || $operator === "=" || $operator === "eq") { return $r === 0; }
    if ($operator === "!=" || $operator === "<>" || $operator === "ne") { return $r !== 0; }
    throw new \ValueError('version_compare(): Argument #3 ($operator) must be a valid comparison operator');
}

/**
 * `count_chars($s, $mode)` — byte frequencies. php's five modes:
 *   0 every byte 0-255 => count · 1 only those that occur · 2 only those that do
 *   not · 3 a STRING of the bytes that occur · 4 a string of those that do not.
 *
 * @return array<int, int>|string
 */
function count_chars(string $string, int $mode = 0): array|string
{
    $counts = [];
    for ($i = 0; $i < 256; $i = $i + 1) { $counts[$i] = 0; }
    $len = \strlen($string);
    for ($i = 0; $i < $len; $i = $i + 1) {
        $b = \ord($string[$i]);
        $counts[$b] = $counts[$b] + 1;
    }
    if ($mode === 0) { return $counts; }
    if ($mode === 1 || $mode === 2) {
        $out = [];
        for ($i = 0; $i < 256; $i = $i + 1) {
            $hit = $counts[$i] > 0;
            if ($mode === 1 ? $hit : !$hit) { $out[$i] = $counts[$i]; }
        }
        return $out;
    }
    if ($mode === 3 || $mode === 4) {
        $out = '';
        for ($i = 0; $i < 256; $i = $i + 1) {
            $hit = $counts[$i] > 0;
            if ($mode === 3 ? $hit : !$hit) { $out = $out . \chr($i); }
        }
        return $out;
    }
    throw new \ValueError('count_chars(): Argument #2 ($mode) must be between 0 and 4 (inclusive)');
}

/**
 * `strtok()` — php's stateful tokenizer, both forms. The two-argument call sets
 * the string and takes the first token; the one-argument call continues from
 * where the last one stopped. That cursor is the whole reason this cannot be a
 * pure function: php keeps it per-process, and so does this.
 */
function strtok(string $a, ?string $b = null): string|false
{
    static $buf = '';
    static $pos = 0;
    $token = $b;
    if ($b === null) {
        $token = $a;
    } else {
        $buf = $a;
        $pos = 0;
    }
    $len = \strlen($buf);
    // Skip leading delimiters, then take everything up to the next one.
    while ($pos < $len && \strpos($token, $buf[$pos]) !== false) { $pos = $pos + 1; }
    if ($pos >= $len) { return false; }
    $start = $pos;
    while ($pos < $len && \strpos($token, $buf[$pos]) === false) { $pos = $pos + 1; }
    $out = \substr($buf, $start, $pos - $start);
    if ($pos < $len) { $pos = $pos + 1; }

    return $out;
}

/**
 * `utf8_decode()` — UTF-8 to ISO-8859-1, php's deprecated-but-present shape: a
 * code point above U+00FF, and every malformed sequence, becomes `?`.
 */
function utf8_decode(string $string): string
{
    $out = '';
    $len = \strlen($string);
    $i = 0;
    while ($i < $len) {
        $c = \ord($string[$i]);
        if ($c < 0x80) { $out = $out . $string[$i]; $i = $i + 1; continue; }
        if ($c >= 0xC0 && $c < 0xE0 && $i + 1 < $len) {
            $c2 = \ord($string[$i + 1]);
            $cp = (($c & 0x1F) << 6) | ($c2 & 0x3F);
            $out = $out . ($cp <= 0xFF ? \chr($cp) : '?');
            $i = $i + 2;
            continue;
        }
        // Three- and four-byte sequences are all above U+00FF, and anything else
        // is malformed; php answers '?' for both.
        if ($c >= 0xE0 && $c < 0xF0) { $out = $out . '?'; $i = $i + 3; continue; }
        if ($c >= 0xF0) { $out = $out . '?'; $i = $i + 4; continue; }
        $out = $out . '?';
        $i = $i + 1;
    }

    return $out;
}

function str_increment(string $string): string
{
    $len = \strlen($string);
    if ($len === 0) { throw new \ValueError('str_increment(): Argument #1 ($string) must not be empty'); }
    for ($k = 0; $k < $len; $k++) {
        $c = \ord($string[$k]);
        if (!(($c >= 48 && $c <= 57) || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122))) {
            throw new \ValueError('str_increment(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }
    }
    $i = $len - 1;
    while ($i >= 0) {
        $c = $string[$i];
        if ($c === 'z') { $string[$i] = 'a'; }
        elseif ($c === 'Z') { $string[$i] = 'A'; }
        elseif ($c === '9') { $string[$i] = '0'; }
        else { $string[$i] = \chr(\ord($c) + 1); return $string; }
        $i = $i - 1;
    }
    $f = $string[0];
    $pre = $f === '0' ? '1' : ($f === 'a' ? 'a' : 'A');
    return $pre . $string;
}

function str_decrement(string $string): string
{
    $len = \strlen($string);
    if ($len === 0) { throw new \ValueError('str_decrement(): Argument #1 ($string) must not be empty'); }
    for ($k = 0; $k < $len; $k++) {
        $c = \ord($string[$k]);
        if (!(($c >= 48 && $c <= 57) || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122))) {
            throw new \ValueError('str_decrement(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters');
        }
    }
    $first = $string[0];
    $orig = $string;
    $i = $len - 1;
    while ($i >= 0) {
        $c = $string[$i];
        if ($c === 'a') { $string[$i] = 'z'; }
        elseif ($c === 'A') { $string[$i] = 'Z'; }
        elseif ($c === '0') { $string[$i] = '9'; }
        else { $string[$i] = \chr(\ord($c) - 1); break; }
        $i = $i - 1;
    }
    if ($first === '0' || ($i < 0 && $len === 1)) {
        throw new \ValueError('str_decrement(): Argument #1 ($string) "' . $orig . '" is out of decrement range');
    }
    // borrow out of a leading a/A drops it ("aa" -> "z"); "10" -> "9"
    if ($i < 0 || ($len > 1 && $string[0] === '0')) {
        $string = \substr($string, 1);
    }
    return $string;
}

function quoted_printable_encode(string $string): string
{
    $len = \strlen($string);
    $out = '';
    $lp = 0;
    $hex = '0123456789ABCDEF';
    $i = 0;
    while ($i < $len) {
        $c = \ord($string[$i]);
        $i = $i + 1;
        if ($c === 13 && $i < $len && $string[$i] === "\n") {
            $out = $out . "\r\n";
            $i = $i + 1;
            $lp = 0;
            continue;
        }
        $nextCr = $i < $len && $string[$i] === "\r";
        if ($c < 32 || $c >= 127 || $c === 61 || ($c === 32 && $nextCr)) {
            $lp = $lp + 3;
            if (($lp > 75 && $c <= 0x7f)
                || ($c > 0x7f && $c <= 0xdf && $lp + 3 > 75)
                || ($c > 0xdf && $c <= 0xef && $lp + 6 > 75)
                || ($c > 0xef && $c <= 0xf4 && $lp + 9 > 75)) {
                $out = $out . "=\r\n";
                $lp = 3;
            }
            $out = $out . '=' . $hex[$c >> 4] . $hex[$c & 15];
        } else {
            $lp = $lp + 1;
            if ($lp > 75) {
                $out = $out . "=\r\n";
                $lp = 1;
            }
            $out = $out . $string[$i - 1];
        }
    }
    return $out;
}

function quoted_printable_decode(string $string): string
{
    $nul = \strpos($string, "\0");
    if ($nul !== false) { $string = \substr($string, 0, $nul); }
    $len = \strlen($string);
    $out = '';
    $i = 0;
    while ($i < $len) {
        $ch = $string[$i];
        if ($ch !== '=') { $out = $out . $ch; $i = $i + 1; continue; }
        if ($i + 2 < $len && \ctype_xdigit($string[$i + 1]) && \ctype_xdigit($string[$i + 2])) {
            $out = $out . \chr(\hexdec($string[$i + 1] . $string[$i + 2]));
            $i = $i + 3;
            continue;
        }
        $k = 1;
        while ($i + $k < $len && ($string[$i + $k] === ' ' || $string[$i + $k] === "\t")) { $k = $k + 1; }
        if ($i + $k >= $len) { $i = $i + $k; }
        elseif ($string[$i + $k] === "\r" && $i + $k + 1 < $len && $string[$i + $k + 1] === "\n") { $i = $i + $k + 2; }
        elseif ($string[$i + $k] === "\r" || $string[$i + $k] === "\n") { $i = $i + $k + 1; }
        else { $out = $out . '='; $i = $i + 1; }
    }
    return $out;
}

function convert_uuencode(string $string): string
{
    $len = \strlen($string);
    $out = '';
    $pos = 0;
    while ($pos < $len) {
        $n = $len - $pos;
        if ($n > 45) { $n = 45; }
        $out = $out . ($n === 0 ? '`' : \chr(($n & 63) + 32));
        for ($j = 0; $j < $n; $j += 3) {
            $b0 = \ord($string[$pos + $j]);
            $b1 = $pos + $j + 1 < $len ? \ord($string[$pos + $j + 1]) : 0;
            $b2 = $pos + $j + 2 < $len ? \ord($string[$pos + $j + 2]) : 0;
            $q = [$b0 >> 2, (($b0 << 4) & 48) | (($b1 >> 4) & 15), (($b1 << 2) & 60) | (($b2 >> 6) & 3), $b2 & 63];
            foreach ($q as $v) { $out = $out . ($v === 0 ? '`' : \chr($v + 32)); }
        }
        $out = $out . "\n";
        $pos = $pos + $n;
    }
    return $out . "`\n";
}

/** One uuencoded byte's 6-bit value; past the end it reads as NUL, like php's terminator. */
function __mc_uu_dec(string $s, int $i, int $e): int
{
    return $i < $e ? ((\ord($s[$i]) - 32) & 63) : ((0 - 32) & 63);
}

/** Where php warns and answers false (empty or malformed input), this throws ValueError. */
function convert_uudecode(string $string): string|false
{
    $e = \strlen($string);
    $bad = 'convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string';
    if ($e === 0) { throw new \ValueError($bad); }
    $out = '';
    $s = 0;
    $total = 0;
    $len = 0;
    while ($s < $e) {
        $len = __mc_uu_dec($string, $s, $e);
        $s = $s + 1;
        if ($len === 0) { break; }
        if ($len > $e) { throw new \ValueError($bad); }
        $total = $total + $len;
        $ee = $s + ($len === 45 ? 60 : (int)\floor($len * 1.33));
        if ($ee > $e) { throw new \ValueError($bad); }
        while ($s < $ee) {
            if ($s + 4 > $e) { throw new \ValueError($bad); }
            $out = $out . \chr(((__mc_uu_dec($string, $s, $e) << 2) | (__mc_uu_dec($string, $s + 1, $e) >> 4)) & 255)
                . \chr(((__mc_uu_dec($string, $s + 1, $e) << 4) | (__mc_uu_dec($string, $s + 2, $e) >> 2)) & 255)
                . \chr(((__mc_uu_dec($string, $s + 2, $e) << 6) | __mc_uu_dec($string, $s + 3, $e)) & 255);
            $s = $s + 4;
        }
        if ($len < 45) { break; }
        $s = $s + 1;
    }
    if ($total > \strlen($out)) {
        $out = $out . \chr(((__mc_uu_dec($string, $s, $e) << 2) | (__mc_uu_dec($string, $s + 1, $e) >> 4)) & 255);
        if ($total > 1) {
            $out = $out . \chr(((__mc_uu_dec($string, $s + 1, $e) << 4) | (__mc_uu_dec($string, $s + 2, $e) >> 2)) & 255);
            if ($total > 2) { $out = $out . \chr(((__mc_uu_dec($string, $s + 2, $e) << 6) | __mc_uu_dec($string, $s + 3, $e)) & 255); }
        }
    }
    return \substr($out, 0, $total);
}

function soundex(string $string): string
{
    // 0 = vowel-ish (dropped, resets run), -1 = H/W (transparent)
    $table = [0, 1, 2, 3, 0, 1, 2, -1, 0, 2, 2, 4, 5, 5, 0, 1, 2, 6, 2, 3, 0, 1, -1, 2, 0, 2];
    $len = \strlen($string);
    $res = '';
    $small = 0;
    $last = -1;
    for ($i = 0; $i < $len && $small < 4; $i++) {
        $code = \ord($string[$i]);
        if ($code >= 97 && $code <= 122) { $code = $code - 32; }
        if ($code < 65 || $code > 90) { continue; }
        if ($small === 0) {
            $res = $res . \chr($code);
            $small = 1;
            $last = $table[$code - 65];
        } else {
            $c = $table[$code - 65];
            if ($c !== $last) {
                $last = $c;
                if ($c > 0) { $res = $res . (string)$c; $small = $small + 1; }
            }
        }
    }
    while ($small < 4) { $res = $res . '0'; $small = $small + 1; }
    return $res;
}

function metaphone(string $string, int $max_phonemes = 0): string
{
    if ($max_phonemes < 0) {
        throw new \ValueError('metaphone(): Argument #2 ($max_phonemes) must be greater than or equal to 0');
    }
    // bit 1 vowel, 2 pass-through (FJLMNR), 4 affects H (CGPST), 8 soft (EIY), 16 no GH->F (BDH)
    $codes = [1, 16, 4, 16, 9, 2, 4, 16, 9, 2, 0, 2, 2, 2, 1, 4, 0, 2, 4, 4, 1, 0, 0, 0, 8, 0];
    $w = \strtoupper($string);
    $n = \strlen($w);
    $nul = \strpos($w, "\0");
    if ($nul !== false) { $n = $nul; }
    $out = '';
    $i = 0;
    while ($i < $n && !\ctype_alpha($w[$i])) { $i = $i + 1; }
    if ($i >= $n) { return ''; }
    $c = $w[$i];
    $nx = $i + 1 < $n ? $w[$i + 1] : '';
    if ($c === 'A') {
        if ($nx === 'E') { $out = 'E'; $i = $i + 2; } else { $out = 'A'; $i = $i + 1; }
    } elseif ($c === 'G' || $c === 'K' || $c === 'P') {
        if ($nx === 'N') { $out = 'N'; $i = $i + 2; }
    } elseif ($c === 'W') {
        if ($nx === 'R') { $out = 'R'; $i = $i + 2; }
        elseif ($nx === 'H' || ($nx !== '' && \ctype_alpha($nx) && ($codes[\ord($nx) - 65] & 1))) { $out = 'W'; $i = $i + 2; }
    } elseif ($c === 'X') {
        $out = 'S'; $i = $i + 1;
    } elseif ($c === 'E' || $c === 'I' || $c === 'O' || $c === 'U') {
        $out = $c; $i = $i + 1;
    }
    $plen = \strlen($out);
    for (; $i < $n && ($max_phonemes === 0 || $plen < $max_phonemes); $i++) {
        $c = $w[$i];
        if (!\ctype_alpha($c)) { continue; }
        $last = $i >= 1 ? $w[$i - 1] : '';
        if ($c === $last && $c !== 'C') { continue; }
        $nx = $i + 1 < $n ? $w[$i + 1] : '';
        $an = $nx !== '' && $i + 2 < $n ? $w[$i + 2] : '';
        $skip = 0;
        $nxCode = ($nx !== '' && \ctype_alpha($nx)) ? $codes[\ord($nx) - 65] : 0;
        $anCode = ($an !== '' && \ctype_alpha($an)) ? $codes[\ord($an) - 65] : 0;
        $lastCode = ($last !== '' && \ctype_alpha($last)) ? $codes[\ord($last) - 65] : 0;
        $p = '';
        if ($c === 'B') {
            if ($last !== 'M') { $p = 'B'; }
        } elseif ($c === 'C') {
            if ($nxCode & 8) {
                if ($an === 'A' && $nx === 'I') { $p = 'X'; }
                elseif ($last === 'S') { }
                else { $p = 'S'; }
            } elseif ($nx === 'H') {
                $p = 'X';
                $skip = 1;
            } else { $p = 'K'; }
        } elseif ($c === 'D') {
            if ($nx === 'G' && ($anCode & 8)) { $p = 'J'; $skip = 1; } else { $p = 'T'; }
        } elseif ($c === 'G') {
            if ($nx === 'H') {
                $b3 = $i >= 3 ? $w[$i - 3] : '';
                $b4 = $i >= 4 ? $w[$i - 4] : '';
                $b3Code = ($b3 !== '' && \ctype_alpha($b3)) ? $codes[\ord($b3) - 65] : 0;
                if (!(($b3Code & 16) || $b4 === 'H')) { $p = 'F'; $skip = 1; }
            } elseif ($nx === 'N') {
                $a3 = $i + 3 < $n ? $w[$i + 3] : '';
                if ($an === '' || !\ctype_alpha($an) || ($an === 'E' && $a3 === 'D')) { }
                else { $p = 'K'; }
            } elseif (($nxCode & 8) && $last !== 'G') { $p = 'J'; }
            else { $p = 'K'; }
        } elseif ($c === 'H') {
            if (($nxCode & 1) && !($lastCode & 4)) { $p = 'H'; }
        } elseif ($c === 'K') {
            if ($last !== 'C') { $p = 'K'; }
        } elseif ($c === 'P') {
            $p = $nx === 'H' ? 'F' : 'P';
        } elseif ($c === 'Q') {
            $p = 'K';
        } elseif ($c === 'S') {
            if ($nx === 'I' && ($an === 'O' || $an === 'A')) { $p = 'X'; }
            elseif ($nx === 'H') { $p = 'X'; $skip = 1; }
            else { $p = 'S'; }
        } elseif ($c === 'T') {
            if ($nx === 'I' && ($an === 'O' || $an === 'A')) { $p = 'X'; }
            elseif ($nx === 'H') { $p = '0'; $skip = 1; }
            elseif (!($nx === 'C' && $an === 'H')) { $p = 'T'; }
        } elseif ($c === 'V') {
            $p = 'F';
        } elseif ($c === 'W') {
            if ($nxCode & 1) { $p = 'W'; }
        } elseif ($c === 'X') {
            $p = 'KS';
        } elseif ($c === 'Y') {
            if ($nxCode & 1) { $p = 'Y'; }
        } elseif ($c === 'Z') {
            $p = 'S';
        } elseif ($c === 'F' || $c === 'J' || $c === 'L' || $c === 'M' || $c === 'N' || $c === 'R') {
            $p = $c;
        }
        $out = $out . $p;
        $plen = $plen + \strlen($p);
        $i = $i + $skip;
    }
    return $out;
}

/** `utf8_encode()` — ISO-8859-1 to UTF-8 (deprecated in php 8.2, still present). */
function utf8_encode(string $string): string
{
    $out = '';
    $len = \strlen($string);
    for ($i = 0; $i < $len; $i++) {
        $c = \ord($string[$i]);
        if ($c < 0x80) { $out = $out . $string[$i]; }
        else { $out = $out . \chr(0xC0 | ($c >> 6)) . \chr(0x80 | ($c & 0x3F)); }
    }
    return $out;
}

/** `chop()` — the alias of rtrim(). */
function chop(string $string, string $characters = " \n\r\t\v\x00"): string
{
    return \rtrim($string, $characters);
}

/**
 * `strcoll()` under the C locale, the only one a compiled binary runs in: byte-wise
 * comparison, answering the difference of the first differing bytes (php answers
 * whatever the host strcoll does — only the sign is portable).
 */
function strcoll(string $string1, string $string2): int
{
    $a = \strlen($string1);
    $b = \strlen($string2);
    $n = $a < $b ? $a : $b;
    for ($i = 0; $i < $n; $i++) {
        $d = \ord($string1[$i]) - \ord($string2[$i]);
        if ($d !== 0) { return $d; }
    }
    return $a === $b ? 0 : ($a < $b ? -\ord($string2[$n]) : \ord($string1[$n]));
}

/** Hebrev: convert logical Hebrew text (ISO-8859-8) to visual text. Port of php's string.c. */
function hebrev(string $string, int $max_chars_per_line = 0): string
{
    $n = \strlen($string);
    if ($n === 0) { return ""; }
    /** @var string[] $heb */
    $heb = \array_fill(0, $n, "");
    $tgt = $n - 1;
    $bs = 0; $be = 0; $tmp = 0;
    $b0 = \ord($string[0]);
    $hebBlock = $b0 >= 224 && $b0 <= 250;
    do {
        if ($hebBlock) {
            while ($tmp + 1 < $n && $be < $n - 1) {
                $c = $string[$tmp + 1];
                $o = \ord($c);
                if (!(($o >= 224 && $o <= 250) || $c === " " || $c === "\t" || \ctype_punct($c) || $c === "\n")) { break; }
                $tmp++; $be++;
            }
            for ($i = $bs + 1; $i <= $be + 1; $i++) {
                $c = $string[$i - 1];
                if ($c === "(") { $c = ")"; } elseif ($c === ")") { $c = "("; }
                elseif ($c === "[") { $c = "]"; } elseif ($c === "]") { $c = "["; }
                elseif ($c === "{") { $c = "}"; } elseif ($c === "}") { $c = "{"; }
                elseif ($c === "<") { $c = ">"; } elseif ($c === ">") { $c = "<"; }
                elseif ($c === "\\") { $c = "/"; } elseif ($c === "/") { $c = "\\"; }
                $heb[$tgt] = $c;
                $tgt--;
            }
            $hebBlock = false;
        } else {
            while ($tmp + 1 < $n && $be < $n - 1) {
                $c = $string[$tmp + 1];
                $o = \ord($c);
                if (($o >= 224 && $o <= 250) || $c === "\n") { break; }
                $tmp++; $be++;
            }
            while ($be > $bs) {
                $c = $string[$tmp];
                if (!($c === " " || $c === "\t" || \ctype_punct($c)) || $c === "/" || $c === "-") { break; }
                $tmp--; $be--;
            }
            for ($i = $be + 1; $i >= $bs + 1; $i--) {
                $heb[$tgt] = $string[$i - 1];
                $tgt--;
            }
            $hebBlock = true;
        }
        $bs = $be + 1;
    } while ($be < $n - 1);

    $out = "";
    $begin = $n - 1;
    $end = $n - 1;
    while (true) {
        $cc = 0;
        while (($max_chars_per_line === 0 || $cc < $max_chars_per_line) && $begin > 0) {
            $cc++;
            $begin--;
            if ($begin <= 0 || $heb[$begin] === "\n" || $heb[$begin] === "\r") {
                while ($begin > 0 && ($heb[$begin - 1] === "\n" || $heb[$begin - 1] === "\r")) { $begin--; $cc++; }
                break;
            }
        }
        if ($cc === $max_chars_per_line) {
            $ncc = $cc;
            $nb = $begin;
            while ($ncc > 0) {
                $c = $heb[$nb];
                if ($c === " " || $c === "\t" || $c === "\n" || $c === "\r") { break; }
                $nb++;
                $ncc--;
            }
            if ($ncc > 0) { $begin = $nb; }
        }
        $ob = $begin;
        if ($heb[$begin] === " " || $heb[$begin] === "\t") { $heb[$begin] = "\n"; }
        while ($begin <= $end && ($heb[$begin] === "\n" || $heb[$begin] === "\r")) { $begin++; }
        for ($i = $begin; $i <= $end; $i++) { $out = $out . $heb[$i]; }
        for ($i = $ob; $i <= $end && ($heb[$i] === "\n" || $heb[$i] === "\r"); $i++) { $out = $out . $heb[$i]; }
        $begin = $ob;
        if ($begin <= 0) { break; }
        $begin--;
        $end = $begin;
    }
    return $out;
}
