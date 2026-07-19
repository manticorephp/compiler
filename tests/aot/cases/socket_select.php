<?php

// socket_select over a loopback pair. Exercises the array& read+rewrite path:
// the $read array is rewritten in place to the ready sockets, and the return is
// the count of ready sockets.

$srv = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($srv, SOL_SOCKET, SO_REUSEADDR, 1);
$port = 0;
for ($p = 49400; $p < 49480; $p = $p + 1) {
    if (@socket_bind($srv, '127.0.0.1', $p)) {
        $port = $p;
        break;
    }
}
socket_listen($srv, 5);

$cli = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_connect($cli, '127.0.0.1', $port);
$conn = socket_accept($srv);

// Nothing sent yet: the read set is empty after select (with a 0s poll).
$r = [$conn];
$w = [];
$e = [];
$n = socket_select($r, $w, $e, 0);
var_dump($n);
var_dump(count($r));

// Now the client writes: $conn becomes readable.
socket_write($cli, "hi");
$r = [$conn];
$w = [];
$e = [];
$n = socket_select($r, $w, $e, 2);
var_dump($n);
var_dump(count($r));
var_dump($r[0] instanceof Socket);

socket_close($cli);
socket_close($conn);
socket_close($srv);
