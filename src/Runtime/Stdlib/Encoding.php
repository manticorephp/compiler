<?php

// Web/API encoding helpers — base64, URL encoding, URL/query parsing — all pure
// PHP (byte-oriented, no FFI). Global php.net surface.

// ── base64 ─────────────────────────────────────────────────────────────

function base64_encode(string $data): string
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
    $out = '';
    $n = \strlen($data);
    $i = 0;
    while ($i < $n) {
        $b0 = \ord($data[$i]);
        $has1 = $i + 1 < $n;
        $has2 = $i + 2 < $n;
        $b1 = $has1 ? \ord($data[$i + 1]) : 0;
        $b2 = $has2 ? \ord($data[$i + 2]) : 0;
        $out = $out . $chars[$b0 >> 2];
        $out = $out . $chars[(($b0 & 3) << 4) | ($b1 >> 4)];
        $out = $out . ($has1 ? $chars[(($b1 & 15) << 2) | ($b2 >> 6)] : '=');
        $out = $out . ($has2 ? $chars[$b2 & 63] : '=');
        $i = $i + 3;
    }
    return $out;
}

/** The value 0..63 of a base64 char, or -1 if not in the alphabet. */
function __mc_b64val(int $c): int
{
    if ($c >= 65 && $c <= 90) { return $c - 65; }        // A-Z
    if ($c >= 97 && $c <= 122) { return $c - 97 + 26; }  // a-z
    if ($c >= 48 && $c <= 57) { return $c - 48 + 52; }   // 0-9
    if ($c === 43) { return 62; }                        // +
    if ($c === 47) { return 63; }                        // /
    return -1;
}

/**
 * base64_decode. In loose mode (default) non-alphabet bytes are skipped; in
 * strict mode any invalid byte returns false. Padding `=` ends the stream.
 */
function base64_decode(string $data, bool $strict = false): string|false
{
    $out = '';
    $n = \strlen($data);
    $acc = 0;
    $bits = 0;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = \ord($data[$i]);
        if ($c === 61) {   // '=' padding — stop
            break;
        }
        $v = \__mc_b64val($c);
        if ($v < 0) {
            // whitespace is tolerated in both modes; other junk fails in strict
            if ($c === 32 || $c === 9 || $c === 10 || $c === 13 || $c === 11 || $c === 12) {
                continue;
            }
            if ($strict) { return false; }
            continue;
        }
        $acc = ($acc << 6) | $v;
        $bits = $bits + 6;
        if ($bits >= 8) {
            $bits = $bits - 8;
            $out = $out . \chr(($acc >> $bits) & 255);
        }
    }
    return $out;
}

// ── URL encoding ───────────────────────────────────────────────────────

/** Uppercase hex digit for 0..15. */
function __mc_hexdig(int $v): string
{
    if ($v < 10) { return \chr(48 + $v); }
    return \chr(55 + $v);   // 'A'..'F'
}

/** An unreserved char under the form (`urlencode`) or raw (`rawurlencode`) set. */
function __mc_url_unreserved(int $c, bool $raw): bool
{
    if (($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || ($c >= 48 && $c <= 57)) {
        return true;
    }
    if ($c === 45 || $c === 95 || $c === 46) { return true; }   // - _ .
    if ($raw && $c === 126) { return true; }                    // ~ (raw only)
    return false;
}

function __mc_url_encode(string $s, bool $raw): string
{
    $out = '';
    $n = \strlen($s);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = \ord($s[$i]);
        if (\__mc_url_unreserved($c, $raw)) {
            $out = $out . $s[$i];
        } elseif (!$raw && $c === 32) {
            $out = $out . '+';   // form encoding: space -> '+'
        } else {
            $out = $out . '%' . \__mc_hexdig($c >> 4) . \__mc_hexdig($c & 15);
        }
    }
    return $out;
}

function urlencode(string $string): string { return \__mc_url_encode($string, false); }
function rawurlencode(string $string): string { return \__mc_url_encode($string, true); }

/** Hex value of an ASCII hex digit, or -1. */
function __mc_unhex(int $c): int
{
    if ($c >= 48 && $c <= 57) { return $c - 48; }
    if ($c >= 65 && $c <= 70) { return $c - 55; }
    if ($c >= 97 && $c <= 102) { return $c - 87; }
    return -1;
}

