<?php

// compat(true): the superglobals are seeded per request, so code written for
// php-fpm runs unchanged — including session_start(), which rides the same
// per-request seam.
//
//   bin/manticore compile examples/http/compat.php -o compat && ./compat
//   curl -c j -b j 'localhost:8082/?name=ada'
//   curl -c j -b j -d 'k=v' localhost:8082/form

use Http\Request;
use Http\Response;
use Http\Server;

(new Server('tcp://127.0.0.1:8082'))
    ->compat(true)      // $_GET / $_POST / $_COOKIE / $_SERVER / $_SESSION
    ->serve(function (Request $req): Response {
        if ($req->path === '/form') {
            return (new Response())->text('post k=' . ($_POST['k'] ?? '-') . "\n");
        }

        session_start();
        $_SESSION['hits'] = (int)($_SESSION['hits'] ?? 0) + 1;

        // Ordinary php: header(), setcookie() and echo all work, and the Server
        // folds them into the Response this handler returns.
        header('X-Powered-By: manticore');
        setcookie('last', $_GET['name'] ?? 'anon', 0, '/');
        http_response_code(200);

        echo "method:  ", $_SERVER['REQUEST_METHOD'], "\n";
        echo "uri:     ", $_SERVER['REQUEST_URI'], "\n";
        echo "name:    ", $_GET['name'] ?? '(none)', "\n";
        echo "session: ", session_id(), " hits=", $_SESSION['hits'], "\n";

        // Nothing else to say: the echoed output IS the body.
        return new Response();
    });
