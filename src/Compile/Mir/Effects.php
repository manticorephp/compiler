<?php

namespace Compile\Mir;

/**
 * Memory-effect set carried by every MIR node (and, aggregated, by
 * every {@see FunctionDef}). Computed by {@see Passes\InferEffects}
 * after type inference; consumed by the future MemoryOps lowering
 * (contract step #5) to decide retain / release / arena placement
 * — so that EmitLlvm never invents memory ops from feature handlers.
 *
 * The vocabulary (contract step #3):
 *  - alloc       op allocates a fresh heap value (string concat, array
 *                / object literal, `new`, closure, (string)/(array)/(object) cast)
 *  - retain      a refcount is incremented here   (reserved — filled by MemoryOps)
 *  - release     a refcount is decremented here   (reserved — filled by MemoryOps)
 *  - escape      a value outlives the current frame (return, throw, store-to-heap)
 *  - throw       op may unwind (div/mod by zero, any call, `new`, `throw`)
 *  - callUnknown dispatches through a callee whose body we can't see
 *                (virtual method, `$f(...)` invoke)
 *  - storeHeap   writes a value into a heap slot (property / element /
 *                static-prop / dynamic-prop store)
 *
 * `retain` / `release` stay false at inference time; they are the
 * MemoryOps pass's output, kept in the vocabulary so the type is the
 * single home for the whole effect lattice.
 */
final class Effects
{
    public function __construct(
        public bool $alloc = false,
        public bool $retain = false,
        public bool $release = false,
        public bool $escape = false,
        public bool $throw = false,
        public bool $callUnknown = false,
        public bool $storeHeap = false,
    ) {}

    public function isEmpty(): bool
    {
        return !$this->alloc && !$this->retain && !$this->release
            && !$this->escape && !$this->throw && !$this->callUnknown
            && !$this->storeHeap;
    }

    /** Union `$o` into this set (mutates in place). */
    public function mergeFrom(Effects $o): void
    {
        if ($o->alloc)       { $this->alloc = true; }
        if ($o->retain)      { $this->retain = true; }
        if ($o->release)     { $this->release = true; }
        if ($o->escape)      { $this->escape = true; }
        if ($o->throw)       { $this->throw = true; }
        if ($o->callUnknown) { $this->callUnknown = true; }
        if ($o->storeHeap)   { $this->storeHeap = true; }
    }

    /**
     * Stable comma-joined spelling in vocabulary order. Empty string
     * for the empty set. Built by hand (no `implode`) to stay on the
     * self-host stdlib surface.
     */
    public function toString(): string
    {
        $out = '';
        $out = $this->append($out, $this->alloc, 'alloc');
        $out = $this->append($out, $this->retain, 'retain');
        $out = $this->append($out, $this->release, 'release');
        $out = $this->append($out, $this->escape, 'escape');
        $out = $this->append($out, $this->throw, 'throw');
        $out = $this->append($out, $this->callUnknown, 'callUnknown');
        $out = $this->append($out, $this->storeHeap, 'storeHeap');
        return $out;
    }

    private function append(string $acc, bool $on, string $name): string
    {
        if (!$on) { return $acc; }
        if ($acc === '') { return $name; }
        return $acc . ',' . $name;
    }
}
