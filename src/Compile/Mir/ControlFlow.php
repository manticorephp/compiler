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
    /** @var int[] loop/switch depth at each open finally's try */
    private array $finallyLoop = [];
    /** @var array<int, array<string, bool>> user labels inside each open finally's try and catches */
    private array $finallyLabels = [];
    /** @var int[] try depth of each open finally's try */
    private array $finallyTry = [];
    /** @var array<int, array<int, Node[]>> */
    private array $savedStack = [];
    /** @var array<int, int[]> */
    private array $savedLoop = [];
    /** @var array<int, array<int, array<string, bool>>> */
    private array $savedLabels = [];
    /** @var array<int, int[]> */
    private array $savedTry = [];
    private int $tryDepth = 0;
    /** @var string[] pending-exception flag slots of the canonical finally bodies being emitted */
    private array $pendFlags = [];
    /** @var string[] */
    private array $pendVals = [];
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
        $this->finallyLoop = [];
        $this->finallyLabels = [];
        $this->finallyTry = [];
        $this->savedStack = [];
        $this->savedLoop = [];
        $this->savedLabels = [];
        $this->savedTry = [];
        $this->tryDepth = 0;
        $this->pendFlags = [];
        $this->pendVals = [];
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

    /**
     * Open a `try` that has a `finally`: every jump out of the try (or out of one
     * of its catches) runs `$body` first. `$labels`: the user labels inside the
     * try and catch bodies — a `goto` to any other label leaves it.
     *
     * @param Node[] $body
     * @param array<string, bool> $labels
     */
    public function pushFinally(array $body, array $labels): void
    {
        $this->finallyStack[] = $body;
        $this->finallyLoop[] = \count($this->breakStack);
        $this->finallyLabels[] = $labels;
        $this->finallyTry[] = $this->tryDepth;
    }

    public function popFinally(): void
    {
        \array_pop($this->finallyStack);
        \array_pop($this->finallyLoop);
        \array_pop($this->finallyLabels);
        \array_pop($this->finallyTry);
    }

    public function hasFinally(): bool
    {
        return $this->finallyStack !== [];
    }

    /** Number of open finally bodies; a `return` leaves all of them. */
    public function finallyCount(): int
    {
        return \count($this->finallyStack);
    }

    /**
     * How many of the open finally bodies (counted from the innermost) a
     * `break N` / `continue N` leaves: those of the trys inside its target loop.
     */
    public function finallysLeftByLevel(int $level): int
    {
        $min = \count($this->breakStack) - $level;
        $n = 0;
        for ($i = \count($this->finallyLoop) - 1; $i >= 0; $i--) {
            if ($this->finallyLoop[$i] <= $min) { break; }
            $n++;
        }
        return $n;
    }

    /** How many of the open finally bodies (from the innermost) a `goto $label` leaves. */
    public function finallysLeftByGoto(string $label): int
    {
        $n = 0;
        for ($i = \count($this->finallyLabels) - 1; $i >= 0; $i--) {
            if (isset($this->finallyLabels[$i][$label])) { break; }
            $n++;
        }
        return $n;
    }

    /** @return Node[] finally body `$i` (0 = outermost) */
    public function finallyBody(int $i): array { return $this->finallyStack[$i]; }

    /** The try depth ({@see enterTry}) of finally body `$i`. */
    public function finallyTryDepth(int $i): int { return $this->finallyTry[$i]; }

    /**
     * Emit finally body `$i` in place, at a jump that leaves it: only the bodies
     * outside it stay open (a `return` inside it runs those, not itself again).
     * Pair with {@see leaveInline}.
     */
    public function enterInline(int $i): void
    {
        $this->savedStack[] = $this->finallyStack;
        $this->savedLoop[] = $this->finallyLoop;
        $this->savedLabels[] = $this->finallyLabels;
        $this->savedTry[] = $this->finallyTry;
        $this->finallyStack = \array_slice($this->finallyStack, 0, $i);
        $this->finallyLoop = \array_slice($this->finallyLoop, 0, $i);
        $this->finallyLabels = \array_slice($this->finallyLabels, 0, $i);
        $this->finallyTry = \array_slice($this->finallyTry, 0, $i);
    }

    public function leaveInline(): void
    {
        $this->finallyStack = \array_pop($this->savedStack);
        $this->finallyLoop = \array_pop($this->savedLoop);
        $this->finallyLabels = \array_pop($this->savedLabels);
        $this->finallyTry = \array_pop($this->savedTry);
    }

    /** Enter a `try` (any kind); its depth, 1 = outermost of the function. */
    public function enterTry(): int
    {
        $this->tryDepth++;
        return $this->tryDepth;
    }

    public function leaveTry(): void
    {
        $this->tryDepth--;
    }

    /**
     * Emitting the canonical finally body of a try whose pending-exception
     * slots are `$flag` / `$val`: a `return` inside it discards that exception.
     */
    public function pushPending(string $flag, string $val): void
    {
        $this->pendFlags[] = $flag;
        $this->pendVals[] = $val;
    }

    public function popPending(): void
    {
        \array_pop($this->pendFlags);
        \array_pop($this->pendVals);
    }

    /** @return string[] */
    public function pendingFlags(): array { return $this->pendFlags; }

    /** @return string[] */
    public function pendingVals(): array { return $this->pendVals; }
}
