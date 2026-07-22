<?php
// enable_crypto guards + method constants. The REAL STARTTLS handshake needs a
// live TLS peer (a single-process loopback would deadlock), validated manually vs
// openssl s_server/s_client (plain connect -> enable_crypto -> HTTP GET over TLS).
// Everything here returns BEFORE any handshake, so it stays offline + deadlock-free.
$m = fopen('php://memory', 'r+');
var_dump(@stream_socket_enable_crypto($m, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)); // not a socket

// A real connected plain socket via loopback (connect completes into the backlog,
// no accept progress needed) — exercise the fast-fail guards on it.
// By-ref out-params must be pre-declared at top-level (MIR.verify dangling-local).
$e = 0; $msg = ''; $e2 = 0; $msg2 = '';
$srv = stream_socket_server('tcp://127.0.0.1:0', $e, $msg);
$name = stream_socket_get_name($srv, false);
$cli = stream_socket_client('tcp://' . $name, $e2, $msg2);
$conn = stream_socket_accept($srv, 1.0);
var_dump(@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER)); // server, no cert ctx
var_dump(@stream_socket_enable_crypto($conn, false));                                 // teardown on plain

var_dump(STREAM_CRYPTO_METHOD_TLS_CLIENT & 1);   // 1 = client
var_dump(STREAM_CRYPTO_METHOD_TLS_SERVER & 1);   // 0 = server
var_dump(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT & 1);
echo "done\n";
