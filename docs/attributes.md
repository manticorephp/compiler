# Manticore Attribute Reference

PHP 8 attributes consumed by the Manticore compiler at AOT time. All attributes
are read during compilation; none allocate at runtime. Unknown attributes are
ignored.

Two namespaces:

- `Manticore\Attr\*` — project layout and memory layout hints.
- `Ffi\*` — foreign function interop (C library binding, type overrides,
  ownership transfer).

---

## Memory Layout

By default every class instance carries an 8-byte header (class id + flags)
used for polymorphic dispatch and `instanceof`. `#[Struct]` opts a class out
of the header when polymorphism is not needed.

### `Manticore\Attr\Struct`

Mark a class as a value-type record: no class-id header, fields start at
offset 0, all method calls resolve statically. ~33% memory savings on small
records (3-field class drops from 32 to 24 bytes).

- **Target:** `TARGET_CLASS`
- **Constructor:** none.

```php
use Manticore\Attr\Struct;

#[Struct]
final class Span
{
    public function __construct(
        public int $line,
        public int $col,
    ) {}
}
```

Compile-time constraints (rejected during type check):

- no `extends` — there is no class id to resolve a subtype against.
- no `abstract` methods — every call must be statically dispatched.
- a class that `extends` a `#[Struct]` is rejected.
- `final` recommended.

Use for AST nodes, span markers, coordinate pairs, parser tokens — anywhere
the type is monomorphic and short-lived. Do not use for anything that needs
`instanceof` checks or virtual dispatch.

---

## FFI (C Interop)

Bind a PHP function or method directly to a C symbol from a shared library.
The compiler emits a direct LLVM `call` to the resolved symbol; there is no
trampoline and no `FFI::cdef` parsing at runtime.

A binding has three pieces: which library (`#[Library]`), which symbol
(`#[Symbol]` or implicit by function name), and how the C types map to PHP
(`#[CType]` per parameter / return).

### `Ffi\Library`

Names the shared library that exports the bound symbol.

- **Target:** `TARGET_CLASS | TARGET_FUNCTION | TARGET_METHOD`
- **Constructor:**
  - `string $name` — library base name (`'c'`, `'curl'`, `'sqlite3'`),
    resolved via the platform loader (`libc.so.6`, `libcurl.dylib`, etc.).
  - `?string $version = null` — optional soname version hint
    (`'4'` → `libcurl.so.4`).

```php
use Ffi\{Library, Symbol};

#[Library('c'), Symbol('getpid')]
function getpid(): int {}
```

When applied to a class, every method of that class inherits the library and
need not repeat the attribute:

```php
#[Library('curl')]
final class Curl
{
    #[Symbol('curl_easy_init')]
    public static function init(): \Ffi\Ptr {}

    #[Symbol('curl_easy_cleanup')]
    public static function cleanup(\Ffi\Ptr $handle): void {}
}
```

### `Ffi\Symbol`

Explicit C symbol name. Use when the PHP function name differs from the C
symbol (e.g. when the C name is verbose or namespaced with prefixes).

- **Target:** `TARGET_FUNCTION | TARGET_METHOD`
- **Constructor:** `string $name`.

```php
#[Library('c'), Symbol('write')]
function sysWrite(int $fd, \Ffi\Ptr $buf, int $count): int {}
```

If omitted, the PHP function name is used verbatim as the C symbol.

### `Ffi\CType`

Disambiguates the C-side scalar type when PHP `int` could be `int32_t`,
`size_t`, `off_t`, etc. The compiler uses this to pick the right LLVM integer
width and signedness, and to emit truncations / extensions at the call site.

- **Target:** `TARGET_PARAMETER` (also applies to return position).
- **Constructor:** `string $type`.

Recognised tokens: `int`, `int8_t`..`int64_t`, `uint8_t`..`uint64_t`,
`size_t`, `ssize_t`, `off_t`, `char`, `void`. PHP `string` maps to `char*`
implicitly; `\Ffi\Ptr` is `void*`.

```php
#[Library('c'), Symbol('write')]
function write(
    #[CType('int')]    int $fd,
                       \Ffi\Ptr $buf,
    #[CType('size_t')] int $count,
): #[CType('ssize_t')] int {}
```

Omit `#[CType]` only when the PHP type maps unambiguously: `bool` → `_Bool`,
`float` → `double`, `string` → `const char*`, `\Ffi\Ptr` → `void*`.

---

## FFI Ownership

Per-parameter / per-return hints describing who owns the pointer crossing
the FFI boundary. Borrowed from Rust's reference rules. The compiler uses
them to insert the right free / refcount / drop call at the scope boundary
once the memory plan lands.

Today these are advisory metadata. `Take` and `Give` will lower to the
chosen allocator's free call once the runtime memory model is finalised.
Write them now; they will start being enforced without source changes.

