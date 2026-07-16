<?php

use Manticore\Attr\RefOut;

// Global-namespace preg_* — thin wrappers over the Runtime\Pcre FFI (host
// libpcre2-8). The pattern language and semantics are PCRE2's, so parity with
// the Zend interpreter (which links the same libpcre2) is exact.
//
// Compiled patterns are cached by their full pattern string (delimiters +
// modifiers included) — pcre2_compile is the dominant cost, so a repeated
// preg_* in a loop compiles once.

/**
 * Map PHP inline modifiers (the chars after the closing delimiter) to a
 * PCRE2 compile-option bitmask. Bit values are from pcre2.h.
 */
function __preg_options(string $mods): int
{
    $opt = 0;
    $n = \strlen($mods);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $mods[$i];
        if ($c === 'i')      { $opt = $opt | 0x00000008; }   // PCRE2_CASELESS
        elseif ($c === 'm')  { $opt = $opt | 0x00000400; }   // PCRE2_MULTILINE
        elseif ($c === 's')  { $opt = $opt | 0x00000020; }   // PCRE2_DOTALL
        elseif ($c === 'x')  { $opt = $opt | 0x00000080; }   // PCRE2_EXTENDED
        elseif ($c === 'U')  { $opt = $opt | 0x00040000; }   // PCRE2_UNGREEDY
        elseif ($c === 'A')  { $opt = $opt | 0x80000000; }   // PCRE2_ANCHORED
        elseif ($c === 'D')  { $opt = $opt | 0x00000010; }   // PCRE2_DOLLAR_ENDONLY
        elseif ($c === 'u')  { $opt = $opt | 0x00080000 | 0x00020000; } // PCRE2_UTF|PCRE2_UCP
    }
    return $opt;
}

/** Closing delimiter for an opening bracket-style delimiter, else itself. */
function __preg_close_delim(string $open): string
{
    if ($open === '(') { return ')'; }
    if ($open === '{') { return '}'; }
    if ($open === '[') { return ']'; }
    if ($open === '<') { return '>'; }
    return $open;
}

/**
 * Compile a PHP-delimited pattern to a cached pcre2_code* (raw address), or 0
 * on a compile error.
 */
function __preg_compile(string $pattern): int
{
    static $cache = [];
    if (isset($cache[$pattern])) {
        return $cache[$pattern];
    }
    $close = \__preg_close_delim($pattern[0]);
    $endPos = \strrpos($pattern, $close);
    // strrpos is int|false; a delimiter-less pattern is not a valid PHP regex,
    // so bail rather than let false coerce into the substr offsets below.
    if ($endPos === false) {
        return 0;
    }
    $end = $endPos;
    $body = \substr($pattern, 1, $end - 1);
    $mods = \substr($pattern, $end + 1);
    $opt = \__preg_options($mods);

    // Out-params: errorcode (int) + erroroffset (size_t). 8 bytes each is a
    // safe over-allocation for both.
    $errcode = \Runtime\Libc\calloc(8, 1);
    $erroff = \Runtime\Libc\calloc(8, 1);
    $code = \Runtime\Pcre\compile($body, \strlen($body), $opt, $errcode, $erroff, 0);
    \Runtime\Libc\free($errcode);
    \Runtime\Libc\free($erroff);
    if ($code === 0) {
        return 0;
    }
    $cache[$pattern] = $code;
    return $code;
}

/**
 * preg_match — search $subject for $pattern. Returns 1 on match, 0 on no
 * match, false on error. On a match, $matches is filled with the full match
 * at [0] and each captured group after it (unmatched groups → "").
 */
/**
 * @param mixed[] $matches
 * @param-out mixed[] $matches
 */
