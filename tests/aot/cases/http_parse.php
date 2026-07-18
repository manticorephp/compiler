<?php

// MANTICORE-ONLY: php has no __mc_http_read_response(), so php dies on the very
// first call — before any output — and difftest PHP-SKIPs the file. The expected
// output is written BY HAND. (A skip is only earned when php prints NOTHING
// first; that is why the call is the first statement that could produce any.)
//
// Why the parser is tested this way rather than through file_get_contents('http://'):
// that CANNOT be exercised offline in one process. It blocks waiting for a reply,
// and there is no fork() — nor system()/proc_open() — to run an origin beside it.
// So the parser takes a stream we already own: stand up a loopback pair, push a
// canned response into the server end, parse it from the client end. Offline,
// deterministic, and it covers where the bugs actually live — status parsing,
// header parsing, Content-Length, chunk boundaries. The transport underneath is
// covered by net_tcp_loopback.
//
// Every expectation below was MEASURED against php 8.5.8 first (see the probes in
// the session): php DECODES chunked, returns FALSE for a non-2xx rather than the
// error body, and exposes raw header lines with the status line at [0].

/** A connected loopback pair: [client, server-side-conn, listener]. */
function pair(): array
{
    $port = 0;
    $srv = false;
    for ($p = 50100; $p < 50180; $p++) {
        $s = @stream_socket_server('tcp://127.0.0.1:' . $p);
        if ($s !== false) { $srv = $s; $port = $p; break; }
    }
    $client = fsockopen('127.0.0.1', $port);
    $conn = stream_socket_accept($srv, 5);
    return [$client, $conn, $srv];
}

/** Push a canned response in, close the writer so EOF is real, then parse it. */
function parse_response(string $raw, bool $followable = false): array
{
    $pp = pair();
    fwrite($pp[1], $raw);
    fclose($pp[1]);                 // EOF — a body with no Content-Length ends here
    $r = __mc_http_read_response($pp[0], $followable);
    fclose($pp[0]);
    fclose($pp[2]);
    return $r;
}

// ── Content-Length ──
$r = parse_response("HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 10\r\n\r\nhello-body");
var_dump($r[0]);
var_dump($r[1]);
var_dump(http_get_last_response_headers());

// ── chunked: the boundary case the read buffer exists for ──
$r = parse_response("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\nWiki\r\n5\r\npedia\r\n0\r\n\r\n");
var_dump($r[0]);

// A chunk-size line may carry an extension after ';'.
$r = parse_response("HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n3;ext=1\r\nabc\r\n0\r\n\r\n");
var_dump($r[0]);

// ── no Content-Length, not chunked: the body runs to EOF ──
$r = parse_response("HTTP/1.1 200 OK\r\n\r\nto-the-end");
var_dump($r[0]);

// ── non-2xx is FALSE, not the error body (php's rule, measured) ──
$r = parse_response("HTTP/1.1 404 Not Found\r\nContent-Length: 3\r\n\r\nnop");
var_dump($r[0]);
// …but the headers are still recorded.
var_dump(http_get_last_response_headers());

// ── 3xx: reported to the caller, which owns the redirect loop (it needs a NEW
//    connection, so the parser cannot follow it itself) ──
$r = parse_response("HTTP/1.1 302 Found\r\nLocation: /next\r\nContent-Length: 0\r\n\r\n", true);
var_dump($r[0]);
var_dump($r[1]);

// Not followable => a 3xx is just a non-2xx.
$r = parse_response("HTTP/1.1 302 Found\r\nLocation: /next\r\nContent-Length: 0\r\n\r\n", false);
var_dump($r[0]);
var_dump($r[1]);

// ── URL splitting ──
var_dump(__mc_url_parts('http://example.com/a/b?q=1'));
var_dump(__mc_url_parts('http://example.com:8080/'));
var_dump(__mc_url_parts('http://example.com'));
var_dump(__mc_url_parts('not-a-url'));

http_clear_last_response_headers();
var_dump(http_get_last_response_headers());
echo "done\n";

// ─────────────────────────────────────────────────────────────────────────
// BLOCKED — not `.php`, so the auto-discovered suite skips it. It cannot pass
// until the unknown/cell soundness fix lands, and the reason is NOT in this file:
//
//   1. parse_response() for a 404/302 returns [false, ''] — php's own contract —
//      and an array holding `false` in a string slot SIGSEGVs in
//      __mir_array_release_str: the NaN-boxed false is released as a string
//      pointer. Root: Type::joinElement lets `unknown` defer to `string`, so
//      vec[unknown] u vec[string] becomes vec[string] and the flavour lies.
//   2. http_get_last_response_headers(): ?array erases the array to a raw
//      pointer (int(4348915816)) — `?array` return type.
//
// Both are one root: a value crossing a tag boundary and being handled RAW.
// Rename back to .php once that is fixed; every expectation here was measured
// against php 8.5.8 first.