### `Ffi\Borrow`

Caller still owns the pointer; callee may read it but must not free.

- **Target:** `TARGET_PARAMETER`
- **Constructor:** `?string $lifetime = null` — optional name to tie this
  borrow to another `Borrow` / return value (`'a'`, `'buf'`).

```php
#[Library('c'), Symbol('strlen')]
function strlen(#[Borrow] \Ffi\Ptr $s): #[CType('size_t')] int {}
```

### `Ffi\BorrowMut`

Caller still owns; callee may write through the pointer but must not free.

- **Target:** `TARGET_PARAMETER`
- **Constructor:** `?string $lifetime = null`.

```php
#[Library('c'), Symbol('memset')]
function memset(
    #[BorrowMut] \Ffi\Ptr $dest,
    #[CType('int')] int $byte,
    #[CType('size_t')] int $n,
): \Ffi\Ptr {}
```

### `Ffi\Take`

Callee takes ownership of the pointer. Caller must not use it after the call.

- **Target:** `TARGET_PARAMETER`
- **Constructor:** none.

```php
#[Library('curl'), Symbol('curl_easy_cleanup')]
function curl_easy_cleanup(#[Take] \Ffi\Ptr $handle): void {}
```

### `Ffi\Give`

Function returns an owned pointer; caller is responsible for freeing it.

- **Target:** `TARGET_FUNCTION | TARGET_METHOD` (applies to return value).
- **Constructor:** none.

```php
#[Library('c'), Symbol('strdup'), Give]
function strdup(#[Borrow] \Ffi\Ptr $s): \Ffi\Ptr {}
```

### `Ffi\StaticPtr`

Function returns a pointer to static / global memory. Nobody frees it.

- **Target:** `TARGET_FUNCTION | TARGET_METHOD`
- **Constructor:** none.

```php
#[Library('c'), Symbol('getenv'), StaticPtr]
function getenv(#[Borrow] \Ffi\Ptr $name): \Ffi\Ptr {}
```

| Want                       | Param attribute | Return attribute |
|----------------------------|-----------------|------------------|
| Read-only view             | `#[Borrow]`     | —                |
| Mutate buffer in place     | `#[BorrowMut]`  | —                |
| Hand ownership to C        | `#[Take]`       | —                |
| Receive owned ptr from C   | —               | `#[Give]`        |
| Receive static / interned  | —               | `#[StaticPtr]`   |

---

## Example: full manifest + FFI binding

Project manifest:

```php
<?php
// manifest.php
use Manticore\Attr\{Project, Module, Entry};

#[Project(name: 'curl-fetch')]
final class Manifest
{
    #[Module(path: 'src')]
    public string $src;

    #[Module(path: 'src/Bindings')]
    public string $bindings;

    #[Entry]
    public string $main = 'src/main.php';
}
```

Struct for a parsed URL — no header overhead, no virtual dispatch:

```php
<?php
// src/Url.php
namespace App;

use Manticore\Attr\Struct;

#[Struct]
final class Url
{
    public function __construct(
        public string $scheme,
        public string $host,
        public int    $port,
        public string $path,
    ) {}
}
```

curl binding — class-level `#[Library]`, per-method `#[Symbol]`, ownership
annotated:

```php
<?php
// src/Bindings/Curl.php
namespace App\Bindings;

use Ffi\{Library, Symbol, CType, Borrow, Take, Give, StaticPtr};

#[Library('curl', version: '4')]
final class Curl
{
    #[Symbol('curl_easy_init'), Give]
    public static function init(): \Ffi\Ptr {}

    #[Symbol('curl_easy_setopt')]
    public static function setopt(
        #[Borrow] \Ffi\Ptr $handle,
        #[CType('int')] int $option,
        #[Borrow] \Ffi\Ptr $value,
    ): #[CType('int')] int {}

    #[Symbol('curl_easy_perform')]
    public static function perform(#[Borrow] \Ffi\Ptr $handle): #[CType('int')] int {}

    #[Symbol('curl_easy_cleanup')]
    public static function cleanup(#[Take] \Ffi\Ptr $handle): void {}

    #[Symbol('curl_easy_strerror'), StaticPtr]
    public static function strerror(#[CType('int')] int $code): \Ffi\Ptr {}
}
```

Entry point:

```php
<?php
// src/main.php
use App\Bindings\Curl;

$h = Curl::init();
if ($h->isNull()) {
    fwrite(STDERR, "curl init failed\n");
    exit(1);
}

$rc = Curl::perform($h);
if ($rc !== 0) {
    $msg = Curl::strerror($rc);     // borrowed, do not free
    fwrite(STDERR, $msg->readCString() . "\n");
}

Curl::cleanup($h);                  // ownership transferred to C; $h dead
```

Build:

```bash
manticore compile manifest.php -o curl-fetch
./curl-fetch
```
