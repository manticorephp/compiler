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
    // Declared LAST: a property added mid-class shifts every later offset.
    private int $__f = 0;

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

    public function getFlags(): int
    {
        return $this->__f;
    }

    public function setFlags(int $flags): void
    {
        $this->__f = $flags;
    }

    /**
     * php's own shape: [flags, storage, extraProps, iteratorClass]. The 4th is
     * NULL for an ArrayIterator, and the 3rd is the dynamic properties the
     * instance carries — always empty here, because this class declares no
     * public slots and php 8.5 deprecates adding one.
     */
    public function __serialize(): array
    {
        return [$this->__f, $this->__s, [], null];
    }

    public function __unserialize(array $data): void
    {
        $this->__f = (int)($data[0] ?? 0);
        $this->__s = $data[1] ?? [];
        $this->__rebuildKeys();
        $this->__i = 0;
    }
}

class ArrayObject implements IteratorAggregate, ArrayAccess, Countable
{
    private mixed $__s;
    // Declared LAST, same reason as ArrayIterator's.
    private int $__f = 0;
    private mixed $__ic = null;

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

    public function getFlags(): int
    {
        return $this->__f;
    }

    public function setFlags(int $flags): void
    {
        $this->__f = $flags;
    }

    public function getIteratorClass(): string
    {
        // php answers the default name, not null, when none was ever set.
        $c = $this->__ic;
        return $c === null ? 'ArrayIterator' : (string)$c;
    }

    public function setIteratorClass(string $class): void
    {
        $this->__ic = $class;
    }

    /** [flags, storage, extraProps, iteratorClass] — {@see ArrayIterator::__serialize}. */
    public function __serialize(): array
    {
        return [$this->__f, $this->__s, [], $this->__ic];
    }

    public function __unserialize(array $data): void
    {
        $this->__f = (int)($data[0] ?? 0);
        $this->__s = $data[1] ?? [];
        // symfony/var-exporter's Hydrator passes only three elements for an
        // ArrayIterator and four for an ArrayObject, so the tail is optional.
        $this->__ic = $data[3] ?? null;
    }
}

/**
 * iterator_to_array — drain a Traversable (or an array) into an array.
 *
 * In the PRELUDE, not the stdlib: it consumes an ITERATOR, and a generator /
 * IteratorAggregate cannot cross the stdlib.o boundary any more than a callback
 * can.
 *
 * ⚠ The Generator case goes through a \Generator-TYPED funnel on purpose.
 * `foreach` picks its loop shape from the subject's STATIC type
 * ({@see EmitLlvmControl::emitForeach}), so an erased `mixed` holding a
 * generator fell through to the ARRAY path — it read a length word off an
 * object pointer and iterated zero times, silently. Handing the value to a
 * typed parameter re-types it at the callee, the same trick the guard folder
 * needed for its AST nodes.
 *
 * A Generator cannot be recognised directly: it is compiler-synthesised state
 * with NO class descriptor, so `instanceof \Generator` and `get_class($gen)`
 * answer false / '' even for a concrete one. The test is therefore INVERTED —
 * a user Iterator / IteratorAggregate does have a descriptor and answers its
 * own interfaces, and a Generator answers false to both — so everything with a
 * real class is handled here and the generator arm keeps the fallthrough.
 */
function iterator_to_array(mixed $iterator, bool $preserve_keys = true): array
{
    $out = [];
    if (\is_array($iterator)) {
        if ($preserve_keys) { return $iterator; }
        foreach ($iterator as $v) { $out[] = $v; }
        return $out;
    }
    // The aggregate hop is taken HERE, not inside a resolver: what
    // getIterator() hands back is very often a GENERATOR, and a generator has
    // no descriptor, so a resolver can only answer null for it — and the
    // caller would then send the ORIGINAL aggregate down the generator arm.
    // `CapBag implements IteratorAggregate { getIterator(): Generator }` did
    // exactly that: the object's refcount was read as `state@8`, the resume
    // step loaded slot 0 (the class descriptor pointer) and jumped into a
    // read-only page. SIGBUS, several statements away from any foreach.
    if ($iterator instanceof \IteratorAggregate) {
        // php unwraps as many aggregate layers as it finds.
        return iterator_to_array($iterator->getIterator(), $preserve_keys);
    }
    $it = __mc_iter_resolve($iterator);
    if ($it !== null) { return __mc_iter_drive_to_array($it, $preserve_keys); }
    return __mc_iter_gen_to_array($iterator, $preserve_keys);
}

