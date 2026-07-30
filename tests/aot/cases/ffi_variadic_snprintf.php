<?php

// `#[Ffi\Variadic($fixed)]` drives the LLVM variadic call type.
//
// Until this landed, variadic arity was keyed off a hardcoded table of C symbol
// names that knew exactly one entry (`fcntl`), so binding any other variadic C
// function silently miscompiled: the wrapper emitted a fixed-arity call, and on
// Darwin arm64 — whose variadic ABI puts varargs on the STACK, not in registers
// — the callee read register garbage where it does `va_arg`. snprintf was never
// in that table, so this case only prints the right thing if the ATTRIBUTE is
// what the emitter reads.
//
// Three varargs of mixed classes (ptr, integer, ptr) so the whole stack slot
// layout is exercised, not just the first slot.
//
// Not difftestable: `mc_snprintf` binds a C symbol, so there is no Zend oracle.

#[\Ffi\Library('c'), \Ffi\Symbol('snprintf'), \Ffi\Variadic(3), \Ffi\CType('int')]
function mc_snprintf(\Ffi\Ptr $s, int $n, string $fmt,
                     string $a1, int $a2, string $a3): int { return -1; }

$buf = \Runtime\Libc\calloc(128, 1);

$len = mc_snprintf($buf, 128, "[%s:%ld:%s]", "id", 4242, "end");
echo cstr_to_str($buf), "\n";
echo "len=", $len, "\n";

// A negative vararg: proves the slot is read at full width, not as a truncated
// or sign-confused half-word.
$len2 = mc_snprintf($buf, 128, "[%s:%ld:%s]", "n", -1, "z");
echo cstr_to_str($buf), "\n";
echo "len2=", $len2, "\n";

\Runtime\Libc\free($buf);
echo "-- done --\n";
