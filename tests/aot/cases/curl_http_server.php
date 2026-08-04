<?php

// MANTICORE-ONLY: our own HTTP client talking to our own HTTP server.
//
// php has no Http\Server, so it dies before printing anything and difftest
// PHP-SKIPs the file — which is why the `\Http\` reference below sits ABOVE the
// first echo. tools/difftest.sh classifies a case as PHP-SKIP only when php
// produced NO stdout, and a prefix printed before the fatal turns it into a
// bogus DIFF. The expected output is written BY HAND.
//
// ⚠ TWO PROCESSES, and it has to be two: curl_easy_perform() blocks the calling
// thread, so a Server sharing this process would never reach accept(). The
// listener is bound HERE and handed to the child through the fork, so
// Server::onListener serves an already-bound socket and there is no "did it come
// up in time" race — the backlog holds our connect either way.

use Http\Request;
use Http\Response;
use Http\Server;

// ⚠ THE MANTICORE-ONLY REFERENCE HAS TO BE HERE, before the port scan and the
// fork — not merely before the first echo.
//
// It used to sit in the forked CHILD, at Server::onListener. Under php the child
// died there and the PARENT sailed on, curling a port nobody was listening on
// and printing the results — so php produced stdout, and difftest graded the
// case as a real DIFF instead of PHP-SKIP. A `use` statement is not enough
// either: it binds a name and touches no class.
$probe = \Http\Method::Get;

$port = 0;
$listener = false;
for ($p = 49900; $p < 49980; $p = $p + 1) {
    $s = @\stream_socket_server('tcp://127.0.0.1:' . $p);
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

// The reactor accepts on a NON-BLOCKING listener; handing it a blocking one
// parks the whole scheduler in accept().
\stream_set_blocking($listener, false);

$pid = \pcntl_fork();
if ($pid === 0) {
    // Child: nothing here may write to stdout — the parent owns it.
    Server::onListener($listener)
        ->workers(0)
        ->serve(function (Request $req): Response {
            if ($req->path === '/hello') {
                return (new Response())->text("hello from Http\\Server\n");
            }
            if ($req->path === '/echo') {
                return (new Response())
                    ->type($req->contentType() === '' ? 'text/plain' : $req->contentType())
                    ->body($req->body());
            }
            if ($req->path === '/head') {
                return (new Response())->header('X-Answer', '42')->text("with header\n");
            }
            return (new Response(404))->text("not found\n");
        });
    exit(0);
}
\fclose($listener);

$base = 'http://127.0.0.1:' . $port;

$ch = \curl_init($base . '/hello');
\curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$hello = \curl_exec($ch);
$code = \curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

$ch2 = \curl_init($base . '/echo');
\curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch2, CURLOPT_POST, true);
\curl_setopt($ch2, CURLOPT_POSTFIELDS, 'round=trip');
\curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: text/plain']);
$echo = \curl_exec($ch2);
$code2 = \curl_getinfo($ch2, CURLINFO_RESPONSE_CODE);

/** @var string[] $hdrs */
$hdrs = [];
$ch3 = \curl_init($base . '/head');
\curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
\curl_setopt($ch3, CURLOPT_HEADERFUNCTION, function ($h, $line) use (&$hdrs) {
    $t = \rtrim($line, "\r\n");
    if ($t !== '') { $hdrs[] = $t; }
    return \strlen($line);
});
$head = \curl_exec($ch3);
$sawAnswer = false;
foreach ($hdrs as $line) {
    if ($line === 'X-Answer: 42') { $sawAnswer = true; }
}

$ch4 = \curl_init($base . '/nope');
\curl_setopt($ch4, CURLOPT_RETURNTRANSFER, true);
$missing = \curl_exec($ch4);
$code4 = \curl_getinfo($ch4, CURLINFO_RESPONSE_CODE);

// SIGTERM: the server loop has no natural end.
\posix_kill($pid, 15);
$status = 0;
\pcntl_waitpid($pid, $status);

echo "hello:   ", $code, " ", $hello;
echo "echo:    ", $code2, " ", $echo, "\n";
echo "head:    ", $head;
echo "header:  ", $sawAnswer ? 'X-Answer: 42' : 'MISSING', "\n";
echo "status:  ", \substr($hdrs[0], 0, 12), "\n";
echo "404:     ", $code4, " ", $missing;
