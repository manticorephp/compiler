<?php

// TCP client transport. php.net semantics for fsockopen / stream_socket_client;
// the stream wrappers on top live with the rest of the IO surface.
//
// The design goal here was to need as LITTLE host knowledge as possible, because
// every byte of it is a silent-corruption risk. What survived that squeeze:
//
//  * No sockaddr is ever built or parsed. getaddrinfo hands back a ready
//    `ai_addr` + `ai_addrlen`; they go straight into connect() as an opaque
//    blob. That erases Darwin's sin_len shift (sin_family @1 w1 vs glibc's
//    @0 w2) AND the AF_INET6 split (30 vs 10) — we never name a family, we
//    forward whatever the resolver picked.
//  * Timeouts are poll(), not SO_RCVTIMEO. poll takes an int MILLISECONDS and no
//    struct, which kills three divergences at once: SOL_SOCKET (65535 vs 1),
//    SO_RCVTIMEO (0x1006 vs 20) and timeval.tv_usec (4 vs 8 bytes). struct
//    pollfd is byte-identical on every target probed.
//  * hints is NULL. Building one would make us depend on sizeof(addrinfo);
//    filtering the results on ai_socktype needs only offsets we already have.
//    (NULL hints also returns DGRAM/RAW entries, so the filter is mandatory.)
//
// What could NOT be squeezed out is the `struct addrinfo` walk — and that is
// exactly where the trap lives, so it is MEASURED, not remembered:
// measured across 5 distros × 2 arches (tools/docker/PROBE_RESULTS.md).

/**
 * `struct addrinfo` field offsets. $which: 0=ai_family 1=ai_socktype
 * 2=ai_protocol 3=ai_addrlen 4=ai_addr 5=ai_next.
 *
 * Int statics, never an array — same reason {@see __mc_stat_off} spells out: an
 * assoc BUILT INSIDE the stdlib faults once it crosses a call boundary, and a
 * runtime string key on one silently misses. Ints carry no refcount and dodge both.
 */
function __mc_ai_off(int $which): int
{
    static $ready = 0;
    static $family = 0;
    static $socktype = 0;
    static $protocol = 0;
    static $addrlen = 0;
    static $addr = 0;
    static $next = 0;

    if ($ready === 0) {
        // MEASURED (tools/docker/PROBE_RESULTS.md), identical on glibc 2.31/2.35/2.36/2.39
        // AND musl, arm64 + x86_64 — the split is Darwin vs Linux, not per-arch
        // and not per-libc:
        //   both:   sizeof 48, ai_flags@0 ai_family@4 ai_socktype@8
        //           ai_protocol@12 ai_addrlen@16(w4) ai_next@40
        //   Darwin: ai_canonname@24  ai_addr@32
        //   Linux:  ai_addr@24       ai_canonname@32     <-- SWAPPED
        // Reading ai_addr at the wrong offset hands connect() a char* canonical
        // name instead of a sockaddr: not a crash, just a wrong pointer.
        $family = 4;
        $socktype = 8;
        $protocol = 12;
        $addrlen = 16;
        $next = 40;
        $addr = \__mc_host_is_darwin() ? 32 : 24;
        $ready = 1;
    }

    if ($which === 0) { return $family; }
    if ($which === 1) { return $socktype; }
    if ($which === 2) { return $protocol; }
    if ($which === 3) { return $addrlen; }
    if ($which === 4) { return $addr; }
    return $next;
}

/**
 * Host network constants. $which: 0=SOCK_STREAM 1=POLLIN 2=POLLOUT 3=POLLERR
 * 4=POLLHUP 5=SOL_SOCKET 6=SO_ERROR 7=SHUT_RDWR.
 *
 * SOCK_STREAM / POLL* / SHUT_* are the same everywhere (measured); SOL_SOCKET
 * and SO_ERROR are not, and they are the only two the design still needs — for
 * reading a failed connect's cause without errno.
 */
function __mc_net_const(int $which): int
{
    static $ready = 0;
    static $solSocket = 0;
    static $soError = 0;
    static $niNumericHost = 0;
    static $afInet6 = 0;
    static $niNumericServ = 0;

    if ($ready === 0) {
        // MEASURED: Darwin SOL_SOCKET 65535 / SO_ERROR 0x1007 / NI_NUMERICHOST 2 /
        // NI_NUMERICSERV 8 / AF_INET6 30; Linux 1 / 4 / 1 / 2 / 10.
        $isDarwin = \__mc_host_is_darwin();
        $solSocket = $isDarwin ? 65535 : 1;
        $soError = $isDarwin ? 4103 : 4;
        $niNumericHost = $isDarwin ? 2 : 1;
        $niNumericServ = $isDarwin ? 8 : 2;
        $afInet6 = $isDarwin ? 30 : 10;
        $ready = 1;
    }

    if ($which === 0) { return 1; }      // SOCK_STREAM — 1 on every target
    if ($which === 1) { return 1; }      // POLLIN
    if ($which === 2) { return 4; }      // POLLOUT
    if ($which === 3) { return 8; }      // POLLERR
    if ($which === 4) { return 16; }     // POLLHUP
    if ($which === 5) { return $solSocket; }
    if ($which === 6) { return $soError; }
    if ($which === 8) { return $niNumericHost; }
    if ($which === 9) { return $afInet6; }
    if ($which === 10) { return $niNumericServ; }
    return 2;                            // SHUT_RDWR — 0/1/2 everywhere
}

/**
 * Wait for $fd to become readable (or writable when $forWrite) within
 * $timeoutMs. Returns >0 ready, 0 on timeout, -1 on error.
 *
 * struct pollfd is `{ int fd; short events; short revents; }` — 8 bytes,
 * fd@0 events@4(w2) revents@6 — byte-identical on every probed target, which is
 * the whole reason timeouts route through poll() instead of SO_RCVTIMEO.
 */
function __mc_poll_one(int $fd, bool $forWrite, int $timeoutMs): int
{
    $pfd = \Runtime\Libc\calloc(8, 1);
    if ($pfd === null) {
        return -1;
    }
    \poke_i32($pfd, 0, $fd);
    \poke_i16($pfd, 4, $forWrite ? \__mc_net_const(2) : \__mc_net_const(1));
    \poke_i16($pfd, 6, 0);
    $rc = \Runtime\Libc\sys_poll($pfd, 1, $timeoutMs);
    if ($rc > 0) {
        // POLLERR/POLLHUP arrive in revents even when they were not requested,
        // so a "ready" fd may in fact be a dead one. Report it as an error
        // rather than letting the caller recv() into a surprise.
        $rev = \peek_i16($pfd, 6);
        if (($rev & (\__mc_net_const(3) | \__mc_net_const(4))) !== 0) {
            $rc = -1;
        }
    }
    \Runtime\Libc\free($pfd);
    return $rc;
}

/**
 * php.net's stream_set_timeout: bound how long a read on $stream waits for data.
 * 0/0 keeps the default (php's default_socket_timeout, 60s); a positive value caps
 * each read at that long, after which the read returns empty and
 * stream_get_meta_data()['timed_out'] is true.
 */
function stream_set_timeout(\Resource $stream, int $seconds, int $microseconds = 0): bool
{
    $ms = $seconds * 1000 + \intdiv($microseconds, 1000);
    $stream->rtimeoutMs = $ms > 0 ? $ms : 0;
    return true;
}

/**
 * Wait up to $timeoutMs for $fd to be readable, for a bounded blocking read.
 * Returns >0 (proceed to recv — data, or a POLLHUP whose recv drains the tail and
 * then reports EOF), 0 (timed out), <0 (poll error — the caller proceeds to recv
 * and lets it report). Unlike __mc_poll_one this does NOT fold POLLHUP into an
 * error: at EOF the last bytes must still be read.
 */
function __mc_poll_readable(int $fd, int $timeoutMs): int
{
    $pfd = \Runtime\Libc\calloc(8, 1);
    if ($pfd === null) {
        return 1;   // cannot poll ⇒ do not turn a read into a hang; just recv
    }
    \poke_i32($pfd, 0, $fd);
    \poke_i16($pfd, 4, \__mc_net_const(1));   // POLLIN
    \poke_i16($pfd, 6, 0);
    $rc = \Runtime\Libc\sys_poll($pfd, 1, $timeoutMs);
    \Runtime\Libc\free($pfd);
    return $rc;
}

/**
 * The pending error on a socket, via getsockopt(SO_ERROR) — 0 when there is
 * none. This is the errno-free way to learn WHY a connect failed: there is no
 * errno binding (Darwin exports `__error()`, glibc `__errno_location()`, so
 * binding both would leave an undefined symbol on whichever host lacks the
 * other — the same family as the open `stat` vs `__xstat` gap on glibc < 2.33).
 */
function __mc_sock_error(int $fd): int
{
    $val = \Runtime\Libc\calloc(4, 1);
    $len = \Runtime\Libc\calloc(4, 1);
    if ($val === null || $len === null) {
        if ($val !== null) { \Runtime\Libc\free($val); }
        if ($len !== null) { \Runtime\Libc\free($len); }
        return -1;
    }
    \poke_i32($len, 0, 4);
    $rc = \Runtime\Libc\sys_getsockopt($fd, \__mc_net_const(5), \__mc_net_const(6), $val, $len);
    $err = $rc === 0 ? \peek_i32($val, 0) : -1;
    \Runtime\Libc\free($val);
    \Runtime\Libc\free($len);
    return $err;
}

