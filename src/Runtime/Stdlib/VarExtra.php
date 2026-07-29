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

// The runtime walk used to live here as `__mc_var_export_cell`. It moved to
// prelude/var_export.php, because the stdlib is a prebuilt `.o` and cannot be
// handed an OBJECT — so an object nested inside an array had nowhere to go.
// Only the escaper stays: it takes a string and returns a string, which crosses
// the boundary fine, and both the prelude walk and the codegen builtin call it.
