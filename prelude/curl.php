<?php

/**
 * ext/curl — the easy API, bound to libcurl through FFI.
 *
 * WHY THE PRELUDE AND NOT src/Runtime/Stdlib: the stdlib `.o.sig` carries
 * FUNCTIONS ONLY. `curl_init()` returns a CurlHandle, and a class declared in
 * the stdlib is never registered by the program holding one of its instances —
 * `instanceof` reads false and its properties come back as raw bits. Same call
 * ext/simplexml and ext/dom made. See src/Runtime/Stdlib/README.md.
 *
 * WHY LIBCURL IS A GOOD FFI TARGET: the easy API is all-function over an opaque
 * `CURL*`. Nothing here dereferences a libcurl struct except curl_multi's
 * CURLMsg and curl_version_info's data block, both of which are ABI-frozen and
 * both of which carry a runtime self-check. `\Ffi\Ptr` has no `read*` family, so
 * a struct-walking C API would mean hard-coded offsets everywhere; this one
 * needs none.
 *
 * THE CALLBACK PROBLEM AND ITS ANSWER: `fn_to_ptr()` needs a STRING LITERAL
 * function name — the address is a relocation resolved at compile time, not a
 * runtime lookup — but a PHP program hands `CURLOPT_WRITEFUNCTION` a Closure.
 * So libcurl never sees the user's callback. It sees one of four FIXED
 * trampolines, and the `void*` it carries alongside (CURLOPT_WRITEDATA and
 * friends) is our handle **id**, an integer libcurl never dereferences. The
 * trampoline recovers the id, looks the PHP callable up in {@see __McCurl}, and
 * calls it. Four literals, four trampolines, any number of closures.
 *
 * ⚠ NOTHING MAY THROW OUT OF A TRAMPOLINE. A `throw` longjmps to the nearest
 * PHP `try`, which sits ABOVE libcurl's own frames — its transfer state is left
 * half-updated and its buffers are never unwound. A user callback's exception is
 * CAUGHT, parked in {@see __McCurl::$cbErr}, and signalled to libcurl by
 * returning a byte count that differs from what it asked for (which is
 * CURLE_WRITE_ERROR). `curl_exec()` then rethrows it on OUR stack.
 *
 * Attributes are fully qualified: the global-namespace prelude is concatenated
 * into ONE blob, so there is nowhere to put a `use`.
 */

