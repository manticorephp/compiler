<?php
// enable_crypto guards + method constants. The real STARTTLS handshake needs a
// live TLS peer (single-process would deadlock), validated manually vs openssl
// s_server (plain connect -> enable_crypto -> HTTP GET over TLS -> "200 ok").
$m = fopen('php://memory', 'r+');
var_dump(@stream_socket_enable_crypto($m, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)); // not a socket
var_dump(STREAM_CRYPTO_METHOD_TLS_CLIENT & 1);   // 1 = client
var_dump(STREAM_CRYPTO_METHOD_TLS_SERVER & 1);   // 0 = server
var_dump(STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT & 1);
echo "done\n";
