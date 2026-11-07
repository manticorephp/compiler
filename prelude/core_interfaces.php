<?php

/**
 * php's core interfaces — Traversable, Iterator, IteratorAggregate, ArrayAccess,
 * Countable, JsonSerializable, Serializable, Stringable. Interfaces ONLY, injected into EVERY
 * program ahead of exceptions.php: any class may implement one, and Throwable
 * extends Stringable. The SPL classes built on them live in spl_iterators.php,
 * demand-gated.
 */

/**
 * php's core interfaces, DECLARED. Undeclared, a hint naming one erased: a
 * `private Iterator $inner` property typed `unknown`, and a method call through
 * it dispatched blind — `$this->inner->current()` over an ArrayIterator read
 * back garbage. The runtime's own special cases (foreach over an Iterator or an
 * IteratorAggregate, `instanceof Traversable`) still key off these names.
 */
interface Traversable
{
}

interface Iterator extends Traversable
{
    public function current(): mixed;
    public function key(): mixed;
    public function next(): void;
    public function rewind(): void;
    public function valid(): bool;
}

interface IteratorAggregate extends Traversable
{
    public function getIterator(): Traversable;
}

interface ArrayAccess
{
    public function offsetExists(mixed $offset): bool;
    public function offsetGet(mixed $offset): mixed;
    public function offsetSet(mixed $offset, mixed $value): void;
    public function offsetUnset(mixed $offset): void;
}

interface Countable
{
    public function count(): int;
}

interface JsonSerializable
{
    public function jsonSerialize(): mixed;
}

/**
 * php's Stringable. No class has to name it: every class that declares
 * `__toString` implements it implicitly ({@see \Compile\Mir\Passes\LowerClasses}).
 */
interface Stringable
{
    public function __toString(): string;
}


interface Serializable
{
    public function serialize();

    public function unserialize(string $data);
}

function is_iterable(mixed $value): bool
{
    return \is_array($value) || $value instanceof \Traversable;
}

function is_countable(mixed $value): bool
{
    return \is_array($value) || $value instanceof \Countable;
}

/** The walk behind a yielding `foreach` over an erased base ({@see \Compile\Mir\Passes\SpillFreshBases}). No foreach here: it would come straight back. */
function __mc_iter_any(mixed $it): \Generator
{
    while ($it instanceof \IteratorAggregate) { $it = $it->getIterator(); }
    if ($it instanceof \Iterator) {
        for ($it->rewind(); $it->valid(); $it->next()) {
            $v = $it->current();
            yield $it->key() => $v;
        }
        return;
    }
    if (\is_object($it)) { $it = __mc_public_object_vars($it); }
    if (\is_array($it)) {
        $keys = \array_keys($it);
        $n = \count($keys);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $k = $keys[$i];
            yield $k => $it[$k];
        }
    }
}
