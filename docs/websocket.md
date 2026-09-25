# `Http\WebSocket` — RFC 6455 over `Http\Server`, and as a client

Real-time over the same server and port as HTTP (chat, live updates, push), and
outbound WebSocket connections to third-party servers, over one core: RFC 6455
framing plus RFC 7692 permessage-deflate.

This is a **superset** feature — `php` has no WebSocket layer, so there is no Zend
oracle and `tools/difftest.sh` cannot check a line of it. Coverage is its own suite
(`tests/aot/cases/ws_*`, hand-written expected output) and Autobahn|Testsuite (below).
It lives in `prelude/websocket.php`, namespace `Http\WebSocket`, demand-gated on
mentioning `WebSocket` — a program that never does carries none of it, and mentioning
it also turns on `Http\` and `Buffer\` (header parsing and the byte buffer it rides
on).

```php
use Http\Request;
use Http\Response;
use Http\Server;
use Http\WebSocket as WS;

(new Server('tcp://0.0.0.0:8080'))->serve(function (Request $req): Response {
    return WS\upgrade($req, function (WS\Connection $ws): void {
        foreach ($ws as $m) {
            $ws->send('echo: ' . $m->data);
        }
    });
});
```

## Server

`WS\upgrade(Request $req, callable $session, ?Options $o = null): Response` checks
the handshake and, on success, answers `101` and hands the connection to `$session`
in its own fiber. A request that is not a valid upgrade gets an **ordinary
response**, not an exception — return it like any other handler result:

- not `GET`, not HTTP/1.1, missing/malformed `Sec-WebSocket-Key`, no `websocket`
  token in `Upgrade`, no `upgrade` token in `Connection` → **400**.
- `Sec-WebSocket-Version` other than `13` → **426** with
  `Sec-WebSocket-Version: 13`.
- `allowedOrigins` non-empty and `Origin` not in it → **403**.

`$session` receives the `Connection`. When it returns, a still-open connection is
closed with `1000`; an exception escaping it closes with `1011` and is rethrown into
the server's own error path (`statErrors`) — there is no HTTP response left to shape
by then.

**`allowedOrigins`** exists because a WebSocket upgrade is a cross-site request CORS
does not police: a browser sends cookies on it regardless of origin, and there is no
preflight. Anyone's page can open `new WebSocket('wss://your-host/…')` against a
logged-in user's session (cross-site WebSocket hijacking, CSWSH). The default,
`[]`, checks nothing — set it whenever the connection is privileged:

```php
(new WS\Options())->allowedOrigins(['https://example.com']);
```

**Subprotocols** — `Options::protocols(['chat.v2', 'chat.v1'])` on the server picks
the first entry of the client's `Sec-WebSocket-Protocol` list that is also in that
array; no match sends no `Sec-WebSocket-Protocol` header at all, leaving the client
to decide whether to proceed. `$ws->protocol()` reads back what was negotiated
('' if none).

## Client

`WS\connect(string $url, ?Options $o = null, array $headers = []): Connection` opens
`ws://` (`tcp://`) or `wss://` (`tls://`, SNI + certificate verification through
`Options::sslContext`, the same shape as a stream context's `ssl` options —
`verify_peer`, `cafile`, …). Any other scheme, an empty host, or a handshake failure
throws `HandshakeException`. `$headers` adds request headers (`Origin`,
`Authorization`, …); one that names `Host`, `Upgrade`, `Connection` or a
`Sec-WebSocket-*` header — or contains CR, LF or NUL — throws `ValueError`, since
those are the handshake's own headers.

The client validates the response itself: status must be `101`, `Sec-WebSocket-Accept`
must match, a returned subprotocol must be one offered, a returned extension must be
one offered — anything else raises `HandshakeException` naming the status line or
what disagreed. A response head over 16 KiB with no `\r\n\r\n` yet also raises
`HandshakeException` rather than waiting indefinitely for one. **Redirects are not
followed.** `connectTimeout` (10 s default) bounds the TCP/TLS connect **and** the
handshake response together, as one deadline.

`connect()` needs no `Async\async()` scope — outside one it blocks the process on
ordinary sockets, exactly like a script written before this feature existed:

```php
$ws = WS\connect('wss://example.com/feed');   // works at top level, no scheduler
$ws->send('hello');
foreach ($ws as $m) {
    echo $m->data, "\n";
}
```

Inside `Async\async()` the same call suspends the fiber instead of the process, so
many connections run concurrently in one program.

## Receiving

`Connection` is an `IteratorAggregate`: `foreach ($ws as $m)` yields `Message`
(`$m->data`, `$m->binary`) until the connection ends, which is exactly what
`receive(): ?Message` returning `null` means. **Only one task may read at a time** —
a second concurrent `receive()` throws `LogicException`. `closeCode()` and
`closeReason()` say why a `null` happened:

