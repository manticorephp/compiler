<?php

// Manticore\Ds\Map / Set — insertion-ordered hash containers over the native
// table runtime (`__mc_hmap_*`). Appended to ds.php by Main.php (one
// compilation unit); under Zend the names resolve to src/Runtime/Stdlib/HMap.php.
namespace Manticore\Ds {

    /** @internal key validation shared by Map and Set */
    function __hkey(mixed $key, string $cls): void
    {
        if (\is_int($key) || \is_string($key) || \is_object($key)) { return; }
        throw new \TypeError('Cannot use a key of type ' . \get_debug_type($key) . ' in ' . $cls);
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
            __hkey($key, 'Map'); $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e >= 0) { return __mc_hmap_val($this->__mcbuf, $e); }
            if (\func_num_args() > 1) { return $default; }
            throw new \OutOfBoundsException('Key not found');
        }

        /** @param K $key @param V $value */
        public function set(mixed $key, mixed $value): void { __hkey($key, 'Map'); __mc_hmap_put($this->__mcbuf, $key, $value); }

        /** @param K $key */
        public function has(mixed $key): bool { __hkey($key, 'Map'); return __mc_hmap_find($this->__mcbuf, $key) >= 0; }

        /** @param K $key @return V */
        public function remove(mixed $key): mixed
        {
            __hkey($key, 'Map'); $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e < 0) { throw new \OutOfBoundsException('Key not found'); }
            $v = __mc_hmap_val($this->__mcbuf, $e);
            __mc_hmap_del($this->__mcbuf, $key);
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
                if (__mc_hmap_epoch($h) !== $epoch) { throw new \RuntimeException('Map modified during iteration'); }
            }
        }

        public function offsetExists(mixed $offset): bool { return $this->has($offset); }
        public function offsetGet(mixed $offset): mixed { return $this->get($offset); }
        public function offsetSet(mixed $offset, mixed $value): void
        {
            if ($offset === null) { throw new \Error('Cannot append to a Map; use set()'); }
            $this->set($offset, $value);
        }
        public function offsetUnset(mixed $offset): void { __hkey($offset, 'Map'); __mc_hmap_del($this->__mcbuf, $offset); }

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
        public function add(mixed $value): void { __hkey($value, 'Set'); __mc_hmap_put($this->__mcbuf, $value, null); }
        /** @param T $value */
        public function has(mixed $value): bool { __hkey($value, 'Set'); return __mc_hmap_find($this->__mcbuf, $value) >= 0; }
        /** @param T $value */
        public function remove(mixed $value): void
        {
            __hkey($value, 'Set');
            if (__mc_hmap_del($this->__mcbuf, $value) === 0) { throw new \OutOfBoundsException('Value not found'); }
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
                if (__mc_hmap_epoch($h) !== $epoch) { throw new \RuntimeException('Set modified during iteration'); }
            }
        }

        public function __serialize(): array { return $this->toArray(); }
        public function __unserialize(array $data): void { $this->__mcbuf = __mc_hmap_alloc(1); foreach ($data as $v) { $this->add($v); } }
        public function __debugInfo(): array { return $this->toArray(); }
        public function jsonSerialize(): mixed { return $this->toArray(); }
    }
}
