<?php

/**
 * Additional PHP variable-handling functions. Pure-PHP / global namespace.
 * The is_* type predicates and gettype/var_dump/var_export are codegen builtins;
 * these fill the small remaining gaps.
 */

/** `(bool)` of any value (PHP `boolval`). */
function boolval(mixed $value): bool
{
    return (bool)$value;
}

/** `(string)` of a scalar value (PHP `strval`). */
function strval(mixed $value): string
{
    return (string)$value;
}

/** True for int / float / string / bool; false for null / array / object
 *  (PHP `is_scalar`). */
function is_scalar(mixed $value): bool
{
    return \is_int($value) || \is_float($value) || \is_string($value) || \is_bool($value);
}

/** True for an array (the Traversable case is not modelled) — PHP `is_iterable`. */
function is_iterable(mixed $value): bool
{
    return \is_array($value);
}

/** True for an array (the Countable case is not modelled) — PHP `is_countable`. */
function is_countable(mixed $value): bool
{
    return \is_array($value);
}

/**
 * var_export string quoting: php.net escapes exactly two bytes inside the
 * single quotes — the backslash and the quote itself. Backslash first, or the
 * one introduced by the quote escape would be doubled again.
 */
function __mc_var_export_qstr(string $s): string
{
    return \str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
}

/**
 * var_export for a value whose type is only known at runtime. The codegen
 * builtin formats statically-typed scalars inline and delegates everything
 * else (arrays, mixed, unions) here, where the NaN tag can be read.
 *
 * $indent is the column the enclosing line starts at: php.net puts a nested
 * array on its OWN line, indented to the key rather than past it, so the value
 * needs to know where its key began.
 */
function __mc_var_export_cell(mixed $v, int $indent): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (\is_bool($v)) {
        return $v ? 'true' : 'false';
    }
    if (\is_int($v)) {
        return (string)$v;
    }
    if (\is_float($v)) {
        // upperE=1, forceDot=1 — var_export must round-trip as a float, so an
        // integer-valued decimal keeps its `.0`.
        return \__mc_dtoa_core(\__float_bits((float)$v), 1, 1);
    }
    if (\is_string($v)) {
        return "'" . \__mc_var_export_qstr((string)$v) . "'";
    }
    if (\is_array($v)) {
        $pad = \str_repeat(' ', $indent);
        $inner = $pad . '  ';
        $out = "array (\n";
        foreach ($v as $k => $e) {
            $ks = \is_int($k) ? (string)$k : ("'" . \__mc_var_export_qstr((string)$k) . "'");
            $out = $out . $inner . $ks . ' => ';
            if (\is_array($e)) {
                // php.net breaks the line before a nested array and re-indents
                // it to the key's own column.
                $out = $out . "\n" . $inner;
            }
            $out = $out . \__mc_var_export_cell($e, $indent + 2) . ",\n";
        }
        return $out . $pad . ')';
    }
    return 'NULL';
}