function __mc_url_decode(string $s, bool $raw): string
{
    $out = '';
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        $c = \ord($s[$i]);
        if (!$raw && $c === 43) {   // '+' -> space (form only)
            $out = $out . ' ';
            $i = $i + 1;
        } elseif ($c === 37 && $i + 2 < $n) {   // %XX
            $hi = \__mc_unhex(\ord($s[$i + 1]));
            $lo = \__mc_unhex(\ord($s[$i + 2]));
            if ($hi >= 0 && $lo >= 0) {
                $out = $out . \chr(($hi << 4) | $lo);
                $i = $i + 3;
            } else {
                $out = $out . $s[$i];
                $i = $i + 1;
            }
        } else {
            $out = $out . $s[$i];
            $i = $i + 1;
        }
    }
    return $out;
}

function urldecode(string $string): string { return \__mc_url_decode($string, false); }
function rawurldecode(string $string): string { return \__mc_url_decode($string, true); }

// ── parse_url ──────────────────────────────────────────────────────────

/**
 * parse_url($url, $component=-1). Returns an assoc array with the keys that are
 * present (scheme/host/port/user/pass/path/query/fragment), or — when $component
 * is a PHP_URL_* int — that single value (string, or int for port), or null.
 *
 * @return array<string,mixed>|string|int|null|false
 */
function parse_url(string $url, int $component = -1): mixed
{
    // A function that returns BOTH an array and scalars in different branches
    // boxes the array-return inconsistently across the stdlib `.sig` boundary
    // (the caller read it as raw bits, is_array()/var_dump() faulted). So the
    // parse lives in __mc_url_parse(), which returns a CLEANLY `array`-typed
    // value — exactly the shape stat()/scandir() use — and this wrapper either
    // hands that array back or pulls one component out of it.
    $parts = \__mc_url_parse($url);
    if ($component < 0) {
        return $parts;
    }
    if ($component === 0) { return $parts['scheme'] ?? null; }
    if ($component === 1) { return $parts['host'] ?? null; }
    if ($component === 2) { return $parts['port'] ?? null; }
    if ($component === 3) { return $parts['user'] ?? null; }
    if ($component === 4) { return $parts['pass'] ?? null; }
    if ($component === 5) { return $parts['path'] ?? null; }
    if ($component === 6) { return $parts['query'] ?? null; }
    if ($component === 7) { return $parts['fragment'] ?? null; }
    return null;
}

/**
 * The parser proper — returns the full assoc array of present components.
 *
 * Declared `array|false` (never actually false) rather than `array` ON PURPOSE:
 * a plain-`array` return coerced into parse_url()'s `mixed` return did not box
 * across the stdlib boundary (is_array()/var_dump() on the result faulted). An
 * `array|false` return is already a tagged CELL — the exact shape stat()/scandir()
 * hand back — so parse_url() passes it straight through with no re-box.
 *
 * @return array<string,mixed>|false
 */
