<?php

namespace Compile\Mir;

/**
 * Move every fixed-size `alloca` into its function's entry block.
 *
 * ── The bug this fixes ──
 * An `alloca` executes every time control reaches it, and the stack it takes is
 * only released when the FUNCTION returns — not when the block exits. So an
 * `alloca` emitted inside a loop body grows the stack once per iteration. The
 * emitter puts expression temporaries (`%r = alloca i64` for a dispatch result,
 * a ternary merge, a coalesce slot) wherever the expression sits, which for a
 * loop body means inside the loop.
 *
 * At `-O2` this is invisible: mem2reg promotes those slots to SSA registers
 * before anything runs. At `-O0` nothing promotes them, and a hot loop simply
 * runs out of stack — `tools/selfhost.sh` assembles at `-O0`, which is why this
 * surfaced as a SIGSEGV in generation 2 of the self-host (a 1.4M-iteration loop
 * over the emitted IR × 37 in-loop allocas ≈ 11 MB against an 8 MB stack) while
 * every `-O2` build of the same code was fine. The crash frame is whatever
 * function was entered when the guard page was hit, which is why it pointed at
 * `__mir_strlen` and not at the loop.
 *
 * Entry-block allocas are also LLVM's canonical form; `-O2` was already
 * normalising to it, so this only makes `-O0` agree with `-O2`.
 *
 * ── Why sharing one slot per loop is safe ──
 * Only a STATIC `alloca` is moved (`alloca <ty>`, never `alloca <ty>, i64 %n`,
 * whose size depends on where it runs). Every slot the emitter creates is
 * written before it is read on each path that reaches the read — they are
 * store/load temporaries, not values carried across iterations — so one slot
 * reused per iteration holds exactly what a fresh one would. Distinct
 * temporaries keep distinct slots regardless: each has its own SSA register.
 */
final class HoistAllocas
{
    /** Allocas moved by the last {@see run} — for MANTICORE_STATS. */
    public int $moved = 0;

    public function run(string $ir): string
    {
        $lines = \explode("\n", $ir);
        $n = \count($lines);
        $out = [];
        $this->moved = 0;
        $i = 0;
        while ($i < $n) {
            $line = $lines[$i];
            if (!(\strlen($line) > 7 && $line[0] === 'd' && \substr($line, 0, 7) === 'define ')) {
                $out[] = $line;
                $i = $i + 1;
                continue;
            }
            // Collect the whole body first: the allocas to hoist are found in
            // the tail, and they have to be emitted near the head.
            $body = [];
            $i = $i + 1;
            while ($i < $n) {
                $body[] = $lines[$i];
                if (\rtrim($lines[$i]) === '}') { $i = $i + 1; break; }
                $i = $i + 1;
            }
            $out[] = $line;
            $this->emitBody($body, $out);
        }
        return \implode("\n", $out);
    }

    /**
     * Append one function body to `$out`, with its static allocas pulled to the
     * front. The insertion point is straight after the first body line — the
     * entry label when there is one, otherwise the first instruction, which is
     * in the entry block either way. Allocas may sit anywhere in that block, so
     * no terminator can be stepped over.
     *
     * @param string[] $body
     * @param string[] $out
     */
    private function emitBody(array $body, array &$out): void
    {
        $count = \count($body);
        if ($count === 0) { return; }
        $allocas = [];
        $rest = [];
        // The first line stays put; hoisting starts after it.
        $first = $body[0];
        for ($k = 1; $k < $count; $k++) {
            $l = $body[$k];
            if ($this->isStaticAlloca($l)) {
                $allocas[] = $l;
                continue;
            }
            $rest[] = $l;
        }
        $out[] = $first;
        foreach ($allocas as $a) { $out[] = $a; }
        foreach ($rest as $r) { $out[] = $r; }
        $this->moved = $this->moved + \count($allocas);
    }

    /**
     * `  %r12 = alloca i64` — yes. `  %r12 = alloca i64, i64 %n` — no: a
     * variable-sized alloca means something different at the top of the
     * function than it does where it was written.
     */
    private function isStaticAlloca(string $l): bool
    {
        $at = \strpos($l, ' = alloca ');
        if ($at === false) { return false; }
        // Must be an instruction (leading whitespace, then a register).
        $t = \ltrim($l);
        if ($t === '' || $t[0] !== '%') { return false; }
        $rest = \substr($l, $at + 10);
        if (\strpos($rest, ',') !== false) { return false; }
        return \rtrim($rest) !== '';
    }
}
