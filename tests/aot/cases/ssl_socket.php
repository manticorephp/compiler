<?php

// Offline, deterministic, difftest-parity: ssl:// and tls:// are WIRED transports
// for stream_socket_client / fsockopen (not "unsupported transport"), and a
// closed port yields false. A real TLS handshake over a raw socket (GET /,
// self-signed rejected, tls:// == ssl://) was confirmed byte-identical to php
// against example.com and self-signed.badssl.com — the offline suite can't stand
// up a TLS peer (needs server-side SSL_accept).
$e = 0; $m = '';
var_dump(@stream_socket_client('ssl://127.0.0.1:1', $e, $m));  // closed -> false
var_dump(strpos($m, 'unsupported') === false);                 // ssl IS supported
$e2 = 0; $m2 = '';
var_dump(@fsockopen('tls://127.0.0.1', 1, $e2, $m2));          // closed -> false
$e3 = 0; $m3 = '';
var_dump(@stream_socket_client('tcp://127.0.0.1:1', $e3, $m3)); // closed -> false
echo "done\n";
