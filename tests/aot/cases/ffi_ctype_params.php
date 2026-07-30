<?php

// `#[Ffi\CType]` in PARAMETER position narrows the i64 carrier to the C
// parameter's real width.
//
// For an ordinary argument this is correctness-hardening with no observable
// effect: AAPCS64 and SysV both leave a narrow argument's upper bits
// unspecified, so a callee reading `w0`/`edi` cannot tell the difference. A
// VARIADIC argument is where it becomes visible — the callee walks the vararg
// area with `va_arg(ap, T)`, reading T's natural size and advancing by it. Pass
// two C `int`s in 8-byte slots and the first `va_arg(ap, int)` consumes 4 bytes,
// leaving every later read misaligned against what the caller laid down.
//
// So: `%d` twice, then `%s`. The string only prints if both int slots were
// written at 4 bytes, which only happens if the parameter attribute is read.
//
// Not difftestable: binds a C symbol, so there is no Zend oracle.

#[\Ffi\Library('c'), \Ffi\Symbol('snprintf'), \Ffi\Variadic(3), \Ffi\CType('int')]
function cp_snprintf(\Ffi\Ptr $s, #[\Ffi\CType('size_t')] int $n, string $fmt,
                     #[\Ffi\CType('int')] int $a,
                     #[\Ffi\CType('int')] int $b,
                     string $c): int { return -1; }

$buf = \Runtime\Libc\calloc(128, 1);

echo cp_snprintf($buf, 128, "%d/%d/%s", 11, 22, "tail"), "\n";
echo cstr_to_str($buf), "\n";

// Negative and boundary values: a C int is signed 32-bit, so these must survive
// the narrowing exactly.
echo cp_snprintf($buf, 128, "%d/%d/%s", -2147483648, 2147483647, "edge"), "\n";
echo cstr_to_str($buf), "\n";

\Runtime\Libc\free($buf);
echo "-- done --\n";