// ── FFI: libcurl ────────────────────────────────────────────────────────────

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_global_init'), \Ffi\CType('int')]
function __mc_curl_global_init(#[\Ffi\CType('long')] int $flags): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_init')]
function __mc_curl_easy_init(): \Ffi\Ptr {}

/**
 * `CURLcode curl_easy_setopt(CURL *handle, CURLoption option, ...)`
 *
 * ONE binding carries every option class. CURLoption and CURLcode are enums, so
 * both are a C `int` — hence the FUNCTION-level `#[CType('int')]`, without which
 * a returned -1 reads back as 4294967295.
 *
 * The vararg is declared `long`. On LP64 a long, a `char*`, a `void*` and a
 * function pointer all ride one 8-byte slot, and the vararg's type never enters
 * the emitted `declare` (only `i32 (ptr, i32, ...)`) — so one declaration really
 * does serve all four, which matters because the emitter allows exactly one
 * signature per C symbol.
 *
 * `#[Variadic(2)]` marks handle+option as the NAMED parameters. Without it the
 * third argument is passed in a register, and Darwin arm64 reads variadic
 * arguments off the STACK — the callee would see garbage. Same rationale as
 * {@see Runtime\Libc\sys_fcntl}.
 */
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_setopt'), \Ffi\Variadic(2), \Ffi\CType('int')]
function __mc_curl_easy_setopt(\Ffi\Ptr $h, #[\Ffi\CType('int')] int $opt,
                               #[\Ffi\CType('long')] int $val): int {}

/** The third argument is ALWAYS an out-pointer here, so it is declared \Ffi\Ptr. */
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_getinfo'), \Ffi\Variadic(2), \Ffi\CType('int')]
function __mc_curl_easy_getinfo(\Ffi\Ptr $h, #[\Ffi\CType('int')] int $info, \Ffi\Ptr $out): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_perform'), \Ffi\CType('int')]
function __mc_curl_easy_perform(\Ffi\Ptr $h): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_cleanup')]
function __mc_curl_easy_cleanup(\Ffi\Ptr $h): void {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_reset')]
function __mc_curl_easy_reset(\Ffi\Ptr $h): void {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_duphandle')]
function __mc_curl_easy_duphandle(\Ffi\Ptr $h): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_strerror')]
function __mc_curl_easy_strerror(#[\Ffi\CType('int')] int $code): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_escape')]
function __mc_curl_easy_escape(\Ffi\Ptr $h, string $s, #[\Ffi\CType('int')] int $len): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_unescape')]
function __mc_curl_easy_unescape(\Ffi\Ptr $h, string $s, #[\Ffi\CType('int')] int $len,
                                 \Ffi\Ptr $outlen): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_pause'), \Ffi\CType('int')]
function __mc_curl_easy_pause(\Ffi\Ptr $h, #[\Ffi\CType('int')] int $bitmask): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_upkeep'), \Ffi\CType('int')]
function __mc_curl_easy_upkeep(\Ffi\Ptr $h): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_version_info')]
function __mc_curl_version_info(#[\Ffi\CType('int')] int $age): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_slist_append')]
function __mc_curl_slist_append(\Ffi\Ptr $list, string $s): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_slist_free_all')]
function __mc_curl_slist_free_all(\Ffi\Ptr $list): void {}

/**
 * libcurl's own deallocator. A block from curl_easy_escape() came out of
 * libcurl's allocator, which is NOT required to be libc's — handing it to
 * `free()` is undefined, and with the small-object pool in play it aborts.
 */
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_free')]
function __mc_curl_free_ptr(\Ffi\Ptr $p): void {}

// ── FFI: libc ───────────────────────────────────────────────────────────────
//
// The PRELUDE MUST NOT DEPEND ON THE STDLIB — it is compiled into every module,
// including ones built with no stdlib beside them. So the two libc entries this
// file needs are declared here, with `__mc_curl_` names so they cannot collide
// with the stdlib's own global functions. The SIGNATURES must match
// src/Runtime/Libc.php exactly: every binding of one C symbol has to agree or
// the emitter rejects the module.

#[\Ffi\Library('c'), \Ffi\Symbol('calloc'), \Ffi\Give]
function __mc_curl_calloc(#[\Ffi\CType('size_t')] int $n, #[\Ffi\CType('size_t')] int $sz): \Ffi\Ptr {}

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function __mc_curl_free(#[\Ffi\Take] \Ffi\Ptr $p): void {}

#[\Ffi\Library('c'), \Ffi\Symbol('memcpy')]
function __mc_curl_memcpy(\Ffi\Ptr $dst, \Ffi\Ptr $src, #[\Ffi\CType('size_t')] int $n): \Ffi\Ptr {}

// ── The side table ──────────────────────────────────────────────────────────

/**
 * Per-handle state, as flat parallel arrays keyed by handle id.
 *
 * Deliberately statics and NOT properties on CurlHandle: a trampoline is a plain
 * global function reached from a C frame holding nothing but an integer, and a
 * static-property read is the one lookup that needs no object in hand.
 *
 * ⚠ Ids start at 1 and every array here stays ASSOC-shaped. `unset()` on an
 * element of a packed (vec) array is a silent no-op, so a row would never
 * actually be dropped — the same trap {@see Process\Supervisor::forget} works
 * around.
 */
final class __McCurl
{
    /**
     * Where a handle's body goes. php does NOT layer these — it keeps ONE
     * method per handle and the LAST of CURLOPT_RETURNTRANSFER / CURLOPT_FILE /
     * CURLOPT_WRITEFUNCTION to be set wins outright. That is observable:
     * setting RETURNTRANSFER *and* a WRITEFUNCTION makes curl_exec() answer
     * `true`, not the (empty) buffer, because the buffer was never the sink.
     */
    public const W_STDOUT = 0;
    public const W_RETURN = 1;
    public const W_FILE   = 2;
    public const W_USER   = 3;

    /** Never 0: 0 is the id an uninitialised void* would carry. */
    public static int $nextId = 1;
    public static bool $globalInit = false;

    /** @var array<int,int> id => CURL* address, 0 once cleaned up */
    public static array $addr = [];
    /** @var array<int,mixed> id => the CurlHandle/CurlMultiHandle/CurlShareHandle object */
    public static array $obj = [];

    /** @var array<int,mixed> id => CURLOPT_WRITEFUNCTION callable */
    public static array $writeCb = [];
    /** @var array<int,mixed> id => CURLOPT_HEADERFUNCTION callable */
    public static array $headerCb = [];
    /** @var array<int,mixed> id => CURLOPT_READFUNCTION callable */
    public static array $readCb = [];
    /** @var array<int,mixed> id => CURLOPT_XFERINFOFUNCTION / PROGRESSFUNCTION callable */
    public static array $progressCb = [];

    /** @var array<int,string> id => body accumulated while the method is W_RETURN */
    public static array $body = [];
    /** @var array<int,int> id => one of the W_* methods above */
    public static array $writeMethod = [];
    /** @var array<int,int> id => the same, for headers (W_USER / W_FILE / W_STDOUT) */
    public static array $headerMethod = [];
    /**
     * @var array<int,bool> id => CURLOPT_HEADER.
     *
     * libcurl routes header data to the write callback ONLY when no header
     * callback is installed — and ours always is, because it is what carries a
     * user's CURLOPT_HEADERFUNCTION. So the "headers belong to the body" half of
     * CURLOPT_HEADER is ours to honour, in the header trampoline.
     */
    public static array $inclHeader = [];
    /** @var array<int,mixed> id => CURLOPT_FILE stream (a \Resource) */
    public static array $sink = [];
    /** @var array<int,mixed> id => CURLOPT_WRITEHEADER stream (a \Resource) */
    public static array $hdrSink = [];
    /** @var array<int,mixed> id => CURLOPT_INFILE stream (a \Resource) */
    public static array $src = [];

    /** @var array<int,array<int,int>> id => CURLOPT_* => curl_slist* head address */
    public static array $slists = [];
    /** @var array<int,array<int,string>> id => CURLOPT_* => the string we pinned */
    public static array $optString = [];
    /** @var array<int,array<int,int>> id => CURLOPT_* => the long we set */
    public static array $optLong = [];

    /** @var array<int,int> id => calloc'd CURL_ERROR_SIZE buffer address */
    public static array $errBuf = [];
    /** @var array<int,int> id => last CURLcode */
    public static array $errno = [];
    /** @var array<int,mixed> id => a Throwable a callback could not throw through C */
    public static array $cbErr = [];
    /**
     * @var array<int,array<int,int>> multi id => the easy ids currently added.
     *
     * Declared here rather than in curl_multi.php so that file adds no state of
     * its own — and read by {@see __mc_curl_release}, which has to work whether
     * or not the multi half was demanded.
     */
    public static array $multiKids = [];
    /** @var array<int,mixed> easy id => the CurlShareHandle set as CURLOPT_SHARE */
    public static array $share = [];
}

// ── Trampolines ─────────────────────────────────────────────────────────────

/** Invoke any callable shape with two arguments (write / header callbacks). */
function __mc_curl_call2(mixed $cb, mixed $a, mixed $b): mixed
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        return $o->$m($a, $b);
    }
    return $cb($a, $b);
}

/** Invoke any callable shape with three arguments (the read callback). */
function __mc_curl_call3(mixed $cb, mixed $a, mixed $b, mixed $c): mixed
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        return $o->$m($a, $b, $c);
    }
    return $cb($a, $b, $c);
}

/** Invoke any callable shape with five arguments (the progress callback). */
function __mc_curl_call5(mixed $cb, mixed $a, mixed $b, mixed $c, mixed $d, mixed $e): mixed
{
    if (\is_array($cb)) {
        $o = $cb[0];
        $m = $cb[1];
        return $o->$m($a, $b, $c, $d, $e);
    }
    return $cb($a, $b, $c, $d, $e);
}

/**
 * Write one chunk to wherever this handle's output goes: a user callback, the
 * RETURNTRANSFER buffer, a CURLOPT_FILE stream, or stdout — php's four, in php's
 * order of precedence.
 *
 * Returns the number of bytes accepted, which is what both write trampolines
 * hand back to libcurl. Anything other than the full count aborts the transfer
 * with CURLE_WRITE_ERROR (23).
 */
function __mc_curl_sink_write(int $id, string $s, int $n): int
{
    $m = isset(__McCurl::$writeMethod[$id]) ? __McCurl::$writeMethod[$id] : __McCurl::W_STDOUT;
    if ($m === __McCurl::W_RETURN) {
        if (isset(__McCurl::$body[$id])) {
            __McCurl::$body[$id] = __McCurl::$body[$id] . $s;
        } else {
            __McCurl::$body[$id] = $s;
        }
        return $n;
    }
    if ($m === __McCurl::W_FILE && isset(__McCurl::$sink[$id])) {
        $fh = __McCurl::$sink[$id];
        if ($fh instanceof \Resource) {
            \fwrite($fh, $s);
            return $n;
        }
    }
    echo $s;
    return $n;
}

/**
 * `size_t write_cb(char *buffer, size_t size, size_t nitems, void *outstream)`
 *
 * ⚠ Nothing may throw out of here — see the file header. A user callback's
 * Throwable is parked and signalled by returning a short count.
 */
function __mc_curl_write_tramp(\Ffi\Ptr $buf, int $size, int $nmemb, \Ffi\Ptr $ud): int
{
    $n = $size * $nmemb;
    if ($n <= 0) { return 0; }
    $id = \ptr_to_int($ud);
    // COPY OUT FIRST. $buf is libcurl's own block and carries no rc header, so
    // it has to become a real headered string before any PHP code touches it —
    // otherwise the first rc_release walks off a header that was never there.
    $s = \str_from_buffer($buf, $n);

    $m = isset(__McCurl::$writeMethod[$id]) ? __McCurl::$writeMethod[$id] : __McCurl::W_STDOUT;
    if ($m === __McCurl::W_USER && isset(__McCurl::$writeCb[$id])) {
        $cb = __McCurl::$writeCb[$id];
        try {
            $r = \__mc_curl_call2($cb, __McCurl::$obj[$id], $s);
            // php treats a non-int return as "all of it was written".
            if (\is_int($r)) { return $r; }
            return $n;
        } catch (\Throwable $e) {
            __McCurl::$cbErr[$id] = $e;
            return -1;
        }
    }
    return \__mc_curl_sink_write($id, $s, $n);
}

/**
 * `size_t header_cb(char *buffer, size_t size, size_t nitems, void *userdata)`
 *
 * Precedence, matching php: a user HEADERFUNCTION, else the CURLOPT_WRITEHEADER
 * stream, else the body when CURLOPT_HEADER is on, else discarded.
 */
function __mc_curl_header_tramp(\Ffi\Ptr $buf, int $size, int $nmemb, \Ffi\Ptr $ud): int
{
    $n = $size * $nmemb;
    if ($n <= 0) { return 0; }
    $id = \ptr_to_int($ud);
    $s = \str_from_buffer($buf, $n);

    $hm = isset(__McCurl::$headerMethod[$id]) ? __McCurl::$headerMethod[$id] : __McCurl::W_STDOUT;
    if ($hm === __McCurl::W_USER && isset(__McCurl::$headerCb[$id])) {
        $cb = __McCurl::$headerCb[$id];
        try {
            $r = \__mc_curl_call2($cb, __McCurl::$obj[$id], $s);
            if (\is_int($r)) { return $r; }
            return $n;
        } catch (\Throwable $e) {
            __McCurl::$cbErr[$id] = $e;
            return -1;
        }
    }
    if ($hm === __McCurl::W_FILE && isset(__McCurl::$hdrSink[$id])) {
        $fh = __McCurl::$hdrSink[$id];
        if ($fh instanceof \Resource) {
            \fwrite($fh, $s);
            return $n;
        }
    }
    if (isset(__McCurl::$inclHeader[$id]) && __McCurl::$inclHeader[$id]) {
        return \__mc_curl_sink_write($id, $s, $n);
    }
    return $n;
}

/**
 * `size_t read_cb(char *buffer, size_t size, size_t nitems, void *instream)`
 *
 * The one trampoline that WRITES INTO libcurl's buffer. The PHP callback returns
 * a string; we copy at most size*nitems bytes of it in and report the count. 0
 * means EOF, which is how an upload ends.
 */
function __mc_curl_read_tramp(\Ffi\Ptr $buf, int $size, int $nmemb, \Ffi\Ptr $ud): int
{
    $want = $size * $nmemb;
    if ($want <= 0) { return 0; }
    $id = \ptr_to_int($ud);

    if (isset(__McCurl::$readCb[$id])) {
        $cb = __McCurl::$readCb[$id];
        try {
            $stream = isset(__McCurl::$src[$id]) ? __McCurl::$src[$id] : null;
            $r = \__mc_curl_call3($cb, __McCurl::$obj[$id], $stream, $want);
            if (!\is_string($r)) { return 0; }
            $len = \strlen($r);
            if ($len === 0) { return 0; }
            if ($len > $want) { $len = $want; }
            // str_bytes() is the raw data address of a real PHP string; memcpy
            // straight into libcurl's block is the whole transfer.
            \__mc_curl_memcpy($buf, \int_to_ptr(\str_bytes($r)), $len);
            return $len;
        } catch (\Throwable $e) {
            __McCurl::$cbErr[$id] = $e;
            // CURL_READFUNC_ABORT — the sanctioned "stop now" from a reader.
            return 268435456;
        }
    }

    // No callback: read from CURLOPT_INFILE, php's default uploader.
    if (isset(__McCurl::$src[$id])) {
        $fh = __McCurl::$src[$id];
        if ($fh instanceof \Resource) {
            $chunk = \fread($fh, $want);
            $len = \strlen($chunk);
            if ($len === 0) { return 0; }
            \__mc_curl_memcpy($buf, \int_to_ptr(\str_bytes($chunk)), $len);
            return $len;
        }
    }
    return 0;
}

/**
 * `int xferinfo_cb(void *clientp, curl_off_t dltotal, curl_off_t dlnow,
 *                  curl_off_t ultotal, curl_off_t ulnow)`
 *
 * curl_off_t is 64-bit on every LP64 target, so it rides the uniform i64 ABI
 * with no truncation. A non-zero return aborts with CURLE_ABORTED_BY_CALLBACK.
 */
function __mc_curl_xferinfo_tramp(\Ffi\Ptr $ud, int $dlt, int $dln, int $ult, int $uln): int
{
    $id = \ptr_to_int($ud);
    if (!isset(__McCurl::$progressCb[$id])) { return 0; }
    $cb = __McCurl::$progressCb[$id];
    try {
        $r = \__mc_curl_call5($cb, __McCurl::$obj[$id], $dlt, $dln, $ult, $uln);
        if (\is_int($r)) { return $r; }
        return 0;
    } catch (\Throwable $e) {
        __McCurl::$cbErr[$id] = $e;
        return 1;
    }
}

// ── Handles ─────────────────────────────────────────────────────────────────

final class CurlHandle
{
    /**
     * The `CURL*` as a raw int ADDRESS. Not an `\Ffi\Ptr` property: a Ptr is a
     * foreign address deliberately excluded from refcounting, and holding one in
     * a property drags those exclusions into every rc path that touches this
     * object. Converted with int_to_ptr at the one place it is used.
     */
    public int $addr = 0;
    /**
     * Key into {@see __McCurl}, and ALSO the `void*` handed to libcurl as
     * CURLOPT_WRITEDATA / HEADERDATA / READDATA / XFERINFODATA. libcurl never
     * dereferences it — it only hands it back — which is what lets a fixed
     * trampoline find this handle's PHP closures again.
     */
    public int $id = 0;
    public bool $closed = false;

    /** A dropped handle must not leak libcurl's connection cache or our slists. */
    public function __destruct()
    {
        if (!$this->closed && $this->addr !== 0) {
            \__mc_curl_easy_cleanup(\int_to_ptr($this->addr));
            \__mc_curl_release($this->id);
            $this->closed = true;
            $this->addr = 0;
        }
    }
}

/** Free every foreign allocation a handle owns, then drop its table rows. */
function __mc_curl_release(int $id): void
{
    if (isset(__McCurl::$slists[$id])) {
        $chains = __McCurl::$slists[$id];
        foreach ($chains as $head) {
            if ($head !== 0) { \__mc_curl_slist_free_all(\int_to_ptr($head)); }
        }
    }
    if (isset(__McCurl::$errBuf[$id])) {
        $eb = __McCurl::$errBuf[$id];
        if ($eb !== 0) { \__mc_curl_free(\int_to_ptr($eb)); }
    }
    unset(__McCurl::$addr[$id]);
    unset(__McCurl::$obj[$id]);
    unset(__McCurl::$writeCb[$id]);
    unset(__McCurl::$headerCb[$id]);
    unset(__McCurl::$readCb[$id]);
    unset(__McCurl::$progressCb[$id]);
    unset(__McCurl::$body[$id]);
    unset(__McCurl::$writeMethod[$id]);
    unset(__McCurl::$headerMethod[$id]);
    unset(__McCurl::$inclHeader[$id]);
    unset(__McCurl::$sink[$id]);
    unset(__McCurl::$hdrSink[$id]);
    unset(__McCurl::$src[$id]);
    unset(__McCurl::$slists[$id]);
    unset(__McCurl::$optString[$id]);
    unset(__McCurl::$optLong[$id]);
    unset(__McCurl::$errBuf[$id]);
    unset(__McCurl::$errno[$id]);
    unset(__McCurl::$cbErr[$id]);
    unset(__McCurl::$share[$id]);
    unset(__McCurl::$multiKids[$id]);
}

/** Reverse lookup used by curl_multi_info_read to name a completed CURL*. */
function __mc_curl_by_addr(int $addr): mixed
{
    if ($addr === 0) { return null; }
    foreach (__McCurl::$addr as $id => $a) {
        if ($a === $addr) {
            return isset(__McCurl::$obj[$id]) ? __McCurl::$obj[$id] : null;
        }
    }
    return null;
}

/**
 * Install the trampolines and php's own defaults on a fresh (or just-reset)
 * CURL*.
 *
 * ⚠ WRITEFUNCTION BEFORE WRITEDATA, always. If WRITEDATA already held our
 * integer id while libcurl's DEFAULT writer was still installed, that writer
 * would `fwrite()` to a `FILE*` that is really the number 7.
 */
function __mc_curl_install(\Ffi\Ptr $h, int $id): void
{
    \__mc_curl_easy_setopt($h, 20011, \ptr_to_int(\fn_to_ptr('__mc_curl_write_tramp')));
    \__mc_curl_easy_setopt($h, 10001, $id);
    \__mc_curl_easy_setopt($h, 20079, \ptr_to_int(\fn_to_ptr('__mc_curl_header_tramp')));
    \__mc_curl_easy_setopt($h, 10029, $id);
    \__mc_curl_easy_setopt($h, 20012, \ptr_to_int(\fn_to_ptr('__mc_curl_read_tramp')));
    \__mc_curl_easy_setopt($h, 10009, $id);
    if (isset(__McCurl::$errBuf[$id]) && __McCurl::$errBuf[$id] !== 0) {
        \__mc_curl_easy_setopt($h, 10010, __McCurl::$errBuf[$id]);
    }
    // php's own defaults, so a handle behaves the same here and there.
    \__mc_curl_easy_setopt($h, 43, 1);      // NOPROGRESS
    \__mc_curl_easy_setopt($h, 99, 1);      // NOSIGNAL — a signal-based DNS timeout
                                            // is unsafe in a process with fibers.
    \__mc_curl_easy_setopt($h, 68, 20);     // MAXREDIRS
    \__mc_curl_easy_setopt($h, 92, 120);    // DNS_CACHE_TIMEOUT
}

// ── The option table ────────────────────────────────────────────────────────

/**
 * The OBJECTPOINT options whose value is a `curl_slist*` rather than a string.
 *
 * This is the only classification libcurl's own numbering cannot give us:
 * CURLOPTTYPE_STRINGPOINT, SLISTPOINT and CBPOINT are all 10000, so the bucket
 * is ambiguous where LONG/FUNCTIONPOINT/OFF_T/BLOB are not.
 */
function __mc_curl_is_slist(int $o): bool
{
    return $o === 10023      // HTTPHEADER
        || $o === 10028      // QUOTE
        || $o === 10039      // POSTQUOTE
        || $o === 10093      // PREQUOTE
        || $o === 10070      // TELNETOPTIONS
        || $o === 10104      // HTTP200ALIASES
        || $o === 10187      // MAIL_RCPT
        || $o === 10203      // RESOLVE
        || $o === 10228      // PROXYHEADER
        || $o === 10243;     // CONNECT_TO
}

/** php's coercion for a LONG option: bools become 1/0, everything else casts. */
function __mc_curl_to_long(mixed $v): int
{
    if (\is_bool($v)) { return $v ? 1 : 0; }
    if ($v === null) { return 0; }
    return (int) $v;
}

/** Build a fresh curl_slist from a PHP array, replacing any chain this option held. */
function __mc_curl_set_slist(int $id, \Ffi\Ptr $h, int $opt, mixed $value): bool
{
    if (isset(__McCurl::$slists[$id][$opt])) {
        $old = __McCurl::$slists[$id][$opt];
        if ($old !== 0) { \__mc_curl_slist_free_all(\int_to_ptr($old)); }
        unset(__McCurl::$slists[$id][$opt]);
    }
    if ($value === null) {
        return \__mc_curl_easy_setopt($h, $opt, 0) === 0;
    }
    if (!\is_array($value)) {
        throw new \TypeError('curl_setopt(): Argument #3 ($value) must be of type array for CURLOPT #' . $opt);
    }
    $head = \int_to_ptr(0);
    foreach ($value as $line) {
        $s = (string) $line;
        if (\strpos($s, "\0") !== false) {
            throw new \ValueError('curl_setopt(): Argument #3 ($value) must not contain any null bytes');
        }
        $head = \__mc_curl_slist_append($head, $s);
    }
    $addr = \ptr_to_int($head);
    if ($addr === 0) {
        // An empty array clears the header list, exactly as php does.
        return \__mc_curl_easy_setopt($h, $opt, 0) === 0;
    }
    // The chain is NOT copied by libcurl — it must outlive the transfer, and it
    // is ours to free. Parking it here is what makes __mc_curl_release correct.
    if (!isset(__McCurl::$slists[$id])) { __McCurl::$slists[$id] = []; }
    __McCurl::$slists[$id][$opt] = $addr;
    return \__mc_curl_easy_setopt($h, $opt, $addr) === 0;
}

/**
 * CURLOPT_POSTFIELDS, the one string option libcurl does NOT copy.
 *
 * Forwarding it directly would hand libcurl a pointer into a PHP string whose
 * refcount can hit zero long before curl_easy_perform runs. So it always goes
 * through COPYPOSTFIELDS — and POSTFIELDSIZE has to be set FIRST, or libcurl
 * `strlen()`s the buffer and truncates any body containing a NUL.
 */
function __mc_curl_postfields(int $id, \Ffi\Ptr $h, mixed $value): bool
{
    if (\is_array($value)) {
        // php encodes an array POSTFIELDS as multipart/form-data via curl_mime,
        // which needs the CURLFile machinery. Urlencoding it here instead would
        // send a DIFFERENT request than php does with the same script — a wrong
        // answer is worse than a clean refusal.
        throw new \ValueError('curl_setopt(): CURLOPT_POSTFIELDS as an array builds a '
            . 'multipart/form-data body, which is not implemented; pass a urlencoded '
            . 'string (http_build_query()) instead');
    }
    $s = $value === null ? '' : (string) $value;
    if (!isset(__McCurl::$optString[$id])) { __McCurl::$optString[$id] = []; }
    __McCurl::$optString[$id][10015] = $s;
    \__mc_curl_easy_setopt($h, 30120, \strlen($s));        // POSTFIELDSIZE_LARGE
    return \__mc_curl_easy_setopt($h, 10165, \str_bytes($s)) === 0;   // COPYPOSTFIELDS
}

/** A `\Resource` argument for a stream-shaped option, or a clean refusal. */
function __mc_curl_need_stream(int $option, mixed $value): mixed
{
    if ($value === null) { return null; }
    if ($value instanceof \Resource) { return $value; }
    throw new \TypeError('curl_setopt(): Argument #3 ($value) must be of type resource for CURLOPT #' . $option);
}

// ── The public API ──────────────────────────────────────────────────────────

function curl_init(?string $url = null): CurlHandle
{
    if (!__McCurl::$globalInit) {
        // CURL_GLOBAL_DEFAULT. libcurl self-initialises on first use, but only
        // in a way that is documented as not thread-safe; doing it once up front
        // is the supported spelling.
        \__mc_curl_global_init(3);
        __McCurl::$globalInit = true;
    }
    $h = \__mc_curl_easy_init();
    if (\ptr_to_int($h) === 0) {
        throw new \RuntimeException('curl_init(): curl_easy_init() failed');
    }
    $id = __McCurl::$nextId;
    __McCurl::$nextId = $id + 1;

    $ch = new CurlHandle();
    $ch->addr = \ptr_to_int($h);
    $ch->id = $id;

    __McCurl::$addr[$id] = $ch->addr;
    __McCurl::$obj[$id] = $ch;
    __McCurl::$body[$id] = '';
    __McCurl::$writeMethod[$id] = __McCurl::W_STDOUT;
    __McCurl::$headerMethod[$id] = __McCurl::W_STDOUT;
    __McCurl::$inclHeader[$id] = false;
    __McCurl::$errno[$id] = 0;
    __McCurl::$slists[$id] = [];
    __McCurl::$optString[$id] = [];
    __McCurl::$optLong[$id] = [];
    // CURL_ERROR_SIZE. libcurl writes a human-readable reason here and does NOT
    // clear it between transfers, so curl_exec zeroes byte 0 before each run.
    __McCurl::$errBuf[$id] = \ptr_to_int(\__mc_curl_calloc(256, 1));

    \__mc_curl_install($h, $id);
    if ($url !== null) {
        \curl_setopt($ch, 10002, $url);
    }
    return $ch;
}

function curl_setopt(CurlHandle $handle, int $option, mixed $value): bool
{
    if ($handle->closed) {
        throw new \ValueError('curl_setopt(): Argument #1 ($handle) is a closed cURL handle');
    }
    $id = $handle->id;
    $h = \int_to_ptr($handle->addr);

    // 1. Options ext/curl implements ITSELF and never forwards. Each of these
    //    SETS THE METHOD rather than adding to a precedence chain — php keeps
    //    one method per handle and the last setter wins.
    if ($option === 19913) {                       // RETURNTRANSFER
        __McCurl::$writeMethod[$id] = $value ? __McCurl::W_RETURN : __McCurl::W_STDOUT;
        return true;
    }
    if ($option === 19914) { return true; }        // BINARYTRANSFER — a php 5 no-op
    if ($option === 10001) {                       // FILE / WRITEDATA
        $res = \__mc_curl_need_stream($option, $value);
        __McCurl::$sink[$id] = $res;
        __McCurl::$writeMethod[$id] = $res === null ? __McCurl::W_STDOUT : __McCurl::W_FILE;
        return true;
    }
    if ($option === 10029) {                       // WRITEHEADER / HEADERDATA
        $res = \__mc_curl_need_stream($option, $value);
        __McCurl::$hdrSink[$id] = $res;
        __McCurl::$headerMethod[$id] = $res === null ? __McCurl::W_STDOUT : __McCurl::W_FILE;
        return true;
    }
    if ($option === 10009) {                       // INFILE / READDATA
        __McCurl::$src[$id] = \__mc_curl_need_stream($option, $value);
        return true;
    }

    // 2. Callback options: park the PHP callable. Our trampoline is already
    //    installed and stays installed — the user's closure never reaches C.
    if ($option === 20011) {                       // WRITEFUNCTION
        __McCurl::$writeCb[$id] = $value;
        __McCurl::$writeMethod[$id] = $value === null ? __McCurl::W_STDOUT : __McCurl::W_USER;
        return true;
    }
    if ($option === 20079) {                       // HEADERFUNCTION
        __McCurl::$headerCb[$id] = $value;
        __McCurl::$headerMethod[$id] = $value === null ? __McCurl::W_STDOUT : __McCurl::W_USER;
        return true;
    }
    if ($option === 20012) { __McCurl::$readCb[$id] = $value; return true; }
    if ($option === 20219 || $option === 20056) {  // XFERINFOFUNCTION / PROGRESSFUNCTION
        __McCurl::$progressCb[$id] = $value;
        if ($value === null) {
            \__mc_curl_easy_setopt($h, 20219, 0);
            return \__mc_curl_easy_setopt($h, 43, 1) === 0;
        }
        // Installed LAZILY: an unconditional XFERINFOFUNCTION forces
        // NOPROGRESS=0 semantics and costs a callback per socket event.
        \__mc_curl_easy_setopt($h, 20219, \ptr_to_int(\fn_to_ptr('__mc_curl_xferinfo_tramp')));
        \__mc_curl_easy_setopt($h, 10057, $id);
        return \__mc_curl_easy_setopt($h, 43, 0) === 0;
    }
    if ($option === 10057 || $option === 10010) {
        // XFERINFODATA and ERRORBUFFER are OURS. Letting a program overwrite
        // either would point a trampoline at a foreign id, or hand libcurl a
        // PHP string to scribble 256 bytes into.
        throw new \ValueError('curl_setopt(): CURLOPT #' . $option
            . ' is managed by ext/curl and cannot be set from PHP');
    }

    $class = \intdiv($option, 10000);

    if ($class === 2) {
        // Every other FUNCTIONPOINT option takes a C function pointer with a
        // signature that has no PHP spelling (curl_sockopt_callback,
        // curl_opensocket_callback, the SSL_CTX hook, ...).
        throw new \ValueError('curl_setopt(): CURLOPT #' . $option
            . ' takes a C function pointer and is not settable from PHP');
    }

    // 3. Type-classified forwarding. libcurl encodes the option's C type IN its
    //    number — LONG 0, OBJECTPOINT 10000, FUNCTIONPOINT 20000, OFF_T 30000,
    //    BLOB 40000 — so one intdiv classifies every option there is, including
    //    ones this file has never heard of.
    if ($class === 0) {
        $lv = \__mc_curl_to_long($value);
        if ($option === 42) { __McCurl::$inclHeader[$id] = $lv !== 0; }   // HEADER
        __McCurl::$optLong[$id][$option] = $lv;
        return \__mc_curl_easy_setopt($h, $option, $lv) === 0;
    }
    if ($class === 3) {                            // OFF_T — 8 bytes, same slot
        return \__mc_curl_easy_setopt($h, $option, \__mc_curl_to_long($value)) === 0;
    }
    if ($class === 1) {
        if ($option === 10015 || $option === 10165) {
            return \__mc_curl_postfields($id, $h, $value);
        }
        if (\__mc_curl_is_slist($option)) {
            return \__mc_curl_set_slist($id, $h, $option, $value);
        }
        if ($option === 10100) {                   // SHARE — a CURLSH*
            if ($value === null) {
                return \__mc_curl_easy_setopt($h, $option, 0) === 0;
            }
            // Duck-typed on purpose: naming CurlShareHandle here would make
            // curl.php depend on curl_multi.php, which is gated apart and very
            // often absent.
            if (!\is_object($value)) {
                throw new \TypeError('curl_setopt(): Argument #3 ($value) must be of type '
                    . 'CurlShareHandle for CURLOPT_SHARE');
            }
            // The share must outlive every easy handle attached to it, so the
            // easy handle CO-OWNS the share object — libcurl only holds a raw
            // CURLSH* and would happily keep using a freed one.
            __McCurl::$share[$id] = $value;
            return \__mc_curl_easy_setopt($h, $option, $value->addr) === 0;
        }
        if ($option === 10037) {                   // STDERR — a real FILE*
            $res = \__mc_curl_need_stream($option, $value);
            if ($res === null) { return \__mc_curl_easy_setopt($h, $option, 0) === 0; }
            if ($res->kind !== \Resource::KIND_FILE) {
                throw new \ValueError('curl_setopt(): CURLOPT_STDERR needs a real file '
                    . 'stream (libcurl writes to it with a C FILE*)');
            }
            return \__mc_curl_easy_setopt($h, $option, $res->addr) === 0;
        }
        if ($value === null) {
            return \__mc_curl_easy_setopt($h, $option, 0) === 0;
        }
        $s = (string) $value;
        if (\strpos($s, "\0") !== false) {
            throw new \ValueError('curl_setopt(): Argument #3 ($value) must not contain any null bytes');
        }
        // Pinned even though libcurl copies every other string option since
        // 7.17: an rc of 2 or more also disables the in-place arms of
        // __mir_str_append and __mir_str_set_char, so the address stays stable
        // for as long as the option is set.
        __McCurl::$optString[$id][$option] = $s;
        return \__mc_curl_easy_setopt($h, $option, \str_bytes($s)) === 0;
    }
    if ($class === 4) {
        throw new \ValueError('curl_setopt(): CURLOPT #' . $option
            . ' takes a struct curl_blob, which is not implemented');
    }
    throw new \ValueError('curl_setopt(): Argument #2 ($option) is not a valid cURL option');
}

function curl_setopt_array(CurlHandle $handle, array $options): bool
{
    foreach ($options as $opt => $val) {
        if (!\curl_setopt($handle, (int) $opt, $val)) { return false; }
    }
    return true;
}

function curl_exec(CurlHandle $handle): string|bool
{
    if ($handle->closed) {
        throw new \ValueError('curl_exec(): Argument #1 ($handle) is a closed cURL handle');
    }
    $id = $handle->id;
    __McCurl::$body[$id] = '';
    unset(__McCurl::$cbErr[$id]);
    if (isset(__McCurl::$errBuf[$id]) && __McCurl::$errBuf[$id] !== 0) {
        \poke_i8(\int_to_ptr(__McCurl::$errBuf[$id]), 0, 0);
    }

    $rc = \__mc_curl_easy_perform(\int_to_ptr($handle->addr));
    __McCurl::$errno[$id] = $rc;

    if (isset(__McCurl::$cbErr[$id])) {
        // A user callback threw. It could not throw THROUGH libcurl's frames, so
        // it parked the Throwable and made the transfer fail; rethrow it here,
        // on our own stack, where a `try` around curl_exec can see it.
        $e = __McCurl::$cbErr[$id];
        unset(__McCurl::$cbErr[$id]);
        throw $e;
    }
    if ($rc !== 0) { return false; }
    // Only W_RETURN yields the buffer. A handle carrying both RETURNTRANSFER
    // and a WRITEFUNCTION answers `true` — the callback was the sink, so the
    // buffer is empty and returning it would be a silent lie.
    $m = isset(__McCurl::$writeMethod[$id]) ? __McCurl::$writeMethod[$id] : __McCurl::W_STDOUT;
    if ($m === __McCurl::W_RETURN) {
        return __McCurl::$body[$id];
    }
    return true;
}

/**
 * A NO-OP, exactly as in php 8.
 *
 * php 8.0 turned the curl resource into a CurlHandle OBJECT, and with that the
 * handle's lifetime became the object's: `curl_close()` stopped closing anything
 * and php 8.5 deprecates it outright. Freeing here would diverge — a script that
 * calls curl_close() and then reuses the handle still works under php, and would
 * hit a cleaned-up CURL* under us.
 *
 * The real teardown is {@see CurlHandle::__destruct}, which runs when the last
 * reference goes away. php 8.5 also emits a deprecation from this function; we
 * do not, because a deprecation is a diagnostic and not a semantic.
 */
function curl_close(CurlHandle $handle): void
{
}

function curl_reset(CurlHandle $handle): void
{
    if ($handle->closed) {
        throw new \ValueError('curl_reset(): Argument #1 ($handle) is a closed cURL handle');
    }
    $id = $handle->id;
    $h = \int_to_ptr($handle->addr);

    // ⚠ curl_easy_reset() clears EVERY option, including the four trampolines
    // and the error buffer. A reset handle that is never re-installed writes
    // straight to stdout and reports no error text.
    \__mc_curl_easy_reset($h);

    if (isset(__McCurl::$slists[$id])) {
        foreach (__McCurl::$slists[$id] as $head) {
            if ($head !== 0) { \__mc_curl_slist_free_all(\int_to_ptr($head)); }
        }
    }
    __McCurl::$slists[$id] = [];
    __McCurl::$optString[$id] = [];
    __McCurl::$optLong[$id] = [];
    __McCurl::$body[$id] = '';
    __McCurl::$writeMethod[$id] = __McCurl::W_STDOUT;
    __McCurl::$headerMethod[$id] = __McCurl::W_STDOUT;
    __McCurl::$inclHeader[$id] = false;
    __McCurl::$errno[$id] = 0;
    unset(__McCurl::$writeCb[$id]);
    unset(__McCurl::$headerCb[$id]);
    unset(__McCurl::$readCb[$id]);
    unset(__McCurl::$progressCb[$id]);
    unset(__McCurl::$sink[$id]);
    unset(__McCurl::$hdrSink[$id]);
    unset(__McCurl::$src[$id]);
    unset(__McCurl::$cbErr[$id]);

    \__mc_curl_install($h, $id);
}

function curl_copy_handle(CurlHandle $handle): CurlHandle
{
    if ($handle->closed) {
        throw new \ValueError('curl_copy_handle(): Argument #1 ($handle) is a closed cURL handle');
    }
    $h2 = \__mc_curl_easy_duphandle(\int_to_ptr($handle->addr));
    if (\ptr_to_int($h2) === 0) {
        throw new \RuntimeException('curl_copy_handle(): curl_easy_duphandle() failed');
    }
    $src = $handle->id;
    $id = __McCurl::$nextId;
    __McCurl::$nextId = $id + 1;

    $ch = new CurlHandle();
    $ch->addr = \ptr_to_int($h2);
    $ch->id = $id;

    __McCurl::$addr[$id] = $ch->addr;
    __McCurl::$obj[$id] = $ch;
    __McCurl::$body[$id] = '';
    __McCurl::$writeMethod[$id] = __McCurl::$writeMethod[$src];
    __McCurl::$headerMethod[$id] = __McCurl::$headerMethod[$src];
    __McCurl::$inclHeader[$id] = __McCurl::$inclHeader[$src];
    __McCurl::$errno[$id] = 0;
    __McCurl::$optString[$id] = __McCurl::$optString[$src];
    __McCurl::$optLong[$id] = __McCurl::$optLong[$src];
    __McCurl::$errBuf[$id] = \ptr_to_int(\__mc_curl_calloc(256, 1));
    if (isset(__McCurl::$writeCb[$src])) { __McCurl::$writeCb[$id] = __McCurl::$writeCb[$src]; }
    if (isset(__McCurl::$headerCb[$src])) { __McCurl::$headerCb[$id] = __McCurl::$headerCb[$src]; }
    if (isset(__McCurl::$readCb[$src])) { __McCurl::$readCb[$id] = __McCurl::$readCb[$src]; }
    if (isset(__McCurl::$progressCb[$src])) { __McCurl::$progressCb[$id] = __McCurl::$progressCb[$src]; }
    if (isset(__McCurl::$sink[$src])) { __McCurl::$sink[$id] = __McCurl::$sink[$src]; }
    if (isset(__McCurl::$hdrSink[$src])) { __McCurl::$hdrSink[$id] = __McCurl::$hdrSink[$src]; }
    if (isset(__McCurl::$src[$src])) { __McCurl::$src[$id] = __McCurl::$src[$src]; }

    // The slists are NOT shared: duphandle copies the POINTERS, so both handles
    // would free one chain. Rebuild from the strings we pinned instead.
    __McCurl::$slists[$id] = [];
    if (isset(__McCurl::$slists[$src])) {
        foreach (__McCurl::$slists[$src] as $opt => $ignored) {
            \__mc_curl_easy_setopt($h2, $opt, 0);
        }
    }

    // duphandle carried the SOURCE handle's id in every *DATA slot. Re-point
    // them, or both handles would append to one body.
    \__mc_curl_install($h2, $id);
    return $ch;
}

function curl_errno(CurlHandle $handle): int
{
    return isset(__McCurl::$errno[$handle->id]) ? __McCurl::$errno[$handle->id] : 0;
}

function curl_error(CurlHandle $handle): string
{
    $id = $handle->id;
    if (isset(__McCurl::$errBuf[$id]) && __McCurl::$errBuf[$id] !== 0) {
        $msg = \cstr_to_str(\int_to_ptr(__McCurl::$errBuf[$id]));
        if ($msg !== '') { return $msg; }
    }
    $rc = isset(__McCurl::$errno[$id]) ? __McCurl::$errno[$id] : 0;
    if ($rc === 0) { return ''; }
    return \cstr_to_str(\__mc_curl_easy_strerror($rc));
}

function curl_strerror(int $error_code): ?string
{
    $p = \__mc_curl_easy_strerror($error_code);
    if (\ptr_to_int($p) === 0) { return null; }
    $s = \cstr_to_str($p);
    // libcurl answers an out-of-range code with this exact sentence; php turns
    // it into null so a caller can tell "no such code" from a real message.
    if ($s === 'Unknown error') { return null; }
    return $s;
}

function curl_escape(CurlHandle $handle, string $string): string|false
{
    if ($handle->closed) {
        throw new \ValueError('curl_escape(): Argument #1 ($handle) is a closed cURL handle');
    }
    $p = \__mc_curl_easy_escape(\int_to_ptr($handle->addr), $string, \strlen($string));
    if (\ptr_to_int($p) === 0) { return false; }
    // COPY, then free with libcurl's OWN deallocator: the block came out of
    // libcurl's allocator and has no rc header of ours.
    $out = \cstr_to_str($p);
    \__mc_curl_free_ptr($p);
    return $out;
}

function curl_unescape(CurlHandle $handle, string $string): string|false
{
    if ($handle->closed) {
        throw new \ValueError('curl_unescape(): Argument #1 ($handle) is a closed cURL handle');
    }
    $lenp = \__mc_curl_calloc(4, 1);
    $p = \__mc_curl_easy_unescape(\int_to_ptr($handle->addr), $string, \strlen($string), $lenp);
    $n = \peek_i32($lenp, 0);
    \__mc_curl_free($lenp);
    if (\ptr_to_int($p) === 0) { return false; }
    // Length-based, not NUL-based: an unescaped %00 is legal and binary-safe.
    $out = \str_from_buffer($p, $n);
    \__mc_curl_free_ptr($p);
    return $out;
}

function curl_pause(CurlHandle $handle, int $flags): int
{
    if ($handle->closed) {
        throw new \ValueError('curl_pause(): Argument #1 ($handle) is a closed cURL handle');
    }
    return \__mc_curl_easy_pause(\int_to_ptr($handle->addr), $flags);
}

function curl_upkeep(CurlHandle $handle): bool
{
    if ($handle->closed) {
        throw new \ValueError('curl_upkeep(): Argument #1 ($handle) is a closed cURL handle');
    }
    return \__mc_curl_easy_upkeep(\int_to_ptr($handle->addr)) === 0;
}

// ── curl_getinfo ────────────────────────────────────────────────────────────

/**
 * The `_T` sibling of a CURLINFO_DOUBLE info, or 0 if it has none.
 *
 * ⚠ THIS BUILD CANNOT READ A C `double` OUT OF MEMORY. There is no `peek_f64`,
 * no i64→double bitcast builtin, and prelude/binary.php's unpack() implements no
 * `d`/`f`/`e`/`g` code — so calling curl_easy_getinfo with a 0x300000-class info
 * would hand us eight bytes we cannot decode. Every float-valued key php reports
 * has an off_t sibling that carries the same number as an integer (microseconds
 * for the timers, bytes for the sizes), which is what we ask for instead.
 */
function __mc_curl_double_as_t(int $info): int
{
    if ($info === 3145731) { return 6291506; }   // TOTAL_TIME
    if ($info === 3145732) { return 6291507; }   // NAMELOOKUP_TIME
    if ($info === 3145733) { return 6291508; }   // CONNECT_TIME
    if ($info === 3145734) { return 6291509; }   // PRETRANSFER_TIME
    if ($info === 3145745) { return 6291510; }   // STARTTRANSFER_TIME
    if ($info === 3145747) { return 6291511; }   // REDIRECT_TIME
    if ($info === 3145761) { return 6291512; }   // APPCONNECT_TIME
    if ($info === 3145735) { return 6291463; }   // SIZE_UPLOAD
    if ($info === 3145736) { return 6291464; }   // SIZE_DOWNLOAD
    if ($info === 3145737) { return 6291465; }   // SPEED_DOWNLOAD
    if ($info === 3145738) { return 6291466; }   // SPEED_UPLOAD
    if ($info === 3145743) { return 6291471; }   // CONTENT_LENGTH_DOWNLOAD
    if ($info === 3145744) { return 6291472; }   // CONTENT_LENGTH_UPLOAD
    return 0;
}

/** True when the `_T` sibling counts MICROSECONDS and php reports seconds. */
function __mc_curl_double_is_time(int $info): bool
{
    return $info === 3145731 || $info === 3145732 || $info === 3145733
        || $info === 3145734 || $info === 3145745 || $info === 3145747
        || $info === 3145761;
}

/** One CURLINFO, classified by the type libcurl encodes in the info's number. */
function __mc_curl_info(\Ffi\Ptr $h, int $info): mixed
{
    $type = $info & 15728640;                    // CURLINFO_TYPEMASK 0xf00000

    if ($type === 3145728) {                     // DOUBLE — never asked for directly
        $t = \__mc_curl_double_as_t($info);
        if ($t === 0) {
            throw new \ValueError('curl_getinfo(): CURLINFO #' . $info . ' returns a C double, '
                . 'which this build cannot read, and it has no _T variant');
        }
        $raw = \__mc_curl_info($h, $t);
        if (!\is_int($raw)) { return 0.0; }
        if (\__mc_curl_double_is_time($info)) { return $raw / 1000000.0; }
        return (float) $raw;
    }

    $out = \__mc_curl_calloc(8, 1);
    $rc = \__mc_curl_easy_getinfo($h, $info, $out);
    if ($rc !== 0) {
        \__mc_curl_free($out);
        // php answers an info libcurl rejects with `false` and NO diagnostic —
        // probed against 8.5, including a nonsense info number. This is one of
        // the places the project's "throw where Zend warns" rule does not apply,
        // because Zend does not warn.
        return false;
    }

    if ($type === 1048576) {                     // STRING — a `char **` out-param
        $addr = \peek_i64($out, 0);
        \__mc_curl_free($out);
        // A NULL char* is `false`, not '' and not null: php distinguishes "no
        // such value" (CURLINFO_CONTENT_TYPE before a response) from an empty
        // one (CURLINFO_EFFECTIVE_URL on a fresh handle, which is "").
        // libcurl's storage carries no rc header of ours, so copy before PHP
        // ever sees it.
        return $addr === 0 ? false : \cstr_to_str(\int_to_ptr($addr));
    }
    if ($type === 4194304) {                     // SLIST / PTR
        $head = \peek_i64($out, 0);
        \__mc_curl_free($out);
        $rows = [];
        $p = $head;
        $guard = 0;
        // struct curl_slist { char *data; struct curl_slist *next; }
        while ($p !== 0 && $guard < 4096) {
            $d = \peek_i64(\int_to_ptr($p), 0);
            if ($d !== 0) { $rows[] = \cstr_to_str(\int_to_ptr($d)); }
            $p = \peek_i64(\int_to_ptr($p), 8);
            $guard = $guard + 1;
        }
        if ($head !== 0) { \__mc_curl_slist_free_all(\int_to_ptr($head)); }
        return $rows;
    }
    // LONG (0x200000) and OFF_T (0x600000). A C `long` is 8 bytes on every LP64
    // target we build for, so peek_i64 is right for both — peek_i32 would read
    // half of it and lose the sign.
    $v = \peek_i64($out, 0);
    \__mc_curl_free($out);
    return $v;
}

/** A LONG-ish info as a plain int, with 0 where this libcurl has no such info. */
function __mc_curl_info_int(\Ffi\Ptr $h, int $info): int
{
    $v = \__mc_curl_info($h, $info);
    return \is_int($v) ? $v : 0;
}

/** A STRING info as php reports it: the string, or '' where libcurl gave none. */
function __mc_curl_info_str(\Ffi\Ptr $h, int $info): string
{
    $v = \__mc_curl_info($h, $info);
    return \is_string($v) ? $v : '';
}

/** A DOUBLE info, routed through its _T sibling. */
function __mc_curl_info_float(\Ffi\Ptr $h, int $info): float
{
    $v = \__mc_curl_info($h, $info);
    return \is_float($v) ? $v : 0.0;
}

function curl_getinfo(CurlHandle $handle, ?int $option = null): mixed
{
    if ($handle->closed) {
        throw new \ValueError('curl_getinfo(): Argument #1 ($handle) is a closed cURL handle');
    }
    $h = \int_to_ptr($handle->addr);
    if ($option !== null) {
        return \__mc_curl_info($h, $option);
    }

    // The no-argument array, in php's own key order. Keys this libcurl has no
    // info for (CURLINFO_HTTPAUTH_USED and _PROXYAUTH_USED landed in 8.12) still
    // appear, carrying 0 — the KEY SET is the contract callers depend on, and a
    // missing key is a fatal `Undefined array key` where a 0 is merely stale.
    $out = [];
    $out['url'] = \__mc_curl_info_str($h, 1048577);
    // The ONE key php reports as null rather than false when libcurl has no
    // value — the single-option read of the same info answers false. Mirrored
    // rather than tidied: it is what a caller's `??` and `=== null` see.
    //
    // ⚠ The if/else is NOT a style choice. Spelled as the obvious ternary,
    //     $out['content_type'] = $ct === false ? null : $ct;
    // the stored null reads back as `double` instead of NULL — a raw word where
    // the read expects a tagged cell, the same repr disagreement as
    // tests/aot/cases/array_erased_elem_repr_gap.php. Confirmed by A/B on this
    // single line against curl_getinfo_shape. It does NOT reproduce in a
    // standalone file with the same shape (a mixed-returning callee, a
    // heterogeneous target array, the same key order), so the trigger is
    // narrower than the spelling and is not understood yet; do not "simplify"
    // this back without re-running that case.
    $ct = \__mc_curl_info($h, 1048594);
    if ($ct === false) {
        $out['content_type'] = null;
    } else {
        $out['content_type'] = $ct;
    }
    $out['http_code'] = \__mc_curl_info_int($h, 2097154);
    $out['header_size'] = \__mc_curl_info_int($h, 2097163);
    $out['request_size'] = \__mc_curl_info_int($h, 2097164);
    $out['filetime'] = \__mc_curl_info_int($h, 2097166);
    $out['ssl_verify_result'] = \__mc_curl_info_int($h, 2097165);
    $out['redirect_count'] = \__mc_curl_info_int($h, 2097172);
    $out['total_time'] = \__mc_curl_info_float($h, 3145731);
    $out['namelookup_time'] = \__mc_curl_info_float($h, 3145732);
    $out['connect_time'] = \__mc_curl_info_float($h, 3145733);
    $out['pretransfer_time'] = \__mc_curl_info_float($h, 3145734);
    $out['size_upload'] = \__mc_curl_info_float($h, 3145735);
    $out['size_download'] = \__mc_curl_info_float($h, 3145736);
    $out['speed_download'] = \__mc_curl_info_float($h, 3145737);
    $out['speed_upload'] = \__mc_curl_info_float($h, 3145738);
    $out['download_content_length'] = \__mc_curl_info_float($h, 3145743);
    $out['upload_content_length'] = \__mc_curl_info_float($h, 3145744);
    $out['starttransfer_time'] = \__mc_curl_info_float($h, 3145745);
    $out['redirect_time'] = \__mc_curl_info_float($h, 3145747);
    $out['redirect_url'] = \__mc_curl_info_str($h, 1048607);
    $out['primary_ip'] = \__mc_curl_info_str($h, 1048608);
    // CURLINFO_CERTINFO hands back a `struct curl_certinfo *`, not a slist — a
    // count plus an ARRAY of slists — and it is only ever populated for a TLS
    // transfer with CURLOPT_CERTINFO on. php reports an empty array in every
    // other case, which is every case we can currently produce.
    $out['certinfo'] = [];
    $out['primary_port'] = \__mc_curl_info_int($h, 2097192);
    $out['local_ip'] = \__mc_curl_info_str($h, 1048617);
    $out['local_port'] = \__mc_curl_info_int($h, 2097194);
    $out['http_version'] = \__mc_curl_info_int($h, 2097198);
    $out['protocol'] = \__mc_curl_info_int($h, 2097200);
    $out['ssl_verifyresult'] = \__mc_curl_info_int($h, 2097199);   // PROXY_SSL_VERIFYRESULT
    $out['scheme'] = \__mc_curl_info_str($h, 1048625);
    $out['appconnect_time_us'] = \__mc_curl_info_int($h, 6291512);
    $out['queue_time_us'] = \__mc_curl_info_int($h, 6291521);
    $out['connect_time_us'] = \__mc_curl_info_int($h, 6291508);
    $out['namelookup_time_us'] = \__mc_curl_info_int($h, 6291507);
    $out['pretransfer_time_us'] = \__mc_curl_info_int($h, 6291509);
    $out['redirect_time_us'] = \__mc_curl_info_int($h, 6291511);
    $out['starttransfer_time_us'] = \__mc_curl_info_int($h, 6291510);
    $out['posttransfer_time_us'] = \__mc_curl_info_int($h, 6291523);
    $out['total_time_us'] = \__mc_curl_info_int($h, 6291506);
    $out['effective_method'] = \__mc_curl_info_str($h, 1048634);
    $out['capath'] = \__mc_curl_info_str($h, 1048638);
    $out['cainfo'] = \__mc_curl_info_str($h, 1048637);
    $out['used_proxy'] = \__mc_curl_info_int($h, 2097218);
    $out['httpauth_used'] = \__mc_curl_info_int($h, 2097221);
    $out['proxyauth_used'] = \__mc_curl_info_int($h, 2097222);
    $out['conn_id'] = \__mc_curl_info_int($h, 6291520);
    return $out;
}

// ── curl_version ────────────────────────────────────────────────────────────

/** A `char *` member of the version struct at $off: NULL becomes ''. */
function __mc_curl_vi_str(\Ffi\Ptr $base, int $off): string
{
    $a = \peek_i64($base, $off);
    if ($a === 0) { return ''; }
    return \cstr_to_str(\int_to_ptr($a));
}

/**
 * A NULL-terminated `char *const *` table → string[].
 *
 * @return string[]
 */
function __mc_curl_vi_strv(int $addr): array
{
    /** @var string[] $out */
    $out = [];
    if ($addr === 0) { return $out; }
    $i = 0;
    while ($i < 256) {
        $p = \peek_i64(\int_to_ptr($addr), $i * 8);
        if ($p === 0) { break; }
        $out[] = \cstr_to_str(\int_to_ptr($p));
        $i = $i + 1;
    }
    return $out;
}

/**
 * php's `feature_list`: the `features` bitmask spelled out by name.
 *
 * @return array<string,bool>
 */
function __mc_curl_feature_list(int $f): array
{
    return [
        'AsynchDNS' => ($f & 128) !== 0,
        'CharConv' => ($f & 4096) !== 0,
        'Debug' => ($f & 64) !== 0,
        'GSS-Negotiate' => ($f & 32) !== 0,
        'IDN' => ($f & 1024) !== 0,
        'IPv6' => ($f & 1) !== 0,
        'krb4' => ($f & 2) !== 0,
        'Largefile' => ($f & 512) !== 0,
        'libz' => ($f & 8) !== 0,
        'NTLM' => ($f & 16) !== 0,
        'NTLMWB' => ($f & 32768) !== 0,
        'SPNEGO' => ($f & 256) !== 0,
        'SSL' => ($f & 4) !== 0,
        'SSPI' => ($f & 2048) !== 0,
        'TLS-SRP' => ($f & 16384) !== 0,
        'HTTP2' => ($f & 65536) !== 0,
        'GSSAPI' => ($f & 131072) !== 0,
        'KERBEROS5' => ($f & 262144) !== 0,
        'UNIX_SOCKETS' => ($f & 524288) !== 0,
        'PSL' => ($f & 1048576) !== 0,
        'HTTPS_PROXY' => ($f & 2097152) !== 0,
        'MULTI_SSL' => ($f & 4194304) !== 0,
        'BROTLI' => ($f & 8388608) !== 0,
        'ALTSVC' => ($f & 16777216) !== 0,
        'HTTP3' => ($f & 33554432) !== 0,
        'UNICODE' => ($f & 134217728) !== 0,
        'ZSTD' => ($f & 67108864) !== 0,
        'HSTS' => ($f & 268435456) !== 0,
        'GSASL' => ($f & 536870912) !== 0,
    ];
}

/**
 * @return array<string,mixed>
 *
 * The ONE place besides curl_multi_info_read that dereferences a libcurl struct.
 * `curl_version_info()` IGNORES the age it is handed and returns libcurl's own
 * static block with `age` set to what THIS build supports — which is exactly the
 * contract that makes hard-coded offsets safe here: the struct only ever gets
 * APPENDED to, and `age` says how far it is valid. Offsets verified with
 * offsetof against this libcurl; the fields past age 4 are simply not read.
 *
 * Deriving these from cheaper calls is not an option: `features` and `protocols`
 * exist nowhere else in the API — the plain C `curl_version()` returns only a
 * display string.
 */
function curl_version(): array
{
    $v = \__mc_curl_version_info(11);            // CURLVERSION_ELEVENTH
    if (\ptr_to_int($v) === 0) {
        throw new \RuntimeException('curl_version(): curl_version_info() returned NULL');
    }
    $age = \peek_i32($v, 0);
    $features = \peek_i32($v, 32);
    $out = [
        'version_number' => \peek_u32($v, 16),
        'age' => $age,
        'features' => $features,
        'feature_list' => \__mc_curl_feature_list($features),
        // ssl_version_num is a C `long`, so 8 bytes on LP64. libcurl has
        // reported it as 0 since 7.75 and php passes that straight through.
        'ssl_version_number' => \peek_i64($v, 48),
        'version' => \__mc_curl_vi_str($v, 8),
        'host' => \__mc_curl_vi_str($v, 24),
        'ssl_version' => \__mc_curl_vi_str($v, 40),
        'libz_version' => \__mc_curl_vi_str($v, 56),
        'protocols' => \__mc_curl_vi_strv(\peek_i64($v, 64)),
    ];
    if ($age >= 1) {
        $out['ares'] = \__mc_curl_vi_str($v, 72);
        $out['ares_num'] = \peek_i32($v, 80);
    }
    if ($age >= 2) {
        $out['libidn'] = \__mc_curl_vi_str($v, 88);
    }
    if ($age >= 3) {
        $out['iconv_ver_num'] = \peek_i32($v, 96);
        $out['libssh_version'] = \__mc_curl_vi_str($v, 104);
    }
    if ($age >= 4) {
        $out['brotli_ver_num'] = \peek_u32($v, 112);
        $out['brotli_version'] = \__mc_curl_vi_str($v, 120);
    }
    return $out;
}

// ── Constants ───────────────────────────────────────────────────────────────
//
// The full php 8.5 `curl` constant set, values as libcurl defines them. They are
// what makes the intdiv classifier above work: libcurl encodes each option's C
// type in its NUMBER (LONG 0, OBJECTPOINT 10000, FUNCTIONPOINT 20000, OFF_T
// 30000, BLOB 40000), and php exposes those raw values unchanged. The two
// exceptions are php's own CURLOPT_RETURNTRANSFER and CURLOPT_BINARYTRANSFER,
// which live at 19913/19914 and never reach libcurl.
//
// An option this libcurl does not know is not a problem: it comes back as
// CURLE_UNKNOWN_OPTION and curl_setopt returns false, exactly as php does when
// built against an older libcurl.

// ── misc ────────────────────────────────────────────────────────

const CURLALTSVC_H1                     = 8;
const CURLALTSVC_H2                     = 16;
const CURLALTSVC_H3                     = 32;
const CURLALTSVC_READONLYFILE           = 4;
const CURLFOLLOW_ALL                    = 1;
const CURLFOLLOW_FIRSTONLY              = 3;
const CURLFOLLOW_OBEYCODE               = 2;
const CURLGSSAPI_DELEGATION_FLAG        = 2;
const CURLGSSAPI_DELEGATION_POLICY_FLAG = 1;
const CURLHSTS_ENABLE                   = 1;
const CURLHSTS_READONLYFILE             = 2;
const CURLMIMEOPT_FORMESCAPE            = 1;
const CURLMSG_DONE                      = 1;
const CURLPAUSE_ALL                     = 5;
const CURLPAUSE_CONT                    = 0;
const CURLPAUSE_RECV                    = 1;
const CURLPAUSE_RECV_CONT               = 0;
const CURLPAUSE_SEND                    = 4;
const CURLPAUSE_SEND_CONT               = 0;
const CURLVERSION_NOW                   = 11;
const CURLWS_RAW_MODE                   = 1;

// ── CURLAUTH_ ───────────────────────────────────────────────────

const CURLAUTH_ANY          = 4294967279;
const CURLAUTH_ANYSAFE      = 4294967278;
const CURLAUTH_AWS_SIGV4    = 128;
const CURLAUTH_BASIC        = 1;
const CURLAUTH_BEARER       = 64;
const CURLAUTH_DIGEST       = 2;
const CURLAUTH_DIGEST_IE    = 16;
const CURLAUTH_GSSAPI       = 4;
const CURLAUTH_GSSNEGOTIATE = 4;
const CURLAUTH_NEGOTIATE    = 4;
const CURLAUTH_NONE         = 0;
const CURLAUTH_NTLM         = 8;
const CURLAUTH_NTLM_WB      = 32;
const CURLAUTH_ONLY         = 2147483648;

// ── CURLE_ ──────────────────────────────────────────────────────

const CURLE_ABORTED_BY_CALLBACK         = 42;
const CURLE_BAD_CALLING_ORDER           = 44;
const CURLE_BAD_CONTENT_ENCODING        = 61;
const CURLE_BAD_DOWNLOAD_RESUME         = 36;
const CURLE_BAD_FUNCTION_ARGUMENT       = 43;
const CURLE_BAD_PASSWORD_ENTERED        = 46;
const CURLE_COULDNT_CONNECT             = 7;
const CURLE_COULDNT_RESOLVE_HOST        = 6;
const CURLE_COULDNT_RESOLVE_PROXY       = 5;
const CURLE_FAILED_INIT                 = 2;
const CURLE_FILESIZE_EXCEEDED           = 63;
const CURLE_FILE_COULDNT_READ_FILE      = 37;
const CURLE_FTP_ACCESS_DENIED           = 9;
const CURLE_FTP_BAD_DOWNLOAD_RESUME     = 36;
const CURLE_FTP_CANT_GET_HOST           = 15;
const CURLE_FTP_CANT_RECONNECT          = 16;
const CURLE_FTP_COULDNT_GET_SIZE        = 32;
const CURLE_FTP_COULDNT_RETR_FILE       = 19;
const CURLE_FTP_COULDNT_SET_ASCII       = 29;
const CURLE_FTP_COULDNT_SET_BINARY      = 17;
const CURLE_FTP_COULDNT_STOR_FILE       = 25;
const CURLE_FTP_COULDNT_USE_REST        = 31;
const CURLE_FTP_PARTIAL_FILE            = 18;
const CURLE_FTP_PORT_FAILED             = 30;
const CURLE_FTP_QUOTE_ERROR             = 21;
const CURLE_FTP_SSL_FAILED              = 64;
const CURLE_FTP_USER_PASSWORD_INCORRECT = 10;
const CURLE_FTP_WEIRD_227_FORMAT        = 14;
const CURLE_FTP_WEIRD_PASS_REPLY        = 11;
const CURLE_FTP_WEIRD_PASV_REPLY        = 13;
const CURLE_FTP_WEIRD_SERVER_REPLY      = 8;
const CURLE_FTP_WEIRD_USER_REPLY        = 12;
const CURLE_FTP_WRITE_ERROR             = 20;
const CURLE_FUNCTION_NOT_FOUND          = 41;
const CURLE_GOT_NOTHING                 = 52;
const CURLE_HTTP_NOT_FOUND              = 22;
const CURLE_HTTP_PORT_FAILED            = 45;
const CURLE_HTTP_POST_ERROR             = 34;
const CURLE_HTTP_RANGE_ERROR            = 33;
const CURLE_HTTP_RETURNED_ERROR         = 22;
const CURLE_LDAP_CANNOT_BIND            = 38;
const CURLE_LDAP_INVALID_URL            = 62;
const CURLE_LDAP_SEARCH_FAILED          = 39;
const CURLE_LIBRARY_NOT_FOUND           = 40;
const CURLE_MALFORMAT_USER              = 24;
const CURLE_OBSOLETE                    = 50;
const CURLE_OK                          = 0;
const CURLE_OPERATION_TIMEDOUT          = 28;
const CURLE_OPERATION_TIMEOUTED         = 28;
const CURLE_OUT_OF_MEMORY               = 27;
const CURLE_PARTIAL_FILE                = 18;
const CURLE_PROXY                       = 97;
const CURLE_READ_ERROR                  = 26;
const CURLE_RECV_ERROR                  = 56;
const CURLE_SEND_ERROR                  = 55;
const CURLE_SHARE_IN_USE                = 57;
const CURLE_SSH                         = 79;
const CURLE_SSL_CACERT                  = 60;
const CURLE_SSL_CACERT_BADFILE          = 77;
const CURLE_SSL_CERTPROBLEM             = 58;
const CURLE_SSL_CIPHER                  = 59;
const CURLE_SSL_CONNECT_ERROR           = 35;
const CURLE_SSL_ENGINE_NOTFOUND         = 53;
const CURLE_SSL_ENGINE_SETFAILED        = 54;
const CURLE_SSL_PEER_CERTIFICATE        = 60;
const CURLE_SSL_PINNEDPUBKEYNOTMATCH    = 90;
const CURLE_TELNET_OPTION_SYNTAX        = 49;
const CURLE_TOO_MANY_REDIRECTS          = 47;
const CURLE_UNKNOWN_TELNET_OPTION       = 48;
const CURLE_UNSUPPORTED_PROTOCOL        = 1;
const CURLE_URL_MALFORMAT               = 3;
const CURLE_URL_MALFORMAT_USER          = 4;
const CURLE_WEIRD_SERVER_REPLY          = 8;
const CURLE_WRITE_ERROR                 = 23;

// ── CURLFTP ─────────────────────────────────────────────────────

const CURLFTPAUTH_DEFAULT      = 0;
const CURLFTPAUTH_SSL          = 1;
const CURLFTPAUTH_TLS          = 2;
const CURLFTPMETHOD_DEFAULT    = 0;
const CURLFTPMETHOD_MULTICWD   = 1;
const CURLFTPMETHOD_NOCWD      = 2;
const CURLFTPMETHOD_SINGLECWD  = 3;
const CURLFTPSSL_ALL           = 3;
const CURLFTPSSL_CCC_ACTIVE    = 2;
const CURLFTPSSL_CCC_NONE      = 0;
const CURLFTPSSL_CCC_PASSIVE   = 1;
const CURLFTPSSL_CONTROL       = 2;
const CURLFTPSSL_NONE          = 0;
const CURLFTPSSL_TRY           = 1;
const CURLFTP_CREATE_DIR       = 1;
const CURLFTP_CREATE_DIR_NONE  = 0;
const CURLFTP_CREATE_DIR_RETRY = 2;

// ── CURLHEADER_ ─────────────────────────────────────────────────

const CURLHEADER_SEPARATE = 1;
const CURLHEADER_UNIFIED  = 0;

// ── CURLINFO_ ───────────────────────────────────────────────────

const CURLINFO_APPCONNECT_TIME           = 3145761;
const CURLINFO_APPCONNECT_TIME_T         = 6291512;
const CURLINFO_CAINFO                    = 1048637;
const CURLINFO_CAPATH                    = 1048638;
const CURLINFO_CERTINFO                  = 4194338;
const CURLINFO_CONDITION_UNMET           = 2097187;
const CURLINFO_CONNECT_TIME              = 3145733;
const CURLINFO_CONNECT_TIME_T            = 6291508;
const CURLINFO_CONN_ID                   = 6291520;
const CURLINFO_CONTENT_LENGTH_DOWNLOAD   = 3145743;
const CURLINFO_CONTENT_LENGTH_DOWNLOAD_T = 6291471;
const CURLINFO_CONTENT_LENGTH_UPLOAD     = 3145744;
const CURLINFO_CONTENT_LENGTH_UPLOAD_T   = 6291472;
const CURLINFO_CONTENT_TYPE              = 1048594;
const CURLINFO_COOKIELIST                = 4194332;
const CURLINFO_DATA_IN                   = 3;
const CURLINFO_DATA_OUT                  = 4;
const CURLINFO_EFFECTIVE_METHOD          = 1048634;
const CURLINFO_EFFECTIVE_URL             = 1048577;
const CURLINFO_FILETIME                  = 2097166;
const CURLINFO_FILETIME_T                = 6291470;
const CURLINFO_FTP_ENTRY_PATH            = 1048606;
const CURLINFO_HEADER_IN                 = 1;
const CURLINFO_HEADER_OUT                = 2;
const CURLINFO_HEADER_SIZE               = 2097163;
const CURLINFO_HTTPAUTH_AVAIL            = 2097175;
const CURLINFO_HTTPAUTH_USED             = 2097221;
const CURLINFO_HTTP_CODE                 = 2097154;
const CURLINFO_HTTP_CONNECTCODE          = 2097174;
const CURLINFO_HTTP_VERSION              = 2097198;
const CURLINFO_LASTONE                   = 71;
const CURLINFO_LOCAL_IP                  = 1048617;
const CURLINFO_LOCAL_PORT                = 2097194;
const CURLINFO_NAMELOOKUP_TIME           = 3145732;
const CURLINFO_NAMELOOKUP_TIME_T         = 6291507;
const CURLINFO_NUM_CONNECTS              = 2097178;
const CURLINFO_OS_ERRNO                  = 2097177;
const CURLINFO_POSTTRANSFER_TIME_T       = 6291523;
const CURLINFO_PRETRANSFER_TIME          = 3145734;
const CURLINFO_PRETRANSFER_TIME_T        = 6291509;
const CURLINFO_PRIMARY_IP                = 1048608;
const CURLINFO_PRIMARY_PORT              = 2097192;
const CURLINFO_PRIVATE                   = 1048597;
const CURLINFO_PROTOCOL                  = 2097200;
const CURLINFO_PROXYAUTH_AVAIL           = 2097176;
const CURLINFO_PROXYAUTH_USED            = 2097222;
const CURLINFO_PROXY_ERROR               = 2097211;
const CURLINFO_PROXY_SSL_VERIFYRESULT    = 2097199;
const CURLINFO_QUEUE_TIME_T              = 6291521;
const CURLINFO_REDIRECT_COUNT            = 2097172;
const CURLINFO_REDIRECT_TIME             = 3145747;
const CURLINFO_REDIRECT_TIME_T           = 6291511;
const CURLINFO_REDIRECT_URL              = 1048607;
const CURLINFO_REFERER                   = 1048636;
const CURLINFO_REQUEST_SIZE              = 2097164;
const CURLINFO_RESPONSE_CODE             = 2097154;
const CURLINFO_RETRY_AFTER               = 6291513;
const CURLINFO_RTSP_CLIENT_CSEQ          = 2097189;
const CURLINFO_RTSP_CSEQ_RECV            = 2097191;
const CURLINFO_RTSP_SERVER_CSEQ          = 2097190;
const CURLINFO_RTSP_SESSION_ID           = 1048612;
const CURLINFO_SCHEME                    = 1048625;
const CURLINFO_SIZE_DOWNLOAD             = 3145736;
const CURLINFO_SIZE_DOWNLOAD_T           = 6291464;
const CURLINFO_SIZE_UPLOAD               = 3145735;
const CURLINFO_SIZE_UPLOAD_T             = 6291463;
const CURLINFO_SPEED_DOWNLOAD            = 3145737;
const CURLINFO_SPEED_DOWNLOAD_T          = 6291465;
const CURLINFO_SPEED_UPLOAD              = 3145738;
const CURLINFO_SPEED_UPLOAD_T            = 6291466;
const CURLINFO_SSL_DATA_IN               = 5;
const CURLINFO_SSL_DATA_OUT              = 6;
const CURLINFO_SSL_ENGINES               = 4194331;
const CURLINFO_SSL_VERIFYRESULT          = 2097165;
const CURLINFO_STARTTRANSFER_TIME        = 3145745;
const CURLINFO_STARTTRANSFER_TIME_T      = 6291510;
const CURLINFO_TEXT                      = 0;
const CURLINFO_TOTAL_TIME                = 3145731;
const CURLINFO_TOTAL_TIME_T              = 6291506;
const CURLINFO_USED_PROXY                = 2097218;

// ── CURLKHMATCH_ ────────────────────────────────────────────────

const CURLKHMATCH_LAST     = 3;
const CURLKHMATCH_MISMATCH = 1;
const CURLKHMATCH_MISSING  = 2;
const CURLKHMATCH_OK       = 0;

// ── CURLMOPT_ ───────────────────────────────────────────────────

const CURLMOPT_CHUNK_LENGTH_PENALTY_SIZE   = 30010;
const CURLMOPT_CONTENT_LENGTH_PENALTY_SIZE = 30009;
const CURLMOPT_MAXCONNECTS                 = 6;
const CURLMOPT_MAX_CONCURRENT_STREAMS      = 16;
const CURLMOPT_MAX_HOST_CONNECTIONS        = 7;
const CURLMOPT_MAX_PIPELINE_LENGTH         = 8;
const CURLMOPT_MAX_TOTAL_CONNECTIONS       = 13;
const CURLMOPT_PIPELINING                  = 3;
const CURLMOPT_PUSHFUNCTION                = 20014;

// ── CURLM_ ──────────────────────────────────────────────────────

const CURLM_ADDED_ALREADY      = 7;
const CURLM_BAD_EASY_HANDLE    = 2;
const CURLM_BAD_HANDLE         = 1;
const CURLM_CALL_MULTI_PERFORM = -1;
const CURLM_INTERNAL_ERROR     = 4;
const CURLM_OK                 = 0;
const CURLM_OUT_OF_MEMORY      = 3;

// ── CURLOPT_ ────────────────────────────────────────────────────

const CURLOPT_ABSTRACT_UNIX_SOCKET       = 10264;
const CURLOPT_ACCEPTTIMEOUT_MS           = 212;
const CURLOPT_ACCEPT_ENCODING            = 10102;
const CURLOPT_ADDRESS_SCOPE              = 171;
const CURLOPT_ALTSVC                     = 10287;
const CURLOPT_ALTSVC_CTRL                = 286;
const CURLOPT_APPEND                     = 50;
const CURLOPT_AUTOREFERER                = 58;
const CURLOPT_AWS_SIGV4                  = 10305;
const CURLOPT_BINARYTRANSFER             = 19914;
const CURLOPT_BUFFERSIZE                 = 98;
const CURLOPT_CAINFO                     = 10065;
const CURLOPT_CAINFO_BLOB                = 40309;
const CURLOPT_CAPATH                     = 10097;
const CURLOPT_CA_CACHE_TIMEOUT           = 321;
const CURLOPT_CERTINFO                   = 172;
const CURLOPT_CONNECTTIMEOUT             = 78;
const CURLOPT_CONNECTTIMEOUT_MS          = 156;
const CURLOPT_CONNECT_ONLY               = 141;
const CURLOPT_CONNECT_TO                 = 10243;
const CURLOPT_COOKIE                     = 10022;
const CURLOPT_COOKIEFILE                 = 10031;
const CURLOPT_COOKIEJAR                  = 10082;
const CURLOPT_COOKIELIST                 = 10135;
const CURLOPT_COOKIESESSION              = 96;
const CURLOPT_CRLF                       = 27;
const CURLOPT_CRLFILE                    = 10169;
const CURLOPT_CUSTOMREQUEST              = 10036;
const CURLOPT_DEBUGFUNCTION              = 20094;
const CURLOPT_DEFAULT_PROTOCOL           = 10238;
const CURLOPT_DIRLISTONLY                = 48;
const CURLOPT_DISALLOW_USERNAME_IN_URL   = 278;
const CURLOPT_DNS_CACHE_TIMEOUT          = 92;
const CURLOPT_DNS_INTERFACE              = 10221;
const CURLOPT_DNS_LOCAL_IP4              = 10222;
const CURLOPT_DNS_LOCAL_IP6              = 10223;
const CURLOPT_DNS_SERVERS                = 10211;
const CURLOPT_DNS_SHUFFLE_ADDRESSES      = 275;
const CURLOPT_DNS_USE_GLOBAL_CACHE       = 91;
const CURLOPT_DOH_SSL_VERIFYHOST         = 307;
const CURLOPT_DOH_SSL_VERIFYPEER         = 306;
const CURLOPT_DOH_SSL_VERIFYSTATUS       = 308;
const CURLOPT_DOH_URL                    = 10279;
const CURLOPT_EGDSOCKET                  = 10077;
const CURLOPT_ENCODING                   = 10102;
const CURLOPT_EXPECT_100_TIMEOUT_MS      = 227;
const CURLOPT_FAILONERROR                = 45;
const CURLOPT_FILE                       = 10001;
const CURLOPT_FILETIME                   = 69;
const CURLOPT_FNMATCH_FUNCTION           = 20200;
const CURLOPT_FOLLOWLOCATION             = 52;
const CURLOPT_FORBID_REUSE               = 75;
const CURLOPT_FRESH_CONNECT              = 74;
const CURLOPT_FTPAPPEND                  = 50;
const CURLOPT_FTPLISTONLY                = 48;
const CURLOPT_FTPPORT                    = 10017;
const CURLOPT_FTPSSLAUTH                 = 129;
const CURLOPT_FTP_ACCOUNT                = 10134;
const CURLOPT_FTP_ALTERNATIVE_TO_USER    = 10147;
const CURLOPT_FTP_CREATE_MISSING_DIRS    = 110;
const CURLOPT_FTP_FILEMETHOD             = 138;
const CURLOPT_FTP_RESPONSE_TIMEOUT       = 112;
const CURLOPT_FTP_SKIP_PASV_IP           = 137;
const CURLOPT_FTP_SSL                    = 119;
const CURLOPT_FTP_SSL_CCC                = 154;
const CURLOPT_FTP_USE_EPRT               = 106;
const CURLOPT_FTP_USE_EPSV               = 85;
const CURLOPT_FTP_USE_PRET               = 188;
const CURLOPT_GSSAPI_DELEGATION          = 210;
const CURLOPT_HAPPY_EYEBALLS_TIMEOUT_MS  = 271;
const CURLOPT_HAPROXYPROTOCOL            = 274;
const CURLOPT_HEADER                     = 42;
const CURLOPT_HEADERFUNCTION             = 20079;
const CURLOPT_HEADEROPT                  = 229;
const CURLOPT_HSTS                       = 10300;
const CURLOPT_HSTS_CTRL                  = 299;
const CURLOPT_HTTP09_ALLOWED             = 285;
const CURLOPT_HTTP200ALIASES             = 10104;
const CURLOPT_HTTPAUTH                   = 107;
const CURLOPT_HTTPGET                    = 80;
const CURLOPT_HTTPHEADER                 = 10023;
const CURLOPT_HTTPPROXYTUNNEL            = 61;
const CURLOPT_HTTP_CONTENT_DECODING      = 158;
const CURLOPT_HTTP_TRANSFER_DECODING     = 157;
const CURLOPT_HTTP_VERSION               = 84;
const CURLOPT_IGNORE_CONTENT_LENGTH      = 136;
const CURLOPT_INFILE                     = 10009;
const CURLOPT_INFILESIZE                 = 14;
const CURLOPT_INFILESIZE_LARGE           = 30115;
const CURLOPT_INTERFACE                  = 10062;
const CURLOPT_IPRESOLVE                  = 113;
const CURLOPT_ISSUERCERT                 = 10170;
const CURLOPT_ISSUERCERT_BLOB            = 40295;
const CURLOPT_KEEP_SENDING_ON_ERROR      = 245;
const CURLOPT_KEYPASSWD                  = 10026;
const CURLOPT_KRB4LEVEL                  = 10063;
const CURLOPT_KRBLEVEL                   = 10063;
const CURLOPT_LOCALPORT                  = 139;
const CURLOPT_LOCALPORTRANGE             = 140;
const CURLOPT_LOGIN_OPTIONS              = 10224;
const CURLOPT_LOW_SPEED_LIMIT            = 19;
const CURLOPT_LOW_SPEED_TIME             = 20;
const CURLOPT_MAIL_AUTH                  = 10217;
const CURLOPT_MAIL_FROM                  = 10186;
const CURLOPT_MAIL_RCPT                  = 10187;
const CURLOPT_MAIL_RCPT_ALLLOWFAILS      = 290;
const CURLOPT_MAXAGE_CONN                = 288;
const CURLOPT_MAXCONNECTS                = 71;
const CURLOPT_MAXFILESIZE                = 114;
const CURLOPT_MAXFILESIZE_LARGE          = 30117;
const CURLOPT_MAXLIFETIME_CONN           = 314;
const CURLOPT_MAXREDIRS                  = 68;
const CURLOPT_MAX_RECV_SPEED_LARGE       = 30146;
const CURLOPT_MAX_SEND_SPEED_LARGE       = 30145;
const CURLOPT_MIME_OPTIONS               = 315;
const CURLOPT_NETRC                      = 51;
const CURLOPT_NETRC_FILE                 = 10118;
const CURLOPT_NEW_DIRECTORY_PERMS        = 160;
const CURLOPT_NEW_FILE_PERMS             = 159;
const CURLOPT_NOBODY                     = 44;
const CURLOPT_NOPROGRESS                 = 43;
const CURLOPT_NOPROXY                    = 10177;
const CURLOPT_NOSIGNAL                   = 99;
const CURLOPT_PASSWORD                   = 10174;
const CURLOPT_PATH_AS_IS                 = 234;
const CURLOPT_PINNEDPUBLICKEY            = 10230;
const CURLOPT_PIPEWAIT                   = 237;
const CURLOPT_PORT                       = 3;
const CURLOPT_POST                       = 47;
const CURLOPT_POSTFIELDS                 = 10015;
const CURLOPT_POSTQUOTE                  = 10039;
const CURLOPT_POSTREDIR                  = 161;
const CURLOPT_PREQUOTE                   = 10093;
const CURLOPT_PREREQFUNCTION             = 20312;
const CURLOPT_PRE_PROXY                  = 10262;
const CURLOPT_PRIVATE                    = 10103;
const CURLOPT_PROGRESSFUNCTION           = 20056;
const CURLOPT_PROTOCOLS                  = 181;
const CURLOPT_PROTOCOLS_STR              = 10318;
const CURLOPT_PROXY                      = 10004;
const CURLOPT_PROXYAUTH                  = 111;
const CURLOPT_PROXYHEADER                = 10228;
const CURLOPT_PROXYPASSWORD              = 10176;
const CURLOPT_PROXYPORT                  = 59;
const CURLOPT_PROXYTYPE                  = 101;
const CURLOPT_PROXYUSERNAME              = 10175;
const CURLOPT_PROXYUSERPWD               = 10006;
const CURLOPT_PROXY_CAINFO               = 10246;
const CURLOPT_PROXY_CAINFO_BLOB          = 40310;
const CURLOPT_PROXY_CAPATH               = 10247;
const CURLOPT_PROXY_CRLFILE              = 10260;
const CURLOPT_PROXY_ISSUERCERT           = 10296;
const CURLOPT_PROXY_ISSUERCERT_BLOB      = 40297;
const CURLOPT_PROXY_KEYPASSWD            = 10258;
const CURLOPT_PROXY_PINNEDPUBLICKEY      = 10263;
const CURLOPT_PROXY_SERVICE_NAME         = 10235;
const CURLOPT_PROXY_SSLCERT              = 10254;
const CURLOPT_PROXY_SSLCERTTYPE          = 10255;
const CURLOPT_PROXY_SSLCERT_BLOB         = 40293;
const CURLOPT_PROXY_SSLKEY               = 10256;
const CURLOPT_PROXY_SSLKEYTYPE           = 10257;
const CURLOPT_PROXY_SSLKEY_BLOB          = 40294;
const CURLOPT_PROXY_SSLVERSION           = 250;
const CURLOPT_PROXY_SSL_CIPHER_LIST      = 10259;
const CURLOPT_PROXY_SSL_OPTIONS          = 261;
const CURLOPT_PROXY_SSL_VERIFYHOST       = 249;
const CURLOPT_PROXY_SSL_VERIFYPEER       = 248;
const CURLOPT_PROXY_TLS13_CIPHERS        = 10277;
const CURLOPT_PROXY_TLSAUTH_PASSWORD     = 10252;
const CURLOPT_PROXY_TLSAUTH_TYPE         = 10253;
const CURLOPT_PROXY_TLSAUTH_USERNAME     = 10251;
const CURLOPT_PROXY_TRANSFER_MODE        = 166;
const CURLOPT_PUT                        = 54;
const CURLOPT_QUICK_EXIT                 = 322;
const CURLOPT_QUOTE                      = 10028;
const CURLOPT_RANDOM_FILE                = 10076;
const CURLOPT_RANGE                      = 10007;
const CURLOPT_READDATA                   = 10009;
const CURLOPT_READFUNCTION               = 20012;
const CURLOPT_REDIR_PROTOCOLS            = 182;
const CURLOPT_REDIR_PROTOCOLS_STR        = 10319;
const CURLOPT_REFERER                    = 10016;
const CURLOPT_REQUEST_TARGET             = 10266;
const CURLOPT_RESOLVE                    = 10203;
const CURLOPT_RESUME_FROM                = 21;
const CURLOPT_RETURNTRANSFER             = 19913;
const CURLOPT_RTSP_CLIENT_CSEQ           = 193;
const CURLOPT_RTSP_REQUEST               = 189;
const CURLOPT_RTSP_SERVER_CSEQ           = 194;
const CURLOPT_RTSP_SESSION_ID            = 10190;
const CURLOPT_RTSP_STREAM_URI            = 10191;
const CURLOPT_RTSP_TRANSPORT             = 10192;
const CURLOPT_SAFE_UPLOAD                = -1;
const CURLOPT_SASL_AUTHZID               = 10289;
const CURLOPT_SASL_IR                    = 218;
const CURLOPT_SERVER_RESPONSE_TIMEOUT    = 112;
const CURLOPT_SERVICE_NAME               = 10236;
const CURLOPT_SHARE                      = 10100;
const CURLOPT_SOCKS5_AUTH                = 267;
const CURLOPT_SOCKS5_GSSAPI_NEC          = 180;
const CURLOPT_SOCKS5_GSSAPI_SERVICE      = 10179;
const CURLOPT_SSH_AUTH_TYPES             = 151;
const CURLOPT_SSH_COMPRESSION            = 268;
const CURLOPT_SSH_HOSTKEYFUNCTION        = 20316;
const CURLOPT_SSH_HOST_PUBLIC_KEY_MD5    = 10162;
const CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256 = 10311;
const CURLOPT_SSH_KNOWNHOSTS             = 10183;
const CURLOPT_SSH_PRIVATE_KEYFILE        = 10153;
const CURLOPT_SSH_PUBLIC_KEYFILE         = 10152;
const CURLOPT_SSLCERT                    = 10025;
const CURLOPT_SSLCERTPASSWD              = 10026;
const CURLOPT_SSLCERTTYPE                = 10086;
const CURLOPT_SSLCERT_BLOB               = 40291;
const CURLOPT_SSLENGINE                  = 10089;
const CURLOPT_SSLENGINE_DEFAULT          = 90;
const CURLOPT_SSLKEY                     = 10087;
const CURLOPT_SSLKEYPASSWD               = 10026;
const CURLOPT_SSLKEYTYPE                 = 10088;
const CURLOPT_SSLKEY_BLOB                = 40292;
const CURLOPT_SSLVERSION                 = 32;
const CURLOPT_SSL_CIPHER_LIST            = 10083;
const CURLOPT_SSL_EC_CURVES              = 10298;
const CURLOPT_SSL_ENABLE_ALPN            = 226;
const CURLOPT_SSL_ENABLE_NPN             = 225;
const CURLOPT_SSL_FALSESTART             = 233;
const CURLOPT_SSL_OPTIONS                = 216;
const CURLOPT_SSL_SESSIONID_CACHE        = 150;
const CURLOPT_SSL_SIGNATURE_ALGORITHMS   = 10328;
const CURLOPT_SSL_VERIFYHOST             = 81;
const CURLOPT_SSL_VERIFYPEER             = 64;
const CURLOPT_SSL_VERIFYSTATUS           = 232;
const CURLOPT_STDERR                     = 10037;
const CURLOPT_STREAM_WEIGHT              = 239;
const CURLOPT_SUPPRESS_CONNECT_HEADERS   = 265;
const CURLOPT_TCP_FASTOPEN               = 244;
const CURLOPT_TCP_KEEPALIVE              = 213;
const CURLOPT_TCP_KEEPCNT                = 326;
const CURLOPT_TCP_KEEPIDLE               = 214;
const CURLOPT_TCP_KEEPINTVL              = 215;
const CURLOPT_TCP_NODELAY                = 121;
const CURLOPT_TELNETOPTIONS              = 10070;
const CURLOPT_TFTP_BLKSIZE               = 178;
const CURLOPT_TFTP_NO_OPTIONS            = 242;
const CURLOPT_TIMECONDITION              = 33;
const CURLOPT_TIMEOUT                    = 13;
const CURLOPT_TIMEOUT_MS                 = 155;
const CURLOPT_TIMEVALUE                  = 34;
const CURLOPT_TIMEVALUE_LARGE            = 30270;
const CURLOPT_TLS13_CIPHERS              = 10276;
const CURLOPT_TLSAUTH_PASSWORD           = 10205;
const CURLOPT_TLSAUTH_TYPE               = 10206;
const CURLOPT_TLSAUTH_USERNAME           = 10204;
const CURLOPT_TRANSFERTEXT               = 53;
const CURLOPT_TRANSFER_ENCODING          = 207;
const CURLOPT_UNIX_SOCKET_PATH           = 10231;
const CURLOPT_UNRESTRICTED_AUTH          = 105;
const CURLOPT_UPKEEP_INTERVAL_MS         = 281;
const CURLOPT_UPLOAD                     = 46;
const CURLOPT_UPLOAD_BUFFERSIZE          = 280;
const CURLOPT_URL                        = 10002;
const CURLOPT_USERAGENT                  = 10018;
const CURLOPT_USERNAME                   = 10173;
const CURLOPT_USERPWD                    = 10005;
const CURLOPT_USE_SSL                    = 119;
const CURLOPT_VERBOSE                    = 41;
const CURLOPT_WILDCARDMATCH              = 197;
const CURLOPT_WRITEFUNCTION              = 20011;
const CURLOPT_WRITEHEADER                = 10029;
const CURLOPT_WS_OPTIONS                 = 320;
const CURLOPT_XFERINFOFUNCTION           = 20219;
const CURLOPT_XOAUTH2_BEARER             = 10220;

// ── CURLPIPE_ ───────────────────────────────────────────────────

const CURLPIPE_HTTP1     = 1;
const CURLPIPE_MULTIPLEX = 2;
const CURLPIPE_NOTHING   = 0;

// ── CURLPROTO_ ──────────────────────────────────────────────────

const CURLPROTO_ALL    = 4294967295;
const CURLPROTO_DICT   = 512;
const CURLPROTO_FILE   = 1024;
const CURLPROTO_FTP    = 4;
const CURLPROTO_FTPS   = 8;
const CURLPROTO_GOPHER = 33554432;
const CURLPROTO_HTTP   = 1;
const CURLPROTO_HTTPS  = 2;
const CURLPROTO_IMAP   = 4096;
const CURLPROTO_IMAPS  = 8192;
const CURLPROTO_LDAP   = 128;
const CURLPROTO_LDAPS  = 256;
const CURLPROTO_MQTT   = 268435456;
const CURLPROTO_POP3   = 16384;
const CURLPROTO_POP3S  = 32768;
const CURLPROTO_RTMP   = 524288;
const CURLPROTO_RTMPE  = 2097152;
const CURLPROTO_RTMPS  = 8388608;
const CURLPROTO_RTMPT  = 1048576;
const CURLPROTO_RTMPTE = 4194304;
const CURLPROTO_RTMPTS = 16777216;
const CURLPROTO_RTSP   = 262144;
const CURLPROTO_SCP    = 16;
const CURLPROTO_SFTP   = 32;
const CURLPROTO_SMB    = 67108864;
const CURLPROTO_SMBS   = 134217728;
const CURLPROTO_SMTP   = 65536;
const CURLPROTO_SMTPS  = 131072;
const CURLPROTO_TELNET = 64;
const CURLPROTO_TFTP   = 2048;

// ── CURLPROXY_ ──────────────────────────────────────────────────

const CURLPROXY_HTTP            = 0;
const CURLPROXY_HTTPS           = 2;
const CURLPROXY_HTTP_1_0        = 1;
const CURLPROXY_SOCKS4          = 4;
const CURLPROXY_SOCKS4A         = 6;
const CURLPROXY_SOCKS5          = 5;
const CURLPROXY_SOCKS5_HOSTNAME = 7;

// ── CURLPX_ ─────────────────────────────────────────────────────

const CURLPX_BAD_ADDRESS_TYPE                 = 1;
const CURLPX_BAD_VERSION                      = 2;
const CURLPX_CLOSED                           = 3;
const CURLPX_GSSAPI                           = 4;
const CURLPX_GSSAPI_PERMSG                    = 5;
const CURLPX_GSSAPI_PROTECTION                = 6;
const CURLPX_IDENTD                           = 7;
const CURLPX_IDENTD_DIFFER                    = 8;
const CURLPX_LONG_HOSTNAME                    = 9;
const CURLPX_LONG_PASSWD                      = 10;
const CURLPX_LONG_USER                        = 11;
const CURLPX_NO_AUTH                          = 12;
const CURLPX_OK                               = 0;
const CURLPX_RECV_ADDRESS                     = 13;
const CURLPX_RECV_AUTH                        = 14;
const CURLPX_RECV_CONNECT                     = 15;
const CURLPX_RECV_REQACK                      = 16;
const CURLPX_REPLY_ADDRESS_TYPE_NOT_SUPPORTED = 17;
const CURLPX_REPLY_COMMAND_NOT_SUPPORTED      = 18;
const CURLPX_REPLY_CONNECTION_REFUSED         = 19;
const CURLPX_REPLY_GENERAL_SERVER_FAILURE     = 20;
const CURLPX_REPLY_HOST_UNREACHABLE           = 21;
const CURLPX_REPLY_NETWORK_UNREACHABLE        = 22;
const CURLPX_REPLY_NOT_ALLOWED                = 23;
const CURLPX_REPLY_TTL_EXPIRED                = 24;
const CURLPX_REPLY_UNASSIGNED                 = 25;
const CURLPX_REQUEST_FAILED                   = 26;
const CURLPX_RESOLVE_HOST                     = 27;
const CURLPX_SEND_AUTH                        = 28;
const CURLPX_SEND_CONNECT                     = 29;
const CURLPX_SEND_REQUEST                     = 30;
const CURLPX_UNKNOWN_FAIL                     = 31;
const CURLPX_UNKNOWN_MODE                     = 32;
const CURLPX_USER_REJECTED                    = 33;

// ── CURLSHOPT_ ──────────────────────────────────────────────────

const CURLSHOPT_NONE    = 0;
const CURLSHOPT_SHARE   = 1;
const CURLSHOPT_UNSHARE = 2;

// ── CURLSSH_ ────────────────────────────────────────────────────

const CURLSSH_AUTH_AGENT     = 16;
const CURLSSH_AUTH_ANY       = 4294967295;
const CURLSSH_AUTH_DEFAULT   = 4294967295;
const CURLSSH_AUTH_GSSAPI    = 32;
const CURLSSH_AUTH_HOST      = 4;
const CURLSSH_AUTH_KEYBOARD  = 8;
const CURLSSH_AUTH_NONE      = 0;
const CURLSSH_AUTH_PASSWORD  = 2;
const CURLSSH_AUTH_PUBLICKEY = 1;

// ── CURLSSLOPT_ ─────────────────────────────────────────────────

const CURLSSLOPT_ALLOW_BEAST        = 1;
const CURLSSLOPT_AUTO_CLIENT_CERT   = 32;
const CURLSSLOPT_NATIVE_CA          = 16;
const CURLSSLOPT_NO_PARTIALCHAIN    = 4;
const CURLSSLOPT_NO_REVOKE          = 2;
const CURLSSLOPT_REVOKE_BEST_EFFORT = 8;

// ── CURLUSESSL_ ─────────────────────────────────────────────────

const CURLUSESSL_ALL     = 3;
const CURLUSESSL_CONTROL = 2;
const CURLUSESSL_NONE    = 0;
const CURLUSESSL_TRY     = 1;

// ── CURL_ ───────────────────────────────────────────────────────

const CURL_FNMATCHFUNC_FAIL    = 2;
const CURL_FNMATCHFUNC_MATCH   = 0;
const CURL_FNMATCHFUNC_NOMATCH = 1;
const CURL_MAX_READ_SIZE       = 10485760;
const CURL_PREREQFUNC_ABORT    = 1;
const CURL_PREREQFUNC_OK       = 0;
const CURL_PUSH_DENY           = 1;
const CURL_PUSH_OK             = 0;
const CURL_READFUNC_PAUSE      = 268435457;
const CURL_TLSAUTH_SRP         = 1;
const CURL_WRITEFUNC_PAUSE     = 268435457;

// ── CURL_HTTP_VERSION_ ──────────────────────────────────────────

const CURL_HTTP_VERSION_1_0               = 1;
const CURL_HTTP_VERSION_1_1               = 2;
const CURL_HTTP_VERSION_2                 = 3;
const CURL_HTTP_VERSION_2TLS              = 4;
const CURL_HTTP_VERSION_2_0               = 3;
const CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE = 5;
const CURL_HTTP_VERSION_3                 = 30;
const CURL_HTTP_VERSION_3ONLY             = 31;
const CURL_HTTP_VERSION_NONE              = 0;

// ── CURL_IPRESOLVE_ ─────────────────────────────────────────────

const CURL_IPRESOLVE_V4       = 1;
const CURL_IPRESOLVE_V6       = 2;
const CURL_IPRESOLVE_WHATEVER = 0;

// ── CURL_LOCK_DATA_ ─────────────────────────────────────────────

const CURL_LOCK_DATA_CONNECT     = 5;
const CURL_LOCK_DATA_COOKIE      = 2;
const CURL_LOCK_DATA_DNS         = 3;
const CURL_LOCK_DATA_PSL         = 6;
const CURL_LOCK_DATA_SSL_SESSION = 4;

// ── CURL_NETRC_ ─────────────────────────────────────────────────

const CURL_NETRC_IGNORED  = 0;
const CURL_NETRC_OPTIONAL = 1;
const CURL_NETRC_REQUIRED = 2;

// ── CURL_REDIR_ ─────────────────────────────────────────────────

const CURL_REDIR_POST_301 = 1;
const CURL_REDIR_POST_302 = 2;
const CURL_REDIR_POST_303 = 4;
const CURL_REDIR_POST_ALL = 7;

// ── CURL_RTSPREQ_ ───────────────────────────────────────────────

const CURL_RTSPREQ_ANNOUNCE      = 3;
const CURL_RTSPREQ_DESCRIBE      = 2;
const CURL_RTSPREQ_GET_PARAMETER = 8;
const CURL_RTSPREQ_OPTIONS       = 1;
const CURL_RTSPREQ_PAUSE         = 6;
const CURL_RTSPREQ_PLAY          = 5;
const CURL_RTSPREQ_RECEIVE       = 11;
const CURL_RTSPREQ_RECORD        = 10;
const CURL_RTSPREQ_SETUP         = 4;
const CURL_RTSPREQ_SET_PARAMETER = 9;
const CURL_RTSPREQ_TEARDOWN      = 7;

// ── CURL_SSLVERSION_ ────────────────────────────────────────────

const CURL_SSLVERSION_DEFAULT     = 0;
const CURL_SSLVERSION_MAX_DEFAULT = 65536;
const CURL_SSLVERSION_MAX_NONE    = 0;
const CURL_SSLVERSION_MAX_TLSv1_0 = 262144;
const CURL_SSLVERSION_MAX_TLSv1_1 = 327680;
const CURL_SSLVERSION_MAX_TLSv1_2 = 393216;
const CURL_SSLVERSION_MAX_TLSv1_3 = 458752;
const CURL_SSLVERSION_SSLv2       = 2;
const CURL_SSLVERSION_SSLv3       = 3;
const CURL_SSLVERSION_TLSv1       = 1;
const CURL_SSLVERSION_TLSv1_0     = 4;
const CURL_SSLVERSION_TLSv1_1     = 5;
const CURL_SSLVERSION_TLSv1_2     = 6;
const CURL_SSLVERSION_TLSv1_3     = 7;

// ── CURL_TIMECOND_ ──────────────────────────────────────────────

const CURL_TIMECOND_IFMODSINCE   = 1;
const CURL_TIMECOND_IFUNMODSINCE = 2;
const CURL_TIMECOND_LASTMOD      = 3;
const CURL_TIMECOND_NONE         = 0;

// ── CURL_VERSION_ ───────────────────────────────────────────────

const CURL_VERSION_ALTSVC       = 16777216;
const CURL_VERSION_ASYNCHDNS    = 128;
const CURL_VERSION_BROTLI       = 8388608;
const CURL_VERSION_CONV         = 4096;
const CURL_VERSION_CURLDEBUG    = 8192;
const CURL_VERSION_DEBUG        = 64;
const CURL_VERSION_GSASL        = 536870912;
const CURL_VERSION_GSSAPI       = 131072;
const CURL_VERSION_GSSNEGOTIATE = 32;
const CURL_VERSION_HSTS         = 268435456;
const CURL_VERSION_HTTP2        = 65536;
const CURL_VERSION_HTTP3        = 33554432;
const CURL_VERSION_HTTPS_PROXY  = 2097152;
const CURL_VERSION_IDN          = 1024;
const CURL_VERSION_IPV6         = 1;
const CURL_VERSION_KERBEROS4    = 2;
const CURL_VERSION_KERBEROS5    = 262144;
const CURL_VERSION_LARGEFILE    = 512;
const CURL_VERSION_LIBZ         = 8;
const CURL_VERSION_MULTI_SSL    = 4194304;
const CURL_VERSION_NTLM         = 16;
const CURL_VERSION_NTLM_WB      = 32768;
const CURL_VERSION_PSL          = 1048576;
const CURL_VERSION_SPNEGO       = 256;
const CURL_VERSION_SSL          = 4;
const CURL_VERSION_SSPI         = 2048;
const CURL_VERSION_TLSAUTH_SRP  = 16384;
const CURL_VERSION_UNICODE      = 134217728;
const CURL_VERSION_UNIX_SOCKETS = 524288;
const CURL_VERSION_ZSTD         = 67108864;
