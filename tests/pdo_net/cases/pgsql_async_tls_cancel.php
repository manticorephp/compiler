<?php
// Manticore-only (Async\ is a superset); expectation in the sibling .expected. A fiber cancelled while parked INSIDE the TLS
// handshake (the peer answered 'S', swallowed the ClientHello and went silent) is released promptly, the cancellation is not
// swallowed, its socket is closed (the peer sees EOF), no fd stays open, and the next TLS connection works.
use function Async\async;
use function Async\spawn;
use function Async\delay;
$fds = fn () => count(scandir('/dev/fd'));
async(function () use ($fds) {
    $en = 0; $es = '';
    $srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $port = parse_url('tcp://' . stream_socket_get_name($srv, false), PHP_URL_PORT);
    // warm the runtime's poller fd and a first real TLS connection (loads libssl state) before counting
    $dsn = (string) getenv('PDO_PG_SSL_DSN');
    $warm = new PDO($dsn . ';sslmode=require', 'u', 'pw');
    $w = $warm->query('select 1'); $w->fetchColumn(); $w = null; $warm = null;
    $before = $fds();
    $peer = spawn(function () use ($srv) {
        $c = stream_socket_accept($srv, 5);
        fread($c, 8);                    // SSLRequest
        fwrite($c, 'S');
        $hello = fread($c, 4096);        // the ClientHello, never answered
        echo "peer: got hello ", strlen($hello) > 0 ? 'yes' : 'no', "\n";
        $rest = fread($c, 4096);         // blocks until the client goes away
        echo "peer: client socket closed ", ($rest === '' || $rest === false) ? 'yes' : 'no', "\n";
        fclose($c);
    });
    $t = spawn(function () use ($port) {
        try {
            new PDO("pgsql:host=127.0.0.1;port=$port;dbname=d;sslmode=require", 'u', 'pw');
            echo "client: connected\n";
        } catch (\Throwable $e) {
            echo "client: ", $e instanceof PDOException ? 'PDOException' : 'cancelled', "\n";
            throw $e;
        }
    });
    delay(0.4);
    $t0 = microtime(true);
    $t->cancel();
    try { $t->join(); } catch (\Throwable $e) { }
    echo "released in time: ", microtime(true) - $t0 < 1.0 ? 'yes' : 'NO', "\n";
    $peer->join();
    fclose($srv);
    $db = new PDO($dsn . ';sslmode=require', 'u', 'pw', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $q = $db->query('select ssl from pg_stat_ssl where pid = pg_backend_pid()');
    echo "next connection: ", var_export($q->fetchColumn(), true), "\n";
    $q = null; $db = null;
    echo $fds() === $before - 1 ? "no fd leak\n" : "FD LEAK\n";   // the listener was counted in $before and is closed
});
