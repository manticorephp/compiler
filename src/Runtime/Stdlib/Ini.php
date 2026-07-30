<?php

/**
 * Pure-PHP parse_ini_string / parse_ini_file. Global namespace so user code
 * resolves here. Matches php 8.5 on well-formed ini: `key = value` pairs,
 * `[section]` headers, `;` comments (leading + inline), single/double quoted
 * values, `key[]` / `key[sub]` arrays, and the three scanner modes
 * (NORMAL=0 keyword→'1'/'' coercion, RAW=1 raw strings, TYPED=2 real scalars).
 *
 * php's zend_ini_parser rejects some malformed lines with a hard false; this
 * best-effort parser skips a malformed line instead. Well-formed input — the
 * realistic case and the difftest corpus — is byte-identical.
 */

/** Coerce a bare (unquoted) ini value per scanner mode. */
function __mc_ini_coerce(string $v, int $mode)
{
    if ($mode === 1) { // INI_SCANNER_RAW
        return $v;
    }
    $low = \strtolower($v);
    if ($mode === 2) { // INI_SCANNER_TYPED
        if ($low === 'true' || $low === 'on' || $low === 'yes') {
            return true;
        }
        if ($low === 'false' || $low === 'off' || $low === 'no' || $low === 'none') {
            return false;
        }
        if ($low === 'null') {
            return null;
        }
        if (\__mc_ini_is_int($v)) {
            return \intval($v);
        }
        if (\__mc_ini_is_float($v)) {
            return \floatval($v);
        }
        return $v;
    }
    // INI_SCANNER_NORMAL
    if ($low === 'true' || $low === 'on' || $low === 'yes') {
        return '1';
    }
    if ($low === 'false' || $low === 'off' || $low === 'no' || $low === 'none' || $low === 'null') {
        return '';
    }
    return $v;
}

/** Whether $v is a plain decimal integer literal (optional sign). */
function __mc_ini_is_int(string $v): bool
{
    $n = \strlen($v);
    if ($n === 0) {
        return false;
    }
    $i = 0;
    if ($v[0] === '-' || $v[0] === '+') {
        $i = 1;
    }
    if ($i >= $n) {
        return false;
    }
    for (; $i < $n; $i++) {
        $c = $v[$i];
        if ($c < '0' || $c > '9') {
            return false;
        }
    }
    return true;
}

/** Whether $v is a plain decimal float literal. */
function __mc_ini_is_float(string $v): bool
{
    $n = \strlen($v);
    if ($n === 0) {
        return false;
    }
    $i = 0;
    if ($v[0] === '-' || $v[0] === '+') {
        $i = 1;
    }
    $digits = false;
    $dot = false;
    for (; $i < $n; $i++) {
        $c = $v[$i];
        if ($c >= '0' && $c <= '9') {
            $digits = true;
            continue;
        }
        if ($c === '.' && !$dot) {
            $dot = true;
            continue;
        }
        return false;
    }
    return $digits && $dot;
}

/** Strip an inline `;` comment that sits outside a quoted span; then trim. */
function __mc_ini_value(string $raw, int $mode)
{
    $s = \trim($raw);
    $n = \strlen($s);
    if ($n === 0) {
        return ($mode === 2) ? '' : '';
    }
    $q = $s[0];
    if ($q === '"' || $q === "'") {
        // quoted: content up to the matching closing quote; rest ignored
        $out = '';
        $i = 1;
        while ($i < $n) {
            if ($s[$i] === $q) {
                break;
            }
            $out .= $s[$i];
            $i++;
        }
        return $out; // quoted values are always strings, no coercion
    }
    // bare value: cut at first ';' inline comment, then rtrim
    $cut = $n;
    for ($i = 0; $i < $n; $i++) {
        if ($s[$i] === ';') {
            $cut = $i;
            break;
        }
    }
    $val = \rtrim(\substr($s, 0, $cut));
    return \__mc_ini_coerce($val, $mode);
}

/**
 * Parse ini text into an array (or false on gross failure).
 * @return array<string,mixed>|false
 */
