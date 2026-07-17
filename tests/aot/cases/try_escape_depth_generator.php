<?php

// `return` / `break` / `continue` out of a try INSIDE a generator. Split from
// try_escape_depth because the generator path is a separate emitter branch:
// emitReturn short-circuits for a generator (state = -1, ret) BEFORE
// finishReturn, so it needs its own slot hand-back. And the depth cannot be
// reloaded from the entry SSA reg here — a `yield` inside the try makes the
// resume switch branch straight into the try body, past the block that defined
// it — so it comes back from the generator frame slot.
//
// 40 iterations each: a leak of 1 per call blows the 16-slot jmp stack. The
// trailing try/catch is what actually steps on the overflow — without it the
// leak is invisible.

function gen_return(int $n)
{
    try {
        yield $n;
        return;
    } catch (\Throwable $e) {
    }
}

function gen_return_after_yield(int $n)
{
    // A yield BEFORE the return, so the resume switch re-enters mid-try.
    try {
        yield $n * 2;
        yield $n * 3;
        return;
    } finally {
        $n = $n;
    }
}

function gen_break(int $n)
{
    foreach ([1, 2, 3] as $x) {
        try {
            if ($x === 2) { break; }
            yield $n + $x;
        } catch (\Throwable $e) {
        }
    }
}

$sum = 0;
for ($i = 0; $i < 40; $i++) {
    foreach (gen_return($i) as $v) { $sum = $sum + $v; }
}
echo "gen_return: ", $sum, "\n";

$sum = 0;
for ($i = 0; $i < 40; $i++) {
    foreach (gen_return_after_yield($i) as $v) { $sum = $sum + $v; }
}
echo "gen_yield_return: ", $sum, "\n";

$sum = 0;
for ($i = 0; $i < 40; $i++) {
    foreach (gen_break($i) as $v) { $sum = $sum + $v; }
}
echo "gen_break: ", $sum, "\n";

// NOT covered here: a generator ABANDONED while suspended inside a try
// (`foreach (gen() as $v) { break; }`). That one still leaks a slot, and it is a
// design hole rather than a missing restore: the suspended generator's jmp_buf
// physically OCCUPIES its slot in the global @__mir_jmp_stack, so the slot
// cannot be handed back at the yield (the caller's next try would reuse it and
// clobber the buffer), and nothing runs when the generator is dropped. The fix
// is per-generator jmp_buf storage in the frame — generators do not have stack
// lifetime, so they cannot share a stack-shaped global. Until then the guard
// turns it into a clean fatal instead of silent argv corruption.

try {
    throw new RuntimeException("still here");
} catch (RuntimeException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo "done\n";
