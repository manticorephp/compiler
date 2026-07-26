<?php

// A supervised HTTP server that shuts down cleanly — the shape a real service
// needs, and the point of the pcntl layer.
//
//   ./poc/async/server_bin &          # 4 supervised workers on :8081
//   kill -TERM %1                     # every worker drains and exits 0
//
// Nothing here is signal-specific plumbing: shutdownOn() cancels the ROOT scope,
// and everything else follows from cancellation already being structured — the
// accept loop and every in-flight connection raise CancelledException at their
// next suspend point, each scope joins its children, and the shielded block gets
// to write a last byte before the socket goes.

use function Async\async;
use function Async\group;
use function Async\shield;
use function Async\shutdownOn;
use function Async\spawn;
use function Process\supervise;
use Async\CancelledException;
use Async\TaskGroup;

const ADDR = 'tcp://127.0.0.1:8081';

final class Stats
{
    public int $served = 0;
    public int $open = 0;
}

function serveConnection(\Resource $conn, Stats $stats): void
{
    $stats->open = $stats->open + 1;
    try {
        while (true) {
            $req = \fread($conn, 8192);
            if ($req === '') {
                return;                       // peer closed
            }
            $body = "worker " . (string)\Process\pid() . " served " . (string)($stats->served + 1) . "\n";
            \fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Length: " . (string)\strlen($body)
                . "\r\nConnection: keep-alive\r\n\r\n" . $body);
            $stats->served = $stats->served + 1;
        }
    } catch (CancelledException $e) {
        // Shutting down mid-connection: tell the client rather than dropping it.
        // The write suspends, so it only gets to happen inside a shield.
        shield(function () use ($conn) {
            \fwrite($conn, "HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\n"
                . "Connection: close\r\n\r\n");
        });
        throw $e;
    } finally {
        $stats->open = $stats->open - 1;
        \Async\close($conn);
    }
}

function worker(int $index): void
{
    async(function () use ($index) {
        shutdownOn(SIGTERM, SIGINT);

        $errno = 0;
        $errstr = '';
        $server = \stream_socket_server(ADDR, $errno, $errstr);
        if ($server === false) {
            \fwrite(\STDERR, "worker " . (string)$index . ": bind failed: " . $errstr . "\n");
            return;
        }
        \stream_set_blocking($server, false);
        $stats = new Stats();

        // Every connection is a child of THIS scope, so the scope cannot close
        // while one is in flight — and cancelling it cancels all of them.
        try {
            group(function (TaskGroup $g) use ($server, $stats) {
                while (true) {
                    $conn = \stream_socket_accept($server);
                    if ($conn === false) {
                        continue;
                    }
                    \stream_set_blocking($conn, false);
                    $g->spawn(fn() => serveConnection($conn, $stats));
                }
            });
        } catch (CancelledException $e) {
            // Expected: this is what SIGTERM does.
        }
        \fclose($server);
        \fwrite(\STDOUT, "worker " . (string)$index . " (pid " . (string)\Process\pid()
            . ") drained: served " . (string)$stats->served . ", " . (string)$stats->open . " still open\n");
    });
}

// The supervisor runs NO scheduler: it forks, reaps, restarts a worker that
// crashes, and forwards SIGTERM to the whole group. Forking happens before any
// reactor exists, which is the only safe order.
supervise(4, function (int $i) { worker($i); });
echo "supervisor: all workers exited\n";
