<?php

use App\Kernel;
use Http\Request;
use Http\Response;
use Http\Server;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

// `composer: true` in manticore.json compiles the vendor/ packages into the binary; nothing is loaded from disk at run time.
require_once dirname(__DIR__).'/vendor/autoload.php';

// A compiled binary does not run the Symfony Runtime component that normally loads .env, so load it here.
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Build and compile the service container once, before the server starts: outside any request fiber, and shared by every forked worker.
$appEnv = getenv('APP_ENV') ?: 'prod';
$kernel = new Kernel(environment: $appEnv, debug: $appEnv === 'dev');
$kernel->boot();

(new Server('tcp://127.0.0.1:' . (getenv('PORT') ?: '8181')))
    ->workers((int)(getenv('WORKERS') ?: 1))   // >0 binds once, then forks that many workers under a supervisor
    ->maxConnections(512)                      // per worker; the permit is taken BEFORE accept
    ->compat(true)
    ->onError(function (\Throwable $e, Request $req): Response {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
        return (new Response(500))->text("Internal Server Error\n");
    })
    ->serve(function (Request $req) use ($kernel): Response {
        $request = SymfonyRequest::createFromGlobals();
        $kernelResponse = $kernel->handle($request);
        $kernel->terminate($request, $kernelResponse);

        $response = new Response($kernelResponse->getStatusCode(), $kernelResponse->getContent());
        foreach ($kernelResponse->headers->all() as $name => $values) {
            foreach ($values as $value) {
                $response->header($name, $value);
            }
        }
        return $response;
    });
