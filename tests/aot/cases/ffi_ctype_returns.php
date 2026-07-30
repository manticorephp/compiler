<?php

// `#[Ffi\CType]` on the RETURN decides how a C result reaches the i64 carrier.
// Before the vocabulary landed, 'int' was the only token acted on and everything
// else was accepted and ignored, so a binding could not say "this returns an
// unsigned int" or "this returns a C float" at all.
//
// A C callee writes only as many bits as its return type has, so the wrapper's
// extension is what decides the value: read `int` -1 as i64 and you get
// 4294967295 — the bug that turned SSL_read's WANT_READ into a 4 GB memmove.
//
// Not difftestable: these bind C symbols, so there is no Zend oracle.

#[\Ffi\Library('c'), \Ffi\Symbol('atoi'), \Ffi\CType('int')]
function ct_atoi(string $s): int { return 0; }

#[\Ffi\Library('c'), \Ffi\Symbol('atol'), \Ffi\CType('long')]
function ct_atol(string $s): int { return 0; }

#[\Ffi\Library('c'), \Ffi\Symbol('atof'), \Ffi\CType('double')]
function ct_atof(string $s): float { return 0.0; }

#[\Ffi\Library('c'), \Ffi\Symbol('strtof'), \Ffi\CType('float')]
function ct_strtof(string $s, \Ffi\Ptr $end): float { return 0.0; }

// `uint32_t htonl(uint32_t)` — the one witness that separates zext from sext.
// On a little-endian host it is a byte swap, so 0xFFFFFFFF maps to itself: a
// signed extension answers -1, an unsigned one answers 4294967295.
#[\Ffi\Library('c'), \Ffi\Symbol('htonl'), \Ffi\CType('uint')]
function ct_htonl(int $x): int { return 0; }

// UNSIGNED narrow return: the same 32 bits, extended the other way.
echo "htonl=", ct_htonl(4294967295), "\n";

// SIGNED narrow return: the callee's -1 has a zero upper half, so only a sext
// answers -1.
echo "atoi=", ct_atoi("-1"), "\n";
echo "atoi_big=", ct_atoi("2147483647"), "\n";

// A 64-bit token must NOT be narrowed — this value does not fit in 32 bits, so
// a wrong i32 mapping would truncate it.
echo "atol=", ct_atol("9007199254740993"), "\n";
echo "atol_neg=", ct_atol("-9007199254740993"), "\n";

// C double round-trips through the bitcast.
echo "atof=", ct_atof("1.5"), "\n";

// C `float` is 32 bits: the wrapper must fpext before reinterpreting, or the
// carrier holds a double built from a float's bit pattern.
$end = \Runtime\Libc\calloc(8, 1);
echo "strtof=", ct_strtof("2.5", $end), "\n";
\Runtime\Libc\free($end);

echo "-- done --\n";
