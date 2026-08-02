<?php

// MANTICORE-ONLY (no Http\Server in php): REQUEST-BOUND state for user code.
//
// The Server opens an Async\Context scope per request, so `Http\request()` is
// the ambient Request for code too deep to be handed one, and anything a
// handler binds with Context::withValue() is visible to the tasks it spawns —
// and to nothing outside that request. Two requests run at once here with
// different bindings; neither may see the other's.
// The expected output is written BY HAND.

use function Async\async;
use function Async\spawn;
use Async\Context;

$port = 0;
$listener = false;
for ($p = 49780; $p < 49860; $p = $p + 1) {
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

function callBody(int $port, string $raw): string
{
    $c = fsockopen('127.0.0.1', $port);
    if ($c === false) {
        return 'CONNECT-FAILED';
    }
    fwrite($c, $raw);
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
                fclose($c);
                return substr($buf, $end + 4, $len);
            }
        }
        $part = fread($c, 4096);
        if ($part === '') {
            fclose($c);
            return $buf;
        }
        $buf .= $part;
    }
}

$server = \Http\Server::onListener($listener)->serverName('')->acceptWait(0.02);

async(function () use ($server, $port) {
    // Outside any request there is no ambient Request.
    echo 'outside: ', \Http\request() === null ? 'null' : 'SET', "\n";

    spawn(function () use ($server) {
        $server->serve(function (\Http\Request $req): \Http\Response {
            if ($req->path === '/deep') {
                // Nothing is handed the Request here — it is read ambiently.
                return (new \Http\Response())->text(deepPath() . ' q=' . deepQuery());
            }
            // /tag: bind a value, suspend so the other request interleaves,
            // then read it back from a CHILD task.
            $tag = $req->query('tag', '?');
            return Context::withValue('app.tag', $tag, function () use ($req) {
                \Async\delay(0.06);
                $t = spawn(function () {
                    $r = \Http\request();
                    return (string)Context::value('app.tag')
                        . '/' . ($r === null ? 'NOREQ' : $r->query('tag', '?'));
                });
                $seen = $t->await();
                return (new \Http\Response())->text('child=' . $seen
                    . ' here=' . (string)Context::value('app.tag')
                    . ' other=' . (Context::value('app.missing') === null ? 'null' : 'SET'));
            });
        });
    });

    \Async\delay(0.05);

    echo 'deep: ', callBody($port, "GET /deep?q=7 HTTP/1.1\r\nHost: t\r\n\r\n"), "\n";

    $a = spawn(fn() => callBody($port, "GET /tag?tag=alpha HTTP/1.1\r\nHost: t\r\n\r\n"));
    $b = spawn(fn() => callBody($port, "GET /tag?tag=beta HTTP/1.1\r\nHost: t\r\n\r\n"));
    $ra = $a->await();
    $rb = $b->await();
    echo 'A: ', $ra, "\n";
    echo 'B: ', $rb, "\n";

    // …and once the requests are done the ambient Request is gone again.
    echo 'after: ', \Http\request() === null ? 'null' : 'SET', "\n";

    $server->stop();
});

/** Deep code that was never handed the Request. */
function deepPath(): string
{
    $r = \Http\request();
    return $r === null ? 'NOREQ' : $r->path;
}

function deepQuery(): string
{
    $r = \Http\request();
    return $r === null ? '-' : $r->query('q', '-');
}

echo "done\n";
