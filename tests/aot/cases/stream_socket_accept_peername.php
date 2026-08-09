<?php

// stream_socket_accept()'s third parameter is the peer's "host:port", by
// REFERENCE. It was not declared at all, so a caller writing
// `stream_socket_accept($srv, -1, $peer)` left $peer dangling.
//
// accept(2) is still called with NULL/NULL — the name is read back off the
// accepted descriptor with getpeername, which is what stream_socket_get_name()
// already does, so the two must agree exactly. The port half is ephemeral and
// cannot be pinned, so only the host half is printed.

$port = 0;
$srv = false;
for ($p = 49420; $p < 49500; $p = $p + 1) {
    $try = @stream_socket_server('tcp://127.0.0.1:' . $p, $errno, $errstr);
    if ($try !== false) {
        $srv = $try;
        $port = $p;
        break;
    }
}
var_dump($port > 0);

$cli = stream_socket_client('tcp://127.0.0.1:' . $port, $cerr, $cstr, 5.0);
var_dump($cli !== false);

$conn = stream_socket_accept($srv, 5.0, $peer);
var_dump($conn !== false);

// the out-parameter is filled, and with exactly what getpeername reports
var_dump(is_string($peer));
var_dump($peer === stream_socket_get_name($conn, true));
var_dump(explode(':', $peer)[0]);

// the peer's port is the CLIENT's local port, not the listening one
var_dump((int)explode(':', $peer)[1] !== $port);

// data still crosses, i.e. the extra getpeername did not disturb the socket
fwrite($cli, "ping\n");
var_dump(fgets($conn));

fclose($conn);
fclose($cli);
fclose($srv);

// omitted entirely — the two-argument spelling every corpus caller uses
$port2 = 0;
$srv2 = false;
for ($p = 49500; $p < 49580; $p = $p + 1) {
    $try = @stream_socket_server('tcp://127.0.0.1:' . $p, $e2, $s2);
    if ($try !== false) {
        $srv2 = $try;
        $port2 = $p;
        break;
    }
}
$cli2 = stream_socket_client('tcp://127.0.0.1:' . $port2, $c2, $c2s, 5.0);
$conn2 = stream_socket_accept($srv2);
var_dump($conn2 !== false);
fclose($conn2);
fclose($cli2);
fclose($srv2);
