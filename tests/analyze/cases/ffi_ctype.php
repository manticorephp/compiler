<?php

// `#[Ffi\CType]` token validation. The token and the PHP type hint describe the
// same value from two sides, so a disagreement is always a bug — and before the
// vocabulary landed, every token other than 'int' was accepted and silently
// ignored, which is how 'unsigned int' and 'nfds_t' got written unnoticed.

// Unknown token. The closed set is the point: a silent fallback is what let the
// bad spellings survive.
#[\Ffi\Library('c'), \Ffi\Symbol('atoi'), \Ffi\CType('quux')]
function c_unknown(string $s): int { return 0; }

// A platform typedef is deliberately NOT aliased — only the binding author can
// resolve its width, and glibc/Darwin disagree about this exact one.
#[\Ffi\Library('c'), \Ffi\Symbol('poll'), \Ffi\CType('nfds_t')]
function c_typedef(\Ffi\Ptr $fds, int $n, int $t): int { return 0; }

// Not a string literal.
#[\Ffi\Library('c'), \Ffi\Symbol('atoi'), \Ffi\CType(42)]
function c_not_string(string $s): int { return 0; }

// No argument.
#[\Ffi\Library('c'), \Ffi\Symbol('atoi'), \Ffi\CType]
function c_no_arg(string $s): int { return 0; }

// The SSL_read rule as a compile error: sign-extending a returned ADDRESS is
// how WANT_READ (-1) became a 4 GB memmove length.
#[\Ffi\Library('c'), \Ffi\Symbol('malloc'), \Ffi\CType('int')]
function c_int_on_ptr(int $n): \Ffi\Ptr { return \Ffi\Ptr::null(); }

// A PHP string carries a pointer too.
#[\Ffi\Library('c'), \Ffi\Symbol('strerror'), \Ffi\CType('long')]
function c_long_on_string(int $e): string { return ''; }

// Class of the carrier disagrees: an int carrier cannot hold a C double.
#[\Ffi\Library('c'), \Ffi\Symbol('atof'), \Ffi\CType('double')]
function c_double_on_int(string $s): int { return 0; }

// …and a float carrier cannot hold a C integer.
#[\Ffi\Library('c'), \Ffi\Symbol('atoi'), \Ffi\CType('int')]
function c_int_on_float(string $s): float { return 0.0; }

// Accepted: every legal spelling, including the multi-word C forms, which ARE
// aliased because their width is unambiguous.
#[\Ffi\Library('c'), \Ffi\Symbol('atoi'), \Ffi\CType('int')]
function c_ok_int(string $s): int { return 0; }

#[\Ffi\Library('c'), \Ffi\Symbol('usleep'), \Ffi\CType('unsigned int')]
function c_ok_multiword(int $us): int { return 0; }

#[\Ffi\Library('c'), \Ffi\Symbol('atof'), \Ffi\CType('double')]
function c_ok_double(string $s): float { return 0.0; }

#[\Ffi\Library('c'), \Ffi\Symbol('strdup'), \Ffi\CType('ptr')]
function c_ok_ptr(string $s): \Ffi\Ptr { return \Ffi\Ptr::null(); }

#[\Ffi\Library('c'), \Ffi\Symbol('free'), \Ffi\CType('void')]
function c_ok_void(\Ffi\Ptr $p): void {}

echo "unreachable\n";
