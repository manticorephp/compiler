<?php

/**
 * php.net filesystem functions that need only scalar libc calls — no struct
 * marshalling (the stat/dirent family lives elsewhere and needs peek_*).
 *
 * Global namespace so user code calling `mkdir($p)` resolves here (the
 * compiler's inline-builtin table does not handle these, so they fall through
 * to user-function resolution).
 *
 * A libc buffer from calloc has no rc header: cut it to a real string with
 * str_from_buffer (known length) or cstr_to_str (NUL-terminated), then free.
 */

/**
 * Create a directory. With $recursive, missing parents are created too and an
 * already-existing *parent* is fine — but an existing leaf still fails, as
 * php.net specifies.
 */
function mkdir(string $directory, int $permissions = 0777, bool $recursive = false): bool
{
    if (!$recursive) {
        return \Runtime\Libc\sys_mkdir($directory, $permissions) === 0;
    }
    if ($directory === '') {
        return false;
    }
    $cur = $directory[0] === '/' ? '' : '.';
    $parts = \explode('/', $directory);
    $n = \count($parts);
    $made = false;
    for ($i = 0; $i < $n; $i = $i + 1) {
        $seg = $parts[$i];
        if ($seg === '') {
            continue;
        }
        $cur = $cur . '/' . $seg;
        if (\file_exists($cur)) {
            $made = false;
            continue;
        }
        if (\Runtime\Libc\sys_mkdir($cur, $permissions) !== 0) {
            return false;
        }
        $made = true;
    }
    // php.net: true only when the leaf itself was created.
    return $made;
}

/** Remove an empty directory. */
function rmdir(string $directory): bool
{
    return \Runtime\Libc\sys_rmdir($directory) === 0;
}

/** Rename (move) a file or directory. */
function rename(string $from, string $to): bool
{
    return \Runtime\Libc\sys_rename($from, $to) === 0;
}

/** Copy a file, streaming in 64 KiB chunks rather than loading it whole. */
function copy(string $from, string $to): bool
{
    $in = \Runtime\Libc\fopen($from, 'rb');
    if ($in === null) {
        return false;
    }
    $out = \Runtime\Libc\fopen($to, 'wb');
    if ($out === null) {
        \Runtime\Libc\fclose($in);
        return false;
    }
    $cap = 65536;
    $buf = \Runtime\Libc\calloc($cap, 1);
    if ($buf === null) {
        \Runtime\Libc\fclose($in);
        \Runtime\Libc\fclose($out);
        return false;
    }
    $ok = true;
    while (true) {
        $n = \Runtime\Libc\fread($buf, 1, $cap, $in);
        if ($n <= 0) {
            break;
        }
        if (\Runtime\Libc\fwrite_buf($buf, 1, $n, $out) !== $n) {
            $ok = false;
            break;
        }
    }
    \Runtime\Libc\free($buf);
    \Runtime\Libc\fclose($in);
    \Runtime\Libc\fclose($out);
    return $ok;
}

/**
 * Create the file if missing, and set its timestamps. An omitted $mtime falls
 * back to $atime and then to time(); an omitted $atime mirrors $mtime.
 */
function touch(string $filename, ?int $mtime = null, ?int $atime = null): bool
{
    if (!\file_exists($filename)) {
        $fp = \Runtime\Libc\fopen($filename, 'wb');
        if ($fp === null) {
            return false;
        }
        \Runtime\Libc\fclose($fp);
    }
    $mt = $mtime;
    if ($mt === null) {
        $mt = $atime;
    }
    if ($mt === null) {
        $mt = \time();
    }
    $at = $atime;
    if ($at === null) {
        $at = $mt;
    }
    // struct timeval[2] = { {atime, 0}, {mtime, 0} }. tv_sec is 8 bytes on both
    // hosts; tv_usec is 4 (Darwin) or 8 (Linux), but it is zeroed either way, so
    // an i64 zero at +8 / +24 covers the member and any tail padding.
    $buf = \Runtime\Libc\calloc(32, 1);
    if ($buf === null) {
        return false;
    }
    \poke_i64($buf, 0, $at);
    \poke_i64($buf, 8, 0);
    \poke_i64($buf, 16, $mt);
    \poke_i64($buf, 24, 0);
    $r = \Runtime\Libc\sys_utimes($filename, $buf);
    \Runtime\Libc\free($buf);
    return $r === 0;
}

