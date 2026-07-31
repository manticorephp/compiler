# `Http\` — an HTTP/1.1 server

A handler is `callable(Http\Request): Http\Response`. Nothing else is required:
no router, no middleware, no container, no `php.ini`.

```php
use Http\{Request, Response, Server};

(new Server('tcp://127.0.0.1:8080'))->serve(function (Request $req): Response {
    return (new Response())->text('hello ' . $req->query('name', 'world') . "\n");
});
```

Compile it and run it — the binary IS the server. There is no fpm, no worker
pool to configure and nothing to keep alive:

```
bin/manticore compile server.php -o server && ./server
```

`Http\` is **demand-gated**: a program that never mentions it carries none of
it. Mentioning it pulls in `Buffer\`, `Async\`, the SAPI seam and the output
buffering it needs, because a server without those is not a server.

---

## Concurrency

One process serves many requests at once. Each connection is a task under one
`Async\TaskGroup`; the I/O is ordinary `fread`/`fwrite`, which suspends the
fiber through the netpoller instead of blocking the process. `->workers(N)`
forks N of those before any reactor exists, so N cores accept on one listener.

`->maxConnections(N)` is the ceiling per worker. The permit is taken **before**
`accept`, so at the ceiling the worker stops accepting and the queue stays in
the kernel backlog — which is what backpressure means for a server.

`stop()` asks the accept loop to wind down; requests already in flight are
joined, not interrupted.

## Request

```php
$req->method        // raw wire token — 'GET', but also 'PROPFIND'
$req->path          // decoded, `..`-collapsed. `%2F` IS a separator here
$req->target        // the raw request-target, still percent-encoded
$req->queryString   // raw, no leading '?'
$req->version       // '1.1' | '1.0'
$req->headers       // Http\Headers
$req->remoteAddr    // '' unless the server was given one
$req->secure        // tls

$req->header('Content-Type')       $req->contentType()      // type, no params
$req->query('name', 'default')     $req->queries()          // array<string,string>
$req->cookie('sid')                $req->cookies()
$req->body()                       $req->hasBody()          $req->contentLength()
$req->stream()                     // ?Buffer\Reader, only for a streamed body
$req->methodEnum()                 // ?Http\Method, for an exhaustive match
$req->is(Http\Method::Post)        $req->isKeepAlive()
```

`Request` is readonly to the handler. Query and cookie parsing is memoised
behind a private bitfield — reading five parameters scans the string once.

Both are flat and last-wins: `?a[]=1` gives you the key `a[]`. Nested GPC and
`$_FILES` wait for the multipart parser that would produce them.

## Response

A mutable fluent builder. Every setter returns the Response.

```php
(new Response())            ->status(201)
    ->header('X-A', '1')    ->addHeader('Set-Cookie', '…')   ->withoutHeader('X-A')
    ->type('text/csv')      ->text($s)     ->html($s)    ->body($s)   ->write($more)
    ->cookie('sid', $v, expires: 0, path: '/', httponly: true, sameSite: 'Lax')
    ->redirect('/login', 302)
    ->close()               // no keep-alive after this one
    ->stream(fn (Http\ChunkedWriter $w) => …);
