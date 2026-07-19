<?php

/**
 * A PHP resource handle.
 *
 * php has no writable `resource` type, so this is a real class in the global
 * namespace — the same call php itself is making as it migrates its resources
 * to objects.
 *
 * It lives in the PRELUDE, not in src/Runtime, because the stdlib .sig carries
 * FUNCTIONS ONLY: a class defined there is never registered by a user program,
 * so `$f instanceof Resource` read false in user code while the stdlib's own
 * is_resource() read true, and a returned handle's properties came back as raw
 * bits. The prelude is compiled into every module — exactly how the Throwable
 * hierarchy already crosses this same boundary. It exists so the public stdlib surface (`fopen`, `fread`, …) is
 * typed `\Resource` and NOT `\Ffi\Ptr`: a Ptr is a raw foreign address that is
 * deliberately excluded from refcounting, so it can carry no state, no type tag
 * and no destructor. Keeping it inside {@see Runtime\Libc} — strictly at the FFI
 * call boundary — is what lets a resource own its lifetime.
 *
 * The backing handle is held as a raw `int` ADDRESS rather than an `\Ffi\Ptr`
 * property: a Ptr-typed property would drag the foreign-handle special cases
 * back into every rc path that touches this object. It is converted with
 * `int_to_ptr` at the one place it is used — the libc call.
 *
 * `__destruct` closes an unclosed handle, so a dropped resource cannot leak an
 * fd. That only works because a `\Resource|false` local IS released — see
 * tests/aot/cases/cell_local_destruct.php; it was not until recently.
 */
// The PRELUDE MUST NOT DEPEND ON THE STDLIB. It is compiled into every module,
// including ones built with no stdlib beside them — so it declares the handful of
// libc entries it needs itself, exactly as `Manticore\Main` does for the compiler.
//
// Calling `\Runtime\Libc\fclose()` from here looked fine and was not: those
// bindings live in the stdlib, reachable only through `lib/manticore_stdlib.o.sig`.
// When the .sig is absent — a compiler binary relocated away from its `lib/`, which
// is exactly what `tools/selfhost_stability.sh` builds — the fully-qualified name
// SILENTLY degrades to the GLOBAL `fclose`, i.e. the stdlib's own
// `fclose(\Resource)`, and clang then dies on `use of undefined value
// '@manticore_fclose'`. Compiling ANY program broke, including `<?php echo "x";`.
//
// Names are `__mc_libc_*` and not `fclose`: the global namespace already holds the
// stdlib's `fclose(\Resource $stream)`, and colliding with it is how the bug above
// looked from the inside. `Symbol()` carries the real C name, so these emit a DIRECT
// call to libc (`U _fclose` in the binary) and need no stdlib at all.
// Attributes are fully qualified because the prelude is concatenated into ONE blob:
// there is nowhere to put a `use`.

