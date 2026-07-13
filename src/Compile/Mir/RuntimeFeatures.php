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
    public bool $needsStrReplaceOne = false;
    public bool $needsCliArgv = false;
    public bool $needsStdStreams = false;
    public bool $needsStrpos = false;
    public bool $needsStrExplode = false;
}
