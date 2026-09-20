<?php

// MANTICORE-ONLY: php has no Http\Server. Expected output written by hand.
// The port is scanned and never printed; the client's own port is not stable
// either, so both are asserted as a SHAPE, never as a value.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49460; $p < 49540; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) { $listener = $s; $port = $p; break; }
}
if ($listener === false) { echo "no free port\n"; return; }
stream_set_blocking($listener, false);

function readOne(\Resource $c): string
{
    $buf = '';
    while (true) {
        $end = strpos($buf, "\r\n\r\n");
        if ($end !== false) {
            $len = 0;
            foreach (explode("\r\n", substr($buf, 0, $end)) as $line) {
                if (stripos($line, 'content-length:') === 0) { $len = (int)trim(substr($line, 15)); }
            }
            if (strlen($buf) >= $end + 4 + $len) { return substr($buf, $end + 4, $len); }
        }
        $chunk = fread($c, 4096);
        if ($chunk === '') { return ''; }
        $buf .= $chunk;
    }
}

/** 'ip:port' → 'ip:<port-shape>' so the line is stable. */
function shape(string $hp): string
{
    $colon = strrpos($hp, ':');
    if ($colon === false) { return $hp . ':NOPORT'; }
    $port = substr($hp, $colon + 1);
    return substr($hp, 0, $colon) . ':' . (ctype_digit($port) && (int)$port > 0 ? 'PORT' : 'BAD(' . $port . ')');
}

$server = \Http\Server::onListener($listener)->serverName('mc-test')->acceptWait(0.02)->compat(true);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            $port = $_SERVER['REMOTE_PORT'] ?? 'MISSING';
            return (new \Http\Response())->text(
                'req=' . shape($req->remoteAddr)
                . ' addr=' . ($_SERVER['REMOTE_ADDR'] ?? 'MISSING')
                . ' port=' . (ctype_digit((string)$port) && (int)$port > 0 ? 'PORT' : 'BAD(' . $port . ')')
            );
        });
    });
    \Async\delay(0.05);
    $c = fsockopen('127.0.0.1', $port);
    fwrite($c, "GET / HTTP/1.1\r\nHost: t\r\n\r\n");
    echo readOne($c), "\n";
    fclose($c);
    $server->stop();
});
echo "done\n";
