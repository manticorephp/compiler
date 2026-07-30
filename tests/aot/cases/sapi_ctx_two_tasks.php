<?php

// Two requests served concurrently in one process must not see each other's
// state. Each task begins its own request, suspends in the middle (the delay is
// what forces the scheduler to interleave them) and re-reads everything after
// resuming: the per-task context swap in Scheduler::step is what has to hold.
//
// ⚠ Nothing printed before the first Async\ call — difftest treats a file as
// manticore-only when php produces NO stdout.

use function Async\async;
use function Async\spawn;
use function Async\delay;

function serve(string $name, string $cookie, int $ms): string
{
    __mc_request_begin(
        ["REQUEST_URI" => "/" . $name],
        ["q" => $name],
        [],
        ["PHPSESSID" => $cookie]
    );
    header("X-Who: " . $name);
    $_SESSION["who"] = $name;

    // Yield in the middle of the request — the other task runs here.
    delay($ms);

    $hdrs = __mc_response_headers();
    $seen = $_GET["q"] . "|" . $_COOKIE["PHPSESSID"] . "|" . $_SESSION["who"]
        . "|" . $_SERVER["REQUEST_URI"] . "|" . $hdrs[0];
    __mc_request_end();
    return $seen;
}

async(function () {
    $a = spawn(function () { return serve("alpha", "cookie-a", 30); });
    $b = spawn(function () { return serve("beta", "cookie-b", 10); });
    echo $b->await(), "\n";
    echo $a->await(), "\n";
});

// The main flow never began a request, so its context is untouched.
var_dump(headers_list(), http_response_code(), isset($_GET["q"]));
