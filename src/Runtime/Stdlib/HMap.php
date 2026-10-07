<?php
// PHP twins of the __mc_hmap_* builtins (insertion-ordered hash table).
// Natively each name is a builtin and these bodies are shadowed.

function __mc_hmap_ekey(mixed $k): string
{
    if (\is_int($k)) { return 'i' . $k; }
    if (\is_string($k)) { return 's' . $k; }
    return 'o' . \spl_object_id($k);
}

/** @return array<string, mixed> */
function &__mc_hmap_tab(int $h, bool $drop = false): array
{
    static $t = [];
    if ($drop) { unset($t[$h]); $none = []; return $none; }
    if (!isset($t[$h])) { $t[$h] = []; }
    return $t[$h];
}

function __mc_hmap_alloc(int $isSet): int
{
    static $next = 0;
    $next++;
    $t = &__mc_hmap_tab($next);
    $t = ['k' => [], 'v' => [], 'alive' => [], 'ix' => [], 'len' => 0, 'epoch' => 0, 'set' => $isSet];
    return $next;
}

function __mc_hmap_free(int $h): void { __mc_hmap_tab($h, true); }
function __mc_hmap_len(int $h): int { return __mc_hmap_tab($h)['len']; }
function __mc_hmap_epoch(int $h): int { return __mc_hmap_tab($h)['epoch']; }

function __mc_hmap_find(int $h, mixed $key): int
{
    $t = &__mc_hmap_tab($h);
    return $t['ix'][__mc_hmap_ekey($key)] ?? -1;
}

function __mc_hmap_key(int $h, int $e): mixed { return __mc_hmap_tab($h)['k'][$e]; }
function __mc_hmap_val(int $h, int $e): mixed { $t = &__mc_hmap_tab($h); return $t['set'] !== 0 ? null : $t['v'][$e]; }

function __mc_hmap_put(int $h, mixed $key, mixed $val): int
{
    $t = &__mc_hmap_tab($h);
    $ek = __mc_hmap_ekey($key);
    if (isset($t['ix'][$ek])) { if ($t['set'] === 0) { $t['v'][$t['ix'][$ek]] = $val; } return 0; }
    $used = \count($t['k']);
    if ($used >= 8 && ($used - $t['len']) * 2 > $t['len']) { __mc_hmap_compact($t); $used = \count($t['k']); }
    $t['k'][] = $key; $t['v'][] = $t['set'] !== 0 ? null : $val; $t['alive'][] = true;
    $t['ix'][$ek] = $used; $t['len']++;
    return 1;
}

/** @param array<string, mixed> $t */
function __mc_hmap_compact(array &$t): void
{
    $k = []; $v = []; $ix = [];
    foreach ($t['alive'] as $e => $a) {
        if ($a) { $ix[__mc_hmap_ekey($t['k'][$e])] = \count($k); $k[] = $t['k'][$e]; $v[] = $t['v'][$e]; }
    }
    $t['k'] = $k; $t['v'] = $v; $t['ix'] = $ix;
    $t['alive'] = \array_fill(0, \count($k), true);
    $t['epoch']++;
}

function __mc_hmap_del(int $h, mixed $key): int
{
    $t = &__mc_hmap_tab($h);
    $ek = __mc_hmap_ekey($key);
    if (!isset($t['ix'][$ek])) { return 0; }
    $e = $t['ix'][$ek];
    unset($t['ix'][$ek]);
    $old = $t['v'][$e];
    $t['alive'][$e] = false; $t['k'][$e] = null; $t['v'][$e] = null; $t['len']--;
    $old = null;
    return 1;
}

function __mc_hmap_next(int $h, int $e): int
{
    $t = &__mc_hmap_tab($h);
    for ($n = \count($t['alive']); $e < $n; $e++) { if ($t['alive'][$e]) { return $e; } }
    return -1;
}

function __mc_hmap_clear(int $h): void
{
    $t = &__mc_hmap_tab($h);
    $old = $t['v'];
    $t['k'] = []; $t['v'] = []; $t['alive'] = []; $t['ix'] = []; $t['len'] = 0; $t['epoch']++;
    $old = null;
}

function __mc_hmap_clone(int $h): int
{
    $src = __mc_hmap_tab($h);
    $n = __mc_hmap_alloc($src['set']);
    $t = &__mc_hmap_tab($n);
    $t = $src;
    return $n;
}
