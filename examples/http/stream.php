<?php

// A streamed response: the length is not known when the head goes out, so it
// is framed chunked and produced by a closure.
//
//   bin/manticore compile examples/http/stream.php -o stream && ./stream
//   curl -N localhost:8081/clock        # one line per second, live
//   curl -s localhost:8081/big | wc -c  # 8 MiB, never held in memory

use Http\ChunkedWriter;
use Http\Request;
use Http\Response;
use Http\Server;

$server = new Server('tcp://127.0.0.1:8081');

$server->serve(function (Request $req) use ($server): Response {
    if ($req->path === '/clock') {
        return (new Response())->type('text/plain')
            ->stream(function (ChunkedWriter $w): void {
                for ($i = 0; $i < 10; $i++) {
                    // flush() is what makes it live: without it the writer
                    // batches to its threshold and the client sees nothing.
                    $w->write(gmdate('H:i:s') . "\n");
                    $w->flush();
                    Async\delay(1.0);
                }
            });
    }
    if ($req->path === '/big') {
        return (new Response())->type('application/octet-stream')
            ->stream(function (ChunkedWriter $w): void {
                $chunk = str_repeat('x', 65536);
                for ($i = 0; $i < 128; $i++) {
                    $w->write($chunk);
                }
            });
    }
    if ($req->path === '/stop') {
        $server->stop();
        return (new Response())->text("stopping\n");
    }
    return (new Response(404))->text("try /clock or /big\n");
});
