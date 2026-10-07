<?php

// Manticore\Ds — typed fixed-width arrays over the native buffer runtime
// (`__mc_nbuf_*`, MemoryAbi BUF_*). DEMAND-GATED (Main.php): only a program
// that mentions `Ds\` carries it.
//
// The same file is the `manticorephp/ds` polyfill under Zend, where the
// `__mc_nbuf_*` names resolve to the PHP twins (src/Runtime/Stdlib/Buf.php):
// one source, so the polyfill is the oracle of the native classes by
// construction. Keep it plain PHP that both sides run.
//
// An element is stored at its declared width and read back as int / float /
// bool; nothing wraps silently — a value that does not fit is a ValueError, a
// value of the wrong type a TypeError. Indexing follows SplFixedArray.

namespace Manticore\Ds {

    /**
     * `T` is the type an element is read as: the scalar itself, or a
     * `#[TypeDef]` class over it (`UInt16Array<TokenKind>`).
     *
     * @template T
     * @implements \ArrayAccess<int, T>
     * @implements \IteratorAggregate<int, T>
     */
    abstract class TypedArray implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
    {
        protected int $__mcbuf = 0;

        /** The buffer kind (MemoryAbi::BUF_KIND_*). */
        abstract protected function kind(): int;

        /** `$value` as the stored representation, or a TypeError / ValueError. */
        abstract protected function coerce(mixed $value): mixed;

        /** The element at the valid position `$k`. */
        abstract protected function load(int $k): mixed;

        /** Stores the already coerced `$c` into [`$from`, `$to`). */
        abstract protected function fillRaw(mixed $c, int $from, int $to): void;

        /** First position >= `$from` holding the already coerced `$c`, or -1. */
        abstract protected function findRaw(mixed $c, int $from): int;

        public function __construct(int $size = 0)
        {
            if ($size < 0) {
                throw new \ValueError(static::class . '::__construct(): Argument #1 ($size) must be greater than or equal to 0');
            }
            $this->__mcbuf = __mc_nbuf_alloc($this->kind(), $size);
        }

        public function __destruct()
        {
            if ($this->__mcbuf !== 0) {
                __mc_nbuf_free($this->__mcbuf);
                $this->__mcbuf = 0;
            }
        }

        public function __clone()
        {
            if ($this->__mcbuf !== 0) { $this->__mcbuf = __mc_nbuf_clone($this->__mcbuf); }
        }

        /** @param array<int|string, mixed> $values */
        public static function fromArray(array $values): static
        {
            $out = new static(\count($values));
            $k = 0;
            foreach ($values as $v) {
                $out->fillRaw($out->coerce($v), $k, $k + 1);
                $k = $k + 1;
            }
            return $out;
        }

        /** @return array<int, mixed> */
        public function toArray(): array
        {
            $out = [];
            $n = __mc_nbuf_len($this->__mcbuf);
            for ($k = 0; $k < $n; $k++) { $out[] = $this->load($k); }
            return $out;
        }

        public function count(): int { return __mc_nbuf_len($this->__mcbuf); }

        public function getSize(): int { return __mc_nbuf_len($this->__mcbuf); }

        public function setSize(int $size): void
        {
            if ($size < 0) {
                throw new \ValueError(static::class . '::setSize(): Argument #1 ($size) must be greater than or equal to 0');
            }
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $size);
        }