/**
 * Open a blocking TCP connection to $host:$port, or false.
 *
 * The canonical getaddrinfo walk: try each candidate in turn and keep the first
 * that connects. That is what makes the resolver's choice — v4 vs v6, and which
 * address of a multi-homed name — none of our business.
 *
 * @return \Resource|false
 */
function __mc_tcp_connect(string $host, int $port, int $wantType = 1)
{
    // $wantType is the socket type to select from the resolver's list: 1
    // SOCK_STREAM (tcp), 2 SOCK_DGRAM (udp) — both values are the same on every
    // target. A DGRAM connect() sets the default peer, so send()/recv() then work
    // without naming the address each call.
    \__mc_net_errno(true, 0);   // clear stale errno; the walk records the real one
    $res = \Runtime\Libc\calloc(8, 1);
    if ($res === null) {
        return false;
    }
    // hints = NULL: see the file header. The result list then also carries
    // the OTHER socktypes, which the ai_socktype filter below drops.
    $rc = \Runtime\Libc\sys_getaddrinfo($host, (string)$port, \int_to_ptr(0), $res);
    if ($rc !== 0) {
        \Runtime\Libc\free($res);
        return false;
    }
    $head = \peek_i64($res, 0);
    \Runtime\Libc\free($res);
    if ($head === 0) {
        return false;
    }

    $fd = -1;
    $ai = $head;
    while ($ai !== 0) {
        $sockType = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(1));
        if ($sockType === $wantType) {
            $family = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(0));
            $proto = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(2));
            $cand = \Runtime\Libc\sys_socket($family, $sockType, $proto);
            if ($cand >= 0) {
                // ai_addr is a POINTER field; ai_addrlen is a 4-byte socklen_t.
                // Both are forwarded untouched — the one place the addrinfo
                // offset table has to be right (Darwin swaps ai_addr with
                // ai_canonname, so a wrong table passes a char* here).
                $addr = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(4));
                $addrLen = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(3));
                if (\Runtime\Libc\sys_connect($cand, \int_to_ptr($addr), $addrLen) === 0) {
                    $fd = $cand;
                    break;
                }
                \__mc_net_errno(true, \__mc_errno());   // capture BEFORE close clobbers errno
                \Runtime\Libc\sys_close($cand);
            }
        }
        $ai = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(5));
    }
    \Runtime\Libc\sys_freeaddrinfo(\int_to_ptr($head));

    if ($fd < 0) {
        return false;
    }
    // Remember the host on the concrete resource (never on a T|false cell — that
    // derefs the boxed handle) so a deferred STARTTLS has an SNI + verify target.
    $r = new \Resource(\Resource::KIND_SOCKET, 'stream', $fd);
    $r->host = $host;
    return $r;
}

/**
 * Open a TLS-over-TCP connection to $host:$port and return a KIND_TLS resource,
 * or false. The TCP walk is __mc_tcp_connect's; this wraps the fd in an OpenSSL
 * engine and drives the handshake. The read buffer, fgets, chunked decoding and
 * the whole HTTP layer above are transport-agnostic — they see a stream, not a
 * socket vs a TLS session (dispatch is in __mc_transport_recv/_send).
 *
 * Security is ON by default, matching php's stream defaults: the peer chain is
 * verified against the host trust store AND the leaf cert must match $host. A
 * self-signed or wrong-host cert therefore fails the handshake — the same result
 * php gives without verify_peer=false (which needs stream_context_create, not
 * yet wired).
 *
 * @return \Resource|false
 */
function __mc_tls_connect(string $host, int $port, bool $verifyPeer = true, bool $verifyName = true)
{
    $sock = \__mc_tcp_connect($host, $port);
    if ($sock === false) {
        return false;
    }
    // ⚠ $sock is typed \Resource|false here, i.e. a CELL — writing $sock->ssl on
    // it would deref the NaN-boxed handle as a raw pointer and SIGSEGV. The
    // handshake (which mutates the resource) MUST run through a \Resource-typed
    // parameter so codegen unboxes to a real object handle. Same funnel Ф3 uses
    // for __mc_http_read_response.
    if (!\__mc_tls_handshake($sock, $host, $verifyPeer, $verifyName)) {
        \fclose($sock);
        return false;
    }
    return $sock;
}

/**
 * Wrap an already-connected TCP resource in an OpenSSL engine and drive the
 * handshake, mutating $sock to KIND_TLS in place on success. Returns false on any
 * failure (the caller closes the fd). $sock is \Resource-typed on purpose — see
 * the warning in __mc_tls_connect.
 */
function __mc_tls_handshake(\Resource $sock, string $host, bool $verifyPeer = true, bool $verifyName = true): bool
{
    $method = \Runtime\Openssl\clientMethod();   // highest TLS the peer offers
    if ($method === 0) {
        return false;
    }
    $ctx = \Runtime\Openssl\ctxNew($method);
    if ($ctx === 0) {
        return false;
    }
    // Trust store + SSL_VERIFY_PEER (1) so SSL_connect fails a bad chain rather
    // than completing an unauthenticated session. A context with
    // verify_peer=false requests SSL_VERIFY_NONE (0) — the handshake then
    // completes for a self-signed or otherwise untrusted cert, matching php.
    \Runtime\Openssl\ctxSetDefaultVerifyPaths($ctx);
    \Runtime\Openssl\ctxSetVerify($ctx, $verifyPeer ? 1 : 0, \int_to_ptr(0));
    $ssl = \Runtime\Openssl\sslNew($ctx);
    // SSL_new took a ref on the ctx, so it lives until SSL_free — drop ours now.
    \Runtime\Openssl\ctxFree($ctx);
    if ($ssl === 0) {
        return false;
    }
    // SNI (SSL_set_tlsext_host_name via SSL_ctrl): a name-based vhost serves the
    // right cert only when told which host. 55 = SSL_CTRL_SET_TLSEXT_HOSTNAME,
    // 0 = TLSEXT_NAMETYPE_host_name.
    \Runtime\Openssl\ctrl($ssl, 55, 0, $host);
    // The hostname the leaf cert must match — without it a valid chain issued for
    // a DIFFERENT host would pass. Skipped when verify_peer_name is off.
    if ($verifyName) {
        \Runtime\Openssl\set1Host($ssl, $host);
    }
    if (\Runtime\Openssl\setFd($ssl, $sock->addr) !== 1
        || \Runtime\Openssl\connect($ssl) !== 1) {
        \Runtime\Openssl\sslFree($ssl);   // does not touch the fd
        return false;
    }
    // Upgrade the TCP resource in place: same fd, now with a TLS engine over it.
    $sock->kind = \Resource::KIND_TLS;
    $sock->type = 'stream';
    $sock->ssl = $ssl;
    return true;
}

/**
 * Open a stream for a transport scheme: tcp:// (or bare) → plain socket, ssl:// /
 * tls:// → TLS. Shared by fsockopen and stream_socket_client so both grew TLS at
 * once. udp:// / unix:// route to their own connectors. TLS verifies by default,
 * matching php's stream defaults since 5.6; a $context (from stream_socket_client)
 * toggles ssl.verify_peer / verify_peer_name per-socket.
 * @return \Resource|false
 */
function __mc_transport_connect(string $scheme, string $host, int $port, ?\Resource $context = null)
{
    if ($scheme === 'ssl' || $scheme === 'tls') {
        // A context now toggles verification per-socket (defaults on, matching php).
        // $context is nullable (a cell) — read the flags only through a typed helper.
        $vp = true;
        $vn = true;
        if ($context !== null) {
            $f = \__mc_ctx_verify_flags($context);
            $vp = $f[0];
            $vn = $f[1];
        }
        return \__mc_tls_connect($host, $port, $vp, $vn);
    }
    if ($scheme === 'tcp' || $scheme === '') {
        return \__mc_tcp_connect($host, $port);
    }
    if ($scheme === 'udp') {
        return \__mc_tcp_connect($host, $port, 2);   // 2 = SOCK_DGRAM
    }
    if ($scheme === 'unix') {
        return \__mc_unix_connect($host);            // $host carries the path
    }
    return false;
}

/**
 * Fill a zeroed >=128-byte buffer as a sockaddr_un for $path and return its
 * addrlen. AF_UNIX is 1 and sun_path sits at offset 2 on BOTH hosts — the only
 * divergence is the family field: Darwin splits offset 0 into sun_len(u8) +
 * sun_family(u8)@1, glibc/musl use a u16 sun_family@0. (This is the one place net
 * builds a sockaddr by hand — getaddrinfo does not do AF_UNIX.)
 */
function __mc_unix_fill(\Ffi\Ptr $buf, string $path): int
{
    $plen = \strlen($path);
    if (\__mc_host_is_darwin()) {
        \poke_i8($buf, 0, 2 + $plen + 1);   // sun_len
        \poke_i8($buf, 1, 1);               // sun_family = AF_UNIX
    } else {
        \poke_i16($buf, 0, 1);              // sun_family = AF_UNIX (u16)
    }
    for ($i = 0; $i < $plen; $i = $i + 1) {
        \poke_i8($buf, 2 + $i, \ord($path[$i]));   // sun_path (buffer pre-zeroed ⇒ NUL-terminated)
    }
    return 2 + $plen + 1;                   // offsetof(sun_path) + strlen + NUL
}

