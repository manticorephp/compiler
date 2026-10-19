<?php
// A fiber cancelled while parked in stream_socket_enable_crypto() leaks the SSL object.
// issue: #328
use function Async\async;
use function Async\spawn;
use function Async\delay;
function cancelled_handshake(int $port): void {
    $t = spawn(function () use ($port) {
        $s = stream_socket_client("tcp://127.0.0.1:$port");
        stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    });
    delay(0.002);
    $t->cancel();
    try { $t->join(); } catch (\Throwable $e) { }
}
async(function () {
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $port = parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT);
    // a silent peer: accepts, reads the ClientHello, never answers
    $acc = spawn(function () use ($srv) {
        $keep = [];
        while (($c = stream_socket_accept($srv, 60)) !== false) {
            $keep[] = $c;
            if (count($keep) > 8) { fclose(array_shift($keep)); }
        }
    });
    for ($i = 0; $i < 30; $i++) { cancelled_handshake($port); }
    $b = memory_get_peak_usage();
    for ($i = 0; $i < 400; $i++) { cancelled_handshake($port); }
    echo (memory_get_peak_usage() - $b) < 4000000 ? "flat" : "leak", "\n";
    $acc->cancel();
});
