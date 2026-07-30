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
- **`#[Library('c')]`** names the library the symbol lives in, and that is what puts it on the
  link line (see *Linking*). `'c'` (libc / libSystem) needs no flag — it is always linked.

Because the body is ignored when compiled, write an empty or trivial one. That body is the
**Zend fallback**: the same source also runs under stock PHP during the cold bootstrap, where
the attribute is inert and the body executes instead. Keep it harmless (`{}`, `return 0;`,
`return '';`).

⚠ **Bindings are free functions, and anything else is a compile error.** `#[Library]` on a
class or `#[Symbol]` on a method is rejected at the declaration. A method binding cannot work:
the lowered function carries a receiver parameter with no C counterpart, so only a `static`
could ever bind — and a static method binding is a namespaced free function with worse
ergonomics. It also could never cross a `.o` boundary, because a module's `.sig` exports free
functions only, which is the property that makes `Runtime\Libc\*` importable at all. Group
related bindings with a namespace; see `src/Runtime/Libc.php` and `src/Runtime/Openssl.php`.

---

## Type mapping

With no `#[CType]`, the PHP hint decides the C ABI type — the **fallback**, not the whole
story (see the next section):

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

### `#[CType]` — saying what the C prototype actually says

PHP's `int` covers C's `char`, `short`, `int`, `long` and `size_t` alike, and the wrapper has
to know the real width to convert correctly. `#[Ffi\CType]` says which one. It goes at
**function level** for the return, and on individual **parameters**:

```php
#[Library('c'), Symbol('write')]
function write(#[CType('int')] int $fd, string $buf,
               #[CType('size_t')] int $n): int {}
```

| token | LLVM | notes |
|---|---|---|
| `void` | `void` | return only |
| `bool` | `i1` | |
| `char` / `uchar` | `i8` | |
| `short` / `ushort` | `i16` | |
| `int` / `uint` | `i32` | |
| `long` `ulong` `longlong` `ulonglong` `size_t` `ssize_t` `off_t` | `i64` | |
| `float` | `float` | C's 32-bit float, **not** PHP's `float` |
| `double` | `double` | |
| `ptr` | `ptr` | an address carried as PHP `int` |

The multi-word C spellings (`unsigned int`, `long long`, …) are accepted as aliases. Anything
else — including a platform typedef like `nfds_t` or `socklen_t` — is a **compile error**: only
you can resolve such a typedef's width, and it is not always the same one (glibc types
`nfds_t` as `unsigned long`, Darwin as `unsigned int`). The set is closed on purpose; when
unknown tokens were silently ignored, `'unsigned int'` and `'nfds_t'` sat in the tree unnoticed.

`long`, `size_t`, `ssize_t` and `off_t` are 64-bit here. Manticore compiles for the host and
every target it builds for is LP64.

**Why the return token is not optional.** A C function returning `int` returns a **32-bit**
value. On arm64 the callee does `mov w0, #-1`, which zeroes the upper half of `x0`, so a
wrapper declaring an `i64` return reads **4294967295** instead of `-1`:

```php
#[Library('c'), Symbol('signalfd'), CType('int'), \Ffi\Weak]
function signalfd(#[CType('int')] int $fd, \Ffi\Ptr $mask,
                  #[CType('int')] int $flags): int { return -1; }
```

Signedness picks the direction — `uint` zero-extends where `int` sign-extends. Hand-written
syscall stubs happen to sign-extend because they write the full `x0`, which is why on Darwin
only the real C libraries (OpenSSL, PCRE2) were exposed, while Linux — where glibc is C all
the way down — is affected systemically.

**The token and the PHP hint must agree**, and the compiler enforces it. A `\Ffi\Ptr` or
`string` carries an address, so an integer token on one is rejected outright: sign-extending a
returned pointer is exactly how `SSL_read`'s `WANT_READ` (-1) became a 4 GB length that
`memmove`'d off the end of the heap. A `float` hint takes only `float` / `double`, and `void`
is a return type only. Use `ptr` for the handle-as-`int` idiom (`SSL_CTX_new`, `strstr`).

⚠ **Every binding of one C symbol must declare the same signature.** Declares are keyed by
symbol, so a second, differently-typed binding of `close` used to be dropped silently and
whichever wrapper was emitted first decided what all call sites were typed against. The
emitter now rejects the module and names both bindings.

### `#[Ffi\Weak]` — a symbol that may not exist on this target

Emits `declare extern_weak` instead of a plain `declare`, so a symbol missing at link time
resolves to null rather than failing the link. Used for platform-specific symbols referenced
from a cross-platform build (`epoll_*` and `signalfd` from a macOS build).

The decorated function must never be **called** where the symbol is absent — guard the call
with a runtime OS branch. `extern_weak` makes the *reference* tolerable, not the call.