/**
 * Connect a Unix-domain stream socket to the filesystem $path, or false.
 * @return \Resource|false
 */
function __mc_unix_connect(string $path)
{
    $fd = \Runtime\Libc\sys_socket(1, 1, 0);   // AF_UNIX(1), SOCK_STREAM(1), proto 0
    if ($fd < 0) {
        return false;
    }
    $buf = \Runtime\Libc\calloc(128, 1);
    if ($buf === null) {
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    $len = \__mc_unix_fill($buf, $path);
    $rc = \Runtime\Libc\sys_connect($fd, $buf, $len);
    \Runtime\Libc\free($buf);
    if ($rc !== 0) {
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    $r = new \Resource(\Resource::KIND_SOCKET, 'stream', $fd);
    $r->host = $path;
    return $r;
}

/**
 * Bind + listen a Unix-domain stream socket at $path, or false. Like php, a stale
 * socket file is NOT auto-removed — bind fails (EADDRINUSE) if $path exists.
 * @return \Resource|false
 */
function __mc_unix_listen(string $path, int $backlog = 16)
{
    $fd = \Runtime\Libc\sys_socket(1, 1, 0);
    if ($fd < 0) {
        return false;
    }
    $buf = \Runtime\Libc\calloc(128, 1);
    if ($buf === null) {
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    $len = \__mc_unix_fill($buf, $path);
    $ok = \Runtime\Libc\sys_bind($fd, $buf, $len) === 0
        && \Runtime\Libc\sys_listen($fd, $backlog) === 0;
    \Runtime\Libc\free($buf);
    if (!$ok) {
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    return new \Resource(\Resource::KIND_SOCKET, 'stream', $fd);
}

/**
 * Build a server SSL_CTX from a PEM cert (+ chain) and private key, or 0 on
 * failure. Reused across every accepted connection (SSL_new refs it), so it is
 * built once at listen time and lives until the listener is closed. $pk may equal
 * $cert (a combined cert+key PEM).
 */
function __mc_tls_server_ctx(string $cert, string $pk): int
{
    $method = \Runtime\Openssl\serverMethod();
    if ($method === 0) {
        return 0;
    }
    $ctx = \Runtime\Openssl\ctxNew($method);
    if ($ctx === 0) {
        return 0;
    }
    // 1 = SSL_FILETYPE_PEM. use_certificate_chain_file also picks up intermediates.
    if (\Runtime\Openssl\ctxUseCertChainFile($ctx, $cert) !== 1
        || \Runtime\Openssl\ctxUsePrivateKeyFile($ctx, $pk, 1) !== 1) {
        \Runtime\Openssl\ctxFree($ctx);
        return 0;
    }
    return $ctx;
}

/**
 * Mark $listener as a TLS server by parking its server SSL_CTX in $ssl — a
 * non-zero $ssl on a LISTENING socket means "SSL_accept every client with this
 * ctx". $listener is \Resource-typed so the write does not deref a boxed handle
 * (the __mc_tcp_listen result is a \Resource|false cell — the Ф4 trap).
 */
function __mc_mark_tls_listener(\Resource $listener, int $ctx): void
{
    $listener->ssl = $ctx;
}

/**
 * Server-side TLS handshake on a freshly accept(2)ed fd, using the listener's
 * shared server ctx. Returns a KIND_TLS \Resource, or false (fd closed).
 * @return \Resource|false
 */
function __mc_tls_accept(int $serverCtx, int $fd)
{
    $ssl = \Runtime\Openssl\sslNew($serverCtx);   // refs the ctx; freed with the SSL
    if ($ssl === 0) {
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    if (\Runtime\Openssl\setFd($ssl, $fd) !== 1
        || \Runtime\Openssl\accept($ssl) !== 1) {
        \Runtime\Openssl\sslFree($ssl);
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    $r = new \Resource(\Resource::KIND_TLS, 'stream', $fd);
    $r->ssl = $ssl;
    return $r;
}

/**
 * Park a context's ssl.* options on a socket for a later STARTTLS. $s is
 * \Resource-typed so a \Resource|false connect/listen result is unboxed (a raw
 * store on the cell would deref the boxed handle). $ctx is likewise typed —
 * callers null-check before calling.
 */
function __mc_attach_ctx(\Resource $s, \Resource $ctx): void
{
    if ($ctx->kind === \Resource::KIND_CONTEXT) {
        $s->ctxBlob = $ctx->rbuf;
    }
}

/**
 * Server-side STARTTLS: upgrade a connected plain socket to TLS in place using a
 * cert+key, mutating $sock to KIND_TLS on success. The server twin of
 * __mc_tls_handshake — SSL_accept instead of SSL_connect. $sock is \Resource-typed
 * so the mutation does not deref a boxed handle.
 */
function __mc_tls_server_handshake(\Resource $sock, string $cert, string $pk): bool
{
    $ctx = \__mc_tls_server_ctx($cert, $pk);
    if ($ctx === 0) {
        return false;
    }
    $ssl = \Runtime\Openssl\sslNew($ctx);
    \Runtime\Openssl\ctxFree($ctx);   // SSL_new took a ref; drop ours (lives until SSL_free)
    if ($ssl === 0) {
        return false;
    }
    if (\Runtime\Openssl\setFd($ssl, $sock->addr) !== 1
        || \Runtime\Openssl\accept($ssl) !== 1) {
        \Runtime\Openssl\sslFree($ssl);   // does not touch the fd
        return false;
    }
    $sock->kind = \Resource::KIND_TLS;
    $sock->type = 'stream';
    $sock->ssl = $ssl;
    return true;
}

/**
 * Open a TCP connection. php.net's signature, including the by-ref diagnostics.
 *
 * $timeout is accepted and currently IGNORED: the connect is blocking, so the
 * OS's own connect timeout applies. Honouring it needs O_NONBLOCK + poll(POLLOUT)
 * + getsockopt(SO_ERROR), which is the natural first step of the Fibers work
 * rather than a bolt-on here.
 *
 * A transport prefix on $hostname is honoured, as php's fsockopen does:
 * `ssl://host` / `tls://host` open a TLS stream, `tcp://host` (or none) a plain
 * one. TLS here verifies by default (no context path through fsockopen).
 *
 * @param int $error_code
 * @param string $error_message
 * @return \Resource|false
 */
/**
 * The last socket errno captured at a failing syscall, kept in a static so the
 * connect walk can record it BEFORE sys_close (which would clobber errno) and the
 * openers report a real code/message. Int static, never an array (the array-in-
 * static boundary trap — {@see __mc_http_headers_store}).
 */
function __mc_net_errno(bool $write, int $val): int
{
    static $e = 0;
    if ($write) { $e = $val; }
    return $e;
}

/** strerror($errno) as an owned string ('' for 0). */
function __mc_errno_msg(int $errno): string
{
    if ($errno === 0) {
        return '';
    }
    return \cstr_to_str(\Runtime\Libc\strerror($errno));
}

function fsockopen(string $hostname, int $port = -1, &$error_code = 0, &$error_message = '', ?float $timeout = null)
{
    $error_code = 0;
    $error_message = '';
    $scheme = 'tcp';
    $host = $hostname;
    $sep = \strpos($hostname, '://');
    if ($sep !== false) {
        $scheme = \strtolower(\substr($hostname, 0, $sep));
        $host = \substr($hostname, $sep + 3, \strlen($hostname) - ($sep + 3));
    }
    // unix:// carries a path, not a port — the port check does not apply.
    if ($port < 0 && $scheme !== 'unix') {
        $error_code = -1;
        $error_message = 'no port specified';
        return false;
    }
    $sock = \__mc_transport_connect($scheme, $host, $port);
    if ($sock === false) {
        // Report the real errno + strerror, as php does — a closed port gives
        // ECONNREFUSED / "Connection refused". Falls back to a generic message
        // when nothing set errno (e.g. name resolution failed, which is not errno).
        $e = \__mc_net_errno(false, 0);
        $error_code = $e;
        $error_message = $e !== 0
            ? \__mc_errno_msg($e)
            : ('connection to ' . $host . ':' . (string)$port . ' failed');
        return false;
    }
    return $sock;
}

/**
 * php.net's stream_socket_client. Accepts `tcp://host:port`, `ssl://host:port`,
 * `tls://host:port`, `udp://host:port`, or a bare `host:port` (tcp), plus
 * `unix:///path`. ssl:// / tls:// negotiate TLS (verify on by default; a $context
 * with ssl.verify_peer=false turns it off). A $context is also parked on the
 * socket so a later stream_socket_enable_crypto() STARTTLS can read its ssl.*
 * options.
 *
 * @param int $error_code
 * @param string $error_message
 * @return \Resource|false
 */
function stream_socket_client(string $address, &$error_code = 0, &$error_message = '', ?float $timeout = null, int $flags = 4, ?\Resource $context = null)
{
    $error_code = 0;
    $error_message = '';
    $addr = $address;
    $scheme = 'tcp';
    $sep = \strpos($addr, '://');
    if ($sep !== false) {
        $scheme = \strtolower(\substr($addr, 0, $sep));
        $addr = \substr($addr, $sep + 3, \strlen($addr) - ($sep + 3));
    }
    if ($scheme !== 'tcp' && $scheme !== 'udp' && $scheme !== 'ssl' && $scheme !== 'tls' && $scheme !== 'unix') {
        $error_code = -1;
        $error_message = 'unsupported transport: ' . $scheme;
        return false;
    }
    // unix:// carries a filesystem path, not host:port — connect straight to it.
    if ($scheme === 'unix') {
        $sock = \__mc_unix_connect($addr);
        if ($sock === false) {
            $error_code = -1;
            $error_message = 'cannot connect to ' . $address;
            return false;
        }
        if ($context !== null) {
            \__mc_attach_ctx($sock, $context);
        }
        return $sock;
    }
    // Rightmost colon: an IPv6 literal is full of them, and php accepts
    // `[::1]:80` for exactly that reason.
    $colon = \strrpos($addr, ':');
    if ($colon === false) {
        $error_code = -1;
        $error_message = 'no port in address: ' . $address;
        return false;
    }
    $host = \substr($addr, 0, $colon);
    $port = (int)\substr($addr, $colon + 1, \strlen($addr) - ($colon + 1));
    if (\strlen($host) > 1 && $host[0] === '[' && $host[\strlen($host) - 1] === ']') {
        $host = \substr($host, 1, \strlen($host) - 2);
    }
    $sock = \__mc_transport_connect($scheme, $host, $port, $context);
    if ($sock === false) {
        $e = \__mc_net_errno(false, 0);
        $error_code = $e;
        $error_message = $e !== 0
            ? \__mc_errno_msg($e)
            : ('connection to ' . $host . ':' . (string)$port . ' failed');
        return false;
    }
    if ($context !== null) {
        \__mc_attach_ctx($sock, $context);
    }
    return $sock;
}

/**
 * The port a socket is actually bound to, or -1.
 *
 * Needed because binding to port 0 asks the OS to pick a free one — which is
 * what makes a self-connecting test deterministic and offline instead of racing
 * for a hard-coded port.
 *
 * `sin_port` sits at offset 2 on EVERY probed target: Darwin splits the first
 * two bytes as sin_len(u8)+sin_family(u8), Linux uses sin_family(u16), so the
 * port lands in the same place either way. That is BSD compatibility, not luck —
 * and it means this needs no host branch. The value is network byte order, so
 * it is assembled big-endian by hand rather than trusting a host ntohs.
 */
function __mc_sock_port(int $fd): int
{
    $addr = \Runtime\Libc\calloc(128, 1);   // sockaddr_storage is smaller than this
    $len = \Runtime\Libc\calloc(4, 1);
    if ($addr === null || $len === null) {
        if ($addr !== null) { \Runtime\Libc\free($addr); }
        if ($len !== null) { \Runtime\Libc\free($len); }
        return -1;
    }
    \poke_i32($len, 0, 128);
    $rc = \Runtime\Libc\sys_getsockname($fd, $addr, $len);
    $port = -1;
    if ($rc === 0) {
        $port = (\peek_u8($addr, 2) << 8) | \peek_u8($addr, 3);
    }
    \Runtime\Libc\free($addr);
    \Runtime\Libc\free($len);
    return $port;
}

/**
 * Listen on $host:$port. Pass port 0 to let the OS choose — read it back with
 * {@see __mc_sock_port}.
 *
 * Like the client, this builds no sockaddr: getaddrinfo produces the one bind()
 * needs. The AI_PASSIVE flag would normally be set for a wildcard bind, but a
 * concrete $host makes it moot, and setting it would mean constructing a hints
 * struct — i.e. depending on sizeof(addrinfo).
 *
 * @return \Resource|false
 */
function __mc_tcp_listen(string $host, int $port, int $backlog = 16, int $wantType = 1)
{
    $res = \Runtime\Libc\calloc(8, 1);
    if ($res === null) {
        return false;
    }
    $rc = \Runtime\Libc\sys_getaddrinfo($host, (string)$port, \int_to_ptr(0), $res);
    if ($rc !== 0) {
        \Runtime\Libc\free($res);
        return false;
    }
    $head = \peek_i64($res, 0);
    \Runtime\Libc\free($res);
    if ($head === 0) {
        return false;
    }

    // A datagram server binds but does NOT listen() (listen is stream-only, and
    // fails ENOTSUP on a DGRAM socket) — it recvfrom()s straight off the bound fd.
    $isDgram = ($wantType === 2);
    $fd = -1;
    $ai = $head;
    while ($ai !== 0) {
        if (\peek_i32(\int_to_ptr($ai), \__mc_ai_off(1)) === $wantType) {
            $family = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(0));
            $proto = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(2));
            $cand = \Runtime\Libc\sys_socket($family, $wantType, $proto);
            if ($cand >= 0) {
                $a = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(4));
                $alen = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(3));
                if (\Runtime\Libc\sys_bind($cand, \int_to_ptr($a), $alen) === 0
                    && ($isDgram || \Runtime\Libc\sys_listen($cand, $backlog) === 0)) {
                    $fd = $cand;
                    break;
                }
                \Runtime\Libc\sys_close($cand);
            }
        }
        $ai = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(5));
    }
    \Runtime\Libc\sys_freeaddrinfo(\int_to_ptr($head));

    if ($fd < 0) {
        return false;
    }
    return new \Resource(\Resource::KIND_SOCKET, 'stream', $fd);
}

/**
 * php.net's stream_socket_server. Accepts tcp://, udp://, ssl:// or tls:// (and a
 * bare host:port = tcp). $flags matches php (STREAM_SERVER_BIND | _LISTEN by
 * default); a udp:// server passes STREAM_SERVER_BIND alone. An ssl:// / tls://
 * listener needs a $context carrying ssl.local_cert (and optionally ssl.local_pk);
 * each accepted connection is then handshaken server-side. unix:// unimplemented.
 *
 * @param int $error_code
 * @param string $error_message
 * @return \Resource|false
 */
function stream_socket_server(string $address, &$error_code = 0, &$error_message = '', int $flags = 12, ?\Resource $context = null)
{
    $error_code = 0;
    $error_message = '';
    $addr = $address;
    $scheme = 'tcp';
    $sep = \strpos($addr, '://');
    if ($sep !== false) {
        $scheme = \strtolower(\substr($addr, 0, $sep));
        $addr = \substr($addr, $sep + 3, \strlen($addr) - ($sep + 3));
    }
    if ($scheme !== 'tcp' && $scheme !== 'udp' && $scheme !== 'ssl' && $scheme !== 'tls' && $scheme !== 'unix') {
        $error_code = -1;
        $error_message = 'unsupported transport: ' . $scheme;
        return false;
    }
    // unix:// carries a filesystem path — bind + listen straight on it.
    if ($scheme === 'unix') {
        $u = \__mc_unix_listen($addr);
        if ($u === false) {
            $error_code = -1;
            $error_message = 'cannot bind ' . $address;
        }
        return $u;
    }
    $colon = \strrpos($addr, ':');
    if ($colon === false) {
        $error_code = -1;
        $error_message = 'no port in address: ' . $address;
        return false;
    }
    $host = \substr($addr, 0, $colon);
    $port = (int)\substr($addr, $colon + 1, \strlen($addr) - ($colon + 1));
    if (\strlen($host) > 1 && $host[0] === '[' && $host[\strlen($host) - 1] === ']') {
        $host = \substr($host, 1, \strlen($host) - 2);
    }
    // A udp:// server binds a datagram socket (no listen/accept); reads come
    // straight off it with recvfrom via the normal fread/fgets path.
    if ($scheme === 'udp') {
        $u = \__mc_tcp_listen($host, $port, 16, 2);
        if ($u === false) {
            $error_code = -1;
            $error_message = 'cannot bind ' . $address;
        }
        return $u;
    }
    $secure = ($scheme === 'ssl' || $scheme === 'tls');
    // Resolve the server cert BEFORE binding, so a misconfigured TLS server fails
    // fast without leaving a socket open.
    $cert = '';
    $pk = '';
    if ($secure) {
        if ($context === null) {
            $error_code = -1;
            $error_message = 'tls server needs a context with ssl.local_cert';
            return false;
        }
        $certs = \__mc_ctx_server_certs($context);
        $cert = $certs[0];
        $pk = $certs[1];
        if ($cert === '') {
            $error_code = -1;
            $error_message = 'tls server needs ssl.local_cert in the context';
            return false;
        }
    }
    $s = \__mc_tcp_listen($host, $port);
    if ($s === false) {
        $error_code = -1;
        $error_message = 'cannot listen on ' . $address;
        return false;
    }
    if ($secure) {
        $ctx = \__mc_tls_server_ctx($cert, $pk);
        if ($ctx === 0) {
            $error_code = -1;
            $error_message = 'cannot load cert/key for ' . $address;
            \fclose($s);
            return false;
        }
        // $s is \Resource|false (a CELL) — write ssl through a typed helper.
        \__mc_mark_tls_listener($s, $ctx);
    }
    // Park the context so accepted (plain) sockets can inherit its ssl.local_cert
    // for a later server-side stream_socket_enable_crypto() STARTTLS.
    if ($context !== null) {
        \__mc_attach_ctx($s, $context);
    }
    return $s;
}

/**
 * php.net's stream_socket_accept. $timeout is SECONDS (php's unit); a negative
 * value blocks. Waiting happens in poll(), so a timeout costs no host constants.
 *
 * The peer's address is not requested — accept(2) takes NULL/NULL for that,
 * which keeps this off the sockaddr-parsing path.
 *
 * @return \Resource|false
 */
function stream_socket_accept(\Resource $server, ?float $timeout = null)
{
    if ($timeout !== null && $timeout >= 0.0) {
        $ready = \__mc_poll_one($server->addr, false, (int)($timeout * 1000.0));
        if ($ready <= 0) {
            return false;
        }
    }
    $fd = \Runtime\Libc\sys_accept($server->addr, \int_to_ptr(0), \int_to_ptr(0));
    if ($fd < 0) {
        return false;
    }
    // A TLS listener parks its server ctx in $ssl (see __mc_mark_tls_listener):
    // handshake the accepted fd server-side and hand back a TLS stream.
    if ($server->ssl !== 0) {
        return \__mc_tls_accept($server->ssl, $fd);
    }
    // A plain listener that carried a context passes its ssl.* options down, so a
    // server-side stream_socket_enable_crypto() STARTTLS on the accepted socket can
    // find ssl.local_cert. Both are concrete \Resource here — a plain field copy.
    $conn = new \Resource(\Resource::KIND_SOCKET, 'stream', $fd);
    $conn->ctxBlob = $server->ctxBlob;
    return $conn;
}

// ── DNS / address functions ────────────────────────────────────────────
//
// The resolving ones ride on getaddrinfo + getnameinfo (already the socket path's
// resolver), so they build no sockaddr and add no host-specific struct parsing —
// getnameinfo(NI_NUMERICHOST) stringifies whatever the resolver chose.

/** The numeric IP of one getaddrinfo result, via getnameinfo. '' on failure. */
function __mc_ai_numeric_host(int $ai): string
{
    $addr = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(4));   // ai_addr
    $alen = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(3));   // ai_addrlen
    $host = \Runtime\Libc\calloc(128, 1);
    if ($host === null) {
        return '';
    }
    $rc = \Runtime\Libc\sys_getnameinfo(\int_to_ptr($addr), $alen, $host, 128,
                                        \int_to_ptr(0), 0, \__mc_net_const(8));   // NI_NUMERICHOST
    $out = $rc === 0 ? \cstr_to_str($host) : '';
    \Runtime\Libc\free($host);
    return $out;
}