function __mc_url_parse(string $url): array|false
{
    $scheme = '';
    $host = '';
    $port = -1;
    $user = '';
    $pass = '';
    $path = '';
    $query = '';
    $fragment = '';
    $hasScheme = false;
    $hasHost = false;
    $hasPort = false;
    $hasUser = false;
    $hasPass = false;
    $hasPath = false;
    $hasQuery = false;
    $hasFragment = false;

    $rest = $url;

    // fragment (first '#')
    $hpos = \strpos($rest, '#');
    if ($hpos !== false) {
        $fragment = \substr($rest, $hpos + 1);
        $hasFragment = true;
        $rest = \substr($rest, 0, $hpos);
    }
    // query (first '?')
    $qpos = \strpos($rest, '?');
    if ($qpos !== false) {
        $query = \substr($rest, $qpos + 1);
        $hasQuery = true;
        $rest = \substr($rest, 0, $qpos);
    }
    // scheme: leading `name:` where name is [a-zA-Z][a-zA-Z0-9+.-]*
    $cpos = \strpos($rest, ':');
    if ($cpos > 0) {
        $cand = \substr($rest, 0, $cpos);
        if (\__mc_is_scheme($cand)) {
            // require it to be followed by "//" OR by a non-digit (so "host:80"
            // is NOT read as scheme "host") — php treats "host:80/x" as authority.
            $after = \substr($rest, $cpos + 1);
            if (\substr($after, 0, 2) === '//' || !\__mc_all_digits_until_slash($after)) {
                $scheme = \strtolower($cand);
                $hasScheme = true;
                $rest = $after;
            }
        }
    }
    // authority: only when the remainder starts with "//"
    if (\substr($rest, 0, 2) === '//') {
        $rest = \substr($rest, 2);
        // authority runs up to the first '/', which begins the path
        $slash = \strpos($rest, '/');
        $auth = $slash === false ? $rest : \substr($rest, 0, $slash);
        $rest = $slash === false ? '' : \substr($rest, $slash);
        // userinfo before '@'
        $at = \strrpos($auth, '@');
        if ($at !== false) {
            $userinfo = \substr($auth, 0, $at);
            $auth = \substr($auth, $at + 1);
            $ucolon = \strpos($userinfo, ':');
            if ($ucolon !== false) {
                $user = \substr($userinfo, 0, $ucolon);
                $pass = \substr($userinfo, $ucolon + 1);
                $hasUser = true;
                $hasPass = true;
            } else {
                $user = $userinfo;
                $hasUser = true;
            }
        }
        // host[:port]
        $pcolon = \strrpos($auth, ':');
        if ($pcolon !== false && \__mc_all_digits(\substr($auth, $pcolon + 1))) {
            $host = \substr($auth, 0, $pcolon);
            $portStr = \substr($auth, $pcolon + 1);
            if ($portStr !== '') {
                $port = (int)$portStr;
                $hasPort = true;
            }
        } else {
            $host = $auth;
        }
        $hasHost = true;
    }
    // whatever remains is the path
    if ($rest !== '') {
        $path = $rest;
        $hasPath = true;
    }

    // Full array — include only present components, php's key order. The @var
    // forces MIXED (cell) values: `port` is an int among string values, and
    // without it the assoc is inferred homogeneous `string`, so the raw int is
    // stored into a string-repr slot and read back as a garbage pointer.
    /** @var array<string,mixed> $out */
    $out = [];
    if ($hasScheme) { $out['scheme'] = $scheme; }
    if ($hasHost) { $out['host'] = $host; }
    if ($hasPort) { $out['port'] = $port; }
    if ($hasUser) { $out['user'] = $user; }
    if ($hasPass) { $out['pass'] = $pass; }
    if ($hasPath) { $out['path'] = $path; }
    if ($hasQuery) { $out['query'] = $query; }
    if ($hasFragment) { $out['fragment'] = $fragment; }
    return $out;
}

function __mc_is_scheme(string $s): bool
{
    $n = \strlen($s);
    if ($n === 0) { return false; }
    $c0 = \ord($s[0]);
    if (!(($c0 >= 65 && $c0 <= 90) || ($c0 >= 97 && $c0 <= 122))) { return false; }
    for ($i = 1; $i < $n; $i = $i + 1) {
        $c = \ord($s[$i]);
        $ok = ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || ($c >= 48 && $c <= 57)
            || $c === 43 || $c === 46 || $c === 45;   // + . -
        if (!$ok) { return false; }
    }
    return true;
}

function __mc_all_digits(string $s): bool
{
    $n = \strlen($s);
    if ($n === 0) { return false; }
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = \ord($s[$i]);
        if ($c < 48 || $c > 57) { return false; }
    }
    return true;
}

/** True when everything up to the first '/' (or end) is digits — i.e. `:NN` is a
 *  port, not a scheme separator (`localhost:80/x`). */
function __mc_all_digits_until_slash(string $s): bool
{
    $slash = \strpos($s, '/');
    $head = $slash === false ? $s : \substr($s, 0, $slash);
    return \__mc_all_digits($head);
}

// ── query strings ──────────────────────────────────────────────────────

/**
 * parse_str($string, &$result). Decodes an application/x-www-form-urlencoded
 * query into $result, honouring `a[b]` / `a[]` nesting and urldecoding keys and
 * values (form decoding: '+' -> space).
 */
