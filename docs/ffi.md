# FFI — calling native C functions

Manticore calls into native C libraries directly. You declare a PHP **function**, tag it with
attributes naming the library and the C symbol, and the compiler emits a direct call to that
symbol — no wrapper runtime, no marshalling layer. This is how the compiler itself reaches
libc (`malloc`, `fopen`, `write`, …) and how extensions bind libraries like zlib or libcurl.

There is no `php.ini`, no extension loader and no `dlopen`. A binding is compile-time and
link-time only.

---

## The binding

```php
use Ffi\Library;
use Ffi\Symbol;

#[Library('c'), Symbol('getpid')]
function getpid(): int {}
```

- **`#[Symbol('getpid')]`** names the C symbol. A function tagged with it is an **extern
  forwarder**: the compiler emits a thin wrapper that calls the C `@getpid` directly and
  **ignores the PHP body**.
- **`#[Library('c')]`** names the library the symbol lives in. Today this is documentation for
  the reader; the actual `-l<lib>` link flag comes from the manifest (see *Linking*). `'c'`
  (libc) needs no flag — it is always linked.

Because the body is ignored when compiled, write an empty or trivial one. That body is the
**Zend fallback**: the same source also runs under stock PHP during the cold bootstrap, where
the attribute is inert and the body executes instead. Keep it harmless (`{}`, `return 0;`,
`return '';`).

⚠ **Bindings are free functions.** `#[Library]` on a class and `#[Symbol]` on its static
methods do nothing — the lowering that reads `#[Symbol]` runs only on top-level function
declarations. Every binding in the tree is a free function; see `src/Runtime/Libc.php` and
`src/Runtime/Openssl.php`. Group related bindings with a namespace, not a class.

---

## Type mapping

Each PHP parameter / return type maps to a C ABI type:

| PHP type | C / LLVM type | Notes |
|---|---|---|
| `int` | `i64` | the native word |
| `float` / `double` | `double` | |
| `bool` | `i1` | |
| `void` | `void` | return only |
| `string` | `ptr` (`char*`) | the pointer to the string bytes |
| `\Ffi\Ptr` | `ptr` (`void*`) | an opaque handle (see below) |
| a class type / untyped | `ptr` | |

The wrapper converts at the boundary: an `int` argument rides as `i64`, a `string` /
`\Ffi\Ptr` is `inttoptr`'d to a real pointer, a `float` is bitcast to `double`; the C return
is converted back (pointer → int carrier, `double` → bitcast, `bool` → zero-extend). You
write ordinary PHP types — the ABI glue is generated.

```php
#[Library('z'), Symbol('crc32')]
function __ffi_crc32(int $crc, string $buf, int $len): int { return 0; }
//                  ^ i64        ^ char*       ^ i64    -> i64
```

### `#[CType('int')]` — the one that is not optional

A C function returning `int` returns a **32-bit** value. On arm64 the callee does
`mov w0, #-1`, which zeroes the upper half of `x0`, so a wrapper that declares an `i64`
return reads **4294967295** instead of `-1`.

Put a **function-level** `#[CType('int')]` on any binding whose C prototype returns `int`, and
the wrapper sign-extends:

```php
#[Library('c'), Symbol('signalfd'), CType('int'), \Ffi\Weak]
function signalfd(int $fd, \Ffi\Ptr $mask, int $flags): int { return -1; }
```

⚠ **Never put it on a binding that returns a pointer, or a `long` / `ssize_t` / `size_t` /
`off_t` carried as PHP `int`** (`SSL_CTX_new`, `SSL_new`, `recv`, …) — the sign extension
truncates the value. This is not theoretical: `SSL_read`'s `WANT_READ` (-1) read back as a
4 GB length and `memmove`'d off the end of the heap.

Hand-written syscall stubs happen to sign-extend because they write the full `x0`, which is
why on Darwin only the real C libraries (OpenSSL, PCRE2) were exposed and Linux — where glibc
is C all the way down — is affected systemically.

`'int'` is the only token the compiler acts on. Any other value is accepted and ignored, and
a parameter-position `#[CType]` is ignored entirely.

### `#[Ffi\Weak]` — a symbol that may not exist on this target

Emits `declare extern_weak` instead of a plain `declare`, so a symbol missing at link time
resolves to null rather than failing the link. Used for platform-specific symbols referenced
from a cross-platform build (`epoll_*` and `signalfd` from a macOS build).

