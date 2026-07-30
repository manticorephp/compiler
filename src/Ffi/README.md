# Ffi

Foreign-function-interface surface: attributes that bind a PHP function to a C
symbol, plus an opaque-pointer type. End-user guide: [`docs/ffi.md`](../../docs/ffi.md).

## Public surface

Attributes (wired into codegen):

- `Ffi\Library(string $name, ?string $version = null)` — names the library the
  symbol lives in, and that is what puts it on the link line: `pkg-config`, then
  `<name>-config`, then a bare `-l<name>`. `'c'` is implicit. The requirement is
  carried in the module's `.sig`, so a program reaching the wrapper through a
  prebuilt `.o` still links what it calls.
- `Ffi\Symbol(string $name)` — names the C symbol. A decorated **free function**
  is an extern forwarder: the compiler emits a direct call to the symbol and
  ignores the (Zend-fallback) PHP body. Free functions only — `#[Symbol]` on a
  method is a compile error.
- `Ffi\CType(string $type)` — the C type, at FUNCTION level for the return and
  on individual parameters. Closed vocabulary (`void` `bool` `char` `uchar`
  `short` `ushort` `int` `uint` `long` `ulong` `longlong` `ulonglong` `size_t`
  `ssize_t` `off_t` `float` `double` `ptr`, plus the multi-word C spellings);
  an unknown token is a compile error. The table lives in
  `Compile\Mir\FfiCTypes`. See `CType.php` for the sign-extension hazard.
- `Ffi\Weak` — emit `declare extern_weak`, so a symbol absent on this target
  resolves to null instead of failing the link. Darwin's `-Wl,-U` allowance is
  derived from these.
- `Ffi\Variadic(int $fixed)` — the named-param count of a C variadic callee
  (those before the `...`), driving the LLVM variadic call type. Validated
  against the binding's arity.

Checked, but NOT lowered — no code is emitted and nothing is freed:

- `Ffi\Ownership` (`Borrow` / `BorrowMut` / `Take` / `Give` / `StaticPtr`) —
  who owns a pointer across the boundary. The checker enforces placement and
  consistency (see `Ownership.php`); two of its rules are memory-safety rules,
  because a PHP string is refcount-owned and a C buffer has no rc header.

Types:

- `Ffi\Ptr` — opaque pointer (`readonly int $address`): `::null()`, `isNull()`,
  `offset(int)`. A raw handle for things you never dereference from PHP (a
  `FILE*`, a dir stream). No automatic free — the caller owns the lifetime.

## Type mapping

Without a `#[CType]`, the PHP hint decides: `int → i64`, `float`/`double →
double`, `bool → i1`, `void → void`, `string`/`\Ffi\Ptr`/class → `ptr`. A
`#[CType]` overrides it, and the two must agree — an integer token on a
pointer-carrying hint is rejected. The wrapper converts both ways. See
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
