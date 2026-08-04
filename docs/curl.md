# ext/curl

An HTTP client with php's own surface — `curl_init`, `curl_setopt`, `curl_exec`,
`curl_getinfo`, the `curl_multi_*` and `curl_share_*` families — bound to
libcurl through FFI. No C extension, no php.ini: the prelude is injected only
into programs that actually call one of these functions, and `-lcurl` reaches
the link line only then.

**Requires libcurl >= 7.68.0** (Ubuntu 20.04's baseline). The floor comes from
`curl_multi_poll` (7.66), `curl_easy_upkeep` (7.62) and the `CURLINFO_*_T`
family. Resolution is `pkg-config --libs curl` → `curl-config --libs` → `-lcurl`,
so nothing needs configuring.

```php
$ch = curl_init('https://example.com/');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
$body = curl_exec($ch);
if ($body === false) {
    throw new RuntimeException(curl_error($ch));
}
echo curl_getinfo($ch, CURLINFO_RESPONSE_CODE), "\n";
```

## Where it lives

| file | what |
|---|---|
| `prelude/curl.php` | binds, the 689 constants, `__McCurl`, `CurlHandle`, the four trampolines, the easy API, `curl_getinfo`, `curl_version` |
| `prelude/curl_multi.php` | `curl_multi_*`, `curl_share_*`, `CurlMultiHandle`, `CurlShareHandle` — gated separately |

It is a **demand-gated prelude**, not an `extensions[]` entry in
`manticore.json`. An extension's glue is compiled into the user module the same
way the stdlib `.sig` is, and a `.sig` carries FUNCTIONS ONLY — `curl_init()`
returns a `CurlHandle`, so a class declared that way would be invisible to the
program holding one. Same call ext/simplexml and ext/dom made.

## The callback trampolines

`fn_to_ptr()` needs a **string literal** function name: the address is a
relocation resolved at compile time, not a runtime lookup. A PHP program hands
`CURLOPT_WRITEFUNCTION` a Closure, so the user's callback can never be the
pointer libcurl holds.

It holds one of four FIXED trampolines instead, and the `void*` alongside it
(`CURLOPT_WRITEDATA` and friends) is the handle **id** — an integer libcurl only
ever hands back, never dereferences. The trampoline recovers the id and looks
the PHP callable up in `__McCurl`. Four literals, any number of closures.

```
libcurl ──calls──▶ __mc_curl_write_tramp(char *buf, size_t, size_t, void *id)
                        │  str_from_buffer(buf, n)        ← copy out FIRST
                        │  __McCurl::$writeCb[id]         ← the user's Closure
                        ▼
                   the PHP callback
```

Three rules make it work, and each one was a bug first:

- **Copy out before PHP sees it.** `$buf` is libcurl's own block and carries no
  rc header. It has to become a real headered string (`str_from_buffer`,
  `cstr_to_str`) before any PHP code touches it, or the first `rc_release` walks
  off a header that was never there.
- **Nothing may throw out of a trampoline.** A `throw` longjmps to the nearest
  PHP `try`, which sits ABOVE libcurl's own frames — its transfer state is left
  half-updated. A user callback's Throwable is caught, parked in
  `__McCurl::$cbErr`, and signalled to libcurl by returning a byte count that
  differs from what it asked for (`CURLE_WRITE_ERROR`). `curl_exec()` rethrows it
  on our own stack.
- **WRITEFUNCTION before WRITEDATA.** If `WRITEDATA` already held our integer id
  while libcurl's DEFAULT writer was still installed, that writer would
  `fwrite()` to a `FILE*` that is really the number 7.

This is the reusable recipe for any C API that takes a callback plus a userdata
pointer — pdo_sqlite's `sqlite3_exec` and progress hooks want the same shape.

## One variadic binding

`curl_easy_setopt(CURL *, CURLoption, ...)` is C variadic, and the emitter allows
exactly one signature per C symbol. One binding carries every option class:

```php
#[\Ffi\Library('curl'), \Ffi\Symbol('curl_easy_setopt'), \Ffi\Variadic(2), \Ffi\CType('int')]
function __mc_curl_easy_setopt(\Ffi\Ptr $h, #[\Ffi\CType('int')] int $opt,
                               #[\Ffi\CType('long')] int $val): int {}
```

The vararg's type never enters the emitted `declare` (only
`i32 (ptr, i32, ...)`), and on LP64 a `long`, a `char*`, a `void*` and a function
pointer all ride one 8-byte slot. `#[Variadic(2)]` is what puts the argument
where Darwin arm64's `va_arg` looks — the stack, not a register.

