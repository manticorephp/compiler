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
 * PCRE2 compile-option bitmask. Bit values are from pcre2.h. -1 for a modifier
 * php does not know (its "Unknown modifier" compile error).
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
        elseif ($c === 'J')  { $opt = $opt | 0x00000040; }   // PCRE2_DUPNAMES
        elseif ($c === 'n')  { $opt = $opt | 0x00002000; }   // PCRE2_NO_AUTO_CAPTURE
        elseif ($c === 'S' || $c === 'X' || $c === ' ' || $c === "\n" || $c === "\r") { }
        else { return -1; }
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
/**
 * The regex caches as TYPED properties. A `static $cache = []` is a cell slot, so
 * every read boxed the stored handle into a cell on the way out — and a PCRE2
 * handle is a heap address, above bit 47 on Linux arm64, which does not fit
 * the inline int form: each lookup allocated an immortal 8-byte int box, two
 * per preg_match.
 */
final class __McPcreCache
{
    /** @var array<string, int> pattern => compiled code */
    public static array $code = [];
    /** @var array<int, int> code => its idle match block ({@see __preg_md}) */
    public static array $idle = [];
    /** @var array<int, int> code => 1 when the pattern has named groups, else 0 ({@see __preg_name_map}) */
    public static array $named = [];
    /** @var array<int, array<int, string>> code => its group number => name map ({@see __preg_name_map}) */
    public static array $nameMaps = [];
    /** The idle match block of the pattern last used, kept out of the map: one slot, one compare. */
    public static int $slotCode = 0;
    public static int $slotMd = 0;
    /** Match context carrying the JIT stack (0 until the first compile). */
    public static int $mctx = 0;
    /** preg_last_error() state. */
    public static int $err = 0;
}

function __preg_compile(string $pattern): int
{
    // ONE probe: `isset` then a read walked the map twice per call, and a
    // php-cs-fixer pattern is a fresh multi-KB concatenation every time.
    $hit = __McPcreCache::$code[$pattern] ?? 0;
    if ($hit !== 0) {
        __McPcreCache::$err = 0;
        return $hit;
    }
    // php: a pattern that does not compile is PREG_INTERNAL_ERROR, and every
    // preg_* answers its error value (false / null).
    __McPcreCache::$err = 1;
    // php skips leading whitespace; then an empty pattern, or an alphanumeric /
    // backslash delimiter, is rejected.
    $pat = \ltrim($pattern, " \t\n\r\v\f");
    if ($pat === '' || \ctype_alnum($pat[0]) || $pat[0] === '\\') {
        return 0;
    }
    $close = \__preg_close_delim($pat[0]);
    $endPos = \strrpos($pat, $close);
    // No closing delimiter: strrpos finds nothing, or (same open and close)
    // only the opening one at 0.
    if ($endPos === false || $endPos === 0) {
        return 0;
    }
    $end = $endPos;
    $body = \substr($pat, 1, $end - 1);
    $mods = \substr($pat, $end + 1);
    $opt = \__preg_options($mods);
    if ($opt === -1) {
        return 0;
    }

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
    // JIT like php's pcre.jit=1; a failure just leaves the interpreter.
    \Runtime\Pcre\jitCompile($code, 1);
    if (__McPcreCache::$mctx === 0) {
        __McPcreCache::$mctx = \Runtime\Pcre\matchContextCreate(0);
        \Runtime\Pcre\jitStackAssign(__McPcreCache::$mctx, 0, \Runtime\Pcre\jitStackCreate(32768, 196608, 0));
    }
    __McPcreCache::$code[$pattern] = $code;
    __McPcreCache::$err = 0;
    return $code;
}

/**
 * A match block for compiled `$code`, lent out and handed back: `$give === 0`
 * borrows one (the idle one, or a fresh one), otherwise returns `$give`.
 *
 * PCRE2 keeps its backtracking frames IN the match block (10.41+), and creating
 * one per call made every preg_* allocate and free >= 20 KiB — the malloc churn
 * that grew a Linux process's RSS by megabytes over a loop that kept nothing.
 * One idle block per pattern: a nested match of the same pattern while the
 * outer holds its block (a preg_replace_callback callback) simply gets its own,
 * and whichever comes back second is freed.
 */
