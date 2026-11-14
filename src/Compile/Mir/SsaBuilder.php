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
    /**
     * Payload register → the tagged word of the fresh cell it came from, still owed a drop.
     * Lives here because its keys name THIS body's registers: a helper body emitted
     * mid-function gets a builder of its own, and an outer entry read there named an
     * unrelated register of the helper (`__mir_cell_drop(i64 %r113)` of a ptr).
     * @var array<string, string>
     */
    private array $cellByReg = [];

    /** Restart numbering for a new function body. */
    public function reset(): void
    {
        $this->nextId = 0;
        $this->nextLabel = 0;
        $this->userLabels = [];
        $this->cellByReg = [];
    }

    public function parkCell(string $reg, string $cell): void
    {
        $this->cellByReg[$reg] = $cell;
    }

    /** The cell parked by `$reg` ('' when none), forgotten as it is handed out. */
    public function takeCell(string $reg): string
    {
        $c = $this->cellByReg[$reg] ?? '';
        if ($c !== '') { unset($this->cellByReg[$reg]); }
        return $c;
    }

    /**
     * Number this body's registers from `$id`: a helper body built while a frame
     * emits takes a range that frame never reaches, so a register the frame
     * created and the body still names cannot pass for one the body defines.
     */
    public function startAt(int $id): void
    {
        $this->nextId = $id;
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

    /**
     * The user-label map, cleared: a finally body emitted again (inlined at a
     * jump) defines its labels anew, and a `goto` in it stays inside the copy.
     * Pair with {@see restoreUserLabels}.
     * @return array<string, string>
     */
    public function takeUserLabels(): array
    {
        $saved = $this->userLabels;
        $this->userLabels = [];
        return $saved;
    }

    /** @param array<string, string> $saved */
    public function restoreUserLabels(array $saved): void
    {
        $this->userLabels = $saved;
    }
}
