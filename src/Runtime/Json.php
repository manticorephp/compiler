<?php

/**
 * Runtime JSON encoder — a real PHP implementation of json_encode, linked
 * into every compiled binary (replacing the former Rust-runtime stub;
 * extensions/json.php carries only the type signatures). Global namespace
 * so an unqualified `json_encode()` in any compiler namespace resolves
 * here. Mirrors the recursive walker proven in tests/aot/cases/
 * json_roundtrip.php.
 *
 * The matching `json_decode` parser (tests/aot/cases/json_object.php) is
 * NOT here yet: it uses `$o->$key = …` dynamic-property stores, which the
 * AST bootstrap backend can't lower. It lands the moment `bin/compile`
 * flips to the MIR backend (which supports stdClass + dynamic props).
 * See [[mir_selfcompile_migration_2026_06_01]].
 */

/** Encode a value as JSON. */
function json_encode(mixed $value): string {
    return __mc_json_enc($value);
}

/**
 * Escape a string for a JSON string literal: `"` and `\` are backslash-escaped,
 * and the C0 control chars that JSON forbids raw (\b \t \n \f \r) use their short
 * form. A fast-return skips the copy when no byte needs escaping (the common
 * case — plain ASCII words). Amortized `.=` builds the escaped copy.
 */
function __mc_json_escape(string $s): string {
    $n = \strlen($s);
    $needs = false;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $s[$i];
        if ($c === '"' || $c === '\\' || $c < ' ') { $needs = true; break; }
    }
    if (!$needs) { return $s; }
    $out = "";
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $s[$i];
        if ($c === '"') { $out = $out . '\\"'; }
        else if ($c === '\\') { $out = $out . '\\\\'; }
        else if ($c === "\n") { $out = $out . '\\n'; }
        else if ($c === "\t") { $out = $out . '\\t'; }
        else if ($c === "\r") { $out = $out . '\\r'; }
        else if ($c === "\x08") { $out = $out . '\\b'; }
        else if ($c === "\x0C") { $out = $out . '\\f'; }
        else { $out = $out . $c; }
    }
    return $out;
}

/**
 * Decode a JSON string. Objects → assoc arrays, arrays → vecs, scalars →
 * int/float/string/bool/null. The `$associative` flag is accepted for PHP
 * compatibility but ignored — we always return arrays (no stdClass), which
 * is what the manifest reader and config consumers want. Delegates to
 * {@see \Runtime\Json\Parser}.
 */
function json_decode(string $json, bool $associative = true): mixed {
    $parser = new \Runtime\Json\Parser($json);
    return $parser->parse();
}

function __mc_json_enc(mixed $v): string {
    if (is_null($v)) { return "null"; }
    if (is_bool($v)) { $s = (string)$v; return $s === "1" ? "true" : "false"; }
    if (is_int($v)) { return (string)$v; }
    if (is_float($v)) { return (string)$v; }
    if (is_string($v)) { return '"' . __mc_json_escape($v) . '"'; }
    if (is_object($v)) {
        $out = "{";
        $first = true;
        foreach ((array)$v as $k => $val) {
            if (!$first) { $out = $out . ","; }
            $first = false;
            $out = $out . '"' . $k . '":' . __mc_json_enc($val);
        }
        return $out . "}";
    }
    // An array encodes as a JSON list `[...]` only when its keys are
    // 0..n-1 in order; otherwise as a JSON object `{...}` with every key
    // stringified (PHP semantics).
    if (array_is_list($v)) {
        $out = "[";
        $first = true;
        foreach ($v as $val) {
            if (!$first) { $out = $out . ","; }
            $first = false;
            $out = $out . __mc_json_enc($val);
        }
        return $out . "]";
    }
    $out = "{";
    $first = true;
    foreach ($v as $k => $val) {
        if (!$first) { $out = $out . ","; }
        $first = false;
        $out = $out . '"' . $k . '":' . __mc_json_enc($val);
    }
    return $out . "}";
}