function parse_ini_string(string $ini, bool $process_sections = false, int $scanner_mode = 0)
{
    $result = [];
    $section = '';
    $len = \strlen($ini);
    $lineStart = 0;
    $pos = 0;
    while ($pos <= $len) {
        if ($pos === $len || $ini[$pos] === "\n") {
            $line = \substr($ini, $lineStart, $pos - $lineStart);
            $line = \rtrim($line, "\r");
            $t = \trim($line);
            $lineStart = $pos + 1;
            $pos++;
            $tn = \strlen($t);
            if ($tn === 0 || $t[0] === ';') {
                continue;
            }
            if ($t[0] === '[') {
                $end = \strpos($t, ']');
                if ($end !== false) {
                    $section = \substr($t, 1, $end - 1);
                }
                continue;
            }
            $eq = \strpos($t, '=');
            if ($eq === false) {
                continue; // malformed: no '=' (php would error; we skip)
            }
            $key = \rtrim(\substr($t, 0, $eq));
            $rawval = \substr($t, $eq + 1);
            if ($key === '') {
                continue;
            }
            $val = \__mc_ini_value($rawval, $scanner_mode);
            // key[] / key[sub] array syntax
            $lb = \strpos($key, '[');
            if ($lb !== false && \substr($key, -1) === ']') {
                $base = \substr($key, 0, $lb);
                $inner = \substr($key, $lb + 1, \strlen($key) - $lb - 2);
                if ($process_sections && $section !== '') {
                    if (!isset($result[$section]) || !\is_array($result[$section])) {
                        $result[$section] = [];
                    }
                    if (!isset($result[$section][$base]) || !\is_array($result[$section][$base])) {
                        $result[$section][$base] = [];
                    }
                    if ($inner === '') {
                        $result[$section][$base][] = $val;
                    } else {
                        $result[$section][$base][$inner] = $val;
                    }
                } else {
                    if (!isset($result[$base]) || !\is_array($result[$base])) {
                        $result[$base] = [];
                    }
                    if ($inner === '') {
                        $result[$base][] = $val;
                    } else {
                        $result[$base][$inner] = $val;
                    }
                }
                continue;
            }
            if ($process_sections && $section !== '') {
                if (!isset($result[$section]) || !\is_array($result[$section])) {
                    $result[$section] = [];
                }
                $result[$section][$key] = $val;
            } else {
                $result[$key] = $val;
            }
            continue;
        }
        $pos++;
    }
    return $result;
}

/**
 * Parse an ini file into an array (or false on failure).
 * @return array<string,mixed>|false
 */
function parse_ini_file(string $filename, bool $process_sections = false, int $scanner_mode = 0)
{
    $raw = \file_get_contents($filename);
    if ($raw === false) {
        return false;
    }
    return \parse_ini_string((string)$raw, $process_sections, $scanner_mode);
}

/**
 * php's runtime configuration surface — the compiled-in defaults.
 *
 * A compiled binary has NO php.ini — that is a project principle, not an
 * omission — so `php_ini_loaded_file()` and `php_ini_scanned_files()` answer
 * `false`, which is exactly what php answers when it was started with `-n`.
 * This table is therefore the *default* layer; `ini_set` writes an override
 * layer on top of it (see `__mc_ini_store`). `ini_get` reports the directives
 * that are genuinely observable here and `false` for everything else, which is
 * also php's answer for an unknown name.
 *
 * The `session.*` block carries php 8.5's own defaults verbatim, because the
 * session extension reads its whole configuration through `ini_get`/`ini_set`
 * rather than keeping a second copy of it.
 */
function __mc_ini_table(): array
{
    return [
        // No arena limit: this binary allocates from the system allocator.
        "memory_limit" => "-1",
        "precision" => "14",
        "serialize_precision" => "-1",
        // Assertions are compiled out, matching php's production default.
        "zend.assertions" => "-1",
        // There is no mbstring extension to overload with.
        "mbstring.func_overload" => "0",
        "default_charset" => "UTF-8",
        "display_errors" => "1",
        "log_errors" => "0",
        "max_execution_time" => "0",
        "date.timezone" => "UTC",
        "session.name" => "PHPSESSID",
        // Empty means "the system temp dir", resolved when the store opens.
        "session.save_path" => "",
        "session.save_handler" => "files",
        "session.serialize_handler" => "php",
        "session.auto_start" => "0",
        "session.gc_probability" => "1",
        "session.gc_divisor" => "1000",
        "session.gc_maxlifetime" => "1440",
        "session.lazy_write" => "1",
        "session.use_strict_mode" => "0",
        "session.use_cookies" => "1",
        "session.use_only_cookies" => "1",
        "session.cookie_lifetime" => "0",
        "session.cookie_path" => "/",
        "session.cookie_domain" => "",
        "session.cookie_secure" => "0",
        // php's built-in default really is empty, not "0", unlike cookie_secure.
        "session.cookie_httponly" => "",
        "session.cookie_samesite" => "",
        "session.cache_limiter" => "nocache",
        "session.cache_expire" => "180",
        "session.sid_length" => "32",
        "session.sid_bits_per_character" => "4",
    ];
}

/**
 * The mutable half of the ini surface: one process-wide override store.
 *
 * ⚠ Kept as a `static string` blob of `key=percent-encoded-value` lines, not as
 * a `static array`, and that is not style: an array BUILT INSIDE the stdlib does
 * not survive being parked in a static (the same trap `__mc_http_headers_store`
 * documents in Net.php). ini traffic is cold, so re-scanning the blob costs
 * nothing measurable, and it keeps the store where `ini_get` has to live —
 * inside the stdlib, because a stdlib function may never demand a prelude.
 *
 * $op: 0 = read, 1 = write, 2 = drop, 3 = dump the raw blob.
 * A read returns the override, or "\x00" when the key has none — "" is a legal
 * ini value (`session.save_path`), so absence needs a value no directive holds.
 */
