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
fiber through the netpoller instead of blocking the process.

`->workers(N)` binds the listener once, then forks N workers that inherit it,
under a supervisor (`Process\supervise`) that reaps and restarts a crashed
worker and forwards SIGTERM/SIGINT to all of them. The parent serves nothing;
`serve()` returns once every worker has exited. `workers(0)` (the default)
serves in-process.

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
$req->remoteAddr    // 'ip:port' from the socket, or the client behind a trusted proxy
$req->peerAddr      // the socket's answer, always
$req->secure        // tls

$req->header('Content-Type')       $req->contentType()      // type, no params
$req->query('name', 'default')     $req->queries()          // array<string,string>, flat
$req->queryArray()                 // php's $_GET shape: `a[]=1&b[x]=2` nests
$req->postArray()                  // php's $_POST shape: urlencoded, or a multipart body's fields
$req->files()                      // array<string, Http\UploadedFile>, the first file per field
$req->allFiles()                   // every file part in wire order, `f[]` and all
$req->filesArray()                 // php's $_FILES shape, six columns transposed
$req->cookie('sid')                $req->cookies()
$req->body()                       $req->hasBody()          $req->contentLength()
$req->stream()                     // ?Buffer\Reader, only for a streamed body
$req->multipart()                  // Generator<Http\Part>, only for a streamed multipart body
$req->methodEnum()                 // ?Http\Method, for an exhaustive match
$req->is(Http\Method::Post)        $req->isKeepAlive()
```

`Request` is readonly to the handler. Query and cookie parsing is memoised
behind a private bitfield — reading five parameters scans the string once.

`queries()` and `cookies()` are flat and last-wins: `?a[]=1` gives you the key
`a[]`. `queryArray()`/`postArray()` nest exactly like `parse_str` (`.` and space
in a name become `_`, `[]` appends, `max_input_vars` truncates). A
`multipart/form-data` body is parsed once, on the first of `postArray()`,
`files()`, `allFiles()`, `filesArray()`; each file part is streamed to a temp
file (`tempnam`, `php` prefix) an `UploadedFile` describes: `field`, `name`,
`fullPath`, `type`, `size`, `error` (`UPLOAD_ERR_*`), `tmpName`, plus
`isValid()`, `moveTo($dest)` and `contents()`. A body the parser cannot frame
is a **400** before the handler runs. Every temp file the handler did not
`moveTo()` is unlinked when the request ends — thrown or not.

## Behind a proxy

Off unless asked: a header any client can send is not evidence.

    $server->trustedProxies(['10.0.0.0/8', '127.0.0.1'], Http\Proxy::ALL);

`Proxy::ALL` is the four `X-Forwarded-*` headers (`FOR|PROTO|HOST|PORT` = 15).
`Proxy::FORWARDED` (RFC 7239 `Forwarded:`) is opt-in — grant it explicitly.
It is NOT in `ALL`: a proxy that merely passes a client-supplied `Forwarded:`
header through is exactly as unsafe as trusting an arbitrary
`X-Forwarded-*`, so defaulting to it would be trusting a header without
knowing your proxy sets it.

When the PEER is in the list:

- `X-Forwarded-For` is walked right to left to the first untrusted hop, and
  sets `$req->remoteAddr`. A hop `\inet_pton` rejects — `unknown`, an
  obfuscated identifier (`_gazonk`), anything malformed — is skipped exactly
  like an empty element; if every hop is junk, `remoteAddr` stays the peer.
- `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port` take the FIRST
  (leftmost) comma-separated value and set `$req->secure`, the `Host` header,
  and `Request::$forwardedPort` — the opposite end from `X-Forwarded-For`'s
  right-to-left walk and from `Forwarded:`'s rightmost element below, since
  each header follows its own convention (Symfony's rule for the
  `X-Forwarded-*` family).
- When `FORWARDED` is granted and `Forwarded:` is present, its elements'
  `for=` values form the SAME right-to-left chain as `X-Forwarded-For` (one
  walk, whichever family supplies it); `proto=`/`host=` come from the
  rightmost element that names them — the hop nearest this process. Each
  field is still gated by its own flag on top of `FORWARDED`: `for` needs
  `FOR`, `proto` needs `PROTO`, `host` needs `HOST`. When both families are
  present, `Forwarded` wins for the fields it names; RFC 7239 quoting
  (`for="[2001:db8::1]:4711"`) is supported, and the port inside a `for=`
  value is discarded (it is not `X-Forwarded-Port`).

These set `$req->remoteAddr`, `$req->secure`, the `Host` header, and
`$_SERVER`'s `REMOTE_ADDR`/`HTTPS`/`SERVER_NAME`/`SERVER_PORT`.
`$req->peerAddr` is always the socket's own answer. `SERVER_NAME`/`SERVER_PORT`
are split from the `Host` header (itself rewritten by `X-Forwarded-Host` under
`HOST`), `X-Forwarded-Port` overriding the header's own port when `PORT` is
granted, then 443/80 by `$req->secure` — unlike php-fpm behind nginx, which
seeds `SERVER_PORT` from the listener it was started on, not from a header a
client can influence; that divergence is deliberate here.

A dual-stack listener (`tcp://[::]:port`) hands a v4 client's connection to
`peerAddr`/`X-Forwarded-For` as a v4-mapped address (`::ffff:a.b.c.d`) — a
plain v4 CIDR in `trustedProxies()` never matches it (`inCidr` compares packed
length first, so a 4-byte network against a 16-byte address fails closed);
list the mapped form (`::ffff:10.0.0.0/104`) or a v6 prefix instead.

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

