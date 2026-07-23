<?php

namespace Async;

use Io\Poll\Event;

/**
 * Blocking-LOOKING async I/O over the reactor. Each call sets the stream
 * non-blocking, attempts the operation, and on EWOULDBLOCK suspends the current
 * task until the fd is ready — then retries. The caller writes straight-line code;
 * the fiber does the yielding.
 */

/** Accept the next connection on a listening socket, suspending until one arrives. */
function accept($server)
{
    \stream_set_blocking($server, false);
    while (true) {
        $conn = \stream_socket_accept($server, 0);
        if ($conn !== false) {
            \stream_set_blocking($conn, false);
            return $conn;
        }
        Scheduler::instance()->waitIo($server, Event::Read);
    }
}

/**
 * Read up to $length bytes, suspending until data is available. Returns the bytes,
 * or "" at end-of-stream (peer closed) — mirrors a blocking fread's EOF.
 */
function read($stream, int $length): string
{
    \stream_set_blocking($stream, false);
    while (true) {
        $data = \fread($stream, $length);
        if ($data !== '' && $data !== false) {
            return $data;
        }
        if (\feof($stream)) {
            return '';
        }
        Scheduler::instance()->waitIo($stream, Event::Read);
    }
}

/** Write all of $data, suspending on back-pressure until the fd is writable. */
function write($stream, string $data): int
{
    \stream_set_blocking($stream, false);
    $total = 0;
    $len = \strlen($data);
    while ($total < $len) {
        $n = \fwrite($stream, \substr($data, $total));
        if ($n === false) {
            return $total;
        }
        if ($n === 0) {
            Scheduler::instance()->waitIo($stream, Event::Write);
            continue;
        }
        $total = $total + $n;
    }
    return $total;
}
