<?php

/**
 * Additional PHP string functions, pure-PHP over the compiler's inline string
 * primitives. Global namespace so `stripos(...)` etc. resolve directly. These
 * live in the string domain only (no array-value re-storing, no callbacks), so
 * they are safe as stdlib externs — unlike the array helpers, whose element type
 * erases across the stdlib boundary and which live in the prelude.
 */

/** Case-insensitive {@see strpos}. Returns the offset or false. */
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
function str_ireplace(array|string $search, array|string $replace, string $subject,
                      #[\Manticore\Attr\RefOut] int &$count = 0): string
{
    $count = 0;
    if (is_array($search)) {
        $out = $subject;
        $n = \count($search);
        $repIsArr = is_array($replace);
        $i = 0;
        while ($i < $n) {
            $rep = $repIsArr ? (string)($replace[$i] ?? '') : (string)$replace;
            $hits = 0;
            $out = __mir_str_ireplace_one((string)$search[$i], $rep, $out, $hits);
            $count = $count + $hits;
            $i = $i + 1;
        }
        return $out;
    }
    return __mir_str_ireplace_one((string)$search, (string)$replace, $subject, $count);
}

/** Single case-insensitive search/replace pair; `$hits` receives the number of
 *  replacements it made. */
function __mir_str_ireplace_one(string $search, string $replace, string $subject,
                                int &$hits): string
{
    $hits = 0;
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
        $hits = $hits + 1;
    }
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
    for ($i = 0; $i < $la; $i = $i + 1) {
        for ($j = 0; $j < $lb; $j = $j + 1) {
            $k = 0;
            while ($i + $k < $la && $j + $k < $lb && $string1[$i + $k] === $string2[$j + $k]) {
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

/** Levenshtein edit distance between `$string1` and `$string2` (insertions,
 *  deletions and substitutions each cost 1). */
function levenshtein(string $string1, string $string2): int
{
    $la = \strlen($string1);
    $lb = \strlen($string2);
    if ($la === 0) { return $lb; }
    if ($lb === 0) { return $la; }
    $prev = [];
    for ($j = 0; $j <= $lb; $j = $j + 1) { $prev[$j] = $j; }
    for ($i = 1; $i <= $la; $i = $i + 1) {
        $cur = [];
        $cur[0] = $i;
        for ($j = 1; $j <= $lb; $j = $j + 1) {
            $cost = $string1[$i - 1] === $string2[$j - 1] ? 0 : 1;
            $del = $prev[$j] + 1;
            $ins = $cur[$j - 1] + 1;
            $sub = $prev[$j - 1] + $cost;
            $m = $del;
            if ($ins < $m) { $m = $ins; }
            if ($sub < $m) { $m = $sub; }
            $cur[$j] = $m;
        }
        $prev = $cur;
    }
    return $prev[$lb];
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
        if (\strpos($characters, $string[$i]) !== false) {
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
        if (\strpos($mask, $subject[$i]) === false) { break; }
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
 * `strtr($string, $from, $to)` — translate each byte present in `$from` to the
 * byte at the same index in `$to` (only up to the shorter of the two). The
 * two-argument array form (`strtr($s, [$search => $replace, …])`) is not modelled
 * here — a stdlib extern would erase the pairs array's element type.
 */
function strtr(string $string, string $from, string $to): string
{
    $m = \strlen($from);
    $mt = \strlen($to);
    if ($mt < $m) { $m = $mt; }
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $string[$i];
        $rep = $c;
        for ($j = 0; $j < $m; $j = $j + 1) {
            if ($from[$j] === $c) { $rep = $to[$j]; break; }
        }
        $out = $out . $rep;
    }
    return $out;
}

/**
 * HTML-escape for `htmlspecialchars` with php 8.1+ default flags
 * (ENT_QUOTES | ENT_HTML401): `&`→`&amp;`, `<`→`&lt;`, `>`→`&gt;`,
 * `"`→`&quot;`, `'`→`&#039;`.
 */
function htmlspecialchars(string $string): string
{
    $n = \strlen($string);
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $string[$i];
        if ($c === '&') { $out = $out . '&amp;'; }
        elseif ($c === '<') { $out = $out . '&lt;'; }
        elseif ($c === '>') { $out = $out . '&gt;'; }
        elseif ($c === '"') { $out = $out . '&quot;'; }
        elseif ($c === "'") { $out = $out . '&#039;'; }
        else { $out = $out . $c; }
    }
    return $out;
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
    $hl = \strlen($haystack);
    if ($offset < 0) {
        $offset = $hl + $offset;
        if ($offset < 0) { $offset = 0; }
    }
    if ($offset > $hl) { return 1; }
    $nl = \strlen($needle);
    $rest = $hl - $offset;
    $len = $length === null ? ($rest > $nl ? $rest : $nl) : $length;
    if ($len < 0) { $len = 0; }
    $a = \substr($haystack, $offset, $len);
    $b = \substr($needle, 0, $len);
    if ($case_insensitive) {
        $a = \strtolower($a);
        $b = \strtolower($b);
    }
    // php returns the strcmp DIFFERENCE, not a normalised -1/1: symfony
    // compares against 0, but the oracle prints the real value.
    $la = \strlen($a);
    $lb = \strlen($b);
    $m = $la < $lb ? $la : $lb;
    $i = 0;
    while ($i < $m) {
        $da = \ord($a[$i]);
        $db = \ord($b[$i]);
        if ($da !== $db) { return $da - $db; }
        $i = $i + 1;
    }
    return $la - $lb;
}

/** php's version ordering rank for one canonicalised part. */
function __mc_version_order(string $part): int
{
    if ($part === "dev") { return 0; }
    if ($part === "alpha" || $part === "a") { return 1; }
    if ($part === "beta" || $part === "b") { return 2; }
    if ($part === "RC" || $part === "rc") { return 3; }
    if ($part === "#") { return 4; }
    if ($part === "pl" || $part === "p") { return 6; }
    if ($part !== "" && \ctype_digit($part)) { return 5; }
    return -1;                                   // any other string sorts first
}

/**
 * php's version canonicaliser: insert `.` around every run-boundary between
 * digits and non-digits, and treat `-`, `_`, `+` as `.`.
 * @return string[]
 */
function __mc_version_parts(string $v): array
{
    $s = "";
    $n = \strlen($v);
    $i = 0;
    while ($i < $n) {
        $c = $v[$i];
        if ($c === "-" || $c === "_" || $c === "+") {
            $s = $s . ".";
        } elseif ($c === ".") {
            $s = $s . ".";
        } else {
            if ($i > 0) {
                $p = $v[$i - 1];
                $pd = \ctype_digit($p);
                $cd = \ctype_digit($c);
                if ($p !== "." && $p !== "-" && $p !== "_" && $p !== "+" && $pd !== $cd) {
                    $s = $s . ".";
                }
            }
            $s = $s . $c;
        }
        $i = $i + 1;
    }
    $raw = \explode(".", $s);
    $out = [];
    foreach ($raw as $p) {
        if ($p !== "") { $out[] = $p; }
    }
    return $out;
}

/** -1 / 0 / 1 for two canonicalised version parts. */
function __mc_version_cmp_part(string $a, string $b): int
{
    $oa = __mc_version_order($a);
    $ob = __mc_version_order($b);
    if ($oa !== $ob) { return $oa < $ob ? -1 : 1; }
    if ($oa === 5) {
        $ia = (int)$a;
        $ib = (int)$b;
        if ($ia === $ib) { return 0; }
        return $ia < $ib ? -1 : 1;
    }
    if ($a === $b) { return 0; }
    return $a < $b ? -1 : 1;
}

/**
 * php's version_compare. With `$operator` it returns a bool; without, -1/0/1.
 * The `$operator` form is what symfony/console's CompleteCommand uses.
 */
function version_compare(string $version1, string $version2, ?string $operator = null): mixed
{
    $a = __mc_version_parts($version1);
    $b = __mc_version_parts($version2);
    $na = \count($a);
    $nb = \count($b);
    $n = $na > $nb ? $na : $nb;
    $r = 0;
    $i = 0;
    while ($i < $n) {
        // A missing part compares as "#" — php's neutral filler, which ranks
        // above dev/alpha/beta/RC and below a number.
        $pa = $i < $na ? $a[$i] : "#";
        $pb = $i < $nb ? $b[$i] : "#";
        $c = __mc_version_cmp_part($pa, $pb);
        if ($c !== 0) { $r = $c; break; }
        $i = $i + 1;
    }
    if ($operator === null) { return $r; }
    if ($operator === "<" || $operator === "lt") { return $r < 0; }
    if ($operator === "<=" || $operator === "le") { return $r <= 0; }
    if ($operator === ">" || $operator === "gt") { return $r > 0; }
    if ($operator === ">=" || $operator === "ge") { return $r >= 0; }
    if ($operator === "==" || $operator === "eq") { return $r === 0; }
    if ($operator === "!=" || $operator === "ne" || $operator === "<>") { return $r !== 0; }
    return null;
}