function __mc_ini_store(int $op, string $key, string $val): string
{
    static $blob = '';
    if ($op === 3) {
        return $blob;
    }
    $found = "\x00";
    $out = '';
    // Scanned with strpos/substr rather than explode(): inside the stdlib the
    // `$blob === '' ? [] : explode(...)` union erased the element type, and the
    // rebuilt blob then carried a raw POINTER where the first line belonged
    // ("4348863856" instead of session.save_path=…), silently dropping every
    // override but the last. Pure string scanning has no element to erase.
    $n = \strlen($blob);
    $i = 0;
    while ($i < $n) {
        $nl = \strpos($blob, "\n", $i);
        $end = ($nl === false) ? $n : $nl;
        $line = \substr($blob, $i, $end - $i);
        $i = $end + 1;
        if ($line === '') {
            continue;
        }
        $eq = \strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        if (\substr($line, 0, $eq) === $key) {
            $found = \rawurldecode(\substr($line, $eq + 1));
            continue; // dropped here; a write re-appends it below
        }
        $out .= $line . "\n";
    }
    if ($op === 0) {
        return $found;
    }
    if ($op === 1) {
        $out .= $key . '=' . \rawurlencode($val) . "\n";
    }
    $blob = $out;
    return $found;
}

/** php's string coercion for an ini value: bool and null are '1'/''. */
function __mc_ini_str(mixed $value): string
{
    if (\is_bool($value)) {
        return $value ? '1' : '';
    }
    if ($value === null) {
        return '';
    }
    return (string)$value;
}

/** One ini directive's value as a string, or false when there is no such one. */
function ini_get(string $option): mixed
{
    $ov = \__mc_ini_store(0, $option, '');
    if ($ov !== "\x00") {
        return $ov;
    }
    $t = __mc_ini_table();
    if (isset($t[$option])) { return $t[$option]; }
    // NOT special-cased to the live error_reporting() mask: that function
    // lives in the demand-gated errors prelude, and a stdlib function must
    // never demand a prelude — the stdlib is compiled on its own.
    return false;
}

/**
 * Every directive, in php's nested shape. `$details = false` flattens it to
 * name => value, which is the form symfony/process reads. `$extension` filters
 * by directive prefix, so `ini_get_all('session')` is the session block.
 * @return array<string,mixed>
 */
function ini_get_all(?string $extension = null, bool $details = true): array
{
    $prefix = ($extension === null || $extension === '') ? '' : $extension . '.';
    $out = [];
    foreach (__mc_ini_table() as $k => $v) {
        if ($prefix !== '' && \strncmp($k, $prefix, \strlen($prefix)) !== 0) {
            continue;
        }
        $live = \ini_get($k);
        $sv = ($live === false) ? $v : (string)$live;
        if ($details) {
            $out[$k] = ["global_value" => $v, "local_value" => $sv, "access" => 7];
        } else {
            $out[$k] = $sv;
        }
    }
    return $out;
}

/** Whether a directive is one php freezes once output has started. */
function __mc_ini_frozen(string $option): bool
{
    return \strncmp($option, 'session.', 8) === 0 && \__mc_out_sent(0) === 1;
}

/**
 * Set a directive for the rest of the process, returning the OLD value, or
 * false for a directive this binary does not carry (php's answer too).
 *
 * A directive the runtime never reads changes only what `ini_get` reports —
 * `memory_limit` and `max_execution_time` have nothing to act on here. The
 * `session.*` block and `date.timezone` are live.
 *
 * `session.*` is frozen once output has gone out, exactly as php freezes it;
 * php also warns, and the false return is the half that carries information —
 * the diagnostic is the half this runtime drops.
 */
function ini_set(string $option, mixed $value): mixed
{
    $old = \ini_get($option);
    if ($old === false || \__mc_ini_frozen($option)) {
        return false;
    }
    $sv = \__mc_ini_str($value);
    // date.timezone and date_default_timezone_set() are ONE value in php, so a
    // write here has to reach the live slot or the two APIs would disagree.
    if ($option === 'date.timezone' && !\date_default_timezone_set($sv)) {
        return false;
    }
    \__mc_ini_store(1, $option, $sv);
    return $old;
}

/** Drop the override, so the compiled-in default is visible again. */
function ini_restore(string $option): void
{
    if (\__mc_ini_frozen($option)) {
        return;
    }
    \__mc_ini_store(2, $option, '');
}

/** No php.ini was loaded, because there is none. */
function php_ini_loaded_file(): mixed
{
    return false;
}

/** Nor a scan directory. */
function php_ini_scanned_files(): mixed
{
    return false;
}

/** php's get_cfg_var, which reads the same table. */
function get_cfg_var(string $option): mixed
{
    return \ini_get($option);
}
