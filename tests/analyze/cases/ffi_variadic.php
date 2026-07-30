<?php

// `#[Ffi\Variadic($fixed)]` argument validation. $fixed names the C callee's
// NAMED parameters — the ones before the `...` — so it can never exceed the
// binding's own arity, and it must be an integer literal the compiler can read
// at lowering time.

// Past the end: the binding declares 2 params, so 3 named ones cannot exist.
#[\Ffi\Library('c'), \Ffi\Symbol('snprintf'), \Ffi\Variadic(3)]
function v_past_end(\Ffi\Ptr $s, int $n): int { return -1; }

// Negative.
#[\Ffi\Library('c'), \Ffi\Symbol('open'), \Ffi\Variadic(-1)]
function v_negative(string $path, int $flags): int { return -1; }

// Not an integer literal.
#[\Ffi\Library('c'), \Ffi\Symbol('ioctl'), \Ffi\Variadic('two')]
function v_not_int(int $fd, int $req, int $arg): int { return -1; }

// No argument at all.
#[\Ffi\Library('c'), \Ffi\Symbol('fcntl'), \Ffi\Variadic]
function v_no_arg(int $fd, int $cmd, int $arg): int { return -1; }

// Accepted: positional at the arity boundary, and the named form.
#[\Ffi\Library('c'), \Ffi\Symbol('snprintf'), \Ffi\Variadic(2)]
function v_ok_positional(\Ffi\Ptr $s, int $n, string $fmt): int { return -1; }

#[\Ffi\Library('c'), \Ffi\Symbol('snprintf'), \Ffi\Variadic(fixed: 2)]
function v_ok_named(\Ffi\Ptr $s, int $n, string $fmt): int { return -1; }

echo "unreachable\n";
