<?php

// ext/sockets (book.sockets) — the low-level BSD socket API. These are php.net's
// procedural socket_* functions, riding the libc syscalls already bound in
// Runtime\Libc (socket/bind/connect/listen/accept/send/recv/… — the same calls
// the stream stack in Net.php uses). The handle is a \Socket OBJECT (prelude),
// not a \Resource, mirroring php 8's migration off resources.
//
// UNLIKE the stream stack, this layer cannot lean on getaddrinfo to dodge every
// sockaddr: socket_bind($s, '0.0.0.0', 8080) must build a real sockaddr_in. So a
// sockaddr IS built by hand here (the one place besides Net.php's unix path), and
// the Darwin-vs-Linux layout split is confronted directly — see __mc_sock_fill.
//
// Host-DIVERGENT constants (SOL_SOCKET, SO_*, AF_INET6, O_NONBLOCK, errnos) are
// read from the runtime selector __mc_sock_const(): NEVER name the user-facing
// constant (AF_INET6, …) in this file — those fold via host_os() at compile time
// (LowerPrelude), a path that must not be reachable from stdlib source or the
// Zend cold seed dies (the FNM_*/PHP_OS rule).

/**
 * Read the fd off a \Socket. The funnel for the cell-erasure trap: a \Socket
 * arriving as an ARRAY element (socket_select) is a NaN-boxed cell, so reading
 * `$s->fd` on it directly would deref the box → SIGSEGV. A \Socket-typed param
 * forces codegen to unbox to a real object handle first. Public socket_* params
 * are already typed plain \Socket, so they read $fd directly and skip this.
 */
function __mc_sock_fd(\Socket $s): int
{
    return $s->fd;
}

/**
 * Host-divergent socket constants, runtime-selected (mirrors {@see __mc_net_const}).
 * Ints only, never an array — an assoc built in the stdlib faults across a call
 * boundary. $which:
 *   0 SOL_SOCKET  1 SO_ERROR  2 SO_RCVTIMEO  3 SO_SNDTIMEO  4 AF_INET6
 *   5 O_NONBLOCK  6 F_GETFL   7 F_SETFL      8 SO_LINGER    9 SO_REUSEADDR
 *  10 EWOULDBLOCK 11 EAGAIN
 */
function __mc_sock_const(int $which): int
{
    static $ready = 0;
    static $solSocket = 0;
    static $soError = 0;
    static $soRcvTimeo = 0;
    static $soSndTimeo = 0;
    static $afInet6 = 0;
    static $oNonblock = 0;
    static $soLinger = 0;
    static $soReuseAddr = 0;
    static $eWouldBlock = 0;
    static $eAgain = 0;

    if ($ready === 0) {
        $isDarwin = \__mc_host_is_darwin();
        // MEASURED Darwin arm64 <sys/socket.h>/<sys/fcntl.h>/<sys/errno.h> vs
        // Linux asm-generic (glibc/musl agree).
        $solSocket = $isDarwin ? 65535 : 1;
        $soError = $isDarwin ? 4103 : 4;
        $soRcvTimeo = $isDarwin ? 4102 : 20;
        $soSndTimeo = $isDarwin ? 4101 : 21;
        $afInet6 = $isDarwin ? 30 : 10;
        $oNonblock = $isDarwin ? 4 : 2048;
        $soLinger = $isDarwin ? 128 : 13;
        $soReuseAddr = $isDarwin ? 4 : 2;
        $eWouldBlock = $isDarwin ? 35 : 11;
        $eAgain = $isDarwin ? 35 : 11;
        $ready = 1;
    }

    if ($which === 0) { return $solSocket; }
    if ($which === 1) { return $soError; }
    if ($which === 2) { return $soRcvTimeo; }
    if ($which === 3) { return $soSndTimeo; }
    if ($which === 4) { return $afInet6; }
    if ($which === 5) { return $oNonblock; }
    if ($which === 6) { return 3; }         // F_GETFL — 3 everywhere
    if ($which === 7) { return 4; }         // F_SETFL — 4 everywhere
    if ($which === 8) { return $soLinger; }
    if ($which === 9) { return $soReuseAddr; }
    if ($which === 10) { return $eWouldBlock; }
    return $eAgain;
}

/**
 * Global last-errno holder for socket_last_error()/socket_clear_error() with no
 * socket argument. Int static, set from __mc_errno() right after a failing call
 * (before any close(2) can clobber it).
 */
function __mc_sock_errno(bool $write, int $val): int
{
    static $e = 0;
    if ($write) { $e = $val; }
    return $e;
}

/** Record errno on both the global holder and the socket that saw it. */
function __mc_sock_fail(\Socket $s): void
{
    $e = \__mc_errno();
    \__mc_sock_errno(true, $e);
    $s->lastErr = $e;
}

/**
 * Fill $buf (a zeroed >=128-byte sockaddr_storage) for ($family,$address,$port)
 * and return the sockaddr length, or -1 on a bad address. This is the manual
 * sockaddr the stream stack avoids — so the Darwin/Linux family-field split is
 * handled here, exactly as Net.php's __mc_unix_fill does.
 *
 * sin_port/sin6_port and sin_addr/sin6_addr sit at the SAME offsets on both hosts
 * (Darwin's 1-byte sin_len shifts only the family byte); only the family field
 * width differs. Ports are network byte order, assembled big-endian by hand.
 */
