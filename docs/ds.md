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
| `UInt64Array` | `int` (the 64 bits) | 8 bytes | 0..18446744073709551615 |
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

## `UInt64Array`

PHP has no unsigned 64-bit int, so a `UInt64Array` element **reads as the int
with the same 64 bits**: a value of 2^63 or more reads negative. An int
**writes its bits**, so `$a[$i] = $a[$j]` copies any value and hashes, masks
and ids round-trip untouched. A numeric string or a float is a **value**:

```php
$h = new UInt64Array(2);
$h[0] = '18446744073709551615';   // reads as -1
$h[1] = 2 ** 63;                  // reads as PHP_INT_MIN
echo $h->getString(0);            // 18446744073709551615
echo UInt64Array::toDecimal($h->max());
```

- `min()`, `max()` and `UInt64Array::compare(int $a, int $b): int` order
  **unsigned**; `sum()` wraps modulo 2^64.
- `getString(int $index): string` and `UInt64Array::toDecimal(int $bits): string`
  give the unsigned decimal value.
- A string or float outside 0..18446744073709551615 (`'-1'`, `2.0 ** 64`) is a
  `ValueError`; a fractional float is a `TypeError`.
- `UInt64Array<T>` takes a `#[TypeDef(repr: 'u64')]`; an `i64` TypeDef does not
  fit, nor does a `u64` one fit an `Int64Array`.
- **JSON writes the value, not the bits.** JSON numbers have no width, so
  `json_encode($h)` gives `[18446744073709551615, …]` — what a Go, Rust or
  JS-BigInt consumer expects. `toArray()`, `var_dump()`, `print_r()` and
  `serialize()` stay on bits (the in-program contract). The lossless way back is
  `JSON_BIGINT_AS_STRING`, whose decimal strings `fromArray()` accepts:

  ```php
  $back = UInt64Array::fromArray(json_decode($json, true, 512, JSON_BIGINT_AS_STRING));
  ```

  Without the flag, php decodes a value past `PHP_INT_MAX` as a float, which
  `fromArray()` accepts only when it is exact. Under Zend (the polyfill) the
  large values encode as quoted strings — nothing there can write them bare.

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

## `Map`, `Set`, `Vec`

Insertion-ordered containers over native tables (`Map`, `Set`) and a native
buffer of boxed values (`Vec`). Unlike a PHP array the keys are **strict**.

```php
use Manticore\Ds\{Map, Set, Vec};

$m = new Map();
$m->set('a', 1);
$m[2] = 'two';                 // int and string keys stay distinct: "2" !== 2
$m->set($obj, 'by identity');  // an object is a key
foreach ($m as $k => $v) { … } // insertion order
```

| `Map` | |
|---|---|
| `set(mixed $key, mixed $value): void`, `$m[$k] = $v` | insert or overwrite (an overwrite keeps the position) |
| `get(mixed $key, mixed $default = null): mixed`, `$m[$k]` | the value; a missing key returns `$default` when one is passed, else `OutOfBoundsException("Key not found")` |
| `has(mixed $key): bool`, `isset($m[$k])` | key present (a stored `null` still counts) |
| `remove(mixed $key): mixed` | remove and return the value; `OutOfBoundsException("Key not found")` when missing |
| `unset($m[$k])` | remove; a missing key is a no-op |
| `count(): int`, `isEmpty(): bool`, `clear(): void` | |
| `keys(): Vec`, `values(): Vec` | in order |
| `toArray(): array` | see below |

`$m[] = $v` is an `Error("Cannot append to a Map; use set()")`.

| `Set` | |
|---|---|
| `add(mixed $value): void` | add; an existing value keeps its position |
| `has(mixed $value): bool` | |
| `remove(mixed $value): void` | `OutOfBoundsException("Value not found")` when missing |
| `count()`, `isEmpty()`, `clear()` | |
| `union(Set)`, `intersect(Set)`, `diff(Set)`: `Set` | new set; order follows the left operand, then the right for `union` |
| `toArray(): array` | a list |

| `Vec` | |
|---|---|
| `Vec::fromArray(array $values): Vec` | values in iteration order |
| `push(mixed $value): void`, `$v[] = $x` | append |
| `pop(): mixed` | `UnderflowException("Cannot pop from an empty Vec")` when empty |
| `$v[$i]`, `$v[$i] = $x`, `isset($v[$i])` | an `int` index; other types `TypeError("Cannot access offset of type <t> on Vec")`; out of range `OutOfBoundsException("Index invalid or out of range")` |
| `count()`, `isEmpty()`, `clear()`, `toArray()` | |

`unset($v[$i])` is an `Error("Cannot unset a Vec element; use pop()")`.

**Keys** (`Map`, `Set`) — `int`, `string` or object. `"1"` and `1` are
different keys; an object is its own key by identity. Anything else (`float`,
`bool`, `null`, `array`) is a `TypeError("Cannot use a key of type <t> in Map")`
(`... in Set`).

**Order** — iteration is insertion order. `remove` + re-insert moves the key to
the end. Removing during `foreach` is fine. A **compaction** (triggered by an
insert) or `clear()` during `foreach` throws
`RuntimeException("Map modified during iteration")` /
`("Set modified during iteration")`.
`Vec` iteration has no guard: it re-reads the length every step.

**`toArray()` / JSON** — `Map::toArray()` throws
`ValueError("Map::toArray(): keys collide in a PHP array")` when `1` and `"1"`
are both keys, and `TypeError("Map::toArray(): object keys cannot be array keys")`
for an object key. `json_encode($map)` goes through `(object) toArray()`, so it
is **always an object** (`{}` when empty). `Set` and `Vec` encode as JSON
lists. `var_dump` / `print_r` show the entries (`Map`: a list of `[key, value]`
pairs); `serialize` round-trips all three.

**Copies** — assignment shares, `clone` copies the table (shallow: keys and
values are retained, not cloned).

**Cycles** — a cycle through a `Map` / `Set` / `Vec` (`$m->set('self', $m)`)
is collected natively by `gc_collect_cycles()`. Under the Zend polyfill it is
not (the buffers live in a static registry).

### Cost

P1 is **erased**: every operation is a method call over a native table, values
are boxed and keys are not specialised. 1,000,000 insert + lookup + remove,
arm64 macOS (`tools/bench/ds_map_bench.php`; peak is the process peak, so it
accumulates down the list):

| | PHP array | Map / Set |
|---|---|---|
| string keys `"k$i"` | 291 ms | 383 ms |
| int keys | 95 ms | 149 ms |
| objects (`spl_object_id` array vs `Set`) | 96 ms | 165 ms |

Expect to trail a PHP array until P2 (key/value specialisation, inlined
lookups, native `foreach`), which is planned. What `Map` / `Set` give today is
strict keys, object keys without `spl_object_id`, and a defined order contract.

## Polyfill

The classes are ordinary PHP (`prelude/ds.php`, `prelude/ds_map.php`) over a small set of buffer
and table primitives. Natively those primitives are compiler builtins; under Zend they
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
PHP array element costs. `Map` / `Set` / `Vec` keep their buffers in a static
registry, so a cycle through one is not collected under Zend.

## Current limits

- The inline path needs a receiver held in a local variable, an `int` index
  built from locals / constants / `+` `-`, and (for a store) a value of the
  element's own type with no call in it. Anything else is an ordinary
  `offsetGet` / `offsetSet` call — correct, slower.
- `Map` / `Set` / `Vec` are erased (P1): no key or value specialisation, no inline
  lookups, no native `foreach` yet — each access is a method call.
