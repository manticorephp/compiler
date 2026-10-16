<?php

// MANTICORE-ONLY (no Http\Server in php). A compat server makes the process a
// web SAPI from serve() on: PHP_SAPI and php_sapi_name() answer 'cli-server',
// as `php -S` does; before serve() they answer 'cli'. Expected written BY HAND.

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

echo 'before: ', PHP_SAPI, ' ', php_sapi_name(), "\n";

$server = \Http\Server::onListener($listener)
    ->compat(true)
    ->acceptWait(0.02);

async(function () use ($server, $port) {
    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            $web = !in_array(\PHP_SAPI, ['cli', 'phpdbg', 'embed'], true);
            return (new \Http\Response())->text(
                PHP_SAPI . ' ' . php_sapi_name() . ' web=' . ($web ? 'yes' : 'no'),
            );
        });
    });

    \Async\delay(0.05);
    echo 'handler: ', call($port, "GET / HTTP/1.1\r\nHost: t\r\n\r\n"), "\n";
    $server->stop();
});

echo 'after: ', PHP_SAPI, "\n";