function __mc_sock_fill(\Ffi\Ptr $buf, int $family, string $address, int $port): int
{
    // AF_UNIX — path in sun_path; reuse the net stack's builder.
    if ($family === 1) {
        return \__mc_unix_fill($buf, $address);
    }

    $isDarwin = \__mc_host_is_darwin();
    $afInet6 = \__mc_sock_const(4);

    if ($family === $afInet6) {
        // sockaddr_in6: family, sin6_port@2, sin6_flowinfo@4, sin6_addr@8 (16B),
        // sin6_scope_id@24. sizeof 28.
        if ($isDarwin) {
            \poke_i8($buf, 0, 28);          // sin6_len
            \poke_i8($buf, 1, $afInet6);    // sin6_family (u8 @1)
        } else {
            \poke_i16($buf, 0, $afInet6);   // sin6_family (u16 @0)
        }
        \poke_i8($buf, 2, ($port >> 8) & 255);
        \poke_i8($buf, 3, $port & 255);
        if (\Runtime\Libc\sys_inet_pton($afInet6, $address, \ptr_offset($buf, 8)) !== 1) {
            return -1;
        }
        return 28;
    }

    // sockaddr_in (AF_INET = 2 on both hosts): sin_port@2, sin_addr@4. sizeof 16.
    if ($isDarwin) {
        \poke_i8($buf, 0, 16);   // sin_len
        \poke_i8($buf, 1, 2);    // sin_family = AF_INET (u8 @1)
    } else {
        \poke_i16($buf, 0, 2);   // sin_family (u16 @0)
    }
    \poke_i8($buf, 2, ($port >> 8) & 255);
    \poke_i8($buf, 3, $port & 255);
    if (\Runtime\Libc\sys_inet_pton(2, $address, \ptr_offset($buf, 4)) !== 1) {
        return -1;
    }
    return 16;
}

/**
 * Numeric host (or serv, when $wantHost is false) for a sockaddr, via
 * getnameinfo — no family-specific parsing on our side. Used to fill the address
 * out-params of getsockname/getpeername/recvfrom for AF_INET/AF_INET6.
 */
function __mc_sock_ni(\Ffi\Ptr $sa, int $alen, bool $wantHost): string
{
    $host = \Runtime\Libc\calloc(128, 1);
    $serv = \Runtime\Libc\calloc(32, 1);
    if ($host === null || $serv === null) {
        if ($host !== null) { \Runtime\Libc\free($host); }
        if ($serv !== null) { \Runtime\Libc\free($serv); }
        return '';
    }
    $flags = \__mc_net_const(8) | \__mc_net_const(10);   // NI_NUMERICHOST|NI_NUMERICSERV
    $rc = \Runtime\Libc\sys_getnameinfo($sa, $alen, $host, 128, $serv, 32, $flags);
    $out = '';
    if ($rc === 0) {
        $out = $wantHost ? \cstr_to_str($host) : \cstr_to_str($serv);
    }
    \Runtime\Libc\free($host);
    \Runtime\Libc\free($serv);
    return $out;
}

/**
 * The port of an AF_INET/AF_INET6 sockaddr, read straight from sin_port@2 in
 * network byte order. This is host-branch-free: Darwin's 1-byte sin_len shifts
 * only the family byte, so the port lands at offset 2 on every target (proved by
 * Net.php's __mc_sock_port). Reading it directly avoids a getnameinfo round-trip
 * for the port — getnameinfo is used for the numeric HOST string only.
 */
function __mc_sock_port_of(\Ffi\Ptr $sa): int
{
    return (\peek_u8($sa, 2) << 8) | \peek_u8($sa, 3);
}

/** The sun_path of an AF_UNIX sockaddr (bytes from offset 2 up to the first NUL). */
function __mc_sock_unpath(\Ffi\Ptr $sa, int $alen): string
{
    $out = '';
    $i = 2;
    while ($i < $alen) {
        $b = \peek_u8($sa, $i);
        if ($b === 0) { break; }
        $out = $out . \chr($b);
        $i = $i + 1;
    }
    return $out;
}

// ── the public socket_* surface ────────────────────────────────────────

function socket_create(int $domain, int $type, int $protocol): \Socket|false
{
    $fd = \Runtime\Libc\sys_socket($domain, $type, $protocol);
    if ($fd < 0) {
        \__mc_sock_errno(true, \__mc_errno());
        return false;
    }
    return new \Socket($fd, $domain, $type, $protocol);
}

/**
 * Create a listening AF_INET socket bound to a port (php's socket_create_listen).
 * php binds to INADDR_ANY (0.0.0.0), sets SO_REUSEADDR, listens.
 */
function socket_create_listen(int $port, int $backlog = 128): \Socket|false
{
    $s = \socket_create(2, 1, 6);           // AF_INET, SOCK_STREAM, IPPROTO_TCP
    if ($s === false) {
        return false;
    }
    \socket_set_option($s, \__mc_sock_const(0), \__mc_sock_const(9), 1);   // SO_REUSEADDR
    if (!\socket_bind($s, '0.0.0.0', $port) || !\socket_listen($s, $backlog)) {
        \socket_close($s);
        return false;
    }
    return $s;
}