function preg_match(string $pattern, string $subject, #[RefOut] array &$matches = [], int $flags = 0, int $offset = 0): int
{
    // Reset through the by-ref: elements are written straight into $matches so
    // the erased (cell) container NaN-boxes each string — a local string[]
    // copied back through the untyped ref would lose its element tag and read
    // back as a raw pointer.
    $matches = [];
    $code = \__preg_compile($pattern);
    if ($code === 0) {
        return 0;
    }
    $md = \Runtime\Pcre\matchDataCreate($code, 0);
    $rc = \Runtime\Pcre\exec($code, $subject, \strlen($subject), $offset, 0, $md, 0);
    // A C int came back in a 64-bit slot: keep the low 32 bits, sign-extend.
    $rc = $rc & 0xFFFFFFFF;
    if ($rc >= 0x80000000) {
        $rc = $rc - 0x100000000;
    }
    if ($rc <= 0) {
        // <0: no-match (-1) or error; ==0: ovector too small (won't happen).
        \Runtime\Pcre\matchDataFree($md);
        return 0;
    }
    $ov = \Runtime\Pcre\ovectorPtr($md);
    for ($i = 0; $i < $rc; $i = $i + 1) {
        $s = \peek_i64($ov, $i * 16);
        $e = \peek_i64($ov, $i * 16 + 8);
        // NaN-box the string into the erased (cell) container so the caller
        // reads back a tagged string, not a raw pointer rendered as an int.
        if ($s < 0) {
            $matches[] = \__mir_to_cell("");   // PCRE2_UNSET (~0) → unmatched group
        } else {
            $matches[] = \__mir_to_cell(\substr($subject, $s, $e - $s));
        }
    }
    \Runtime\Pcre\matchDataFree($md);
    return 1;
}

/**
 * preg_quote — escape regex metacharacters in $str (and $delimiter, when a
 * non-empty single char is given). NUL becomes "\000" per PHP.
 */
function preg_quote(string $str, string $delimiter = ""): string
{
    $special = ".\\+*?[^]\$(){}=!<>|:-#";
    $out = "";
    $n = \strlen($str);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $str[$i];
        if ($c === "\0") {
            $out .= "\\000";
            continue;
        }
        $isSpecial = \strpos($special, $c) !== false;
        $isDelim = $delimiter !== "" && $c === $delimiter;
        if ($isSpecial || $isDelim) {
            $out .= "\\";
        }
        $out .= $c;
    }
    return $out;
}

// ── shared low-level match + error state ───────────────────────────────────

/**
 * One match of compiled $code against $subject at $offset. Returns [] on
 * no-match, else a flat int vector `[rc, s0, e0, s1, e1, ...]` — pair `i` is
 * group `i`'s byte start/end (PCRE2_UNSET → -1 for an unmatched group).
 * @return int[]
 */
function __preg_match_at(int $code, int $md, string $subject, int $len, int $offset): array
{
    $rc = \Runtime\Pcre\exec($code, $subject, $len, $offset, 0, $md, 0);
    $rc = $rc & 0xFFFFFFFF;                       // C int in an i64 slot
    if ($rc >= 0x80000000) { $rc = $rc - 0x100000000; }
    if ($rc <= 0) {
        \__preg_error($rc < -1 ? 1 : 0);          // <-1 = real error; -1 = nomatch
        return [];
    }
    \__preg_error(0);
    $ov = \Runtime\Pcre\ovectorPtr($md);
    $out = [$rc];
    $pairs = $rc * 2;
    for ($i = 0; $i < $pairs; $i = $i + 1) { $out[] = \peek_i64($ov, $i * 8); }
    return $out;
}

/** Step past a match end, forcing progress on an empty match (avoid a loop). */
function __preg_advance(int $matchStart, int $matchEnd): int
{
    return $matchEnd > $matchStart ? $matchEnd : $matchEnd + 1;
}

/** preg_last_error() state. Pass -1 to read, >=0 to set; returns current. */
function __preg_error(int $set = -1): int
{
    static $err = 0;
    if ($set >= 0) { $err = $set; }
    return $err;
}

function preg_last_error(): int
{
    return \__preg_error(-1);
}

function preg_last_error_msg(): string
{
    $e = \__preg_error(-1);
    if ($e === 0) { return "No error"; }
    if ($e === 2) { return "Backtrack limit exhausted"; }
    if ($e === 3) { return "Recursion limit exhausted"; }
    if ($e === 4) { return "Malformed UTF-8 characters, possibly incorrectly encoded"; }
    if ($e === 5) { return "The offset did not correspond to the beginning of a valid UTF-8 character"; }
    if ($e === 6) { return "JIT stack limit exhausted"; }
    return "Internal error";
}

// ── preg_match_all ─────────────────────────────────────────────────────────

/**
 * @param mixed[] $matches
 * @param-out mixed[] $matches
 */