The function-level `#[CType('int')]` is not optional: `CURLcode` is an enum, so a
returned `-1` reads back as `4294967295` without it.

## The option table is libcurl's own numbering

libcurl encodes each option's C type **in its number** — `LONG 0`,
`OBJECTPOINT 10000`, `FUNCTIONPOINT 20000`, `OFF_T 30000`, `BLOB 40000`, each
option being `TYPE + ordinal`. So `intdiv($option, 10000)` classifies every
option there is, including ones this file has never heard of. No 300-entry table
is ever built; the only explicit list is the ~10 OBJECTPOINT options whose value
is a `curl_slist*` rather than a string, which is the one distinction the
numbering cannot make.

## Deliberate divergences from php, and why

| | |
|---|---|
| `curl_close()` is a **no-op** | php 8.0 made the handle an object; php 8.5 deprecates the function outright. The teardown is `CurlHandle::__destruct`. Freeing here would break a script that closes and then reuses a handle — which still works under php. |
| `CURLOPT_POSTFIELDS` with an **array throws** | php builds a `multipart/form-data` body through `curl_mime`, which needs the CURLFile machinery. Urlencoding it instead would put a DIFFERENT request on the wire than php sends for the same script; a wrong answer is worse than a clean refusal. Pass `http_build_query()`. |
| `CURLOPT_ERRORBUFFER` / `CURLOPT_XFERINFODATA` **throw if set** | both are ours. Letting a program overwrite either points a trampoline at a foreign id, or hands libcurl a PHP string to scribble 256 bytes into. |
| every float `CURLINFO` is read through its **`_T` sibling** | this build cannot read a C `double` out of memory — no `peek_f64`, no bitcast builtin, no `unpack('d')`. The off_t siblings carry the same number as integers (microseconds for timers, bytes for sizes) and are divided back down. |
| `curl_getinfo()` reports `httpauth_used` / `proxyauth_used` as **0** on libcurl < 8.12 | the key set is the contract callers branch on; a missing key is a fatal `Undefined array key` where a stale 0 is not. |

php's `curl_exec` answering `true` rather than the buffer when both
`CURLOPT_RETURNTRANSFER` and a `CURLOPT_WRITEFUNCTION` are set is **not** a
divergence — it is matched. php keeps ONE write method per handle
(`STDOUT`/`RETURN`/`FILE`/`USER`) and the last setter wins outright.

## Not implemented

Each throws with a message naming itself rather than failing quietly:

- `CURLFile` / `CURLOPT_MIMEPOST` / `CURLOPT_HTTPPOST` — multipart uploads
- every `CURLOPTTYPE_BLOB` option (`CURLOPT_*_BLOB`)
- `CURLMOPT_PUSHFUNCTION` — HTTP/2 server push needs the `curl_pushheaders` API
- `CURLINFO_CERTINFO` reports `[]`; the real thing is a `struct curl_certinfo`
  deref and only ever populated for a TLS transfer with `CURLOPT_CERTINFO` on

## The two structs

Everything else is function-shaped. These two are read at hard-coded offsets,
each with a self-check rather than a comment:

- **`CURLMsg`** (`curl_multi_info_read`): `msg@0`, `easy_handle@8`, `data@16`,
  size 24 on every LP64 target. The `easy_handle` is looked up among the live
  handles — one that matches nothing means the layout moved, and that is a clean
  error instead of a wrong answer.
- **`curl_version_info_data`** (`curl_version`): read under the struct's own
  `age` field, which exists precisely so a caller can tell how far the block is
  valid. The struct is only ever appended to.

## Tests

`tests/aot/cases/curl_*.php`, run with `bash tests/aot/run.sh -k curl`. Three
tiers, and the tier decides whether the parity gate can grade it:

- **`file://`** — no server, no fork, and byte-exact against real php.
- **a forked child running a plain-PHP responder** (`stream_socket_server` +
  `fwrite`, nothing manticore-only) — also byte-exact, because php and the native
  binary run the identical server.
- **a forked child running `\Http\Server`** — manticore-only, so difftest
  PHP-SKIPs it and the expected output is written by hand.

⚠ The server always needs its own process. `curl_easy_perform()` blocks the
calling thread, so a server sharing it would never reach `accept()`. The listener
is bound in the parent BEFORE the fork, which also removes the startup race —
the backlog holds the parent's `connect()` either way.
