<?php

namespace Compile\Mir;

/**
 * Runtime-feature demand set for a single module emit.
 *
 * EmitLlvm sets a flag the first time it emits an instruction that references
 * a runtime helper (string concat, arena, tagged-cell juggling, exceptions, …);
 * the declaration/runtime-emit prologue reads the flag to decide whether to emit
 * the corresponding `@__mir_*` helper. A program that never touches a feature
 * pays zero code for it.
 *
 * A fresh instance is created per {@see EmitLlvm::emit()} — every flag starts
 * false, so demand never leaks across modules.
 */
final class RuntimeFeatures
{
    /** Emit the runtime call-stack + instrument user calls (backtrace support). */
    public bool $needsBacktrace = false;
    /** Any `.` concat was emitted (gates the string runtime). */
    public bool $needsConcat = false;
    /** Amortized self-append (`$s .= …`) was emitted (gates __mir_str_append). */
    public bool $needsStrAppend = false;
    /** Any arena alloc / scope was emitted (gates the arena runtime). */
    public bool $needsArena = false;
    /** An rc retain/release was emitted (gates the rc runtime). */
    public bool $needsRc = false;
    /** `gc_collect_cycles()` is used — gates the Bacon-Rajan cycle collector. */
    public bool $needsCc = false;
    /** Any string is rc retained/released (sentinel-guarded str rc helpers). */
    public bool $needsStrRc = false;

/** A CAPTURING closure env was built ⇒ emit `__mir_closure_retain/release`. */
public bool $needsClosureRc = false;
    /** A loop resets the arena per iteration (arena position save/restore). */
    public bool $needsArenaReset = false;
    public bool $needsFloatStr = false;
    public bool $needsFloatShortest = false;
    public bool $needsStrtol = false;
    public bool $needsStrtod = false;
    public bool $needsStrcmp = false;
    public bool $needsIntStr = false;
    public bool $needsExceptions = false;
    public bool $needsTagged = false;
    public bool $needsTaggedEcho = false;
    public bool $needsTaggedToStr = false;
    public bool $needsImplodeCell = false;
    /** Cell → raw string pointer at a typed-`string` boundary (renders an
     *  int/float/bool cell, strips a pointer one, keeps null NULL). */
    public bool $needsCellToStrPtr = false;
    public bool $needsTaggedToInt = false;
    public bool $needsTaggedToFloat = false;
    public bool $needsTaggedCompare = false;
    public bool $needsTaggedEq = false;
    public bool $needsTaggedArith = false;
    public bool $needsTaggedTruthy = false;
    /** Array indexed by a `mixed`/cell key — int-vs-string key dispatch helpers. */
    public bool $needsCellKey = false;
    public bool $needsSubstr = false;
    public bool $needsStrRepeat = false;
    public bool $needsStrtolower = false;
    public bool $needsStrtoupper = false;
    public bool $needsIpow = false;
    public bool $needsAddslashes = false;
    public bool $needsJsonEscape = false;
    public bool $needsJsonEnc = false;
    public bool $needsJsonDec = false;
    public bool $needsRyu = false;
    public bool $needsStrReplaceOne = false;
    public bool $needsCliArgv = false;

    /** The program uses `\Fiber` — emit the fcontext `module asm` (arch-branched),
     *  the `@__mir_current_fiber` global, and the mc_fiber_make/jump declares. */
    public bool $needsFibers = false;

    /** The program reads the process environment ($_SERVER / $_ENV): emit the
     *  `environ` accessors. Off by default — a program that never asks pays
     *  nothing. */
    public bool $needsEnviron = false;

    /** The program asks the clock (time/microtime/hrtime): emit the
     *  clock_gettime wrapper. */
    public bool $needsClock = false;
    public bool $needsStdStreams = false;