/** This machine's host name, or false. */
function gethostname()
{
    $buf = \Runtime\Libc\calloc(256, 1);
    if ($buf === null) {
        return false;
    }
    $rc = \Runtime\Libc\sys_gethostname($buf, 256);
    $out = $rc === 0 ? \cstr_to_str($buf) : false;
    \Runtime\Libc\free($buf);
    return $out;
}

/**
 * The first IPv4 address of $hostname, or — as php does — $hostname unchanged when
 * it does not resolve.
 */
function gethostbyname(string $hostname): string
{
    $res = \Runtime\Libc\calloc(8, 1);
    if ($res === null) {
        return $hostname;
    }
    if (\Runtime\Libc\sys_getaddrinfo($hostname, "0", \int_to_ptr(0), $res) !== 0) {
        \Runtime\Libc\free($res);
        return $hostname;
    }
    $head = \peek_i64($res, 0);
    \Runtime\Libc\free($res);
    $ip = $hostname;
    $ai = $head;
    while ($ai !== 0) {
        if (\peek_i32(\int_to_ptr($ai), \__mc_ai_off(0)) === 2) {   // AF_INET
            $h = \__mc_ai_numeric_host($ai);
            if ($h !== '') { $ip = $h; break; }
        }
        $ai = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(5));
    }
    if ($head !== 0) {
        \Runtime\Libc\sys_freeaddrinfo(\int_to_ptr($head));
    }
    return $ip;
}

