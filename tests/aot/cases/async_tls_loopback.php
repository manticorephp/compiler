<?php

// TLS over the netpoller, OFFLINE. This is the case whose absence let an FFI bug
// live: `SSL_read`'s C `int` return was not sign-extended, so its -1 (WANT_READ)
// arrived as 4294967295, and since that return is a BYTE COUNT the stream layer
// memmove'd ~4 GB. It only fired under a scheduler (a blocking fd never makes
// SSL_read return -1) and only against a real TLS peer — which the offline suite had
// no way to stand up.
//
// Two tasks in ONE process do it: the server task accepts and handshakes
// (SSL_accept), the client task connects and handshakes (SSL_connect), and the
// scheduler interleaves them. In SYNC mode the same shape deadlocks — that is why
// enable_crypto's case stops before the handshake and this one does not.
//
// The cert is a self-signed fixture for 127.0.0.1 (tests/aot/fixtures), so the
// client turns verification off; what is under test is the record layer, not PKI.
// Nothing prints before the first async() — difftest classifies this file as
// manticore-only by "php produced no stdout" (php has no Io\Poll).

use function Async\async;
use function Async\spawn;

$cert = __DIR__ . '/../fixtures/tls_localhost.pem';

$ctx = stream_context_create([
    'ssl' => ['local_cert' => $cert, 'verify_peer' => false, 'verify_peer_name' => false],
]);

$errno = 0;
$errstr = '';
$port = 0;
$server = false;
for ($p = 51500; $p < 51580; $p = $p + 1) {
    $s = @stream_socket_server('tls://127.0.0.1:' . $p, $errno, $errstr, 12, $ctx);
    if ($s !== false) { $server = $s; $port = $p; break; }
}

// A body big enough to cross several TLS records, so a truncation at a record
// boundary (the other half of the old bug) shows up as a short read.
$body = str_repeat('0123456789abcdef', 2048);   // 32 KiB

$out = async(function () use ($server, $port, $body, $cert): string {
    $srv = spawn(function () use ($server, $body): string {
        $conn = stream_socket_accept($server, 5.0);
        if ($conn === false) { return 'no-conn'; }
        $req = fread($conn, 32);
        fwrite($conn, $body);
        fclose($conn);
        return 'got=' . (string)$req;
    })->named('tls-server');

    $cli = spawn(function () use ($port, $cert, $body): string {
        $cctx = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $e = 0; $m = '';
        $c = stream_socket_client('tls://127.0.0.1:' . (string)$port, $e, $m, 5.0, 4, $cctx);
        if ($c === false) { return 'no-connect(' . (string)$m . ')'; }
        fwrite($c, 'ping-over-tls');
        $seen = '';
        while (strlen($seen) < strlen($body)) {
            $chunk = fread($c, 8192);
            if ($chunk === '' || $chunk === false) { break; }
            $seen = $seen . $chunk;
        }
        fclose($c);
        return 'read=' . (string)strlen($seen) . ' exact=' . ($seen === $body ? 'yes' : 'no');
    })->named('tls-client');

    // A third task proves the handshake and the 32 KiB transfer did not stall the
    // loop: it must complete all its ticks alongside them.
    $tick = spawn(function (): int {
        $n = 0;
        for ($i = 0; $i < 6; $i = $i + 1) { \Async\delay(0.01); $n = $n + 1; }
        return $n;
    })->named('ticker');

    $s = $srv->await();
    $c = $cli->await();
    $t = $tick->await();
    return $s . ' ' . $c . ' ticks=' . (string)$t;
});

echo 'listener: ', ($server !== false ? 'yes' : 'no'), "\n";
echo $out, "\n";
fclose($server);
echo "done\n";
