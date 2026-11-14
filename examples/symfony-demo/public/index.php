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

// The kernel and its services are built for one request at a time (php-fpm's model). A request that
// enters while another waits on I/O finds half-built services (`twig -> twig` circular reference) and
// shared cache temp files: one request at a time through the kernel in a worker, concurrency from WORKERS.
$gate = new Async\Mutex();

(new Server('tcp://127.0.0.1:' . (getenv('PORT') ?: '8181')))
    ->workers(getenv('WORKERS') === false ? 1 : (int)getenv('WORKERS'))   // 0 serves in this process; N binds once, then forks N workers under a supervisor
    ->maxConnections(512)                      // per worker; the permit is taken BEFORE accept
    ->compat(true)
    ->onError(function (\Throwable $e, Request $req): Response {
        fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString() . "\n");
        for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
            fwrite(STDERR, 'PREV ' . get_class($p) . ': ' . $p->getMessage() . ' @ ' . $p->getFile() . ':' . $p->getLine() . "\n" . $p->getTraceAsString() . "\n");
        }
        return (new Response(500))->text("Internal Server Error\n");
    })
    ->serve(function (Request $req) use ($kernel, $gate): Response {
        // No web server in front of the binary: it serves public/ itself. `realpath` keeps `..` inside the root.
        $static = \is_string($req->path) && $req->path !== '/' ? \realpath(__DIR__ . $req->path) : false;
        if ($static !== false && \str_starts_with($static, __DIR__ . '/') && \is_file($static) && !\str_ends_with($static, '.php')) {
            return (new Response())->file($static);
        }
        return $gate->withLock(function () use ($kernel): Response {
            $request = SymfonyRequest::createFromGlobals();
            $kernelResponse = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, !getenv("DEMO_RAISE"));
            $kernel->terminate($request, $kernelResponse);

            $response = new Response($kernelResponse->getStatusCode(), $kernelResponse->getContent());
            foreach ($kernelResponse->headers->all() as $name => $values) {
                foreach ($values as $value) {
                    $response->header($name, $value);
                }
            }
            return $response;
        });
    });
