<?php

// Native async load client — opens CONNS concurrent keep-alive connections (one
// fiber each), each firing REQS requests, all on one process. Far lighter than a
// php client (compiled, no interpreter), so several instances can actually drive
// a multi-worker server. Prints its own req/s.

use function Async\run;
use Async\TaskGroup;

const CONNS = 50;
const REQS = 4000;

run(function () {
    $req = "GET /plaintext HTTP/1.1\r\nHost: x\r\n\r\n";
    $t0 = microtime(true);
    TaskGroup::run(function (TaskGroup $g) use ($req) {
        for ($i = 0; $i < CONNS; $i++) {
            $g->spawn(function () use ($req) {
                $conn = Async\connect("tcp://127.0.0.1:8080");
                for ($j = 0; $j < REQS; $j++) {
                    Async\write($conn, $req);
                    $resp = "";
                    while (\strlen($resp) < 134) {
                        $chunk = Async\read($conn, 8192);
                        if ($chunk === "") { break; }
                        $resp .= $chunk;
                    }
                }
                Async\close($conn);
            });
        }
    });
    $dt = microtime(true) - $t0;
    $total = CONNS * REQS;
    \printf("%d req/s  (%d conns × %d)\n", (int)($total / $dt), CONNS, REQS);
});
