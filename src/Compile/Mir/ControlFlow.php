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
    /**
     * The iterator slot of every open IteratorAggregate `foreach` — the loop owns
     * the `getIterator()` result (+1) and gives it back after its end label —
     * with the loop level it belongs to (1 = outermost) and whether it may be a
     * Generator frame at run time. A `return`, and a `break N` / `continue N`
     * that leaves such a loop, branches past that release: the iterator held
     * its subject, and a SplFixedArray subject every element. innermost last.
     * @var string[]
     */
    private array $aggIterSlots = [];
    /** @var bool[] */
    private array $aggIterDyn = [];
    /** @var int[] */
    private array $aggIterLevel = [];
    /** @var string[] an i1 slot that says whether the iterator is owned at all ('': always) */
    private array $aggIterFlag = [];
    /** @var string[] the release flavor of an owned array iterable ('': an iterator object) */
    private array $aggIterFlavor = [];

    /** Restart for a new function body. */
    public function reset(): void
    {
        $this->breakStack = [];
        $this->continueStack = [];
        $this->finallyStack = [];
        $this->aggIterSlots = [];
        $this->aggIterDyn = [];
        $this->aggIterLevel = [];
        $this->aggIterFlag = [];
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

    /** `break N` target — indexes outward from the innermost loop. */
    public function breakTarget(int $level): string
    {
        return $this->targetAt($this->breakStack, $level);
    }

    public function continueTarget(int $level): string
    {
        return $this->targetAt($this->continueStack, $level);
    }

    /**
     * Nth-outermost entry of a level stack.
     *
     * @param string[] $stack
     */
    private function targetAt(array $stack, int $level): string
    {
        $n = \count($stack);
        if ($n === 0) { return 'unreachable_no_loop'; }
        $idx = $n - $level;
        if ($idx < 0) { $idx = 0; }
        return $stack[$idx];
    }

    /** Open an aggregate foreach whose loop is entered next — or, `$flavor`
     *  set, a foreach that owns a fresh array iterable, released by that flavor. */
    public function pushAggIter(string $slot, bool $dyn, string $flag = '', string $flavor = ''): void
    {
        $this->aggIterFlavor[] = $flavor;
        $this->aggIterFlag[] = $flag;
        $this->aggIterSlots[] = $slot;
        $this->aggIterDyn[] = $dyn;
        $this->aggIterLevel[] = \count($this->breakStack) + 1;
    }

    public function popAggIter(): void
    {
        \array_pop($this->aggIterSlots);
        \array_pop($this->aggIterDyn);
        \array_pop($this->aggIterLevel);
        \array_pop($this->aggIterFlag);
        \array_pop($this->aggIterFlavor);
    }

    /**
     * Indices of the open aggregate iterators a jump LEAVES, innermost first:
     * all of them for a `return` ($level 0), and for a `break N` / `continue N`
     * the loops strictly inside its target — the target's own iterator is
     * released at its end label (break) or lives on (continue).
     * @return int[]
     */
    public function aggItersLeftBy(int $level): array
    {
        $min = $level === 0 ? 0 : \count($this->breakStack) - $level + 2;
        $out = [];
        for ($i = \count($this->aggIterSlots) - 1; $i >= 0; $i--) {
            if ($this->aggIterLevel[$i] >= $min) { $out[] = $i; }
        }
        return $out;
    }

    public function aggIterSlot(int $i): string { return $this->aggIterSlots[$i]; }

    public function aggIterDyn(int $i): bool { return $this->aggIterDyn[$i]; }

    public function aggIterFlag(int $i): string { return $this->aggIterFlag[$i]; }

    public function aggIterFlavor(int $i): string { return $this->aggIterFlavor[$i]; }

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
