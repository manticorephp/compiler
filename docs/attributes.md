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
used for polymorphic dispatch and `instanceof`. Two attributes opt out, by
degree:

- `#[Struct]` drops the **header** — still a heap record, but a bare one.
- `#[TypeDef]` drops the **object** — the class erases to the single value it
  wraps, and costs nothing at all.

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

### `TypeDef`

Where `#[Struct]` removes the object *header*, `#[TypeDef]` removes the *object*.

A `#[TypeDef]` class is **erased** to the single readonly value it wraps. The
class costs nothing at runtime: no allocation, no refcount, no class id, no
header, no indirection. `U8` *is* an `i64`; `Email` *is* a string pointer.
`$byte->value` is the value itself (no load, no offset), and `$byte->add($x)` is
a direct call taking the raw scalar.

You still get everything the object model was for: a named type, its own
methods, and type-checking at every boundary. You just stop paying for it.

- **Target:** `TARGET_CLASS`
- **Constructor:** `repr` (optional string).

#### Two shapes

**A machine type** — nothing to compute, so the property is promoted and that
is the whole class:

```php
#[TypeDef(repr: 'u8')]
final class U8
{
    public function __construct(public readonly int $value) {}

    public function add(U8 $other): U8
    {
        return new U8(($this->value + $other->value) & 0xFF);
    }
}

$sum = (new U8(200))->add(new U8(100));   // 44
```

`new U8(200)` allocates nothing. `U8::add` compiles to two `i64` arguments and
integer arithmetic — byte for byte what a hand-written `add_u8(int, int): int`
would emit.

**A refinement type** — the value must be validated or sanitised first, so the
class declares a `__invoke` **normaliser**:

```php
#[TypeDef]
final class Email
{
    public readonly string $address;

    // Zend needs this to build a real object when `php` runs the same source.
    // The compiler never lowers it — see "Why the PHP body is real" below.
    public function __construct(string $raw)
    {
        $this->address = $this($raw);
    }

    public function __invoke(string $raw): string
    {
        $raw = strtolower(trim($raw));
        if (strpos($raw, '@') === false) {
            throw new InvalidArgumentException('not an email: ' . $raw);
        }
        return $raw;
    }

    public function domain(): string
    {
        return substr($this->address, strpos($this->address, '@') + 1);
    }
}

function deliver(Email $to): string { ... }   // re-validates NOTHING
```

`new Email($raw)` lowers to a direct call to the normaliser. The validation runs
**once**, at construction; from then on the *type* carries the proof. `deliver()`
takes a raw string pointer, not a wrapper — a refinement type that costs nothing.

The constructor is not where the meaning lives, and it may not be: PHP
constructors do not return values. `__invoke` is an ordinary function with an
ordinary `return`, and its `carrier → carrier` signature is checked by the
compiler.

#### The carrier

The **carrier** is the type of the one property: `int`, `float` or `string`. It
is what the value really is. Reading the property is where the TypeDef ends and
a plain scalar begins — `$byte->value + 1` is ordinary integer arithmetic and is
always allowed.

`repr` names the intended machine form (`i8`/`i16`/`i32`/`i64`, `u8`/`u16`/`u32`/
`u64`, `f32`/`f64`). Today it is **declarative**: it is validated against the
carrier and recorded, but the value still occupies a full `i64`/`double`. It
becomes load-bearing when narrow layouts land. Omit it for a plain newtype.

#### What the compiler refuses, and why

An erased value is invisible — and therefore correct — everywhere the program
treats it as a *value*. It becomes visible, and would disagree with `php`, at
exactly the places where it is treated as an *object*. Each of these is a hard
error naming the class, the site and the fix:

| you wrote | why it is refused |
|---|---|
| `$a === $b` | PHP compares object **identity**; two `new U8(5)` are not identical. Compare `$a->value === $b->value`. (`==` is fine — PHP's loose object compare is field-wise, so it agrees.) |
| `$a + $b` | PHP has no operator overloading: `php` raises `TypeError`. The erased form would quietly compute a number instead. Operate on the carrier. |
| `$x instanceof U8` | there is no class id left to test. |
| `var_dump($x)` | `php` prints `object(U8)#1 { … }`; the erased form is a number. Same for `print_r` / `var_export` / `get_class` / `is_object` / `json_encode` / `serialize` / `gettype`. |
| a `mixed` slot | boxing into a tagged cell drops the type, and everything downstream sees a bare number. Declare the parameter / property / return as the TypeDef. |

A **typed** container is fine and stays unboxed: `/** @var U8[] $bytes */` is a
vector of raw scalars, not of boxed cells.

Refusal, not a quiet fallback to a heap object — a TypeDef that silently re-boxed
would silently give back the allocation it exists to remove.

#### Why the PHP body is real

The class body is ordinary PHP, and `php` executes it as a genuine object — the
honest arithmetic, the honest validation. That is deliberate: Manticore's cold
bootstrap runs `src/` under Zend, so the language may only be extended in ways
Zend ignores. An attribute is inert to Zend; the body is not.

So there is exactly **one** implementation. Native runs the very `__invoke` and
the very methods the programmer wrote — only unboxed. The two paths cannot drift,
because there is nothing to drift from.

#### Rules

- `final`, no `extends` — an erased value has no class id to dispatch on.
- exactly **one** property, `readonly`, typed `int` / `float` / `string`.
- either promote it (`__construct(public readonly int $value) {}`) **or** declare
  `__invoke(T $raw): T` plus the one-line constructor Zend needs — not both: a
  promoted property stores the raw argument, so the normaliser would never run.

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