On Darwin, ld64 still errors on a weak-undefined symbol unless it is allowed explicitly. The
driver **derives** `-Wl,-U,_<sym>` from every `extern_weak` the module actually emitted, and
carries the set across a library's `.sig` for a binding that lives in a linked `.o` — so the
allowance cannot drift from the bindings, and a program that pulls in no weak binding gets no
`-U` flags at all. GNU ld auto-binds a weak-undefined to 0, so Linux needs no flag.

### `#[Ffi\Variadic($fixed)]` — a C variadic callee

`$fixed` is the number of **named** parameters, the ones before the C `...`:

```php
#[Library('c'), Symbol('fcntl'), Variadic(2), CType('int')]
function fcntl(#[CType('int')] int $fd, #[CType('int')] int $cmd,
               #[CType('int')] int $arg): int {}
```

Without it the wrapper emits a fixed-arity call, and on Darwin arm64 — whose variadic ABI
passes varargs on the **stack** — the callee reads register garbage where it does `va_arg`.
Parameter `#[CType]` matters most here: `va_arg(ap, T)` reads `T`'s natural size and advances
by it, so an `int` handed over in an 8-byte slot leaves every later read misaligned.

`$fixed` must be between 0 and the binding's arity; anything else is a compile error.

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

**`#[Library('name')]` is what puts the library on the link line.** Nothing else is required:

```php
#[Library('z'), Symbol('crc32')]
function __ffi_crc32(int $crc, string $buf, int $len): int { return 0; }
// -> the link gets -lz
```

- **libc / libSystem** (`#[Library('c')]`) is implicit — always linked, never flagged.
- Any other name resolves through `pkg-config --libs <name>`, then `<name>-config --libs`,
  then a bare `-l<name>`. The probe order is what makes OpenSSL and PCRE2 work on a host where
  Homebrew keeps them off the default search path.
- Requirements are collected **per emitted wrapper**, then carried in the module's `.sig`.
  That matters because linking is whole-program while a wrapper is emitted once, in the module
  that owns the source: a program calling `preg_match` gets the pcre2 wrapper out of
  `lib/manticore_stdlib.o` and has no `#[Library]` of its own to derive `-lpcre2-8` from.
- `-dead_strip_dylibs` / `--as-needed` drop a library the program never actually reaches.

The manifest's `extensions[].link` remains the **escape hatch** — for a library no
`#[Library]` names, or one whose flags are not a bare `-l`:

```json
{
  "extensions": { "zlib": { "src": "ext/zlib", "link": ["z"] } },
  "applications": [
    { "src": "src/app", "output": "bin/app", "extensions": ["zlib"] }
  ]
}
```

Both sources are deduped by `-l<name>`, so declaring a library twice is harmless. An
application may only name an extension that the top-level `extensions` map declares — naming
an unknown one fails the build. See [`modules.md`](modules.md) for the full flow.

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
// ssize_t write(int fd, const void *buf, size_t n)
#[Library('c'), Symbol('write')]
function write(#[CType('int')] int $fd, string $buf,
               #[CType('size_t')] int $n): int { return 0; }

// char *getenv(const char *name) — a pointer, so no integer token
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

## Ownership — checked, never lowered

`Borrow`, `BorrowMut`, `Take`, `Give` and `StaticPtr` record who owns a pointer across the
boundary:

```php
#[Library('c'), Symbol('malloc'), Give]
function malloc(#[CType('size_t')] int $n): \Ffi\Ptr {}   // caller must free

#[Library('c'), Symbol('free')]
function free(#[Take] \Ffi\Ptr $p): void {}               // callee takes it

#[Library('c'), Symbol('strlen')]
function strlen(#[Borrow] string $s): int {}              // callee must not free
```

⚠ **Nothing is freed on your behalf.** These are *checked*, not lowered — they emit no code at
all. What the compiler enforces:

| rule | |
|---|---|
| Every `Ffi\` attribute needs a `#[Ffi\Symbol]` on the same declaration | there is no C callee to describe otherwise |
| `Give` and `StaticPtr` are mutually exclusive | the callee cannot both hand ownership over and keep it forever |
| `Borrow` / `BorrowMut` / `Take` — at most one per parameter | one parameter, one ownership story |
| ownership only on a pointer-carrying parameter / return | a number has no lifetime |
| `Take` never on a `string`; `Give` never on a `string` return | see below |

The last row is the one that is safety rather than tidiness. A PHP string is **refcount-owned**
— its rc word sits before the bytes — so handing one to C's `free()` corrupts the allocator's
metadata. A C buffer is the mirror image: it has no rc header, so letting `rc_release` reach it
corrupts the heap. Declare either as `\Ffi\Ptr` and copy across the boundary (see *Memory*).

Repeats are not rejected: Zend does not enforce them for userland attributes at compile time,
and diverging from it here buys nothing.

There is no runtime `Ffi\dlopen` / `dlsym` / `call(...)`. That layer was removed — it bottomed
out on primitives that stub-linked to 0 — and the static `#[Library, Symbol]` path is the
supported, zero-overhead one.
