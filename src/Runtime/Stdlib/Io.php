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
    // str_from_buffer, NOT substr: $buf is a raw \Ffi\Ptr (calloc), NOT a
    // headered string — substr would read a bogus header (and truncate a file
    // with an embedded NUL). str_from_buffer copies exactly $size bytes into a
    // proper headered string (binary-safe). Mirrors Manticore\read_file.
    $s = \str_from_buffer($buf, $size);
    // str_from_buffer copies, so the raw block is ours to release. \Ffi\Ptr is
    // excluded from refcounting — without this every call leaks the buffer.
    \Runtime\Libc\free($buf);
    return $s;
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
// A PHP "resource" is a \Resource object owning a libc FILE*; the raw Ptr never
// leaves this file's libc calls. The f* family follows php.net argument order.

// ── resource predicates (php.net) ──────────────────────────────────────
// A closed resource stays an object but reports type "Unknown" and is no
// longer a resource — `is_resource($f)` is false after fclose($f), which is
// php's own behaviour and the reason `closed` is state rather than a free().

/**
 * The cached \Resource wrapping a standard stream: 0=stdin, 1=stdout, 2=stderr.
 *
 * STDIN/STDOUT/STDERR lower to a call here (see LowerPrelude) because the f*
 * family is typed \Resource. The FILE* is passed IN rather than fetched here on
 * purpose: fetching it means the `__mir_std*` builtin, whose emitter resolves
 * the platform global via host_os() — and host_os() rides libc bindings that are
 * empty stubs under the Zend seed. A stdlib fn mentioning those builtins makes
 * the compiler's own src/ use a stream and kills the cold bootstrap.
 *
 * Cached per stream, so `STDOUT === STDOUT` and its id is stable; a fresh object
 * per mention would also hand a destructor the real stdout. Marked persistent —
 * these are libc's globals, never ours to close. Built lazily, so the ids follow
 * first use rather than php's fixed 1/2/3 (php's numbering is unreachable anyway:
 * it hands the first fopen() id 5).
 * @param \Ffi\Ptr $handle libc's FILE* for this stream
 */
function __mc_std_res(int $which, \Ffi\Ptr $handle): \Resource
{
    static $in = null;
    static $out = null;
    static $err = null;
    static $haveIn = 0;
    static $haveOut = 0;
    static $haveErr = 0;
    if ($which === 0) {
        if ($haveIn === 0) { $in = new \Resource(\Resource::KIND_FILE, 'stream', \ptr_to_int($handle), true); $haveIn = 1; }
        return $in;
    }
    if ($which === 1) {
        if ($haveOut === 0) { $out = new \Resource(\Resource::KIND_FILE, 'stream', \ptr_to_int($handle), true); $haveOut = 1; }
        return $out;
    }
    if ($haveErr === 0) { $err = new \Resource(\Resource::KIND_FILE, 'stream', \ptr_to_int($handle), true); $haveErr = 1; }
    return $err;
}

/** Whether $value is an open resource. */
function is_resource(mixed $value): bool
{
    if (!($value instanceof \Resource)) {
        return false;
    }
    return !$value->closed;
}

/**
 * Resource type name ("stream", "dir", …), or "Unknown" once closed.
 * @return string|false
 */
function get_resource_type(mixed $value)
{
    if (!($value instanceof \Resource)) {
        return false;
    }
    return $value->type;
}

/** The resource's id. php numbers them from 1 and never reuses one. */
function get_resource_id(\Resource $value): int
{
    return $value->id;
}

/**
 * Open a file. Returns a file resource, or false on failure.
 * @return \Resource|false
 */
function fopen(string $filename, string $mode)
{
    $fp = \Runtime\Libc\fopen($filename, $mode);
    if ($fp === null) {
        return false;
    }
    return new \Resource(\Resource::KIND_FILE, 'stream', \ptr_to_int($fp));
}

/**
 * Close an open file resource. Returns true on success. Idempotent per
 * php.net: closing twice is false the second time, not a double fclose.
 */
function fclose(\Resource $stream): bool
{
    return $stream->close();
}