The decorated function must never be **called** where the symbol is absent — guard the call
with a runtime OS branch. `extern_weak` makes the *reference* tolerable, not the call.

On Darwin, ld64 still errors on a weak-undefined symbol unless it is allowed explicitly; the
driver adds `-Wl,-U,_<sym>` for the symbols it knows about (`src/Manticore/Main.php`). GNU ld
auto-binds a weak-undefined to 0, so Linux needs no flag.

---

## Opaque handles — `\Ffi\Ptr`

For C handles you hold but never dereference from PHP (a `FILE*`, a directory stream, a
library cookie), use `\Ffi\Ptr` — a `readonly` wrapper over a raw address:

```php
#[Library('c'), Symbol('fopen')]
function fopen(string $path, string $mode): \Ffi\Ptr {}

#[Library('c'), Symbol('fclose')]
function fclose(\Ffi\Ptr $stream): int { return 0; }
```

Its whole surface is `Ptr::null()`, `isNull()` and `offset(int)`. There is deliberately **no
`read*` family** — reading through a raw address from PHP was removed. `\Ffi\Ptr` is an
address and nothing more; it has no automatic free, and the caller owns the lifetime of
whatever it points at.

---

## Linking the native library

The C symbol must resolve at link time:

- **libc / libSystem** (`#[Library('c')]`) — always linked by `cc`. Nothing to do.
- **Anything else** — declare it as an **extension** in the manifest, which adds `-l<lib>` at
  the link step:

  ```json
  {
    "extensions": { "zlib": { "src": "ext/zlib", "link": ["z"] } },
    "applications": [
      { "src": "src/app", "output": "bin/app", "extensions": ["zlib"] }
    ]
  }
  ```

  An application may only name an extension that the top-level `extensions` map declares —
  naming an unknown one fails the build. See [`modules.md`](modules.md) for the full flow.

The native library never touches Manticore's arena / refcount heap, so it adds no
memory-safety surface of its own — it is an ordinary C archive linked by `cc`.

---

## Memory across the boundary

A buffer that comes back from a C allocator (`malloc`, `calloc`, or bytes read into one) is a
**raw pointer with no Manticore string header** — the refcount runtime must never touch it.
Before such a buffer flows into normal PHP code, **copy it into a real string**:

```php
$buf = calloc($size + 1, 1);     // raw libc block — NO rc header
$n   = fread($buf, 1, $size, $fp);
return substr($buf, 0, $n);      // owned, rc-headered string — safe to return
```

`substr` and friends allocate a properly headered string; the raw `calloc` block is left alone
(the compiler does not refcount FFI-call results). Returning the raw buffer directly would let
`rc_release` run on a header-less block and corrupt the heap.

---

## Worked examples

libc, as the compiler's own runtime declares it (`src/Runtime/Libc.php`):

```php
#[Library('c'), Symbol('write')]
function write(int $fd, string $buf, int $n): int { return 0; }

#[Library('c'), Symbol('getenv')]
function getenv(string $name): string { return ''; }
```

zlib, as an extension (`ext/zlib/crc32.php`):

```php
#[Library('z'), Symbol('crc32')]
function __ffi_crc32(int $crc, string $buf, int $len): int { return 0; }

function ext_zlib_crc32(string $s): int {
    return __ffi_crc32(0, $s, strlen($s));   // crc32("hello") === 907060870
}
```

---

## Declared but not consumed

The `Ffi` namespace also ships attributes that codegen does **not** read. They compile, they
document intent, and they change nothing:

- **`#[Ffi\Variadic(int $fixed)]`** — meant to emit an LLVM variadic call type so a C variadic
  callee (`fcntl`, `ioctl`, `open`-with-mode) gets its arguments where `va_arg` looks for them.
  The attribute is inert: the emitter keys variadic arity off the **C symbol name** instead
  (`EmitLlvmCalls::ffiVariadicFixed`, which today knows only `fcntl`). Binding another variadic
  C function means editing that method, not adding the attribute.
- **`Ffi\Ownership`** — `Borrow`, `BorrowMut`, `Take`, `Give`, `StaticPtr`. Advisory lifetime
  hints for a boundary memory plan that does not exist yet. Nothing is freed on your behalf.
- **`#[Ffi\CType]` in parameter position, and every token other than `'int'`.** See above.

There is no runtime `Ffi\dlopen` / `dlsym` / `call(...)`. That layer was removed — it bottomed
out on primitives that stub-linked to 0 — and the static `#[Library, Symbol]` path is the
supported, zero-overhead one.
