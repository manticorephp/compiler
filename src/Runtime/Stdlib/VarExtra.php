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
