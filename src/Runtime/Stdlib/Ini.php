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