/**
 * Every IPv4 address of $hostname (deduped — getaddrinfo repeats each per
 * socktype), or false when it does not resolve.
 * @return string[]|false
 */
function gethostbynamel(string $hostname)
{
    $res = \Runtime\Libc\calloc(8, 1);
    if ($res === null) {
        return false;
    }
    if (\Runtime\Libc\sys_getaddrinfo($hostname, "0", \int_to_ptr(0), $res) !== 0) {
        \Runtime\Libc\free($res);
        return false;
    }
    $head = \peek_i64($res, 0);
    \Runtime\Libc\free($res);
    /** @var string[] $ips */
    $ips = [];
    $ai = $head;
    while ($ai !== 0) {
        if (\peek_i32(\int_to_ptr($ai), \__mc_ai_off(0)) === 2) {
            $h = \__mc_ai_numeric_host($ai);
            if ($h !== '' && !\in_array($h, $ips, true)) { $ips[] = $h; }
        }
        $ai = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(5));
    }
    if ($head !== 0) {
        \Runtime\Libc\sys_freeaddrinfo(\int_to_ptr($head));
    }
    return $ips;
}

/** Dotted-quad IPv4 string to its 32-bit int, or false on a malformed address. */
function ip2long(string $ip)
{
    $parts = \explode('.', $ip);
    if (\count($parts) !== 4) {
        return false;
    }
    $n = 0;
    foreach ($parts as $p) {
        $len = \strlen($p);
        if ($len === 0 || $len > 3 || !\ctype_digit($p)) {
            return false;
        }
        $v = (int)$p;
        if ($v > 255) {
            return false;
        }
        $n = ($n * 256) + $v;   // *256 keeps it a positive int (0..4294967295)
    }
    return $n;
}

/** A 32-bit int to its dotted-quad IPv4 string (masked to 32 bits, as php does). */
function long2ip(int $ip): string
{
    $ip = $ip & 4294967295;
    return (string)(($ip >> 24) & 255) . '.' . (string)(($ip >> 16) & 255) . '.'
         . (string)(($ip >> 8) & 255) . '.' . (string)($ip & 255);
}

/** A printable IP (v4 or v6) to its packed in_addr/in6_addr bytes, or false. */
function inet_pton(string $address)
{
    $af = \strpos($address, ':') !== false ? \__mc_net_const(9) : 2;   // AF_INET6 / AF_INET
    $sz = $af === 2 ? 4 : 16;
    $dst = \Runtime\Libc\calloc(16, 1);
    if ($dst === null) {
        return false;
    }
    $rc = \Runtime\Libc\sys_inet_pton($af, $address, $dst);
    $out = $rc === 1 ? \str_from_buffer($dst, $sz) : false;
    \Runtime\Libc\free($dst);
    return $out;
}

/** Packed in_addr/in6_addr bytes (4 or 16) to a printable IP string, or false. */
function inet_ntop(string $in_addr)
{
    $len = \strlen($in_addr);
    if ($len === 4) {
        $af = 2;
    } elseif ($len === 16) {
        $af = \__mc_net_const(9);
    } else {
        return false;
    }
    $src = \Runtime\Libc\calloc(16, 1);
    $dst = \Runtime\Libc\calloc(64, 1);
    if ($src === null || $dst === null) {
        if ($src !== null) { \Runtime\Libc\free($src); }
        if ($dst !== null) { \Runtime\Libc\free($dst); }
        return false;
    }
    for ($i = 0; $i < $len; $i = $i + 1) {
        \poke_i8($src, $i, \ord($in_addr[$i]));
    }
    $r = \Runtime\Libc\sys_inet_ntop($af, $src, $dst, 64);
    $out = $r === null ? false : \cstr_to_str($dst);
    \Runtime\Libc\free($src);
    \Runtime\Libc\free($dst);
    return $out;
}

/** The port for a service/protocol (getservbyname('http','tcp') = 80), or false. */
function getservbyname(string $service, string $protocol)
{
    $sv = \Runtime\Libc\sys_getservbyname($service, $protocol);
    if ($sv === null) {
        return false;
    }
    // s_port @16, in network byte order (big-endian) in the low 16 bits.
    return (\peek_u8($sv, 16) << 8) | \peek_u8($sv, 17);
}

/** The service name for a port/protocol (getservbyport(80,'tcp') = 'http'), or false. */
function getservbyport(int $port, string $protocol)
{
    // getservbyport takes the port in network byte order.
    $netport = (($port & 255) << 8) | (($port >> 8) & 255);
    $sv = \Runtime\Libc\sys_getservbyport($netport, $protocol);
    if ($sv === null) {
        return false;
    }
    return \cstr_to_str(\int_to_ptr(\peek_i64($sv, 0)));   // s_name @0
}

/** The protocol number for a name (getprotobyname('tcp') = 6), or false. */
function getprotobyname(string $name)
{
    $pe = \Runtime\Libc\sys_getprotobyname($name);
    if ($pe === null) {
        return false;
    }
    return \peek_i32($pe, 16);   // p_proto @16 (host order)
}

/** The protocol name for a number (getprotobynumber(6) = 'tcp'), or false. */
function getprotobynumber(int $protocol)
{
    $pe = \Runtime\Libc\sys_getprotobynumber($protocol);
    if ($pe === null) {
        return false;
    }
    return \cstr_to_str(\int_to_ptr(\peek_i64($pe, 0)));   // p_name @0
}

/** Open a connection to the system logger. */
function openlog(string $prefix, int $flags, int $facility): bool
{
    \Runtime\Libc\sys_openlog($prefix, $flags, $facility);
    return true;
}

/**
 * Send a message to the system logger. % is escaped: the libc syslog treats its
 * message as a printf FORMAT, so a raw % would read a missing vararg.
 */
