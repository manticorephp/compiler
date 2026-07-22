<?php
// Block-IO parity: socketpair + select + set_blocking/chunk_size + context get/set.
// All offline (a connected socketpair, no listener race, no TLS handshake).
$r = null; $w = null; $e = null;   // by-ref select args pre-declared (MIR.verify)

$p = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
var_dump(is_array($p));
var_dump(count($p));

fwrite($p[0], "ping");
var_dump(fread($p[1], 4));

// select: after a write to [0], [1] is readable.
fwrite($p[0], "data");
$r = [$p[1]];
$n = stream_select($r, $w, $e, 1, 0);
var_dump($n);
var_dump(count($r));
var_dump(fread($p[1], 4));

// non-blocking: an empty read returns '' instead of waiting.
var_dump(stream_set_blocking($p[1], false));
var_dump(fread($p[1], 10));
var_dump(stream_set_chunk_size($p[1], 4096));

// context get/set (best-effort: only non-default honored keys round-trip).
$ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'local_cert' => '/tmp/x.pem']]);
$o = stream_context_get_options($ctx);
var_dump($o['ssl']['verify_peer']);
var_dump($o['ssl']['local_cert']);
stream_context_set_option($ctx, 'http', 'method', 'POST');
$o2 = stream_context_get_options($ctx);
var_dump($o2['http']['method']);
echo "done\n";
