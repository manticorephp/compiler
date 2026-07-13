<?php

namespace Compile\Mir;

/**
 * Interns string literals for a single module emit.
 *
 * Each distinct string gets a stable dense id (`@.str.N`); the id is its
 * insertion order, so the emitted `@.str.N` constants and every reference to
 * them stay deterministic. A fresh instance is created per
 * {@see EmitLlvm::emit()} — ids never leak across modules.
 */
final class StringPool
{
    /** @var array<string, int> literal value → dense id */
    private array $pool = [];

    /** Id for a literal, interning it on first sight. */
    public function intern(string $s): int
    {
        if (isset($this->pool[$s])) { return $this->pool[$s]; }
        $id = \count($this->pool);
        $this->pool[$s] = $id;
        return $id;
    }

    /** @return array<string, int> value → id, in insertion order (for @.str.N emit) */
    public function all(): array
    {
        return $this->pool;
    }
}
