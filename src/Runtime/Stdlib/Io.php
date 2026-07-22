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
function file_get_contents(string $path, bool $use_include_path = false, ?\Resource $context = null): string|false
{
    // The seam. http:// and https:// share the HTTP layer; the only difference is
    // the transport __mc_http_get opens (plain socket vs TLS), so both route here.
    // A stream context (POST body, extra headers, ssl verify flags) is threaded
    // through a \Resource-typed helper so the (nullable, hence cell) $context is
    // unboxed before its fields are read.
    $scheme = \__mc_scheme_of($path);
    if ($scheme === 'http' || $scheme === 'https') {
        if ($context !== null) {
            return \__mc_http_get_with_context($path, $context);
        }
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
 * Whether $s is a network stream — a plain SOCKET or a TLS stream. Both are
 * non-seekable, buffer their reads, and carry their own eof; the ONLY thing that
 * differs is the byte primitive (recv/send vs SSL_read/SSL_write), funnelled
 * through __mc_transport_recv/_send. Every f*-family dispatch that used to test
 * `=== KIND_SOCKET` tests this instead, so TLS inherits the whole socket path.
 */
function __mc_stream_is_net(\Resource $s): bool
{
    return $s->kind === \Resource::KIND_SOCKET || $s->kind === \Resource::KIND_TLS;
}

/**
 * Whether $s reads through the rbuf/rpos buffer rather than a FILE*: a network
 * stream OR an in-memory one. A KIND_MEMORY stream has $eof set, so the same
 * readers drain it and never attempt a socket fill. Used by the read/eof/seek
 * dispatch; only fwrite distinguishes further (a memory stream is read-only).
 */
function __mc_stream_is_buffered(\Resource $s): bool
{
    return $s->kind === \Resource::KIND_SOCKET || $s->kind === \Resource::KIND_TLS
        || $s->kind === \Resource::KIND_MEMORY;
}

/**
 * Read up to $n bytes off the transport into $buf. For a TLS stream this is
 * SSL_read (which handles the record layer); for a plain socket, recv(2).
 * Returns the byte count, <=0 on close/error.
 */
function __mc_transport_recv(\Resource $s, \Ffi\Ptr $buf, int $n): int
{
    if ($s->kind === \Resource::KIND_TLS) {
        return \Runtime\Openssl\read($s->ssl, $buf, $n);
    }
    return \Runtime\Libc\sys_recv($s->addr, $buf, $n, 0);
}

/**
 * Write $n bytes of $data to the transport — SSL_write for TLS, send(2) for a
 * plain socket. Returns the byte count, <=0 on error.
 */
function __mc_transport_send(\Resource $s, string $data, int $n): int
{
    if ($s->kind === \Resource::KIND_TLS) {
        return \Runtime\Openssl\write($s->ssl, $data, $n);
    }
    return \Runtime\Libc\sys_send($s->addr, $data, $n, 0);
}

/**
 * Wait (bounded) for a network stream to be readable before a blocking recv, so a
 * hung peer cannot block forever. Returns 1 to proceed, 0 on timeout (records
 * $timedOut). For TLS, buffered plaintext (SSL_pending) counts as readable — the
 * fd may be idle while the engine already holds data.
 */
function __mc_wait_read(\Resource $s): int
{
    if ($s->kind === \Resource::KIND_TLS && \Runtime\Openssl\pending($s->ssl) > 0) {
        return 1;
    }
    // Non-blocking (stream_set_blocking(false)): never wait — a 0 timeout means a
    // read with no data ready returns immediately (fill sees 0), matching php.
    $to = $s->blocking ? ($s->rtimeoutMs > 0 ? $s->rtimeoutMs : 60000) : 0;
    if (\__mc_poll_readable($s->addr, $to) === 0) {
        $s->timedOut = $s->blocking;   // a non-blocking empty read is not a timeout
        return 0;
    }
    return 1;   // readable, POLLHUP (recv drains + reports EOF), or poll error
}

/**
 * Pull ONE chunk from the socket into the buffer. Returns bytes added; 0 means
 * the peer closed OR the read timed out (both stop the reader; feof vs timed_out
 * distinguishes them).
 *
 * ⚠ THE ONLY BLOCKING CALL in the stream path. See the note above.
 */
function __mc_stream_fill(\Resource $s, int $want): int
{
    if ($want < 4096) {
        $want = 4096;   // a syscall costs the same for 1 byte or 4 KiB
    }
    if (\__mc_wait_read($s) === 0) {
        return 0;   // timed out — do not block on recv
    }
    $buf = \Runtime\Libc\calloc($want + 1, 1);
    if ($buf === null) {
        return 0;
    }
    $got = __mc_transport_recv($s, $buf, $want);
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
            if (\__mc_wait_read($s) === 0) {
                return '';   // timed out
            }
            $buf = \Runtime\Libc\calloc($n + 1, 1);
            if ($buf === null) {
                return '';
            }
            $got = __mc_transport_recv($s, $buf, $n);
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

/**
 * php.net's stream_get_meta_data — the subset that is knowable here. `timed_out`
 * is the important one (whether the last read hit stream_set_timeout's deadline);
 * `eof`, `blocked`, `unread_bytes`, `seekable`, `stream_type` follow. php returns
 * a few more keys (mode, wrapper_type, uri) that carry no useful value for our
 * streams, so they are omitted rather than faked.
 * @return array<string,mixed>
 */
function stream_get_meta_data(\Resource $stream): array
{
    $net = \__mc_stream_is_buffered($stream);
    return [
        'timed_out' => $stream->timedOut,
        'blocked' => true,
        'eof' => $net ? ($stream->eof && \__mc_buf_len($stream) === 0) : false,
        'unread_bytes' => $net ? \__mc_buf_len($stream) : 0,
        'stream_type' => $net ? 'tcp_socket/ssl' : 'STDIO',
        'seekable' => !$net,
    ];
}

/** The stream wrappers (schemes) fopen/file_get_contents understand here. */
function stream_get_wrappers(): array
{
    return ['php', 'file', 'http', 'https'];
}

/** Whether $stream is a LOCAL stream (a file/memory resource), not a network one. */
function stream_is_local(\Resource $stream): bool
{
    $k = $stream->kind;
    return $k === \Resource::KIND_FILE || $k === \Resource::KIND_DIR
        || $k === \Resource::KIND_MEMFILE || $k === \Resource::KIND_MEMORY;
}

/** Whether $stream supports flock() — only a FILE-backed stream does. */
function stream_supports_lock(\Resource $stream): bool
{
    return $stream->kind === \Resource::KIND_FILE;
}

/**
 * Set the read buffer size. A tuning hint with no effect here (our reads are
 * already buffered by the rbuf/rpos machinery); 0 = success, as in php.
 */
function stream_set_read_buffer(\Resource $stream, int $size): int
{
    return 0;
}

/** Set the write buffer size. A no-op tuning hint; 0 = success. */
function stream_set_write_buffer(\Resource $stream, int $size): int
{
    return 0;
}

/**
 * Set blocking (default) or non-blocking mode on a socket/TLS stream. Non-blocking
 * sets O_NONBLOCK on the fd and flips $blocking so the read path polls with a 0
 * timeout — a read with no data ready then returns '' instead of waiting. Only
 * meaningful for a network stream; php returns true for it, false otherwise.
 */
function stream_set_blocking(\Resource $stream, bool $enable): bool
{
    if (!\__mc_stream_is_net($stream)) {
        return false;
    }
    // F_GETFL(3) / F_SETFL(4) / O_NONBLOCK — via the socket-const table (host-split).
    $fl = \Runtime\Libc\sys_fcntl($stream->addr, \__mc_sock_const(6), 0);
    if ($fl < 0) {
        return false;
    }
    $nb = \__mc_sock_const(5);   // O_NONBLOCK
    $new = $enable ? ($fl & ~$nb) : ($fl | $nb);
    if (\Runtime\Libc\sys_fcntl($stream->addr, \__mc_sock_const(7), $new) < 0) {
        return false;
    }
    $stream->blocking = $enable;
    return true;
}

/**
 * Set the read chunk size. A tuning hint with no behavioural effect here; php
 * returns the PREVIOUS size, which defaults to 8192.
 */
function stream_set_chunk_size(\Resource $stream, int $size): int
{
    return 8192;
}

/** Whether $stream is connected to a terminal. */
function stream_isatty(\Resource $stream): bool
{
    // Only a FILE-backed stream (stdio) has a tty behind it; a socket/TLS/memory/
    // context stream never does, and has no FILE* to hand fileno().
    if ($stream->kind !== \Resource::KIND_FILE) {
        return false;
    }
    return \Runtime\Libc\sys_isatty(\__mc_fileno($stream)) === 1;
}

/**
 * Read the rest of $stream (or $maxlen bytes), optionally seeking to $offset
 * first (>= 0). Returns the bytes read.
 */
function stream_get_contents(\Resource $stream, int $maxlen = -1, int $offset = -1): string
{
    if ($offset >= 0) {
        \fseek($stream, $offset);
    }
    $out = '';
    if ($maxlen < 0) {
        while (true) {
            $chunk = \fread($stream, 8192);
            if ($chunk === '') { break; }
            $out = $out . $chunk;
        }
    } else {
        while (\strlen($out) < $maxlen) {
            $chunk = \fread($stream, $maxlen - \strlen($out));
            if ($chunk === '') { break; }
            $out = $out . $chunk;
        }
    }
    return $out;
}

/**
 * Read a line up to $length bytes, stopping at $ending (which is CONSUMED but not
 * returned — unlike fgets which keeps the newline). $ending '' reads to $length or
 * EOF. Returns the line, or false at EOF with nothing read.
 * @return string|false
 */
function stream_get_line(\Resource $stream, int $length, string $ending = "")
{
    $out = '';
    $elen = \strlen($ending);
    while ($length <= 0 || \strlen($out) < $length) {
        $c = \fread($stream, 1);
        if ($c === '') {
            break;   // EOF
        }
        $out = $out . $c;
        if ($elen > 0) {
            $olen = \strlen($out);
            if ($olen >= $elen && \substr($out, $olen - $elen, $elen) === $ending) {
                return \substr($out, 0, $olen - $elen);   // strip the delimiter
            }
        }
    }
    return $out === '' ? false : $out;
}

/**
 * Copy up to $maxlen bytes (all when -1) from $from to $to, optionally seeking
 * $from to $offset (> 0) first. Returns the number of bytes copied.
 */
function stream_copy_to_stream(\Resource $from, \Resource $to, int $maxlen = -1, int $offset = -1): int
{
    if ($offset > 0) {
        \fseek($from, $offset);
    }
    $copied = 0;
    while ($maxlen < 0 || $copied < $maxlen) {
        $want = 8192;
        if ($maxlen >= 0) {
            $rem = $maxlen - $copied;
            if ($rem < $want) { $want = $rem; }
        }
        $chunk = \fread($from, $want);
        if ($chunk === '') {
            break;
        }
        \fwrite($to, $chunk);
        $copied = $copied + \strlen($chunk);
    }
    return $copied;
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
    if ($scheme === 'file' || $scheme === 'http' || $scheme === 'https'
        || $scheme === 'php') {
        return $scheme;
    }
    // Known-but-unimplemented schemes are still NOT files: php would find no
    // wrapper and fail, and so must we — silently opening an unknown scheme as a
    // relative filename would be worse than failing.
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

// ── stream context ─────────────────────────────────────────────────────
//
// stream_context_create() returns a php `resource(stream-context)` — a
// KIND_CONTEXT \Resource with no backing handle. Its options are serialised into
// the resource's `$rbuf` string rather than parked as a nested array: an array
// built in the stdlib and handed back to user code (or reread later) does not
// survive the boundary — the same reason http_get_last_response_headers keeps a
// STRING. A length-prefixed encoding (`<len>|<bytes>`) is binary-safe, so a
// request body with embedded NULs/newlines round-trips intact.

/** `<decimal length>|<bytes>` — length-prefixed so any byte string is safe. */
function __mc_ctx_pack(string $s): string
{
    return (string)\strlen($s) . '|' . $s;
}

/**
 * Unpack the KIND_CONTEXT blob into [method, header, content, localCert, localPk,
 * verifyPeer, verifyName]. The two flags are '1'/'0' strings so the result is a
 * homogeneous string array (no cell), which callers turn back into bools.
 * @return string[]
 */
function __mc_ctx_unpack(string $blob): array
{
    $pos = 0;
    $n = \strlen($blob);
    /** @var string[] $out */
    $out = [];
    // Five length-prefixed strings: method, header, content, local_cert, local_pk.
    $i = 0;
    while ($i < 5) {
        $bar = \strpos($blob, '|', $pos);
        if ($bar === false) {
            $out[] = '';
            $i = $i + 1;
            continue;
        }
        $len = (int)\substr($blob, $pos, $bar - $pos);
        $out[] = \substr($blob, $bar + 1, $len);
        $pos = $bar + 1 + $len;
        $i = $i + 1;
    }
    // Two trailing flag chars.
    $out[] = ($pos < $n) ? $blob[$pos] : '1';
    $out[] = ($pos + 1 < $n) ? $blob[$pos + 1] : '1';
    return $out;
}

/**
 * Normalise a context 'header' option to a header block where each line ends
 * with CRLF. php accepts a string ("A: b\r\nC: d") or a string[] (["A: b", …]).
 * @param mixed $h
 */
function __mc_ctx_header_block($h): string
{
    if (\is_array($h)) {
        $block = '';
        foreach ($h as $line) {
            $l = (string)$line;
            if ($l !== '') { $block = $block . $l . "\r\n"; }
        }
        return $block;
    }
    $s = (string)$h;
    if ($s === '') {
        return '';
    }
    // A single string may already carry its own CRLFs; add a trailing one only
    // when it is missing so the request builder can just splice it in.
    if (\substr($s, \strlen($s) - 2, 2) === "\r\n") {
        return $s;
    }
    return $s . "\r\n";
}

/**
 * php's stream_context_create(). Only the option subset the HTTP/TLS client
 * honours is stored: http.method / http.header / http.content and
 * ssl.verify_peer / ssl.verify_peer_name. Everything else is accepted and
 * ignored (php does the same for options a wrapper does not implement).
 *
 * @param array<string,mixed>|null $options
 * @param array<string,mixed>|null $params
 */
function stream_context_create(?array $options = null, ?array $params = null): \Resource
{
    $method = 'GET';
    $header = '';
    $content = '';
    $localCert = '';
    $localPk = '';
    $verifyPeer = '1';
    $verifyName = '1';
    if ($options !== null) {
        if (isset($options['http']) && \is_array($options['http'])) {
            $h = $options['http'];
            if (isset($h['method'])) { $method = (string)$h['method']; }
            if (isset($h['header'])) { $header = \__mc_ctx_header_block($h['header']); }
            if (isset($h['content'])) { $content = (string)$h['content']; }
        }
        if (isset($options['ssl']) && \is_array($options['ssl'])) {
            $s = $options['ssl'];
            // Only an explicit `false` turns verification off; the default is on.
            if (isset($s['verify_peer']) && $s['verify_peer'] === false) { $verifyPeer = '0'; }
            if (isset($s['verify_peer_name']) && $s['verify_peer_name'] === false) { $verifyName = '0'; }
            // Server certificate/key (a TLS listener). local_pk defaults to
            // local_cert — a combined cert+key PEM, which php also accepts.
            if (isset($s['local_cert'])) { $localCert = (string)$s['local_cert']; }
            $localPk = $localCert;
            if (isset($s['local_pk'])) { $localPk = (string)$s['local_pk']; }
        }
    }
    $blob = \__mc_ctx_pack($method) . \__mc_ctx_pack($header) . \__mc_ctx_pack($content)
          . \__mc_ctx_pack($localCert) . \__mc_ctx_pack($localPk)
          . $verifyPeer . $verifyName;
    $r = new \Resource(\Resource::KIND_CONTEXT, 'stream-context', 0);
    $r->rbuf = $blob;
    return $r;
}

/**
 * The context-aware HTTP fetch. $context is \Resource-typed (unboxed) on purpose:
 * a `?\Resource` cell would deref the boxed handle when read. A non-context
 * resource is ignored, matching php's tolerance.
 * @return string|false
 */
function __mc_http_get_with_context(string $path, \Resource $context)
{
    if ($context->kind !== \Resource::KIND_CONTEXT) {
        return \__mc_http_get($path);
    }
    $o = \__mc_ctx_unpack($context->rbuf);
    // [0]=method [1]=header [2]=content, [3]=local_cert [4]=local_pk (server-only),
    // [5]=verify_peer [6]=verify_peer_name.
    return \__mc_http_get($path, 20, $o[0], $o[1], $o[2], $o[5] === '1', $o[6] === '1');
}

/**
 * A stream context's server cert + private key paths (ssl.local_cert /
 * ssl.local_pk), for a TLS listener. Empty strings when absent. $context is
 * \Resource-typed on purpose (a cell would deref the boxed handle).
 * @return string[]
 */
function __mc_ctx_server_certs(\Resource $context): array
{
    if ($context->kind !== \Resource::KIND_CONTEXT) {
        return ['', ''];
    }
    $o = \__mc_ctx_unpack($context->rbuf);
    return [$o[3], $o[4]];
}

/**
 * A stream context's ssl.verify_peer / ssl.verify_peer_name flags (blob slots
 * [5][6]), for a client STARTTLS. Both default true when absent or not a context.
 * $context is \Resource-typed on purpose (a cell would deref the boxed handle).
 * @return bool[]
 */
function __mc_ctx_verify_flags(\Resource $context): array
{
    if ($context->kind !== \Resource::KIND_CONTEXT) {
        return [true, true];
    }
    $o = \__mc_ctx_unpack($context->rbuf);
    return [$o[5] === '1', $o[6] === '1'];
}

/** Reassemble the 7-field context blob from its parts (twin of the create packer). */
function __mc_ctx_repack(string $method, string $header, string $content, string $cert, string $pk, string $vp, string $vn): string
{
    return \__mc_ctx_pack($method) . \__mc_ctx_pack($header) . \__mc_ctx_pack($content)
         . \__mc_ctx_pack($cert) . \__mc_ctx_pack($pk) . $vp . $vn;
}

/**
 * The options set on a context, as php's nested array. ⚠ BEST-EFFORT / lossy: the
 * context stores only the honored ssl/http subset (not arbitrary wrapper keys), and
 * with no "was set" marker it can only emit the NON-DEFAULT honored values — which
 * matches php for the common explicit sets (verify_peer=false, POST, headers, certs)
 * but omits an option a caller redundantly set to its default. Insertion order is
 * ssl-before-http (the storage order), not the caller's.
 * @return array<string,array<string,mixed>>
 */
function stream_context_get_options(\Resource $context): array
{
    /** @var array<string,array<string,mixed>> $out */
    $out = [];
    if ($context->kind !== \Resource::KIND_CONTEXT) {
        return $out;
    }
    $o = \__mc_ctx_unpack($context->rbuf);
    /** @var array<string,mixed> $ssl */
    $ssl = [];
    if ($o[5] === '0') { $ssl['verify_peer'] = false; }
    if ($o[6] === '0') { $ssl['verify_peer_name'] = false; }
    if ($o[3] !== '') { $ssl['local_cert'] = $o[3]; }
    if ($o[4] !== '' && $o[4] !== $o[3]) { $ssl['local_pk'] = $o[4]; }
    if (\count($ssl) > 0) { $out['ssl'] = $ssl; }
    /** @var array<string,mixed> $http */
    $http = [];
    if ($o[0] !== '' && $o[0] !== 'GET') { $http['method'] = $o[0]; }
    if ($o[1] !== '') { $http['header'] = $o[1]; }
    if ($o[2] !== '') { $http['content'] = $o[2]; }
    if (\count($http) > 0) { $out['http'] = $http; }
    return $out;
}

/**
 * Set one honored option on a context (or a whole ['ns'=>['k'=>v]] array), re-packing
 * the stored blob so a later STARTTLS / http fetch honors it. Non-honored keys are
 * accepted and ignored, matching php's tolerance. Returns true for a context.
 * @param string|array<string,mixed> $wrapper_or_options
 * @param mixed $value
 */
function stream_context_set_option(\Resource $context, $wrapper_or_options, ?string $option = null, $value = null): bool
{
    if ($context->kind !== \Resource::KIND_CONTEXT) {
        return false;
    }
    $o = \__mc_ctx_unpack($context->rbuf);
    $method = $o[0]; $header = $o[1]; $content = $o[2];
    $cert = $o[3]; $pk = $o[4]; $vp = $o[5]; $vn = $o[6];
    if (\is_array($wrapper_or_options)) {
        // The array form: ['ssl'=>['verify_peer'=>false,...], 'http'=>[...]].
        if (isset($wrapper_or_options['ssl']) && \is_array($wrapper_or_options['ssl'])) {
            $s = $wrapper_or_options['ssl'];
            if (isset($s['verify_peer'])) { $vp = $s['verify_peer'] === false ? '0' : '1'; }
            if (isset($s['verify_peer_name'])) { $vn = $s['verify_peer_name'] === false ? '0' : '1'; }
            if (isset($s['local_cert'])) { $cert = (string)$s['local_cert']; }
            if (isset($s['local_pk'])) { $pk = (string)$s['local_pk']; }
        }
        if (isset($wrapper_or_options['http']) && \is_array($wrapper_or_options['http'])) {
            $h = $wrapper_or_options['http'];
            if (isset($h['method'])) { $method = (string)$h['method']; }
            if (isset($h['header'])) { $header = \__mc_ctx_header_block($h['header']); }
            if (isset($h['content'])) { $content = (string)$h['content']; }
        }
    } else {
        // The (wrapper, option, value) form.
        $wrapper = (string)$wrapper_or_options;
        $opt = $option ?? '';
        if ($wrapper === 'ssl') {
            if ($opt === 'verify_peer') { $vp = $value === false ? '0' : '1'; }
            elseif ($opt === 'verify_peer_name') { $vn = $value === false ? '0' : '1'; }
            elseif ($opt === 'local_cert') { $cert = (string)$value; }
            elseif ($opt === 'local_pk') { $pk = (string)$value; }
        } elseif ($wrapper === 'http') {
            if ($opt === 'method') { $method = (string)$value; }
            elseif ($opt === 'header') { $header = \__mc_ctx_header_block($value); }
            elseif ($opt === 'content') { $content = (string)$value; }
        }
    }
    $context->rbuf = \__mc_ctx_repack($method, $header, $content, $cert, $pk, $vp, $vn);
    return true;
}

/**
 * Open a file. Returns a file resource, or false on failure.
 * @return \Resource|false
 */
function fopen(string $filename, string $mode)
{
    $scheme = \__mc_scheme_of($filename);
    if ($scheme === 'php') {
        // php://memory and php://temp are both an in-memory read-write file here
        // (php://temp spills to disk past 2 MB; we keep it in memory — behaviour is
        // identical up to that size). ⚠ php://stdin/out/err are NOT handled here:
        // a stdlib fn that mentions STDIN/STDOUT/STDERR makes the compiler's own
        // src/ emit the __mir_std* builtin (host_os() in the emitter → libc stub
        // under the Zend seed), which kills the cold bootstrap — see __mc_std_res.
        $lower = \strtolower($filename);
        if ($lower === 'php://memory' || \strpos($lower, 'php://temp') === 0) {
            return new \Resource(\Resource::KIND_MEMFILE, 'stream', 0);
        }
        return false;
    }
    if ($scheme === 'http' || $scheme === 'https') {
        // php's http(s):// fopen streams the response body. We fetch it whole on
        // open into a KIND_MEMORY stream — correct fread/fgets/feof semantics, and
        // the chunked/Content-Length decoding is the client's, not re-done here.
        // (True incremental streaming would need on-the-fly dechunking in fread.)
        $body = \__mc_http_get($filename);
        if ($body === false) {
            return false;
        }
        $r = new \Resource(\Resource::KIND_MEMORY, 'stream', 0);
        // (string) forces the unbox: __mc_http_get returns string|false (a CELL),
        // and storing the boxed value straight into the string field $rbuf would
        // leave later strpos/substr derefing a NaN-boxed value. Same erasure class
        // as the Ф4 field-write trap, on the store side.
        $r->rbuf = (string)$body;
        $r->eof = true;   // the buffer is all there is — see KIND_MEMORY
        return $r;
    }
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
    // TLS teardown lives here, not in Resource::close(): the prelude must not
    // reference OpenSSL (see \Resource::KIND_TLS). Send close_notify and free the
    // SSL engine BEFORE close() drops the fd; guard on $ssl so a double fclose is
    // a no-op. SSL_free does not close the fd — Resource::close() still does.
    if ($stream->kind === \Resource::KIND_TLS && $stream->ssl !== 0 && !$stream->closed) {
        \Runtime\Openssl\shutdown($stream->ssl);
        \Runtime\Openssl\sslFree($stream->ssl);
        $stream->ssl = 0;
    }
    // A KIND_SOCKET with $ssl set is a TLS LISTENER: $ssl is the shared server
    // SSL_CTX (not a connection), so free the ctx, not an SSL.
    if ($stream->kind === \Resource::KIND_SOCKET && $stream->ssl !== 0 && !$stream->closed) {
        \Runtime\Openssl\ctxFree($stream->ssl);
        $stream->ssl = 0;
    }
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
    if ($stream->kind === \Resource::KIND_MEMFILE) {
        // php://memory: overwrite $len bytes at the cursor, extending past the end;
        // the tail after the written span is preserved.
        // (int)$len: $len derives from the ?int $length param, so it types as a
        // cell; bare arithmetic (rpos = pos+len) would box the result and store
        // garbage into the int field. The socket/file paths never hit this because
        // they only pass $len across a coercing call boundary.
        $wl = (int)$len;
        $pos = $stream->rpos;
        $blen = \strlen($stream->rbuf);
        $before = $pos > 0 ? \substr($stream->rbuf, 0, $pos) : '';
        $after = ($pos + $wl < $blen) ? \substr($stream->rbuf, $pos + $wl, $blen - ($pos + $wl)) : '';
        $newBuf = $before . \substr($data, 0, $wl) . $after;
        $stream->rpos = $pos + $wl;
        $stream->rbuf = $newBuf;
        return $wl;
    }
    if ($stream->kind === \Resource::KIND_MEMORY) {
        // php's http(s):// stream accepts a write and reports the byte count even
        // though the body is not resent — the data is discarded here.
        return $len;
    }
    if (\__mc_stream_is_net($stream)) {
        // A socket's addr is an fd, so int_to_ptr() would hand fwrite a small
        // integer as a FILE*. send(2) — or SSL_write for TLS — instead, and a
        // short write is a real outcome here, not an error, so report what went out.
        $n = \__mc_transport_send($stream, $data, $len);
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
    if ($stream->kind === \Resource::KIND_MEMFILE) {
        // Read from the seek cursor WITHOUT compacting — a seek-back must still
        // find the earlier bytes.
        $avail = \strlen($stream->rbuf) - $stream->rpos;
        if ($avail <= 0) {
            return "";
        }
        $take = $length < $avail ? $length : $avail;
        $out = \substr($stream->rbuf, $stream->rpos, $take);
        $stream->rpos = $stream->rpos + $take;
        return $out;
    }
    $buf = \Runtime\Libc\calloc($length + 1, 1);
    if ($buf === null) {
        return "";
    }
    if (\__mc_stream_is_buffered($stream)) {
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
    if ($stream->kind === \Resource::KIND_MEMFILE) {
        // A line from the cursor, terminator included, capped at $cap-1 like php.
        $blen = \strlen($stream->rbuf);
        if ($stream->rpos >= $blen) {
            return false;
        }
        $at = \strpos($stream->rbuf, "\n", $stream->rpos);
        $end = $at === false ? $blen : $at + 1;
        if ($length !== null && $length > 1 && $end - $stream->rpos > $cap - 1) {
            $end = $stream->rpos + ($cap - 1);
        }
        $out = \substr($stream->rbuf, $stream->rpos, $end - $stream->rpos);
        $stream->rpos = $end;
        return $out;
    }
    $buf = \Runtime\Libc\calloc($cap + 1, 1);
    if ($buf === null) {
        return false;
    }
    if (\__mc_stream_is_buffered($stream)) {
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
    if ($stream->kind === \Resource::KIND_MEMFILE) {
        return $stream->rpos >= \strlen($stream->rbuf);
    }
    if (\__mc_stream_is_buffered($stream)) {
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
    if ($stream->kind === \Resource::KIND_MEMFILE) {
        $blen = \strlen($stream->rbuf);
        if ($whence === 1) { $new = $stream->rpos + $offset; }   // SEEK_CUR
        elseif ($whence === 2) { $new = $blen + $offset; }       // SEEK_END
        else { $new = $offset; }                                  // SEEK_SET
        if ($new < 0) {
            return -1;
        }
        if ($new > $blen) { $new = $blen; }   // no sparse writes here
        $stream->rpos = $new;
        return 0;
    }
    if (\__mc_stream_is_buffered($stream)) {
        return -1;   // php: a socket/http stream is not seekable
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
    if ($stream->kind === \Resource::KIND_MEMFILE) {
        return $stream->rpos;
    }
    if (\__mc_stream_is_buffered($stream)) {
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
    if ($stream->kind === \Resource::KIND_MEMFILE) {
        $stream->rpos = 0;
        return true;
    }
    if (\__mc_stream_is_buffered($stream)) {
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
    if (\__mc_stream_is_buffered($stream)) {
        return true;   // send(2)/memory is unbuffered — nothing to flush
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
