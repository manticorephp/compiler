<?php
// MANTICORE-ONLY (php has no Async\ scheduler). Pin for round-2 review item A:
// __mc_stream_send_retry's TLS branch used to compute ONE absolute deadline
// for the WHOLE retry sequence, instead of giving each park a fresh
// wtimeoutMs budget the way __mc_wait_write already does on the plain-socket
// side (and the way fwrite's own comment says the design is: "an absolute
// per-call budget would fail a big fwrite over a slow link where php
// succeeds"). A write to a peer that is never idle for a WHOLE write
// timeout, but takes MORE than one write timeout in total to fully drain,
// used to fail outright at the old one-shot deadline instead of landing.
//
// Buffers shrunk on both ends (SO_RCVBUF on the listener, inherited by the
// accepted fd; SO_SNDBUF on the client) — same technique as
// async_write_timeout.php — so backpressure is immediate and deterministic
// instead of riding on the host's default (large, auto-tuned) socket
// buffers.

use function Async\async;
use function Async\spawn;

$cert = __DIR__ . '/../fixtures/tls_localhost.pem';
$ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'verify_peer' => false]]);

$errno = 0;
$errstr = '';
$l = stream_socket_server('tls://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
stream_set_blocking($l, false);
$ls = socket_import_stream($l);
if ($ls !== false) {
    socket_set_option($ls, SOL_SOCKET, SO_RCVBUF, 4096);
}
$name = stream_socket_get_name($l, false);
$port = (int)substr($name, strrpos($name, ':') + 1);

async(function () use ($l, $port) {
    spawn(function () use ($l) {
        $c = stream_socket_accept($l, 5.0);
        stream_set_blocking($c, false);
        $total = 0;
        $want = 512 * 1024;
        // Every gap (0.1s) is well under the client's 0.3s write timeout, but
        // the small SO_RCVBUF means the whole drain needs many such gaps —
        // several multiples of 0.3s in total.
        while ($total < $want) {
            \Async\delay(0.1);
            $chunk = \fread($c, 8192);
            if ($chunk === '') {
                break;
            }
            $total = $total + \strlen($chunk);
        }
        echo 'server got=', $total, "\n";
        \fclose($c);
    });

    $cctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $cerrno = 0;
    $cerrstr = '';
    $c = stream_socket_client('tls://127.0.0.1:' . $port, $cerrno, $cerrstr, 5.0, STREAM_CLIENT_CONNECT, $cctx);
    stream_set_blocking($c, false);
    $cs = socket_import_stream($c);
    if ($cs !== false) {
        socket_set_option($cs, SOL_SOCKET, SO_SNDBUF, 4096);
    }
    stream_set_timeout($c, 0, 300000);   // 300ms: a FRESH budget per park, not a total

    $payload = str_repeat('x', 512 * 1024);
    $n = fwrite($c, $payload);
    echo 'client sent=', $n, "\n";

    \fclose($c);
    \fclose($l);
});