function parse_str(string $string, #[\Manticore\Attr\RefOut] array &$result = []): void
{
    $result = [];
    if ($string === '') {
        return;
    }
    $pairs = \explode('&', $string);
    foreach ($pairs as $pair) {
        if ($pair === '') { continue; }
        $eq = \strpos($pair, '=');
        if ($eq === false) {
            $rawKey = $pair;
            $val = '';
        } else {
            $rawKey = \substr($pair, 0, $eq);
            $val = \urldecode(\substr($pair, $eq + 1));
        }
        \__mc_parse_str_assign($result, $rawKey, $val);
    }
}

/** Assign one decoded value into $arr under a possibly-bracketed key. */
function __mc_parse_str_assign(array &$arr, string $rawKey, string $val): void
{
    $bpos = \strpos($rawKey, '[');
    if ($bpos === false) {
        $arr[\urldecode($rawKey)] = $val;
        return;
    }
    $base = \urldecode(\substr($rawKey, 0, $bpos));
    // Collect the bracketed segments in order.
    /** @var string[] $segs */
    $segs = [];
    $s = \substr($rawKey, $bpos);
    $n = \strlen($s);
    $i = 0;
    while ($i < $n) {
        if ($s[$i] === '[') {
            $close = \strpos($s, ']', $i);
            if ($close === false) { break; }
            $segs[] = \substr($s, $i + 1, $close - $i - 1);
            $i = $close + 1;
        } else {
            $i = $i + 1;
        }
    }
    if (!isset($arr[$base]) || !\is_array($arr[$base])) {
        $arr[$base] = [];
    }
    \__mc_nested_assign($arr[$base], $segs, 0, $val);
}

/** Walk/create nested arrays for the bracket segments, then set $val. An empty
 *  segment ("[]") appends. */
function __mc_nested_assign(array &$node, array $segs, int $idx, string $val): void
{
    $seg = $segs[$idx];
    $last = $idx === \count($segs) - 1;
    if ($seg === '') {
        // append
        if ($last) {
            $node[] = $val;
        } else {
            $child = [];
            \__mc_nested_assign($child, $segs, $idx + 1, $val);
            $node[] = $child;
        }
        return;
    }
    if ($last) {
        $node[$seg] = $val;
        return;
    }
    if (!isset($node[$seg]) || !\is_array($node[$seg])) {
        $node[$seg] = [];
    }
    \__mc_nested_assign($node[$seg], $segs, $idx + 1, $val);
}

/**
 * http_build_query($data, ...). Flattens an assoc/list into a form-encoded query,
 * urlencoding keys and values and using `key[sub]` for nested arrays.
 *
 * NOTE: reading the element VALUES of the incoming array back as their real type
 * across the stdlib boundary needs the by-ref/cell-element boxing that the
 * separate repr-consistency epic supplies — until that merges the values read as
 * raw pointers. Implemented; starts producing correct output once repr lands.
 */
function http_build_query(#[\Manticore\Attr\CellArg] array $data, string $numeric_prefix = '', string $arg_separator = '&'): string
{
    $sep = $arg_separator === '' ? '&' : $arg_separator;
    /** @var string[] $parts */
    $parts = [];
    \__mc_build_query($data, '', $numeric_prefix, $parts);
    return \implode($sep, $parts);
}

/** @param string[] $parts */
function __mc_build_query(#[\Manticore\Attr\CellArg] array $data, string $prefix, string $numPrefix, array &$parts): void
{
    foreach ($data as $k => $v) {
        $key = (string)$k;
        if ($prefix === '' && $numPrefix !== '' && \__mc_all_digits($key)) {
            $key = $numPrefix . $key;
        }
        $name = $prefix === '' ? \urlencode($key) : $prefix . '%5B' . \urlencode($key) . '%5D';
        if (\is_array($v)) {
            \__mc_build_query($v, $name, '', $parts);
        } else {
            $parts[] = $name . '=' . \urlencode(\__mc_scalar_str($v));
        }
    }
}

/** A scalar value as php would stringify it for a query (bool -> 0/1). */
function __mc_scalar_str(mixed $v): string
{
    if ($v === true) { return '1'; }
    if ($v === false) { return '0'; }
    if ($v === null) { return ''; }
    return (string)$v;
}
