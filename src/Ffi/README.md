# Ffi

Foreign-function-interface surface: attributes that bind a PHP function to a C
symbol, plus an opaque-pointer type. End-user guide: [`docs/ffi.md`](../../docs/ffi.md).

## Public surface

Attributes (wired into codegen):

- `Ffi\Library(string $name, ?string $version = null)` — names the library the
  symbol lives in. The name is documentation today; the actual `-l<lib>` link
  flag comes from the manifest's `extensions` (libc needs none).
- `Ffi\Symbol(string $name)` — names the C symbol. A decorated function is an
  extern forwarder: the compiler emits a direct call to the symbol and ignores
  the (Zend-fallback) PHP body.

Types:

- `Ffi\Ptr` — opaque pointer (`readonly int $address`): `::null()`, `isNull()`,
  `offset(int)`. A raw handle for things you never dereference from PHP (a
  `FILE*`, a dir stream). No automatic free — the caller owns the lifetime.

## Roadmap metadata (declared, NOT yet consumed by codegen)

- `Ffi\CType(string $type)` — exact-width C types (`uint32_t`, `size_t`, …) for
  when PHP's coarse `int`/`string` isn't precise enough at the ABI. Inert today;
  bindings ride 64-bit registers and work on arm64 / x86-64 without it.
- `Ffi\Ownership` (`Borrow` / `BorrowMut` / `Take` / `Give` / `StaticPtr`) —
  ownership hints to drive free / refcount at the boundary once the memory plan
  extends to FFI. Advisory only.

## Type mapping

`int → i64`, `float`/`double → double`, `bool → i1`, `void → void`,
`string`/`\Ffi\Ptr`/class → `ptr`. The wrapper converts both ways. See
[`docs/ffi.md`](../../docs/ffi.md) for the binding model, linking, and the
raw-buffer memory rule.

## Usage

```php
#[\Ffi\Library('c'), \Ffi\Symbol('getpid')]
function getpid(): int {}
```

## Note

A dynamic-dispatch runtime layer (`dlopen` / `dlsym` / `call(...)`) and
`Ptr::read*` were removed: they bottomed out on `manticore_rt_*` primitives that
stub-link to 0 on the no-Rust branch (non-functional), and the static
`#[Library, Symbol]` path above is the supported, zero-overhead one. A real
io/os runtime will be (re)built deliberately when needed.