    /**
     * Any byte destined for stdout was emitted — gates the `@__mir_out_*`
     * funnel family and the `@__mir_ob_*` buffer state.
     *
     * Set INSIDE the emitOut* helpers ({@see EmitLlvmExpr::emitOutWrite}), never
     * at a call site: they are the only way to reach the funnel, so demand and
     * definition cannot drift apart. That matters more here than for other
     * flags — `tools/link_stubs.sh` resolves an unknown symbol to a `return 0`
     * stub, so a demanded-but-undefined funnel would make every byte of program
     * output vanish with no diagnostic at all.
     */
    public bool $needsOutBuf = false;
    public bool $needsStrpos = false;
    /** A concrete-element subscript read has to ask the array whether its slots
     *  are actually boxed cells ({@see EmitLlvmRuntime::elemUntagRuntime}). */
    public bool $needsElemUntag = false;
    public bool $needsStrcspn = false;
    public bool $needsStrExplode = false;

    /**
     * Some function in the module calls `func_num_args` / `func_get_arg` /
     * `func_get_args` — emit the `@__mir_fa_argc` side channel and its take
     * helper. The count cannot ride the signature: the caller has already
     * filled every omitted default by the time the callee runs, and the
     * closure struct is fixed-arity so an indirect call could not carry an
     * extra parameter anyway.
     */
    public bool $needsFuncArgs = false;

    /**
     * The program asks `function_exists($var)` with a non-literal name — emit
     * the closed-world name table and the scan over it.
     */
    public bool $needsFnExists = false;

    // ── derived demands ────────────────────────────────────────────────────
    // A helper is often pulled in by more than one feature. Naming each union
    // once here keeps the subtle "why" with the flags instead of re-deriving
    // the condition at every emit site (where a missing arm links an undefined
    // ref and degrades to an identity stub — boxed bits printed as garbage).

    /** Every string-formatting path renders through snprintf. */
    public function needsSnprintf(): bool
    {
        return $this->needsConcat || $this->needsFloatStr
            || $this->needsIntStr || $this->needsTaggedToStr || $this->needsCellToStrPtr;
    }

    /** tagged_to_str (mixed→string) calls int_to_str for the int tag. */
    public function needsIntToStr(): bool
    {
        return $this->needsConcat || $this->needsIntStr || $this->needsTaggedToStr;
    }

    /** box_int/unbox_int: the full tagged runtime AND every tagged render
     *  helper (whose `asint` arm calls unbox_int). */
    public function needsBoxInt(): bool
    {
        return $this->needsTagged || $this->needsTaggedToStr || $this->needsTaggedToInt
            || $this->needsTaggedToFloat || $this->needsTaggedEcho || $this->needsIntStr;
    }

    /** tagged_to_double's string-tag branch parses via strtod, so it is needed
     *  whenever that helper is emitted — not only on a direct `(float)"str"`. */
    public function needsStrtodDecl(): bool
    {
        return $this->needsStrtod || $this->needsTaggedToFloat;
    }