function __preg_md(int $code, int $give): int
{
    if ($give === 0) {
        if (__McPcreCache::$slotCode === $code && __McPcreCache::$slotMd !== 0) {
            $slot = __McPcreCache::$slotMd;
            __McPcreCache::$slotMd = 0;
            return $slot;
        }
        $md = isset(__McPcreCache::$idle[$code]) ? __McPcreCache::$idle[$code] : 0;
        if ($md !== 0) {
            __McPcreCache::$idle[$code] = 0;
            return $md;
        }
        return \Runtime\Pcre\matchDataCreate($code, 0);
    }
    if (__McPcreCache::$slotMd === 0) {
        __McPcreCache::$slotCode = $code;
        __McPcreCache::$slotMd = $give;
        return 0;
    }
    if (!isset(__McPcreCache::$idle[$code]) || __McPcreCache::$idle[$code] === 0) {
        __McPcreCache::$idle[$code] = $give;
    } else {
        \Runtime\Pcre\matchDataFree($give);
    }
    return 0;
}

/**
 * Whether `$pattern` matches `$subject` from `$offset`: 1 for a match, 0 for none,
 * -1 when the pattern does not compile or PCRE reports anything but "no match"
 * (a bad UTF-8 subject under /u, a match limit). preg_match does the same
 * search, then builds `$matches` — an array, a substr and a boxed cell per
 * group — which a caller that only asks "does it match" throws away: 3 000
 * instructions for a one-line check, two thirds of them that bookkeeping.
 */
function __preg_test(string $pattern, string $subject, int $offset = 0): int
{
    $code = \__preg_compile($pattern);
    if ($code === 0) {
        return -1;
    }
    $md = \__preg_md($code, 0);
    $rc = \Runtime\Pcre\exec($code, $subject, \strlen($subject), $offset, 0, $md, __McPcreCache::$mctx);
    \__preg_md($code, $md);
    $rc = $rc & 0xFFFFFFFF;
    if ($rc >= 0x80000000) {
        $rc = $rc - 0x100000000;
    }
    if ($rc > 0) {
        return 1;
    }
    return $rc === -1 ? 0 : -1;
}

/**
 * preg_match — search $subject for $pattern. Returns 1 on match, 0 on no
 * match, false on error. On a match, $matches is filled with the full match
 * at [0] and each captured group after it (unmatched groups → "").
 */
/**
 * $matches is CELL-element, like preg_match_all's.
 *
 * It used to be declared `array<int, string>` because every capture PCRE hands
 * back is a string, and that typing was load-bearing: a bare `array` crosses
 * the .sig as `mixed[]`, and a caller in ANOTHER MODULE reading `$m[1]` as a
 * string once got the tagged bits as a pointer and SIGSEGV'd. The declared
 * element type is what makes the read-back typed
 * ({@see LowerFromAst::defineOutParams}).
 *
 * PREG_OFFSET_CAPTURE is why it cannot stay that way: under that flag php
 * reports every group as `[text, byteOffset]`, so the values genuinely ARE
 * arrays and a string element has nowhere to put them. The flag was therefore
 * being silently ignored — twig's Lexer, symfony/yaml's Inline parser and the
 * DI PhpDumper all read the offset out of `$m[n][1]` and were getting a
 * one-character substring of the matched text instead.
 *
 * The cross-module read that motivated the narrow typing is exactly what the
 * probe exercises: every user program calls into the stdlib .o, so
 * tests/aot/cases/cap_preg_named_groups.php IS the regression test for it.
 *
 * @param mixed[] $matches
 * @param-out mixed[] $matches
 */