#[\Ffi\Library('c'), \Ffi\Symbol('fclose')]
function __mc_libc_fclose(#[\Ffi\Take] \Ffi\Ptr $stream): int {}

#[\Ffi\Library('c'), \Ffi\Symbol('closedir')]
function __mc_libc_closedir(#[\Ffi\Take] \Ffi\Ptr $dir): int {}

#[\Ffi\Library('c'), \Ffi\Symbol('close')]
function __mc_libc_close(#[\Ffi\CType('int')] int $fd): int {}

/**
 * Name for the OBJECT arm of `gettype()` / `get_debug_type()`.
 *
 * php insists a resource is not an object, so `gettype($fh)` is "resource" and
 * `get_debug_type($fh)` is "resource (stream)" — but to us a \Resource IS an
 * object, and the cell path of {@see EmitLlvmBuiltins::biGettype} only sees tag
 * 8. Telling them apart needs a runtime CLASS check, which is exactly what a
 * prelude fn can do and a tag-select chain cannot.
 *
 * Safe to call on ANY cell (the select that consumes it evaluates both arms):
 * `instanceof` on an int/string cell is simply false.
 *
 * A closed resource reports "resource (closed)" from BOTH functions — verified
 * against php 8.5.
 */
function __mir_obj_type_name(mixed $v, bool $debug): string
{
    if ($v instanceof \Resource) {
        if ($v->closed) {
            return 'resource (closed)';
        }
        if ($debug) {
            return 'resource (' . $v->type . ')';
        }
        return 'resource';
    }
    // get_debug_type() of an object is its class name. A statically-typed object
    // gets the name folded in biGettype, but a Socket/AddressInfo arriving as a
    // CELL (the `socket_create(): \Socket|false` union) has no static class here,
    // and get_class() on a mixed value reads the STATIC type (empty) — so the
    // runtime class must be recovered by an instanceof probe, the same way
    // \Resource is above. (A fully general cell→class-name needs a runtime
    // class-id→name table the runtime does not carry; that is a pre-existing gap
    // for any object in a union, not specific to sockets.)
    if ($debug) {
        if ($v instanceof \Socket) {
            return 'Socket';
        }
        if ($v instanceof \AddressInfo) {
            return 'AddressInfo';
        }
    }
    return 'object';
}

final class Resource
{
    /**
     * Backend kinds — they decide what `$addr` MEANS, so every read of it has to
     * dispatch on this first. FILE and DIR hold a libc `FILE*`/`DIR*`; a SOCKET
     * holds a raw **fd**, an int, not a pointer.
     *
     * That difference is why the socket path does not go through stdio: wrapping
     * the fd with `fdopen()` would let the whole f* family work unchanged, but
     * stdio buffering on a socket fights poll() timeouts and non-blocking reads,
     * and the HTTP layer above would inherit that.
     */
    public const KIND_FILE = 0;
    public const KIND_DIR = 1;
    public const KIND_SOCKET = 2;
    /**
     * A TLS stream: `$addr` is the TCP fd (as for SOCKET), and `$ssl` holds the
     * OpenSSL `SSL*`. Reads/writes go through SSL_read/SSL_write, not recv/send.
     *
     * The prelude MUST NOT depend on OpenSSL — it is compiled into every module,
     * and referencing SSL_free here would drag libssl into a hello-world. So the
     * SSL engine is torn down by the stdlib's `fclose()` ({@see \fclose}); the
     * prelude's own close()/__destruct closes only the fd. A TLS resource dropped
     * WITHOUT fclose() therefore leaks the SSL* (not the fd) — documented debt,
     * and not the path file_get_contents/fclose take.
     */
    public const KIND_TLS = 3;
    /**
     * A stream context (php's `resource(stream-context)`), from
     * stream_context_create(). It has NO backing handle — `$addr` is 0, so
     * close()/__destruct are no-ops — and it never does IO. Its serialized options
     * (http method/header/content, ssl verify flags) are parked in `$rbuf`, the
     * one string field a context has no other use for. See {@see \stream_context_create}.
     */
    public const KIND_CONTEXT = 4;
    /**
     * An in-memory read stream: no fd (`$addr` 0), all bytes pre-loaded in `$rbuf`
     * with `$eof` set true from birth — "the buffer is all there is". fopen() on an
     * http(s):// URL returns one (the whole body, fetched on open), and the
     * existing buffered readers (fread/fgets/feof) drain it with no socket fill.
     * Currently NON-seekable (it backs a network body, which php makes non-seekable);
     * a future php://memory / php://temp is the same kind made seekable.
     */
    public const KIND_MEMORY = 5;
    /**
     * php://memory / php://temp — a read-WRITE, SEEKABLE in-memory file. Unlike
     * KIND_MEMORY (a read-only network body that drains and compacts), the bytes
     * live in `$rbuf` in full and `$rpos` is a real seek cursor: fwrite overwrites/
     * extends at the cursor, fseek moves it, and nothing is ever compacted away (a
     * seek-back must find the earlier bytes). `$addr` is 0 (no handle).
     */
    public const KIND_MEMFILE = 6;


    /** php numbers resources from 1 and never reuses an id within a run. */
    private static int $nextId = 1;

    public int $id;
    /** php.net's get_resource_type(). NOTE: a DIR is "stream" too, not "dir". */
    public string $type;
    public int $kind;
    /** Raw address of the backing handle (FILE* / DIR*), 0 once closed. */
    public int $addr;
    public bool $closed;
    /**
     * A borrowed handle we must never close: STDIN/STDOUT/STDERR are libc's own
     * globals, not ours. Without this a dropped STDOUT resource would fclose the
     * real stdout.
     */
    public bool $persistent;
    /**
     * SOCKET only: the peer has closed (recv returned 0).
     *
     * A FILE* carries its own EOF flag and feof() reads it; a bare fd does not,
     * so the one place that can observe the zero-length read has to record it.
     * Without this, feof() on a socket would answer from a FILE* that is not there.
     */
    public bool $eof = false;
    /**
     * SOCKET read buffer — raw bytes plus a cursor. Owned by the stdlib
     * ({@see __mc_stream_read}); nothing here touches them.
     *
     * PLAIN FIELDS, not a `ByteBuffer` object, and that is forced rather than
     * chosen: the prelude MUST NOT depend on the stdlib (it is compiled into
     * modules that have no stdlib beside them), and a stdlib CLASS is invisible to
     * a user module anyway — the `.sig` carries functions only, so an object of one
     * arrives untyped and reads back as raw bits. Putting a real ByteBuffer here
     * would mean putting it in the PRELUDE, i.e. paying its IR in every program
     * that ever says `echo` (\Resource's own three methods already cost 276 lines
     * of a trivial program's 5220).
     *
     * $rpos is not an optimisation: without a cursor every consume is a
     * `substr($rbuf, $n)` — a full copy of the remainder — which is quadratic over
     * a response read in chunks.
     */
    public string $rbuf = '';
    public int $rpos = 0;
    /** KIND_TLS only: the OpenSSL `SSL*`, 0 otherwise. See KIND_TLS. */
    public int $ssl = 0;
    /**
     * SOCKET/TLS read timeout in ms; 0 = the default (php's default_socket_timeout,
     * 60s). A hung peer must not block a read forever — every fill waits at most
     * this long. Set via stream_set_timeout(). `$timedOut` records that the last
     * read hit the deadline (surfaced by stream_get_meta_data()).
     */
    public int $rtimeoutMs = 0;
    public bool $timedOut = false;

    public function __construct(int $kind, string $type, int $addr, bool $persistent = false)
    {
        $this->id = self::$nextId;
        self::$nextId = self::$nextId + 1;
        $this->kind = $kind;
        $this->type = $type;
        $this->addr = $addr;
        $this->closed = false;
        $this->persistent = $persistent;
    }

    /**
     * Close the underlying handle. Idempotent: php lets fclose() run once and
     * then reports the resource as closed, so a later __destruct must not
     * double-close (a second fclose on a freed FILE* is undefined behaviour).
     */
    public function close(): bool
    {
        if ($this->closed || $this->addr === 0 || $this->persistent) {
            return false;
        }
        $ok = false;
        if ($this->kind === self::KIND_FILE) {
            $ok = \__mc_libc_fclose(\int_to_ptr($this->addr)) === 0;
        } elseif ($this->kind === self::KIND_DIR) {
            $ok = \__mc_libc_closedir(\int_to_ptr($this->addr)) === 0;
        } elseif ($this->kind === self::KIND_SOCKET || $this->kind === self::KIND_TLS) {
            // A socket's $addr is an fd, not a FILE* — close(2), and no
            // int_to_ptr. Passing it to fclose() would treat the small integer
            // as a pointer. For a TLS stream the SSL* is freed by the stdlib's
            // fclose(); here we only close the fd (see KIND_TLS).
            $ok = \__mc_libc_close($this->addr) === 0;
        }
        $this->closed = true;
        $this->addr = 0;
        // php reports a closed resource as type "Unknown" — var_dump prints
        // `resource(5) of type (Unknown)` and is_resource() turns false.
        $this->type = 'Unknown';
        return $ok;
    }

    /** RAII: a resource that goes out of scope closes itself, as php's does. */
    public function __destruct()
    {
        if (!$this->closed && !$this->persistent && $this->addr !== 0) {
            $this->close();
        }
    }
}

/**
 * The handle ext/sockets hands back — php 8's opaque `Socket` OBJECT, not a
 * `resource`. It is a distinct class from {@see Resource} on purpose: php migrated
 * ext/sockets off resources onto this class, so `is_resource($s)` is false,
 * `gettype($s)` is "object" and `get_debug_type($s)` is "Socket". Modelling it as a
 * \Resource would report all three the resource way and break difftest parity.
 *
 * It lives in the PRELUDE for the same reason \Resource does: the stdlib `.sig`
 * carries functions only, so a class defined in src/Runtime is invisible to user
 * code (`instanceof` false, fields read back as raw bits). Compiled into every
 * module.
 *
 * `$fd` is a raw int fd (never a pointer), PUBLIC so the stdlib's `__mc_sock_fd()`
 * funnel can read it off a plainly-typed \Socket param — a global function cannot
 * touch a private, and reading a field off a `\Socket|false` cell would deref the
 * NaN box (the recurring cell-erasure trap), so every field access goes through a
 * \Socket-typed parameter. Unlike \Resource there is no stream read-buffer: the
 * socket_* API reads/writes the fd DIRECTLY (recv/send), so none of the buffered
 * f*-family machinery applies.
 *
 * KNOWN divergence (documented debt): php's Socket exposes ZERO php-visible
 * properties, so `var_dump($s)` prints `object(Socket)#N (0) {}`. Ours shows `$fd`
 * (a global function needs it public). Unavoidable — php's is a true internal
 * opaque class. Every other introspection is byte-identical.
 */
final class Socket
{
    public int $fd;
    public bool $closed = false;
    /**
     * The address family / type / protocol the socket was created with. php's
     * internal Socket tracks these; socket_bind/connect/sendto need the family to
     * pick sockaddr_in vs in6 vs un, and socket_accept inherits them.
     */
    public int $family = 0;
    public int $type = 0;
    public int $proto = 0;
    /**
     * The last errno seen on THIS socket — socket_last_error($sock) reports it,
     * socket_clear_error($sock) zeroes it (php tracks a per-socket error as well
     * as a global one).
     */
    public int $lastErr = 0;

    public function __construct(int $fd, int $family = 0, int $type = 0, int $proto = 0)
    {
        $this->fd = $fd;
        $this->family = $family;
        $this->type = $type;
        $this->proto = $proto;
    }

    /**
     * Idempotent close, as php's socket_close / Socket destructor. A socket fd is
     * closed with close(2) — never fclose(), which would treat the small int as a
     * FILE*.
     */
    public function close(): bool
    {
        if ($this->closed || $this->fd < 0) {
            return false;
        }
        $ok = \__mc_libc_close($this->fd) === 0;
        $this->closed = true;
        $this->fd = -1;
        return $ok;
    }

    /** RAII: php 8 closes a Socket when it is destroyed. */
    public function __destruct()
    {
        if (!$this->closed && $this->fd >= 0) {
            $this->close();
        }
    }
}

/**
 * The handle `socket_addrinfo_lookup()` returns and the *_connect/_bind/_explain
 * calls consume — php 8's opaque `AddressInfo` object. It holds one resolved
 * `struct addrinfo` entry flattened into fields plus the raw sockaddr bytes, so
 * *_connect/_bind can build a socket and connect/bind without re-resolving, and
 * *_explain can hand back the php-shaped array. Prelude-resident for the same
 * reason as \Socket.
 */
final class AddressInfo
{
    public int $family;
    public int $socktype;
    public int $protocol;
    /** Raw sockaddr bytes (binary-safe string) + its length. */
    public string $addr;
    public int $addrlen;

    public function __construct(int $family, int $socktype, int $protocol, string $addr, int $addrlen)
    {
        $this->family = $family;
        $this->socktype = $socktype;
        $this->protocol = $protocol;
        $this->addr = $addr;
        $this->addrlen = $addrlen;
    }
}
