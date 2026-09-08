<?php

// The SAPI-shaped surface a CLI binary can answer honestly.
//
// Each of these exists in php for a request lifecycle a compiled binary does not
// have. php's own CLI answers are the right ones — a trap here would be worse
// than the truth, because the caller is almost always a framework asking "am I
// in a web request?" and acting on the answer.

/** No client to abort in a CLI process: php answers 0. */
function connection_aborted(): int
{
    return 0;
}

/** php returns the PREVIOUS setting; CLI has it off, so 0 either way. */
function ignore_user_abort(?bool $enable = null): int
{
    return 0;
}

/** php's CLI has no time limit; setting one succeeds and changes nothing. */
function set_time_limit(int $seconds): bool
{
    return true;
}

/** Nothing was uploaded: there is no request. php answers false. */
function is_uploaded_file(string $filename): bool
{
    return false;
}

/** Same reason — refuse rather than move an arbitrary file. */
function move_uploaded_file(string $from, string $to): bool
{
    return false;
}

/**
 * `uniqid()` — the microsecond clock in hex, php's exact widths: 13 characters
 * (8 for the seconds, 5 for the microseconds), or 23 with `$more_entropy`, whose
 * tail php formats as a 10-character combined LCG value.
 */
function uniqid(string $prefix = '', bool $more_entropy = false): string
{
    $now = \microtime(true);
    $sec = (int)$now;
    $usec = (int)(($now - (float)$sec) * 1000000.0);
    if ($usec > 999999) { $usec = 999999; }
    $out = $prefix . \str_pad(\dechex($sec), 8, '0', \STR_PAD_LEFT)
         . \str_pad(\dechex($usec), 5, '0', \STR_PAD_LEFT);
    if (!$more_entropy) { return $out; }
    // php appends `%.8F` of its combined-LCG value, which lands in [0, 10) —
    // ten characters including the dot, so the whole string is 23. The VALUE is
    // random in php too; only the SHAPE is observable.
    $frac = (float)\random_int(0, 999999999) / 100000000.0;

    return $out . \sprintf('%.8F', $frac);
}

/**
 * `opcache_compile_file()` — there is no opcode cache in an AOT binary, and php
 * answers exactly this way when OPcache is disabled: false, having compiled
 * nothing. A trap here would be worse than the truth, because the caller is a
 * warm-up script asking "can I precompile?" and false is the honest answer.
 */
function opcache_compile_file(string $filename): bool
{
    return false;
}

/** Same: nothing is cached, so nothing can be invalidated. */
function opcache_invalidate(string $filename, bool $force = false): bool
{
    return false;
}

/**
 * `dba_list()` — the open dba handles. There is no dba layer in a compiled
 * binary, so nothing can be open and php's own answer for that state is the
 * empty array. A caller iterating it does the right thing; a caller that would
 * have hit a trap here was only asking what is open.
 *
 * @return array<int,string>
 */
function dba_list(): array
{
    return [];
}