function syslog(int $priority, string $message): bool
{
    \Runtime\Libc\sys_syslog($priority, \str_replace('%', '%%', $message));
    return true;
}

/** Close the system logger connection. */
function closelog(): bool
{
    \Runtime\Libc\sys_closelog();
    return true;
}

/**
 * Reverse DNS: the host name for $ip, or $ip unchanged when it has no PTR, or
 * false on a malformed address — php's contract. IPv4 for now (builds a
 * sockaddr_in by hand, like unix://, then getnameinfo resolves it).
 * @return string|false
 */
function gethostbyaddr(string $ip)
{
    $packed = \inet_pton($ip);
    if ($packed === false || \strlen($packed) !== 4) {
        return false;
    }
    $sa = \Runtime\Libc\calloc(16, 1);   // sizeof(sockaddr_in)
    if ($sa === null) {
        return false;
    }
    // AF_INET (2): Darwin sin_len@0 + sin_family@1; glibc/musl sin_family@0 (u16).
    if (\__mc_host_is_darwin()) {
        \poke_i8($sa, 0, 16);
        \poke_i8($sa, 1, 2);
    } else {
        \poke_i16($sa, 0, 2);
    }
    for ($i = 0; $i < 4; $i = $i + 1) {   // sin_addr @4
        \poke_i8($sa, 4 + $i, \ord($packed[$i]));
    }
    $host = \Runtime\Libc\calloc(256, 1);
    if ($host === null) {
        \Runtime\Libc\free($sa);
        return false;
    }
    // flags 0 (no NI_NAMEREQD): a missing PTR gives the numeric IP back, rc 0 —
    // exactly php's "returns the address unchanged".
    $rc = \Runtime\Libc\sys_getnameinfo($sa, 16, $host, 256, \int_to_ptr(0), 0, 0);
    $out = $rc === 0 ? \cstr_to_str($host) : $ip;
    \Runtime\Libc\free($sa);
    \Runtime\Libc\free($host);
    return $out;
}

// ── stream/socket helpers ──────────────────────────────────────────────

/** Persistent-connection fsockopen. We keep no pool yet, so it just connects. */
function pfsockopen(string $hostname, int $port = -1, &$error_code = 0, &$error_message = '', ?float $timeout = null)
{
    return \fsockopen($hostname, $port, $error_code, $error_message, $timeout);
}

/**
 * STARTTLS: upgrade a connected plain socket to TLS in place, or tear a TLS stream
 * back down. $crypto_method's bit 0 selects client (1) vs server (0), default
 * STREAM_CRYPTO_METHOD_TLS_CLIENT. Returns true on success, false otherwise.
 *
 * Client STARTTLS verifies by default (SNI + cert-chain + hostname), using the host
 * remembered at connect time; a context attached to the socket (stream_socket_client
 * with ssl.verify_peer=false) turns verification off. Server STARTTLS needs the
 * socket to carry a context with ssl.local_cert (a plain listener created via
 * stream_socket_server(..., $ctx), whose accepted sockets inherit it).
 *
 * $session_stream (TLS session reuse) is ignored — niche.
 *
 * @param mixed $session_stream
 * @return bool
 */
function stream_socket_enable_crypto(\Resource $stream, bool $enable, ?int $crypto_method = null, $session_stream = null): bool
{
    if (!$enable) {
        // Tear crypto down: close_notify + free the engine, revert to a plain fd.
        // php returns false when there was no crypto to disable.
        if ($stream->kind === \Resource::KIND_TLS && $stream->ssl !== 0) {
            \Runtime\Openssl\shutdown($stream->ssl);
            \Runtime\Openssl\sslFree($stream->ssl);   // does not touch the fd
            $stream->ssl = 0;
            $stream->kind = \Resource::KIND_SOCKET;
            return true;
        }
        return false;
    }
    if ($stream->kind !== \Resource::KIND_SOCKET) {
        return false;   // not a plain socket (already TLS, or a file/memory stream)
    }
    $method = $crypto_method ?? 121;   // STREAM_CRYPTO_METHOD_TLS_CLIENT
    if (($method & 1) === 1) {
        // Client: SNI + cert-hostname against the connect host; verify on unless a
        // context turned it off. Mutates $stream to KIND_TLS on success.
        $vp = true;
        $vn = true;
        if ($stream->ctxBlob !== '') {
            $o = \__mc_ctx_unpack($stream->ctxBlob);
            $vp = $o[5] === '1';
            $vn = $o[6] === '1';
        }
        return \__mc_tls_handshake($stream, $stream->host, $vp, $vn);
    }
    // Server: needs a cert context (ssl.local_cert) carried on the socket.
    if ($stream->ctxBlob === '') {
        return false;
    }
    $o = \__mc_ctx_unpack($stream->ctxBlob);
    if ($o[3] === '') {
        return false;
    }
    return \__mc_tls_server_handshake($stream, $o[3], $o[4]);
}

/**
 * Shut down part of a full-duplex socket. $how is STREAM_SHUT_RD (0) /
 * STREAM_SHUT_WR (1) / STREAM_SHUT_RDWR (2), which equal SHUT_RD/WR/RDWR on both
 * hosts. Only meaningful for a socket/TLS stream.
 */
function stream_socket_shutdown(\Resource $stream, int $how): bool
{
    if (!\__mc_stream_is_net($stream)) {
        return false;
    }
    return \Runtime\Libc\sys_shutdown($stream->addr, $how) === 0;
}

/**
 * Create a connected pair of stream sockets (socketpair(2)) — an anonymous pipe
 * that is bidirectional and full-duplex. Returns [\Resource, \Resource] (two plain
 * KIND_SOCKET streams), or false. $domain is STREAM_PF_UNIX/INET, $type
 * STREAM_SOCK_STREAM/DGRAM. Both handles are returned by value in an array, so the
 * element boxing is the ordinary return path (no by-ref erasure).
 * @return array{0:\Resource,1:\Resource}|false
 */
function stream_socket_pair(int $domain, int $type, int $protocol)
{
    $sv = \Runtime\Libc\calloc(8, 1);
    if ($sv === null) {
        return false;
    }
    $rc = \Runtime\Libc\sys_socketpair($domain, $type, $protocol, $sv);
    if ($rc !== 0) {
        \Runtime\Libc\free($sv);
        return false;
    }
    $fd0 = \peek_i32($sv, 0);
    $fd1 = \peek_i32($sv, 4);
    \Runtime\Libc\free($sv);
    $a = new \Resource(\Resource::KIND_SOCKET, 'stream', $fd0);
    $b = new \Resource(\Resource::KIND_SOCKET, 'stream', $fd1);
    return [$a, $b];
}

/** The fd behind a stream \Resource (typed so an array element is unboxed). */
function __mc_stream_fd(\Resource $s): int
{
    return $s->addr;
}

/**
 * stream_select(&$read, &$write, &$except, $sec, $usec) over poll(2) — the stream
 * twin of socket_select (the codebase avoids fd_set / FD_SETSIZE). The three arrays
 * are rewritten in place to hold only the ready streams; returns the ready count, 0
 * on timeout, or false on error. O(n) per call — fine for modest fd counts; a
 * kqueue/epoll backend is the async epic, and would slot in under this same API.
 */
function stream_select(?array &$read, ?array &$write, ?array &$except, ?int $sec, ?int $usec = null): int|false
{
    $POLLIN = \__mc_net_const(1);
    $POLLOUT = \__mc_net_const(2);
    $POLLERR = \__mc_net_const(3);
    $POLLHUP = \__mc_net_const(4);
    $POLLPRI = 2;

    /** @var int[] $fds */
    $fds = [];
    /** @var int[] $evs */
    $evs = [];
    if ($read !== null) {
        foreach ($read as $s) {
            $fd = \__mc_stream_fd($s);
            $idx = \__mc_sel_index($fds, $fd);
            if ($idx < 0) { $fds[] = $fd; $evs[] = $POLLIN; }
            else { $evs[$idx] = $evs[$idx] | $POLLIN; }
        }
    }
    if ($write !== null) {
        foreach ($write as $s) {
            $fd = \__mc_stream_fd($s);
            $idx = \__mc_sel_index($fds, $fd);
            if ($idx < 0) { $fds[] = $fd; $evs[] = $POLLOUT; }
            else { $evs[$idx] = $evs[$idx] | $POLLOUT; }
        }
    }
    if ($except !== null) {
        foreach ($except as $s) {
            $fd = \__mc_stream_fd($s);
            $idx = \__mc_sel_index($fds, $fd);
            if ($idx < 0) { $fds[] = $fd; $evs[] = $POLLPRI; }
            else { $evs[$idx] = $evs[$idx] | $POLLPRI; }
        }
    }

    $count = \count($fds);
    if ($count === 0) {
        return 0;
    }
    $pfds = \Runtime\Libc\calloc($count * 8, 1);
    if ($pfds === null) {
        return false;
    }
    for ($i = 0; $i < $count; $i = $i + 1) {
        \poke_i32($pfds, $i * 8, $fds[$i]);
        \poke_i16($pfds, $i * 8 + 4, $evs[$i]);
        \poke_i16($pfds, $i * 8 + 6, 0);
    }
    // null $sec = block forever (-1); otherwise sec*1000 + usec/1000.
    $um = $usec === null ? 0 : $usec;
    $timeoutMs = $sec === null ? -1 : ($sec * 1000 + \intdiv($um, 1000));
    $rc = \Runtime\Libc\sys_poll($pfds, $count, $timeoutMs);
    if ($rc < 0) {
        \Runtime\Libc\free($pfds);
        return false;
    }
    /** @var int[] $rev */
    $rev = [];
    for ($i = 0; $i < $count; $i = $i + 1) {
        $rev[] = \peek_i16($pfds, $i * 8 + 6);
    }
    \Runtime\Libc\free($pfds);

    $ready = 0;
    if ($read !== null) {
        $nr = [];
        foreach ($read as $s) {
            $r = $rev[\__mc_sel_index($fds, \__mc_stream_fd($s))];
            if (($r & ($POLLIN | $POLLHUP | $POLLERR)) !== 0) { \__mc_sel_keep($nr, $s); $ready = $ready + 1; }
        }
        $read = $nr;
    }
    if ($write !== null) {
        $nw = [];
        foreach ($write as $s) {
            $r = $rev[\__mc_sel_index($fds, \__mc_stream_fd($s))];
            if (($r & ($POLLOUT | $POLLERR)) !== 0) { \__mc_sel_keep($nw, $s); $ready = $ready + 1; }
        }
        $write = $nw;
    }
    if ($except !== null) {
        $ne = [];
        foreach ($except as $s) {
            $r = $rev[\__mc_sel_index($fds, \__mc_stream_fd($s))];
            if (($r & ($POLLPRI | $POLLERR)) !== 0) { \__mc_sel_keep($ne, $s); $ready = $ready + 1; }
        }
        $except = $ne;
    }
    return $ready;
}

