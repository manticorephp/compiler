<?php

namespace Compile\Mir;

/**
 * Structured control-flow targets for the function being emitted: where a
 * `break N` / `continue N` inside a loop or switch lands, and which `finally`
 * bodies a `return` must still run.
 *
 * Loops and switches nest strictly, so each is a push/pop on the level stacks —
 * `break 2` indexes one frame further out. Reset per function ({@see reset});
 * one instance per {@see EmitLlvm::emit()}.
 */
final class ControlFlow
{
    /** @var string[] `break N` level stack, outermost first */
    private array $breakStack = [];
    /** @var string[] `continue N` level stack, outermost first */
    private array $continueStack = [];
    /** @var array<int, Node[]> pending `finally` bodies, innermost last */
    private array $finallyStack = [];

    /** Restart for a new function body. */
    public function reset(): void
    {
        $this->breakStack = [];
        $this->continueStack = [];
        $this->finallyStack = [];
    }

    /** Enter a loop body: `break` lands at $break, `continue` at $continue. */
    public function enterLoop(string $break, string $continue): void
    {
        $this->breakStack[] = $break;
        $this->continueStack[] = $continue;
    }

    /**
     * Enter a switch body. A switch counts as one level for `break N`, and PHP
     * treats a bare `continue` inside a switch as a `break` — so both level
     * stacks get the switch's end label.
     */
    public function enterSwitch(string $end): void
    {
        $this->enterLoop($end, $end);
    }

    /** Leave the innermost loop or switch. */
    public function leave(): void
    {
        \array_pop($this->breakStack);
        \array_pop($this->continueStack);
    }

    /**
     * `break N` target — indexes outward from the innermost loop. Reads the
     * stack field directly (no array param: self-host mishandles indexing an
     * array passed by value).
     */
    public function breakTarget(int $level): string
    {
        $n = \count($this->breakStack);
        if ($n === 0) { return 'unreachable_no_loop'; }
        $idx = $n - $level;
        if ($idx < 0) { $idx = 0; }
        return $this->breakStack[$idx];
    }

    public function continueTarget(int $level): string
    {
        $n = \count($this->continueStack);
        if ($n === 0) { return 'unreachable_no_loop'; }
        $idx = $n - $level;
        if ($idx < 0) { $idx = 0; }
        return $this->continueStack[$idx];
    }

    /** @param Node[] $body */
    public function pushFinally(array $body): void
    {
        $this->finallyStack[] = $body;
    }

    public function popFinally(): void
    {
        \array_pop($this->finallyStack);
    }

    public function hasFinally(): bool
    {
        return $this->finallyStack !== [];
    }

    /**
     * The pending `finally` bodies (innermost last) AND clear them: a `return`
     * inside an inlined finally must exit directly rather than re-run the
     * chain. Pair with {@see restoreFinally}.
     *
     * @return array<int, Node[]>
     */
    public function takeFinally(): array
    {
        $saved = $this->finallyStack;
        $this->finallyStack = [];
        return $saved;
    }

    /** @param array<int, Node[]> $saved */
    public function restoreFinally(array $saved): void
    {
        $this->finallyStack = $saved;
    }
}
