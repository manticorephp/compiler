<?php

namespace Compile\Mir;

final class Param
{
    /** Declared `array` (bare) or a typed array param. A by-value copy-on-entry
     *  separates it when the body mutates it in place (PHP value semantics),
     *  even after a bare `array` hint erased the element type to unknown. */
    public bool $arrayHinted = false;

    /** A by-ref param marked `#[RefOut]`: pure output, safe to auto-vivify
     *  at the caller. Serialized into the interface `.sig`. */
    public bool $refOut = false;

    /** The param's array type came from an ELEMENT-ONLY doc form (`T[]` or
     *  `array<V>`), which in php commits the element and says NOTHING about the
     *  keys — `array<K, V>` is the spelling that commits a key type. It lowers
     *  to a packed vec because that is what such an array almost always is, but
     *  the commitment is OURS: a call site handing it a string-keyed array is
     *  correct php, and {@see Passes\InferTypes::scanDocListKeyPromote} moves
     *  the param to the tagged key channel rather than refusing the program. */
    public bool $docList = false;

    /** An `array` param marked `#[CellArg]`: the callee consumes element VALUES,
     *  so a concrete-element array arg must be cellified (each element boxed) at
     *  the call site. Serialized into the interface `.sig`. */
    public bool $cellArg = false;

    public function __construct(
        public readonly string $name,
        public Type $type,
        public readonly bool $byRef = false,
        public readonly bool $variadic = false,
        // Pre-lowered default value (constant expr) for an optional param.
        // Used to pad omitted trailing args at a call site whose receiver
        // class only resolves after InferTypes (typed method calls).
        public readonly ?Node $default = null,
    ) {}

    /**
     * This param's ELEMENT type is the compiler's own GUESS, read out of how the
     * BODY uses the elements ({@see Passes\InferScans::scanParamElements} —
     * `(string) $elem`, a concat, a char subscript) rather than from a hint, a
     * doc form, or a call site.
     *
     * A guess is worth making and worth WITHDRAWING. When call sites then prove
     * they disagree with EACH OTHER, no single concrete element can be right,
     * and {@see Passes\InferScans::scanCallSiteArrayElems} puts the element back
     * to erased rather than leaving the guess for TypeCheck to refuse. Only a
     * guess is withdrawn: a declared type, or one the call sites agreed on, is
     * knowledge rather than a hypothesis.
     *
     * ⚠ Declared AFTER the constructor deliberately. Property order IS the
     * object layout, and the promoted constructor parameters are declared at the
     * constructor's position — a plain property added above it shifts every one
     * of them by a slot.
     */
    public bool $elemGuessed = false;

    /**
     * The body-usage guess for this param was WITHDRAWN because call sites
     * disagreed with each other, and must not be made again.
     *
     * Without this the two scans fight: {@see Passes\InferScans::scanCallSiteArrayElems}
     * puts the element back to erased, {@see Passes\InferScans::scanParamElements}
     * sees an erased element on the next iteration and re-guesses `vec[string]`,
     * and whichever ran last decides — in practice the guess, so the conflict
     * came straight back. Inference iterates to a fixpoint, so every retraction
     * it makes has to be MONOTONE or it is not a retraction at all.
     */
    public bool $elemGuessWithdrawn = false;
}