- `closeCode()` is the **peer's** Close code — `1005` if its Close frame carried
  none — or **`1006`** if no valid Close ever arrived (EOF, a protocol error, a
  ping that went unanswered). It is never the code *we* sent.

**The connection lives only while something reads it**: the read timeout doubles as
the ping timer (see Keepalive below), so a connection nobody is `receive()`-ing never
gets pinged and never notices the peer going away until the next write fails.
Push-only code — a feed that only calls `send()`/`sendBinary()` — must still run a
reader, even one that does nothing with the messages:

```php
Async\spawn(function () use ($ws): void {
    foreach ($ws as $m) { /* discard, or route it */ }
});
```

or use `WS\run($ws, $handler)` / `WS\handler($h)`, which always reads (see
Callbacks below).

## Sending

`send(string $data)`, `sendBinary(string $data)` and `ping(string $payload = '')`
(payload ≤ 125 bytes) may be called from **any task**, not only the reader —
concurrent writers are serialized under an internal mutex, so frames never interleave
on the wire. Outgoing messages are sent as a single frame; there is no
fragmentation on send. `send()`/`sendBinary()` on a closed connection throws
`ConnectionClosedException`.

## Closing

`close(int $code = 1000, string $reason = '')` — `$code` must be a sendable close
code (`closeCodeSendable`) and `$reason` at most 123 bytes (the close frame's
payload is 2 bytes of code plus the reason, and a control frame caps at 125); either
violation throws `ValueError` before anything is sent:

- Called from the task that last ran `receive()` (or the connection's owning task,
  before any `receive()` has run) — the **reader** — it sends the Close frame and
  then **waits for the peer's answer**, up to `closeTimeout` (5 s default).
- Called from any other task (a broadcaster kicking one client while its own reader
  is elsewhere) it sends the Close frame and arms a `closeTimeout` deadline that
  shuts the socket down if nothing settles it first — it **never blocks the
  caller**. The parked reader sees the peer's Close (or the deadline's abrupt EOF,
  `closeCode()` → `1006`) at its next `receive()`.

`Server::stop()` (and the SIGTERM path built on it) sends every open WebSocket
session `1001 "server shutdown"` through the same non-blocking path — the stop hook
never reads, and never parks behind a writer stuck on a full send buffer.

A session closure that **throws** closes the connection with `1011` before the
exception is rethrown into the server's error path.

## Keepalive

`pingInterval` (30 s default; `0` turns it off) is also the read timeout: every read
without a ping outstanding re-arms it. On timeout with no ping outstanding, a `ping`
goes out and the wait becomes `pongTimeout` (10 s default); silence past that closes
the socket with `1006`. A `pong` (or any other read) before then clears the pending
ping and the interval starts again — an active connection is never pinged just
because time passed. With `pingInterval` at `0`, a read timeout is silently re-armed
forever; nothing ever probes a silent peer that way, so this is only safe for a
connection you know is otherwise alive.

## Limits

`maxFrameSize` (default = `maxMessageSize`, 16 MiB) is checked from the frame header
**before** the payload is read, so an oversized frame never gets buffered; over the
limit closes with **`1009`**. `maxMessageSize` (16 MiB default) bounds the
reassembled message across fragments, checked incrementally as fragments arrive —
same code, same close.

## Compression

Off by default — `Options::compression(true)` on both sides negotiates
permessage-deflate (RFC 7692): `server_no_context_takeover`,
`client_no_context_takeover`, `server_max_window_bits`, `client_max_window_bits`, all
four parameters. The server accepts the first well-formed offer it can satisfy; the
client offers `permessage-deflate; client_max_window_bits` and fails the handshake if
the server's answer names anything it did not allow. An offer that would bind *our*
deflater to an 8-bit window is declined rather than silently upgraded to 9 — zlib
(and this pure-PHP encoder, which matches it) cannot do a true 256-byte window, so
the server skips such an offer and the client raises `HandshakeException`.

Only messages at least `compressionMinBytes` (256 default) are compressed; smaller
ones go over the wire uncompressed, which RFC 7692 allows per message. Compression
and framing share one write lock, so a compressed frame's position on the wire
matches the deflater's own stream order.

**Cost.** The encoder is pure PHP (fixed-Huffman DEFLATE — no dynamic Huffman
tables), roughly **1 ms of CPU per compressed round trip** on ordinary message
sizes, and its output runs somewhat larger than zlib's on the same text. A single
10 MB `deflate_add` call has been measured peaking around **622 MB RSS**; this is
not a background cost, it only applies while compression is on and messages are
large. **Decompression is bomb-bounded**: input is fed to `inflate_add` in 4 KiB
slices, so a message is abandoned (closed with `1009`) once its **decompressed**
size passes `maxMessageSize`, at a peak of `maxMessageSize` plus at most one slice's
worth of output (up to ~4 MiB) rather than the whole crafted payload. Corrupt
compressed data closes with `1007`.