/** Change file permissions. */
function chmod(string $filename, int $permissions): bool
{
    return \Runtime\Libc\sys_chmod($filename, $permissions) === 0;
}

/** Change file owner/group by numeric id (php.net also accepts a name). */
function chown(string $filename, int $user): bool
{
    return \Runtime\Libc\sys_chown($filename, $user, -1) === 0;
}

/** Change file group by numeric id. */
function chgrp(string $filename, int $group): bool
{
    return \Runtime\Libc\sys_chown($filename, -1, $group) === 0;
}

/** Create a symbolic link at $link pointing to $target. */
function symlink(string $target, string $link): bool
{
    return \Runtime\Libc\sys_symlink($target, $link) === 0;
}

/** Create a hard link. */
function link(string $target, string $link): bool
{
    return \Runtime\Libc\sys_link($target, $link) === 0;
}

/** Target of a symbolic link, or false on failure. */
function readlink(string $path): string|false
{
    $cap = 4096;
    $buf = \Runtime\Libc\calloc($cap + 1, 1);
    if ($buf === null) {
        return false;
    }
    $n = \Runtime\Libc\sys_readlink($path, $buf, $cap);
    if ($n < 0) {
        \Runtime\Libc\free($buf);
        return false;
    }
    // readlink(2) does NOT NUL-terminate — cut to the returned length, so
    // str_from_buffer, never cstr_to_str.
    $s = \str_from_buffer($buf, $n);
    \Runtime\Libc\free($buf);
    return $s;
}

/** Canonical absolute path with symlinks resolved, or false when it does not exist. */
function realpath(string $path): string|false
{
    // POSIX requires the buffer to hold PATH_MAX bytes when it is not NULL.
    $buf = \Runtime\Libc\calloc(4097, 1);
    if ($buf === null) {
        return false;
    }
    $p = \Runtime\Libc\sys_realpath($path, $buf);
    if ($p === null) {
        \Runtime\Libc\free($buf);
        return false;
    }
    $s = \cstr_to_str($buf);
    \Runtime\Libc\free($buf);
    return $s;
}

/** Whether a path is writable. access(path, W_OK=2). */
function is_writable(string $filename): bool
{
    return \Runtime\Libc\access($filename, 2) === 0;
}

/** Alias of is_writable(). */
function is_writeable(string $filename): bool
{
    return \Runtime\Libc\access($filename, 2) === 0;
}

/** Whether a path is executable. access(path, X_OK=1). */
function is_executable(string $filename): bool
{
    return \Runtime\Libc\access($filename, 1) === 0;
}

/**
 * Underlying file descriptor of a stream. Internal: php.net has no fileno(),
 * so claiming the global name would collide with a user function rather than
 * add parity.
 * @param resource $stream
 */
function __mc_fileno(\Ffi\Ptr $stream): int
{
    return \Runtime\Libc\sys_fileno($stream);
}

/**
 * Truncate an open stream to $size bytes.
 * @param resource $stream
 */
function ftruncate(\Ffi\Ptr $stream, int $size): bool
{
    \Runtime\Libc\fflush($stream);
    return \Runtime\Libc\sys_ftruncate(\__mc_fileno($stream), $size) === 0;
}

/**
 * Advisory lock. $operation is LOCK_SH (1) / LOCK_EX (2) / LOCK_UN (3),
 * optionally | LOCK_NB (4).
 *
 * PHP's LOCK_* constants are PHP's own values and do NOT all match flock(2):
 * LOCK_UN is 3 in PHP but 8 to the OS, where 3 means LOCK_SH|LOCK_EX and is
 * rejected with EINVAL. LOCK_SH/LOCK_EX/LOCK_NB coincide numerically on both
 * Darwin and Linux. Zend performs the same translation.
 * @param resource $stream
 */
