<?php

/**
 * Pure-PHP implementations of common PHP filesystem std functions on top
 * of libc primitives bound via #[Ffi\Library, Symbol] in {@see Runtime\Libc}.
 * No Rust runtime, no external libs — the same compile-time-FFI mechanism the
 * compiler uses internally (Manticore\read_file).
 *
 * Global namespace so user code calling `file_get_contents($p)` resolves here
 * (the compiler's inline-builtin table does not handle these, so they fall
 * through to user-function resolution).
 *
 * A libc buffer from calloc has no rc header, so a raw read is copied into a
 * real (rc-headered) string via `substr($buf, 0, $len)` before returning.
 */

/**
 * Read an entire file into a string, or false on failure.
 */
function file_get_contents(string $path): string|false
{
    $fp = \Runtime\Libc\fopen($path, "rb");
    if ($fp === null) {
        return false;
    }
    // SEEK_END = 2, SEEK_SET = 0 on both Linux and macOS.
    \Runtime\Libc\fseek($fp, 0, 2);
    $size = \Runtime\Libc\ftell($fp);
    \Runtime\Libc\fseek($fp, 0, 0);
    if ($size < 0) {
        \Runtime\Libc\fclose($fp);
        return false;
    }
    $buf = \Runtime\Libc\calloc($size + 1, 1);
    if ($buf === null) {
        \Runtime\Libc\fclose($fp);
        return false;
    }
    \Runtime\Libc\fread($buf, 1, $size, $fp);
    \Runtime\Libc\fclose($fp);
    return \substr($buf, 0, $size);
}

/**
 * Write a string to a file. `$flags & FILE_APPEND` (8) appends instead of
 * truncating. Returns the byte count, or false on failure.
 */
function file_put_contents(string $path, string $data, int $flags = 0): int|false
{
    $mode = ($flags & 8) !== 0 ? "ab" : "wb";
    $fp = \Runtime\Libc\fopen($path, $mode);
    if ($fp === null) {
        return false;
    }
    $len = \strlen($data);
    $n = \Runtime\Libc\fwrite($data, 1, $len, $fp);
    \Runtime\Libc\fclose($fp);
    return $n;
}

// ── file handle (resource) functions, php.net semantics ────────────────
// A PHP "resource" is a libc FILE* carried as an \Ffi\Ptr. fopen returns
// false on failure (php.net); the f* family follows php.net argument order.

/**
 * Open a file. Returns a file resource, or false (the null resource) on
 * failure — `if ($fh === false)`, `if (!$fh)` and `$fh === null` all detect it.
 * @return resource
 */
function fopen(string $filename, string $mode)
{
    return \Runtime\Libc\fopen($filename, $mode);
}

/**
 * Close an open file resource. Returns true on success.
 * @param resource $stream
 */
function fclose(\Ffi\Ptr $stream): bool
{
    return \Runtime\Libc\fclose($stream) === 0;
}

/**
 * Write $data to $stream, at most $length bytes when given. Returns the
 * number of bytes written.
 * @param resource $stream
 */
function fwrite(\Ffi\Ptr $stream, string $data, ?int $length = null): int
{
    $len = \strlen($data);
    if ($length !== null && $length >= 0 && $length < $len) {
        $len = $length;
    }
    return \Runtime\Libc\fwrite($data, 1, $len, $stream);
}

/**
 * Alias of fwrite().
 * @param resource $stream
 */
function fputs(\Ffi\Ptr $stream, string $data, ?int $length = null): int
{
    return fwrite($stream, $data, $length);
}

/**
 * Read up to $length bytes from $stream. Returns the bytes read as a string.
 * @param resource $stream
 */
function fread(\Ffi\Ptr $stream, int $length): string
{
    if ($length <= 0) {
        return "";
    }
    $buf = \Runtime\Libc\calloc($length + 1, 1);
    if ($buf === null) {
        return "";
    }
    $n = \Runtime\Libc\fread($buf, 1, $length, $stream);
    if ($n < 0) {
        $n = 0;
    }
    return \substr($buf, 0, $n);
}

/**
 * Read a line from $stream (up to $length-1 bytes, including the newline).
 * Returns the line, or false at EOF.
 * @param resource $stream
 * @return string|false
 */
function fgets(\Ffi\Ptr $stream, ?int $length = null)
{
    $cap = ($length !== null && $length > 1) ? $length : 8192;
    $buf = \Runtime\Libc\calloc($cap + 1, 1);
    if ($buf === null) {
        return false;
    }
    $r = \Runtime\Libc\fgets($buf, $cap, $stream);
    if ($r === null) {
        return false;
    }
    // raw calloc buffer (no header): 2-arg substr copies to the NUL via libc
    // strlen — `strlen($buf)` would read the absent len field post-flip.
    return \substr($buf, 0);
}

/**
 * Whether the file pointer is at end-of-file.
 * @param resource $stream
 */
function feof(\Ffi\Ptr $stream): bool
{
    return \Runtime\Libc\feof($stream) !== 0;
}

/**
 * Seek on a file resource. $whence is SEEK_SET (0) / SEEK_CUR (1) / SEEK_END
 * (2). Returns 0 on success, -1 on failure.
 * @param resource $stream
 */
function fseek(\Ffi\Ptr $stream, int $offset, int $whence = 0): int
{
    return \Runtime\Libc\fseek($stream, $offset, $whence);
}

/**
 * Current position of the file pointer, or false on failure.
 * @param resource $stream
 * @return int|false
 */
function ftell(\Ffi\Ptr $stream)
{
    $p = \Runtime\Libc\ftell($stream);
    if ($p < 0) {
        return false;
    }
    return $p;
}

/**
 * Rewind a file resource to the start. Returns true on success.
 * @param resource $stream
 */
function rewind(\Ffi\Ptr $stream): bool
{
    return \Runtime\Libc\fseek($stream, 0, 0) === 0;
}

/**
 * Flush buffered output to a file resource. Returns true on success.
 * @param resource $stream
 */
function fflush(\Ffi\Ptr $stream): bool
{
    return \Runtime\Libc\fflush($stream) === 0;
}

/**
 * Whether a path exists (file or directory). access(path, F_OK=0).
 */
function file_exists(string $path): bool
{
    return \Runtime\Libc\access($path, 0) === 0;
}

/**
 * Whether a path is readable. access(path, R_OK=4).
 */
function is_readable(string $path): bool
{
    return \Runtime\Libc\access($path, 4) === 0;
}

/**
 * Delete a file. Returns true on success.
 */
function unlink(string $path): bool
{
    return \Runtime\Libc\sys_unlink($path) === 0;
}

/**
 * Current working directory, or false on failure.
 */
function getcwd(): string|false
{
    $buf = \Runtime\Libc\calloc(4097, 1);
    if ($buf === null) {
        return false;
    }
    $r = \Runtime\Libc\sys_getcwd($buf, 4096);
    if ($r === null) {
        return false;
    }
    // raw calloc buffer (no header): 2-arg substr copies to the NUL via libc.
    return \substr($buf, 0);
}
