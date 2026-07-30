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
 * php's runtime configuration surface.
 *
 * A compiled binary has NO php.ini — that is a project principle, not an
 * omission — so `php_ini_loaded_file()` and `php_ini_scanned_files()` answer
 * `false`, which is exactly what php answers when it was started with `-n`.
 * `ini_get` reports the directives that are genuinely observable here and
 * `false` for everything else, which is also php's answer for an unknown name.
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
    ];
}

/** One ini directive's value as a string, or false when there is no such one. */
function ini_get(string $option): mixed
{
    $t = __mc_ini_table();
    if (isset($t[$option])) { return $t[$option]; }
    // NOT special-cased to the live error_reporting() mask: that function
    // lives in the demand-gated errors prelude, and a stdlib function must
    // never demand a prelude — the stdlib is compiled on its own.
    return false;
}

/**
 * Every directive, in php's nested shape. `$details = false` flattens it to
 * name => value, which is the form symfony/process reads.
 * @return array<string,mixed>
 */
function ini_get_all(?string $extension = null, bool $details = true): array
{
    $out = [];
    foreach (__mc_ini_table() as $k => $v) {
        if ($details) {
            $out[$k] = ["global_value" => $v, "local_value" => $v, "access" => 7];
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** Setting a directive is a no-op: there is nothing to set it in. */
function ini_set(string $option, mixed $value): mixed
{
    return \ini_get($option);
}

/** Same, for the restore spelling. */
function ini_restore(string $option): void
{
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
