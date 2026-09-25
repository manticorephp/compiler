<?php

// WeakMap and WeakReference (php 8.0 / 7.4 core). Neither holds its object:
// both keep the object's ADDRESS (`spl_object_id`) and learn of its death
// from the free path, which calls `__mc_weak_forget` through the hook
// `__mc_weak_arm()` installs on the first enrollment ({@see
// EmitLlvmRuntime::dropRuntimeBody}). A program that never enrolls an
// object pays one null test per free.
//
// Everything a map holds lives in the registry, keyed by map id, not on the
// map object: the death hook has to reach it with nothing but an address.
// A removed value is always moved into a local before the registry forgets
// it, so a destructor it runs cannot re-enter a half-updated registry.

final class __McWeak
{
    /** @var array<int, int> address => enrollment serial of the live object there */
    public static array $serial = [];
    /** @var array<int, array<int, bool>> address => ids of the maps keyed by it */
    public static array $maps = [];
    /** @var array<int, array<int, mixed>> map id => (address => value) */
    public static array $data = [];
    /** @var array<int, WeakReference> address => the object's one WeakReference */
    public static array $refs = [];
    public static int $next = 0;

    public static function enroll(object $o): int
    {
        $a = \spl_object_id($o);
        if (!isset(self::$serial[$a])) {
            \__mc_weak_arm();
            self::$next = self::$next + 1;
            self::$serial[$a] = self::$next;
        }
        return $a;
    }

    public static function newMap(): int
    {
        self::$next = self::$next + 1;
        self::$data[self::$next] = [];
        return self::$next;
    }
}

function __mc_weak_forget(int $addr): int
{
    if (!isset(__McWeak::$serial[$addr])) { return 0; }
    unset(__McWeak::$serial[$addr]);
    $ref = __McWeak::$refs[$addr] ?? null;
    unset(__McWeak::$refs[$addr]);
    /** @var mixed[] $dead */
    $dead = [];
    if (isset(__McWeak::$maps[$addr])) {
        $ids = __McWeak::$maps[$addr];
        unset(__McWeak::$maps[$addr]);
        foreach ($ids as $id => $_) {
            if (isset(__McWeak::$data[$id]) && \array_key_exists($addr, __McWeak::$data[$id])) {
                $dead[] = __McWeak::$data[$id][$addr];
                unset(__McWeak::$data[$id][$addr]);
            }
        }
    }
    return \count($dead) + ($ref === null ? 0 : 1);
}

final class WeakMap implements ArrayAccess, Countable, IteratorAggregate
{
    private int $__id;

    public function __construct()
    {
        $this->__id = __McWeak::newMap();
    }

    private static function key(mixed $object): object
    {
        if (!\is_object($object)) {
            throw new TypeError('WeakMap key must be an object');
        }
        return $object;
    }

    public function offsetGet(mixed $object): mixed
    {
        $o = self::key($object);
        $a = \spl_object_id($o);
        if (!isset(__McWeak::$serial[$a]) || !\array_key_exists($a, __McWeak::$data[$this->__id])) {
            throw new Error('Object ' . \get_class($o) . '#' . (string)$a . ' not contained in WeakMap');
        }
        return __McWeak::$data[$this->__id][$a];
    }

    public function offsetSet(mixed $object, mixed $value): void
    {
        if ($object === null) {
            throw new Error('Cannot append to WeakMap');
        }
        $a = __McWeak::enroll(self::key($object));
        $old = __McWeak::$data[$this->__id][$a] ?? null;
        __McWeak::$maps[$a][$this->__id] = true;
        __McWeak::$data[$this->__id][$a] = $value;
        unset($old);
    }

    public function offsetExists(mixed $object): bool
    {
        $a = \spl_object_id(self::key($object));
        return isset(__McWeak::$serial[$a]) && isset(__McWeak::$data[$this->__id][$a]);
    }

    public function offsetUnset(mixed $object): void
    {
        $a = \spl_object_id(self::key($object));
        if (!\array_key_exists($a, __McWeak::$data[$this->__id])) { return; }
        $old = __McWeak::$data[$this->__id][$a];
        unset(__McWeak::$data[$this->__id][$a]);
        unset(__McWeak::$maps[$a][$this->__id]);
        unset($old);
    }

    public function count(): int
    {
        return \count(__McWeak::$data[$this->__id]);
    }

    public function getIterator(): Iterator
    {
        foreach (__McWeak::$data[$this->__id] as $a => $v) {
            if (!isset(__McWeak::$serial[$a])) { continue; }
            yield \__mc_obj_from_addr($a) => $v;
        }
    }

    public function __clone()
    {
        $src = __McWeak::$data[$this->__id];
        $this->__id = __McWeak::newMap();
        foreach ($src as $a => $v) {
            __McWeak::$maps[$a][$this->__id] = true;
        }
        __McWeak::$data[$this->__id] = $src;
    }

    public function __destruct()
    {
        $held = __McWeak::$data[$this->__id] ?? [];
        unset(__McWeak::$data[$this->__id]);
        foreach ($held as $a => $_) {
            unset(__McWeak::$maps[$a][$this->__id]);
        }
        unset($held);
    }

    public function __serialize(): array
    {
        throw new Exception("Serialization of 'WeakMap' is not allowed");
    }
}

final class WeakReference
{
    private int $__a = 0;
    private int $__s = 0;

    private function __construct()
    {
    }

    public static function create(object $object): WeakReference
    {
        $a = __McWeak::enroll($object);
        $r = __McWeak::$refs[$a] ?? null;
        if ($r !== null) { return $r; }
        $r = new WeakReference();
        $r->__a = $a;
        $r->__s = __McWeak::$serial[$a];
        __McWeak::$refs[$a] = $r;
        return $r;
    }

    public function get(): ?object
    {
        if ((__McWeak::$serial[$this->__a] ?? 0) !== $this->__s) { return null; }
        return \__mc_obj_from_addr($this->__a);
    }

    public function __serialize(): array
    {
        throw new Exception("Serialization of 'WeakReference' is not allowed");
    }
}
