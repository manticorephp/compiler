<?php

// TCP client + server against each other, in ONE process on the loopback.
//
// This shape exists because a client alone is UNTESTABLE offline: a real host
// makes the suite need the network and flake, and 127.0.0.1 has nobody
// listening. Binding a listener in the same process is the only way to make the
// transport deterministic and offline — which is why bind/listen/accept landed
// with the client rather than after it.
//
// The port is SCANNED rather than read back with stream_socket_get_name(),
// deliberately: get_name returns "host:port", so implementing it would mean
// parsing a sockaddr — the exact thing the design avoids by handing
// getaddrinfo's blob straight to connect()/bind(). The port number is never
// printed either; it is arbitrary in both runtimes.

$port = 0;
$server = false;
for ($p = 49200; $p < 49280; $p = $p + 1) {
    // @ matters: php warns to STDOUT on a failed bind, which would poison this
    // case's own expected output.
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $server = $s;
        $port = $p;
        break;
    }
}
var_dump($server !== false);
var_dump($port > 0);
var_dump(get_resource_type($server));
var_dump(is_resource($server));

$client = fsockopen('127.0.0.1', $port);
var_dump($client !== false);
var_dump(get_resource_type($client));

$conn = stream_socket_accept($server, 5);
var_dump($conn !== false);

// client -> server
fwrite($client, "ping\n");
echo fgets($conn);

// server -> client
fwrite($conn, "pong\n");
echo fgets($client);

// A short binary-ish read: fread must come back with exactly what was sent.
fwrite($conn, "0123456789");
var_dump(fread($client, 10));

// NOT asserted here, on purpose:
//   ftell($client)  — php returns an int (it counts bytes through its own
//                     buffer); we return false. A real divergence, recorded at
//                     the call site in Io.php, to be closed with the Ф3 buffer.
//   rewind($client) — php emits "Stream does not support seeking" as a WARNING,
//                     and php CLI writes warnings to STDOUT, so asserting it
//                     would poison this file's own expected output with an
//                     absolute path.

// The peer closing is the only way a socket reports EOF: recv returns 0, and
// there is no FILE* to remember it — the Resource has to.
fclose($client);
var_dump(fgets($conn));
var_dump(feof($conn));

fclose($conn);
fclose($server);
var_dump(is_resource($server));
echo "done\n";
