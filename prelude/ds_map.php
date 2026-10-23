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
     * An insertion-ordered hash map from keys of any hashable kind to values.
     *
     * Unlike a PHP array, a `Map` key may be an `int`, a `string` or an
     * object, and the kinds never merge: `1` and `'1'` are two different keys,
     * and two objects are two keys unless they are the same instance. Any other
     * key type (`float`, `bool`, `null`, `array`) throws a `TypeError`.
     *
     * Iteration order is insertion order. Overwriting an existing key keeps its
     * position; removing a key and setting it again moves it to the end.
     * `get()`, `set()`, `has()` and `remove()` run in O(1) on average.
     *
     * A `Map` is an object: assigning it copies the handle, not the contents.
     * Use `clone` for an independent (shallow) copy. It implements
     * `ArrayAccess` (`$map[$k]`, `isset($map[$k])`, `unset($map[$k])`),
     * `Countable`, `IteratorAggregate` (`foreach ($map as $k => $v)`) and
     * `JsonSerializable` (encoded as a JSON object).
     *
     * Changing the map while it is being iterated is not supported. Updating
     * the value of a key that is already present is safe; adding keys can make
     * the running loop visit them, and some changes (such as `clear()`) make
     * the loop throw a `RuntimeException` ("Map modified during iteration").
     *
     * ```php
     * $ages = new Map();
     * $ages->set('ann', 31);
     * echo $ages['ann'];   // 31
     * ```
     *
     * @template K
     * @template V
     * @implements \ArrayAccess<K, V>
     * @implements \IteratorAggregate<K, V>
     * @example doc_ds_map_basic
     * @see Set
     * @see Vec
     */
    final class Map implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
    {
        private int $__mcbuf = 0;

        /** Creates an empty map. */
        public function __construct() { $this->__mcbuf = __mc_hmap_alloc(0); }
        public function __destruct() { if ($this->__mcbuf !== 0) { __mc_hmap_free($this->__mcbuf); $this->__mcbuf = 0; } }
        /** Makes the clone an independent map with the same entries (values are copied shallowly). */
        public function __clone() { $this->__mcbuf = __mc_hmap_clone($this->__mcbuf); }

        /**
         * Returns the value stored under `$key`.
         *
         * When the key is absent the call returns `$default` if one was passed
         * (even an explicit `null`), and throws `OutOfBoundsException` if not.
         * `$map[$key]` is the same as `get($key)` without a default.
         *
         * @param K $key  the key to look up
         * @param mixed $default  returned when `$key` is absent; omit it to get an exception instead
         * @return V  the stored value
         * @throws \OutOfBoundsException  when the key is absent and no default was passed
         * @throws \TypeError  when `$key` is not an int, a string or an object
         */
        public function get(mixed $key, mixed $default = null): mixed
        {
            $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e >= 0) { return __mc_hmap_val($this->__mcbuf, $e); }
            if ($e === -2) { __hkey($key, 'Map'); }
            if (\func_num_args() > 1) { return $default; }
            throw new \OutOfBoundsException('Key not found');
        }

        /**
         * Stores `$value` under `$key`, replacing any previous value.
         *
         * A new key goes to the end of the iteration order; an existing key
         * keeps its position. `$map[$key] = $value` is the same call.
         *
         * @param K $key  the key
         * @param V $value  the value to store
         * @throws \TypeError  when `$key` is not an int, a string or an object
         */
        public function set(mixed $key, mixed $value): void
        {
            if (__mc_hmap_put($this->__mcbuf, $key, $value) === -2) { __hkey($key, 'Map'); }
        }

        /**
         * Tells whether `$key` is present, whatever its value (a stored `null` counts).
         *
         * `isset($map[$key])` calls this method.
         *
         * @param K $key  the key to look up
         * @return bool  true when the key is present
         * @throws \TypeError  when `$key` is not an int, a string or an object
         */
        public function has(mixed $key): bool
        {
            $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e === -2) { __hkey($key, 'Map'); }
            return $e >= 0;
        }

        /**
         * Removes `$key` and returns the value it held.
         *
         * `unset($map[$key])` does the same but ignores an absent key.
         *
         * @param K $key  the key to remove
         * @return V  the removed value
         * @throws \OutOfBoundsException  when the key is absent
         * @throws \TypeError  when `$key` is not an int, a string or an object
         */
        public function remove(mixed $key): mixed
        {
            $e = __mc_hmap_find($this->__mcbuf, $key);
            if ($e < 0) { if ($e === -2) { __hkey($key, 'Map'); } throw new \OutOfBoundsException('Key not found'); }
            $v = __mc_hmap_val($this->__mcbuf, $e);
            __mc_hmap_delat($this->__mcbuf, $e);
            return $v;
        }

        /**
         * Returns the number of entries.
         *
         * @return int  the entry count
         */
        public function count(): int { return __mc_hmap_len($this->__mcbuf); }

        /**
         * Tells whether the map has no entries.
         *
         * @return bool  true when empty
         */
        public function isEmpty(): bool { return __mc_hmap_len($this->__mcbuf) === 0; }

        /** Removes every entry. */
        public function clear(): void { __mc_hmap_clear($this->__mcbuf); }

        /**
         * Copies the entries into a PHP array, in iteration order.
         *
         * Only maps whose keys can be array keys convert: an object key throws
         * `TypeError`, and two keys that PHP arrays would merge (`1` and `'1'`)
         * throw `ValueError`.
         *
         * @return array<int|string, V>  the entries as a PHP array
         * @throws \TypeError  when a key is an object
         * @throws \ValueError  when two keys collide in a PHP array
         */
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

        /**
         * Iterates the entries in insertion order, yielding `key => value`.
         *
         * @return \Generator  a generator over the entries
         * @throws \RuntimeException  when the map is changed in a way the running loop cannot follow
         */
        public function getIterator(): \Generator
        {
            $h = $this->__mcbuf;
            $epoch = __mc_hmap_epoch($h);
            for ($e = __mc_hmap_next($h, 0); $e >= 0; $e = __mc_hmap_next($h, $e + 1)) {
                yield __mc_hmap_key($h, $e) => __mc_hmap_val($h, $e);
                if (__mc_hmap_epoch($h) !== $epoch) { __iter_modified('Map'); }
            }
        }

        /**
         * Array-access form of {@see has()}: `isset($map[$offset])`.
         *
         * @param mixed $offset  the key
         * @return bool  true when the key is present
         */
        public function offsetExists(mixed $offset): bool { return $this->has($offset); }

        /**
         * Array-access form of {@see get()}: `$map[$offset]`.
         *
         * @param mixed $offset  the key
         * @return mixed  the stored value
         * @throws \OutOfBoundsException  when the key is absent
         */
        public function offsetGet(mixed $offset): mixed { return $this->get($offset); }

        /**
         * Array-access form of {@see set()}: `$map[$offset] = $value`.
         *
         * @param mixed $offset  the key
         * @param mixed $value  the value to store
         * @throws \Error  on `$map[] = $value`: a map has no append, use `set()`
         */
        public function offsetSet(mixed $offset, mixed $value): void
        {
            if ($offset === null) { throw new \Error('Cannot append to a Map; use set()'); }
            $this->set($offset, $value);
        }

        /**
         * Removes `$offset` if present: `unset($map[$offset])`. An absent key is ignored.
         *
         * @param mixed $offset  the key
         * @throws \TypeError  when `$offset` is not an int, a string or an object
         */
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

        /**
         * Encodes as a JSON object (an empty map is `{}`).
         *
         * Uses {@see toArray()}, so the same key restrictions apply.
         *
         * @return mixed  an object ready for `json_encode`
         * @throws \TypeError  when a key is an object
         * @throws \ValueError  when two keys collide in a PHP array
         */
        public function jsonSerialize(): mixed { return (object) $this->toArray(); }

        /**
         * Returns the keys, in iteration order.
         *
         * @return Vec<K>  a new vector of the keys
         */
        public function keys(): Vec
        {
            $out = new Vec();
            for ($e = __mc_hmap_next($this->__mcbuf, 0); $e >= 0; $e = __mc_hmap_next($this->__mcbuf, $e + 1)) { $out->push(__mc_hmap_key($this->__mcbuf, $e)); }
            return $out;
        }

        /**
         * Returns the values, in iteration order.
         *
         * @return Vec<V>  a new vector of the values
         */
        public function values(): Vec
        {
            $out = new Vec();
            for ($e = __mc_hmap_next($this->__mcbuf, 0); $e >= 0; $e = __mc_hmap_next($this->__mcbuf, $e + 1)) { $out->push(__mc_hmap_val($this->__mcbuf, $e)); }
            return $out;
        }

        /**
         * Calls `$f($value, $key)` for every entry, in order.
         *
         * @param callable(V, K): void $f  the callback
         */
        public function each(callable $f): void { foreach ($this as $k => $v) { $f($v, $k); } }

        /**
         * Builds a new map with the same keys and `$f($value, $key)` as values.
         *
         * @template U
         * @param callable(V, K): U $f  maps a value (and its key) to the new value
         * @return Map<K, U>  a new map; this one is unchanged
         */
        public function map(callable $f): Map { $r = new Map(); foreach ($this as $k => $v) { $r->set($k, $f($v, $k)); } return $r; }

        /**
         * Builds a new map with the entries for which `$f($value, $key)` is truthy.
         *
         * @param callable(V, K): bool $f  the predicate
         * @return Map<K, V>  a new map; this one is unchanged
         */
        public function filter(callable $f): Map { $r = new Map(); foreach ($this as $k => $v) { if ($f($v, $k)) { $r->set($k, $v); } } return $r; }

        /**
         * Folds the entries into one value: `$carry = $f($carry, $value, $key)`.
         *
         * @template R
         * @param callable(R, V, K): R $f  combines the carry with the next entry
         * @param R $initial  the starting carry, also the result for an empty map
         * @return R  the final carry
         */
        public function reduce(callable $f, mixed $initial): mixed { $c = $initial; foreach ($this as $k => $v) { $c = $f($c, $v, $k); } return $c; }

        /**
         * Tells whether `$f($value, $key)` is truthy for at least one entry (stops at the first).
         *
         * @param callable(V, K): bool $f  the predicate
         * @return bool  false for an empty map
         */
        public function any(callable $f): bool { foreach ($this as $k => $v) { if ($f($v, $k)) { return true; } } return false; }

        /**
         * Tells whether `$f($value, $key)` is truthy for every entry (stops at the first miss).
         *
         * @param callable(V, K): bool $f  the predicate
         * @return bool  true for an empty map
         */
        public function all(callable $f): bool { foreach ($this as $k => $v) { if (!$f($v, $k)) { return false; } } return true; }

        /**
         * Returns the first value for which `$f($value, $key)` is truthy.
         *
         * `null` means "none matched" but is also a valid stored value; use
         * {@see any()} when the map can hold `null`.
         *
         * @param callable(V, K): bool $f  the predicate
         * @return ?V  the first matching value, or null
         */
        public function find(callable $f): mixed { foreach ($this as $k => $v) { if ($f($v, $k)) { return $v; } } return null; }
    }

    /**
     * An insertion-ordered collection of unique values.
     *
     * Values may be `int`, `string` or objects, with the same rules as
     * {@see Map} keys: `1` and `'1'` are different members, objects are
     * compared by identity, and any other type throws `TypeError`. Adding a
     * value that is already present does nothing. `add()`, `has()` and
     * `remove()` run in O(1) on average.
     *
     * Iteration is in insertion order and yields the positions `0, 1, 2, …` as
     * keys. Like `Map`, a `Set` is an object (assignment copies the handle,
     * `clone` copies the contents), and it must not be changed while it is
     * being iterated.
     *
     * ```php
     * $tags = new Set();
     * $tags->add('php');
     * $tags->add('php');
     * echo count($tags);   // 1
     * ```
     *
     * @template T
     * @implements \IteratorAggregate<int, T>
     * @example doc_ds_set_basic
     * @see Map
     */
    final class Set implements \Countable, \IteratorAggregate, \JsonSerializable
    {
        private int $__mcbuf = 0;

        /** Creates an empty set. */
        public function __construct() { $this->__mcbuf = __mc_hmap_alloc(1); }
        public function __destruct() { if ($this->__mcbuf !== 0) { __mc_hmap_free($this->__mcbuf); $this->__mcbuf = 0; } }
        /** Makes the clone an independent set with the same members. */
        public function __clone() { $this->__mcbuf = __mc_hmap_clone($this->__mcbuf); }

        /**
         * Adds `$value`; a value already in the set is left where it is.
         *
         * @param T $value  the member to add
         * @throws \TypeError  when `$value` is not an int, a string or an object
         */
        public function add(mixed $value): void
        {
            if (__mc_hmap_put($this->__mcbuf, $value, null) === -2) { __hkey($value, 'Set'); }
        }
        /**
         * Tells whether `$value` is a member.
         *
         * @param T $value  the value to look for
         * @return bool  true when present
         * @throws \TypeError  when `$value` is not an int, a string or an object
         */
        public function has(mixed $value): bool
        {
            $e = __mc_hmap_find($this->__mcbuf, $value);
            if ($e === -2) { __hkey($value, 'Set'); }
            return $e >= 0;
        }
        /**
         * Removes `$value`.
         *
         * @param T $value  the member to remove
         * @throws \OutOfBoundsException  when `$value` is not a member
         * @throws \TypeError  when `$value` is not an int, a string or an object
         */
        public function remove(mixed $value): void
        {
            $e = __mc_hmap_find($this->__mcbuf, $value);
            if ($e < 0) { if ($e === -2) { __hkey($value, 'Set'); } throw new \OutOfBoundsException('Value not found'); }
            __mc_hmap_delat($this->__mcbuf, $e);
        }

        /**
         * Returns the number of members.
         *
         * @return int  the member count
         */
        public function count(): int { return __mc_hmap_len($this->__mcbuf); }

        /**
         * Tells whether the set has no members.
         *
         * @return bool  true when empty
         */
        public function isEmpty(): bool { return __mc_hmap_len($this->__mcbuf) === 0; }

        /** Removes every member. */
        public function clear(): void { __mc_hmap_clear($this->__mcbuf); }

        /**
         * Copies the members into a list, in insertion order.
         *
         * @return list<T>  the members
         */
        public function toArray(): array
        {
            $out = [];
            for ($e = __mc_hmap_next($this->__mcbuf, 0); $e >= 0; $e = __mc_hmap_next($this->__mcbuf, $e + 1)) { $out[] = __mc_hmap_key($this->__mcbuf, $e); }
            return $out;
        }

        /**
         * Returns a new set with the members of both sets.
         *
         * Members of this set come first, then the ones only `$other` has.
         *
         * @param Set<T> $other  the other set
         * @return Set<T>  a new set; neither input changes
         */
        public function union(Set $other): Set { $r = clone $this; foreach ($other as $v) { $r->add($v); } return $r; }
        /**
         * Returns a new set with the members present in both sets, in this set's order.
         *
         * @param Set<T> $other  the other set
         * @return Set<T>  a new set; neither input changes
         */
        public function intersect(Set $other): Set { $r = new Set(); foreach ($this as $v) { if ($other->has($v)) { $r->add($v); } } return $r; }
        /**
         * Returns a new set with the members of this set that `$other` lacks, in this set's order.
         *
         * @param Set<T> $other  the set to subtract
         * @return Set<T>  a new set; neither input changes
         */
        public function diff(Set $other): Set { $r = new Set(); foreach ($this as $v) { if (!$other->has($v)) { $r->add($v); } } return $r; }

        /**
         * Iterates the members in insertion order; the keys are the positions `0, 1, 2, …`.
         *
         * @return \Generator  a generator over the members
         * @throws \RuntimeException  when the set is changed in a way the running loop cannot follow
         */
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

        /**
         * Encodes as a JSON array of the members.
         *
         * @return mixed  the list from {@see toArray()}
         */
        public function jsonSerialize(): mixed { return $this->toArray(); }

        /**
         * Calls `$f($member)` for every member, in order.
         *
         * @param callable(T): void $f  the callback
         */
        public function each(callable $f): void { foreach ($this as $v) { $f($v); } }

        /**
         * Builds a new set from `$f($member)` for every member.
         *
         * Results that coincide collapse into one member.
         *
         * @template U
         * @param callable(T): U $f  maps a member to the new member
         * @return Set<U>  a new set; this one is unchanged
         */
        public function map(callable $f): Set { $r = new Set(); foreach ($this as $v) { $r->add($f($v)); } return $r; }

        /**
         * Builds a new set with the members for which `$f($member)` is truthy.
         *
         * @param callable(T): bool $f  the predicate
         * @return Set<T>  a new set; this one is unchanged
         */
        public function filter(callable $f): Set { $r = new Set(); foreach ($this as $v) { if ($f($v)) { $r->add($v); } } return $r; }

        /**
         * Folds the members into one value: `$carry = $f($carry, $member)`.
         *
         * @template R
         * @param callable(R, T): R $f  combines the carry with the next member
         * @param R $initial  the starting carry, also the result for an empty set
         * @return R  the final carry
         */
        public function reduce(callable $f, mixed $initial): mixed { $c = $initial; foreach ($this as $v) { $c = $f($c, $v); } return $c; }

        /**
         * Tells whether `$f($member)` is truthy for at least one member (stops at the first).
         *
         * @param callable(T): bool $f  the predicate
         * @return bool  false for an empty set
         */
        public function any(callable $f): bool { foreach ($this as $v) { if ($f($v)) { return true; } } return false; }

        /**
         * Tells whether `$f($member)` is truthy for every member (stops at the first miss).
         *
         * @param callable(T): bool $f  the predicate
         * @return bool  true for an empty set
         */
        public function all(callable $f): bool { foreach ($this as $v) { if (!$f($v)) { return false; } } return true; }

        /**
         * Returns the first member for which `$f($member)` is truthy.
         *
         * `null` means "none matched" but is also a valid member; use
         * {@see any()} when the set can hold `null`.
         *
         * @param callable(T): bool $f  the predicate
         * @return ?T  the first matching member, or null
         */
        public function find(callable $f): mixed { foreach ($this as $v) { if ($f($v)) { return $v; } } return null; }
    }

    /**
     * A growable list of values of any type, indexed `0..count-1`.
     *
     * A `Vec` holds arbitrary PHP values (use the typed arrays such as
     * {@see Int32Array} when every element is a fixed-width number). Reads and
     * writes by index are O(1), `push()` is amortised O(1). Unlike a PHP array
     * it has no gaps and no string keys: an index outside `0..count-1` throws
     * `OutOfBoundsException`, a non-int index throws `TypeError`, and elements
     * cannot be `unset()`; use `pop()` to drop the last one.
     *
     * A `Vec` is an object (assignment copies the handle, `clone` copies the
     * elements shallowly). It implements `ArrayAccess` (`$v[$i]`, `$v[] = $x`),
     * `Countable`, `IteratorAggregate` and `JsonSerializable` (encoded as a
     * JSON array).
     *
     * ```php
     * $v = new Vec();
     * $v->push('a');
     * $v[] = 'b';
     * echo count($v);   // 2
     * ```
     *
     * @template T
     * @implements \ArrayAccess<int, T>
     * @implements \IteratorAggregate<int, T>
     * @example doc_ds_vec_basic
     * @see Map
     * @see Set
     */
    final class Vec implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
    {
        private int $__mcbuf = 0;

        /** Creates an empty vector. */
        // 11 = MemoryAbi::BUF_KIND_CELL (prelude cannot read MemoryAbi)
        public function __construct() { $this->__mcbuf = __mc_nbuf_alloc(11, 0); }
        public function __destruct() { if ($this->__mcbuf !== 0) { __mc_nbuf_free($this->__mcbuf); $this->__mcbuf = 0; } }
        /** Makes the clone an independent vector with the same elements (copied shallowly). */
        public function __clone() { $this->__mcbuf = __mc_nbuf_clone($this->__mcbuf); }

        /**
         * Builds a vector from the values of `$values`; the keys are ignored.
         *
         * @param array<T> $values  the source array
         * @return Vec<T>  a new vector holding the values in array order
         */
        public static function fromArray(array $values): Vec { $v = new Vec(); foreach ($values as $x) { $v->push($x); } return $v; }

        /**
         * Appends `$value`; `$v[] = $value` is the same.
         *
         * @param T $value  the element to append
         */
        public function push(mixed $value): void
        {
            $n = __mc_nbuf_len($this->__mcbuf);
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $n + 1);
            __mc_nbuf_set_c($this->__mcbuf, $n, $value);
        }

        /**
         * Removes and returns the last element.
         *
         * @return T  the removed element
         * @throws \UnderflowException  when the vector is empty
         */
        public function pop(): mixed
        {
            $n = __mc_nbuf_len($this->__mcbuf);
            if ($n === 0) { throw new \UnderflowException('Cannot pop from an empty Vec'); }
            $v = __mc_nbuf_get_c($this->__mcbuf, $n - 1);
            $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, $n - 1);
            return $v;
        }

        /**
         * Returns the number of elements.
         *
         * @return int  the element count
         */
        public function count(): int { return __mc_nbuf_len($this->__mcbuf); }

        /**
         * Tells whether the vector has no elements.
         *
         * @return bool  true when empty
         */
        public function isEmpty(): bool { return __mc_nbuf_len($this->__mcbuf) === 0; }

        /** Removes every element. */
        public function clear(): void { $this->__mcbuf = __mc_nbuf_resize($this->__mcbuf, 0); }

        private function at(mixed $offset): int
        {
            if (!\is_int($offset)) { throw new \TypeError('Cannot access offset of type ' . \get_debug_type($offset) . ' on Vec'); }
            if ($offset < 0 || $offset >= __mc_nbuf_len($this->__mcbuf)) { throw new \OutOfBoundsException('Index invalid or out of range'); }
            return $offset;
        }

        /**
         * Tells whether `$offset` is an int index inside `0..count-1`: `isset($v[$offset])`.
         *
         * Any other `$offset` (including a numeric string) answers false.
         *
         * @param mixed $offset  the index
         * @return bool  true when the index is valid
         */
        public function offsetExists(mixed $offset): bool { return \is_int($offset) && $offset >= 0 && $offset < __mc_nbuf_len($this->__mcbuf); }

        /**
         * Returns the element at `$offset`: `$v[$offset]`.
         *
         * @param mixed $offset  the index
         * @return mixed  the element
         * @throws \OutOfBoundsException  when the index is outside `0..count-1`
         * @throws \TypeError  when the index is not an int
         */
        public function offsetGet(mixed $offset): mixed { return __mc_nbuf_get_c($this->__mcbuf, $this->at($offset)); }

        /**
         * Replaces the element at `$offset`: `$v[$offset] = $value`; with a `null` offset (`$v[] = $value`) it appends.
         *
         * @param mixed $offset  the index, or null to append
         * @param mixed $value  the new element
         * @throws \OutOfBoundsException  when the index is outside `0..count-1`
         * @throws \TypeError  when the index is not an int
         */
        public function offsetSet(mixed $offset, mixed $value): void
        {
            if ($offset === null) { $this->push($value); return; }
            __mc_nbuf_set_c($this->__mcbuf, $this->at($offset), $value);
        }

        /**
         * Always throws: elements cannot be removed from the middle.
         *
         * @param mixed $offset  the index
         * @throws \Error  always; use `pop()` to drop the last element
         */
        public function offsetUnset(mixed $offset): void { throw new \Error('Cannot unset a Vec element; use pop()'); }

        /**
         * Copies the elements into a PHP list.
         *
         * @return list<T>  the elements in index order
         */
        public function toArray(): array
        {
            $out = [];
            for ($i = 0, $n = __mc_nbuf_len($this->__mcbuf); $i < $n; $i++) { $out[] = __mc_nbuf_get_c($this->__mcbuf, $i); }
            return $out;
        }

        /**
         * Iterates the elements by index, yielding `index => element`.
         *
         * @return \Generator  a generator over the elements
         */
        public function getIterator(): \Generator
        {
            for ($i = 0; $i < __mc_nbuf_len($this->__mcbuf); $i++) { yield $i => __mc_nbuf_get_c($this->__mcbuf, $i); }
        }

        public function __serialize(): array { return $this->toArray(); }
        public function __unserialize(array $data): void { $this->__mcbuf = __mc_nbuf_alloc(11, 0); foreach ($data as $x) { $this->push($x); } }
        public function __debugInfo(): array { return $this->toArray(); }

        /**
         * Encodes as a JSON array of the elements.
         *
         * @return mixed  the list from {@see toArray()}
         */
        public function jsonSerialize(): mixed { return $this->toArray(); }

        /**
         * Calls `$f($element, $index)` for every element, in order.
         *
         * @param callable(T, int): void $f  the callback
         */
        public function each(callable $f): void { foreach ($this as $k => $v) { $f($v, $k); } }

        /**
         * Builds a new vector of `$f($element, $index)` for every element.
         *
         * @template U
         * @param callable(T, int): U $f  maps an element (and its index) to the new element
         * @return Vec<U>  a new vector; this one is unchanged
         */
        public function map(callable $f): Vec { $r = new Vec(); foreach ($this as $k => $v) { $r->push($f($v, $k)); } return $r; }

        /**
         * Builds a new vector with the elements for which `$f($element, $index)` is truthy.
         *
         * The kept elements are renumbered from 0.
         *
         * @param callable(T, int): bool $f  the predicate
         * @return Vec<T>  a new vector; this one is unchanged
         */
        public function filter(callable $f): Vec { $r = new Vec(); foreach ($this as $k => $v) { if ($f($v, $k)) { $r->push($v); } } return $r; }

        /**
         * Folds the elements into one value: `$carry = $f($carry, $element, $index)`.
         *
         * @template R
         * @param callable(R, T, int): R $f  combines the carry with the next element
         * @param R $initial  the starting carry, also the result for an empty vector
         * @return R  the final carry
         */
        public function reduce(callable $f, mixed $initial): mixed { $c = $initial; foreach ($this as $k => $v) { $c = $f($c, $v, $k); } return $c; }

        /**
         * Tells whether `$f($element, $index)` is truthy for at least one element (stops at the first).
         *
         * @param callable(T, int): bool $f  the predicate
         * @return bool  false for an empty vector
         */
        public function any(callable $f): bool { foreach ($this as $k => $v) { if ($f($v, $k)) { return true; } } return false; }

        /**
         * Tells whether `$f($element, $index)` is truthy for every element (stops at the first miss).
         *
         * @param callable(T, int): bool $f  the predicate
         * @return bool  true for an empty vector
         */
        public function all(callable $f): bool { foreach ($this as $k => $v) { if (!$f($v, $k)) { return false; } } return true; }

        /**
         * Returns the first element for which `$f($element, $index)` is truthy.
         *
         * `null` means "none matched" but is also a valid element; use
         * {@see any()} when the vector can hold `null`.
         *
         * @param callable(T, int): bool $f  the predicate
         * @return ?T  the first matching element, or null
         */
        public function find(callable $f): mixed { foreach ($this as $k => $v) { if ($f($v, $k)) { return $v; } } return null; }
    }
}