function preg_match_all(string $pattern, string $subject, #[RefOut] array &$matches = [], int $flags = 1, int $offset = 0): int
{
    $matches = [];
    $code = \__preg_compile($pattern);
    if ($code === 0) { return 0; }
    $setOrder = ($flags & 2) !== 0;               // PREG_SET_ORDER
    $len = \strlen($subject);
    $md = \Runtime\Pcre\matchDataCreate($code, 0);

    // Collect each match's group strings as a local string[][].
    $rows = [];
    $ngroups = 0;
    while ($offset <= $len) {
        $m = \__preg_match_at($code, $md, $subject, $len, $offset);
        if ($m === []) { break; }
        $rc = $m[0];
        if ($rc > $ngroups) { $ngroups = $rc; }
        $row = [];
        for ($i = 0; $i < $rc; $i = $i + 1) {
            $s = $m[1 + $i * 2];
            $e = $m[2 + $i * 2];
            $row[] = $s < 0 ? "" : \substr($subject, $s, $e - $s);
        }
        $rows[] = $row;
        $offset = \__preg_advance($m[1], $m[2]);
    }
    \Runtime\Pcre\matchDataFree($md);

    $count = \count($rows);
    if ($setOrder) {
        // matches[i] = [full, g1, g2, ...] for match i.
        for ($i = 0; $i < $count; $i = $i + 1) {
            $matches[] = \__mir_to_cell(\__preg_cells($rows[$i]));
        }
    } else {
        // PREG_PATTERN_ORDER (default): matches[g] = [g of match 0, g of match 1, ...].
        for ($g = 0; $g < $ngroups; $g = $g + 1) {
            $col = [];
            for ($i = 0; $i < $count; $i = $i + 1) {
                $r = $rows[$i];
                $col[] = $g < \count($r) ? $r[$g] : "";
            }
            $matches[] = \__mir_to_cell(\__preg_cells($col));
        }
    }
    return $count;
}

/** Box a string[] into a vec[cell] (NaN-boxed elements) for an erased sink.
 *  @param string[] $strs @return mixed[] */
function __preg_cells(array $strs): array
{
    $out = [];
    foreach ($strs as $s) { $out[] = \__mir_to_cell($s); }
    return $out;
}

// ── replacement (backreference expansion) ──────────────────────────────────

/**
 * Expand `$N`, `${N}`, `\N` backreferences in $repl using the match ovector
 * `[rc, s0, e0, ...]` over $subject. `$0` / `\0` is the whole match.
 * @param int[] $m
 */
function __preg_expand(string $repl, string $subject, array $m): string
{
    $rc = $m[0];
    $out = "";
    $n = \strlen($repl);
    $i = 0;
    while ($i < $n) {
        $c = $repl[$i];
        if (($c === '$' || $c === '\\') && $i + 1 < $n) {
            $j = $i + 1;
            $braced = false;
            if ($c === '$' && $repl[$j] === '{') { $braced = true; $j = $j + 1; }
            $numStart = $j;
            while ($j < $n && $repl[$j] >= '0' && $repl[$j] <= '9') { $j = $j + 1; }
            if ($j > $numStart) {
                $num = (int)\substr($repl, $numStart, $j - $numStart);
                if ($braced && $j < $n && $repl[$j] === '}') { $j = $j + 1; }
                if ($num < $rc) {
                    $s = $m[1 + $num * 2];
                    $e = $m[2 + $num * 2];
                    if ($s >= 0) { $out .= \substr($subject, $s, $e - $s); }
                }
                $i = $j;
                continue;
            }
        }
        // A literal `\\` collapses to `\`.
        if ($c === '\\' && $i + 1 < $n && $repl[$i + 1] === '\\') {
            $out .= '\\';
            $i = $i + 2;
            continue;
        }
        $out .= $c;
        $i = $i + 1;
    }
    return $out;
}

function preg_replace(string $pattern, string $replacement, string $subject, int $limit = -1, #[RefOut] int &$count = 0): string
{
    $count = 0;
    $code = \__preg_compile($pattern);
    if ($code === 0) { return $subject; }
    $len = \strlen($subject);
    $md = \Runtime\Pcre\matchDataCreate($code, 0);
    $out = "";
    $pos = 0;
    while ($pos <= $len) {
        if ($limit >= 0 && $count >= $limit) { break; }
        $m = \__preg_match_at($code, $md, $subject, $len, $pos);
        if ($m === []) { break; }
        $ms = $m[1];
        $me = $m[2];
        $out .= \substr($subject, $pos, $ms - $pos);         // text before the match
        $out .= \__preg_expand($replacement, $subject, $m);  // the replacement
        $count = $count + 1;
        $next = \__preg_advance($ms, $me);
        if ($me === $ms) { $out .= \substr($subject, $ms, 1); }  // empty match: keep the char
        $pos = $next;
    }
    \Runtime\Pcre\matchDataFree($md);
    if ($pos < $len) { $out .= \substr($subject, $pos, $len - $pos); }
    return $out;
}

function preg_replace_callback(string $pattern, callable $callback, string $subject, int $limit = -1, #[RefOut] int &$count = 0): string
{
    $count = 0;
    $code = \__preg_compile($pattern);
    if ($code === 0) { return $subject; }
    $len = \strlen($subject);
    $md = \Runtime\Pcre\matchDataCreate($code, 0);
    $out = "";
    $pos = 0;
    while ($pos <= $len) {
        if ($limit >= 0 && $count >= $limit) { break; }
        $m = \__preg_match_at($code, $md, $subject, $len, $pos);
        if ($m === []) { break; }
        $ms = $m[1];
        $me = $m[2];
        $rc = $m[0];
        $groups = [];
        for ($i = 0; $i < $rc; $i = $i + 1) {
            $s = $m[1 + $i * 2];
            $e = $m[2 + $i * 2];
            $groups[] = $s < 0 ? "" : \substr($subject, $s, $e - $s);
        }
        $arg = \__preg_cells($groups);
        $out .= \substr($subject, $pos, $ms - $pos);
        $out .= $callback($arg);
        $count = $count + 1;
        $next = \__preg_advance($ms, $me);
        if ($me === $ms) { $out .= \substr($subject, $ms, 1); }
        $pos = $next;
    }
    \Runtime\Pcre\matchDataFree($md);
    if ($pos < $len) { $out .= \substr($subject, $pos, $len - $pos); }
    return $out;
}

/**
 * @param array<string, callable> $patterns
 */
function preg_replace_callback_array(array $patterns, string $subject, int $limit = -1, #[RefOut] int &$count = 0): string
{
    $count = 0;
    foreach ($patterns as $pattern => $callback) {
        $c = 0;
        $subject = \preg_replace_callback($pattern, $callback, $subject, $limit, $c);
        $count = $count + $c;
    }
    return $subject;
}

// ── preg_split ─────────────────────────────────────────────────────────────

/**
 * @return string[]
 */
function preg_split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array
{
    $noEmpty = ($flags & 1) !== 0;          // PREG_SPLIT_NO_EMPTY
    $delimCap = ($flags & 2) !== 0;         // PREG_SPLIT_DELIM_CAPTURE
    $out = [];
    $code = \__preg_compile($pattern);
    if ($code === 0) { $out[] = $subject; return $out; }
    $len = \strlen($subject);
    $md = \Runtime\Pcre\matchDataCreate($code, 0);
    $last = 0;
    $pos = 0;
    $pieces = 0;
    while ($pos <= $len) {
        if ($limit > 0 && $pieces >= $limit - 1) { break; }
        $m = \__preg_match_at($code, $md, $subject, $len, $pos);
        if ($m === []) { break; }
        $ms = $m[1];
        $me = $m[2];
        if ($me === $ms && $ms === $last) {
            // empty match at the cursor — advance without splitting
            $pos = $ms + 1;
            continue;
        }
        $piece = \substr($subject, $last, $ms - $last);
        if (!$noEmpty || $piece !== "") { $out[] = $piece; $pieces = $pieces + 1; }
        if ($delimCap) {
            $rc = $m[0];
            for ($i = 1; $i < $rc; $i = $i + 1) {
                $s = $m[1 + $i * 2];
                $e = $m[2 + $i * 2];
                $g = $s < 0 ? "" : \substr($subject, $s, $e - $s);
                if (!$noEmpty || $g !== "") { $out[] = $g; }
            }
        }
        $last = $me;
        $pos = \__preg_advance($ms, $me);
    }
    \Runtime\Pcre\matchDataFree($md);
    $tail = \substr($subject, $last, $len - $last);
    if (!$noEmpty || $tail !== "") { $out[] = $tail; }
    return $out;
}

// ── preg_grep ──────────────────────────────────────────────────────────────

/**
 * @param string[] $array
 * @return array<int, string>
 */
function preg_grep(string $pattern, array $array, int $flags = 0): array
{
    $invert = ($flags & 1) !== 0;           // PREG_GREP_INVERT
    $out = [];
    $tmp = [];
    foreach ($array as $key => $value) {
        $hit = \preg_match($pattern, $value, $tmp) === 1;
        if ($hit !== $invert) { $out[$key] = $value; }
    }
    return $out;
}
