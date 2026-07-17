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
    // The seam. https:// resolves to '' here and fails until Ф4 supplies the
    // transport — the HTTP layer itself is already transport-agnostic.
    $scheme = \__mc_scheme_of($path);
    if ($scheme === 'http') {
        return \__mc_http_get($path);
    }
    if ($scheme !== 'file') {
        return false;
    }
    $fp = \Runtime\Libc\fopen(\__mc_file_path($path), "rb");
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

// ── the one place a stream blocks ──────────────────────────────────────
//
// EVERY socket read goes through __mc_stream_fill(). That is a rule, not a
// convenience: for `Async\run(fn() => file_get_contents(...))` to become
// non-blocking later WITHOUT rewriting the HTTP layer, there has to be exactly
// ONE function that decides to wait. Teaching it to suspend (scheduler active ?
// Io\Poll + suspend : recv) is then a local change; a parser that called recv()
// itself would have to be rewritten instead.

/** Bytes buffered and not yet consumed. */
function __mc_buf_len(\Resource $s): int
{
    return \strlen($s->rbuf) - $s->rpos;
}

/**
 * Drop the consumed prefix. Only when it is worth the copy — compacting on every
 * read is exactly the quadratic behaviour the cursor exists to avoid.
 */
function __mc_buf_compact(\Resource $s): void
{
    if ($s->rpos === 0) {
        return;
    }
    if ($s->rpos >= \strlen($s->rbuf)) {
        $s->rbuf = '';
        $s->rpos = 0;
        return;
    }
    if ($s->rpos >= 8192) {
        $s->rbuf = \substr($s->rbuf, $s->rpos);
        $s->rpos = 0;
    }
}

/**
 * Pull ONE chunk from the socket into the buffer. Returns bytes added; 0 means
 * the peer closed (recorded as eof — a bare fd has no FILE* to remember it).
 *
 * ⚠ THE ONLY BLOCKING CALL in the stream path. See the note above.
 */
function __mc_stream_fill(\Resource $s, int $want): int
{
    if ($want < 4096) {
        $want = 4096;   // a syscall costs the same for 1 byte or 4 KiB
    }
    $buf = \Runtime\Libc\calloc($want + 1, 1);
    if ($buf === null) {
        return 0;
    }
    $got = \Runtime\Libc\sys_recv($s->addr, $buf, $want, 0);
    if ($got > 0) {
        __mc_buf_compact($s);
        $s->rbuf = $s->rbuf . \str_from_buffer($buf, $got);
    } elseif ($got === 0) {
        $s->eof = true;
    }
    \Runtime\Libc\free($buf);
    return $got > 0 ? $got : 0;
}

/** Read up to $n buffered bytes, filling from the socket only when empty. */
function __mc_stream_read(\Resource $s, int $n): string
{
    if ($n <= 0) {
        return '';
    }
    if (\__mc_buf_len($s) === 0) {
        if ($s->eof) {
            return '';
        }
        // BULK BYPASS: nothing buffered and a big ask ⇒ recv straight into the
        // result. The buffer exists so fgets() can keep the bytes past a newline;
        // a large fread() wants none of that, and routing it through the buffer
        // costs THREE copies of the data (str_from_buffer, the concat into $rbuf,
        // then the substr back out) plus a malloc — where php does one.
        // MEASURED, externally with `time`, 16 MiB over loopback:
        //   before: 0.42s vs php's 0.07s — 6x SLOWER
        // The threshold is the fill size: below it the buffered path is already
        // reading a whole chunk anyway, so there is nothing to win and the
        // read-ahead is worth keeping.
        if ($n >= 4096) {
            $buf = \Runtime\Libc\calloc($n + 1, 1);
            if ($buf === null) {
                return '';
            }
            $got = \Runtime\Libc\sys_recv($s->addr, $buf, $n, 0);
            if ($got <= 0) {
                if ($got === 0) { $s->eof = true; }
                \Runtime\Libc\free($buf);
                return '';
            }
            $out = \str_from_buffer($buf, $got);
            \Runtime\Libc\free($buf);
            return $out;
        }
        \__mc_stream_fill($s, $n);
    }
    $avail = \__mc_buf_len($s);
    if ($avail === 0) {
        return '';
    }
    if ($n > $avail) {
        $n = $avail;
    }
    $out = \substr($s->rbuf, $s->rpos, $n);
    $s->rpos = $s->rpos + $n;
    \__mc_buf_compact($s);
    return $out;
}

