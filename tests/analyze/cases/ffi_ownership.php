<?php

// The `Ffi\Ownership` family — Borrow / BorrowMut / Take / Give / StaticPtr —
// is CHECKED and lowered NOWHERE. Nothing is freed on your behalf; this pass
// changes not one byte of emitted code. It exists so the attributes stop being
// decoration, which is what they were until now: written at ten sites in
// src/Runtime/Libc.php and read by nothing at all.
//
// One function per rule, in order, so this file and its expected output are the
// specification.

// ── O1: an Ffi attribute on a declaration that is not a binding ─────────────
// Without #[Symbol] there is no C callee, so there is nothing to describe.
#[\Ffi\Give]
function o1_no_symbol(): \Ffi\Ptr { return \Ffi\Ptr::null(); }

function o1_param_no_symbol(#[\Ffi\Take] \Ffi\Ptr $p): void {}

// ── O2: Give and StaticPtr are mutually exclusive ───────────────────────────
// The callee cannot both hand ownership over and keep it forever.
#[\Ffi\Library('c'), \Ffi\Symbol('strdup'), \Ffi\Give, \Ffi\StaticPtr]
function o2_both(string $s): \Ffi\Ptr { return \Ffi\Ptr::null(); }

// ── O3: one parameter, one ownership story ──────────────────────────────────
#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function o3_two_on_one(#[\Ffi\Borrow] #[\Ffi\Take] \Ffi\Ptr $p): void {}

// ── O4: ownership is a property of a pointer, not of a number ───────────────
#[\Ffi\Library('c'), \Ffi\Symbol('close')]
function o4_on_int(#[\Ffi\Take] #[\Ffi\CType('int')] int $fd): int { return 0; }

// ── O5: a return-ownership claim needs something to own ─────────────────────
#[\Ffi\Library('c'), \Ffi\Symbol('getpid'), \Ffi\Give, \Ffi\CType('int')]
function o5_give_on_int(): int { return 0; }

#[\Ffi\Library('c'), \Ffi\Symbol('free'), \Ffi\StaticPtr]
function o5_staticptr_on_void(\Ffi\Ptr $p): void {}

// ── O6: a PHP string is refcount-owned; C must never free it ────────────────
// Handing a refcounted block to free() corrupts the heap silently — the word
// before the bytes is manticore's rc, not the allocator's.
#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function o6_take_string(#[\Ffi\Take] string $s): void {}

// ── O7: and the reverse — a C buffer has no rc header to release ────────────
#[\Ffi\Library('c'), \Ffi\Symbol('strdup'), \Ffi\Give]
function o7_give_string(string $s): string { return ''; }

// ── An FFI binding must be a free function ──────────────────────────────────
// A method binding cannot work: the MIR function carries a receiver with no C
// counterpart, and Sig::emitModule exports only free functions, so a
// class-based binding could never cross a .o boundary.
#[\Ffi\Library('c')]
class Ffi_OnClass
{
    #[\Ffi\Library('c'), \Ffi\Symbol('getpid')]
    public function bound(): int { return 0; }

    #[\Ffi\Symbol('getppid')]
    public static function boundStatic(): int { return 0; }
}

// ── Accepted: every shape the runtime actually uses ─────────────────────────
#[\Ffi\Library('c'), \Ffi\Symbol('malloc'), \Ffi\Give]
function ok_give(#[\Ffi\CType('size_t')] int $n): \Ffi\Ptr { return \Ffi\Ptr::null(); }

#[\Ffi\Library('c'), \Ffi\Symbol('free')]
function ok_take(#[\Ffi\Take] \Ffi\Ptr $p): void {}

#[\Ffi\Library('c'), \Ffi\Symbol('strlen')]
function ok_borrow(#[\Ffi\Borrow] string $s): int { return 0; }

#[\Ffi\Library('c'), \Ffi\Symbol('memset')]
function ok_borrow_mut(#[\Ffi\BorrowMut] \Ffi\Ptr $p, #[\Ffi\CType('int')] int $c,
                       #[\Ffi\CType('size_t')] int $n): \Ffi\Ptr { return \Ffi\Ptr::null(); }

#[\Ffi\Library('c'), \Ffi\Symbol('strerror'), \Ffi\StaticPtr, \Ffi\CType('ptr')]
function ok_static_ptr(#[\Ffi\CType('int')] int $e): \Ffi\Ptr { return \Ffi\Ptr::null(); }

echo "unreachable\n";