function socket_bind(\Socket $socket, string $address, int $port = 0): bool
{
    $buf = \Runtime\Libc\calloc(128, 1);
    if ($buf === null) {
        return false;
    }
    $len = \__mc_sock_fill($buf, $socket->family, $address, $port);
    if ($len < 0) {
        \Runtime\Libc\free($buf);
        return false;
    }
    $rc = \Runtime\Libc\sys_bind($socket->fd, $buf, $len);
    if ($rc !== 0) { \__mc_sock_fail($socket); }
    \Runtime\Libc\free($buf);
    return $rc === 0;
}

function socket_connect(\Socket $socket, string $address, int $port = 0): bool
{
    $buf = \Runtime\Libc\calloc(128, 1);
    if ($buf === null) {
        return false;
    }
    $len = \__mc_sock_fill($buf, $socket->family, $address, $port);
    if ($len < 0) {
        \Runtime\Libc\free($buf);
        return false;
    }
    $rc = \Runtime\Libc\sys_connect($socket->fd, $buf, $len);
    if ($rc !== 0) { \__mc_sock_fail($socket); }
    \Runtime\Libc\free($buf);
    return $rc === 0;
}

function socket_listen(\Socket $socket, int $backlog = 0): bool
{
    $rc = \Runtime\Libc\sys_listen($socket->fd, $backlog);
    if ($rc !== 0) { \__mc_sock_fail($socket); }
    return $rc === 0;
}

function socket_accept(\Socket $socket): \Socket|false
{
    $fd = \Runtime\Libc\sys_accept($socket->fd, \int_to_ptr(0), \int_to_ptr(0));
    if ($fd < 0) {
        \__mc_sock_fail($socket);
        return false;
    }
    return new \Socket($fd, $socket->family, $socket->type, $socket->proto);
}

function socket_close(\Socket $socket): void
{
    $socket->close();
}

function socket_write(\Socket $socket, string $data, ?int $length = null): int|false
{
    $n = \strlen($data);
    if ($length !== null && $length >= 0 && $length < $n) {
        $n = $length;
        $data = \substr($data, 0, $n);
    }
    $sent = \Runtime\Libc\sys_send($socket->fd, $data, $n, 0);
    if ($sent < 0) {
        \__mc_sock_fail($socket);
        return false;
    }
    return $sent;
}

function socket_send(\Socket $socket, string $data, int $length, int $flags): int|false
{
    $n = \strlen($data);
    if ($length >= 0 && $length < $n) {
        $n = $length;
        $data = \substr($data, 0, $n);
    }
    $sent = \Runtime\Libc\sys_send($socket->fd, $data, $n, $flags);
    if ($sent < 0) {
        \__mc_sock_fail($socket);
        return false;
    }
    return $sent;
}

/**
 * socket_recv($sock, &$buf, $length, $flags). Reads up to $length bytes into $buf
 * (write-only out-param). Returns the byte count, 0 at the peer's orderly close,
 * or false on error.
 */
