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
    /** @var array<string, int> literal value → dense id (lookup side only) */
    private array $pool = [];

    /**
     * @var array<int, string> dense id → literal value
     *
     * The emit side reads THIS, not $pool. A literal that is a canonical
     * decimal ("0", "42") is normalised to an INT key by php's array rule, so
     * iterating $pool hands the emitter an int where it declared a string —
     * under Zend that survives on implicit int→string coercion, natively it is
     * a raw 1 walked as a pointer. Keying by the id keeps values untouched.
     */
    private array $byId = [];

    /** Id for a literal, interning it on first sight. */
    public function intern(string $s): int
    {
        if (isset($this->pool[$s])) { return $this->pool[$s]; }
        $id = \count($this->pool);
        $this->pool[$s] = $id;
        $this->byId[$id] = $s;
        return $id;
    }

    /** @return array<int, string> id → value, in insertion order (for @.str.N emit) */
    public function all(): array
    {
        return $this->byId;
    }
}