/**
 * Append a ready stream to the rewritten select array. $s is \Resource-TYPED (a
 * funnel): a value read out of the untyped `$read` param is an erased obj handle,
 * and appending it raw stores a BORROWED reference — so when stream_select's
 * `$read = $nr` reassignment releases the old array, the caller's resource is
 * over-released (its fd zeroes on the next call). A \Resource-typed store retains
 * the element (+1), balancing that release.
 */
function __mc_sel_keep(array &$dst, \Resource $s): void
{
    $dst[] = $s;
}

/** The transports stream_socket_client/server understand here. */
function stream_get_transports(): array
{
    return ['tcp', 'udp', 'unix', 'ssl', 'tls'];
}

/** "host:port" for a sockaddr via getnameinfo (numeric host + serv); '' on error. */
function __mc_sockaddr_name(\Ffi\Ptr $sa, int $alen): string
{
    $host = \Runtime\Libc\calloc(128, 1);
    $serv = \Runtime\Libc\calloc(32, 1);
    if ($host === null || $serv === null) {
        if ($host !== null) { \Runtime\Libc\free($host); }
        if ($serv !== null) { \Runtime\Libc\free($serv); }
        return '';
    }
    $flags = \__mc_net_const(8) | \__mc_net_const(10);   // NI_NUMERICHOST | NI_NUMERICSERV
    $rc = \Runtime\Libc\sys_getnameinfo($sa, $alen, $host, 128, $serv, 32, $flags);
    $out = $rc === 0 ? (\cstr_to_str($host) . ':' . \cstr_to_str($serv)) : '';
    \Runtime\Libc\free($host);
    \Runtime\Libc\free($serv);
    return $out;
}

/**
 * The local ("host:port") name of a socket, or the peer's when $want_peer.
 * @return string|false
 */
function stream_socket_get_name(\Resource $handle, bool $want_peer)
{
    if (!\__mc_stream_is_net($handle)) {
        return false;
    }
    $sa = \Runtime\Libc\calloc(128, 1);   // sockaddr_storage
    $len = \Runtime\Libc\calloc(4, 1);
    if ($sa === null || $len === null) {
        if ($sa !== null) { \Runtime\Libc\free($sa); }
        if ($len !== null) { \Runtime\Libc\free($len); }
        return false;
    }
    \poke_i32($len, 0, 128);
    $rc = $want_peer
        ? \Runtime\Libc\sys_getpeername($handle->addr, $sa, $len)
        : \Runtime\Libc\sys_getsockname($handle->addr, $sa, $len);
    $name = $rc === 0 ? \__mc_sockaddr_name($sa, \peek_i32($len, 0)) : '';
    \Runtime\Libc\free($sa);
    \Runtime\Libc\free($len);
    return $name === '' ? false : $name;
}

/**
 * Receive up to $length bytes from a (datagram) socket, filling &$address with the
 * sender's "host:port". Returns the bytes, or false.
 * @param string $address
 * @return string|false
 */
function stream_socket_recvfrom(\Resource $handle, int $length, int $flags = 0, &$address = '')
{
    $address = '';
    if (!\__mc_stream_is_net($handle) || $length <= 0) {
        return false;
    }
    $buf = \Runtime\Libc\calloc($length + 1, 1);
    $sa = \Runtime\Libc\calloc(128, 1);
    $slen = \Runtime\Libc\calloc(4, 1);
    if ($buf === null || $sa === null || $slen === null) {
        if ($buf !== null) { \Runtime\Libc\free($buf); }
        if ($sa !== null) { \Runtime\Libc\free($sa); }
        if ($slen !== null) { \Runtime\Libc\free($slen); }
        return false;
    }
    \poke_i32($slen, 0, 128);
    $got = \Runtime\Libc\sys_recvfrom($handle->addr, $buf, $length, $flags, $sa, $slen);
    if ($got >= 0) {
        $address = \__mc_sockaddr_name($sa, \peek_i32($slen, 0));
    }
    $out = $got < 0 ? false : \str_from_buffer($buf, $got);
    \Runtime\Libc\free($buf);
    \Runtime\Libc\free($sa);
    \Runtime\Libc\free($slen);
    return $out;
}

/**
 * Send $data on a (datagram) socket. With $address ("host:port") it is resolved
 * and sent there; empty $address sends on a connected socket. Returns the byte
 * count, or -1.
 */
function stream_socket_sendto(\Resource $handle, string $data, int $flags = 0, string $address = ''): int
{
    if (!\__mc_stream_is_net($handle)) {
        return -1;
    }
    $len = \strlen($data);
    if ($address === '') {
        $n = \Runtime\Libc\sys_send($handle->addr, $data, $len, $flags);
        return $n < 0 ? -1 : $n;
    }
    $colon = \strrpos($address, ':');
    if ($colon === false) {
        return -1;
    }
    $host = \substr($address, 0, $colon);
    $port = \substr($address, $colon + 1, \strlen($address) - ($colon + 1));
    if (\strlen($host) > 1 && $host[0] === '[' && $host[\strlen($host) - 1] === ']') {
        $host = \substr($host, 1, \strlen($host) - 2);
    }
    $res = \Runtime\Libc\calloc(8, 1);
    if ($res === null) {
        return -1;
    }
    if (\Runtime\Libc\sys_getaddrinfo($host, $port, \int_to_ptr(0), $res) !== 0) {
        \Runtime\Libc\free($res);
        return -1;
    }
    $head = \peek_i64($res, 0);
    \Runtime\Libc\free($res);
    if ($head === 0) {
        return -1;
    }
    $n = -1;
    $ai = $head;
    while ($ai !== 0) {
        if (\peek_i32(\int_to_ptr($ai), \__mc_ai_off(1)) === 2) {   // SOCK_DGRAM
            $addr = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(4));
            $alen = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(3));
            $r = \Runtime\Libc\sys_sendto($handle->addr, $data, $len, $flags, \int_to_ptr($addr), $alen);
            if ($r >= 0) { $n = $r; break; }
        }
        $ai = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(5));
    }
    \Runtime\Libc\sys_freeaddrinfo(\int_to_ptr($head));
    return $n;
}

// ── HTTP/1.1 client ────────────────────────────────────────────────────
//
// Everything below reads through fgets()/fread() on the \Resource, i.e. through
// __mc_stream_fill() — the ONE blocking call. That is deliberate: when a
// scheduler arrives, teaching that one function to suspend makes this whole
// parser non-blocking with no edits here.
//
// Behaviour MEASURED against php 8.5.8 rather than assumed:
//   * the request php sends is minimal — request line, Host, Connection: close.
//     No User-Agent, no Accept.
//   * a non-2xx status makes file_get_contents() return FALSE, not the error body.
//   * Transfer-Encoding: chunked is DECODED by the wrapper.
//   * $http_response_header is DEPRECATED in 8.5 ("call
//     http_get_last_response_headers() instead"), so the function is the real API
//     and the variable is not worth emulating.

/**
 * Raw header lines of the last HTTP response, status line first — php 8.5's
 * replacement for the deprecated $http_response_header.
 * @return string[]|null
 */
function http_get_last_response_headers(): ?array
{
    $joined = \__mc_http_headers_store(false, '');
    if ($joined === '') {
        return null;
    }
    // Rebuilt HERE, in the caller's world, rather than parked as an array.
    return \explode("\n", $joined);
}

/** php 8.5's companion to the above. */
function http_clear_last_response_headers(): void
{
    \__mc_http_headers_store(true, '');
}

