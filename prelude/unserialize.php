<?php

/**
 * `unserialize()` — the reader for php's native serialization format.
 *
 * PRELUDE, and gated SEPARATELY from `serialize.php`: the object arm is
 * `__mc_unser_alloc` / `__mc_unser_fill`, generated per class table, and a
 * program that only serializes must not pay for the rebuild arms.
 *
 * SLOT NUMBERING mirrors the writer exactly ({@see prelude/serialize.php}):
 * the counter advances for EVERY value, back-references included, and array
 * keys do not count. `r:<k>` resolves against that numbering, so the two must
 * agree or a round trip of a shared object lands on the wrong value.
 *
 * `R:` is php's REFERENCE marker. Manticore arrays carry no is_ref bit, so it
 * is accepted on input and treated as `r:` — a value copy, not an alias.
 *
 * A malformed input sets `$st->ok = false` and unwinds to `false`, which is
 * what php returns (it also warns; we do not). Trailing bytes after a complete
 * value are ignored, as in php.
 */

final class __McUnSt
{
    public string $s = '';
    public int $p = 0;
    public bool $ok = true;
    public int $n = 0;
    /** @var array<int,mixed> value slot -> the value it held */
    public array $vars = [];
    public bool $allowAll = true;
    /** @var array<string,bool> */
    public array $allowed = [];
}

function unserialize(string $data, array $options = []): mixed
{
    $st = new __McUnSt();
    $st->s = $data;
    $v = __mc_unser_val($st);
    if (!$st->ok) { return false; }
    return $v;
}

/** The bytes from the cursor up to the next `$stop`, cursor left just past it.
 *  Fails the parse when `$stop` is absent. */
function __mc_un_upto(\__McUnSt $st, string $stop): string
{
    if (!$st->ok) { return ''; }
    $q = strpos($st->s, $stop, $st->p);
    if ($q === false) { $st->ok = false; return ''; }
    $raw = substr($st->s, $st->p, $q - $st->p);
    $st->p = $q + 1;
    return $raw;
}

/** Consume `$lit` at the cursor, or fail the parse. */
function __mc_un_lit(\__McUnSt $st, string $lit): void
{
    if (!$st->ok) { return; }
    $n = strlen($lit);
    if (substr($st->s, $st->p, $n) !== $lit) { $st->ok = false; return; }
    $st->p = $st->p + $n;
}

/** `s:<len>:"<bytes>";` — LEN is a BYTE count, so the body is taken by length,
 *  never scanned for the closing quote (a serialized string may contain `";`). */
function __mc_un_str(\__McUnSt $st): string
{
    $len = (int)__mc_un_upto($st, ':');
    if (!$st->ok || $len < 0) { $st->ok = false; return ''; }
    __mc_un_lit($st, '"');
    if (!$st->ok) { return ''; }
    if ($st->p + $len > strlen($st->s)) { $st->ok = false; return ''; }
    $body = substr($st->s, $st->p, $len);
    $st->p = $st->p + $len;
    __mc_un_lit($st, '";');
    return $body;
}

/** An array KEY: `i:<n>;` or `s:<len>:"…";`. Keys are not values, so no slot. */
function __mc_un_key(\__McUnSt $st): mixed
{
    if (!$st->ok) { return 0; }
    $t = substr($st->s, $st->p, 2);
    if ($t === 'i:') {
        $st->p = $st->p + 2;
        return (int)__mc_un_upto($st, ';');
    }
    if ($t === 's:') {
        $st->p = $st->p + 2;
        return __mc_un_str($st);
    }
    $st->ok = false;
    return 0;
}

function __mc_unser_val(\__McUnSt $st): mixed
{
    if (!$st->ok) { return null; }
    // Count FIRST, like php-src's php_add_var_hash: the slot belongs to this
    // value whatever it turns out to be, and a back-reference consumes one too.
    $slot = $st->n + 1;
    $st->n = $slot;
    $t = substr($st->s, $st->p, 2);
    if ($t === 'N;') {
        $st->p = $st->p + 2;
        return null;
    }
    if ($t === 'b:') {
        $st->p = $st->p + 2;
        $b = __mc_un_upto($st, ';') === '1';
        $st->vars[$slot] = $b;
        return $b;
    }
    if ($t === 'i:') {
        $st->p = $st->p + 2;
        $i = (int)__mc_un_upto($st, ';');
        $st->vars[$slot] = $i;
        return $i;
    }
    if ($t === 'd:') {
        $st->p = $st->p + 2;
        $raw = __mc_un_upto($st, ';');
        // A cast reads these as 0.0 — php spells them out in the stream.
        if ($raw === 'INF') { $d = INF; }
        else if ($raw === '-INF') { $d = -INF; }
        else if ($raw === 'NAN') { $d = NAN; }
        else { $d = (float)$raw; }
        $st->vars[$slot] = $d;
        return $d;
    }
    if ($t === 's:') {
        $st->p = $st->p + 2;
        $s = __mc_un_str($st);
        $st->vars[$slot] = $s;
        return $s;
    }
    if ($t === 'a:') {
        $st->p = $st->p + 2;
        $cnt = (int)__mc_un_upto($st, ':');
        __mc_un_lit($st, '{');
        $arr = [];
        $i = 0;
        while ($i < $cnt) {
            if (!$st->ok) { return null; }
            $k = __mc_un_key($st);
            $val = __mc_unser_val($st);
            if (!$st->ok) { return null; }
            $arr[$k] = $val;
            $i = $i + 1;
        }
        __mc_un_lit($st, '}');
        if (!$st->ok) { return null; }
        // Recorded only AFTER it is complete: an array is a value in php and is
        // never the target of a well-formed `r:`, so nothing observes the gap.
        $st->vars[$slot] = $arr;
        return $arr;
    }
    if ($t === 'r:' || $t === 'R:') {
        $st->p = $st->p + 2;
        $k = (int)__mc_un_upto($st, ';');
        if (!$st->ok) { return null; }
        if (!array_key_exists($k, $st->vars)) { $st->ok = false; return null; }
        return $st->vars[$k];
    }
    $st->ok = false;
    return null;
}
