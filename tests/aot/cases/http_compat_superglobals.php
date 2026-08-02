<?php

// MANTICORE-ONLY (no Http\Server in php). compat(true): $_GET, $_POST,
// $_COOKIE and $_SERVER are seeded per request, and TWO CLIENTS RUN AT ONCE
// with different values — the handler suspends in the middle, so the two
// requests are genuinely interleaved on one process.
//
// This is the case that can catch both concurrency bugs at once: a request
// context leaking between tasks (the superglobals), and an `echo` leaking
// between tasks (the output-buffer stack is a PROCESS global, so without
// __mc_ob_ctx_switch fiber A's echo lands in fiber B's buffer).
//
// Output is ordered by collecting both answers and printing them by NAME, so
// the completion order — which is a scheduler detail — cannot make it flake.
// The expected output is written BY HAND.

use function Async\async;
use function Async\spawn;

$port = 0;
$listener = false;
for ($p = 49700; $p < 49780; $p = $p + 1) {
    $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
    if ($s !== false) {
        $listener = $s;
        $port = $p;
        break;
    }
}
if ($listener === false) {
    echo "no free port\n";
    return;
}
stream_set_blocking($listener, false);

function readBody(\Resource $c): string
{
    $buf = '';
    while (true) {
        $end = strpos($buf, "\r\n\r\n");
        if ($end !== false) {
            $len = 0;
            foreach (explode("\r\n", substr($buf, 0, $end)) as $line) {
                if (stripos($line, 'content-length:') === 0) {
                    $len = (int)trim(substr($line, 15));
                }
            }
            if (strlen($buf) >= $end + 4 + $len) {
                return substr($buf, $end + 4, $len);
            }
        }
        $part = fread($c, 4096);
        if ($part === '') {
            return $buf;
        }
        $buf .= $part;
    }
}

/** One request on its own connection; answers the response body. */
function call(int $port, string $raw): string
{
    $c = fsockopen('127.0.0.1', $port);
    if ($c === false) {
        return 'CONNECT-FAILED';
    }
    fwrite($c, $raw);
    $b = readBody($c);
    fclose($c);
    return $b;
}

$server = \Http\Server::onListener($listener)
    ->compat(true)
    ->serverName('')
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/server') {
                return (new \Http\Response())->text(
                    'method=' . $_SERVER['REQUEST_METHOD']
                    . ' uri=' . $_SERVER['REQUEST_URI']
                    . ' proto=' . $_SERVER['SERVER_PROTOCOL']
                    . ' qs=' . $_SERVER['QUERY_STRING']
                    . ' host=' . $_SERVER['SERVER_NAME']
                    . ' https=' . ($_SERVER['HTTPS'] === '' ? 'off' : $_SERVER['HTTPS'])
                    . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '-'),
                );
            }
            if ($req->path === '/post') {
                return (new \Http\Response())->text(
                    'post=' . ($_POST['k'] ?? '-')
                    . ' cookie=' . ($_COOKIE['c'] ?? '-')
                    . ' request=' . ($_REQUEST['k'] ?? '-'),
                );
            }
            // /who — the interleaving probe. It echoes ITS OWN name, suspends
            // long enough for the other request to run inside the gap, and only
            // then returns; if the buffers were shared the two names would mix.
            $who = $_GET['who'] ?? '?';
            echo 'who=', $who;
            \Async\delay(0.06);
            echo ' get=', $_GET['who'] ?? '?';
            echo ' n=', count($_GET);
            return new \Http\Response();
        });
    });

    \Async\delay(0.05);

    echo call($port, "GET /server?a=1 HTTP/1.1\r\nHost: example.test:8080\r\n"
        . "User-Agent: probe/1\r\n\r\n"), "\n";

    $body = 'k=v1';
    echo call($port, "POST /post HTTP/1.1\r\nHost: t\r\nCookie: c=ck1\r\n"
        . "Content-Type: application/x-www-form-urlencoded\r\n"
        . 'Content-Length: ' . strlen($body) . "\r\n\r\n" . $body), "\n";

    // The two overlapping requests.
    $a = spawn(function () use ($port) {
        return call($port, "GET /who?who=alpha&x=1 HTTP/1.1\r\nHost: t\r\n\r\n");
    });
    $b = spawn(function () use ($port) {
        return call($port, "GET /who?who=beta HTTP/1.1\r\nHost: t\r\n\r\n");
    });
    $ra = $a->await();
    $rb = $b->await();
    echo 'A: ', $ra, "\n";
    echo 'B: ', $rb, "\n";

    $server->stop();
    echo 'served=', $server->stats()['served'], "\n";
});

echo "done\n";