    /**
     * The libc symbols this module's runtime needs → their `declare` line.
     *
     * Keyed by symbol because declaring one twice is a hard LLVM error: the
     * always-on set, the flag-driven set and the per-builtin extras all route
     * through this one map. Insertion order is the emit order.
     *
     * @return array<string, string> symbol → declare line
     */
    public function libcDecls(bool $verify): array
    {
        $decls = [];
        $decls['printf'] = "declare i32 @printf(ptr, ...) nofree nounwind";
        $decls['malloc'] = "declare ptr @malloc(i64)";
        $decls['free']   = "declare void @free(ptr)";
        // __mir_realloc_tagged is always emitted (tagged vec grow), so the
        // realloc decl must always be present.
        $decls['realloc'] = "declare ptr @realloc(ptr, i64)";
        // The small-object pool reserves its region with mmap and is emitted
        // with the allocators, i.e. always. Declared here rather than from
        // `poolRuntime()` because libcExtra is already rendered by then — the
        // fiber block, which runs earlier, may also key 'mmap' and this map
        // de-duplicates by symbol.
        if (\Compile\Debug::$pool) {
            $decls['mmap'] = "declare ptr @mmap(ptr, i64, i32, i32, i32, i64)";
        }
        // A2 verify mode (MANTICORE_DEBUG_VERIFY): rc helpers abort on an
        // over-release (rc<1 before decrement = double-free / UAF). Gated so
        // production IR is byte-identical.
        if ($verify) {
            $decls['abort'] = "declare void @abort() noreturn";
            $decls['dprintf'] = "declare i32 @dprintf(i32, ptr, ...)";
        }
        if ($this->needsArena) {
            $decls['realloc'] = "declare ptr @realloc(ptr, i64)";
            $decls['memcpy']  = "declare ptr @memcpy(ptr, ptr, i64)";
        }
        if ($this->needsConcat) { $decls['strlen'] = "declare i64 @strlen(ptr)"; }
        if ($this->needsSnprintf()) { $decls['snprintf'] = "declare i32 @snprintf(ptr, i64, ptr, ...)"; }
        if ($this->needsStrtol) { $decls['strtol'] = "declare i64 @strtol(ptr, ptr, i32)"; }
        if ($this->needsStrcmp) { $decls['strcmp'] = "declare i32 @strcmp(ptr, ptr)"; }
        if ($this->needsExceptions) {
            // `_setjmp`/`_longjmp`, NOT `setjmp`/`longjmp`, and it is worth
            // 20% of a server's CPU. The plain pair saves and restores the
            // SIGNAL MASK: on glibc `setjmp` is `__sigsetjmp(env, 1)`, and on
            // Darwin it calls `sigprocmask` AND `sigaltstack` — two syscalls
            // on every `try` ENTERED, not on every throw. Profiled under load:
            // 875 of 4374 samples were exactly those two, with `_setjmp` in
            // the same profile costing one sample and no children.
            //
            // Nothing here needs the mask: a longjmp out of a SIGNAL HANDLER
            // is the only case that does, and the exception runtime never
            // unwinds from one — the SIGSEGV/SIGBUS handler the fiber guard
            // installs aborts, it does not resume.
            $decls['setjmp'] = "declare i32 @_setjmp(ptr) returns_twice";
            $decls['longjmp'] = "declare void @_longjmp(ptr, i32) noreturn";
        }
        if ($this->needsStrtodDecl()) { $decls['strtod'] = "declare double @strtod(ptr, ptr)"; }
        // Unified PhpArray runtime libc deps (docs/16).
        $decls['realloc'] = "declare ptr @realloc(ptr, i64)";
        $decls['memset']  = "declare ptr @memset(ptr, i32, i64)";
        $decls['memcpy']  = "declare ptr @memcpy(ptr, ptr, i64)";
        $decls['memmove'] = "declare ptr @memmove(ptr, ptr, i64)";
        $decls['memcmp']  = "declare i32 @memcmp(ptr, ptr, i64)";
        $decls['memchr']  = "declare ptr @memchr(ptr, i32, i64)";
        $decls['malloc']  = "declare ptr @malloc(i64)";
        $decls['strlen']  = "declare i64 @strlen(ptr)";
        $decls['free']    = "declare void @free(ptr)";
        $decls['strcmp']  = "declare i32 @strcmp(ptr, ptr)";
        // Reflection's name→rmeta index ({@see RuntimeLibrary::reflIndex}):
        // zeroed table allocation, and ctlz to round the capacity up to a power
        // of two. Unconditional because the registry always is — and a missing
        // declare would not fail the link here, it would stub to `return 0`, so
        // the table would be "allocated" at address 0.
        $decls['calloc'] = "declare ptr @calloc(i64, i64)";
        $decls['ctlz']   = "declare i64 @llvm.ctlz.i64(i64, i1)";
        return $decls;
    }
}
