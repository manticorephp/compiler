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
 * var_export string quoting: php.net escapes exactly two bytes inside the
 * single quotes — the backslash and the quote itself. Backslash first, or the
 * one introduced by the quote escape would be doubled again. A NUL breaks out
 * of the quotes as `' . "\0" . '`, as php writes it.
 */
function __mc_var_export_qstr(string $s): string
{
    return \str_replace(['\\', "'", "\0"], ['\\\\', "\\'", "' . \"\\0\" . '"], $s);
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
        || $e === 'tokenizer' || $e === 'mbstring' || $e === 'intl'
        || $e === 'session';
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

/** A php variable name: `[A-Za-z_\x7f-\xff][A-Za-z0-9_\x7f-\xff]*`. */
function __mc_extract_valid_name(string $n): bool
{
    $len = \strlen($n);
    if ($len === 0) { return false; }
    for ($i = 0; $i < $len; $i = $i + 1) {
        $c = \ord($n[$i]);
        if ($c === 95 || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122) || $c >= 127) { continue; }
        if ($i > 0 && $c >= 48 && $c <= 57) { continue; }
        return false;
    }
    return true;
}

/**
 * The assignments `extract($arr, $flags, $prefix)` performs, as name => value,
 * plus the count php returns under the key '' (never a valid variable name).
 * `$exists` says which candidate locals are defined in the caller's scope; the
 * caller (a compiler expansion) performs the stores, since locals are slots.
 * @param array<string, bool> $exists
 * @return array<string, mixed>
 */
function __mc_extract_plan(array $arr, int $flags, string $prefix, bool $hasPrefix, array $exists, bool $global): array
{
    $refs = ($flags & 256) !== 0;
    $type = $flags & 255;
    if ($flags < 0 || $type > 6) {
        throw new \ValueError('extract(): Argument #2 ($flags) must be a valid extract type');
    }
    if ($type > 1 && $type < 6 && !$hasPrefix) {
        throw new \ValueError('extract(): Argument #3 ($prefix) is required when using this extract type');
    }
    if ($prefix !== '' && !__mc_extract_valid_name($prefix)) {
        throw new \ValueError('extract(): Argument #3 ($prefix) must be a valid identifier');
    }
    if ($refs) {
        throw new \Error('extract(): EXTR_REFS is not supported (variables cannot alias array elements)');
    }
    $out = [];
    $count = 0;
    foreach ($arr as $k => $v) {
        $isStr = \is_string($k);
        $key = (string)$k;
        $has = $isStr && isset($exists[$key]) && $exists[$key];
        $name = '';
        if ($type === 0 || $type === 1 || $type === 6) {
            if (!$isStr || !__mc_extract_valid_name($key)) { continue; }
            if ($type === 1 && ($has || $key === 'this')) { continue; }
            if ($type === 6 && !$has) { continue; }
            if ($global && $key === 'GLOBALS') { continue; }
            $name = $key;
        } elseif ($type === 3) {
            if ($isStr && $key === '') { continue; }
            $name = $prefix . '_' . $key;
        } elseif ($type === 4) {
            $name = ($isStr && $key !== 'this' && __mc_extract_valid_name($key)) ? $key : $prefix . '_' . $key;
        } elseif ($type === 5) {
            if (!$has) { continue; }
            $name = $prefix . '_' . $key;
        } else {
            if (!$isStr || $key === '') { continue; }
            if ($has || $key === 'this') {
                $name = $prefix . '_' . $key;
            } else {
                if (!__mc_extract_valid_name($key)) { continue; }
                $name = $key;
            }
        }
        if (!__mc_extract_valid_name($name)) { continue; }
        if ($name === 'this') { throw new \Error('Cannot re-assign $this'); }
        $out[$name] = $v;
        $exists[$name] = true;
        $count = $count + 1;
    }
    $out[''] = $count;
    return $out;
}

/** The count `extract()` returns, out of an {@see __mc_extract_plan}. @param array<string, mixed> $plan */
function __mc_extract_count(array $plan): int
{
    return (int)$plan[''];
}
