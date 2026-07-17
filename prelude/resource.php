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
            $ok = \Runtime\Libc\fclose(\int_to_ptr($this->addr)) === 0;
        } elseif ($this->kind === self::KIND_DIR) {
            $ok = \Runtime\Libc\sys_closedir(\int_to_ptr($this->addr)) === 0;
        } elseif ($this->kind === self::KIND_SOCKET) {
            // A socket's $addr is an fd, not a FILE* — close(2), and no
            // int_to_ptr. Passing it to fclose() would treat the small integer
            // as a pointer.
            $ok = \Runtime\Libc\sys_close($this->addr) === 0;
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