function flock(\Ffi\Ptr $stream, int $operation): bool
{
    $op = $operation & 3;
    if ($op === 3) { $op = 8; }
    if (($operation & 4) !== 0) { $op = $op | 4; }
    return \Runtime\Libc\sys_flock(\__mc_fileno($stream), $op) === 0;
}

/**
 * Flush the OS buffers for a stream to disk.
 * @param resource $stream
 */
function fsync(\Ffi\Ptr $stream): bool
{
    \Runtime\Libc\fflush($stream);
    return \Runtime\Libc\sys_fsync(\__mc_fileno($stream)) === 0;
}

/**
 * fdatasync(2) is not portable to Darwin; fsync is a valid (stronger)
 * substitute, so php.net's contract still holds.
 * @param resource $stream
 */
function fdatasync(\Ffi\Ptr $stream): bool
{
    return \fsync($stream);
}

/** Current umask, or set it and return the previous one. */
function umask(?int $mask = null): int
{
    if ($mask === null) {
        // No read-only umask(2): set-then-restore. Single-threaded, so the
        // window is not observable.
        $old = \Runtime\Libc\sys_umask(0);
        \Runtime\Libc\sys_umask($old);
        return $old;
    }
    return \Runtime\Libc\sys_umask($mask);
}

/**
 * Read a file into an array of lines. Lines keep their trailing newline unless
 * FILE_IGNORE_NEW_LINES (2). FILE_SKIP_EMPTY_LINES (4) drops lines that are
 * empty *after* that stripping — on its own it therefore changes nothing,
 * which matches php.net.
 * @return string[]|false
 */
function file(string $filename, int $flags = 0): array|false
{
    $raw = \file_get_contents($filename);
    if ($raw === false) {
        return false;
    }
    // file_get_contents returns string|false, i.e. a cell. Unbox once here:
    // indexing a cell is correct but costs a NaN-tag branch per access, and
    // this loop touches every byte of the file.
    $data = (string)$raw;
    $strip = ($flags & 2) !== 0;
    $skip = ($flags & 4) !== 0;
    $out = [];
    $len = \strlen($data);
    $start = 0;
    for ($i = 0; $i < $len; $i = $i + 1) {
        if ($data[$i] !== "\n") {
            continue;
        }
        $line = \substr($data, $start, $i - $start + 1);
        if ($strip) {
            $line = \rtrim($line, "\r\n");
        }
        if (!($skip && $line === '')) {
            $out[] = $line;
        }
        $start = $i + 1;
    }
    if ($start < $len) {
        $line = \substr($data, $start);
        if ($strip) {
            $line = \rtrim($line, "\r\n");
        }
        if (!($skip && $line === '')) {
            $out[] = $line;
        }
    }
    return $out;
}

/** Write a file straight to stdout; returns the byte count or false. */
function readfile(string $filename): int|false
{
    $data = \file_get_contents($filename);
    if ($data === false) {
        return false;
    }
    echo $data;
    return \strlen($data);
}

/**
 * One byte from a stream, or false at EOF.
 * @param resource $stream
 * @return string|false
 */
function fgetc(\Ffi\Ptr $stream)
{
    $s = \fread($stream, 1);
    if ($s === '') {
        return false;
    }
    return $s;
}

/** Directory for temporary files, without a trailing slash. */
function sys_get_temp_dir(): string
{
    $t = \getenv('TMPDIR');
    if ($t === false || $t === '') {
        return '/tmp';
    }
    // getenv returns string|false, i.e. a cell — unbox once, then let rtrim do
    // the scan rather than looping over bytes here.
    $s = \rtrim((string)$t, '/');
    if ($s === '') {
        return '/';
    }
    return $s;
}

/** No-op: nothing here caches stat results, so there is nothing to clear. */
function clearstatcache(bool $clear_realpath_cache = false, string $filename = ''): void
{
}
