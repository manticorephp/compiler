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
     * Pre-try `@__mir_jmp_depth` SSA regs of the OPEN trys, outermost first.
     *
     * A try pushes its slot by bumping the global depth and pops it again only
     * on the fall-through and catch paths ({@see EmitLlvmExceptions::emitTry}).
     * Every other way out — `return`, `break`, `continue` — branches away and
     * would leave the slot claimed FOREVER, since the depth is a process-global.
     * 15 such escapes and the next try's setjmp writes past @__mir_jmp_stack,
     * over @__mir_jmp_depth / @__mir_thrown / @__manticore_argc / @__manticore_argv.
     * So each escape restores the depth from here first.
     *
     * The reg is the depth BEFORE the try (`$od` when there's a finally — that
     * form burns two slots — else `$idb`), and it dominates the whole try
     * region, so an escape anywhere inside can name it.
     * @var string[]
     */
    private array $tryDepthStack = [];
    /**
     * Generator frame slot holding each open try's pre-try depth, parallel to
     * $tryDepthStack; -1 outside a generator.
     *
     * The SSA reg alone is not enough there: a `yield` inside the try makes the
     * resume switch branch INTO the try body, past the block that defined the
     * reg, so it no longer dominates. {@see EmitLlvmExceptions::tryReloadDepth}
     * reads the frame instead — the same reason the fall-through and catch pops
     * already go through it.
     * @var int[]
     */
    private array $tryDepthSlots = [];
    /**
     * count($tryDepthStack) sampled at each loop/switch entry; parallel to
     * $breakStack. A `break`/`continue` unwinds only the trys opened INSIDE its
     * target loop — the entries at or past this mark — so the loop restores to
     * $tryDepthStack[$mark], not to the function's entry depth.
     * @var int[]
     */
    private array $loopTryLen = [];

    /** Restart for a new function body. */
    public function reset(): void
    {
        $this->breakStack = [];
        $this->continueStack = [];
        $this->finallyStack = [];
        $this->tryDepthStack = [];
        $this->tryDepthSlots = [];
        $this->loopTryLen = [];
    }

    /** Enter a loop body: `break` lands at $break, `continue` at $continue. */
    public function enterLoop(string $break, string $continue): void
    {
        $this->breakStack[] = $break;
        $this->continueStack[] = $continue;
        $this->loopTryLen[] = \count($this->tryDepthStack);
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
        \array_pop($this->loopTryLen);
    }

    /**
     * Enter a try region whose pre-try depth is held in $depthReg, and (in a
     * generator) also in frame slot $genSlot — pass -1 when there is none.
     */
    public function pushTryDepth(string $depthReg, int $genSlot): void
    {
        $this->tryDepthStack[] = $depthReg;
        $this->tryDepthSlots[] = $genSlot;
    }

    public function popTryDepth(): void
    {
        \array_pop($this->tryDepthStack);
        \array_pop($this->tryDepthSlots);
    }

    /**
     * Depth to restore before a `return`: the outermost open try's pre-try
     * depth, which IS this function's entry depth. '' when no try is open —
     * then the depth was never touched and needs no restore.
     */
    public function returnDepthReg(): string
    {
        if ($this->tryDepthStack === []) { return ''; }
        return $this->tryDepthStack[0];
    }

    /** Generator frame slot paired with {@see returnDepthReg}; -1 if none. */
    public function returnDepthSlot(): int
    {
        if ($this->tryDepthSlots === []) { return -1; }
        return $this->tryDepthSlots[0];
    }

    /**
     * Index into $tryDepthStack of the outermost try opened inside the `break N`
     * / `continue N` target loop, or -1 when the jump crosses no try — the
     * common case, and then no restore is emitted.
     */
    private function loopTryIndex(int $level): int
    {
        $n = \count($this->loopTryLen);
        if ($n === 0) { return -1; }
        $idx = $n - $level;
        if ($idx < 0) { $idx = 0; }
        $mark = $this->loopTryLen[$idx];
        if ($mark >= \count($this->tryDepthStack)) { return -1; }
        return $mark;
    }

    /** Depth to restore before a `break N` / `continue N`. '' = nothing to do. */
    public function loopDepthReg(int $level): string
    {
        $i = $this->loopTryIndex($level);
        if ($i < 0) { return ''; }
        return $this->tryDepthStack[$i];
    }

    /** Generator frame slot paired with {@see loopDepthReg}; -1 if none. */
    public function loopDepthSlot(int $level): int
    {
        $i = $this->loopTryIndex($level);
        if ($i < 0) { return -1; }
        return $this->tryDepthSlots[$i];
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
