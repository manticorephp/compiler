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
// tools/docker/probe.c across 5 distros × 2 arches (tools/docker/PROBE_RESULTS.md).

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
        // MEASURED (tools/docker/probe.c), identical on glibc 2.31/2.35/2.36/2.39
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

    if ($ready === 0) {
        // MEASURED: Darwin SOL_SOCKET 65535 / SO_ERROR 0x1007; Linux 1 / 4.
        $isDarwin = \__mc_host_is_darwin();
        $solSocket = $isDarwin ? 65535 : 1;
        $soError = $isDarwin ? 4103 : 4;
        $ready = 1;
    }

    if ($which === 0) { return 1; }      // SOCK_STREAM — 1 on every target
    if ($which === 1) { return 1; }      // POLLIN
    if ($which === 2) { return 4; }      // POLLOUT
    if ($which === 3) { return 8; }      // POLLERR
    if ($which === 4) { return 16; }     // POLLHUP
    if ($which === 5) { return $solSocket; }
    if ($which === 6) { return $soError; }
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
function __mc_tcp_connect(string $host, int $port)
{
    $res = \Runtime\Libc\calloc(8, 1);
    if ($res === null) {
        return false;
    }
    // hints = NULL: see the file header. The result list then also carries
    // DGRAM/RAW entries, which the ai_socktype filter below drops.
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
        if ($sockType === \__mc_net_const(0)) {
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
 * Open a TCP connection. php.net's signature, including the by-ref diagnostics.
 *
 * $timeout is accepted and currently IGNORED: the connect is blocking, so the
 * OS's own connect timeout applies. Honouring it needs O_NONBLOCK + poll(POLLOUT)
 * + getsockopt(SO_ERROR), which is the natural first step of the Fibers work
 * rather than a bolt-on here.
 *
 * @param int $error_code
 * @param string $error_message
 * @return \Resource|false
 */
function fsockopen(string $hostname, int $port = -1, &$error_code = 0, &$error_message = '', ?float $timeout = null)
{
    $error_code = 0;
    $error_message = '';
    if ($port < 0) {
        $error_code = -1;
        $error_message = 'no port specified';
        return false;
    }
    $sock = \__mc_tcp_connect($hostname, $port);
    if ($sock === false) {
        // php reports errno here. We have none (see the header), so report the
        // stage that failed rather than inventing a number.
        $error_code = -1;
        $error_message = 'connection to ' . $hostname . ':' . (string)$port . ' failed';
        return false;
    }
    return $sock;
}

/**
 * php.net's stream_socket_client. Accepts `tcp://host:port` or a bare
 * `host:port`; other transports (udp://, unix://, ssl://) are not implemented
 * yet — ssl:// arrives with the openssl extension.
 *
 * @param int $error_code
 * @param string $error_message
 * @return \Resource|false
 */
function stream_socket_client(string $address, &$error_code = 0, &$error_message = '', ?float $timeout = null)
{
    $error_code = 0;
    $error_message = '';
    $addr = $address;
    $scheme = 'tcp';
    $sep = \strpos($addr, '://');
    if ($sep !== false) {
        $scheme = \substr($addr, 0, $sep);
        $addr = \substr($addr, $sep + 3, \strlen($addr) - ($sep + 3));
    }
    if ($scheme !== 'tcp') {
        $error_code = -1;
        $error_message = 'unsupported transport: ' . $scheme;
        return false;
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
    return \fsockopen($host, $port, $error_code, $error_message, $timeout);
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
function __mc_tcp_listen(string $host, int $port, int $backlog = 16)
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

    $fd = -1;
    $ai = $head;
    while ($ai !== 0) {
        if (\peek_i32(\int_to_ptr($ai), \__mc_ai_off(1)) === \__mc_net_const(0)) {
            $family = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(0));
            $proto = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(2));
            $cand = \Runtime\Libc\sys_socket($family, \__mc_net_const(0), $proto);
            if ($cand >= 0) {
                $a = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(4));
                $alen = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(3));
                if (\Runtime\Libc\sys_bind($cand, \int_to_ptr($a), $alen) === 0
                    && \Runtime\Libc\sys_listen($cand, $backlog) === 0) {
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
 * php.net's stream_socket_server, tcp:// only.
 * @param int $error_code
 * @param string $error_message
 * @return \Resource|false
 */
function stream_socket_server(string $address, &$error_code = 0, &$error_message = '')
{
    $error_code = 0;
    $error_message = '';
    $addr = $address;
    $scheme = 'tcp';
    $sep = \strpos($addr, '://');
    if ($sep !== false) {
        $scheme = \substr($addr, 0, $sep);
        $addr = \substr($addr, $sep + 3, \strlen($addr) - ($sep + 3));
    }
    if ($scheme !== 'tcp') {
        $error_code = -1;
        $error_message = 'unsupported transport: ' . $scheme;
        return false;
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
    $s = \__mc_tcp_listen($host, $port);
    if ($s === false) {
        $error_code = -1;
        $error_message = 'cannot listen on ' . $address;
        return false;
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
    return new \Resource(\Resource::KIND_SOCKET, 'stream', $fd);
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
        // [0] = body|false, [1] = redirect target ('' = none)
        if ($resp[1] !== '' && $hops < $maxRedirects) {
            $hops = $hops + 1;
            $location = $resp[1];
            // A relative Location is resolved against the current origin — same
            // scheme, so an https redirect stays on TLS.
            if (\strpos($location, '://') === false) {
                $location = $scheme . '://' . $host . ($port === $defPort ? '' : ':' . (string)$port)
                          . ($location !== '' && $location[0] === '/' ? '' : '/') . $location;
            }
            $url = $location;
            // A followed 301/302/303 becomes a bodyless GET (php's default). 307/308
            // method preservation is debt; extra headers and verify flags persist.
            $method = 'GET';
            $body = '';
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
            return [false, ''];
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
            return [false, $location];
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
            return [false, ''];
        }
        return [$body, ''];
    }
}
