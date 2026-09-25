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

/**
 * `settype(&$var, $type)` — convert in place; php 8 throws a ValueError for
 * an unknown type name. `"null"` unsets the value (php sets it to null).
 * The by-ref `mixed` parameter is what makes the caller's slot a cell
 * (docs/design/value-channels.md, P5/P6), so the new kind lands as itself.
 */
function settype(mixed &$var, string $type): bool
{
    switch (\strtolower($type)) {
        case "int":
        case "integer":
            $var = (int)$var;
            return true;
        case "float":
        case "double":
            $var = (float)$var;
            return true;
        case "string":
            $var = (string)$var;
            return true;
        case "bool":
        case "boolean":
            $var = (bool)$var;
            return true;
        case "array":
            $var = (array)$var;
            return true;
        case "null":
            $var = null;
            return true;
        case "object":
            $var = (object)$var;
            return true;
    }
    throw new \ValueError("settype(): Argument #2 (\$type) must be a valid type");
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
 * ext-filter's `filter_var` for scalar filters. Covers the *_VALIDATE_BOOL /
 * INT / FLOAT / REGEXP ids and the *_DEFAULT / UNSAFE_RAW passthrough — the
 * surface real apps hit (symfony reads `filter_var(env, FILTER_VALIDATE_BOOL)`
 * and `filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])`).
 * `$options` is php's: the flags bitmask, or `['flags' => …, 'options' => […]]`
 * with `default`, `min_range` / `max_range` and `regexp`. The sanitise filters
 * beyond a string passthrough are not modelled.
 */
function filter_var(mixed $value, int $filter = 516, array|int $options = 0): mixed
{
    $flags = \is_int($options) ? $options : (int)($options['flags'] ?? 0);
    $opts = \is_array($options) && \is_array($options['options'] ?? null) ? $options['options'] : [];
    $nullFail = ($flags & 134217728) !== 0;   // FILTER_NULL_ON_FAILURE
    $fail = \array_key_exists('default', $opts) ? $opts['default'] : ($nullFail ? null : false);
    if ($filter === 258) {                       // FILTER_VALIDATE_BOOL
        $s = \strtolower(\trim((string)$value));
        if ($s === '1' || $s === 'true' || $s === 'on' || $s === 'yes') { return true; }
        if ($s === '0' || $s === 'false' || $s === 'off' || $s === 'no' || $s === '') {
            return $nullFail && $s === '' ? null : false;
        }
        return $fail;
    }
    if ($filter === 257) {                        // FILTER_VALIDATE_INT
        $s = \trim((string)$value);
        if ($s === '' || \preg_match('/^[+-]?\d+$/', $s) !== 1) { return $fail; }
        $n = (int)$s;
        if (\array_key_exists('min_range', $opts) && $n < (int)$opts['min_range']) { return $fail; }
        if (\array_key_exists('max_range', $opts) && $n > (int)$opts['max_range']) { return $fail; }
        return $n;
    }
    if ($filter === 259) {                        // FILTER_VALIDATE_FLOAT
        $s = \trim((string)$value);
        if ($s === '' || !\is_numeric($s)) { return $fail; }
        $f = (float)$s;
        if (\array_key_exists('min_range', $opts) && $f < (float)$opts['min_range']) { return $fail; }
        if (\array_key_exists('max_range', $opts) && $f > (float)$opts['max_range']) { return $fail; }
        return $f;
    }
    if ($filter === 272) {                        // FILTER_VALIDATE_REGEXP
        $s = (string)$value;
        $re = (string)($opts['regexp'] ?? '');
        if ($re === '') {
            throw new \ValueError("filter_var(): \"regexp\" option missing");
        }
        return \preg_match($re, $s) === 1 ? $s : $fail;
    }
    // FILTER_DEFAULT / FILTER_UNSAFE_RAW (516) and every unmodelled filter fall
    // back to the string form of the value (php's default is an unmodified
    // string passthrough).
    return (string)$value;
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
 * `extension_loaded($name)` — the runtime answer for a name only known at run
 * time; the compile-time fold in LowerFromAst::foldGuard covers the literal
 * case (and must agree with this list). A whole-program binary carries a fixed
 * set: nothing can be loaded later.
 */
function extension_loaded(string $extension): bool
{
    $e = \strtolower($extension);

    // ⚠ MUST agree with LowerFromAst::extensionIsBuiltIn(), which folds the
    // GUARD form `if (extension_loaded('x'))` at compile time. This function
    // answers the EXPRESSION form. Two lists, one truth — change both.
    return $e === 'pcre' || $e === 'json' || $e === 'ctype'
        || $e === 'openssl' || $e === 'core' || $e === 'standard'
        || $e === 'tokenizer';
}


/**
 * Resident memory, in bytes.
 *
 * php reports its own emalloc arena; this binary allocates from the system
 * allocator and has no separate arena to report, so BOTH the `$real_usage`
 * forms answer the process's peak resident size via getrusage(2). It is the
 * honest number available here, and it is what the only real callers
 * (progress-bar "memory used" displays) want. Named as a divergence rather than
 * faked with a counter that would drift.
 */
function __mc_rss_bytes(): int
{
    $buf = \Runtime\Libc\calloc(1, 256);
    // struct rusage opens with two struct timeval (16 bytes each on Darwin and
    // glibc/x86_64 alike), so ru_maxrss is at offset 32 on both.
    $rc = \Runtime\Libc\sys_getrusage(0, $buf);
    $maxrss = $rc === 0 ? \peek_i64($buf, 32) : 0;
    \Runtime\Libc\free($buf);
    // Darwin counts bytes, Linux kilobytes.
    return \__mc_host_is_darwin() ? $maxrss : $maxrss * 1024;
}

function memory_get_usage(bool $real_usage = false): int
{
    return \__mc_rss_bytes();
}

function memory_get_peak_usage(bool $real_usage = false): int
{
    return \__mc_rss_bytes();
}

function memory_reset_peak_usage(): void
{
}

/**
 * php's spl_object_hash: 32 lowercase hex digits, unique among live objects.
 * Built from spl_object_id (a compiler builtin) so the two agree, which is the
 * property every caller actually relies on — symfony/console uses it purely as
 * a map key.
 */
function spl_object_hash(object $object): string
{
    $id = \spl_object_id($object);
    $hex = \dechex($id);
    return \str_pad($hex, 32, "0", STR_PAD_LEFT);
}
// The runtime walk used to live here as `__mc_var_export_cell`. It moved to
// prelude/var_export.php, because the stdlib is a prebuilt `.o` and cannot be
// handed an OBJECT — so an object nested inside an array had nowhere to go.
// Only the escaper stays: it takes a string and returns a string, which crosses
// the boundary fine, and both the prelude walk and the codegen builtin call it.

/**
 * `class_alias()` — the PHP body the BOOTSTRAP RULE asks for.
 *
 * The compiler that builds the next compiler has never heard of the codegen
 * builtin, and an unresolved call is a silent runtime trap rather than a link
 * error — so the name needs a body here first. This one cannot do the job (a
 * name→metadata registry is a runtime the emitter owns), and it says so by
 * answering false; the generation that has the builtin shadows it, because
 * `emitCall` asks `emitBuiltin` before `definedFns`.
 */
function class_alias(string $class, string $alias, bool $autoload = true): bool
{
    return false;
}

// The type predicates are codegen builtins (inlined at every direct call); these
// bodies are their NAMED form — the BOOTSTRAP RULE's PHP twin — so a call by a
// runtime name reaches them: symfony OptionsResolver validates through
// `self::VALIDATION_FUNCTIONS[$type]($value)`, which found no `is_string` to
// dispatch to and rejected every `string[]` option. Inside each body the call
// is the builtin again (emitBuiltin is asked before any defined function).

function is_array(mixed $value): bool
{
    return \is_array($value);
}

function is_bool(mixed $value): bool
{
    return \is_bool($value);
}

function is_callable(mixed $value, bool $syntax_only = false, ?string &$callable_name = null): bool
{
    return \is_callable($value);
}

function is_float(mixed $value): bool
{
    return \is_float($value);
}

function is_double(mixed $value): bool
{
    return \is_float($value);
}

function is_int(mixed $value): bool
{
    return \is_int($value);
}

function is_integer(mixed $value): bool
{
    return \is_int($value);
}

function is_long(mixed $value): bool
{
    return \is_int($value);
}

function is_null(mixed $value): bool
{
    return \is_null($value);
}

function is_numeric(mixed $value): bool
{
    return \is_numeric($value);
}

function is_object(mixed $value): bool
{
    return \is_object($value);
}

function is_string(mixed $value): bool
{
    return \is_string($value);
}