/**
 * fgets() for a SOCKET: a line INCLUDING its terminator, or false when nothing
 * at all could be read.
 *
 * Buffered, unlike the byte-at-a-time recv() this replaces: a recv boundary never
 * lines up with a line boundary, so the bytes past the newline have to be KEPT for
 * whoever asks next. That is the whole reason the buffer exists — reading one byte
 * per syscall was the only other way to avoid over-reading, and it cost a syscall
 * per character.
 *
 * @return string|false
 */
function __mc_socket_gets(\Resource $stream, int $cap)
{
    $searched = 0;
    while (true) {
        $len = \__mc_buf_len($stream);
        if ($len > 0) {
            $at = \strpos($stream->rbuf, "\n", $stream->rpos + $searched);
            if ($at !== false) {
                $take = ($at - $stream->rpos) + 1;
                if ($cap > 0 && $take > $cap - 1) {
                    $take = $cap - 1;
                }
                $out = \substr($stream->rbuf, $stream->rpos, $take);
                $stream->rpos = $stream->rpos + $take;
                \__mc_buf_compact($stream);
                return $out;
            }
            // php's $length caps the line even with no terminator in sight.
            if ($cap > 0 && $len >= $cap - 1) {
                $out = \substr($stream->rbuf, $stream->rpos, $cap - 1);
                $stream->rpos = $stream->rpos + ($cap - 1);
                \__mc_buf_compact($stream);
                return $out;
            }
            // Everything buffered is newline-free — do not rescan it next round.
            $searched = $len;
        }
        if ($stream->eof) {
            break;
        }
        if (\__mc_stream_fill($stream, 4096) === 0) {
            break;
        }
    }
    // Whatever is left with no terminator: php returns it, and false only when
    // there was nothing at all.
    $len = \__mc_buf_len($stream);
    if ($len === 0) {
        return false;
    }
    $out = \substr($stream->rbuf, $stream->rpos, $len);
    $stream->rpos = $stream->rpos + $len;
    \__mc_buf_compact($stream);
    return $out;
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

// ── stream wrappers: scheme -> handler ─────────────────────────────────
//
// This is the seam, and it is why it is not `if (strpos($p,'http://')===0)`:
// https:// is the SAME protocol over a different transport, so a hardcoded
// http check would be torn out again immediately. Every opener funnels through
// __mc_scheme_of() so a new scheme is one arm, not a new call path.
//
// NOT stream_wrapper_register(): a user-registered wrapper dispatches methods on
// a class known only at runtime, which needs dynamic class resolution — a
// separate epic. This table is the INTERNAL set.

/**
 * The scheme of $path, lowercased, or 'file' when it carries none.
 * Returns '' when a scheme is present but not one we handle — php reports
 * "Unable to find the wrapper" and the call returns false.
 *
 * Rules MEASURED against php 8.5.8, not assumed:
 *   /tmp/x                     -> file    (no scheme at all)
 *   file:///tmp/x              -> file
 *   FILE:///tmp/x              -> file    (scheme is CASE-INSENSITIVE)
 *   file://localhost/tmp/x     -> file    (a host is allowed, and ignored)
 *   2bad://x                   -> ''      (a scheme may not START with a digit)
 *   nosuch://x                 -> ''      (unregistered -> false, not a filename)
 * A bare `://` at offset 0 is not a scheme either.
 */
function __mc_scheme_of(string $path): string
{
    $at = \strpos($path, '://');
    if ($at === false || $at === 0) {
        return 'file';
    }
    $raw = \substr($path, 0, $at);
    // [A-Za-z][A-Za-z0-9+.-]* — anything else is not a scheme, so php treats the
    // whole string as a filename rather than a URL. That is why '/tmp/a://b'
    // opens a FILE and '2bad://x' does not.
    $n = \strlen($raw);
    for ($i = 0; $i < $n; $i = $i + 1) {
        $c = $raw[$i];
        $isAlpha = ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z');
        if ($i === 0) {
            if (!$isAlpha) { return 'file'; }
            continue;
        }
        $ok = $isAlpha || ($c >= '0' && $c <= '9') || $c === '+' || $c === '.' || $c === '-';
        if (!$ok) { return 'file'; }
    }
    $scheme = \strtolower($raw);
    if ($scheme === 'file' || $scheme === 'http') {
        return $scheme;
    }
    // Known-but-unimplemented schemes are still NOT files: php would find no
    // wrapper and fail, and so must we — silently opening `https://x` as a
    // relative filename would be worse than failing. (https:// lands here until
    // the TLS transport exists; the protocol layer above is already written.)
    return '';
}

/**
 * The filesystem path inside a `file://` URL. php accepts an (ignored) host, so
 * `file://localhost/tmp/x` and `file:///tmp/x` are the same file.
 *
 * ⚠ Strips ONLY a real `file://` prefix. Containing `://` is not enough: for
 * `2bad:///tmp/x` the scheme is invalid (a scheme may not start with a digit), so
 * php treats the WHOLE string as a filename — one that does not exist. Stripping
 * it anyway turned that into a successful read of /tmp/x: not an error, just the
 * wrong file. Caught by stream_scheme.php, which is why the case is in it.
 */
function __mc_file_path(string $path): string
{
    $at = \strpos($path, '://');
    if ($at === false) {
        return $path;
    }
    // Only a literal `file` scheme is a URL here; anything else (invalid, or
    // simply a path that happens to contain '://') is a plain filename.
    if (\strtolower(\substr($path, 0, $at)) !== 'file') {
        return $path;
    }
    $rest = \substr($path, $at + 3, \strlen($path) - ($at + 3));
    // Everything up to the first '/' is the host component — php ignores it.
    $slash = \strpos($rest, '/');
    if ($slash === false) {
        return $rest;
    }
    return \substr($rest, $slash, \strlen($rest) - $slash);
}

/**
 * Open a file. Returns a file resource, or false on failure.
 * @return \Resource|false
 */
function fopen(string $filename, string $mode)
{
    $scheme = \__mc_scheme_of($filename);
    if ($scheme !== 'file') {
        return false;   // no wrapper for it (yet) — php: "Unable to find the wrapper"
    }
    $fp = \Runtime\Libc\fopen(\__mc_file_path($filename), $mode);
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
    if ($stream->kind === \Resource::KIND_SOCKET) {
        // A socket's addr is an fd, so int_to_ptr() would hand fwrite a small
        // integer as a FILE*. send(2) instead — and a short write is a real
        // outcome here, not an error, so report what went out.
        $n = \Runtime\Libc\sys_send($stream->addr, $data, $len, 0);
        return $n < 0 ? 0 : $n;
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
    if ($stream->kind === \Resource::KIND_SOCKET) {
        // MUST go through the buffer, not straight to recv(): fgets() reads ahead
        // by design, so bytes it already pulled would be invisible to a direct
        // recv() and the stream would silently skip them.
        \Runtime\Libc\free($buf);
        return \__mc_stream_read($stream, $length);
    }
    {
        $n = \Runtime\Libc\fread($buf, 1, $length, \int_to_ptr($stream->addr));
        if ($n < 0) {
            $n = 0;
        }
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
    if ($stream->kind === \Resource::KIND_SOCKET) {
        \Runtime\Libc\free($buf);
        return \__mc_socket_gets($stream, $cap);
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
    if ($stream->kind === \Resource::KIND_SOCKET) {
        // Buffered bytes mean NOT eof even after the peer closed: php reports eof
        // only once a read actually finds nothing left.
        return $stream->eof && \__mc_buf_len($stream) === 0;
    }
    return \Runtime\Libc\feof(\int_to_ptr($stream->addr)) !== 0;
}

/**
 * Seek on a file resource. $whence is SEEK_SET (0) / SEEK_CUR (1) / SEEK_END
 * (2). Returns 0 on success, -1 on failure.
 * @param \Resource $stream
 */
function fseek(\Resource $stream, int $offset, int $whence = 0): int
{
    if ($stream->kind === \Resource::KIND_SOCKET) {
        return -1;   // php: a socket stream is not seekable
    }
    return \Runtime\Libc\fseek(\int_to_ptr($stream->addr), $offset, $whence);
}

/**
 * Current position of the file pointer, or false on failure.
 * @param \Resource $stream
 * @return int|false
 */
function ftell(\Resource $stream)
{
    if ($stream->kind === \Resource::KIND_SOCKET) {
        // KNOWN DIVERGENCE, measured against php 8.5.8: php DOES report a
        // position on a socket stream (it counts the bytes that passed through
        // its own buffer) — `ftell()` there returns an int, not false. Matching
        // it means carrying a byte counter on the Resource and bumping it in
        // every read/write path; that lands with the Ф3 read buffer, which needs
        // the same bookkeeping. Until then this reports "no position" rather
        // than inventing a number that would drift from php's.
        return false;
    }
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
    if ($stream->kind === \Resource::KIND_SOCKET) {
        return false;
    }
    return \Runtime\Libc\fseek(\int_to_ptr($stream->addr), 0, 0) === 0;
}

/**
 * Flush buffered output to a file resource. Returns true on success.
 * @param \Resource $stream
 */
function fflush(\Resource $stream): bool
{
    if ($stream->kind === \Resource::KIND_SOCKET) {
        return true;   // send(2) is unbuffered — nothing to flush
    }
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
