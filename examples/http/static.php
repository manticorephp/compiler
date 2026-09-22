<?php

// A static file server: sendfile(2), conditional requests, Range, and gzip.
//
//   bin/manticore compile examples/http/static.php -o static && ./static
//   curl -v localhost:8080/index.html
//   curl -v -H 'Range: bytes=0-15' localhost:8080/index.html
//   curl -v -H 'Accept-Encoding: gzip' --compressed localhost:8080/app.js
//
// safePath() is the whole security story: both the root and the request path go
// through realpath, so `..` and symlinks cannot leave the root. A directory
// answers null, which is why the index file is named here and not guessed.
//
// The root is derived from the EXECUTABLE, not from __DIR__: __DIR__ is
// resolved at compile time and names the source's directory on the build
// machine, which after a deploy need not exist. `public/` travels beside the
// binary.

use Http\Request;
use Http\Response;
use Http\Server;

$root = dirname(realpath($argv[0])) . '/public';

(new Server('tcp://127.0.0.1:8080'))
    ->compression(true, 1024, 6)
    ->serve(function (Request $req) use ($root): Response {
        $path = $req->path === '/' ? '/index.html' : $req->path;
        $file = Http\safePath($root, $path);
        if ($file === null) {
            return (new Response(404))->text("not found\n");
        }
        return (new Response())->file($file);
    });
