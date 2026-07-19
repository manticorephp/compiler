<?php

// socket_import_stream / socket_export_stream bridge a stream \Resource and a
// \Socket around the same connection. A loopback in one process keeps it offline.

$srv = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
socket_set_option($srv, SOL_SOCKET, SO_REUSEADDR, 1);
$port = 0;
for ($p = 49700; $p < 49780; $p = $p + 1) {
    if (@socket_bind($srv, '127.0.0.1', $p)) {
        $port = $p;
        break;
    }
}
socket_listen($srv, 5);

// A stream client, imported into a \Socket.
$streamCli = fsockopen('127.0.0.1', $port);
$sockCli = socket_import_stream($streamCli);
var_dump($sockCli instanceof Socket);

$conn = socket_accept($srv);

// The server side exported from a \Socket into a stream \Resource.
$streamConn = socket_export_stream($conn);
var_dump(is_resource($streamConn));
var_dump(get_resource_type($streamConn));

// client(\Socket) -> server(stream)
socket_write($sockCli, "abc");
var_dump(fread($streamConn, 3));

// server(stream) -> client(\Socket)
fwrite($streamConn, "xyz");
$buf = '';
socket_recv($sockCli, $buf, 3, 0);
var_dump($buf);

socket_close($sockCli);
socket_close($conn);
socket_close($srv);