function preg_match(string $pattern, string $subject, #[RefOut] array<int, mixed> &$matches = [], int $flags = 0, int $offset = 0): int|false
{
    // Written straight into the by-ref so the caller's container is the one
    // that grows.
    $matches = [];
    $offsetCapture = ($flags & 256) !== 0;        // PREG_OFFSET_CAPTURE
    $asNull = ($flags & 512) !== 0;               // PREG_UNMATCHED_AS_NULL
    $code = \__preg_compile($pattern);
    if ($code === 0) {
        return false;
    }
    $md = \__preg_md($code, 0);
    $rc = \Runtime\Pcre\exec($code, $subject, \strlen($subject), $offset, 0, $md, __McPcreCache::$mctx);
    // A C int came back in a 64-bit slot: keep the low 32 bits, sign-extend.
    $rc = $rc & 0xFFFFFFFF;
    if ($rc >= 0x80000000) {
        $rc = $rc - 0x100000000;
    }
    if ($rc <= 0) {
        // -1: no match; below that an error (a bad UTF-8 subject under /u, a
        // match limit) — php answers false and records it for preg_last_error.
        \__preg_md($code, $md);
        $err = \__preg_errcode($rc);
        __McPcreCache::$err = $err;
        return $err === 0 ? 0 : false;
    }
    $ov = \Runtime\Pcre\ovectorPtr($md);
    // A named group appears TWICE in php's $matches — under its name first,
    // then under its number — so the two are interleaved rather than appended.
    $names = \__preg_name_map($code);
    // PCRE2 stops its return count at the LAST group that participated, so a
    // trailing optional group is simply absent — which is what php reports too,
    // except under PREG_UNMATCHED_AS_NULL, where every declared group is
    // present and an absent one is null. Only then is the pattern's own capture
    // count needed.
    $total = $rc;
    if ($asNull) {
        $declared = \__preg_capture_count($code) + 1;   // +1: group 0, the whole match
        if ($declared > $total) { $total = $declared; }
    }
    for ($i = 0; $i < $total; $i = $i + 1) {
        $s = $i < $rc ? \peek_i64($ov, $i * 16) : -1;
        $e = $i < $rc ? \peek_i64($ov, $i * 16 + 8) : -1;
        // PCRE2_UNSET (~0) reads back negative: the group did not participate.
        $unset = $s < 0;
        if ($offsetCapture) {
            // A fresh pair per key: two keys sharing one buffer would hand the
            // caller a double release, the same reason preg_match_all copies.
            if (isset($names[$i])) {
                $matches[$names[$i]] = \__mir_to_cell(\__preg_group_pair($subject, $s, $e, $asNull));
            }
            $matches[$i] = \__mir_to_cell(\__preg_group_pair($subject, $s, $e, $asNull));
            continue;
        }
        $val = $unset
            ? ($asNull ? null : "")
            : \substr($subject, $s, $e - $s);
        if (isset($names[$i])) {
            $matches[$names[$i]] = \__mir_to_cell($val);
        }
        $matches[$i] = \__mir_to_cell($val);
    }
    // `(*MARK:name)` / `(*:name)`: php reports the last one passed under 'MARK'
    // — symfony's CompiledUrlMatcher picks the matched route by it.
    $mark = \Runtime\Pcre\getMark($md);
    if ($mark !== 0) {
        $matches['MARK'] = \__mir_to_cell(\cstr_to_str(\int_to_ptr($mark)));
    }
    \__preg_md($code, $md);
    return 1;
}

/**
 * Group number → name for every `(?P<name>…)` / `(?<name>…)` / `(?'name'…)` in
 * a compiled pattern, read out of PCRE2's name table.
 *
 * Table layout in 8-bit mode: `namecount` entries of `nameentrysize` bytes,
 * each holding a 2-byte BIG-ENDIAN group number followed by the NUL-terminated
 * name. Big-endian regardless of host, so the bytes are combined by hand
 * rather than peeked as a u16.
 *
 * @return array<int, string>
 */
function __preg_name_map(int $code): array
{
    if ((__McPcreCache::$named[$code] ?? -1) === 0) {
        return [];
    }
    if (isset(__McPcreCache::$nameMaps[$code])) {
        return __McPcreCache::$nameMaps[$code];
    }
    $buf = \Runtime\Libc\calloc(8, 1);
    // PCRE2_INFO_NAMECOUNT = 17, NAMEENTRYSIZE = 18, NAMETABLE = 19.
    \Runtime\Pcre\patternInfo($code, 17, $buf);
    $count = \peek_u32($buf, 0);
    if ($count <= 0) {
        \Runtime\Libc\free($buf);
        __McPcreCache::$named[$code] = 0;
        return [];
    }
    __McPcreCache::$named[$code] = 1;
    \Runtime\Pcre\patternInfo($code, 18, $buf);
    $size = \peek_u32($buf, 0);
    \Runtime\Pcre\patternInfo($code, 19, $buf);
    $table = \peek_i64($buf, 0);
    \Runtime\Libc\free($buf);
    if ($table === 0 || $size <= 2) {
        return [];
    }
    /** @var array<int, string> $out */
    $out = [];
    for ($i = 0; $i < $count; $i = $i + 1) {
        $entry = \int_to_ptr($table + $i * $size);
        $group = \peek_u8($entry, 0) * 256 + \peek_u8($entry, 1);
        // cstr_to_str is the NUL-terminated-char* boundary: it copies into a
        // real rc-headed string. The table itself belongs to the cached
        // pcre2_code and must not be freed here.
        $out[$group] = \cstr_to_str(\int_to_ptr($table + $i * $size + 2));
    }
    __McPcreCache::$nameMaps[$code] = $out;
    return $out;
}

/**
 * preg_quote — escape regex metacharacters in $str (and $delimiter, when a
 * non-empty single char is given). NUL becomes "\000" per PHP.
 */
function preg_quote(string $str, string $delimiter = ""): string
{
    $mask = ".\\+*?[^]\$(){}=!<>|:-#\0";
    if ($delimiter !== "") { $mask .= $delimiter[0]; }
    $n = \strlen($str);
    $i = \strcspn($str, $mask);
    if ($i === $n) { return $str; }
    $out = "";
    $from = 0;
    while ($i < $n) {
        if ($i > $from) { $out .= \substr($str, $from, $i - $from); }
        if ($str[$i] === "\0") { $out .= "\\000"; } else { $out .= "\\" . $str[$i]; }
        $from = $i + 1;
        $i = $from + \strcspn($str, $mask, $from);
    }
    if ($from < $n) { $out .= \substr($str, $from, $n - $from); }
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
    $rc = \Runtime\Pcre\exec($code, $subject, $len, $offset, 0, $md, __McPcreCache::$mctx);
    $rc = $rc & 0xFFFFFFFF;                       // C int in an i64 slot
    if ($rc >= 0x80000000) { $rc = $rc - 0x100000000; }
    if ($rc <= 0) {
        \__preg_error(\__preg_errcode($rc));          // <-1 = real error; -1 = nomatch
        return [];
    }
    \__preg_error(0);
    $ov = \Runtime\Pcre\ovectorPtr($md);
    $out = [$rc];
    $pairs = $rc * 2;
    for ($i = 0; $i < $pairs; $i = $i + 1) { $out[] = \peek_i64($ov, $i * 8); }
    return $out;
}

/**
 * One match attempt: the group count (> 0) on a match with the groups left in
 * the match block's ovector, 0 on no match or an error (recorded for
 * preg_last_error). Callers read the ovector themselves — no per-match vector.
 */
function __preg_exec(int $code, int $md, string $subject, int $len, int $offset): int
{
    $rc = \Runtime\Pcre\exec($code, $subject, $len, $offset, 0, $md, __McPcreCache::$mctx);
    $rc = $rc & 0xFFFFFFFF;
    if ($rc >= 0x80000000) { $rc = $rc - 0x100000000; }
    if ($rc <= 0) {
        __McPcreCache::$err = \__preg_errcode($rc);
        return 0;
    }
    __McPcreCache::$err = 0;
    return $rc;
}

/** Step past a match end, forcing progress on an empty match (avoid a loop). */
function __preg_advance(int $matchStart, int $matchEnd): int
{
    return $matchEnd > $matchStart ? $matchEnd : $matchEnd + 1;
}

/** php's preg_last_error() code for a pcre2_match return `$rc` (<= 0). */
function __preg_errcode(int $rc): int
{
    if ($rc >= -1) { return 0; }                 // -1 = no match
    if ($rc >= -23) { return 4; }                // PCRE2_ERROR_UTF8_ERR1..21 and friends
    if ($rc === -36) { return 5; }               // PCRE2_ERROR_BADUTFOFFSET
    if ($rc === -47) { return 2; }               // MATCHLIMIT
    if ($rc === -53) { return 3; }               // DEPTHLIMIT
    if ($rc === -46) { return 6; }               // JIT_STACKLIMIT
    return 1;
}
/** preg_last_error() state. Pass -1 to read, >=0 to set; returns current. */
function __preg_error(int $set = -1): int
{
    if ($set >= 0) { __McPcreCache::$err = $set; }
    return __McPcreCache::$err;
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
    if ($e === 5) { return "The offset did not correspond to the beginning of a valid UTF-8 code point"; }
    if ($e === 6) { return "JIT stack limit exhausted"; }
    return "Internal error";
}

// ── preg_match_all ─────────────────────────────────────────────────────────

/**
 * @param mixed[] $matches
 * @param-out mixed[] $matches
 */
function preg_match_all(string $pattern, string $subject, #[RefOut] array &$matches = [], int $flags = 1, int $offset = 0): int|false
{
    $matches = [];
    $code = \__preg_compile($pattern);
    if ($code === 0) { return false; }
    $setOrder = ($flags & 2) !== 0;               // PREG_SET_ORDER
    $offsetCapture = ($flags & 256) !== 0;        // PREG_OFFSET_CAPTURE
    $len = \strlen($subject);
    $md = \__preg_md($code, 0);
    $declared = \__preg_capture_count($code) + 1;   // +1: group 0, the whole match

    // Plain strings, no named group: straight from the ovector into the result,
    // no per-match int vector, row array or second copy.
    if (!$offsetCapture && \__preg_name_map($code) === []) {
        $asNull = ($flags & 512) !== 0;               // PREG_UNMATCHED_AS_NULL
        $ov = \Runtime\Pcre\ovectorPtr($md);
        $err = 0;
        $count = 0;
        /** @var mixed[] $flat */
        $flat = [];
        while ($offset <= $len) {
            $rc = \Runtime\Pcre\exec($code, $subject, $len, $offset, 0, $md, __McPcreCache::$mctx);
            $rc = $rc & 0xFFFFFFFF;
            if ($rc >= 0x80000000) { $rc = $rc - 0x100000000; }
            if ($rc <= 0) {
                $err = \__preg_errcode($rc);
                break;
            }
            $count = $count + 1;
            // pattern order pads every declared group; set order stops at the last participant
            $upto = ($setOrder && !$asNull) ? $rc : $declared;
            /** @var mixed[] $row */
            $row = [];
            for ($g = 0; $g < $upto; $g = $g + 1) {
                $s = $g < $rc ? \peek_i64($ov, $g * 16) : -1;
                if ($s < 0) {
                    if ($asNull) { $val = null; } else { $val = ""; }
                } else {
                    $val = \substr($subject, $s, \peek_i64($ov, $g * 16 + 8) - $s);
                }
                if ($setOrder) { $row[] = $val; } else { $flat[] = $val; }
            }
            if ($setOrder) { $matches[] = $row; }
            $ms = \peek_i64($ov, 0);
            $offset = \__preg_advance($ms, \peek_i64($ov, 8));
        }
        \__preg_md($code, $md);
        \__preg_error($err);
        if (!$setOrder) {
            if ($declared === 1) {
                $matches[0] = $flat;
            } else {
                for ($g = 0; $g < $declared; $g = $g + 1) {
                    /** @var mixed[] $col */
                    $col = [];
                    for ($i = 0; $i < $count; $i = $i + 1) { $col[] = $flat[$i * $declared + $g]; }
                    $matches[$g] = $col;
                }
            }
        }
        // php fills $matches with what matched before the error, then answers false.
        return $err === 0 ? $count : false;
    }

    // Collect each match's groups as a local mixed[][] — already CELLS, so the
    // offset-capture pair (itself an array) and a plain string share one shape.
    /** @var array<int, mixed[]> $rows */
    $rows = [];
    $ngroups = 0;
    while ($offset <= $len) {
        $m = \__preg_match_at($code, $md, $subject, $len, $offset);
        if ($m === []) { break; }
        $rc = $m[0];
        if ($rc > $ngroups) { $ngroups = $rc; }
        /** @var mixed[] $row */
        $row = [];
        for ($i = 0; $i < $rc; $i = $i + 1) {
            $s = $m[1 + $i * 2];
            $e = $m[2 + $i * 2];
            $str = $s < 0 ? "" : \substr($subject, $s, $e - $s);
            $row[] = $offsetCapture ? \__mir_to_cell(\__preg_pair($str, $s)) : \__mir_to_cell($str);
        }
        $rows[] = $row;
        $offset = \__preg_advance($m[1], $m[2]);
    }
    \__preg_md($code, $md);

    $count = \count($rows);
    if (!$setOrder) { $ngroups = $declared; }
    // Same interleaving rule as preg_match: a named group is reported under its
    // NAME first, then under its number — at the top level in pattern order,
    // and inside each row in set order.
    $names = \__preg_name_map($code);
    if ($setOrder) {
        // matches[i] = [full, g1, g2, ...] for match i. The row is COPIED: the
        // local $rows is released when this function returns, and boxing a row
        // pointer straight into the out-param does not co-own it — the caller
        // would be left holding a freed buffer (the release then walked garbage
        // and blew the stack).
        for ($i = 0; $i < $count; $i = $i + 1) {
            $matches[] = \__mir_to_cell(\__preg_row_named($rows[$i], $names));
        }
    } else {
        // PREG_PATTERN_ORDER (default): matches[g] = [g of match 0, g of match 1, ...].
        for ($g = 0; $g < $ngroups; $g = $g + 1) {
            /** @var mixed[] $col */
            $col = [];
            for ($i = 0; $i < $count; $i = $i + 1) {
                $r = $rows[$i];
                if ($g < \count($r)) {
                    $col[] = $r[$g];
                } else {
                    $col[] = $offsetCapture ? \__mir_to_cell(\__preg_pair("", -1)) : \__mir_to_cell("");
                }
            }
            // Copied for the same reason the set-order row above is: $col is a
            // named LOCAL, released at return, so the out-param must not simply
            // box its pointer. The named alias gets its OWN copy rather than a
            // second reference to the same buffer — sharing one pointer under
            // two keys would hand the caller a double release.
            if (isset($names[$g])) {
                $matches[$names[$g]] = \__mir_to_cell(\__preg_copy_cells($col));
            }
            $matches[$g] = \__mir_to_cell(\__preg_copy_cells($col));
        }
    }
    return __McPcreCache::$err === 0 ? $count : false;
}

/**
 * One PREG_OFFSET_CAPTURE entry: php reports every group as `[text, byteOffset]`,
 * with offset -1 for a group that did not participate. Returned already boxed,
 * so it drops into the same cell row a plain string does.
 *
 * Without this the flag was silently IGNORED and `$m[0][1]` — which symfony's
 * OutputFormatter reads as the match offset — indexed the matched STRING
 * instead, handing it a one-character text and a letter for a position. Every
 * style tag then survived into the output and `<` doubled around it.
 */
/** Copy a row of cells into a FRESH buffer the caller owns. The element store
 *  co-owns each payload, so the source row's own release stays balanced.
 *  @param mixed[] $cells @return mixed[] */
function __preg_copy_cells(array $cells): array
{
    /** @var mixed[] $out */
    $out = [];
    foreach ($cells as $c) { $out[] = $c; }
    return $out;
}

/** One PREG_SET_ORDER row, with each named group aliased ahead of its number.
 *  Copies like {@see __preg_copy_cells} — the source row is a local that gets
 *  released at return, and the name alias is a separate copy so two keys never
 *  share one buffer.
 *  @param mixed[] $cells @param array<int, string> $names @return mixed[] */
function __preg_row_named(array $cells, array $names): array
{
    if ($names === []) {
        return \__preg_copy_cells($cells);
    }
    /** @var mixed[] $out */
    $out = [];
    $i = 0;
    foreach ($cells as $c) {
        if (isset($names[$i])) { $out[$names[$i]] = $c; }
        $out[$i] = $c;
        $i = $i + 1;
    }
    return $out;
}

/**
 * One PREG_OFFSET_CAPTURE entry for group `[$s,$e)` of $subject.
 *
 * A group that did not participate is `["", -1]`, or `[null, -1]` under
 * PREG_UNMATCHED_AS_NULL — the flag changes the TEXT half of the pair, not the
 * offset.
 *
 * @return mixed[]
 */
function __preg_group_pair(string $subject, int $s, int $e, bool $asNull): array
{
    if ($s < 0) {
        return [\__mir_to_cell($asNull ? null : ""), \__mir_to_cell(-1)];
    }
    return \__preg_pair(\substr($subject, $s, $e - $s), $s);
}

/** Number of capturing groups the pattern DECLARES (group 0 not counted). */
function __preg_capture_count(int $code): int
{
    $buf = \Runtime\Libc\calloc(8, 1);
    \Runtime\Pcre\patternInfo($code, 4, $buf);      // PCRE2_INFO_CAPTURECOUNT
    $n = \peek_u32($buf, 0);
    \Runtime\Libc\free($buf);
    return $n;
}

function __preg_pair(string $s, int $off): array
{
    return [\__mir_to_cell($s), \__mir_to_cell($off < 0 ? -1 : $off)];
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
 * Expand `$N`, `${N}`, `\N` backreferences in $repl from the match ovector
 * (`$rc` groups) over $subject. `$0` / `\0` is the whole match.
 */
function __preg_expand(string $repl, string $subject, \Ffi\Ptr $ov, int $rc): string
{
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
                    $s = \peek_i64($ov, $num * 16);
                    if ($s >= 0) { $out .= \substr($subject, $s, \peek_i64($ov, $num * 16 + 8) - $s); }
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

/**
 * php's `preg_replace`: a pattern LIST is applied in order (each with the
 * replacement at the same position, or `$replacement` itself when that is a
 * string), and an array subject maps key by key. The typed forms are overloads
 * ({@see \Manticore\Attr\Overload}); this body is what an erased argument reaches.
 */
function preg_replace(array|string $pattern, array|string $replacement, array|string $subject, int $limit = -1, #[RefOut] int &$count = 0): array|string|null
{
    $count = 0;
    if (!\is_array($pattern) && \is_array($replacement)) {
        throw new \TypeError('preg_replace(): Argument #1 ($pattern) must be of type array when argument #2 ($replacement) is an array, string given');
    }
    if (\is_array($subject)) {
        $out = [];
        foreach ($subject as $k => $one) {
            $c = 0;
            $r = \__preg_replace_list($pattern, $replacement, (string)$one, $limit, $c);
            // php leaves out a subject whose replacement failed.
            if ($r !== null) { $out[$k] = $r; }
            $count = $count + $c;
        }
        return $out;
    }
    return \__preg_replace_list($pattern, $replacement, $subject, $limit, $count);
}

/** A pattern list over one string subject — php's order: pattern by pattern. */
#[\Manticore\Attr\Overload('preg_replace')]
function __preg_replace_list(array|string $pattern, array|string $replacement, string $subject, int $limit = -1, #[RefOut] int &$count = 0): ?string
{
    $count = 0;
    if (!\is_array($pattern)) {
        return \preg_replace__str($pattern, (string)$replacement, $subject, $limit, $count);
    }
    /** @var string[] $reps */
    $reps = [];
    if (\is_array($replacement)) {
        foreach ($replacement as $r) { $reps[] = (string)$r; }
    }
    $out = $subject;
    $i = 0;
    foreach ($pattern as $p) {
        $r = \is_array($replacement) ? ($reps[$i] ?? '') : $replacement;
        $c = 0;
        $next = \preg_replace__str((string)$p, $r, $out, $limit, $c);
        if ($next === null) { return null; }
        $out = $next;
        $count = $count + $c;
        $i = $i + 1;
    }
    return $out;
}

#[\Manticore\Attr\Overload('preg_replace')]
function preg_replace__str(string $pattern, string $replacement, string $subject, int $limit = -1, #[RefOut] int &$count = 0): ?string
{
    $count = 0;
    $code = \__preg_compile($pattern);
    if ($code === 0) { return null; }
    $len = \strlen($subject);
    $md = \__preg_md($code, 0);
    $out = "";
    $pos = 0;
    // No `$N` / `\N` in the replacement: it is inserted as written.
    $literal = \strcspn($replacement, '$\\') === \strlen($replacement);
    $ov = \Runtime\Pcre\ovectorPtr($md);
    while ($pos <= $len) {
        if ($limit >= 0 && $count >= $limit) { break; }
        $rc = \__preg_exec($code, $md, $subject, $len, $pos);
        if ($rc === 0) {
            if (__McPcreCache::$err !== 0) { \__preg_md($code, $md); return null; }
            break;
        }
        $ms = \peek_i64($ov, 0);
        $me = \peek_i64($ov, 8);
        $out .= \substr($subject, $pos, $ms - $pos);         // text before the match
        $out .= $literal ? $replacement : \__preg_expand($replacement, $subject, $ov, $rc);  // the replacement
        $count = $count + 1;
        $next = \__preg_advance($ms, $me);
        if ($me === $ms) { $out .= \substr($subject, $ms, 1); }  // empty match: keep the char
        $pos = $next;
    }
    \__preg_md($code, $md);
    if ($count === 0) { return $subject; }
    if ($pos < $len) { $out .= \substr($subject, $pos, $len - $pos); }
    return $out;
}

/**
 * php's `preg_replace_callback`: a pattern list applies in order, an array
 * subject maps key by key. The typed forms are overloads.
 */
function preg_replace_callback(array|string $pattern, callable $callback, array|string $subject, int $limit = -1, #[RefOut] int &$count = 0): array|string|null
{
    $count = 0;
    if (\is_array($subject)) {
        $out = [];
        foreach ($subject as $k => $one) {
            $c = 0;
            $r = \__preg_replace_callback_list($pattern, $callback, (string)$one, $limit, $c);
            if ($r !== null) { $out[$k] = $r; }
            $count = $count + $c;
        }
        return $out;
    }
    return \__preg_replace_callback_list($pattern, $callback, $subject, $limit, $count);
}

/** A pattern list over one string subject. */
#[\Manticore\Attr\Overload('preg_replace_callback')]
function __preg_replace_callback_list(array|string $pattern, callable $callback, string $subject, int $limit = -1, #[RefOut] int &$count = 0): ?string
{
    $count = 0;
    if (!\is_array($pattern)) {
        return \preg_replace_callback__str($pattern, $callback, $subject, $limit, $count);
    }
    $out = $subject;
    foreach ($pattern as $p) {
        $c = 0;
        $next = \preg_replace_callback__str((string)$p, $callback, $out, $limit, $c);
        if ($next === null) { return null; }
        $out = $next;
        $count = $count + $c;
    }
    return $out;
}

#[\Manticore\Attr\Overload('preg_replace_callback')]
function preg_replace_callback__str(string $pattern, callable $callback, string $subject, int $limit = -1, #[RefOut] int &$count = 0): ?string
{
    $count = 0;
    $code = \__preg_compile($pattern);
    if ($code === 0) { return null; }
    $len = \strlen($subject);
    $md = \__preg_md($code, 0);
    // A named group reaches the callback under its name too, as in preg_match.
    $names = \__preg_name_map($code);
    $out = "";
    $pos = 0;
    $ov = \Runtime\Pcre\ovectorPtr($md);
    $hasNames = $names !== [];
    while ($pos <= $len) {
        if ($limit >= 0 && $count >= $limit) { break; }
        $rc = \__preg_exec($code, $md, $subject, $len, $pos);
        if ($rc === 0) {
            if (__McPcreCache::$err !== 0) { \__preg_md($code, $md); return null; }
            break;
        }
        $ms = \peek_i64($ov, 0);
        $me = \peek_i64($ov, 8);
        /** @var mixed[] $arg */
        $arg = [];
        for ($i = 0; $i < $rc; $i = $i + 1) {
            $s = \peek_i64($ov, $i * 16);
            $g = $s < 0 ? "" : \substr($subject, $s, \peek_i64($ov, $i * 16 + 8) - $s);
            if ($hasNames && isset($names[$i])) { $arg[$names[$i]] = $g; }
            $arg[$i] = $g;
        }
        $out .= \substr($subject, $pos, $ms - $pos);
        $out .= $callback($arg);
        $count = $count + 1;
        $next = \__preg_advance($ms, $me);
        if ($me === $ms) { $out .= \substr($subject, $ms, 1); }
        $pos = $next;
    }
    \__preg_md($code, $md);
    if ($pos < $len) { $out .= \substr($subject, $pos, $len - $pos); }
    return $out;
}

/**
 * @param array<string, callable> $patterns
 */
function preg_replace_callback_array(array $patterns, string $subject, int $limit = -1, #[RefOut] int &$count = 0): ?string
{
    $count = 0;
    foreach ($patterns as $pattern => $callback) {
        $c = 0;
        $next = \preg_replace_callback__str($pattern, $callback, $subject, $limit, $c);
        if ($next === null) { return null; }
        $subject = $next;
        $count = $count + $c;
    }
    return $subject;
}

// ── preg_split ─────────────────────────────────────────────────────────────

/**
 * @return string[]|false
 */
function preg_split(string $pattern, string $subject, int $limit = -1, int $flags = 0): array|false
{
    $noEmpty = ($flags & 1) !== 0;          // PREG_SPLIT_NO_EMPTY
    $delimCap = ($flags & 2) !== 0;         // PREG_SPLIT_DELIM_CAPTURE
    $offCap = ($flags & 4) !== 0;          // PREG_SPLIT_OFFSET_CAPTURE
    $out = [];
    $code = \__preg_compile($pattern);
    if ($code === 0) { return false; }
    $len = \strlen($subject);
    $md = \__preg_md($code, 0);
    $ov = \Runtime\Pcre\ovectorPtr($md);
    $last = 0;
    $pos = 0;
    $pieces = 0;
    while ($pos <= $len) {
        if ($limit > 0 && $pieces >= $limit - 1) { break; }
        $rc = \__preg_exec($code, $md, $subject, $len, $pos);
        if ($rc === 0) {
            if (__McPcreCache::$err !== 0) { \__preg_md($code, $md); return false; }
            break;
        }
        $ms = \peek_i64($ov, 0);
        $me = \peek_i64($ov, 8);
        if ($me === $ms && $ms === $last) {
            // empty match at the cursor — advance without splitting
            $pos = $ms + 1;
            continue;
        }
        $piece = \substr($subject, $last, $ms - $last);
        if (!$noEmpty || $piece !== "") { $out[] = $offCap ? [$piece, $last] : $piece; $pieces = $pieces + 1; }
        if ($delimCap) {
            for ($i = 1; $i < $rc; $i = $i + 1) {
                $s = \peek_i64($ov, $i * 16);
                $g = $s < 0 ? "" : \substr($subject, $s, \peek_i64($ov, $i * 16 + 8) - $s);
                if (!$noEmpty || $g !== "") { $out[] = $offCap ? [$g, $s] : $g; }
            }
        }
        $last = $me;
        $pos = \__preg_advance($ms, $me);
    }
    \__preg_md($code, $md);
    $tail = \substr($subject, $last, $len - $last);
    if (!$noEmpty || $tail !== "") { $out[] = $offCap ? [$tail, $last] : $tail; }
    return $out;
}

// ── preg_grep ──────────────────────────────────────────────────────────────

/**
 * ⚠ The parameter is deliberately left ERASED. A `string[]` declaration reads
 * every element RAW, and the caller's array may hold BOXED cells —
 * `array_keys()` answers `vec[cell]`, and symfony's `Application::find()` does
 * `preg_grep(…, array_keys($this->commands))`, so preg_match dereferenced the
 * NaN tag and `./app <unknown-command>` SIGSEGV'd before printing anything.
 * `mixed[]` is not the answer either — it would force cells on the concrete
 * callers. `__mir_untag_str` normalises whatever arrived to an OWNED raw
 * string, which keeps the RESULT honestly `string[]` for its consumers.
 *
 * @return array<int|string, string>
 */
function preg_grep(string $pattern, array $array, int $flags = 0): array
{
    $invert = ($flags & 1) !== 0;           // PREG_GREP_INVERT
    $out = [];
    $tmp = [];
    foreach ($array as $key => $value) {
        $s = \__mir_untag_str($value);
        $r = \preg_match($pattern, $s, $tmp);
        // php stops at the first error and answers what it kept so far.
        if ($r === false) { break; }
        $hit = $r === 1;
        if ($hit !== $invert) { $out[$key] = $s; }
    }
    return $out;
}
