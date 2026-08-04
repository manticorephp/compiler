<?php
// @php-skip — Http\ and Buffer\ live in manticore's prelude; the interpreter
// has no such classes, so there is no parity run to compare against.
//
// The instrument for allocation work on the server path: parse and
// build+render with NO SOCKETS. End-to-end rps cannot see a userspace change
// under ~10% (the syscall floor swallows it); this can, and repeatably.
// Iterations default to a few seconds native; pass a count to run it longer
// for profiling (`./http_parse 2000000`).
//
// ⚠ Every loop must feed something that is printed. A result nothing reads is
// dead code, and LLVM deletes the whole loop with it.

use Buffer\ByteBuffer;
use Http\Headers;
use Http\Parser;

// An explicit numeric arg is an absolute iteration count; any other arg (the
// dummy the LEAK harness passes) scales the default instead.
$n = 200000 * $argc;
if ($argc > 1 && (int)$argv[1] > 0) {
    $n = (int)$argv[1];
}

// 12 headers, the shape a browser actually sends.
$wire = "GET /users/42?page=2&sort=name HTTP/1.1\r\n"
      . "Host: example.com\r\n"
      . "User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)\r\n"
      . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9\r\n"
      . "Accept-Language: en-GB,en;q=0.9\r\n"
      . "Accept-Encoding: gzip, deflate, br\r\n"
      . "Connection: keep-alive\r\n"
      . "Cookie: sid=8f3c1a2b4d5e6f70; theme=dark; tz=Europe%2FKyiv\r\n"
      . "Referer: https://example.com/users\r\n"
      . "Sec-Fetch-Site: same-origin\r\n"
      . "Sec-Fetch-Mode: navigate\r\n"
      . "Upgrade-Insecure-Requests: 1\r\n"
      . "\r\n";

$acc = 0;
$t0 = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $buf = new ByteBuffer(0);
    $buf->append($wire);
    $p = new Parser($buf, '127.0.0.1');
    if ($p->parse() === Parser::READY) {
        $r = $p->request();
        if ($r !== null) {
            $acc += strlen($r->path) + strlen($r->header('host')) + strlen($r->query('page'));
        }
    }
}
$t1 = microtime(true);

// The response half: a fresh header set per response, rendered to the wire
// block — what every request pays on the way out.
$bytes = 0;
$t2 = microtime(true);
for ($i = 0; $i < $n; $i++) {
    $h = new Headers();
    $h->set('Content-Type', 'text/plain; charset=utf-8');
    $h->set('Content-Length', '13');
    $h->set('Date', 'Sun, 02 Aug 2026 10:00:00 GMT');
    $h->set('Server', 'manticore');
    $h->set('Connection', 'keep-alive');
    $out = "HTTP/1.1 200 OK\r\n" . $h->render() . "\r\n";
    $bytes += strlen($out);
}
$t3 = microtime(true);

printf(
    "parse %.2f us/req   build %.0f ns/res   n=%d acc=%d bytes=%d\n",
    ($t1 - $t0) * 1000000.0 / $n,
    ($t3 - $t2) * 1000000000.0 / $n,
    $n,
    $acc,
    $bytes
);
