<?php

/**
 * Built-in SPL array classes, injected into a user program (as a parsed
 * prelude) ONLY when it references `ArrayIterator` or `ArrayObject`
 * (see Main::lower_module + LowerFromAst::$includeArrayClasses) — so the
 * cell runtime is not pulled into every binary.
 *
 * This file lives OUTSIDE `src/` on purpose: `src/` is compiled into the
 * compiler binary (and `src/Runtime` into stdlib.o), so a class here would
 * be double-defined. The compiler READS this file at compile time and parses
 * it as guest source.
 *
 * Backing store is a `mixed` (cell) array so any value type round-trips; the
 * keys list is rebuilt with a foreach (NOT array_keys — historically a
 * prelude-only call wouldn't link; array_keys is now a codegen builtin but
 * the foreach keeps the prelude self-contained). All key/value params are
 * `mixed` so the call sites NaN-box them, then the cell array
 * store/get/isset/unset/foreach paths handle them.
 *
 * NOTE: LowerFromAst::arrayClassesPreludeSrc() keeps a byte-identical
 * embedded copy as the bootstrap/distribution fallback (when this file
 * can't be read). Keep the two in sync.
 */

class ArrayIterator implements Iterator, ArrayAccess, Countable
{
    private mixed $__s;
    private mixed $__k;
    private int $__i = 0;

    public function __construct(mixed $array = [])
    {
        $this->__s = $array;
        $this->__rebuildKeys();
    }

    private function __rebuildKeys(): void
    {
        $ks = [];
        foreach ($this->__s as $k => $v) {
            $ks[] = $k;
        }
        $this->__k = $ks;
    }

    public function rewind(): void
    {
        $this->__rebuildKeys();
        $this->__i = 0;
    }

    public function valid(): bool
    {
        return $this->__i < count($this->__k);
    }

    public function current(): mixed
    {
        return $this->__s[$this->__k[$this->__i]];
    }

    public function key(): mixed
    {
        return $this->__k[$this->__i];
    }

    public function next(): void
    {
        $this->__i = $this->__i + 1;
    }

    public function offsetExists(mixed $o): bool
    {
        return isset($this->__s[$o]);
    }

    public function offsetGet(mixed $o): mixed
    {
        return $this->__s[$o];
    }

    public function offsetSet(mixed $o, mixed $v): void
    {
        if ($o === null) {
            $this->__s[] = $v;
        } else {
            $this->__s[$o] = $v;
        }
    }

    public function offsetUnset(mixed $o): void
    {
        unset($this->__s[$o]);
    }

    public function count(): int
    {
        return count($this->__s);
    }

    public function append(mixed $v): void
    {
        $this->__s[] = $v;
    }

    public function getArrayCopy(): mixed
    {
        return $this->__s;
    }
}

class ArrayObject implements IteratorAggregate, ArrayAccess, Countable
{
    private mixed $__s;

    public function __construct(mixed $array = [])
    {
        $this->__s = $array;
    }

    public function offsetExists(mixed $o): bool
    {
        return isset($this->__s[$o]);
    }

    public function offsetGet(mixed $o): mixed
    {
        return $this->__s[$o];
    }

    public function offsetSet(mixed $o, mixed $v): void
    {
        if ($o === null) {
            $this->__s[] = $v;
        } else {
            $this->__s[$o] = $v;
        }
    }

    public function offsetUnset(mixed $o): void
    {
        unset($this->__s[$o]);
    }

    public function count(): int
    {
        return count($this->__s);
    }

    public function append(mixed $v): void
    {
        $this->__s[] = $v;
    }

    public function getArrayCopy(): mixed
    {
        return $this->__s;
    }

    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->__s);
    }
}