/**
 * The last response's headers, kept as ONE newline-joined STRING.
 *
 * ⚠ Not a `static array`, and that is not style. An array BUILT INSIDE the stdlib
 * does not survive being parked in a static and handed to user code later — the
 * first cut did exactly that and `http_get_last_response_headers()` returned NULL
 * while the very same static, written AND read from user code, was fine.
 * {@see __mc_stat_off} carries the same warning for its offset table and dodges it
 * the same way: hold something that carries no refcount (it uses ints; a header
 * list is text, so a string), and rebuild the container at the boundary.
 * A header line cannot contain a newline, so joining on one is lossless.
 */
function __mc_http_headers_store(bool $write, string $joined): string
{
    static $headers = '';
    if ($write) {
        $headers = $joined;
    }
    return $headers;
}

/**
 * Split a URL into scheme/host/port/path. Returns [] when it is not one.
 * @return string[]
 */
function __mc_url_parts(string $url): array
{
    $sep = \strpos($url, '://');
    if ($sep === false) {
        return [];
    }
    $scheme = \strtolower(\substr($url, 0, $sep));
    $rest = \substr($url, $sep + 3, \strlen($url) - ($sep + 3));
    $slash = \strpos($rest, '/');
    if ($slash === false) {
        $auth = $rest;
        $path = '/';
    } else {
        $auth = \substr($rest, 0, $slash);
        $path = \substr($rest, $slash, \strlen($rest) - $slash);
    }
    $port = $scheme === 'https' ? '443' : '80';
    $host = $auth;
    // Rightmost colon: an IPv6 literal is full of them, which is why php wants
    // [::1]:80 for that case.
    $colon = \strrpos($auth, ':');
    if ($colon !== false) {
        $maybe = \substr($auth, $colon + 1, \strlen($auth) - ($colon + 1));
        if ($maybe !== '' && \ctype_digit($maybe)) {
            $host = \substr($auth, 0, $colon);
            $port = $maybe;
        }
    }
    if (\strlen($host) > 1 && $host[0] === '[' && $host[\strlen($host) - 1] === ']') {
        $host = \substr($host, 1, \strlen($host) - 2);
    }
    if ($host === '') {
        return [];
    }
    return [$scheme, $host, $port, $path];
}

/**
 * Read a chunked body. Each chunk is a hex length line, the bytes, then CRLF;
 * a zero length ends it.
 *
 * A chunk boundary never lines up with a recv boundary — which is exactly what
 * the read buffer is for. fread() here serves from it, so a chunk split across
 * two packets costs nothing.
 */
function __mc_http_chunked(\Resource $s): string
{
    $body = '';
    while (true) {
        $line = \fgets($s);
        if ($line === false) {
            break;
        }
        $line = \rtrim($line, "\r\n");
        // A chunk-size line may carry ';ext=...' after the length.
        $semi = \strpos($line, ';');
        if ($semi !== false) {
            $line = \substr($line, 0, $semi);
        }
        if ($line === '') {
            continue;
        }
        $n = \intval($line, 16);
        if ($n <= 0) {
            break;                      // 0 = last chunk
        }
        $left = $n;
        while ($left > 0) {
            $part = \fread($s, $left);
            if ($part === '') {
                break;
            }
            $body = $body . $part;
            $left = $left - \strlen($part);
        }
        \fgets($s);                     // the CRLF that closes the chunk
    }
    return $body;
}

/**
 * GET $url over HTTP/1.1 and return the body, or false.
 *
 * php's own rules, measured: a non-2xx status is FALSE (the error body is NOT
 * returned), and up to $maxRedirects 3xx hops are followed.
 * @return string|false
 */
function __mc_http_get(string $url, int $maxRedirects = 20, string $method = 'GET', string $extraHeaders = '', string $body = '', bool $verifyPeer = true, bool $verifyName = true)
{
    $hops = 0;
    while (true) {
        $parts = \__mc_url_parts($url);
        if ($parts === []) {
            return false;
        }
        $scheme = $parts[0];
        $secure = ($scheme === 'https');
        if ($scheme !== 'http' && !$secure) {
            return false;               // only http:// and https://
        }
        $host = $parts[1];
        $port = (int)$parts[2];
        $path = $parts[3];
        // The Host header (and a reconstructed redirect origin) omit the port
        // only when it is the scheme's default — 443 for https, 80 for http.
        $defPort = $secure ? 443 : 80;

        $sock = $secure ? \__mc_tls_connect($host, $port, $verifyPeer, $verifyName) : \__mc_tcp_connect($host, $port);
        if ($sock === false) {
            return false;
        }
        // Default request mirrors php: no User-Agent, no Accept. `Connection: close`
        // ends the body at EOF when there is no Content-Length. Context adds the
        // method, extra headers, and a body (with its Content-Length) when given.
        $req = $method . ' ' . $path . " HTTP/1.1\r\n"
             . 'Host: ' . $host . ($port === $defPort ? '' : ':' . (string)$port) . "\r\n"
             . $extraHeaders
             . ($body !== '' ? 'Content-Length: ' . (string)\strlen($body) . "\r\n" : '')
             . "Connection: close\r\n\r\n"
             . $body;
        \fwrite($sock, $req);

        $resp = \__mc_http_read_response($sock, $maxRedirects > 0);
        \fclose($sock);
        // [0] = body|false, [1] = redirect target ('' = none), [2] = status code
        if ($resp[1] !== '' && $hops < $maxRedirects) {
            $hops = $hops + 1;
            $code = (int)$resp[2];
            $location = $resp[1];
            // A relative Location is resolved against the current origin — same
            // scheme, so an https redirect stays on TLS.
            if (\strpos($location, '://') === false) {
                $location = $scheme . '://' . $host . ($port === $defPort ? '' : ':' . (string)$port)
                          . ($location !== '' && $location[0] === '/' ? '' : '/') . $location;
            }
            $url = $location;
            // 307/308 preserve the method AND body (RFC 7538/7231); 301/302/303
            // are followed as a bodyless GET, which is php's — and every browser's —
            // behaviour. Extra headers and verify flags persist across every hop.
            if ($code !== 307 && $code !== 308) {
                $method = 'GET';
                $body = '';
            }
            continue;
        }
        return $resp[0];
    }
}

/**
 * Parse ONE HTTP response off an already-connected stream: status line, headers,
 * then the body by Content-Length, chunked, or EOF.
 *
 * Split out from __mc_http_get so it can be TESTED. `file_get_contents('http://…')`
 * cannot be exercised offline in a single process — it blocks waiting for a reply
 * and there is no fork() (nor system/proc_open) to run an origin beside it. This
 * function takes a stream we already own, so a test can stand up a loopback pair,
 * push a canned response into the server end, and parse it from the client end —
 * offline and deterministic. The transport itself is covered by net_tcp_loopback.
 *
 * Returns [body|false, location]. `location` is non-empty only when $followable
 * and the status is a 3xx carrying one — the caller owns the redirect loop,
 * because it needs a NEW connection.
 *
 * Reads go through fgets()/fread(), i.e. __mc_stream_fill() — the one blocking
 * call. A chunk boundary never lines up with a recv boundary; the read buffer is
 * what makes that a non-issue here.
 *
 * @return array{0: string|false, 1: string}
 */
function __mc_http_read_response(\Resource $sock, bool $followable = false): array
{
    {
        $status = \fgets($sock);
        if ($status === false) {
            return [false, '', 0];
        }
        $headers = [];
        $headers[] = \rtrim($status, "\r\n");
        $len = -1;
        $chunked = false;
        $location = '';
        while (true) {
            $line = \fgets($sock);
            if ($line === false) {
                break;
            }
            $line = \rtrim($line, "\r\n");
            if ($line === '') {
                break;                  // end of headers
            }
            $headers[] = $line;
            $colon = \strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $name = \strtolower(\rtrim(\substr($line, 0, $colon)));
            $val = \ltrim(\substr($line, $colon + 1, \strlen($line) - ($colon + 1)));
            if ($name === 'content-length') {
                $len = (int)$val;
            } elseif ($name === 'transfer-encoding' && \strtolower($val) === 'chunked') {
                $chunked = true;
            } elseif ($name === 'location') {
                $location = $val;
            }
        }
        \__mc_http_headers_store(true, \implode("\n", $headers));

        // "HTTP/1.1 200 OK" -> 200
        $code = 0;
        $sp = \strpos($headers[0], ' ');
        if ($sp !== false) {
            $code = (int)\substr($headers[0], $sp + 1, 3);
        }

        if ($followable && $code >= 300 && $code < 400 && $location !== '') {
            // [2] = the 3xx code so the caller can preserve the method+body on a
            // 307/308 and downgrade to GET on a 301/302/303.
            return [false, $location, $code];
        }

        if ($chunked) {
            $body = \__mc_http_chunked($sock);
        } elseif ($len >= 0) {
            $body = '';
            $left = $len;
            while ($left > 0) {
                $part = \fread($sock, $left);
                if ($part === '') {
                    break;
                }
                $body = $body . $part;
                $left = $left - \strlen($part);
            }
        } else {
            // No length and not chunked: the body runs to EOF, which is what
            // `Connection: close` guarantees.
            $body = '';
            while (true) {
                $part = \fread($sock, 65536);
                if ($part === '') {
                    break;
                }
                $body = $body . $part;
            }
        }
        // php: a non-2xx makes file_get_contents() return false — the error body
        // is NOT handed back (only ignore_errors in a context changes that).
        if ($code < 200 || $code >= 300) {
            return [false, '', $code];
        }
        return [$body, '', $code];
    }
}