## `maxConnections`

A WebSocket session holds its connection's `Server::maxConnections` permit for the
session's **whole life**, not per request — size the server's connection ceiling for
concurrent *sockets*, including every open WebSocket, not concurrent HTTP requests.

## Callbacks and `Hub`

A thin event layer over the same `Connection`, for code that prefers `onMessage`
over a `foreach`:

```php
interface Handler
{
    public function onOpen(Connection $c): void;
    public function onMessage(Connection $c, Message $m): void;
    public function onClose(Connection $c, int $code, string $reason): void;
    public function onError(Connection $c, \Throwable $e): void;
}

function handler(Handler $h): \Closure;        // upgrade($req, handler($h), $o)
function run(Connection $c, Handler $h): void;  // client side, same loop
```

`run()` calls `onOpen`, then `onMessage` per message, then `onClose` **exactly
once**, whichever way the loop ends. An exception from `onOpen`/`onMessage` goes to
`onError` and closes the connection with `1011`; an exception from `onError` itself
is swallowed, the same one-level rule `Server::onError` uses.

`Hub` is a set of connections to fan a message out to:

```php
final class Hub
{
    public function add(Connection $c): void;
    public function remove(Connection $c): void;
    public function broadcast(string $data, bool $binary = false): int;  // sent count
    public function count(): int;
}
```

`broadcast()` skips and drops any connection that is closed or whose `send` fails —
one dead client never stops the fan-out to the rest. See `examples/http/ws_chat.php`.

It is **sequential**: each `send` returns only once that client has taken the whole
frame, so recipients are served one after another from the calling task. A client
that is slow but alive — one that keeps reading a few bytes, just often enough that
its write never times out — holds up every recipient after it for as long as it
takes to drain the message. `broadcast()` suits small rooms and small messages on
healthy links. For a wide or large fan-out, give each connection its own writer: a
task per client draining a bounded queue, with the broadcaster only enqueueing (and
closing a client whose queue overflows), so one slow reader costs its own queue and
nobody else's latency.

## Testing

`tests/aot/cases/ws_*.php` (codec byte-exactness, the accept-key vector, loopback
echo, fragmentation, protocol-error close codes, handshake refusals, keepalive,
`Server::stop()`, concurrent senders, permessage-deflate against the RFC 7692
examples, TLS, the callback/`Hub` layer) — `bash tests/aot/run.sh -k ws_`.

`tools/autobahn.sh {server|client}` runs Autobahn|Testsuite against
`examples/http/ws_echo.php` (mode `server`, we are the server under test) or against
its `fuzzingserver` (mode `client`, `tools/autobahn_client.php` is the client under
test); needs Docker, no-ops if it is absent. `AUTOBAHN_CASES` overrides the case-id
list (default: sections `1 2 3 4 5 6 7 9 12 13`). A pattern that matches zero cases
hangs `wstest` rather than exiting — known, size the pattern to match something. This
is a manual gate, run by whoever is verifying a change, not part of `bin/build` or
the suite. Latest run recorded: section `1.*` (framing) 16/16 `OK` both directions
(server and client mode); the rest of the case list has not been run end to end yet.

## Memory

Measured: per-message cost is flat regardless of message count. Per connection,
roughly 1.3 KB residual (plain) or 6.6 KB (with compression) — this is a known
`Http\Server` task-lifetime leak shared with plain HTTP connections, tracked
separately, not something the WebSocket code itself holds onto.

## `ext/zlib` incremental API

permessage-deflate is built on `deflate_init`/`deflate_add`/`inflate_init`/
`inflate_add`/`inflate_get_status`/`inflate_get_read_len` (`DeflateContext`,
`InflateContext`) — Zend's own API, pure PHP here, so `difftest` is its oracle for
this part. Inflate decoding resumes at the last complete unit — a symbol, a slice of
a stored block, a block header, a container field — so a stream fed in any chunking
answers what zlib answers, not a block-at-a-time approximation of it; a data error
returns `false` rather than throwing, matching the existing one-shot `gzinflate`
contract.

## Not in this layer (out of scope)

WebSockets over HTTP/2 (RFC 8441); extensions other than permessage-deflate;
fragmentation on send (every outgoing message is one frame); a socket-hijack API;
WebSocket through `ext/curl`; following redirects in `connect()`.

## See also

`docs/http.md` (`Response::takeover()`, `Server::onStop()`/`offStop()` — the
protocol-agnostic hooks this rides on) · `docs/async.md` (the scheduler and
netpoller under `connect()` and every server session) · `examples/http/ws_echo.php`,
`ws_chat.php`, `ws_client.php` · `tests/aot/cases/ws_*.php` · `tools/autobahn.sh`.
