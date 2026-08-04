<?php

/**
 * ext/curl — the multi and share interfaces.
 *
 * A separate, separately demand-gated file so a program that makes one request
 * at a time carries none of it. It names {@see __McCurl} and {@see CurlHandle},
 * so it is concatenated AFTER curl.php — classes are built in SOURCE order and a
 * forward reference inherits nothing.
 *
 * WAITING: `curl_multi_poll` (libcurl 7.66+), never `curl_multi_fdset` +
 * select(2). fdset means building an fd_set triple by hand — 128 bytes whose
 * layout differs per platform — and it mishandles the "no fds to wait on yet"
 * case that poll/wait were introduced to fix. **This extension requires libcurl
 * >= 7.68.0**, which is Ubuntu 20.04's baseline and also gives curl_easy_upkeep
 * and the CURLINFO_*_T family the easy API leans on.
 *
 * OWNERSHIP: a multi handle CO-OWNS every easy handle added to it. php gets that
 * from the CurlHandle object's own refcount; here the object is parked in
 * `__McCurl::$obj` under its id and the multi additionally records the id in
 * `__McCurl::$multiKids`, so a caller dropping its `$ch` while the transfer is
 * still running cannot run __destruct → curl_easy_cleanup under libcurl.
 */

// ── FFI ─────────────────────────────────────────────────────────────────────

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_init')]
function __mc_curlm_init(): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_add_handle'), \Ffi\CType('int')]
function __mc_curlm_add(\Ffi\Ptr $m, \Ffi\Ptr $e): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_remove_handle'), \Ffi\CType('int')]
function __mc_curlm_remove(\Ffi\Ptr $m, \Ffi\Ptr $e): int {}

/** `CURLMcode curl_multi_perform(CURLM *, int *running_handles)` */
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_perform'), \Ffi\CType('int')]
function __mc_curlm_perform(\Ffi\Ptr $m, \Ffi\Ptr $running): int {}

