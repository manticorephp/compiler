# Typed arrays — `Manticore\Ds`

Fixed-width, statically typed arrays over one native buffer. A PHP array stores
every element as a tagged cell behind a hash/vector buffer; a `Manticore\Ds`
array stores elements at their declared width in one contiguous block and reads
them back as `int`, `float` or `bool`.

This is a superset feature: Zend has no such classes. The same source ships as
a pure-PHP polyfill ([below](#polyfill)), so a program using them still runs
under `php`.

```php
use Manticore\Ds\Int32Array;

$offsets = new Int32Array(1024);
$offsets[0] = 17;
$offsets->insert(1, 2, -1);          // open two slots holding -1 before index 1
echo $offsets[0] + $offsets[1];      // 16
```

## Classes

| Class | Element | Width | Range |
|---|---|---|---|
| `Int8Array` | `int` | 1 byte | -128..127 |
| `Int16Array` | `int` | 2 bytes | -32768..32767 |
| `Int32Array` | `int` | 4 bytes | -2147483648..2147483647 |
| `Int64Array` | `int` | 8 bytes | `PHP_INT_MIN`..`PHP_INT_MAX` |
| `UInt8Array` | `int` | 1 byte | 0..255 |
| `UInt16Array` | `int` | 2 bytes | 0..65535 |
| `UInt32Array` | `int` | 4 bytes | 0..4294967295 |
| `Float32Array` | `float` | 4 bytes | IEEE-754 binary32 |
| `Float64Array` | `float` | 8 bytes | IEEE-754 binary64 |
| `BitArray` | `bool` | 1 bit | |

All are `final` and extend `Manticore\Ds\TypedArray`, which implements
`ArrayAccess`, `Countable`, `IteratorAggregate` and `JsonSerializable`.

There is no `UInt64Array` (PHP has no unsigned 64-bit int) and no typed string
array.

## API

| Method | |
|---|---|
| `__construct(int $size = 0)` | `$size` zeroed elements |
| `static fromArray(array $values): static` | values in iteration order; keys are ignored |
| `toArray(): array` | a list of the elements |
| `count(): int`, `getSize(): int` | number of elements |
| `setSize(int $size): void` | grow (zero-filled) or shrink |
| `insert(int $at, int $count, $value = 0): void` | open `$count` elements holding `$value` before `$at`; `$at === count()` appends |
| `push($value): void`, `$a[] = $v` | append one element |
| `pop(): mixed` | remove and return the last element; `UnderflowException("Cannot pop from an empty <Class>")` when empty |
| `remove(int $at, int $count): void` | remove `$count` elements; the tail moves down |
| `fill($value, int $from = 0, ?int $to = null): void` | store `$value` into `[$from, $to)` |
| `copyFrom(TypedArray $src, int $srcAt, int $dstAt, int $count): void` | copy between two arrays of the SAME class (overlap-safe on one array) |
| `indexOf($value, int $from = 0): int` | first position holding `$value`, or `-1` |
| `sum()` | sum of the elements — `int` for the integer arrays (wraps past the int range), `float` for the float arrays, the number of set bits for `BitArray` |
| `min()`, `max()` | smallest / largest element of an integer or float array; `ValueError("<Class>::min(): array must contain at least one element")` when empty |
| `equals(TypedArray $other): bool` | same class, same length, equal elements (a `NAN` equals nothing) |
| `$a[$i]`, `$a[$i] = $v`, `isset($a[$i])`, `unset($a[$i])` | element access |
| `foreach ($a as $i => $v)` | by value, in order |

Appending is amortised O(1) (capacity doubles); an insert or removal in the
middle is one `memmove` of the tail.

## Cost

`$a[$i]` and `$a[$i] = $v` on a typed array held in a local compile to a bounds
test plus one load / store of the element width — no call, no boxing. A
10M-iteration `$a[$i] = $a[$i] + 1` loop over `Int32Array` runs about 4x faster
than the same loop over a PHP `array<int, int>` (12 ms vs 47 ms, arm64 macOS).
An index out of range, or a value outside a narrow kind's range, leaves the
inline path and throws from the method.

## Semantics

**Index** — as `SplFixedArray`: an `int`; a canonical integer string (`"12"`),
a `float` or a `bool` is converted; anything else is a
`TypeError("Cannot access offset of type <t> on <Class>")`. Out of range is an
`OutOfBoundsException("Index invalid or out of range")`. `$a[] = $v` appends;
a value that fails the element rules throws and leaves the size alone.

**Value** — converted like a non-strict typed parameter, and **never wrapped
silently**:

- int kinds take an `int`, a `bool`, an integral `float` (`3.0`), or a numeric
  string naming an integer (`"12"`, `"1e2"`). A fractional float, a non-numeric
  string, `null`, an array or an object is a `TypeError`. A value outside the
  element range is a
  `ValueError("Value 300 is out of range for Int8Array (-128..127)")`.
- float kinds take a `float`, an `int`, a `bool` or a numeric string.
  `Float32Array` stores the value rounded to binary32 (ties to even) and a read
  returns the rounded value: `0.1` reads back as `0.10000000149011612`; a
  magnitude past the binary32 range stores `INF`.
- `BitArray` takes any scalar and stores its truthiness.

`indexOf()` answers `-1` for a value outside the element range and throws the
same `TypeError` as a store for a value of the wrong type.

**`isset` / `unset`** — a fixed-width slot cannot be absent: `isset($a[$i])` is
`true` for every in-range index, and `unset($a[$i])` zeroes the element
(`0` / `0.0` / `false`).

**Copies** — an array is an object: assignment shares it, `clone` copies the
buffer.

**Dumps and serialization** — `var_dump`, `print_r`, `serialize` / `unserialize` and
`json_encode` (through `jsonSerialize()`) present the array as the list
`toArray()` returns.

## `ByteBuffer`

A `ByteBuffer` is a typed array of bytes (0..255, as `UInt8Array`; a class of its
own) — every method above works on it, `$b[$i]`
is one byte — with typed reads and writes at a **byte offset**:

```php
use Manticore\Ds\ByteBuffer;

$b = ByteBuffer::fromString($packet);
$length = $b->getUInt16(2, true);        // big-endian (network order)
$stamp  = $b->getInt64(4);               // little-endian by default
$b->setFloat32(12, 0.5);
$b->write(16, "tail");
fwrite($sock, $b->toString());
```

| | |
|---|---|
| `ByteBuffer::fromString(string $bytes): ByteBuffer` | a buffer holding a copy of the bytes |
| `toString(int $offset = 0, ?int $length = null): string` | the bytes `[$offset, $offset + $length)`; to the end when `$length` is null |
| `write(int $offset, string $bytes): void` | copy a string over the buffer |
| `getInt8` / `getUInt8(int $offset): int` | one byte |
| `getInt16` / `getUInt16` / `getInt32` / `getUInt32` / `getInt64(int $offset, bool $bigEndian = false): int` | an integer of that width |
| `getFloat32` / `getFloat64(int $offset, bool $bigEndian = false): float` | an IEEE-754 float |
| `setInt8` … `setInt64(int $offset, int $value, bool $bigEndian = false): void` | store an integer; a value outside the width is a `ValueError("Value 70000 is out of range for uint16 (0..65535)")` |
| `setFloat32` / `setFloat64(int $offset, float $value, bool $bigEndian = false): void` | store a float (`setFloat32` rounds to binary32) |

An access that runs past the end is an
`OutOfBoundsException("Index invalid or out of range")`; nothing grows the
buffer but `setSize`, `push` and `$b[] = $byte`. A typed read costs about
5 ns — the same as composing the value from `ord()` calls by hand, and some
60 times less than `unpack()` at an offset.
## `#[TypeDef]` and typed arrays

The `repr` set of [`#[TypeDef]`](attributes.md#typedef) (`i8` … `i64`, `u8` …
`u32`, `f32`, `f64`) is the same set of machine widths, and a typed array can
be **bound** to such a class: the element is stored at the array's width and
read back AS the class — the same scalar, named, with its methods.

```php
#[TypeDef(repr: 'u16')]
final class TokenKind
{
    public function __construct(public readonly int $value) {}
    public function isComment(): bool { return $this->value === 7 || $this->value === 8; }
}

final class Tokens
{
    /** @var UInt16Array<TokenKind> */
    public UInt16Array $kinds;
}

/** @var UInt16Array<TokenKind> $kinds */
$kinds = new UInt16Array($n);
$kinds[$i] = new TokenKind(7);        // a 2-byte store; no object exists
if ($kinds[$i]->isComment()) { … }    // a 2-byte load and a direct call
$last = $kinds->pop();                // a TokenKind
```

The binding is written in a docblock (`@var` on a local or a property,
`@param`, `@return`), like every other [generic](generics.md). It costs
nothing at run time: `get_class($kinds)` is still `Manticore\Ds\UInt16Array`.
An array with no binding reads plain `int` / `float`, as before. `foreach`
over a bound array still yields the plain scalar.
## Polyfill

The classes are ordinary PHP (`prelude/ds.php`) over a small set of buffer
primitives. Natively those primitives are compiler builtins; under Zend they
are PHP functions that keep each buffer in a PHP array. Both are generated from
the same files, so the polyfill behaves identically by construction — it is
the oracle the native implementation is tested against.

```bash
php tools/ds_polyfill.php            # writes build/ds-polyfill (composer package manticorephp/ds)
```

A program that `require`s the package's `src/bootstrap.php` (or installs it
through Composer) runs unchanged under `php` and under the compiler; natively
the bootstrap is a no-op because the classes are built in.

The polyfill is for portability, not speed: under Zend an element costs what a
PHP array element costs.

## Current limits

- The inline path needs a receiver held in a local variable, an `int` index
  built from locals / constants / `+` `-`, and (for a store) a value of the
  element's own type with no call in it. Anything else is an ordinary
  `offsetGet` / `offsetSet` call — correct, slower.
