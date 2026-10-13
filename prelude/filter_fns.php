<?php
// ext/filter entry points. In the prelude (not the stdlib) because they need what a stdlib.o cannot
// reach: user callables for FILTER_CALLBACK and the request superglobals. The validators and
// sanitizers live in src/Runtime/Stdlib/Filter.php.
// DEMAND-GATED (Main.php): only a program that calls one of these carries it.

/**
 * A function NAME given as FILTER_CALLBACK. A runtime string cannot name a builtin
 * (only a user function or a literal lowered at its call site is dispatchable), so the
 * builtins people actually pass here are mapped to closures; anything else is called by name.
 */
function __mc_filter_builtin_cb(string $name): mixed
{
    $n = \ltrim(\strtolower($name), '\\');
    if ($n === 'trim') { return fn($x) => \trim($x); }
    if ($n === 'ltrim') { return fn($x) => \ltrim($x); }
    if ($n === 'rtrim') { return fn($x) => \rtrim($x); }
    if ($n === 'strtolower') { return fn($x) => \strtolower($x); }
    if ($n === 'strtoupper') { return fn($x) => \strtoupper($x); }
    if ($n === 'ucfirst') { return fn($x) => \ucfirst($x); }
    if ($n === 'ucwords') { return fn($x) => \ucwords($x); }
    if ($n === 'strrev') { return fn($x) => \strrev($x); }
    if ($n === 'strlen') { return fn($x) => \strlen($x); }
    if ($n === 'intval') { return fn($x) => \intval($x); }
    if ($n === 'floatval') { return fn($x) => \floatval($x); }
    if ($n === 'htmlspecialchars') { return fn($x) => \htmlspecialchars($x); }
    if ($n === 'htmlentities') { return fn($x) => \htmlentities($x); }
    if ($n === 'addslashes') { return fn($x) => \addslashes($x); }
    if ($n === 'stripslashes') { return fn($x) => \stripslashes($x); }
    if ($n === 'strip_tags') { return fn($x) => \strip_tags($x); }
    if ($n === 'nl2br') { return fn($x) => \nl2br($x); }
    if ($n === 'urlencode') { return fn($x) => \urlencode($x); }
    if ($n === 'rawurlencode') { return fn($x) => \rawurlencode($x); }
    if ($n === 'base64_encode') { return fn($x) => \base64_encode($x); }
    if ($n === 'md5') { return fn($x) => \md5($x); }
    return $name;
}

/** A Stringable becomes its string here: the stdlib cannot see a user class's __toString. */
function __mc_filter_unwrap(mixed $v): mixed
{
    return $v instanceof \Stringable ? (string)$v : $v;
}

function __mc_filter_callback(string $fn, mixed $cb, mixed $value, int $flags): mixed
{
    if (\is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) { $out[$k] = __mc_filter_callback($fn, $cb, $v, $flags); }
        return $out;
    }
    if (\is_object($value) && !($value instanceof \Stringable)) { return ($flags & 134217728) !== 0 ? null : false; }
    if ($cb === null) { throw new \TypeError($fn . '(): Option must be a valid callback'); }
    if (\is_string($cb)) { $cb = __mc_filter_builtin_cb($cb); }
    return \call_user_func($cb, (string)$value);
}

function __mc_filter_recursive(string $fn, mixed $value, int $filter, int $flags, mixed $opts, mixed $cb): mixed
{
    if (\is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) { $out[$k] = __mc_filter_recursive($fn, $v, $filter, $flags, $opts, $cb); }
        return $out;
    }
    if ($filter === 1024) { return __mc_filter_callback($fn, $cb, $value, $flags); }
    return __mc_filter_scalar($fn, __mc_filter_unwrap($value), $filter, $flags, $opts);
}

/**
 * php_filter_call: $args is the user's `$options` (int flags or array with
 * filter/flags/options), $defFlags the flags when none are given.
 *
 * @param int $argNo position of $options in the public signature (messages)
 */