/**
 * Write $data to $stream, at most $length bytes when given. Returns the
 * number of bytes written.
 * @param \Resource $stream
 */
function fwrite(\Resource $stream, string $data, ?int $length = null): int
{
    $len = \strlen($data);
    if ($length !== null && $length >= 0 && $length < $len) {
        $len = $length;
    }
    return \Runtime\Libc\fwrite($data, 1, $len, \int_to_ptr($stream->addr));
}

/**
 * Alias of fwrite().
 * @param \Resource $stream
 */
function fputs(\Resource $stream, string $data, ?int $length = null): int
{
    return fwrite($stream, $data, $length);
}

/**
 * Read up to $length bytes from $stream. Returns the bytes read as a string.
 * @param \Resource $stream
 */
function fread(\Resource $stream, int $length): string
{
    if ($length <= 0) {
        return "";
    }
    $buf = \Runtime\Libc\calloc($length + 1, 1);
    if ($buf === null) {
        return "";
    }
    $n = \Runtime\Libc\fread($buf, 1, $length, \int_to_ptr($stream->addr));
    if ($n < 0) {
        $n = 0;
    }
    // str_from_buffer, NOT substr: raw \Ffi\Ptr, exactly $n bytes (binary-safe).
    $s = \str_from_buffer($buf, $n);
    \Runtime\Libc\free($buf);
    return $s;
}

/**
 * Read a line from $stream (up to $length-1 bytes, including the newline).
 * Returns the line, or false at EOF.
 * @param \Resource $stream
 * @return string|false
 */
function fgets(\Resource $stream, ?int $length = null)
{
    $cap = ($length !== null && $length > 1) ? $length : 8192;
    $buf = \Runtime\Libc\calloc($cap + 1, 1);
    if ($buf === null) {
        return false;
    }
    $r = \Runtime\Libc\fgets($buf, $cap, \int_to_ptr($stream->addr));
    if ($r === null) {
        \Runtime\Libc\free($buf);
        return false;
    }
    // cstr_to_str, NOT substr: $buf is a raw \Ffi\Ptr (no header) holding a
    // NUL-terminated line — substr would read a bogus header once substr goes
    // binary-safe. cstr_to_str is the libc-strlen boundary for FFI char*.
    $s = \cstr_to_str($buf);
    \Runtime\Libc\free($buf);
    return $s;
}

/**
 * Whether the file pointer is at end-of-file.
 * @param \Resource $stream
 */
function feof(\Resource $stream): bool
{
    return \Runtime\Libc\feof(\int_to_ptr($stream->addr)) !== 0;
}

/**
 * Seek on a file resource. $whence is SEEK_SET (0) / SEEK_CUR (1) / SEEK_END
 * (2). Returns 0 on success, -1 on failure.
 * @param \Resource $stream
 */
function fseek(\Resource $stream, int $offset, int $whence = 0): int
{
    return \Runtime\Libc\fseek(\int_to_ptr($stream->addr), $offset, $whence);
}

/**
 * Current position of the file pointer, or false on failure.
 * @param \Resource $stream
 * @return int|false
 */
function ftell(\Resource $stream)
{
    $p = \Runtime\Libc\ftell(\int_to_ptr($stream->addr));
    if ($p < 0) {
        return false;
    }
    return $p;
}

/**
 * Rewind a file resource to the start. Returns true on success.
 * @param \Resource $stream
 */
function rewind(\Resource $stream): bool
{
    return \Runtime\Libc\fseek(\int_to_ptr($stream->addr), 0, 0) === 0;
}

/**
 * Flush buffered output to a file resource. Returns true on success.
 * @param \Resource $stream
 */
function fflush(\Resource $stream): bool
{
    return \Runtime\Libc\fflush(\int_to_ptr($stream->addr)) === 0;
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
        \Runtime\Libc\free($buf);
        return false;
    }
    // cstr_to_str, NOT substr: raw \Ffi\Ptr, NUL-terminated cwd (see fgets).
    $s = \cstr_to_str($buf);
    \Runtime\Libc\free($buf);
    return $s;
}