/**
 * `CURLMcode curl_multi_poll(CURLM *, struct curl_waitfd extra_fds[],
 *                            unsigned int extra_nfds, int timeout_ms,
 *                            int *numfds)`
 */
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_poll'), \Ffi\CType('int')]
function __mc_curlm_poll(\Ffi\Ptr $m, \Ffi\Ptr $extra, #[\Ffi\CType('uint')] int $nextra,
                         #[\Ffi\CType('int')] int $timeoutMs, \Ffi\Ptr $numfds): int {}

/** `CURLMsg *curl_multi_info_read(CURLM *, int *msgs_in_queue)` */
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_info_read')]
function __mc_curlm_info_read(\Ffi\Ptr $m, \Ffi\Ptr $left): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_cleanup'), \Ffi\CType('int')]
function __mc_curlm_cleanup(\Ffi\Ptr $m): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_strerror')]
function __mc_curlm_strerror(#[\Ffi\CType('int')] int $code): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_multi_setopt'), \Ffi\Variadic(2), \Ffi\CType('int')]
function __mc_curlm_setopt(\Ffi\Ptr $m, #[\Ffi\CType('int')] int $opt,
                           #[\Ffi\CType('long')] int $val): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_share_init')]
function __mc_curlsh_init(): \Ffi\Ptr {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_share_setopt'), \Ffi\Variadic(2), \Ffi\CType('int')]
function __mc_curlsh_setopt(\Ffi\Ptr $sh, #[\Ffi\CType('int')] int $opt,
                            #[\Ffi\CType('long')] int $val): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_share_cleanup'), \Ffi\CType('int')]
function __mc_curlsh_cleanup(\Ffi\Ptr $sh): int {}

#[\Ffi\Library('curl'), \Ffi\Symbol('curl_share_strerror')]
function __mc_curlsh_strerror(#[\Ffi\CType('int')] int $code): \Ffi\Ptr {}

// ── Handles ─────────────────────────────────────────────────────────────────

final class CurlMultiHandle
{
    public int $addr = 0;
    public int $id = 0;
    public bool $closed = false;

    public function __destruct()
    {
        if (!$this->closed && $this->addr !== 0) {
            \__mc_curlm_teardown($this);
        }
    }
}

final class CurlShareHandle
{
    public int $addr = 0;
    public int $id = 0;
    public bool $closed = false;

    public function __destruct()
    {
        if (!$this->closed && $this->addr !== 0) {
            \__mc_curlsh_cleanup(\int_to_ptr($this->addr));
            \__mc_curl_release($this->id);
            $this->closed = true;
            $this->addr = 0;
        }
    }
}

/**
 * ⚠ Every easy handle must be REMOVED before curl_multi_cleanup. libcurl says so
 * outright, and leaving them in throws away the connection cache the multi
 * exists to keep.
 */
function __mc_curlm_teardown(CurlMultiHandle $mh): void
{
    $m = \int_to_ptr($mh->addr);
    if (isset(__McCurl::$multiKids[$mh->id])) {
        foreach (__McCurl::$multiKids[$mh->id] as $kid) {
            if (isset(__McCurl::$addr[$kid]) && __McCurl::$addr[$kid] !== 0) {
                \__mc_curlm_remove($m, \int_to_ptr(__McCurl::$addr[$kid]));
            }
        }
    }
    \__mc_curlm_cleanup($m);
    unset(__McCurl::$multiKids[$mh->id]);
    \__mc_curl_release($mh->id);
    $mh->closed = true;
    $mh->addr = 0;
}

// ── curl_multi_* ────────────────────────────────────────────────────────────

function curl_multi_init(): CurlMultiHandle
{
    if (!__McCurl::$globalInit) {
        \__mc_curl_global_init(3);
        __McCurl::$globalInit = true;
    }
    $m = \__mc_curlm_init();
    if (\ptr_to_int($m) === 0) {
        throw new \RuntimeException('curl_multi_init(): curl_multi_init() failed');
    }
    $id = __McCurl::$nextId;
    __McCurl::$nextId = $id + 1;

    $mh = new CurlMultiHandle();
    $mh->addr = \ptr_to_int($m);
    $mh->id = $id;

    __McCurl::$addr[$id] = $mh->addr;
    __McCurl::$obj[$id] = $mh;
    __McCurl::$errno[$id] = 0;
    __McCurl::$multiKids[$id] = [];
    return $mh;
}

function curl_multi_add_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int
{
    if ($multi_handle->closed) {
        throw new \ValueError('curl_multi_add_handle(): Argument #1 ($multi_handle) is closed');
    }
    if ($handle->closed) {
        throw new \ValueError('curl_multi_add_handle(): Argument #2 ($handle) is a closed cURL handle');
    }
    $id = $handle->id;
    // Nothing resets this for us: curl_exec is the only other place that clears
    // the accumulator, and a multi transfer never goes through it.
    __McCurl::$body[$id] = '';
    unset(__McCurl::$cbErr[$id]);
    if (isset(__McCurl::$errBuf[$id]) && __McCurl::$errBuf[$id] !== 0) {
        \poke_i8(\int_to_ptr(__McCurl::$errBuf[$id]), 0, 0);
    }

    $rc = \__mc_curlm_add(\int_to_ptr($multi_handle->addr), \int_to_ptr($handle->addr));
    __McCurl::$errno[$multi_handle->id] = $rc;
    if ($rc === 0) {
        $kids = __McCurl::$multiKids[$multi_handle->id];
        $kids[] = $id;
        __McCurl::$multiKids[$multi_handle->id] = $kids;
    }
    return $rc;
}

function curl_multi_remove_handle(CurlMultiHandle $multi_handle, CurlHandle $handle): int
{
    if ($multi_handle->closed || $handle->closed) {
        throw new \ValueError('curl_multi_remove_handle(): a supplied handle is closed');
    }
    $rc = \__mc_curlm_remove(\int_to_ptr($multi_handle->addr), \int_to_ptr($handle->addr));
    __McCurl::$errno[$multi_handle->id] = $rc;
    // Rebuild rather than unset(): the kid list is a PACKED array, and unset on
    // a vec element is a silent no-op.
    $keep = [];
    if (isset(__McCurl::$multiKids[$multi_handle->id])) {
        foreach (__McCurl::$multiKids[$multi_handle->id] as $kid) {
            if ($kid !== $handle->id) { $keep[] = $kid; }
        }
    }
    __McCurl::$multiKids[$multi_handle->id] = $keep;
    return $rc;
}

function curl_multi_exec(CurlMultiHandle $multi_handle, int &$still_running): int
{
    if ($multi_handle->closed) {
        throw new \ValueError('curl_multi_exec(): Argument #1 ($multi_handle) is closed');
    }
    $out = \__mc_curl_calloc(4, 1);
    $rc = \__mc_curlm_perform(\int_to_ptr($multi_handle->addr), $out);
    $still_running = \peek_i32($out, 0);
    \__mc_curl_free($out);
    __McCurl::$errno[$multi_handle->id] = $rc;

    // A trampoline that could not throw through libcurl parked its Throwable.
    // Surface it here, on our own stack — this is the only place a multi
    // transfer's callbacks are ever driven from.
    if (isset(__McCurl::$multiKids[$multi_handle->id])) {
        foreach (__McCurl::$multiKids[$multi_handle->id] as $kid) {
            if (isset(__McCurl::$cbErr[$kid])) {
                $e = __McCurl::$cbErr[$kid];
                unset(__McCurl::$cbErr[$kid]);
                throw $e;
            }
        }
    }
    return $rc;
}

function curl_multi_select(CurlMultiHandle $multi_handle, float $timeout = 1.0): int
{
    if ($multi_handle->closed) {
        throw new \ValueError('curl_multi_select(): Argument #1 ($multi_handle) is closed');
    }
    $out = \__mc_curl_calloc(4, 1);
    $ms = (int) ($timeout * 1000.0);
    $rc = \__mc_curlm_poll(\int_to_ptr($multi_handle->addr), \int_to_ptr(0), 0, $ms, $out);
    $n = \peek_i32($out, 0);
    \__mc_curl_free($out);
    __McCurl::$errno[$multi_handle->id] = $rc;
    // php reports -1 for a CURLM error rather than throwing.
    return $rc === 0 ? $n : -1;
}

function curl_multi_getcontent(CurlHandle $handle): ?string
{
    $id = $handle->id;
    // Whatever the write trampoline accumulated, which is non-empty exactly when
    // the handle was set to W_RETURN. No libcurl call is involved.
    if (!isset(__McCurl::$writeMethod[$id])
        || __McCurl::$writeMethod[$id] !== __McCurl::W_RETURN) {
        return null;
    }
    return isset(__McCurl::$body[$id]) ? __McCurl::$body[$id] : null;
}

function curl_multi_info_read(CurlMultiHandle $multi_handle, &$queued_messages = null): array|false
{
    if ($multi_handle->closed) {
        throw new \ValueError('curl_multi_info_read(): Argument #1 ($multi_handle) is closed');
    }
    $left = \__mc_curl_calloc(4, 1);
    $msg = \__mc_curlm_info_read(\int_to_ptr($multi_handle->addr), $left);
    $queued_messages = \peek_i32($left, 0);
    \__mc_curl_free($left);
    if (\ptr_to_int($msg) === 0) { return false; }

    // struct CURLMsg { CURLMSG msg; CURL *easy_handle; union { void *whatever;
    //                  CURLcode result; } data; }
    //
    // On every LP64 target, with natural alignment and no packing attribute:
    // msg@0 (int, 4 bytes + 4 padding), easy_handle@8, data@16, sizeof 24 —
    // verified with offsetof against this libcurl. It is the ONE struct this
    // extension dereferences, so the read below proves itself rather than
    // trusting the comment: an easy_handle that matches no live handle means the
    // layout moved, and that is a clean error instead of a wrong answer.
    $what = \peek_i32($msg, 0);
    $eh = \peek_i64($msg, 8);
    $res = \peek_i32($msg, 16);
    $obj = \__mc_curl_by_addr($eh);
    if ($obj === null) {
        throw new \RuntimeException('curl_multi_info_read(): CURLMsg layout unexpected on this '
            . 'libcurl — the easy_handle read at +8 matches no live handle');
    }
    __McCurl::$errno[$obj->id] = $res;

    // ⚠ Built as a LITERAL, not by three appends to an `$out = []`. Spelled the
    // other way the int keys come back raw — `$m['msg']` printed
    // 4.9406564584125E-324, the double whose bits are 1 — because a three-entry
    // heterogeneous array leaves its element type erased and the store and the
    // read then disagree about the repr. Same family as
    // tests/aot/cases/array_erased_elem_repr_gap.php; the literal path boxes its
    // values up front and is immune.
    return ['msg' => $what, 'result' => $res, 'handle' => $obj];
}

function curl_multi_setopt(CurlMultiHandle $multi_handle, int $option, mixed $value): bool
{
    if ($multi_handle->closed) {
        throw new \ValueError('curl_multi_setopt(): Argument #1 ($multi_handle) is closed');
    }
    // CURLMOPT_* use the same TYPE+ordinal encoding as CURLOPT_*, so the same
    // classifier applies. The two FUNCTIONPOINT options are server push, which
    // needs a curl_pushheaders API this extension does not bind.
    $class = \intdiv($option, 10000);
    if ($class === 2 || $option === 10015) {
        throw new \ValueError('curl_multi_setopt(): CURLMOPT #' . $option
            . ' is a push callback and is not settable from PHP');
    }
    if ($class !== 0 && $class !== 3) {
        throw new \ValueError('curl_multi_setopt(): Argument #2 ($option) is not a valid cURL multi option');
    }
    return \__mc_curlm_setopt(\int_to_ptr($multi_handle->addr), $option,
                              \__mc_curl_to_long($value)) === 0;
}

function curl_multi_close(CurlMultiHandle $multi_handle): void
{
    if ($multi_handle->closed) { return; }
    \__mc_curlm_teardown($multi_handle);
}

function curl_multi_errno(CurlMultiHandle $multi_handle): int
{
    return isset(__McCurl::$errno[$multi_handle->id]) ? __McCurl::$errno[$multi_handle->id] : 0;
}

function curl_multi_strerror(int $error_code): ?string
{
    $p = \__mc_curlm_strerror($error_code);
    if (\ptr_to_int($p) === 0) { return null; }
    $s = \cstr_to_str($p);
    if ($s === 'Unknown error') { return null; }
    return $s;
}

// ── curl_share_* ────────────────────────────────────────────────────────────

function curl_share_init(): CurlShareHandle
{
    if (!__McCurl::$globalInit) {
        \__mc_curl_global_init(3);
        __McCurl::$globalInit = true;
    }
    $s = \__mc_curlsh_init();
    if (\ptr_to_int($s) === 0) {
        throw new \RuntimeException('curl_share_init(): curl_share_init() failed');
    }
    $id = __McCurl::$nextId;
    __McCurl::$nextId = $id + 1;

    $sh = new CurlShareHandle();
    $sh->addr = \ptr_to_int($s);
    $sh->id = $id;

    __McCurl::$addr[$id] = $sh->addr;
    __McCurl::$obj[$id] = $sh;
    __McCurl::$errno[$id] = 0;
    return $sh;
}

function curl_share_setopt(CurlShareHandle $share_handle, int $option, mixed $value): bool
{
    if ($share_handle->closed) {
        throw new \ValueError('curl_share_setopt(): Argument #1 ($share_handle) is closed');
    }
    // CURLSHOPT_SHARE(1) / UNSHARE(2) only. LOCKFUNC / UNLOCKFUNC / USERDATA
    // exist but are meaningless single-threaded, and php does not expose them
    // either.
    if ($option !== 1 && $option !== 2) {
        throw new \ValueError('curl_share_setopt(): Argument #2 ($option) is not a valid cURL share option');
    }
    $rc = \__mc_curlsh_setopt(\int_to_ptr($share_handle->addr), $option,
                              \__mc_curl_to_long($value));
    __McCurl::$errno[$share_handle->id] = $rc;
    return $rc === 0;
}

function curl_share_close(CurlShareHandle $share_handle): void
{
    // php 8.0 made this a no-op for the same reason curl_close is one: the
    // handle is an object and dies with its last reference. Cleaning up here
    // would also be wrong while an easy handle still references the share —
    // curl_share_cleanup answers CURLSHE_IN_USE and libcurl keeps it alive.
}

function curl_share_errno(CurlShareHandle $share_handle): int
{
    return isset(__McCurl::$errno[$share_handle->id]) ? __McCurl::$errno[$share_handle->id] : 0;
}

function curl_share_strerror(int $error_code): ?string
{
    $p = \__mc_curlsh_strerror($error_code);
    if (\ptr_to_int($p) === 0) { return null; }
    $s = \cstr_to_str($p);
    if ($s === 'Unknown error') { return null; }
    return $s;
}
