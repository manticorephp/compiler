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
        Scheduler::instance()->waitIo($server, Event::Read);
    }
}

/** Read up to $length bytes (raw recv), suspending until readable. "" at EOF. */
function read(\Resource $conn, int $length): string
{
    $fd = $conn->addr;
    while (true) {
        $buf = \Runtime\Libc\calloc($length, 1);
        $n = \Async\sys_recv($fd, $buf, $length, 0);
        if ($n > 0) {
            $s = \str_from_buffer($buf, $n);
            \Runtime\Libc\free($buf);
            return $s;
        }
        \Runtime\Libc\free($buf);
        if ($n === 0) {
            return '';                       // peer closed
        }
        Scheduler::instance()->waitIo($conn, Event::Read);   // EWOULDBLOCK
    }
}

/** Write all of $data (raw send), suspending on back-pressure. */
function write(\Resource $conn, string $data): int
{
    $fd = $conn->addr;
    $len = \strlen($data);
    $total = 0;
    while ($total < $len) {
        $chunk = $total === 0 ? $data : \substr($data, $total);
        $n = \Async\sys_send($fd, $chunk, $len - $total, 0);
        if ($n > 0) {
            $total = $total + $n;
        } elseif ($n === 0) {
            break;
        } else {
            Scheduler::instance()->waitIo($conn, Event::Write);
        }
    }
    return $total;
}
