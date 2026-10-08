<?php

// Manticore\Ds\Map / Set — insertion-ordered hash containers over the native
// table runtime (`__mc_hmap_*`). Appended to ds.php by Main.php (one
// compilation unit); under Zend the names resolve to src/Runtime/Stdlib/HMap.php.
namespace Manticore\Ds {

    /** @internal key validation shared by Map and Set */
    function __hkey(mixed $key, string $cls): mixed
    {
        if (\is_int($key) || \is_string($key) || \is_object($key)) { return $key; }
        throw new \TypeError('Cannot use a key of type ' . \get_debug_type($key) . ' in ' . $cls);
    }

    /** @internal a Map/Set foreach saw the table move under it (the Generator and the native loop) */
    function __iter_modified(string $cls): never
    {
        throw new \RuntimeException($cls . ' modified during iteration');
    }

    /** @internal `foreach ($ds as &$v)`: Zend's answer for a by-value Generator */
    function __iter_byref(): never
    {
        throw new \Exception('You can only iterate a generator by-reference if it declared that it yields by-reference');
    }

    /**
     * @template K
     * @template V
     * @implements \ArrayAccess<K, V>
     * @implements \IteratorAggregate<K, V>
     */
    final class Map implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
    {
        private int $__mcbuf = 0;

        public function __construct() { $this->__mcbuf = __mc_hmap_alloc(0); }
        public function __destruct() { if ($this->__mcbuf !== 0) { __mc_hmap_free($this->__mcbuf); $this->__mcbuf = 0; } }
        public function __clone() { $this->__mcbuf = __mc_hmap_clone($this->__mcbuf); }

        /** @param K $key @return V */
        public function get(mixed $key, mixed $default = null): mixed
        {
            $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e >= 0) { return __mc_hmap_val($this->__mcbuf, $e); }
            if ($e === -2) { __hkey($key, 'Map'); }
            if (\func_num_args() > 1) { return $default; }
            throw new \OutOfBoundsException('Key not found');
        }

        /** @param K $key @param V $value */
        public function set(mixed $key, mixed $value): void
        {
            if (__mc_hmap_put($this->__mcbuf, $key, $value) === -2) { __hkey($key, 'Map'); }
        }

