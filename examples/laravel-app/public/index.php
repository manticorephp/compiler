<?php

use Http\Request;
use Http\Response;
use Http\Server;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request as LaravelRequest;

require_once dirname(__DIR__).'/vendor/autoload.php';

$app = require_once dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
// Boot once, before the first connection: two requests racing through a lazy bootstrap saw a half-built container.
$kernel->bootstrap();

// Laravel keeps per-request state in objects every request shares (the matched Route, the container's
// request-scoped instances). Two requests interleaving at an I/O wait would trample it, as php-fpm never lets
// them: one request at a time through the kernel in a worker, and concurrency from WORKERS.
$gate = new Async\Mutex();

(new Server('tcp://127.0.0.1:' . (getenv('PORT') ?: '8181')))
    ->workers((int)(getenv('WORKERS') ?: 1))
    ->maxConnections(512)
    ->compat(true)
    ->onError(function (\Throwable $e, Request $req): Response {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
        return (new Response(500))->text("Internal Server Error\n");
    })
    ->serve(function (Request $req) use ($kernel, $gate): Response {
        return $gate->withLock(function () use ($kernel): Response {
            $request = LaravelRequest::capture();
            $laravel = $kernel->handle($request);
            $kernel->terminate($request, $laravel);

            $response = new Response($laravel->getStatusCode(), $laravel->getContent());
            foreach ($laravel->headers->all() as $name => $values) {
                foreach ($values as $value) {
                    $response->header($name, $value);
                }
            }
            return $response;
        });
    });
