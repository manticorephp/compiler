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
