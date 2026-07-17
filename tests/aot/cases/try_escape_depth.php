<?php

// A try claims a slot in @__mir_jmp_stack by bumping the process-global
// @__mir_jmp_depth. Only the fall-through and catch paths used to pop it, so
// `return` / `break` / `continue` out of a try leaked a slot PERMANENTLY. The
// stack holds 16; after 15 leaks the next try's setjmp wrote 192 bytes at slot
// 16 — landing on @__mir_jmp_depth, @__mir_thrown, @__manticore_argc and
// @__manticore_argv. The crash then surfaced somewhere else entirely (argv
// reads as garbage), which is why this needs an explicit test rather than
// trusting a passing suite.
//
// Every loop here runs well past 16 iterations: a leak of even 1 per escape
// puts the counter over the edge, and the trailing try/catch is what actually
// steps on the overflowed slot. Keep the trailing try — without it a leak is
// invisible.

function ret_from_try(int $i): int
{
    try {
        return $i * 2;
    } catch (\Throwable $e) {
        return -1;
    }
}

function ret_from_catch(int $i): int
{
    try {
        if ($i >= 0) { throw new RuntimeException("x"); }
        return 0;
    } catch (\Throwable $e) {
        return $i + 1;
    }
}

function ret_from_finally(int $i): int
{
    // The finally form burns TWO slots (outer + inner buf), so it leaks twice
    // as fast as a plain try.
    try {
        return $i;
    } finally {
        $i = $i;
    }
}

$sum = 0;
for ($i = 0; $i < 40; $i++) { $sum = $sum + ret_from_try($i); }
echo "return: ", $sum, "\n";

$sum = 0;
for ($i = 0; $i < 40; $i++) { $sum = $sum + ret_from_catch($i); }
echo "catch: ", $sum, "\n";

$sum = 0;
for ($i = 0; $i < 40; $i++) { $sum = $sum + ret_from_finally($i); }
echo "finally: ", $sum, "\n";

// `continue` out of a try — leaks once per ITERATION, not once per call.
$sum = 0;
for ($i = 0; $i < 40; $i++) {
    try {
        if ($i % 2 === 0) { continue; }
        $sum = $sum + $i;
    } catch (\Throwable $e) {
    }
}
echo "continue: ", $sum, "\n";

// `break` out of a try, re-entered by an outer loop.
$sum = 0;
for ($j = 0; $j < 40; $j++) {
    for ($i = 0; $i < 10; $i++) {
        try {
            if ($i === 3) { break; }
            $sum = $sum + 1;
        } catch (\Throwable $e) {
        }
    }
}
echo "break: ", $sum, "\n";

// A nested try must give back BOTH slots on the way out.
function nested(int $i): int
{
    try {
        try {
            return $i;
        } catch (\Throwable $e) {
            return -1;
        }
    } catch (\Throwable $e) {
        return -2;
    }
}
$sum = 0;
for ($i = 0; $i < 40; $i++) { $sum = $sum + nested($i); }
echo "nested: ", $sum, "\n";

// The payload: after all that escaping, the exception machinery must still
// work and argv must still be intact. Both die if a slot leaked.
try {
    throw new RuntimeException("still here");
} catch (RuntimeException $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
echo "done\n";
