<?php

namespace Compile\Mir;

/**
 * Allocates the dense SSA register and block-label ids for one function body.
 *
 * `%rN` registers and `hint.N` labels number from zero within each function, so
 * {@see reset} is called at the start of every function emit. The counters are
 * monotonic within a function — the emitted names stay deterministic.
 */
final class SsaBuilder
{
    private int $nextId = 0;
    private int $nextLabel = 0;
    /** @var array<string, string> user goto-label name → its stable block label */
    private array $userLabels = [];

    /** Restart numbering for a new function body. */
    public function reset(): void
    {
        $this->nextId = 0;
        $this->nextLabel = 0;
        $this->userLabels = [];
    }

    /** Fresh SSA register (`%rN`). */
    public function allocReg(): string
    {
        $id = $this->nextId;
        $this->nextId = $this->nextId + 1;
        return '%r' . (string)$id;
    }

    /** Fresh block label (`hint.N`). */
    public function allocLabel(string $hint): string
    {
        $id = $this->nextLabel;
        $this->nextLabel = $this->nextLabel + 1;
        return $hint . '.' . (string)$id;
    }

    /**
     * Stable block label for a user `goto` label name — allocated once per
     * function so a `goto L` and the `L:` label resolve to the SAME block.
     */
    public function userLabel(string $name): string
    {
        if (!isset($this->userLabels[$name])) {
            $this->userLabels[$name] = $this->allocLabel('user.' . $name);
        }
        return $this->userLabels[$name];
    }
}