function __mc_filter_call(string $fn, int $argNo, mixed $value, int $filter, mixed $args, int $defFlags): mixed
{
    $flags = $defFlags;
    $opts = null;
    if (\is_array($args)) {
        $flags = \array_key_exists('flags', $args) ? (int)$args['flags'] : 0;
        if (\array_key_exists('options', $args)) { $opts = $args['options']; }
    } elseif (\is_int($args) && $args !== 0) {
        $flags = $args;
    }
    if (($flags & 134217728) !== 0 && ($flags & 268435456) !== 0) {
        throw new \ValueError($fn . '(): Argument #' . $argNo . ' ($options) cannot use both FILTER_NULL_ON_FAILURE and FILTER_THROW_ON_FAILURE');
    }
    if (($flags & 16777216) === 0 && ($flags & 67108864) === 0) { $flags |= 33554432; }
    if (!__mc_filter_known($filter) && $filter !== 1024) {
        throw new \ValueError($fn . '(): Unknown filter with ID ' . $filter);
    }
    $cb = $opts;
    if (\is_array($value)) {
        if ($filter === 1024 && $opts === null) { return ($flags & 134217728) !== 0 ? null : false; }
        if (($flags & 33554432) !== 0 && $filter !== 1024) {
            return __mc_filter_failed($flags, $opts, 'not a scalar value (got an array)', false);
        }
        return __mc_filter_recursive($fn, $value, $filter, $flags, $opts, $cb);
    }
    if (($flags & 16777216) !== 0) {
        return __mc_filter_failed($flags, $opts, 'not an array (got ' . (\is_bool($value) ? ($value ? 'true' : 'false') : \get_debug_type($value)) . ')', false);
    }
    if ($filter === 1024) {
        $r = __mc_filter_callback($fn, $cb, $value, $flags);
    } else {
        $r = __mc_filter_scalar($fn, __mc_filter_unwrap($value), $filter, $flags, $opts);
    }
    if (($flags & 67108864) !== 0) { return [$r]; }
    return $r;
}

function filter_var(mixed $value, int $filter = 516, array|int $options = 0): mixed
{
    return __mc_filter_call('filter_var', 3, $value, $filter, $options, 0);
}

function __mc_filter_array_handler(string $fn, array $input, mixed $op, bool $addEmpty): mixed
{
    if (\is_int($op)) {
        return __mc_filter_call($fn, 2, $input, $op, 0, 16777216);
    }
    $out = [];
    foreach ($op as $k => $arg) {
        if (!\is_string($k)) { throw new \TypeError($fn . '(): Argument #2 ($options) must contain only string keys'); }
        if ($k === '') { throw new \ValueError($fn . '(): Argument #2 ($options) cannot contain empty keys'); }
        if (!\array_key_exists($k, $input)) {
            if ($addEmpty) { $out[$k] = null; }
            continue;
        }
        if (\is_array($arg)) {
            $f = \array_key_exists('filter', $arg) ? (int)$arg['filter'] : 516;
            $out[$k] = __mc_filter_call($fn, 2, $input[$k], $f, $arg, 0);
        } else {
            $out[$k] = __mc_filter_call($fn, 2, $input[$k], (int)$arg, 0, 0);
        }
    }
    return $out;
}

function filter_var_array(array $array, array|int $options = 516, bool $add_empty = true): array|false|null
{
    return __mc_filter_array_handler('filter_var_array', $array, $options, $add_empty);
}

/** @return array<string,mixed> */
function __mc_filter_source(string $fn, int $type, ?string $only = null): array
{
    /** @var array<string,mixed> $r */
    $r = [];
    if ($type === 0) { $r = $_POST; }
    elseif ($type === 1) { $r = $_GET; }
    elseif ($type === 2) { $r = $_COOKIE; }
    elseif ($type === 4) {
        // the no-arg getenv() is a startup snapshot; a named lookup sees putenv()
        if ($only !== null) {
            $v = \getenv($only);
            if ($v !== false) { $r[$only] = $v; }
        } else {
            foreach (\getenv() as $k => $v) { $r[(string)$k] = $v; }
        }
    }
    elseif ($type === 5) { $r = $_SERVER; }
    else { throw new \ValueError($fn . '(): Argument #1 ($type) must be an INPUT_* constant'); }
    return $r;
}

function filter_has_var(int $input_type, string $var_name): bool
{
    $src = __mc_filter_source('filter_has_var', $input_type, $var_name);
    return \array_key_exists($var_name, $src);
}

function filter_input(int $type, string $var_name, int $filter = 516, array|int $options = 0): mixed
{
    $src = __mc_filter_source('filter_input', $type, $var_name);
    if (!\array_key_exists($var_name, $src)) {
        $flags = \is_int($options) ? $options : (int)($options['flags'] ?? 0);
        if (\is_array($options) && \is_array($options['options'] ?? null) && \array_key_exists('default', $options['options'])) {
            return $options['options']['default'];
        }
        return ($flags & 134217728) !== 0 ? false : null;
    }
    return __mc_filter_call('filter_input', 4, $src[$var_name], $filter, $options, 0);
}

function filter_input_array(int $type, array|int $options = 516, bool $add_empty = true): array|false|null
{
    $src = __mc_filter_source('filter_input_array', $type);
    if (\count($src) === 0) {
        $flags = 0;
        if (\is_int($options)) { $flags = $options; }
        elseif (\array_key_exists('flags', $options)) { $flags = (int)$options['flags']; }
        return ($flags & 134217728) !== 0 ? false : null;
    }
    return __mc_filter_array_handler('filter_input_array', $src, $options, $add_empty);
}
