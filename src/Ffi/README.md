# Ffi

Foreign-function-interface surface: attributes that bind a PHP function to a C
symbol, plus an opaque-pointer type. End-user guide: [`docs/ffi.md`](../../docs/ffi.md).

## Public surface

Attributes (wired into codegen):

- `Ffi\Library(string $name, ?string $version = null)` — names the library the
  symbol lives in. The name is documentation today; the actual `-l<lib>` link
  flag comes from the manifest's `extensions` (libc needs none).
- `Ffi\Symbol(string $name)` — names the C symbol. A decorated **free function**
  is an extern forwarder: the compiler emits a direct call to the symbol and
  ignores the (Zend-fallback) PHP body. Only top-level function declarations are
  lowered this way — `#[Symbol]` on a method does nothing.
- `Ffi\CType('int')` at FUNCTION level — the C return is a 32-bit `int`, so the
  wrapper sign-extends it. The only token acted on; see `CType.php` for the
  hazard.
- `Ffi\Weak` — emit `declare extern_weak`, so a symbol absent on this target
  resolves to null instead of failing the link.

Types:

- `Ffi\Ptr` — opaque pointer (`readonly int $address`): `::null()`, `isNull()`,
  `offset(int)`. A raw handle for things you never dereference from PHP (a
  `FILE*`, a dir stream). No automatic free — the caller owns the lifetime.

## Declared, NOT consumed by codegen

- `Ffi\Variadic(int $fixed)` — the named-param count of a C variadic callee.
  Inert: the emitter keys variadic arity off the C symbol name instead
  (`EmitLlvmCalls::ffiVariadicFixed`, which knows only `fcntl`).
- `Ffi\Ownership` (`Borrow` / `BorrowMut` / `Take` / `Give` / `StaticPtr`) —
  ownership hints to drive free / refcount at the boundary once the memory plan
  extends to FFI. Advisory only; nothing is freed on your behalf.
- `Ffi\CType` with any token other than `'int'`, and in parameter position.

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
stub-link to 0 (non-functional), and the static
`#[Library, Symbol]` path above is the supported, zero-overhead one. A real
io/os runtime will be (re)built deliberately when needed.
