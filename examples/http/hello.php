<?php

// The smallest real server: a handler is callable(Request): Response.
//
//   bin/manticore compile examples/http/hello.php -o hello && ./hello
//   curl -v localhost:8080/          curl localhost:8080/hello/ada?loud=1
//
// Prefork across cores with ->workers(N); each worker accepts on the same
// listener, so the kernel balances them.

use Http\Request;
use Http\Response;
use Http\Server;

(new Server('tcp://127.0.0.1:8080'))
    ->workers(0)             // >0 forks that many workers before any reactor exists
    ->maxConnections(512)    // per worker; the permit is taken BEFORE accept
    ->serve(function (Request $req): Response {
        if ($req->path === '/') {
            return (new Response())->html("<h1>hello</h1>\n");
        }
        if (str_starts_with($req->path, '/hello/')) {
            $name = substr($req->path, 7);
            $body = 'hello, ' . $name;
            if ($req->query('loud') !== '') {
                $body = strtoupper($body);
            }
            return (new Response())->text($body . "\n");
        }
        if ($req->path === '/echo' && $req->is(Http\Method::Post)) {
            return (new Response())
                ->type($req->contentType() === '' ? 'text/plain' : $req->contentType())
                ->body($req->body());
        }
        if ($req->path === '/json') {
            // json_encode is a codegen builtin, so it stays at the call site —
            // Response has no json() on purpose (docs/http.md).
            return (new Response())->type('application/json')
                ->body(json_encode(['ok' => true, 'path' => $req->path]));
        }
        return (new Response(404))->text("not found\n");
    });
