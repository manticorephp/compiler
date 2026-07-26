<?php

// ext/sockets under the scheduler. The socket_* family used to block the LOOP —
// AsyncHook was consulted nowhere in Sockets.php — so a program mixing ext/sockets
// with async got a scheduler in name only: one socket_read stalled every task.
//
// Here a server task and a client task talk over socket_* in ONE process while a
// third task keeps ticking. If any call blocked the loop, the ticker could not finish
// and (for accept, which runs before the client exists) the case would deadlock.
//
// Nothing prints before the first async() — difftest treats a file as manticore-only
// by "php produced no stdout", and php cannot run this (no Io\Poll).

use function Async\async;
use function Async\spawn;

$srvSock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($srvSock, SOL_SOCKET, SO_REUSEADDR, 1);
$port = 0;
for ($p = 51900; $p < 51980; $p = $p + 1) {
    if (@socket_bind($srvSock, '127.0.0.1', $p)) { $port = $p; break; }
}
socket_listen($srvSock, 8);

$payload = str_repeat('sockets-over-async ', 512);   // ~9.7 KiB, several MTUs

$out = async(function () use ($srvSock, $port, $payload): string {
    // accept() parks on readability instead of blocking: the client below cannot
    // even start until this task yields.
    $srv = spawn(function () use ($srvSock, $payload): string {
        $conn = socket_accept($srvSock);
        if ($conn === false) { return 'no-conn'; }
        $req = socket_read($conn, 64);
        $sent = socket_write($conn, $payload);
        socket_close($conn);
        return 'req=' . (string)$req . ' sent=' . (string)$sent;
    })->named('sock-server');

    $cli = spawn(function () use ($port, $payload): string {
        $c = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!@socket_connect($c, '127.0.0.1', $port)) { return 'no-connect'; }
        socket_write($c, "hello-sockets\n");
        $seen = '';
        while (strlen($seen) < strlen($payload)) {
            $chunk = socket_read($c, 4096);
            if ($chunk === '' || $chunk === false) { break; }
            $seen = $seen . $chunk;
        }
        socket_close($c);
        return 'read=' . (string)strlen($seen) . ' exact=' . ($seen === $payload ? 'yes' : 'no');
    })->named('sock-client');

    $tick = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 6; $i = $i + 1) { \Async\delay(0.01); $n = $n + 1; }
        return $n;
    })->named('ticker');

    $s = $srv->await();
    $c = $cli->await();
    return $s . ' ' . $c . ' ticks=' . (string)$tick->await();
});

echo 'bound: ', ($port > 0 ? 'yes' : 'no'), "\n";
echo $out, "\n";

// socket_recv (the buffer-out-param form) parks the same way.
$recvd = async(function () use ($srvSock, $port): string {
    $srv = spawn(function () use ($srvSock): string {
        $conn = socket_accept($srvSock);
        if ($conn === false) { return 'no-conn'; }
        socket_send($conn, 'recv-path', 9, 0);
        socket_close($conn);
        return 'served';
    });
    $cli = spawn(function () use ($port): string {
        $c = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!@socket_connect($c, '127.0.0.1', $port)) { return 'no-connect'; }
        $buf = '';
        $n = socket_recv($c, $buf, 32, 0);
        socket_close($c);
        return 'n=' . (string)$n . ' buf=' . (string)$buf;
    });
    return $srv->await() . ' ' . $cli->await();
});
echo $recvd, "\n";

// Outside a scheduler the family is unchanged: still blocking, still correct.
socket_close($srvSock);
echo "done\n";