A streamed `multipart/form-data` body is read one part at a time through
`$req->multipart()`: each `Http\Part` (`name`, `filename`, `type`)
hands its bytes out through `read($max)` (`''` at the part's end) or
`readAll()`, straight off the wire — no temp file, nothing buffered beyond one
read, and a part the handler stops reading is drained when the loop moves on.
The body is the generator's: `allFiles()`/`postArray()` on it, or a second
`multipart()`, throw a `LogicException`; a buffered body has no `multipart()`
(use `allFiles()`). `$_FILES` and `$_POST` are not seeded from a streamed body.

A malformed streamed body makes `multipart()` throw
`\RuntimeException('malformed multipart')` from inside the generator — at
iteration, not at the call; generators are lazy. Garbage right after a
delimiter throws the same way from the handler's own `Part::read()`/
`readAll()` call, when that garbage follows the part currently being read, not
only when the generator resumes to drain a part the handler stopped short of.
Either way the connection is what suffers, not the server: `runHandler` catches
every `Throwable` and answers **500**, so a crafted body cannot take the accept
loop down. `onError($e, $req)` is where you turn one into your own response.
A client that cuts the stream mid-part ends that part
silently — `read()` answers the remainder, then `''`, and the generator ends;
there is no `PARTIAL` signal in pull mode (the buffered path reports
`UPLOAD_ERR_PARTIAL`), so a handler that needs the whole part checks the byte
count against its own expectation.

## Files

```php
$p = Http\safePath('/srv/app/public', $req->path);
return $p === null
    ? (new Response(404))->text('not found')
    : (new Response())->file($p);
```

`file()` stats the OPENED fd once — the length on the wire is the length of the
file the body reads — and sends the bytes with `sendfile(2)`, so they never
enter the process. A TLS connection has to encrypt them, so it takes a
`fread` loop instead; the wire format is identical either way. The head carries
`Content-Type` from the extension (`Http\mimeFor()`, override with the second
argument), `Last-Modified`, a weak `ETag` of mtime and size, and
`Accept-Ranges: bytes`. A missing or unreadable path is a handler bug and
THROWS — it is not a 404.

`Http\safePath($root, $path)` is the only way a request path should become a
file path: both sides go through `realpath`, so `..` and a symlink that leaves
the root are refused by construction. A path ending in `/` answers null by rule
rather than by `realpath` — Darwin resolves `/pub/index.html/` to the file and
Linux refuses it, and one request must not answer differently per host.

Three things catch people out.

**A directory answers null.** `safePath` does not guess an index file; which
one a directory stands for is the handler's policy:

```php
$path = $req->path === '/' ? '/index.html' : $req->path;
$file = Http\safePath($root, $path);
```

**`__DIR__` is not "next to the binary".** It is resolved when the program is
COMPILED and names the directory of the *source* on the build machine — which
after a deploy may not exist. To serve a directory beside the executable, ask
the executable:

```php
$root = dirname(realpath($argv[0])) . '/public';
```

**A file is never gzipped on the fly** — see below. `Accept-Encoding: gzip` on a
`file()` response with no `<path>.gz` sibling gets the plain bytes (with `Vary`,
so a cache still keys correctly). Precompress at build time.

## Conditional requests

On a file response the handler left at **200**, and only for GET/HEAD:

- `If-None-Match` — weak comparison, `*` and lists honoured — answers **304**
  with the validators and no body.
- `If-Modified-Since` answers **304**, and is consulted only when there is no
  `If-None-Match`.
- A single `Range: bytes=a-b` (`a-`, `-n` and `a-b` forms) answers **206** with
  `Content-Range`; an unsatisfiable one **416**. A multi-range, another unit or
  garbage is ignored and the full representation goes out, as the RFC allows.
- `If-Range` narrows only on the exact `Last-Modified` date. Our ETags are all
  weak, and a weak tag never satisfies `If-Range` (RFC 9110 §13.1.5).

A handler that set its own status is answering something other than "here is
this file", so its status is left alone and no conditional runs.

## Compression

```php
$server->compression(true, minBytes: 1024, level: 6);
```

Off by default. On, a BUFFERED body of a text-like type, at least `minBytes`
long, to a client whose `Accept-Encoding` grants gzip, goes out
`Content-Encoding: gzip` through the pure-PHP `gzencode()`; a strong `ETag`
becomes weak, since the encoded bytes are a different representation.
`Vary: Accept-Encoding` is set on every compressible-type response whether or
not that response came out encoded — a cache must key on it both ways.

A file is never deflated on the fly: a sibling `<path>.gz` that is not OLDER
than the file is served in its place, with the original's `Last-Modified` and a
`-gz` ETag. A stale sibling is a build artefact nobody refreshed, so it is
ignored rather than served for ever. Streamed bodies are not compressed —
`gzencode()` is one-shot.

```
public/app.css      2160 B   →  Content-Length: 2160
public/app.css.gz     91 B   →  Content-Encoding: gzip, ETag W/"…-gz"
```

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

Off by default: seeding the superglobals per request for code that never reads
them is pure cost. Turn it on and `$_SERVER`, `$_GET`, `$_POST` (urlencoded
forms and multipart fields, nested like `parse_str`), `$_COOKIE`, `$_REQUEST`,
`$_FILES` and `$_SESSION` are seeded per request, and `session_start()` works —
it rides the same per-request seam. `$_FILES` has php's shape, `full_path`
included, with `f[]` and `u[avatar]` names transposed per column and an
anonymous part (filename= without name=) under `$_FILES[0]`.
`is_uploaded_file()` and `move_uploaded_file()` are per request: true only for
a temp file THIS request produced and nobody moved yet. The temp files are
removed at request end; a moved one survives.

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
| `maxFileUploads` | 20 | further file parts dropped (php's `max_file_uploads`) |
| `uploadMaxFilesize` | 2097152 | the part is kept with `error` 1 (`UPLOAD_ERR_INI_SIZE`), no temp file |
| `postMaxSize` | 0 | reserved: php's `post_max_size`; not enforced in either mode — a buffered body is already bounded by `maxBodySize` (413), and a streamed one's field bytes are the handler's (`Part::readAll()`) |
| `maxInputVars` | 1000 | `queryArray()`, urlencoded and multipart `postArray()` truncated silently (php's `max_input_vars`) |
| `keepAliveMax` | 1000 | connection closed after N requests |
| `idleTimeout` | 5.0 | silent close between requests |
| `headerTimeout` | 10.0 | 408 mid-head |
| `writeTimeout` | 30.0 | the write is bounded |

The rest of the knobs, none of which has a section of its own:

```php
$server->serverName('acme/1.0')   // the Server: header, default 'manticore'
    ->acceptWait(0.25)            // how long an idle accept parks before a scheduler turn
    ->captureEcho(true)           // fold echoed bytes into the body (see Absorption)
    ->compat(false)               // seed the superglobals per request
    ->onError(fn (\Throwable $e, Request $r) => (new Response(500))->text('…'));

$server->stats();                 // ['served'=>, 'accepted'=>, 'errors'=>, 'stopped'=>]
```

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
| `echo "hi";` | 7.2k |
| the same inside `Async\async()` | 49k |
| `examples/http/hello.php` | 104k |
| `examples/http/static.php` (files + gzip) | 103k |
| `examples/http/compat.php` (sessions + superglobals) | 163k |

The marginal cost of `Http\` over a program that is already async is the two
prelude files; `compat.php`'s jump is `session` and `json`, not the server.

`static.php` is no dearer than `hello.php`: `prelude/http.php` is ONE unit, so
every program that mentions `Http\` carries the file and compression code
whether or not it calls it — about 6 k lines and 1.6 % of the binary. What that
code REACHES stays gated: `gzencode` is a stdlib symbol the linker resolves only
where compression is on, and a program that never mentions `Http\` is untouched.

## Protocol takeover

`Response::takeover(\Closure $fn): Response` marks a response for hand-off to
`$fn(\Resource $conn, \Buffer\ByteBuffer $buf, Server $server)` instead of writing a
body. Only a `101` on HTTP/1.1, with no unread streamed-body byte left on the wire,
actually takes over; anything else — a different status, HTTP/1.0, a handler that
marked takeover but left request-body bytes unread — is a handler bug and the server
answers **500** without ever calling `$fn`. On a clean takeover: the head goes out,
the write timeout is cleared, the SAPI request context ends (`header()` has no
meaning past this point), and `$fn` runs with `$buf` still holding whatever bytes the
client sent right after the request head. The server never reads or writes the
connection again after that call returns.
`Server::onStop(\Closure $fn): int` / `offStop(int $id)` register and unregister a hook
that runs once when `stop()` is called — a long-lived takeover's only way to hear
about a shutdown, since it owns the socket and the server's own loop cannot signal it
any other way. Both are protocol-agnostic; `Http\WebSocket` (`docs/websocket.md`) is
the one consumer today.

## Not in this layer

Routing, middleware, PSR-7/PSR-15, HTTP/2, multi-range (`multipart/byteranges`),
brotli, and compression of a STREAMED body. PSR-7 wrappers are an ordinary pure-PHP
package on top of this; the rest are their own epics.

## See also

`docs/async.md` (the scheduler and the netpoller this rides on) · `docs/websocket.md`
(`Http\WebSocket`, built on `takeover()` above) ·
`examples/http/` (`hello`, `stream`, `compat`, `static`) · `tests/aot/cases/http_*.php`.