/**
 * The concrete \Iterator behind a Traversable, or null when the value is a
 * Generator (no descriptor) and belongs on the generator path.
 *
 * ⚠ Callers must take the IteratorAggregate hop THEMSELVES before asking — a
 * hop taken in here is invisible to the caller's generator fallback.
 */
function __mc_iter_resolve(mixed $iterator): ?\Iterator
{
    if ($iterator instanceof \Iterator) { return $iterator; }
    return null;
}

/**
 * The Iterator protocol driven BY HAND rather than with `foreach`: a foreach
 * over an erased base classifies its subject at run time and has its own open
 * holes, and the five methods have none.
 */
function __mc_iter_drive_to_array(\Iterator $it, bool $preserve_keys): array
{
    /** @var array<int|string,mixed> $out */
    $out = [];
    $it->rewind();
    while ($it->valid()) {
        $v = $it->current();
        if ($preserve_keys) {
            // Same reason as the generator arm: a bare cell key gives the index
            // store no concrete layout to pick.
            $k = $it->key();
            if (\is_int($k)) { $out[(int)$k] = $v; } else { $out[(string)$k] = $v; }
        } else {
            $out[] = $v;
        }
        $it->next();
    }
    return $out;
}

/** The generator arm of iterator_to_array, behind a TYPED parameter. */
function __mc_iter_gen_to_array(\Generator $g, bool $preserve_keys): array
{
    // Yielded values are MIXED, so the accumulator must hold cells — left bare
    // it types itself from the first store and the caller reads raw values back
    // through a cell-shaped access (denormal floats).
    /** @var array<int|string,mixed> $out */
    $out = [];
    foreach ($g as $k => $v) {
        if (!$preserve_keys) {
            $out[] = $v;
            continue;
        }
        // The key is a cell; casting it gives the index store a CONCRETE key
        // type to pick its layout from, which a bare cell key does not.
        if (\is_int($k)) {
            $out[(int)$k] = $v;
        } else {
            $out[(string)$k] = $v;
        }
    }
    return $out;
}

/** Number of elements a Traversable yields. */
function iterator_count(mixed $iterator): int
{
    if (\is_array($iterator)) { return \count($iterator); }
    // Hop the aggregate here, for the reason iterator_to_array gives.
    if ($iterator instanceof \IteratorAggregate) {
        return iterator_count($iterator->getIterator());
    }
    $it = __mc_iter_resolve($iterator);
    if ($it !== null) { return __mc_iter_drive_count($it); }
    return __mc_iter_gen_count($iterator);
}

/** iterator_count's object arm — counted without materialising the values. */
function __mc_iter_drive_count(\Iterator $it): int
{
    $n = 0;
    $it->rewind();
    while ($it->valid()) { $n = $n + 1; $it->next(); }
    return $n;
}

/** The generator arm of iterator_count, behind a TYPED parameter. */
function __mc_iter_gen_count(\Generator $g): int
{
    $n = 0;
    foreach ($g as $v) { $n = $n + 1; }
    return $n;
}

/** Call `$callback` once per element for as long as it returns true. */
function iterator_apply(mixed $iterator, mixed $callback, ?array $args = null): int
{
    $n = 0;
    foreach (\iterator_to_array($iterator) as $v) {
        $n = $n + 1;
        if (!$callback()) { break; }
    }
    return $n;
}
