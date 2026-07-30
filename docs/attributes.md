# Manticore Attribute Reference

PHP 8 attributes consumed by the Manticore compiler at AOT time. All attributes
are read during compilation; none allocate at runtime. Unknown attributes are
ignored.

Two namespaces:

- `Manticore\Attr\*` — layout, erasure and parameter semantics. Documented here.
- `Ffi\*` — foreign function interop. Documented in [`ffi.md`](ffi.md); this page
  does not repeat it.

⚠ Attribute names are matched **textually**, in three spellings each: bare
(`#[Struct]`), `Attr\Struct`, and `Manticore\Attr\Struct`. Two consequences.
First, a `use` statement is optional — the bare form is what the test suite and
the compiler's own source use. Second, `Manticore\Attr\TypeDef` is accepted by
name even though **no such class file exists** (`src/Manticore/Attr/` holds only
`Struct`, `RefOut` and `CellArg`), so write `#[TypeDef]` bare.

---

## Memory Layout

By default every class instance carries a **16-byte header** — a pointer to the
class descriptor at offset 0, and the packed refcount word at offset 8 — used for
polymorphic dispatch, `instanceof`, refcounting and the cycle collector. The
class id itself lives one indirection away, at descriptor offset 0. Exact layout:
[`design/memory-abi.md`](design/memory-abi.md).

Two attributes opt out, by degree:

- `#[Struct]` drops the **header** — still a heap record, but a bare one.
- `#[TypeDef]` drops the **object** — the class erases to the single value it
  wraps, and costs nothing at all.

### `Manticore\Attr\Struct`

Mark a class as a value-type record: no header at all, fields start at offset 0,
all method calls resolve statically, and no retain / release / cycle-collector
hook ever touches it. A 3-field class drops from **40 bytes to 24** — a 40%
saving, and the bigger win is the refcount traffic that disappears with it.

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

Rules you must keep — **the compiler does not check them.** Unlike `#[TypeDef]`,
which has a dedicated soundness gate, `#[Struct]` is read once and only sets
`ClassDef::$isStruct`; nothing diagnoses a misuse, so breaking these produces
wrong code rather than an error:

- no `extends`, and nothing may `extend` it — there is no class id to resolve a
  subtype against.
- no `abstract` methods — every call must dispatch statically.
- no `instanceof`, no virtual dispatch, no use as a `mixed` value.
- `final` strongly recommended, since it is the only thing that will actually
  stop a subclass.

Use for AST nodes, span markers, coordinate pairs, parser tokens — anywhere the
type is monomorphic and short-lived.

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

`repr` names the machine form (`i8`/`i16`/`i32`/`i64`, `u8`/`u16`/`u32`/`u64`,
`f32`/`f64`), and it **costs what it says**: a property declared as one occupies
exactly its repr — 1 byte for `i8`/`u8`, 2 for `i16`/`u16`, 4 for `i32`/`u32`/`f32`
— aligned to its own width.

```php
final class Pixel {
    public function __construct(
        public U8 $r, public U8 $g, public U8 $b, public U8 $a,
    ) {}
}
// 24 bytes, not 48.
```

A property slot is the only place the promise can be kept: in a register a narrow
type buys nothing, because a register is 64 bits either way. Loads widen back to
the carrier (sign-extending for the signed reprs, zero-extending for the unsigned,
`fpext` for an `f32`), so the value your program sees is unchanged — only the bytes
on the heap differ.

Omit `repr` for a plain newtype: then the property is a full word, and the type is
about meaning, not layout.

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

## Parameter semantics

### `Manticore\Attr\RefOut`

Mark a by-reference (`&$x`) parameter as pure **output** — the callee assigns it
fresh and never reads the incoming value. Unlike a plain by-ref param (IN-OUT,
e.g. `sort()`), a `#[RefOut]` arg is safe for the caller to **auto-vivify**: an
undefined variable passed to it is defined with the parameter's element type
before the call, and the read-back carries that static type instead of erasing
to `unknown`. This is what lets `preg_match($p, $s, $m)` work with no prior
`$m = []`.

```php
#[\Attribute(\Attribute::TARGET_PARAMETER | \Attribute::TARGET_FUNCTION | \Attribute::TARGET_METHOD)]

// on the parameter (natural form)
function preg_match(string $p, string $s, #[RefOut] array &$matches = []): int;

// on the function, by name (handy for generated / multi-out signatures)
#[Manticore\Attr\RefOut('matches')]
function preg_match(string $p, string $s, array &$matches = []): int;
```

Core compiler semantics (not FFI-specific); used by the `preg_*` family.

---

### `Manticore\Attr\CellArg`

Mark an `array` parameter as **element-consuming**: the callee reads element
*values* (casts, concatenates, stringifies them) and therefore needs a
self-describing cell-tagged array, not the raw element representation a concrete
`vec[string]` / `vec[int]` caller would hand it.

```php
function vsprintf(string $format, #[\Manticore\Attr\CellArg] array $args): string
```

Why it is needed: a bundled-stdlib function is compiled **once**, so its `array`
parameter carries a fixed element repr in the `.sig` (`mixed[]`). A caller with a
concrete-element array does not match, and the raw slots then decode as tagged
cells — garbage. `#[CellArg]` tells the **call site** to box each element before
the call, so the callee always sees cells.

Do **not** put it on an element-*preserving* passthrough (`array_merge`,
`array_combine` move slots raw) — those must keep the raw repr.

- **Target:** `TARGET_PARAMETER | TARGET_FUNCTION | TARGET_METHOD`
- **Constructor:** variadic `string ...$params` — the function-level form names
  the parameters, which is the portable spelling because a parameter-position
  attribute alone does not survive the `.sig`.

The PHP signature stays identical (`array $args`); this is compiler metadata
carried in the `.sig` exactly like `byref` / `refout`. Used across
`src/Runtime/Stdlib/{Format,Csv,Encoding,Sockets}.php`.

---

## FFI

The `Ffi\*` attributes — `#[Library]`, `#[Symbol]`, `#[CType]`, `#[Weak]`,
`#[Variadic]`, and the `Ownership` family (checked, never lowered) — are
documented in **[`ffi.md`](ffi.md)**, together with the type vocabulary,
linking and the raw-buffer memory rule.