function socket_recv(\Socket $socket, #[RefOut] string &$buf, int $length, int $flags): int|false
{
    if ($length <= 0) {
        $buf = '';
        return 0;
    }
    $tmp = \Runtime\Libc\calloc($length, 1);
    if ($tmp === null) {
        return false;
    }
    $n = \Runtime\Libc\sys_recv($socket->fd, $tmp, $length, $flags);
    if ($n < 0) {
        \__mc_sock_fail($socket);
        \Runtime\Libc\free($tmp);
        $buf = '';
        return false;
    }
    $buf = \str_from_buffer($tmp, $n);
    \Runtime\Libc\free($tmp);
    return $n;
}

/**
 * socket_read($sock, $length, $mode). PHP_BINARY_READ (default, 2) does one recv
 * up to $length. PHP_NORMAL_READ (1) reads until a line ending, one byte per recv.
 */
function socket_read(\Socket $socket, int $length, int $mode = 2): string|false
{
    if ($length <= 0) {
        return '';
    }
    if ($mode === 1) {
        // Read until \n or \r (php stops AT the terminator and drops it).
        $out = '';
        while (\strlen($out) < $length) {
            $one = \Runtime\Libc\calloc(1, 1);
            if ($one === null) { return false; }
            $n = \Runtime\Libc\sys_recv($socket->fd, $one, 1, 0);
            if ($n < 0) {
                \__mc_sock_fail($socket);
                \Runtime\Libc\free($one);
                return false;
            }
            if ($n === 0) {
                \Runtime\Libc\free($one);
                break;
            }
            $c = \peek_u8($one, 0);
            \Runtime\Libc\free($one);
            if ($c === 10 || $c === 13) { break; }
            $out = $out . \chr($c);
        }
        return $out;
    }

    $tmp = \Runtime\Libc\calloc($length, 1);
    if ($tmp === null) {
        return false;
    }
    $n = \Runtime\Libc\sys_recv($socket->fd, $tmp, $length, 0);
    if ($n < 0) {
        \__mc_sock_fail($socket);
        \Runtime\Libc\free($tmp);
        return false;
    }
    $out = \str_from_buffer($tmp, $n);
    \Runtime\Libc\free($tmp);
    return $out;
}

function socket_sendto(\Socket $socket, string $data, int $length, int $flags,
                       string $address, int $port = 0): int|false
{
    $n = \strlen($data);
    if ($length >= 0 && $length < $n) {
        $n = $length;
        $data = \substr($data, 0, $n);
    }
    $buf = \Runtime\Libc\calloc(128, 1);
    if ($buf === null) {
        return false;
    }
    $alen = \__mc_sock_fill($buf, $socket->family, $address, $port);
    if ($alen < 0) {
        \Runtime\Libc\free($buf);
        return false;
    }
    $sent = \Runtime\Libc\sys_sendto($socket->fd, $data, $n, $flags, $buf, $alen);
    if ($sent < 0) { \__mc_sock_fail($socket); }
    \Runtime\Libc\free($buf);
    return $sent < 0 ? false : $sent;
}

/**
 * socket_recvfrom($sock, &$buf, $length, $flags, &$address, &$port). Fills $buf
 * with the datagram and $address/$port with the sender.
 */
function socket_recvfrom(\Socket $socket, #[RefOut] string &$buf, int $length, int $flags,
                         #[RefOut] string &$address, #[RefOut] int &$port = 0): int|false
{
    if ($length <= 0) {
        $buf = '';
        $address = '';
        $port = 0;
        return 0;
    }
    $tmp = \Runtime\Libc\calloc($length, 1);
    $sa = \Runtime\Libc\calloc(128, 1);
    $slen = \Runtime\Libc\calloc(4, 1);
    if ($tmp === null || $sa === null || $slen === null) {
        if ($tmp !== null) { \Runtime\Libc\free($tmp); }
        if ($sa !== null) { \Runtime\Libc\free($sa); }
        if ($slen !== null) { \Runtime\Libc\free($slen); }
        return false;
    }
    \poke_i32($slen, 0, 128);
    $n = \Runtime\Libc\sys_recvfrom($socket->fd, $tmp, $length, $flags, $sa, $slen);
    if ($n < 0) {
        \__mc_sock_fail($socket);
        \Runtime\Libc\free($tmp);
        \Runtime\Libc\free($sa);
        \Runtime\Libc\free($slen);
        return false;
    }
    $buf = \str_from_buffer($tmp, $n);
    $alen = \peek_i32($slen, 0);
    if ($socket->family === 1) {
        $address = \__mc_sock_unpath($sa, $alen);
        $port = 0;
    } else {
        $address = \__mc_sock_ni($sa, $alen, true);
        $port = (int)\__mc_sock_ni($sa, $alen, false);
    }
    \Runtime\Libc\free($tmp);
    \Runtime\Libc\free($sa);
    \Runtime\Libc\free($slen);
    return $n;
}

function socket_getsockname(\Socket $socket, #[RefOut] string &$address, #[RefOut] int &$port = 0): bool
{
    $sa = \Runtime\Libc\calloc(128, 1);
    $slen = \Runtime\Libc\calloc(4, 1);
    if ($sa === null || $slen === null) {
        if ($sa !== null) { \Runtime\Libc\free($sa); }
        if ($slen !== null) { \Runtime\Libc\free($slen); }
        return false;
    }
    \poke_i32($slen, 0, 128);
    $rc = \Runtime\Libc\sys_getsockname($socket->fd, $sa, $slen);
    if ($rc === 0) {
        $alen = \peek_i32($slen, 0);
        if ($socket->family === 1) {
            $address = \__mc_sock_unpath($sa, $alen);
            $port = 0;
        } else {
            $address = \__mc_sock_ni($sa, $alen, true);
            $port = \__mc_sock_port_of($sa);
        }
    } else {
        \__mc_sock_fail($socket);
    }
    \Runtime\Libc\free($sa);
    \Runtime\Libc\free($slen);
    return $rc === 0;
}

function socket_getpeername(\Socket $socket, #[RefOut] string &$address, #[RefOut] int &$port = 0): bool
{
    $sa = \Runtime\Libc\calloc(128, 1);
    $slen = \Runtime\Libc\calloc(4, 1);
    if ($sa === null || $slen === null) {
        if ($sa !== null) { \Runtime\Libc\free($sa); }
        if ($slen !== null) { \Runtime\Libc\free($slen); }
        return false;
    }
    \poke_i32($slen, 0, 128);
    $rc = \Runtime\Libc\sys_getpeername($socket->fd, $sa, $slen);
    if ($rc === 0) {
        $alen = \peek_i32($slen, 0);
        if ($socket->family === 1) {
            $address = \__mc_sock_unpath($sa, $alen);
            $port = 0;
        } else {
            $address = \__mc_sock_ni($sa, $alen, true);
            $port = \__mc_sock_port_of($sa);
        }
    } else {
        \__mc_sock_fail($socket);
    }
    \Runtime\Libc\free($sa);
    \Runtime\Libc\free($slen);
    return $rc === 0;
}

/**
 * socket_set_option. $value is an int for most options, or an array for the two
 * struct options: SO_LINGER (['l_onoff'=>,'l_linger'=>]) and SO_RCVTIMEO/SO_SNDTIMEO
 * (['sec'=>,'usec'=>]).
 */
function socket_set_option(\Socket $socket, int $level, int $option, mixed $value): bool
{
    $rcvTimeo = \__mc_sock_const(2);
    $sndTimeo = \__mc_sock_const(3);
    $soLinger = \__mc_sock_const(8);

    if ($option === $rcvTimeo || $option === $sndTimeo) {
        // struct timeval { time_t tv_sec; suseconds_t tv_usec; }. tv_usec is 4
        // bytes on Darwin, 8 on Linux; the struct is 16 bytes on both.
        $sec = (int)($value['sec'] ?? 0);
        $usec = (int)($value['usec'] ?? 0);
        $tv = \Runtime\Libc\calloc(16, 1);
        if ($tv === null) { return false; }
        \poke_i64($tv, 0, $sec);
        if (\__mc_host_is_darwin()) {
            \poke_i32($tv, 8, $usec);
        } else {
            \poke_i64($tv, 8, $usec);
        }
        $rc = \Runtime\Libc\sys_setsockopt($socket->fd, $level, $option, $tv, 16);
        \Runtime\Libc\free($tv);
        if ($rc !== 0) { \__mc_sock_fail($socket); }
        return $rc === 0;
    }

    if ($option === $soLinger) {
        // struct linger { int l_onoff; int l_linger; } — 8 bytes both hosts.
        $onoff = (int)($value['l_onoff'] ?? 0);
        $linger = (int)($value['l_linger'] ?? 0);
        $lg = \Runtime\Libc\calloc(8, 1);
        if ($lg === null) { return false; }
        \poke_i32($lg, 0, $onoff);
        \poke_i32($lg, 4, $linger);
        $rc = \Runtime\Libc\sys_setsockopt($socket->fd, $level, $option, $lg, 8);
        \Runtime\Libc\free($lg);
        if ($rc !== 0) { \__mc_sock_fail($socket); }
        return $rc === 0;
    }

    // Plain int option.
    $iv = (int)$value;
    $p = \Runtime\Libc\calloc(4, 1);
    if ($p === null) { return false; }
    \poke_i32($p, 0, $iv);
    $rc = \Runtime\Libc\sys_setsockopt($socket->fd, $level, $option, $p, 4);
    \Runtime\Libc\free($p);
    if ($rc !== 0) { \__mc_sock_fail($socket); }
    return $rc === 0;
}

/** php spells socket_setopt as an alias of socket_set_option. */
function socket_setopt(\Socket $socket, int $level, int $option, mixed $value): bool
{
    return \socket_set_option($socket, $level, $option, $value);
}

function socket_get_option(\Socket $socket, int $level, int $option): mixed
{
    $rcvTimeo = \__mc_sock_const(2);
    $sndTimeo = \__mc_sock_const(3);
    $soLinger = \__mc_sock_const(8);

    if ($option === $rcvTimeo || $option === $sndTimeo) {
        $tv = \Runtime\Libc\calloc(16, 1);
        $len = \Runtime\Libc\calloc(4, 1);
        if ($tv === null || $len === null) {
            if ($tv !== null) { \Runtime\Libc\free($tv); }
            if ($len !== null) { \Runtime\Libc\free($len); }
            return false;
        }
        \poke_i32($len, 0, 16);
        $rc = \Runtime\Libc\sys_getsockopt($socket->fd, $level, $option, $tv, $len);
        $sec = \peek_i64($tv, 0);
        $usec = \__mc_host_is_darwin() ? \peek_i32($tv, 8) : \peek_i64($tv, 8);
        \Runtime\Libc\free($tv);
        \Runtime\Libc\free($len);
        if ($rc !== 0) { \__mc_sock_fail($socket); return false; }
        return ['sec' => $sec, 'usec' => $usec];
    }

    if ($option === $soLinger) {
        $lg = \Runtime\Libc\calloc(8, 1);
        $len = \Runtime\Libc\calloc(4, 1);
        if ($lg === null || $len === null) {
            if ($lg !== null) { \Runtime\Libc\free($lg); }
            if ($len !== null) { \Runtime\Libc\free($len); }
            return false;
        }
        \poke_i32($len, 0, 8);
        $rc = \Runtime\Libc\sys_getsockopt($socket->fd, $level, $option, $lg, $len);
        $onoff = \peek_i32($lg, 0);
        $linger = \peek_i32($lg, 4);
        \Runtime\Libc\free($lg);
        \Runtime\Libc\free($len);
        if ($rc !== 0) { \__mc_sock_fail($socket); return false; }
        return ['l_onoff' => $onoff, 'l_linger' => $linger];
    }

    $p = \Runtime\Libc\calloc(4, 1);
    $len = \Runtime\Libc\calloc(4, 1);
    if ($p === null || $len === null) {
        if ($p !== null) { \Runtime\Libc\free($p); }
        if ($len !== null) { \Runtime\Libc\free($len); }
        return false;
    }
    \poke_i32($len, 0, 4);
    $rc = \Runtime\Libc\sys_getsockopt($socket->fd, $level, $option, $p, $len);
    $v = \peek_i32($p, 0);
    \Runtime\Libc\free($p);
    \Runtime\Libc\free($len);
    if ($rc !== 0) { \__mc_sock_fail($socket); return false; }
    return $v;
}

function socket_getopt(\Socket $socket, int $level, int $option): mixed
{
    return \socket_get_option($socket, $level, $option);
}

function socket_shutdown(\Socket $socket, int $mode = 2): bool
{
    $rc = \Runtime\Libc\sys_shutdown($socket->fd, $mode);
    if ($rc !== 0) { \__mc_sock_fail($socket); }
    return $rc === 0;
}

/**
 * socket_select(&$read, &$write, &$except, $sec, $usec). Implemented over poll()
 * (not select/fd_set — the codebase deliberately avoids fd_set's FD_SETSIZE and
 * timeval-width divergence). The three arrays are rewritten in place to hold only
 * the ready sockets; the return value is the total count of ready sockets, 0 on
 * timeout, or false on error.
 */
function socket_select(?array &$read, ?array &$write, ?array &$except, ?int $seconds, int $microseconds = 0): int|false
{
    $POLLIN = \__mc_net_const(1);
    $POLLOUT = \__mc_net_const(2);
    $POLLERR = \__mc_net_const(3);
    $POLLHUP = \__mc_net_const(4);
    $POLLPRI = 2;

    // Collect distinct fds with combined requested events.
    /** @var int[] $fds */
    $fds = [];
    /** @var int[] $evs */
    $evs = [];

    if ($read !== null) {
        foreach ($read as $s) {
            $fd = \__mc_sock_fd($s);
            $idx = \__mc_sel_index($fds, $fd);
            if ($idx < 0) { $fds[] = $fd; $evs[] = $POLLIN; }
            else { $evs[$idx] = $evs[$idx] | $POLLIN; }
        }
    }
    if ($write !== null) {
        foreach ($write as $s) {
            $fd = \__mc_sock_fd($s);
            $idx = \__mc_sel_index($fds, $fd);
            if ($idx < 0) { $fds[] = $fd; $evs[] = $POLLOUT; }
            else { $evs[$idx] = $evs[$idx] | $POLLOUT; }
        }
    }
    if ($except !== null) {
        foreach ($except as $s) {
            $fd = \__mc_sock_fd($s);
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

    // Timeout: null seconds = block forever (-1); otherwise sec*1000 + usec/1000.
    $timeoutMs = $seconds === null ? -1 : ($seconds * 1000 + \intdiv($microseconds, 1000));
    $rc = \Runtime\Libc\sys_poll($pfds, $count, $timeoutMs);
    if ($rc < 0) {
        \__mc_sock_errno(true, \__mc_errno());
        \Runtime\Libc\free($pfds);
        return false;
    }

    // Read revents back per fd.
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
            $r = $rev[\__mc_sel_index($fds, \__mc_sock_fd($s))];
            if (($r & ($POLLIN | $POLLHUP | $POLLERR)) !== 0) { $nr[] = \__mc_sock_id($s); $ready = $ready + 1; }
        }
        $read = $nr;
    }
    if ($write !== null) {
        $nw = [];
        foreach ($write as $s) {
            $r = $rev[\__mc_sel_index($fds, \__mc_sock_fd($s))];
            if (($r & ($POLLOUT | $POLLERR)) !== 0) { $nw[] = \__mc_sock_id($s); $ready = $ready + 1; }
        }
        $write = $nw;
    }
    if ($except !== null) {
        $ne = [];
        foreach ($except as $s) {
            $r = $rev[\__mc_sel_index($fds, \__mc_sock_fd($s))];
            if (($r & ($POLLPRI | $POLLERR)) !== 0) { $ne[] = \__mc_sock_id($s); $ready = $ready + 1; }
        }
        $except = $ne;
    }

    return $ready;
}

/**
 * Identity funnel that RE-TYPES an erased select-array element to \Socket in the
 * caller's frame, so the inline `$nr[] = __mc_sock_id($s)` store retains it (+1) —
 * else the `$read = $nr` rewrite over-releases the caller's sockets (freed after the
 * first call, the stream_select bug). A by-ref-array store helper would retain too
 * but its .sig writeback erases the element repr (breaks `$r[0] instanceof Socket`);
 * a return-funnel stays in-frame and keeps the concrete obj repr.
 */
function __mc_sock_id(\Socket $s): \Socket
{
    return $s;
}

/** Linear lookup of $fd in the parallel $fds list (small n; avoids an assoc). */
function __mc_sel_index(array $fds, int $fd): int
{
    $n = \count($fds);
    for ($i = 0; $i < $n; $i = $i + 1) {
        if ($fds[$i] === $fd) { return $i; }
    }
    return -1;
}

/**
 * socket_last_error(?Socket). With a socket, its per-socket errno; otherwise the
 * global last errno. Both are set right after a failing call.
 */
function socket_last_error(?\Socket $socket = null): int
{
    if ($socket !== null) {
        return \__mc_sock_last_of($socket);
    }
    return \__mc_sock_errno(false, 0);
}

/** Funnel: read $socket->lastErr off a plainly-typed \Socket (the ?Socket param is a cell). */
function __mc_sock_last_of(\Socket $socket): int
{
    return $socket->lastErr;
}

function socket_clear_error(?\Socket $socket = null): void
{
    if ($socket !== null) {
        \__mc_sock_clear_of($socket);
    } else {
        \__mc_sock_errno(true, 0);
    }
}

function __mc_sock_clear_of(\Socket $socket): void
{
    $socket->lastErr = 0;
}

function socket_strerror(int $error_code): string
{
    return \cstr_to_str(\Runtime\Libc\strerror($error_code));
}

// ── Ф2: pairs, blocking mode, stream bridge, addrinfo ──────────────────

/** Copy a PHP string into a fresh NUL-padded libc buffer (caller frees). Used to
 * hand a raw sockaddr (held in an AddressInfo as a string) back to connect/bind,
 * which want a Ptr. */
function __mc_str_to_buf(string $s): ?\Ffi\Ptr
{
    $n = \strlen($s);
    $buf = \Runtime\Libc\calloc($n + 1, 1);
    if ($buf === null) {
        return null;
    }
    for ($i = 0; $i < $n; $i = $i + 1) {
        \poke_i8($buf, $i, \ord($s[$i]));
    }
    return $buf;
}

/** The address family a bare fd belongs to, read from getsockname's sockaddr
 * family field (host-branched: Darwin @1 u8, Linux @0 u16). Returns the HOST's AF
 * value, which is exactly what __mc_sock_fill expects back. 0 if unknown. */
function __mc_sock_domain(int $fd): int
{
    $sa = \Runtime\Libc\calloc(128, 1);
    $len = \Runtime\Libc\calloc(4, 1);
    if ($sa === null || $len === null) {
        if ($sa !== null) { \Runtime\Libc\free($sa); }
        if ($len !== null) { \Runtime\Libc\free($len); }
        return 0;
    }
    \poke_i32($len, 0, 128);
    $rc = \Runtime\Libc\sys_getsockname($fd, $sa, $len);
    $fam = 0;
    if ($rc === 0) {
        $fam = \__mc_host_is_darwin() ? \peek_u8($sa, 1) : \peek_u16($sa, 0);
    }
    \Runtime\Libc\free($sa);
    \Runtime\Libc\free($len);
    return $fam;
}

/**
 * socket_create_pair($domain, $type, $protocol, &$pair). Fills $pair with two
 * connected \Socket objects. AF_UNIX is the usual domain.
 */
function socket_create_pair(int $domain, int $type, int $protocol, #[RefOut] array &$pair): bool
{
    $sv = \Runtime\Libc\calloc(8, 1);
    if ($sv === null) {
        return false;
    }
    $rc = \Runtime\Libc\sys_socketpair($domain, $type, $protocol, $sv);
    if ($rc !== 0) {
        \__mc_sock_errno(true, \__mc_errno());
        \Runtime\Libc\free($sv);
        return false;
    }
    $fd0 = \peek_i32($sv, 0);
    $fd1 = \peek_i32($sv, 4);
    \Runtime\Libc\free($sv);
    $pair = [
        new \Socket($fd0, $domain, $type, $protocol),
        new \Socket($fd1, $domain, $type, $protocol),
    ];
    return true;
}

function socket_set_nonblock(\Socket $socket): bool
{
    $fl = \Runtime\Libc\sys_fcntl($socket->fd, \__mc_sock_const(6), 0);   // F_GETFL
    if ($fl < 0) { \__mc_sock_fail($socket); return false; }
    $rc = \Runtime\Libc\sys_fcntl($socket->fd, \__mc_sock_const(7), $fl | \__mc_sock_const(5));
    if ($rc < 0) { \__mc_sock_fail($socket); }
    return $rc >= 0;
}

function socket_set_block(\Socket $socket): bool
{
    $fl = \Runtime\Libc\sys_fcntl($socket->fd, \__mc_sock_const(6), 0);   // F_GETFL
    if ($fl < 0) { \__mc_sock_fail($socket); return false; }
    $rc = \Runtime\Libc\sys_fcntl($socket->fd, \__mc_sock_const(7), $fl & ~\__mc_sock_const(5));
    if ($rc < 0) { \__mc_sock_fail($socket); }
    return $rc >= 0;
}

/**
 * socket_import_stream(\Resource): wrap a socket-backed stream's fd in a \Socket.
 * The fd is DUP'd so the two handles own independent fds — closing one leaves the
 * other usable. (php shares the same fd; the difference is only observable if a
 * program closes one and then uses the other, and dup is the safer default for
 * our RAII close.)
 */
function socket_import_stream(\Resource $stream): \Socket|false
{
    if ($stream->kind !== \Resource::KIND_SOCKET && $stream->kind !== \Resource::KIND_TLS) {
        return false;
    }
    $nfd = \Runtime\Libc\sys_dup($stream->addr);
    if ($nfd < 0) {
        \__mc_sock_errno(true, \__mc_errno());
        return false;
    }
    $fam = \__mc_sock_domain($nfd);
    return new \Socket($nfd, $fam, 1, 0);
}

/**
 * socket_export_stream(\Socket): wrap a \Socket's fd in a stream \Resource. The fd
 * is DUP'd (see socket_import_stream).
 */
function socket_export_stream(\Socket $socket): \Resource|false
{
    $nfd = \Runtime\Libc\sys_dup($socket->fd);
    if ($nfd < 0) {
        \__mc_sock_fail($socket);
        return false;
    }
    return new \Resource(\Resource::KIND_SOCKET, 'stream', $nfd);
}

/**
 * socket_addrinfo_lookup($host, $service, $hints). Resolves $host/$service to a
 * list of \AddressInfo. hints filters by ai_family / ai_socktype when present
 * (NULL hints passed to getaddrinfo, results filtered — the Net.php approach that
 * avoids depending on sizeof(addrinfo)).
 *
 * @param array<string,int> $hints
 * @return \AddressInfo[]
 */
function socket_addrinfo_lookup(string $host, ?string $service = null, array $hints = []): array
{
    $res = \Runtime\Libc\calloc(8, 1);
    if ($res === null) {
        return [];
    }
    $svc = $service ?? '';
    $rc = \Runtime\Libc\sys_getaddrinfo($host, $svc, \int_to_ptr(0), $res);
    if ($rc !== 0) {
        \Runtime\Libc\free($res);
        return [];
    }
    $head = \peek_i64($res, 0);
    \Runtime\Libc\free($res);

    $wantFamily = isset($hints['ai_family']) ? (int)$hints['ai_family'] : -1;
    $wantType = isset($hints['ai_socktype']) ? (int)$hints['ai_socktype'] : -1;

    /** @var \AddressInfo[] $out */
    $out = [];
    $ai = $head;
    while ($ai !== 0) {
        $family = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(0));
        $socktype = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(1));
        $proto = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(2));
        $keep = ($wantFamily < 0 || $family === $wantFamily)
            && ($wantType < 0 || $socktype === $wantType);
        if ($keep) {
            $addr = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(4));
            $alen = \peek_i32(\int_to_ptr($ai), \__mc_ai_off(3));
            $bytes = $addr !== 0 ? \str_from_buffer(\int_to_ptr($addr), $alen) : '';
            $out[] = new \AddressInfo($family, $socktype, $proto, $bytes, $alen);
        }
        $ai = \peek_i64(\int_to_ptr($ai), \__mc_ai_off(5));
    }
    \Runtime\Libc\sys_freeaddrinfo(\int_to_ptr($head));
    return $out;
}

function socket_addrinfo_connect(\AddressInfo $address): \Socket|false
{
    $fd = \Runtime\Libc\sys_socket($address->family, $address->socktype, $address->protocol);
    if ($fd < 0) {
        \__mc_sock_errno(true, \__mc_errno());
        return false;
    }
    $buf = \__mc_str_to_buf($address->addr);
    if ($buf === null) {
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    $rc = \Runtime\Libc\sys_connect($fd, $buf, $address->addrlen);
    \Runtime\Libc\free($buf);
    if ($rc !== 0) {
        \__mc_sock_errno(true, \__mc_errno());
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    return new \Socket($fd, $address->family, $address->socktype, $address->protocol);
}

function socket_addrinfo_bind(\AddressInfo $address): \Socket|false
{
    $fd = \Runtime\Libc\sys_socket($address->family, $address->socktype, $address->protocol);
    if ($fd < 0) {
        \__mc_sock_errno(true, \__mc_errno());
        return false;
    }
    $buf = \__mc_str_to_buf($address->addr);
    if ($buf === null) {
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    $rc = \Runtime\Libc\sys_bind($fd, $buf, $address->addrlen);
    \Runtime\Libc\free($buf);
    if ($rc !== 0) {
        \__mc_sock_errno(true, \__mc_errno());
        \Runtime\Libc\sys_close($fd);
        return false;
    }
    return new \Socket($fd, $address->family, $address->socktype, $address->protocol);
}

/**
 * socket_addrinfo_explain(\AddressInfo): the php-shaped decode of one entry.
 * @return array<string,mixed>
 */
function socket_addrinfo_explain(\AddressInfo $address): array
{
    $buf = \__mc_str_to_buf($address->addr);
    $host = '';
    $port = 0;
    if ($buf !== null) {
        if ($address->family === 1) {
            $host = \__mc_sock_unpath($buf, $address->addrlen);
        } else {
            $host = \__mc_sock_ni($buf, $address->addrlen, true);
            $port = \__mc_sock_port_of($buf);
        }
        \Runtime\Libc\free($buf);
    }
    $afInet6 = \__mc_sock_const(4);
    $addrKey = $address->family === $afInet6 ? 'sin6_addr' : 'sin_addr';
    $portKey = $address->family === $afInet6 ? 'sin6_port' : 'sin_port';
    return [
        'ai_flags' => 0,
        'ai_family' => $address->family,
        'ai_socktype' => $address->socktype,
        'ai_protocol' => $address->protocol,
        'ai_addr' => [$portKey => $port, $addrKey => $host],
    ];
}

function socket_atmark(\Socket $socket): bool
{
    return \Runtime\Libc\sys_sockatmark($socket->fd) === 1;
}

/**
 * socket_cmsg_space($level, $type, $num). The buffer size for ancillary data of
 * $type. NOT implemented — CMSG_SPACE needs msghdr/cmsghdr alignment math, part
 * of the deferred sendmsg/recvmsg subsystem. Returns null (php returns ?int).
 */
function socket_cmsg_space(int $level, int $type, int $num = 0): ?int
{
    return null;
}
