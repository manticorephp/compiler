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
| `remove(int $at, int $count): void` | remove `$count` elements; the tail moves down |
| `fill($value, int $from = 0, ?int $to = null): void` | store `$value` into `[$from, $to)` |
| `copyFrom(TypedArray $src, int $srcAt, int $dstAt, int $count): void` | copy between two arrays of the SAME class (overlap-safe on one array) |
| `indexOf($value, int $from = 0): int` | first position holding `$value`, or `-1` |
| `$a[$i]`, `$a[$i] = $v`, `isset($a[$i])`, `unset($a[$i])` | element access |
| `foreach ($a as $i => $v)` | by value, in order |

Appending is amortised O(1) (capacity doubles); an insert or removal in the
middle is one `memmove` of the tail.

## Semantics

**Index** — as `SplFixedArray`: an `int`; a canonical integer string (`"12"`),
a `float` or a `bool` is converted; anything else is a
`TypeError("Cannot access offset of type <t> on <Class>")`. Out of range is an
`OutOfBoundsException("Index invalid or out of range")`. `$a[] = $v` is an
`Error` — the size only changes through `setSize` / `insert` / `remove`.

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

**Dumps and serialization** — `var_dump`, `serialize` / `unserialize` and
`jsonSerialize()` present the array as the list `toArray()` returns.

## `#[TypeDef]` and typed arrays

The `repr` set of [`#[TypeDef]`](attributes.md#typedef) (`i8` … `i64`, `u8` …
`u32`, `f32`, `f64`) is the same set of machine widths. A typed array is the
container to keep such values in: store `$id->value`, rebuild with
`new Id($a[$i])` — both sides are erased, so the round trip costs nothing.

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

- Element access compiles to a method call; the inline load/store for a
  statically known receiver is not in yet.
- `json_encode($typedArray)` does not call `jsonSerialize()` yet (issue #94) —
  encode `$a->toArray()`.
- `print_r($typedArray)` prints no elements (issue #57).
- `$m[$i] = $v` where `$m` is a `mixed` value holding a typed array crashes
  (issue #95) — keep the receiver typed.
