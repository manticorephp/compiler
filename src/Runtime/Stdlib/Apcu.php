<?php

// APCu — the user cache, as a compiled binary can honestly provide it.
//
// php's APCu is shared memory across a pool of SAPI workers. A compiled binary
// is ONE process, so the faithful shape here is a process-local map: everything
// a single-process program can observe (store/fetch/add/exists/delete/clear, and
// TTL expiry) behaves exactly as php's does; what cannot exist is another worker
// seeing the entry, and no single-process program can tell the difference.
//
// The store is a `static` local rather than a class: a static local is a module
// global here, which is precisely the lifetime APCu wants.

/**
 * The backing map, `key => [value, expiresAt]`. `$op` selects the operation so
 * the whole cache is one static slot — php's own API is a set of free functions
 * over one shared table, and this mirrors that rather than inventing a class.
 *
 * @param mixed $value
 * @return mixed
 */
function __mc_apcu_op(string $op, string $key, mixed $value = null, int $ttl = 0): mixed
{
    static $store = [];
    static $exp = [];
    if ($op === 'clear') {
        $store = [];
        $exp = [];
        return true;
    }
    // Expiry is checked on READ, as php's is: a TTL that passed makes the entry
    // absent without anything having to sweep it.
    if (isset($exp[$key]) && $exp[$key] !== 0 && $exp[$key] <= \time()) {
        unset($store[$key]);
        unset($exp[$key]);
    }
    if ($op === 'exists') { return isset($store[$key]); }
    if ($op === 'fetch') { return $store[$key] ?? null; }
    if ($op === 'delete') {
        if (!isset($store[$key])) { return false; }
        unset($store[$key]);
        unset($exp[$key]);
        return true;
    }
    if ($op === 'add' && isset($store[$key])) { return false; }
    $store[$key] = $value;
    $exp[$key] = $ttl > 0 ? \time() + $ttl : 0;
    return true;
}

/** Store a value, overwriting any existing one. */
function apcu_store(string $key, mixed $var, int $ttl = 0): bool
{
    return (bool)__mc_apcu_op('store', $key, $var, $ttl);
}

/** Store only if the key is not already present. */
function apcu_add(string $key, mixed $var, int $ttl = 0): bool
{
    return (bool)__mc_apcu_op('add', $key, $var, $ttl);
}

/**
 * php's `apcu_fetch` reports absence through `$success`, and returns false.
 * The by-ref out-parameter is why this is not simply the map read.
 */
function apcu_fetch(string $key, ?bool &$success = null): mixed
{
    $hit = (bool)__mc_apcu_op('exists', $key);
    $success = $hit;
    if (!$hit) { return false; }

    return __mc_apcu_op('fetch', $key);
}

function apcu_exists(string $key): bool
{
    return (bool)__mc_apcu_op('exists', $key);
}

function apcu_delete(string $key): bool
{
    return (bool)__mc_apcu_op('delete', $key);
}

function apcu_clear_cache(): bool
{
    return (bool)__mc_apcu_op('clear', '');
}