```

**There is no `Response::json()`, deliberately.** `json_encode` is a codegen
builtin, inlined per call site; putting it behind a `mixed`-argument method
would pull the generic tagged encoder into *every* HTTP program. Write
`->type('application/json')->body(json_encode($v))` and the cost stays with the
program that serialises.

`Http\Status` is a class of `const int` plus `text()`/`isRedirect()`/`hasBody()`
— not an enum, for the same reason: 42 enum cases would put `from`/`tryFrom`/
`cases()` in the IR of every program, to model a value that goes on the wire as
an int.

## Streaming

```php
return (new Response())->type('text/plain')->stream(function (ChunkedWriter $w) {
    foreach ($rows as $row) {
        $w->write($row . "\n");
        $w->flush();          // without this the writer batches to 64 KiB
    }
});
```

No `Content-Length` — the length is not knowable when the head goes out, and
buffering it to find out is what streaming exists to avoid. An HTTP/1.1 peer
gets `Transfer-Encoding: chunked`; a 1.0 peer gets the bytes raw with
`Connection: close`, because the close is its only framing.

A closure that throws does **not** take the server down: the head is already on
the wire, so there is no status left to change — the framing is terminated and
the connection dropped.

Request bodies go the other way: `->maxBodySize(N)` (8 MiB) is buffered into
`$req->body()`, and anything past it is a **413** unless `->streamBodies(true)`
is on, in which case `$req->stream()` is a `Buffer\Reader` with the declared
length as its budget. A body the handler ignores is drained before the
connection is reused. A chunked body is always buffered — it declares no total,
so the cap is applied per chunk, which is the only point at which it can be.

## php's builtins work inside a handler

This is the part that makes existing code run. `header()`, `header_remove()`,
`headers_list()`, `headers_sent()`, `http_response_code()`, `setcookie()`,
`setrawcookie()` and plain `echo` are all live in every handler, per request,
with many requests in flight.

```php
$server->serve(function (Request $req): Response {
    header('X-Trace: ' . $id);
    setcookie('sid', $v, 0, '/');
    http_response_code(201);
    echo "the body\n";
    return new Response();          // ← everything above is folded into this
});
```

**Absorption — one rule, three times: the explicit API wins.**

| | |
|---|---|
| Headers | ambient lines first, the Response's own on top with *replace* semantics. `Set-Cookie` accumulates — §5.2 excludes it from joining, so calling both `setcookie()` and `->cookie()` means both. |
| Status | the Response's, if it set one; otherwise `http_response_code()`'s. |
| Body | what was echoed becomes the body **only** if the Response has none and is not streaming. Both together is a handler bug: the explicit body wins and the echoed bytes are dropped — never silently merged. |

`headers_sent()` is per-request, not per-process. Inside a streaming body it
answers **true**, because by then the head really is on the wire.

### `compat(true)` — the superglobals

Off by default: seeding four superglobals per request for code that never reads
them is pure cost. Turn it on and `$_SERVER`, `$_GET`, `$_POST` (urlencoded
forms), `$_COOKIE`, `$_REQUEST` and `$_SESSION` are seeded per request, and
`session_start()` works — it rides the same per-request seam.

Every one of these is **request-bound**, keyed by task id and swapped at the one
place the scheduler switches tasks. So is the output-buffer stack: without that
swap, two concurrent handlers would share one `ob_*` stack and fiber A's `echo`
would land in fiber B's body.

### Request-bound state of your own

```php
Async\Context::withValue('app.user', $user, function () {
    …                                   // visible here and in any task spawned here
});
$u = Async\Context::value('app.user');  // null in any other request
Http\request();                         // the ambient Request, or null outside one
```

The Server opens one `Async\Context` scope per request, around the write as
well as the handler, so a streaming body sees it too.

## Limits

| | default | on breach |
|---|---|---|
| `maxHeaderBytes` | 16384 | 431 |
| `maxHeaderCount` | 100 | 431 |
| `maxBodySize` | 8388608 | 413 (or streamed) |
| `keepAliveMax` | 100 | connection closed after N requests |
| `idleTimeout` | 5.0 | silent close between requests |
| `headerTimeout` | 10.0 | 408 mid-head |
| `writeTimeout` | 30.0 | the write is bounded |

Also refused, always: a malformed request line, a header name with whitespace
before its colon, 1.1 without `Host`, a version other than 1.1/1.0 (**505**), a
body-bearing method with no framing (**411**), an `Expect` we do not implement
(**417**), `CONNECT` (**501**), and `Transfer-Encoding` together with
`Content-Length` (**400**).

That last one is stricter than the RFC, on purpose. The two lengths can only be
compared by decoding the body, and every recipient that resolves the ambiguity
differently is one half of a smuggled request. It costs a 400 on a message no
correct client sends.

Every refusal is a precomputed constant and a close: these are the paths an
unauthenticated peer can reach, and an error path that allocates is one a client
can turn into a cost.

`Expect: 100-continue` is answered only once the framing has been *accepted* —
a body you have decided to refuse is never invited.

## `Buffer\`

`Http\` is written on it and it is useful on its own.

- **`ByteBuffer`** — bytes plus a read cursor. The cursor is not an
  optimisation: without it every consume is a `substr` of the remainder, i.e.
  quadratic. `append`/`peek`/`read`/`skip`/`indexOf`/`view`/`compact`.
- **`Reader`** — a bounded read over a stream through a shared ByteBuffer, so
  bytes read past the current message stay available to the next one.
- **`Writer`** — buffered writes with a vectored `writev()`.

`indexOf` answers `-1`, not `false`. A `int|false` return makes the value a
CELL at every call site, and this code compares these arithmetically on every
request. The divergence from `strpos` is deliberate and local.

## What it costs

`dump-llvm | wc -l`, macOS, this tree:

| program | LLVM lines |
|---|---|
| `echo "hi";` | 8.8k |
| the same inside `Async\async()` | 46k |
| `examples/http/hello.php` | 82k |
| `examples/http/compat.php` (sessions + superglobals) | 191k |

The marginal cost of `Http\` over a program that is already async is the two
prelude files; `compat.php`'s jump is `session` and `json`, not the server.

## Not in this layer

Routing, middleware, PSR-7/PSR-15, multipart/`$_FILES`, nested GPC arrays,
HTTP/2, WebSockets. PSR-7 wrappers are an ordinary pure-PHP package on top of
this; the rest are their own epics.

## See also

`docs/async.md` (the scheduler and the netpoller this rides on) ·
`examples/http/` (`hello`, `stream`, `compat`) · `tests/aot/cases/http_*.php`.
