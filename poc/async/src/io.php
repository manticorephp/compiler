<?php

namespace Async;

use Io\Poll\Event;

// Raw fd-level async I/O. We have our OWN reactor (Io\Poll = kqueue/epoll), so the
// read/write path must NOT go through the stream stdio layer (fread/fwrite) — that
// does its own internal poll(2) per call, blocking the single-threaded scheduler.
// Instead: raw recv/send on the fd (non-blocking). recv/send returning <0 means
// EWOULDBLOCK (we only got here because the reactor said ready, so treat it as a
// spurious wake) → suspend on the reactor and retry. The reactor is the ONLY thing
// that ever waits.

#[\Ffi\Library('c'), \Ffi\Symbol('recv')]
function sys_recv(int $fd, \Ffi\Ptr $buf, int $len, int $flags): int {}

#[\Ffi\Library('c'), \Ffi\Symbol('send')]
function sys_send(int $fd, string $buf, int $len, int $flags): int {}

/** Connect to $addr (e.g. "tcp://127.0.0.1:8080"), returning a non-blocking stream. */
function connect(string $addr): \Resource
{
    $errno = 0;
    $errstr = "";
    $conn = \stream_socket_client($addr, $errno, $errstr);
    if ($conn === false) {
        throw new \RuntimeException("connect failed: " . $errstr);
    }
    \stream_set_blocking($conn, false);
    return $conn;
}

/** Accept the next connection, suspending until one arrives. Sets it non-blocking ONCE. */
function accept(\Resource $server): \Resource
{
    while (true) {
        $conn = \stream_socket_accept($server, 0);
        if ($conn !== false) {
            \stream_set_blocking($conn, false);
            return $conn;
        }
        Scheduler::instance()->waitReadable($server);
    }
}

/** Drop the connection's persistent reactor watcher, then close it. */
function close(\Resource $conn): void
{
    Scheduler::instance()->closeConn($conn);
    \fclose($conn);
}

/** Read up to $length bytes (raw recv), suspending until readable. "" at EOF. */
function read(\Resource $conn, int $length): string
{
    $fd = $conn->addr;
    $sched = Scheduler::instance();
    $buf = $sched->readBuf($length);         // reused, no per-read calloc/free
    // Optimistic read (data may already be buffered).
    $n = \Async\sys_recv($fd, $buf, $length, 0);
    if ($n > 0) {
        return \str_from_buffer($buf, $n);
    }
    if ($n === 0) {
        return '';                           // clean EOF
    }
    // n < 0 = EWOULDBLOCK (no data yet). Suspend until readable, then read ONCE
    // more. A <= 0 return after a readability wake is EOF or a hard error
    // (ECONNRESET) — not would-block — so we return closed rather than spinning
    // forever on a reset connection (there is no errno binding to distinguish).
    $sched->waitReadable($conn);
    $n = \Async\sys_recv($fd, $buf, $length, 0);
    if ($n > 0) {
        return \str_from_buffer($buf, $n);
    }
    return '';                               // EOF or error → closed
}

/** Write all of $data (raw send), suspending on back-pressure. */
function write(\Resource $conn, string $data): int
{
    $fd = $conn->addr;
    $len = \strlen($data);
    $total = 0;
    $sched = Scheduler::instance();
    while ($total < $len) {
        $chunk = $total === 0 ? $data : \substr($data, $total);
        $n = \Async\sys_send($fd, $chunk, $len - $total, 0);
        if ($n > 0) {
            $total = $total + $n;
            continue;
        }
        if ($n === 0) {
            break;
        }
        // n < 0 = back-pressure (EWOULDBLOCK). Wait for writability, retry ONCE;
        // a second <= 0 is a hard error (EPIPE / reset), not would-block, so we
        // stop rather than spin forever writing to a dead peer.
        $sched->waitWritable($conn);
        $n = \Async\sys_send($fd, $chunk, $len - $total, 0);
        if ($n > 0) {
            $total = $total + $n;
        } else {
            break;
        }
    }
    return $total;
}
