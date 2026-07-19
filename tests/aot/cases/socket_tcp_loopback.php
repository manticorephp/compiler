<?php

// TCP client + server against each other, in ONE process on the loopback —
// the only way a socket_* client is testable offline (see net_tcp_loopback for
// the same shape over the stream API). The port is scanned, never printed, so
// the output is deterministic across runtimes.

$srv = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($srv, SOL_SOCKET, SO_REUSEADDR, 1);

$port = 0;
for ($p = 49300; $p < 49380; $p = $p + 1) {
    if (@socket_bind($srv, '127.0.0.1', $p)) {
        $port = $p;
        break;
    }
}
var_dump($port > 0);
var_dump(socket_listen($srv, 5));

$cli = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
var_dump(socket_connect($cli, '127.0.0.1', $port));

$conn = socket_accept($srv);
var_dump($conn instanceof Socket);

// client -> server
socket_write($cli, "ping\n");
var_dump(socket_read($conn, 5));

// server -> client, via recv into a by-ref buffer
socket_write($conn, "0123456789");
$buf = '';
$n = socket_recv($cli, $buf, 10, 0);
var_dump($n);
var_dump($buf);

// getsockname / getpeername agree on the bound port
$sn = '';
$sp = 0;
var_dump(socket_getsockname($cli, $sn, $sp));
var_dump($sn === '127.0.0.1');
$pn = '';
$pp = 0;
socket_getpeername($cli, $pn, $pp);
var_dump($pp === $port);

socket_close($cli);
socket_close($conn);
socket_close($srv);
