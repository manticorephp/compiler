# FFI — calling native C functions

Manticore can call into native C libraries directly. You declare a PHP function,
tag it with two attributes naming the library and the C symbol, and the compiler
emits a direct call to that symbol — no wrapper runtime, no marshalling layer.
This is how the compiler itself reaches libc (`malloc`, `fopen`, `write`, …) and
how extensions bind libraries like zlib or libcurl.

---

## The binding

```php
use Ffi\Library;
use Ffi\Symbol;

#[Library('c'), Symbol('getpid')]
function getpid(): int {}
```

- **`#[Symbol('getpid')]`** names the C symbol. A function tagged with it is an
  **extern forwarder**: the compiler emits `@manticore_getpid` as a thin wrapper
  that calls the C `@getpid` directly, and **ignores the PHP body**.
- **`#[Library('c')]`** names the library the symbol lives in. Today this is
  metadata for the reader; the actual `-l<lib>` link flag comes from the
  manifest (see *Linking* below). `'c'` (libc) needs no flag — it's always
  linked.

Because the body is ignored when compiled, you write an empty (or trivial) body.
That body is the **Zend fallback**: the same source also runs under stock PHP
during the cold bootstrap, where the attribute is inert and the body executes
instead. Keep it a harmless stub (`{}`, `return 0;`, `return '';`).

---

## Type mapping

Each PHP parameter / return type maps to a C ABI type:

| PHP type | C / LLVM type | Notes |
|----------|---------------|-------|
| `int` | `i64` | the native word |
| `float` / `double` | `double` | |
| `bool` | `i1` | |
| `void` | `void` | return only |
| `string` | `ptr` (`char*`) | the pointer to the string bytes |
| `\Ffi\Ptr` | `ptr` (`void*`) | an opaque handle (see below) |
| a class type / untyped | `ptr` | |

The wrapper converts at the boundary: an `int` argument rides as `i64`, a
`string`/`\Ffi\Ptr` is `inttoptr`'d to a real pointer, a `float` is bitcast to
`double`; the C return is converted back (pointer → int carrier, `double` →
bitcast, `bool` → zero-extend). You write ordinary PHP types — the ABI glue is
generated.

```php
#[Library('z'), Symbol('crc32')]
function __ffi_crc32(int $crc, string $buf, int $len): int { return 0; }
//                  ^ i64        ^ char*       ^ i64    -> i64
```

When PHP's type is too coarse for the C side (e.g. a `uint32_t` vs `int`), the
binding still works for the common cases because everything rides a 64-bit
register; an exact-width `#[Ffi\CType('uint32_t')]` annotation exists as metadata
but is not yet consulted by codegen — pass the value you mean and it works on
arm64 / x86-64.

---

## Opaque handles — `\Ffi\Ptr`

For C handles you hold but never dereference from PHP (a `FILE*`, a directory
stream, a library cookie), use `\Ffi\Ptr` — a wrapper over a raw address:

```php
#[Library('c'), Symbol('fopen')]
function fopen(string $path, string $mode): \Ffi\Ptr {}

#[Library('c'), Symbol('fclose')]
function fclose(\Ffi\Ptr $stream): int { return 0; }
```

`\Ffi\Ptr` is just an address — it has **no automatic free**; the caller owns the
lifetime of whatever it points at.

---

## Linking the native library

The C symbol must be resolved at link time:

- **libc / libSystem** (`#[Library('c')]`) — always linked by `cc`. Nothing to do.
- **Any other library** — declare it on an **extension** in the manifest, which
  adds `-l<lib>` at the link step:

  ```json
  {
    "extensions": { "zlib": { "src": "ext/zlib", "link": ["z"] } },
    "applications": [{ "src": "src/app", "output": "bin/app", "extensions": ["zlib"] }]
  }
  ```

  See [`docs/modules.md`](modules.md) for the full extension flow. The native
  library never touches Manticore's arena/refcount heap, so it adds no
  memory-safety surface — it's an ordinary C archive linked by `cc`.

---

## Memory across the boundary

A buffer that comes back from a C allocator (`malloc`, `calloc`, or bytes read
into one) is a **raw pointer with no Manticore string header** — the refcount
runtime must never touch it. Before such a buffer flows into normal PHP code,
**copy it into a real string** so you get an rc-managed, releasable value:

```php
$buf = calloc($size + 1, 1);     // raw libc block — NO rc header
$n   = fread($buf, 1, $size, $fp);
return substr($buf, 0, $n);      // owned, rc-headered MIR string — safe to return
```

`substr` (and friends) allocate a proper headered string; the raw `calloc` block
is left alone (the compiler does not refcount FFI-call results). Returning the
raw buffer directly would let `rc_release` run on a header-less block and corrupt
the heap.

---

## Worked examples

libc, in the compiler's own driver (`src/Manticore/Main.php`):

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

## What's not (yet) wired

The `Ffi` namespace also declares some forward-looking surface that codegen does
not consume today — treat it as metadata / roadmap, not behaviour:

- **`#[Ffi\CType(...)]`** exact-width C types and **`#[Ffi\Borrow]` / `Take` /
  `Give`** ownership hints — advisory only; they don't yet change emission or
  insert frees.
- **Runtime `Ffi\dlopen` / `dlsym` / `call(...)`** — a dynamic-dispatch fallback
  for arities 0–2; the static `#[Library, Symbol]` direct-call path above is the
  supported, zero-overhead one.