        /** @param K $key */
        public function has(mixed $key): bool
        {
            $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e === -2) { __hkey($key, 'Map'); }
            return $e >= 0;
        }

        /** @param K $key @return V */
        public function remove(mixed $key): mixed
        {
            $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e < 0) { if ($e === -2) { __hkey($key, 'Map'); } throw new \OutOfBoundsException('Key not found'); }
            $v = __mc_hmap_val($this->__mcbuf, $e);
            __mc_hmap_delat($this->__mcbuf, $e);
            return $v;
        }

        public function count(): int { return __mc_hmap_len($this->__mcbuf); }
        public function isEmpty(): bool { return __mc_hmap_len($this->__mcbuf) === 0; }
        public function clear(): void { __mc_hmap_clear($this->__mcbuf); }

        /** @return array<int|string, V> */
        public function toArray(): array
        {
            $out = [];
            for ($e = __mc_hmap_next($this->__mcbuf, 0); $e >= 0; $e = __mc_hmap_next($this->__mcbuf, $e + 1)) {
                $k = __mc_hmap_key($this->__mcbuf, $e);
                if (\is_object($k)) { throw new \TypeError('Map::toArray(): object keys cannot be array keys'); }
                if (\array_key_exists($k, $out)) { throw new \ValueError('Map::toArray(): keys collide in a PHP array'); }
                $out[$k] = __mc_hmap_val($this->__mcbuf, $e);
            }
            return $out;
        }

        public function getIterator(): \Generator
        {
            $h = $this->__mcbuf;
            $epoch = __mc_hmap_epoch($h);
            for ($e = __mc_hmap_next($h, 0); $e >= 0; $e = __mc_hmap_next($h, $e + 1)) {
                yield __mc_hmap_key($h, $e) => __mc_hmap_val($h, $e);
                if (__mc_hmap_epoch($h) !== $epoch) { __iter_modified('Map'); }
            }
        }

        public function offsetExists(mixed $offset): bool { return $this->has($offset); }
        public function offsetGet(mixed $offset): mixed { return $this->get($offset); }
        public function offsetSet(mixed $offset, mixed $value): void
        {
            if ($offset === null) { throw new \Error('Cannot append to a Map; use set()'); }
            $this->set($offset, $value);
        }
        public function offsetUnset(mixed $offset): void
        {
            $e = __mc_hmap_find($this->__mcbuf, $offset);
            if ($e === -2) { __hkey($offset, 'Map'); }
            if ($e >= 0) { __mc_hmap_delat($this->__mcbuf, $e); }
        }

        /** @return list<array{0: K, 1: V}> */
        public function __serialize(): array
        {
            $out = [];
            foreach ($this as $k => $v) { $out[] = [$k, $v]; }
            return $out;
        }
        /** @param list<array{0: K, 1: V}> $data */
        public function __unserialize(array $data): void
        {
            $this->__mcbuf = __mc_hmap_alloc(0);
            foreach ($data as $p) { $this->set($p[0], $p[1]); }
        }
        public function __debugInfo(): array { return $this->__serialize(); }
        public function jsonSerialize(): mixed { return (object) $this->toArray(); }

        /** @return Vec<K> */
        public function keys(): Vec
        {
            $out = new Vec();
            for ($e = __mc_hmap_next($this->__mcbuf, 0); $e >= 0; $e = __mc_hmap_next($this->__mcbuf, $e + 1)) { $out->push(__mc_hmap_key($this->__mcbuf, $e)); }
            return $out;
        }

        /** @return Vec<V> */
        public function values(): Vec
        {
            $out = new Vec();
            for ($e = __mc_hmap_next($this->__mcbuf, 0); $e >= 0; $e = __mc_hmap_next($this->__mcbuf, $e + 1)) { $out->push(__mc_hmap_val($this->__mcbuf, $e)); }
            return $out;
        }
    }

    /**
     * @template T
     * @implements \IteratorAggregate<int, T>
     */
    final class Set implements \Countable, \IteratorAggregate, \JsonSerializable
    {
        private int $__mcbuf = 0;

        public function __construct() { $this->__mcbuf = __mc_hmap_alloc(1); }
        public function __destruct() { if ($this->__mcbuf !== 0) { __mc_hmap_free($this->__mcbuf); $this->__mcbuf = 0; } }
        public function __clone() { $this->__mcbuf = __mc_hmap_clone($this->__mcbuf); }

        /** @param T $value */
        public function add(mixed $value): void
        {
            if (__mc_hmap_put($this->__mcbuf, $value, null) === -2) { __hkey($value, 'Set'); }
        }
        /** @param T $value */
        public function has(mixed $value): bool
        {
            $e = __mc_hmap_find($this->__mcbuf, $value);
            if ($e === -2) { __hkey($value, 'Set'); }
            return $e >= 0;
        }
        /** @param T $value */
        public function remove(mixed $value): void
        {
            $e = __mc_hmap_find($this->__mcbuf, $value);
            if ($e < 0) { if ($e === -2) { __hkey($value, 'Set'); } throw new \OutOfBoundsException('Value not found'); }
            __mc_hmap_delat($this->__mcbuf, $e);
        }
        public function count(): int { return __mc_hmap_len($this->__mcbuf); }
        public function isEmpty(): bool { return __mc_hmap_len($this->__mcbuf) === 0; }
        public function clear(): void { __mc_hmap_clear($this->__mcbuf); }

        /** @return list<T> */
        public function toArray(): array
        {
            $out = [];
            for ($e = __mc_hmap_next($this->__mcbuf, 0); $e >= 0; $e = __mc_hmap_next($this->__mcbuf, $e + 1)) { $out[] = __mc_hmap_key($this->__mcbuf, $e); }
            return $out;
        }

        /** @param Set<T> $other @return Set<T> */
        public function union(Set $other): Set { $r = clone $this; foreach ($other as $v) { $r->add($v); } return $r; }
        /** @param Set<T> $other @return Set<T> */
        public function intersect(Set $other): Set { $r = new Set(); foreach ($this as $v) { if ($other->has($v)) { $r->add($v); } } return $r; }
        /** @param Set<T> $other @return Set<T> */
        public function diff(Set $other): Set { $r = new Set(); foreach ($this as $v) { if (!$other->has($v)) { $r->add($v); } } return $r; }

        public function getIterator(): \Generator
        {
            $h = $this->__mcbuf;
            $epoch = __mc_hmap_epoch($h);
            for ($e = __mc_hmap_next($h, 0); $e >= 0; $e = __mc_hmap_next($h, $e + 1)) {
                yield __mc_hmap_key($h, $e);
                if (__mc_hmap_epoch($h) !== $epoch) { __iter_modified('Set'); }
            }
        }

        public function __serialize(): array { return $this->toArray(); }
        public function __unserialize(array $data): void { $this->__mcbuf = __mc_hmap_alloc(1); foreach ($data as $v) { $this->add($v); } }
        public function __debugInfo(): array { return $this->toArray(); }
        public function jsonSerialize(): mixed { return $this->toArray(); }
    }

    /**
     * @template T
     * @implements \ArrayAccess<int, T>
     * @implements \IteratorAggregate<int, T>
     */
    final class Vec implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
    {
        private int $__mcbuf = 0;

        // 11 = MemoryAbi::BUF_KIND_CELL (prelude cannot read MemoryAbi)
        public function __construct() { $this->__mcbuf = __mc_nbuf_alloc(11, 0); }
        public function __destruct() { if ($this->__mcbuf !== 0) { __mc_nbuf_free($this->__mcbuf); $this->__mcbuf = 0; } }
        public function __clone() { $this->__mcbuf = __mc_nbuf_clone($this->__mcbuf); }

        /** @param array<T> $values @return Vec<T> */
        public static function fromArray(array $values): Vec { $v = new Vec(); foreach ($values as $x) { $v->push($x); } return $v; }

        /** @param T $value */
        public function push(mixed $value): void
        {
            $n = __mc_nbuf_len($this->__mcbuf);
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $n + 1);
            __mc_nbuf_set_c($this->__mcbuf, $n, $value);
        }

        /** @return T */
        public function pop(): mixed
        {
            $n = __mc_nbuf_len($this->__mcbuf);
            if ($n === 0) { throw new \UnderflowException('Cannot pop from an empty Vec'); }
            $v = __mc_nbuf_get_c($this->__mcbuf, $n - 1);
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $n - 1);
            return $v;
        }

        public function count(): int { return __mc_nbuf_len($this->__mcbuf); }
        public function isEmpty(): bool { return __mc_nbuf_len($this->__mcbuf) === 0; }
        public function clear(): void { $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, 0); }

        private function at(mixed $offset): int
        {
            if (!\is_int($offset)) { throw new \TypeError('Cannot access offset of type ' . \get_debug_type($offset) . ' on Vec'); }
            if ($offset < 0 || $offset >= __mc_nbuf_len($this->__mcbuf)) { throw new \OutOfBoundsException('Index invalid or out of range'); }
            return $offset;
        }

        public function offsetExists(mixed $offset): bool { return \is_int($offset) && $offset >= 0 && $offset < __mc_nbuf_len($this->__mcbuf); }
        public function offsetGet(mixed $offset): mixed { return __mc_nbuf_get_c($this->__mcbuf, $this->at($offset)); }
        public function offsetSet(mixed $offset, mixed $value): void
        {
            if ($offset === null) { $this->push($value); return; }
            __mc_nbuf_set_c($this->__mcbuf, $this->at($offset), $value);
        }
        public function offsetUnset(mixed $offset): void { throw new \Error('Cannot unset a Vec element; use pop()'); }

        /** @return list<T> */
        public function toArray(): array
        {
            $out = [];
            for ($i = 0, $n = __mc_nbuf_len($this->__mcbuf); $i < $n; $i++) { $out[] = __mc_nbuf_get_c($this->__mcbuf, $i); }
            return $out;
        }

        public function getIterator(): \Generator
        {
            for ($i = 0; $i < __mc_nbuf_len($this->__mcbuf); $i++) { yield $i => __mc_nbuf_get_c($this->__mcbuf, $i); }
        }

        public function __serialize(): array { return $this->toArray(); }
        public function __unserialize(array $data): void { $this->__mcbuf = __mc_nbuf_alloc(11, 0); foreach ($data as $x) { $this->push($x); } }
        public function __debugInfo(): array { return $this->toArray(); }
        public function jsonSerialize(): mixed { return $this->toArray(); }
    }
}
