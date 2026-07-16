<?php

/**
 * Pure-string path functions from php.net's filesystem section. Unlike the
 * rest of Io.php these touch neither libc nor the disk — php.net is explicit
 * that they operate on the string alone and do not check for existence.
 *
 * Global namespace so user code calling `basename($p)` resolves here (the
 * compiler's inline-builtin table does not handle these, so they fall through
 * to user-function resolution).
 *
 * POSIX separator only: PHP treats `\` as a separator on Windows builds, and
 * Manticore does not target Windows.
 */

/**
 * Trailing component of a path. $suffix is removed only when it is a *proper*
 * suffix — php.net keeps the basename intact when it equals $suffix exactly.
 */
function basename(string $path, string $suffix = ""): string
{
    $end = \strlen($path);
    while ($end > 0 && $path[$end - 1] === '/') {
        $end--;
    }
    if ($end === 0) {
        return "";
    }
    $start = $end;
    while ($start > 0 && $path[$start - 1] !== '/') {
        $start--;
    }
    $base = \substr($path, $start, $end - $start);
    if ($suffix !== "" && $base !== $suffix && \str_ends_with($base, $suffix)) {
        $base = \substr($base, 0, \strlen($base) - \strlen($suffix));
    }
    return $base;
}

/**
 * One level of dirname(). Split out so dirname() can iterate $levels times.
 */
function __mc_dirname1(string $path): string
{
    if (\strlen($path) === 0) {
        return "";
    }
    $end = \strlen($path);
    while ($end > 0 && $path[$end - 1] === '/') {
        $end--;
    }
    if ($end === 0) {
        return "/";
    }
    $p = $end;
    while ($p > 0 && $path[$p - 1] !== '/') {
        $p--;
    }
    if ($p === 0) {
        return ".";
    }
    $d = $p - 1;
    while ($d > 0 && $path[$d - 1] === '/') {
        $d--;
    }
    if ($d === 0) {
        return "/";
    }
    return \substr($path, 0, $d);
}

/**
 * Parent directory of a path, $levels up.
 */
function dirname(string $path, int $levels = 1): string
{
    if ($levels < 1) {
        throw new \ValueError('dirname(): Argument #2 ($levels) must be greater than or equal to 1');
    }
    $r = $path;
    for ($i = 0; $i < $levels; $i++) {
        $r = __mc_dirname1($r);
    }
    return $r;
}

/**
 * Path components. With PATHINFO_ALL (the default) an array; with any other
 * mask a single string — php.net returns the first element present in
 * dirname/basename/extension/filename order, not the whole subset.
 * @return array<string,string>|string
 */
function pathinfo(string $path, int $flags = 15)
{
    $dir = dirname($path);
    $base = basename($path);
    $ext = null;
    $fname = $base;
    // strrpos, not strpos: '.hidden' has extension 'hidden' and filename ''.
    // Compare !== false — a leading dot yields offset 0, which is falsy.
    $dot = \strrpos($base, '.');
    if ($dot !== false) {
        $ext = \substr($base, $dot + 1);
        $fname = \substr($base, 0, $dot);
    }
    if ($flags !== 15) {
        if (($flags & 1) !== 0 && $dir !== "") {
            return $dir;
        }
        if (($flags & 2) !== 0) {
            return $base;
        }
        if (($flags & 4) !== 0) {
            return $ext ?? "";
        }
        if (($flags & 8) !== 0) {
            return $fname;
        }
        return "";
    }
    $out = [];
    // php.net omits 'dirname' entirely when it is empty (i.e. $path === "").
    if ($dir !== "") {
        $out['dirname'] = $dir;
    }
    $out['basename'] = $base;
    if ($ext !== null) {
        $out['extension'] = $ext;
    }
    $out['filename'] = $fname;
    return $out;
}
