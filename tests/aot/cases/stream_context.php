<?php

// Offline, deterministic, difftest-parity: stream_context_create returns a php
// stream-context resource, and a context passed to file_get_contents on a closed
// port still yields false. The TLS verify_peer=false path (self-signed accepted)
// and POST-with-body were confirmed byte-identical to php against real endpoints
// (badssl.com, httpbin.org) — an offline TLS handshake test would need
// server-side TLS (SSL_accept), which is not built.
$ctx = stream_context_create([
    'http' => ['method' => 'POST', 'header' => "X-Test: 1\r\n", 'content' => 'a=1'],
    'ssl'  => ['verify_peer' => false],
]);
var_dump(is_resource($ctx));
var_dump(get_resource_type($ctx));
var_dump(@file_get_contents('http://127.0.0.1:1/', false, $ctx));   // closed port -> false
$empty = stream_context_create();
var_dump(is_resource($empty));
echo "done\n";