        /** Appends `$value`; `$a[] = $value` is the same. Amortised O(1). */
        public function push(mixed $value): void
        {
            $c = $this->coerce($value);
            $n = __mc_nbuf_len($this->__mcbuf);
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $n + 1);
            $this->fillRaw($c, $n, $n + 1);
        }

        /**
         * Removes and returns the last element.
         * @return T
         */
        public function pop(): mixed
        {
            $n = __mc_nbuf_len($this->__mcbuf);
            if ($n === 0) {
                throw new \UnderflowException('Cannot pop from an empty ' . $this->shortName());
            }
            $v = $this->load($n - 1);
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $n - 1);
            return $v;
        }

        /** Opens `$count` elements holding `$value` before position `$at`. */
        public function insert(int $at, int $count, mixed $value = 0): void
        {
            if ($count < 0) {
                throw new \ValueError(static::class . '::insert(): Argument #2 ($count) must be greater than or equal to 0');
            }
            if ($at < 0 || $at > __mc_nbuf_len($this->__mcbuf)) {
                throw new \OutOfBoundsException('Index invalid or out of range');
            }
            $c = $this->coerce($value);
            $this->__mcbuf = __mc_nbuf_insert($this->__mcbuf, $at, $count);
            $this->fillRaw($c, $at, $at + $count);
        }

        /** Removes `$count` elements from position `$at`; the tail moves down. */
        public function remove(int $at, int $count): void
        {
            if ($count < 0) {
                throw new \ValueError(static::class . '::remove(): Argument #2 ($count) must be greater than or equal to 0');
            }
            if ($at < 0 || $at + $count > __mc_nbuf_len($this->__mcbuf)) {
                throw new \OutOfBoundsException('Index invalid or out of range');
            }
            __mc_nbuf_remove($this->__mcbuf, $at, $count);
        }

        /** Stores `$value` into [`$from`, `$to`); `$to` defaults to the size. */
        public function fill(mixed $value, int $from = 0, ?int $to = null): void
        {
            $n = __mc_nbuf_len($this->__mcbuf);
            $end = $to === null ? $n : $to;
            if ($from < 0 || $end > $n || $from > $end) {
                throw new \OutOfBoundsException('Index invalid or out of range');
            }
            $this->fillRaw($this->coerce($value), $from, $end);
        }

        /** Copies `$count` elements of `$src` (same class) from `$srcAt` to `$dstAt`. */
        public function copyFrom(TypedArray $src, int $srcAt, int $dstAt, int $count): void
        {
            if (\get_class($src) !== static::class) {
                throw new \TypeError(static::class . '::copyFrom(): Argument #1 ($src) must be of type '
                    . static::class . ', ' . \get_class($src) . ' given');
            }
            if ($count < 0) {
                throw new \ValueError(static::class . '::copyFrom(): Argument #4 ($count) must be greater than or equal to 0');
            }
            if ($srcAt < 0 || $srcAt + $count > __mc_nbuf_len($src->__mcbuf)
                || $dstAt < 0 || $dstAt + $count > __mc_nbuf_len($this->__mcbuf)) {
                throw new \OutOfBoundsException('Index invalid or out of range');
            }
            __mc_nbuf_copy($this->__mcbuf, $dstAt, $src->__mcbuf, $srcAt, $count);
        }

        /** First position >= `$from` holding `$value`, or -1. */
        /** Element-wise equality with the same class of array. */
        abstract protected function sameRaw(TypedArray $other): bool;

        /** Whether `$other` is the same class of array with the same elements. */
        public function equals(TypedArray $other): bool
        {
            return \get_class($other) === static::class
                && __mc_nbuf_len($other->__mcbuf) === __mc_nbuf_len($this->__mcbuf)
                && $this->sameRaw($other);
        }

        public function indexOf(mixed $value, int $from = 0): int
        {
            try {
                $c = $this->coerce($value);
            } catch (\ValueError $e) {
                return -1;
            }
            return $this->findRaw($c, $from < 0 ? 0 : $from);
        }

        public function offsetExists(mixed $offset): bool
        {
            if (\is_int($offset)) {
                $k = $offset;
            } elseif (\is_string($offset) && \is_numeric($offset) && (string)(int)$offset === $offset) {
                $k = (int)$offset;
            } elseif (\is_float($offset) || \is_bool($offset)) {
                $k = (int)$offset;
            } else {
                return false;
            }
            return $k >= 0 && $k < __mc_nbuf_len($this->__mcbuf);
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            if ($offset === null) {
                $this->push($value);
                return;
            }
            $k = $this->idx($offset);
            $this->fillRaw($this->coerce($value), $k, $k + 1);
        }

        /** Zeroes the element: a fixed-width slot cannot be absent. */
        public function offsetUnset(mixed $offset): void
        {
            $k = $this->idx($offset);
            $this->fillRaw($this->coerce(false), $k, $k + 1);
        }

        public function getIterator(): \Iterator
        {
            for ($k = 0; $k < __mc_nbuf_len($this->__mcbuf); $k++) {
                yield $k => $this->load($k);
            }
        }

        /** @return array<int, mixed> */
        public function jsonSerialize(): array { return $this->toArray(); }

        /** @return array<int, mixed> */
        public function __serialize(): array { return $this->toArray(); }

        /** @param array<int, mixed> $data */
        public function __unserialize(array $data): void
        {
            if ($this->__mcbuf !== 0) { __mc_nbuf_free($this->__mcbuf); }
            $this->__mcbuf = __mc_nbuf_alloc($this->kind(), \count($data));
            $k = 0;
            foreach ($data as $v) {
                $this->fillRaw($this->coerce($v), $k, $k + 1);
                $k = $k + 1;
            }
        }

        /** @return array<int, mixed> */
        public function __debugInfo(): array { return $this->toArray(); }

        /** The valid position `$offset` names (SplFixedArray's rules), or it throws. */
        protected function idx(mixed $offset): int
        {
            if (\is_int($offset)) {
                $k = $offset;
            } elseif (\is_string($offset) && \is_numeric($offset) && (string)(int)$offset === $offset) {
                $k = (int)$offset;
            } elseif (\is_float($offset) || \is_bool($offset)) {
                $k = (int)$offset;
            } else {
                throw new \TypeError('Cannot access offset of type ' . \get_debug_type($offset) . ' on ' . static::class);
            }
            if ($k < 0 || $k >= __mc_nbuf_len($this->__mcbuf)) {
                throw new \OutOfBoundsException('Index invalid or out of range');
            }
            return $k;
        }

        /** The class name without its namespace. */
        protected function shortName(): string
        {
            $c = static::class;
            $p = \strrpos($c, '\\');
            return $p === false ? $c : \substr($c, $p + 1);
        }
    }

    /**
     * The integer kinds: an element is an int inside [min(), max()].
     * @implements \ArrayAccess<int, int>
     * @implements \IteratorAggregate<int, int>
     *
     * @template T = int
     * @extends TypedArray<T>
     */
    abstract class IntTypedArray extends TypedArray
    {
        abstract protected function rangeMin(): int;

        abstract protected function rangeMax(): int;

        /** @return T */
        public function offsetGet(mixed $offset): int
        {
            if (\is_int($offset) && $offset >= 0 && $offset < __mc_nbuf_len($this->__mcbuf)) {
                return __mc_nbuf_get_i($this->__mcbuf, $offset);
            }
            return __mc_nbuf_get_i($this->__mcbuf, $this->idx($offset));
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            // The common store, without the offset and value normalisers.
            if (\is_int($offset) && \is_int($value) && $offset >= 0 && $offset < __mc_nbuf_len($this->__mcbuf)
                && $value >= $this->rangeMin() && $value <= $this->rangeMax()) {
                __mc_nbuf_set_i($this->__mcbuf, $offset, $value);
                return;
            }
            parent::offsetSet($offset, $value);
        }

        protected function load(int $k): mixed { return __mc_nbuf_get_i($this->__mcbuf, $k); }

        protected function coerce(mixed $value): mixed
        {
            if (\is_int($value)) {
                $i = $value;
            } elseif (\is_bool($value)) {
                $i = $value ? 1 : 0;
            } else {
                $f = 0.0;
                if (\is_float($value)) {
                    $f = $value;
                } elseif (\is_string($value) && \is_numeric($value)) {
                    $si = (int)$value;
                    $f = (float)$value;
                    if ((float)$si === $f) { return $this->inRange($si); }
                } else {
                    throw new \TypeError(static::class . ' element must be of type int, ' . \get_debug_type($value) . ' given');
                }
                if (!\is_finite($f) || \floor($f) !== $f) {
                    throw new \TypeError(static::class . ' element must be of type int, ' . \get_debug_type($value) . ' given');
                }
                if ($f < -9223372036854775808.0 || $f >= 9223372036854775808.0) {
                    throw new \ValueError('Value ' . (string)$f . ' is out of range for ' . $this->shortName()
                        . ' (' . (string)$this->rangeMin() . '..' . (string)$this->rangeMax() . ')');
                }
                $i = (int)$f;
            }
            return $this->inRange($i);
        }

        private function inRange(int $i): int
        {
            if ($i < $this->rangeMin() || $i > $this->rangeMax()) {
                throw new \ValueError('Value ' . (string)$i . ' is out of range for ' . $this->shortName()
                    . ' (' . (string)$this->rangeMin() . '..' . (string)$this->rangeMax() . ')');
            }
            return $i;
        }

        protected function fillRaw(mixed $c, int $from, int $to): void
        {
            __mc_nbuf_fill_i($this->__mcbuf, (int)$c, $from, $to);
        }

        protected function findRaw(mixed $c, int $from): int
        {
            return __mc_nbuf_find_i($this->__mcbuf, (int)$c, $from);
        }
        /** The sum of the elements (wraps past the int range, as native ints do). */
        public function sum(): int { return __mc_nbuf_reduce_i($this->__mcbuf, 0); }

        public function min(): int
        {
            if (__mc_nbuf_len($this->__mcbuf) === 0) { throw new \ValueError($this->shortName() . '::min(): array must contain at least one element'); }
            return __mc_nbuf_reduce_i($this->__mcbuf, 1);
        }

        public function max(): int
        {
            if (__mc_nbuf_len($this->__mcbuf) === 0) { throw new \ValueError($this->shortName() . '::max(): array must contain at least one element'); }
            return __mc_nbuf_reduce_i($this->__mcbuf, 2);
        }

        protected function sameRaw(TypedArray $other): bool
        {
            return __mc_nbuf_same($this->__mcbuf, $other->__mcbuf) !== 0;
        }
    }

    /**
     * The float kinds.
     * @implements \ArrayAccess<int, float>
     * @implements \IteratorAggregate<int, float>
     *
     * @template T = float
     * @extends TypedArray<T>
     */
    abstract class FloatTypedArray extends TypedArray
    {
        /** @return T */
        public function offsetGet(mixed $offset): float
        {
            if (\is_int($offset) && $offset >= 0 && $offset < __mc_nbuf_len($this->__mcbuf)) {
                return __mc_nbuf_get_f($this->__mcbuf, $offset);
            }
            return __mc_nbuf_get_f($this->__mcbuf, $this->idx($offset));
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            if (\is_int($offset) && \is_float($value) && $offset >= 0 && $offset < __mc_nbuf_len($this->__mcbuf)) {
                __mc_nbuf_set_f($this->__mcbuf, $offset, $value);
                return;
            }
            parent::offsetSet($offset, $value);
        }

        protected function load(int $k): mixed { return __mc_nbuf_get_f($this->__mcbuf, $k); }

        protected function coerce(mixed $value): mixed
        {
            if (\is_float($value)) { return $value; }
            if (\is_int($value)) { return (float)$value; }
            if (\is_bool($value)) { return $value ? 1.0 : 0.0; }
            if (\is_string($value) && \is_numeric($value)) { return (float)$value; }
            throw new \TypeError(static::class . ' element must be of type float, ' . \get_debug_type($value) . ' given');
        }

        protected function fillRaw(mixed $c, int $from, int $to): void
        {
            __mc_nbuf_fill_f($this->__mcbuf, (float)$c, $from, $to);
        }

        protected function findRaw(mixed $c, int $from): int
        {
            return __mc_nbuf_find_f($this->__mcbuf, (float)$c, $from);
        }
        /** The sum of the elements (summed in index order). */
        public function sum(): float { return __mc_nbuf_reduce_f($this->__mcbuf, 0); }

        public function min(): float
        {
            if (__mc_nbuf_len($this->__mcbuf) === 0) { throw new \ValueError($this->shortName() . '::min(): array must contain at least one element'); }
            return __mc_nbuf_reduce_f($this->__mcbuf, 1);
        }

        public function max(): float
        {
            if (__mc_nbuf_len($this->__mcbuf) === 0) { throw new \ValueError($this->shortName() . '::max(): array must contain at least one element'); }
            return __mc_nbuf_reduce_f($this->__mcbuf, 2);
        }

        protected function sameRaw(TypedArray $other): bool
        {
            return __mc_nbuf_same($this->__mcbuf, $other->__mcbuf) !== 0;
        }
    }

    /** @template T = int @extends IntTypedArray<T> */
    final class Int8Array extends IntTypedArray
    {
        protected function kind(): int { return 1; }
        protected function rangeMin(): int { return -128; }
        protected function rangeMax(): int { return 127; }
    }

    /** @template T = int @extends IntTypedArray<T> */
    final class Int16Array extends IntTypedArray
    {
        protected function kind(): int { return 2; }
        protected function rangeMin(): int { return -32768; }
        protected function rangeMax(): int { return 32767; }
    }

    /** @template T = int @extends IntTypedArray<T> */
    final class Int32Array extends IntTypedArray
    {
        protected function kind(): int { return 3; }
        protected function rangeMin(): int { return -2147483648; }
        protected function rangeMax(): int { return 2147483647; }
    }

    /** @template T = int @extends IntTypedArray<T> */
    final class Int64Array extends IntTypedArray
    {
        protected function kind(): int { return 4; }
        protected function rangeMin(): int { return \PHP_INT_MIN; }
        protected function rangeMax(): int { return \PHP_INT_MAX; }
    }

    /** @template T = int @extends IntTypedArray<T> */
    final class UInt8Array extends IntTypedArray
    {
        protected function kind(): int { return 5; }
        protected function rangeMin(): int { return 0; }
        protected function rangeMax(): int { return 255; }
    }

    /** @template T = int @extends IntTypedArray<T> */
    final class UInt16Array extends IntTypedArray
    {
        protected function kind(): int { return 6; }
        protected function rangeMin(): int { return 0; }
        protected function rangeMax(): int { return 65535; }
    }

    /**
     * 64-bit unsigned elements. PHP has no unsigned int, so an element READS
     * as the int with the same 64 bits — a value of 2^63 or more reads
     * negative — and an int WRITES its bits, so `$a[$i] = $a[$j]` copies any
     * value. A numeric string or a float is a VALUE: 0..18446744073709551615,
     * anything else is a `ValueError`. Ordering (`min`, `max`, `compare`) is
     * unsigned; `getString()` gives the decimal value.
     *
     * @template T = int
     * @extends IntTypedArray<T>
     */
    final class UInt64Array extends IntTypedArray
    {
        protected function kind(): int { return 12; }
        protected function rangeMin(): int { return \PHP_INT_MIN; }
        protected function rangeMax(): int { return \PHP_INT_MAX; }

        /** Element `$index` as its unsigned decimal value. */
        public function getString(int $index): string
        {
            return self::toDecimal($this->offsetGet($index));
        }

        /** -1, 0 or 1 as `$a` is below, equal to or above `$b`, both read as unsigned. */
        public static function compare(int $a, int $b): int
        {
            return ($a ^ \PHP_INT_MIN) <=> ($b ^ \PHP_INT_MIN);
        }

        /**
         * Every element as its unsigned decimal value: JSON numbers have no
         * width, so one past 2^63 is written exactly — a Go / Rust / BigInt
         * consumer reads 18446744073709551615, not -1. `toArray()` stays on
         * bits. Decode it back losslessly with JSON_BIGINT_AS_STRING and
         * `fromArray()`.
         * @return array<int, mixed>
         */
        public function jsonSerialize(): array
        {
            // Off the raw buffer, not toArray(): an element typed `T` (a
            // TypeDef) must not flow through a mixed-typed list here.
            $out = [];
            $n = __mc_nbuf_len($this->__mcbuf);
            for ($k = 0; $k < $n; $k++) {
                $bits = __mc_nbuf_get_i($this->__mcbuf, $k);
                $out[] = $bits < 0 ? new JsonNumber(self::toDecimal($bits)) : $bits;
            }
            return $out;
        }

        /** The unsigned decimal value of 64 bits. */
        public static function toDecimal(int $bits): string
        {
            if ($bits >= 0) { return (string)$bits; }
            // Unsigned divide by ten: halve as unsigned, divide by five.
            $q = \intdiv(($bits >> 1) & \PHP_INT_MAX, 5);
            return (string)$q . (string)($bits - $q * 10);
        }

        protected function coerce(mixed $value): mixed
        {
            if (\is_int($value)) { return $value; }
            if (\is_bool($value)) { return $value ? 1 : 0; }
            if (\is_string($value) && \is_numeric($value)) {
                $d = \ltrim(\trim($value), '+');
                $digits = $d !== '';
                for ($k = 0; $k < \strlen($d); $k++) {
                    if ($d[$k] < '0' || $d[$k] > '9') { $digits = false; break; }
                }
                if ($digits) { return $this->parseDecimal(\ltrim($d, '0')); }
                $value = (float)$value;
            }
            if (!\is_float($value)) {
                throw new \TypeError(static::class . ' element must be of type int, ' . \get_debug_type($value) . ' given');
            }
            if (!\is_finite($value) || \floor($value) !== $value) {
                throw new \TypeError(static::class . ' element must be of type int, float given');
            }
            if ($value < 0.0 || $value >= 18446744073709551616.0) { $this->outOfRange((string)$value); }
            if ($value < 9223372036854775808.0) { return (int)$value; }
            return ((int)($value - 9223372036854775808.0)) | \PHP_INT_MIN;
        }

        /** Decimal digits with no leading zeros → the 64 bits of that value. */
        private function parseDecimal(string $d): int
        {
            $n = \strlen($d);
            if ($n === 0) { return 0; }
            if ($n > 20 || ($n === 20 && \strcmp($d, '18446744073709551615') > 0)) { $this->outOfRange($d); }
            $v = 0;
            for ($k = 0; $k < $n; $k++) { $v = $v * 10 + (\ord($d[$k]) - 48); }
            return $v;
        }

        private function outOfRange(string $what): never
        {
            throw new \ValueError('Value ' . $what . ' is out of range for UInt64Array (0..18446744073709551615)');
        }
    }

    /**
     * A resizable run of bytes: a `UInt8Array` (`$b[$i]`, `push`, `fill`, …)
     * with typed reads and writes at a BYTE offset, little-endian unless
     * `$bigEndian`. An access that runs past the end is an
     * `OutOfBoundsException`; an integer that does not fit its width is a
     * `ValueError`.
     *
     * @template T = int
     * @extends IntTypedArray<T>
     */
    final class ByteBuffer extends IntTypedArray
    {
        protected function kind(): int { return 5; }
        protected function rangeMin(): int { return 0; }
        protected function rangeMax(): int { return 255; }

        public static function fromString(string $bytes): self
        {
            $n = \strlen($bytes);
            $b = new self($n);
            for ($k = 0; $k < $n; $k++) { __mc_nbuf_set_i($b->__mcbuf, $k, \ord($bytes[$k])); }
            return $b;
        }

        /** The bytes `[$offset, $offset + $length)` as a string; to the end when `$length` is null. */
        public function toString(int $offset = 0, ?int $length = null): string
        {
            $w = $length ?? __mc_nbuf_len($this->__mcbuf) - $offset;
            $this->span($offset, $w);
            $out = '';
            for ($k = 0; $k < $w; $k++) { $out .= \chr(__mc_nbuf_get_i($this->__mcbuf, $offset + $k)); }
            return $out;
        }

        /** Copies `$bytes` over the buffer at `$offset`. */
        public function write(int $offset, string $bytes): void
        {
            $w = \strlen($bytes);
            $this->span($offset, $w);
            for ($k = 0; $k < $w; $k++) { __mc_nbuf_set_i($this->__mcbuf, $offset + $k, \ord($bytes[$k])); }
        }

        public function getUInt8(int $offset): int { return $this->peek($offset, 1, false); }

        public function getInt8(int $offset): int
        {
            $v = $this->peek($offset, 1, false);
            return $v >= 128 ? $v - 256 : $v;
        }

        public function getUInt16(int $offset, bool $bigEndian = false): int { return $this->peek($offset, 2, $bigEndian); }

        public function getInt16(int $offset, bool $bigEndian = false): int
        {
            $v = $this->peek($offset, 2, $bigEndian);
            return $v >= 32768 ? $v - 65536 : $v;
        }

        public function getUInt32(int $offset, bool $bigEndian = false): int { return $this->peek($offset, 4, $bigEndian); }

        public function getInt32(int $offset, bool $bigEndian = false): int
        {
            $v = $this->peek($offset, 4, $bigEndian);
            return $v >= 2147483648 ? $v - 4294967296 : $v;
        }

        public function getInt64(int $offset, bool $bigEndian = false): int { return $this->peek($offset, 8, $bigEndian); }

        public function getFloat32(int $offset, bool $bigEndian = false): float
        {
            return __mc_nbuf_bits_f($this->peek($offset, 4, $bigEndian), 4);
        }

        public function getFloat64(int $offset, bool $bigEndian = false): float
        {
            return __mc_nbuf_bits_f($this->peek($offset, 8, $bigEndian), 8);
        }

        public function setUInt8(int $offset, int $value): void { $this->poke($offset, 1, false, $value, 0, 255, 'uint8'); }

        public function setInt8(int $offset, int $value): void { $this->poke($offset, 1, false, $value, -128, 127, 'int8'); }

        public function setUInt16(int $offset, int $value, bool $bigEndian = false): void { $this->poke($offset, 2, $bigEndian, $value, 0, 65535, 'uint16'); }

        public function setInt16(int $offset, int $value, bool $bigEndian = false): void { $this->poke($offset, 2, $bigEndian, $value, -32768, 32767, 'int16'); }

        public function setUInt32(int $offset, int $value, bool $bigEndian = false): void { $this->poke($offset, 4, $bigEndian, $value, 0, 4294967295, 'uint32'); }

        public function setInt32(int $offset, int $value, bool $bigEndian = false): void { $this->poke($offset, 4, $bigEndian, $value, -2147483648, 2147483647, 'int32'); }

        public function setInt64(int $offset, int $value, bool $bigEndian = false): void { $this->poke($offset, 8, $bigEndian, $value, \PHP_INT_MIN, \PHP_INT_MAX, 'int64'); }

        public function setFloat32(int $offset, float $value, bool $bigEndian = false): void
        {
            $this->span($offset, 4);
            __mc_nbuf_poke($this->__mcbuf, $offset, 4, $bigEndian ? 1 : 0, __mc_nbuf_f_bits($value, 4));
        }

        public function setFloat64(int $offset, float $value, bool $bigEndian = false): void
        {
            $this->span($offset, 8);
            __mc_nbuf_poke($this->__mcbuf, $offset, 8, $bigEndian ? 1 : 0, __mc_nbuf_f_bits($value, 8));
        }

        private function span(int $offset, int $width): void
        {
            if ($offset < 0 || $width < 0 || $offset + $width > __mc_nbuf_len($this->__mcbuf)) {
                throw new \OutOfBoundsException('Index invalid or out of range');
            }
        }

        private function peek(int $offset, int $width, bool $bigEndian): int
        {
            $this->span($offset, $width);
            return __mc_nbuf_peek($this->__mcbuf, $offset, $width, $bigEndian ? 1 : 0);
        }

        private function poke(int $offset, int $width, bool $bigEndian, int $value, int $min, int $max, string $type): void
        {
            if ($value < $min || $value > $max) {
                throw new \ValueError('Value ' . (string)$value . ' is out of range for ' . $type
                    . ' (' . (string)$min . '..' . (string)$max . ')');
            }
            $this->span($offset, $width);
            __mc_nbuf_poke($this->__mcbuf, $offset, $width, $bigEndian ? 1 : 0, $value);
        }
    }

    /** @template T = int @extends IntTypedArray<T> */
    final class UInt32Array extends IntTypedArray
    {
        protected function kind(): int { return 7; }
        protected function rangeMin(): int { return 0; }
        protected function rangeMax(): int { return 4294967295; }
    }

    /**
     * Elements are stored as IEEE-754 binary32: a read returns the rounded value.
     *
     * @template T = float
     * @extends FloatTypedArray<T>
     */
    final class Float32Array extends FloatTypedArray
    {
        protected function kind(): int { return 8; }
    }

    /** @template T = float @extends FloatTypedArray<T> */
    final class Float64Array extends FloatTypedArray
    {
        protected function kind(): int { return 9; }
    }

    /**
     * One bit per element.
     * @implements \ArrayAccess<int, bool>
     * @implements \IteratorAggregate<int, bool>
     */
    final class BitArray extends TypedArray
    {
        protected function kind(): int { return 10; }

        public function offsetGet(mixed $offset): bool
        {
            return __mc_nbuf_get_i($this->__mcbuf, $this->idx($offset)) !== 0;
        }

        protected function load(int $k): mixed { return __mc_nbuf_get_i($this->__mcbuf, $k) !== 0; }

        protected function coerce(mixed $value): mixed
        {
            if (\is_bool($value)) { return $value ? 1 : 0; }
            if (\is_int($value)) { return $value !== 0 ? 1 : 0; }
            if (\is_float($value)) { return $value !== 0.0 ? 1 : 0; }
            if (\is_string($value)) { return ($value !== '' && $value !== '0') ? 1 : 0; }
            throw new \TypeError(static::class . ' element must be of type bool, ' . \get_debug_type($value) . ' given');
        }

        protected function fillRaw(mixed $c, int $from, int $to): void
        {
            __mc_nbuf_fill_i($this->__mcbuf, (int)$c, $from, $to);
        }

        protected function findRaw(mixed $c, int $from): int
        {
            return __mc_nbuf_find_i($this->__mcbuf, (int)$c, $from);
        }
        /** The number of set bits. */
        public function sum(): int { return __mc_nbuf_reduce_i($this->__mcbuf, 0); }

        protected function sameRaw(TypedArray $other): bool
        {
            return __mc_nbuf_same($this->__mcbuf, $other->__mcbuf) !== 0;
        }
    }

    /**
     * A JSON number php's int cannot hold, as its decimal text. The native
     * encoder writes the digits BARE (it knows the class by its stable id);
     * under Zend, where nothing does, it degrades to the quoted string.
     * @internal
     */
    final class JsonNumber implements \JsonSerializable
    {
        public function __construct(public readonly string $digits) {}

        public function jsonSerialize(): mixed { return $this->digits; }
    }
}
