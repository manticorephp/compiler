<?php

namespace Compile\Mir;

/**
 * The ONE answer to "is this value +1": the store-side question
 * ({@see classifyStored}, asked by {@see Passes\InsertMemoryOps} for the
 * destination local of a StoreLocal) and the temp-side question
 * ({@see classifyTemp}, asked by the EmitLlvm traits for a consumed operand —
 * a call argument, a concat operand, a base read). Each side's drift used to
 * be a leak or a double free, because the two halves lived in two classes.
 *
 * A code > 0 is Own(flavor); {@see BORROW} is an rc value this site does not
 * own; {@see NONE} is a value with no refcount at all.
 *
 * ★ The flavor code IS the release-helper choice. {@see flavorOf} derives it
 * from the SAME decision {@see releaseFlavor} / {@see condFlavor} make (class
 * tables, struct / enum / `Ffi\Ptr` guards), so a class with no rc header is
 * NONE, never BORROW, and {@see flavorName} of a code names the helper family
 * the release takes: a Generator is {@see GEN}, released through the `str`
 * path. A closure env ({@see CLOSURE}) is the one rc value releaseFlavor does
 * not name — it drops through `closure`.
 *
 * {@see CondOwn} (conditionals) and {@see AliasOwn} (aliases, property reads,
 * borrowing builtins) are the shared sub-contracts both sides call through here.
 *
 * TWIN-DRIFT: the store side and the temp side still answer differently for the
 * node kinds listed at {@see classifyTemp}. Every one of those is deliberate
 * TODAY (the store path retains what the temp path borrows, or the reverse), and
 * each is to be unified by the ownership-flow pass, never silently here.
 */
final class Ownership
{
    public const BORROW = 0;
    public const NONE = -1;

    public const STR = 1;
    public const OBJ = 2;
    public const VEC = 3;
    public const ASSOC = 4;
    public const CELL = 5;
    public const CLOSURE = 6;
    /** A Generator frame: string-style rc header, released by the `str` helper. */
    public const GEN = 7;

    /** OR-ed onto a code for a slot raw on some paths and a cell on others.
     *  Produced by the ownership-flow pass (Task 4); nothing sets it yet. */
    public const MIXED = 8;

    public OwnershipContext $ctx;

    public function __construct(OwnershipContext $ctx)
    {
        $this->ctx = $ctx;
    }

    /**
     * The site-free question. A node does not know whether it is being STORED
     * or CONSUMED as a temp, so this is the store-side answer — the one a pass
     * without emitter state can compute. A temp consumer asks {@see classifyTemp}.
     *
     * @return int flavor code > 0 = Own(flavor), 0 = Borrow, -1 = None (scalar/no rc)
     */
    public function classify(Node $v): int
    {
        return $this->classifyStored($v);
    }

    /** Does a local that STORES `$v` own it (and so release it at scope exit /
     *  before an overwrite)? */
    public function classifyStored(Node $v): int
    {
        if ($this->storedOwned($v)) { return $this->ownCode($v->type); }
        return $this->flavorOf($v->type) > 0 ? self::BORROW : self::NONE;
    }

    /**
     * Is a CONSUMED operand a fresh +1 its consumer must give back?
     *
     * `$lastCallWasBuiltin` is EMITTER-ONLY: whether the call that produced `$v`
     * was answered by a codegen builtin rather than a `@manticore_*` body. Only
     * a CELL-typed CALL reads it ({@see tempCellOwned}); a caller without emitter
     * state passes false, which reads every such call as a body.
     *
     * TWIN-DRIFT: where this and {@see classifyStored} disagree today —
     *  - LoadLocal alias (and `(string)` of one) of an obj / string / cell:
     *    stored Own (AliasOwn::coOwns, the store retains), temp Borrow;
     *  - PropertyAccess of a string / non-closure obj, and StaticProp of a
     *    string: stored Own (AliasOwn::propReadCoOwns / strPropCoOwns), temp Borrow;
     *  - PropertyAccess of a vec / assoc, or of an erased bare-`array` slot,
     *    and StaticProp of a vec: stored Own (retain / `__mir_array_copy`), temp Borrow;
     *  - ArrayAccess on an ARRAY base co-owning its element (vec / assoc /
     *    string / closure / rc obj; Debug::$rcElemReadOwns): stored Own, temp
     *    Borrow — only a STRING-base char read is a fresh temp;
     *  - Invoke of an obj / array / cell result: stored Own, temp Borrow
     *    (a closure- or string-typed Invoke agrees);
     *  - Clone and an array-union Add stamped RC_HEAP: stored Own, temp Borrow
     *    (stamped ARENA, both are Borrow: the arena frees them);
     *  - Call of an obj / array / closure / cell result to a NON-FFI callee:
     *    stored Own for every one but a borrowing builtin; temp Own only for a
     *    fn with a FunctionDef in the module ({@see OwnershipContext::$moduleFns})
     *    that does not return by reference, plus the named minting builtins
     *    (array: builtinMintsOwnedArray; cell: json_encode / json_decode / max /
     *    min / the endpoint builtins) — a cell Call also reads the emitter-only
     *    `$lastCallWasBuiltin`. So a builtin, a by-ref-returning body and a
     *    stdlib name the module does not define differ;
     *  - Call of an array / cell / non-`Ffi\Ptr` obj / closure result to an FFI
     *    fn: stored Borrow (externFns), temp Own — moduleFns holds the FFI
     *    declaration, so tempArgFlavor / tempCellOwned read it as a body;
     *  - Call of a STRING result to an FFI fn: stored Borrow, temp Own;
     *  - NewObj / ArrayLit / Concat whose allocKind is not RC_HEAP (arena):
     *    stored Borrow, temp Own;
     *  - a conditional whose result is a UNION with an obj atom of empty class:
     *    stored Own (objClassIsRc('') is true), temp Borrow (condFlavor '').
     *
     * @return int flavor code > 0 = Own(flavor), 0 = Borrow, -1 = None (scalar/no rc)
     */
    public function classifyTemp(Node $v, bool $lastCallWasBuiltin = false): int
    {
        $k = $v->type->kind;
        if ($k === Type::KIND_STRING) {
            return $this->tempStrOwned($v) ? self::STR : self::BORROW;
        }
        if ($k === Type::KIND_CELL) {
            return $this->tempCellOwned($v, $lastCallWasBuiltin) ? self::CELL : self::BORROW;
        }
        if ($this->tempArgFlavor($v, $lastCallWasBuiltin) !== '') { return $this->ownCode($v->type); }
        return $this->flavorOf($v->type) > 0 ? self::BORROW : self::NONE;
    }

    /** The release-helper family of a code — for an Own code, exactly what
     *  {@see releaseFlavor} answers for the type it was derived from (`vec` /
     *  `assoc` name the family; the element depth stays releaseFlavor's). */
    public static function flavorName(int $code): string
    {
        if ($code === self::BORROW) { return 'borrow'; }
        if ($code === self::NONE) { return 'none'; }
        $mix = '';
        $base = $code;
        if (($code & self::MIXED) !== 0) {
            $mix = 'mix';
            $base = $code - self::MIXED;
        }
        if ($base === self::STR || $base === self::GEN) { return $mix . 'str'; }
        if ($base === self::OBJ) { return $mix . 'obj'; }
        if ($base === self::VEC) { return $mix . 'vec'; }
        if ($base === self::ASSOC) { return $mix . 'assoc'; }
        if ($base === self::CELL) { return $mix . 'cell'; }
        if ($base === self::CLOSURE) { return $mix . 'closure'; }
        return $mix . '?' . $base;
    }

    /** The code a SLOT of this type is released by, or NONE when it carries no
     *  refcount — the {@see releaseFlavor} decision (the {@see condFlavor} one
     *  for a UNION), plus the closure env it does not name. */
    public function flavorOf(Type $slot): int
    {
        if (self::isClosureValueType($slot)) { return self::CLOSURE; }
        $f = $slot->kind === Type::KIND_UNION ? $this->condFlavor($slot) : $this->releaseFlavor($slot);
        if ($f === '') { return self::NONE; }
        if ($f === 'str') { return $slot->kind === Type::KIND_OBJ ? self::GEN : self::STR; }
        if ($f === 'cell') { return self::CELL; }
        if ($f === 'obj') { return self::OBJ; }
        if (\str_starts_with($f, 'assoc')) { return self::ASSOC; }
        if (\str_starts_with($f, 'vec')) { return self::VEC; }
        return self::NONE;
    }

    /**
     * The Own code of a value classified owned. Two owned values have no slot
     * flavor, and both codes are CONVENTIONS — a consumer releases by the SLOT's
     * representation, never by this code:
     *  - an ERASED bare-`array` property read (KIND_UNKNOWN) → VEC (vec and
     *    assoc release through the one buffer family);
     *  - a store-side conditional over a UNION with an empty-class obj atom
     *    (the TWIN-DRIFT {@see condOwnedStored} names) → OBJ.
     */
    private function ownCode(Type $t): int
    {
        $c = $this->flavorOf($t);
        if ($c > 0) { return $c; }
        return $t->kind === Type::KIND_UNION ? self::OBJ : self::VEC;
    }

    public static function isClosureClass(string $cls): bool
    {
        return $cls === 'Closure' || \str_starts_with($cls, '__closure_');
    }

    /** A value that IS a closure env — `callable`/`Closure` under KIND_CLOSURE,
     *  or the `obj<__closure_N>` / `obj<Closure>` handle a literal carries. */
    public static function isClosureValueType(Type $t): bool
    {
        if ($t->kind === Type::KIND_CLOSURE) { return true; }
        return $t->kind === Type::KIND_OBJ && self::isClosureClass($t->class ?? '');
    }

    /** A scalar kind with no rc payload — an array of these needs no
     *  per-element drop, so its release/retain can skip the repr bits. */
    public static function isNonRcScalarKind(string $k): bool
    {
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_NULL;
    }

    /** An enum case is a value-type ORDINAL (no rc header) — never rc-managed,
     *  like an int. `$cls` is an obj type's class name. */
    public function isEnumClass(string $cls): bool
    {
        return $cls !== '' && isset($this->ctx->enums[$cls]);
    }

    // ── the store side ─────────────────────────────────────────────────────

    private function storedOwned(Node $value): bool
    {
        // A conditional (ternary / `?:` / `??` / match) the contract covers is an
        // owned producer: the emitter gives EVERY arm a +1 of the result type
        // ({@see Passes\EmitLlvmControl::armRetainPostBox}), so the destination local
        // owns it and must release it — that release is what stops the next
        // iteration of `$out = $c ? $s : ($out . ',' . $s);` from handing out a
        // freed block. Tested FIRST: its result may be a UNION (`$c ? new B :
        // new C`), which the kind gate below rejects, and it carries no
        // allocation of its own for the allocKind gate further down.
        //
        // ⚠ This answer must match {@see condOwnedTemp} exactly. If only the
        // emitter says owned, the value leaks; if only this side does, the
        // release has no matching retain and the value is double-freed.
        if ($this->condOwnedStored($value)) { return true; }
        // A CLOSURE LITERAL builds a fresh capturing env with rc=1 and a drop fn
        // ({@see Passes\EmitLlvmCalls::emitClosure}); the local owns it and releases it
        // at scope exit / before an overwrite, which is what frees both the env
        // and the +1 it took on every captured value. Only the literal counts:
        // a closure ARRIVING from anywhere else (a param, an element read, a
        // call return through an erased channel) stays borrowed, so nothing
        // over-releases a `Closure` this frame did not build.
        if ($value->kind === Node::KIND_CLOSURE) { return true; }
        // The caught exception: `throw` handed `@__mir_thrown` a +1, and the
        // catch takes it out of the slot ({@see CaughtValue_}).
        if ($value->kind === Node::KIND_CAUGHT_VALUE) { return true; }
        // A `yield` expression MOVES the sent value out of the frame's slot
        // ({@see Passes\EmitLlvmGenerator::emitYield}).
        if ($value->kind === Node::KIND_YIELD) { return true; }
        $tk = $value->type->kind;
        // A `Closure`-returning method types its call `closure`, not
        // `obj<Closure>`; the same producer rule as the object arm below.
        if ($tk === Type::KIND_CLOSURE) {
            $ck = $value->kind;
            if ($ck === Node::KIND_CALL) { return !isset($this->ctx->externFns[$value->function]); }
            // An ELEMENT read co-owns ({@see elemReadCoOwns}; the emitter half
            // is EmitLlvmLocals::elemReadCoOwn).
            if ($ck === Node::KIND_ARRAY_ACCESS) { return \Compile\Debug::$rcElemReadOwns; }
            if ($this->propReadCoOwns($value)) { return true; }
            return $ck === Node::KIND_METHOD_CALL || $ck === Node::KIND_STATIC_CALL
                || $ck === Node::KIND_INVOKE;
        }
        // A CELL counts: `f(): Foo|false` boxes a FRESH object into a cell, and
        // the +1 return convention transfers it to us exactly as for a plain
        // obj. Excluding it meant a cell local was NEVER released — the object
        // leaked and its __destruct never ran (`$r = fopen(...)` is precisely
        // this shape). The producer gate below keeps it symmetric: only a call /
        // new / clone is owned; a LoadLocal alias or an array read stays
        // borrowed, so a boxed value read out of a container is not over-
        // released. The drop itself (__mir_cell_drop) is tag-guarded, so a cell
        // holding an int/float/null is a no-op.
        // An all-object UNION read out of a property is the same bare object
        // pointer a plain object read is, and co-owns on the same terms.
        if ($tk === Type::KIND_UNION) { return $this->propReadCoOwns($value); }
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY
            && $tk !== Type::KIND_STRING && $tk !== Type::KIND_CELL) { return false; }
        // #[Struct] classes have no class_id/rc header (offset 0 is a
        // property) — they must never be rc-managed.
        if ($tk === Type::KIND_OBJ) {
            $cls = $value->type->class ?? '';
            if ($cls !== '' && isset($this->ctx->classes[$cls]) && $this->ctx->classes[$cls]->isStruct) {
                return false;
            }
            // Enum values are ORDINALS (an immortal per-case singleton when
            // boxed) — never rc-managed, whatever produced them. A `from()` /
            // a method returning the enum yields an obj<Enum> STATIC/METHOD call
            // that would otherwise be tracked as a +1 owned heap object and
            // rc_release the ordinal-as-pointer (SIGSEGV).
            if ($cls !== '' && isset($this->ctx->enums[$cls])) { return false; }
            // A closure env carries its own lifetime header
            // ({@see Passes\EmitLlvmCalls::emitClosure}), and a call hands one back
            // under the same +1 return convention an object rides: the callee
            // retains a borrowed closure it returns
            // ({@see returnBorrowsObj}), a returned owned
            // local transfers. So a call / invoke producer is owned; any other
            // (a property read) stays a borrow; an element read and an alias co-own. Refusing
            // them all meant a closure that left the frame that built it —
            // returned, then dropped — was never released, nor was anything
            // it captured.
            if ($cls === 'Closure' || \str_starts_with($cls, '__closure_')) {
                $ck = $value->kind;
                if ($ck === Node::KIND_CALL) { return !isset($this->ctx->externFns[$value->function]); }
                if ($ck === Node::KIND_ARRAY_ACCESS) { return \Compile\Debug::$rcElemReadOwns; }
                if ($this->propReadCoOwns($value)) { return true; }
                // `$fn = $s` over an `obj<__closure_N>` (a monomorphized
                // `callable` param): the store retains it like any object alias
                // ({@see AliasOwn::coOwns}, {@see Passes\EmitLlvmLocals}'s
                // $aliasObjStr), so the local owns that +1 — read as a borrow,
                // every call kept the env.
                if (AliasOwn::coOwns($value)) { return true; }
                return $ck === Node::KIND_METHOD_CALL || $ck === Node::KIND_STATIC_CALL
                    || $ck === Node::KIND_INVOKE;
            }
            // Ffi\Ptr is an opaque foreign pointer (FILE*/DIR*/raw addr) with
            // no rc header — rc-releasing it frees libc memory and aborts.
            if ($cls === 'Ffi\\Ptr') { return false; }
            // A Generator frame now carries a string-style rc header
            // (rc@-8, free base = ptr-16) — track it as owned so its frame is
            // freed on the last reference (EmitLlvm routes the release through
            // the str rc path). Its producer is a call/invoke (the creator).
        }
        $k = $value->kind;
        // The RELEASE half of {@see AliasOwn} — `$b = $s`, and the
        // pass-through `(string)$s` that is the same alias. Its retain half is
        // {@see Passes\EmitLlvmLocals}'s $aliasObjStr; both read this one predicate,
        // and the class carries what each failure mode cost. The kind gate and
        // the struct / enum / closure / Ffi\Ptr guards above are this caller's
        // own rc-eligibility test, which AliasOwn deliberately does not make.
        if (AliasOwn::coOwns($value)) { return true; }
        // …and a property / static-property read of every rc kind, which the
        // emitter retains the same way ({@see propReadCoOwns}).
        if ($this->propReadCoOwns($value)) { return true; }
        // A string / cell bitwise op mints its result like a concat, on the
        // heap whatever the allocKind says ({@see BitOp::mintsFresh}).
        if (BitOp::mintsFresh($value)) { return true; }
        // `(string)$int` / `(string)$float` ALLOCATE — __mir_int_to_str and
        // __mir_float_to_str hand back a fresh rc=1 buffer exactly as a string
        // builtin does. This was the one producer nobody owned: the local took
        // the +1, no release was ever scheduled, and a rebind in a loop dropped
        // the reference on the floor. `for (…) { $s = (string)$i; }` leaked one
        // string per iteration — 63 MB per 1M where php is flat, and every
        // decorate/serialize loop that stringifies a counter pays it.
        //
        // EVERY operand but a STRING. A string is returned unchanged
        // ({@see Passes\EmitLlvmExpr::emitCast}) — a borrow, and owning it would free
        // the source. bool/array reach immortal literals, where a release is a
        // no-op, so claiming them costs nothing and missing a minting arm
        // costs one buffer per cast.
        if ($k === Node::KIND_CAST && $value->type->kind === Type::KIND_STRING) {
            // The twin of {@see tempStrOwned}'s cast arm, and it has to answer
            // identically or the temp is freed twice or never. Only a STRING
            // operand is returned unchanged — a borrow. Every other kind mints
            // (int/float/erased-raw), retains the payload it aliases (cell /
            // erased-boxed), or reaches an IMMORTAL literal where the release
            // is a no-op.
            if ($value->operand->type->kind === Type::KIND_STRING) {
                // The pass-through arm inherits its operand's ownership.
                return $this->storedOwned($value->operand);
            }
            return true;
        }
        // A call transfers a +1 owned ref (the return convention) for
        // any flavor (incl. string builtins: substr / strtolower / …).
        // EXCEPT an FFI call: it returns a foreign libc buffer/pointer
        // with no rc header — rc-releasing it frees raw memory → abort.
        if ($k === Node::KIND_CALL) {
            // __mir_fiber_current() hands back a BORROWED alias of the
            // @__mir_current_fiber global (owned by the user's own `$f`), not a
            // +1 ref — releasing it at scope exit would free the live fiber
            // mid-run (use-after-free ⇒ a garbage resumer ⇒ jump into hyperspace).
            if (\in_array(\ltrim($value->function, '\\'), $this->ctx->borrowingBuiltins, true)) { return false; }
            return !isset($this->ctx->externFns[$value->function]);
        }
        if ($k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) {
            return true;
        }
        // An ELEMENT READ co-owns what it hands out — the emitter retains it in
        // {@see Passes\EmitLlvmLocals::emitStoreLocal}, so the local must release it.
        // The two are one change: see {@see \Compile\Debug::$rcElemReadOwns}.
        // Without it `$keep = $m['a']; unset($m);` hands back freed memory.
        // ⚠ The two halves must decide on the SAME predicate, or they disagree
        // on a name and leave a retain with no release — the extra `dtor elem`
        // php never runs. {@see Passes\EmitLlvmLocals::elemReadCoOwn} is the other half.
        if (\Compile\Debug::$rcElemReadOwns && $k === Node::KIND_ARRAY_ACCESS
            && self::elemReadCoOwns($value->type, $this->ctx->enums, $this->ctx->classes)) {
            return true;
        }
        if (self::cellElemReadCoOwns($value)) { return true; }
        // A PROPERTY read of an ARRAY is owned BY RETAIN rather than by
        // allocation — the one producer the pass could not see, because it gates
        // on `effects->alloc`. {@see Passes\EmitLlvmLocals::emitStoreLocal}'s snapshot
        // path already takes a +1 on it (`$saved = $this->map`) so that a later
        // mutation of either side copy-on-writes instead of clobbering the
        // other's buffer; the retain cannot simply be dropped, because a borrow
        // that left rc alone would let a mutation through the local see rc == 1
        // and write THROUGH into the property. The local genuinely owns — and
        // nothing ever released it, neither on a rebind nor at scope exit.
        //
        // That is ROOT 1 of the compiler's own monotone climb: InferTypes::
        // mergeLocals' per-block local-type maps (402 MB of __mir_array_set_str,
        // 69.7% of that allocator) were still resident at a snapshot taken with
        // the process blocked in clang, with nothing on any stack holding them.
        //
        // The slot's REPRESENTATION decides the flavor
        // ({@see Passes\InsertMemoryOps::slotStoredType}), which is what makes
        // this claim safe: a nullable array property reads back a NaN-boxed
        // cell, and it is released as a cell.
        if ($k === Node::KIND_PROPERTY_ACCESS
            && ($value->type->isVec() || $value->type->isAssoc())) {
            return true;
        }
        // …and the SAME read of a slot declared a bare `array`, whose type erased
        // to KIND_UNKNOWN so neither isVec() nor isAssoc() sees it. The emitter's
        // half already had this fallback and this one did not — so for
        // `Compile\Mir\Type::$typeArgs`, `ClassDef::$typeParams` and every other
        // undeclared-element array property, `$a = $o->prop` took a +1 that
        // NOTHING ever released. That is the compiler's own top live-set site:
        // `InferCalls::genericReturnType` held 830 279 blocks / 63.3 MB at the
        // peak, all of them arrays reaching a slot no release was scheduled for.
        // The rule this restores is the file's own: both halves decide on ONE
        // predicate, or a retain is left without its release.
        if ($k === Node::KIND_PROPERTY_ACCESS && $this->erasedArrayPropRead($value)) {
            return true;
        }
        // A VEC read of a STATIC property is answered with `__mir_array_copy`
        // ({@see Passes\EmitLlvmLocals::emitStoreLocal}'s $copiedVecProp — the same
        // snapshot the instance-property arm above takes), so the local holds
        // a fresh rc=1 buffer of its own. The pass's storeMakesArrayCopy
        // already named the pair; this half did not, so the copy was never
        // released: `$out = Context::$emptyGpc; …; return $out;` in
        // Http\Request::filesArray() left one buffer behind per compat request.
        // An ASSOC read of one — and a vec read a held-read spill co-owns
        // without the copy — is retained like the instance-property arm above:
        // the static's store releases what it overwrites, so a borrow would
        // dangle (`$m = C::$map; C::$map = [];` freed `$m`).
        if ($k === Node::KIND_STATIC_PROP && ($value->type->isVec() || $value->type->isAssoc())) {
            return true;
        }
        // A fresh RcHeap allocation: `new` (obj) / array-literal (vec) /
        // concat (string). Arena values are excluded — freed by the arena
        // scope; rc-releasing them would be wrong (their header is -1 so
        // release no-ops, but don't track them as owned regardless).
        if ($value->allocKind !== AllocationKind::RC_HEAP) { return false; }
        if ($tk === Type::KIND_OBJ) { return $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE; }
        if ($tk === Type::KIND_STRING) { return $k === Node::KIND_CONCAT; }
        // An array-typed `+` is the union operator — __mir_array_union returns a
        // FRESH +1 array, so it is owned exactly like a literal.
        return $k === Node::KIND_ARRAY_LIT
            || ($tk === Type::KIND_ARRAY && $k === Node::KIND_ADD);
    }

    /**
     * Does a local that stores this property / static-property read co-own
     * it? For every rc kind but an array (whose read is copied or retained on
     * its own arm, below): a string, an object, a closure env, an all-object
     * union, a cell. A property store always releases what it overwrites, so a
     * read kept as a borrow would dangle the moment the slot is written. The
     * emitter's retain ({@see Passes\EmitLlvmLocals::emitStoreLocal}) asks this
     * same predicate. A `#[Struct]`, an enum ordinal and an `Ffi\Ptr` have no
     * count to take.
     */
    public function propReadCoOwns(Node $v): bool
    {
        if (!AliasOwn::propReadCoOwns($v)) { return false; }
        $t = $v->type;
        $k = $t->kind;
        if ($k === Type::KIND_UNION) { return $this->condFlavor($t) === 'obj'; }
        if ($k !== Type::KIND_OBJ) { return true; }
        $cls = $t->class ?? '';
        if ($cls === 'Ffi\\Ptr' || $this->isEnumClass($cls)) { return false; }
        return !($cls !== '' && isset($this->ctx->classes[$cls]) && $this->ctx->classes[$cls]->isStruct);
    }

    /**
     * A read of a property whose slot is declared a bare `array` but whose TYPE
     * erased to KIND_UNKNOWN — the case {@see storedOwned}'s vec/assoc test
     * cannot see and {@see Passes\EmitLlvmLocals::emitStoreLocal}'s `$aliasArrayProp`
     * already retains for.
     *
     * ⚠ Deliberately a STRICT SUBSET of the emitter's condition: the class must
     * be named AND declare the property itself, so `slotHolder` over there is
     * guaranteed to reach the same ClassDef and see the same hint. Under-
     * claiming here costs today's leak; over-claiming would schedule a release
     * against a retain that was never emitted, which is a use-after-free.
     */
    public function erasedArrayPropRead(Node $value): bool
    {
        if ($value->kind !== Node::KIND_PROPERTY_ACCESS) { return false; }
        if ($value->type->isVec() || $value->type->isAssoc()) { return false; }
        if ($value->type->kind !== Type::KIND_UNKNOWN) { return false; }
        return $this->propReadArrayHinted($value);
    }

    /** Read through a PropertyAccess_-typed param so `->object` / `->property`
     *  resolve the right field offsets under the self-host. */
    private function propReadArrayHinted(PropertyAccess_ $pa): bool
    {
        $cls = $pa->object->type->class ?? '';
        if ($cls === '' || !isset($this->ctx->classes[$cls])) { return false; }
        $cd = $this->ctx->classes[$cls];
        if ($cd->propertyOffset($pa->property) < 0) { return false; }
        return $cd->propertyArrayHinted[$pa->property] ?? false;
    }

    /**
     * A CELL read out of a container's element into a local co-owns what it
     * holds, as a string / array / object element read does: the emitter takes
     * `__mir_cell_retain` ({@see Passes\EmitLlvmLocals::elemReadCoOwn}) and the
     * store is Own ({@see storedOwned}). A bare borrow was safe only while no
     * cell element slot dropped what it held; now an overwrite or an unset
     * does, and `$y = $a[0]; $a[0] = 5; $y->n` read freed memory.
     */
    public static function cellElemReadCoOwns(Node $v): bool
    {
        if (!\Compile\Debug::$rcElemReadOwns) { return false; }
        if (!($v instanceof ArrayAccess_) || $v->probe) { return false; }
        if ($v->type->kind !== Type::KIND_CELL) { return false; }
        $at = $v->array->type;
        return $at->isVec() || $at->isAssoc() || $at->kind === Type::KIND_OBJ;
    }

    /**
     * Does an ELEMENT read of this type co-own what it hands out? One predicate
     * for the store side ({@see storedOwned}) and the emitter's element-read
     * retain ({@see Passes\EmitLlvmLocals::elemReadCoOwn}).
     *
     * @param array<string, EnumDef> $enums
     * @param array<string, ClassDef> $classes
     */
    public static function elemReadCoOwns(?Type $t, array $enums, array $classes = []): bool
    {
        if ($t === null) { return false; }
        if ($t->isVec() || $t->isAssoc()) { return true; }
        // A STRING element is co-owned on exactly the same terms, and leaving it
        // out was the last hole: `$s = $m['k']; $m['k'] = '';` and its `foreach`
        // twin handed back FREED bytes the moment the element SLOT started
        // dropping ({@see \Compile\Debug::$rcElemSlotDrop}). Both rc helpers
        // self-guard — `__mir_rc_retain_str` / `__mir_rc_release_str` no-op on
        // null and on an IMMORTAL literal (negative rc) — so a slot holding a
        // constant costs nothing and a heap string is counted like any other.
        if ($t->kind === Type::KIND_STRING) { return true; }
        // A CLOSURE element is co-owned too, now that its slot gives its count
        // back on overwrite / unset / container death ({@see \Compile\MemoryAbi::
        // ARRAY_REPR_CLO}): `$f = $a[$k]; unset($a[$k]); $f();` would otherwise
        // call a freed env. rcRetainByType's closure arm takes the +1 and the
        // local's `closure` release gives it back — both through the helpers
        // that act only on a word carrying the env magic.
        if ($t->kind === Type::KIND_CLOSURE) { return true; }
        if ($t->kind === Type::KIND_OBJ) {
            $c = $t->class ?? '';
            if ($c === '') { return true; }
            // ★★★ REFUSE EXACTLY WHAT THE RETAIN MACHINERY REFUSES. This used to
            // exclude enums ALONE, while the emitter's half takes its +1 through
            // {@see Passes\EmitLlvmMemory::rcRetainByType}, which SILENTLY returns ''
            // for a `#[Struct]`, an `Ffi\Ptr` and a `Generator` (a closure has its own arm).
            // Agreeing with itself is not enough — a predicate that says "owned"
            // where the retain emits nothing leaves the pass's scope-exit
            // release with NOTHING to balance it, and these are precisely the
            // records with NO rc header, so the decrement lands in the
            // allocator's own metadata. The corruption then surfaces anywhere
            // (a SIGSEGV in `Walk::children` on a Node that was fine), and
            // never as an rc<=0 abort, because the word being decremented is
            // not a refcount at all.
            if (isset($enums[$c])) { return false; }
            if ($c === 'Ffi\\Ptr') { return false; }
            if ($c === 'Closure' || \str_starts_with($c, '__closure_')) { return true; }
            // A Generator retains through the STRING rc path and would be
            // released through the object one — a flavor disagreement of the
            // same family [[rc-flavor-disagreement]]. Left out entirely.
            if ($c === 'Generator') { return false; }
            $cd = $classes[$c] ?? null;
            if ($cd !== null && $cd->isStruct) { return false; }
            return true;
        }
        return false;
    }

    /** {@see CondOwn} — the shared half of the contract, plus the store side's
     *  rc-eligibility guard on the result type.
     *  TWIN-DRIFT: {@see condOwnedTemp} guards by {@see condFlavor}, which refuses
     *  a UNION with an obj atom of EMPTY class; this side accepts it. */
    public function condOwnedStored(Node $value): bool
    {
        if (!CondOwn::isConditional($value)) { return false; }
        if (!$this->condResultIsRc($value->type)) { return false; }
        return CondOwn::armsCoverable($value);
    }

    private function condResultIsRc(Type $t): bool
    {
        if (CondOwn::shapeIsRc($t)) { return true; }
        $k = $t->kind;
        if ($k === Type::KIND_OBJ) { return $this->objClassIsRc($t->class ?? ''); }
        if ($k !== Type::KIND_UNION) { return false; }
        $atoms = $t->atoms;
        if (\count($atoms) === 0) { return false; }
        foreach ($atoms as $a) {
            if ($a->kind !== Type::KIND_OBJ) { return false; }
            if (!$this->objClassIsRc($a->class ?? '')) { return false; }
        }
        return true;
    }

    /** ⚠ Character-for-character the obj guards of {@see releaseFlavor} and the
     *  union loop of {@see condFlavor} — both sides must answer identically. */
    public function objClassIsRc(string $cls): bool
    {
        if ($cls === 'Ffi\\Ptr' || $cls === 'Closure') { return false; }
        if (\str_starts_with($cls, '__closure_')) { return false; }
        if ($cls !== '' && isset($this->ctx->enums[$cls])) { return false; }
        if ($cls !== '' && isset($this->ctx->classes[$cls]) && $this->ctx->classes[$cls]->isStruct) { return false; }
        return true;
    }

    // ── the temp side ──────────────────────────────────────────────────────

    /**
     * Does this node yield an OWNED (+1) value because it is a conditional the
     * emitter normalizes? The contract and the arm rule live in {@see CondOwn} —
     * the same predicate {@see condOwnedStored} uses.
     *
     * True here means: every arm was given a +1 of this node's result type
     * ({@see Passes\EmitLlvmControl::armRetainPostBox}), so consumers must treat the
     * result as a fresh temp — release it when done, never add a second retain.
     */
    public function condOwnedTemp(Node $n): bool
    {
        if (!CondOwn::isConditional($n)) { return false; }
        if ($this->condFlavor($n->type) === '') { return false; }
        return CondOwn::armsCoverable($n);
    }

    /**
     * A string that was just produced fresh (concat result or an owned
     * call/builtin return) — not a borrow (literal / local / property /
     * element read). Such a value, once consumed (a concat operand, a
     * borrowed call argument), is dead and can be freed.
     */
    public function tempStrOwned(Node $node): bool
    {
        if ($node->type->kind !== Type::KIND_STRING) { return false; }
        $k = $node->kind;
        // A conditional (ternary / `?:` / `??` / match) hands out +1 from EVERY
        // arm ({@see Passes\EmitLlvmControl::armRetainPostBox}), so its result is a
        // fresh temp exactly like a concat. Only the shapes CondOwn declares
        // owned qualify — one with an erased arm stays borrowed.
        if ($this->condOwnedTemp($node)) { return true; }
        if (self::isStrCharRead($node)) { return true; }
        // `(string)$int` / `(string)$float` MINT a buffer (__mir_int_to_str /
        // __mir_float_to_str), so a consumer that frees its fresh operands must
        // free this one: `strlen((string)$i)` and `$m[(string)$i] = 1` each
        // leaked one string per call. The same arm is asked by the store side
        // ({@see storedOwned}) — the two sides have to answer identically, or a
        // temp is freed twice or never.
        // A STRING operand returns the SAME pointer — the one borrow.
        if ($k === Node::KIND_CAST) {
            // Every arm of the cast but ONE hands back a +1: int/float mint,
            // a CELL retains the payload it aliases ({@see
            // Passes\EmitLlvmExpr::cellStrResultOwnIr}), and the ERASED dispatch
            // ({@see Passes\EmitLlvmExpr::coerceToStr}) is that same retain on its
            // boxed arm, `__mir_int_to_str` on its raw arm and an IMMORTAL
            // literal ("Array", "") on the rest — a release on rc < 0 is a
            // no-op, so naming them all is sound and naming none of them
            // stranded one buffer per `strlen((string)json_encode($v))`, where
            // `strlen(json_encode($v))` was flat: `json_encode` types as
            // `unknown` here, so the CELL arm never fired. Only a STRING
            // operand comes back unchanged, and owning that would free the
            // source.
            if ($node->operand->type->kind === Type::KIND_STRING) {
                // The pass-through arm INHERITS its operand's ownership.
                // `(string)$borrow` is a borrow, but `(string)array_pop($t)`
                // is the popped element itself and its owner is whoever
                // consumes the cast — reading the arm as a flat borrow left
                // that element unowned, which is the whole of `array_pop`'s
                // and `array_shift`'s row in the ownership table.
                return $this->tempStrOwned($node->operand);
            }
            return true;
        }
        return $k === Node::KIND_CONCAT || $k === Node::KIND_CALL
            || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_INVOKE || BitOp::mintsFresh($node);
    }

    /**
     * `$s[$i]` on a STRING base is an ALLOCATION, not a borrow:
     * `__mir_str_char_at` mints a fresh 1-char headered buffer for every read
     * ({@see Passes\DemoteCharLocals}, which exists because that allocation is
     * expensive). Only the reads DemoteCharLocals could not prove dead reach the
     * emitter, and a consumer that frees its other fresh operands has to free
     * this one too — `$out = $out . $s[$i]` leaked one buffer per character,
     * which is the whole of urldecode's 305 B/call. An ARRAY element read stays
     * a borrow: it hands back the container's own reference.
     *
     * ONE predicate, asked by BOTH sides of the ownership contract. It lived
     * inline in {@see tempStrOwned} only, so {@see Passes\EmitLlvmControl::armIsFresh}
     * read the same node as BORROWED and a conditional arm normalizing to +1
     * retained a buffer that was already +1: `$out . ($ok ? $s[$i] : '=')` — the
     * shape of base64_encode's inner loop — leaked one char buffer per iteration
     * at rc 1, and only the ternary form of it, which is why the identical
     * ternary-free loop right above it was clean.
     */
    public static function isStrCharRead(Node $n): bool
    {
        return $n->kind === Node::KIND_ARRAY_ACCESS
            && $n->array->type->kind === Type::KIND_STRING;
    }

    /**
     * A fresh, OWNED cell temp — the cell twin of {@see tempStrOwned}.
     *
     * The +1 return convention covers cells: `EmitLlvmModule::emitReturn`
     * retains a BORROWED cell payload before handing it back (both the
     * boxing arm and the already-a-cell arm), for the stated reason that the
     * caller may `__mir_cell_drop` a discarded result. So a call result IS the
     * caller's to drop — the evidence is the one {@see
     * Passes\EmitLlvmCalls::emitDiscardedCallRelease} already trusts: a user body was
     * called (a name in the module), and it does not return by reference.
     *
     * A BUILTIN result is not owned in general (many hand back a borrowed
     * element), so only the emitters that provably MINT their result are named.
     * `$lastCallWasBuiltin` is the EMITTER-ONLY fact that the call just emitted
     * was answered by a codegen builtin.
     */
    public function tempCellOwned(Node $n, bool $lastCallWasBuiltin): bool
    {
        if ($n->type->kind !== Type::KIND_CELL) { return false; }
        // A normalized conditional hands out +1 from every arm ({@see
        // Passes\EmitLlvmControl::armRetainPreBox} retains the borrowed one), so a
        // cell-typed `$_GET['a'] ?? '-'` is a fresh temp its consumer
        // drops by the tagged word — exactly like a cell call result. Without
        // this arm the +1 had no taker anywhere but an assignment: a concat
        // operand, a builtin argument and a cast each stranded the payload.
        if (CondOwn::isConditional($n)) { return $this->condOwnedTemp($n); }
        $k = $n->kind;
        // The sent value a `yield` expression moved out of the frame.
        if ($k === Node::KIND_YIELD) { return true; }
        // A closure / callable INVOKE returns under the same +1 convention
        // ({@see returnBorrowsObj} and {@see keyTempRelease} already read it as
        // fresh): `[...$closure()]` stranded the whole array it spread.
        if ($k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) { return true; }
        // `clone` of an erased value boxes a fresh copy — +1 on every arm of
        // the runtime-class dispatch ({@see Passes\EmitLlvmObjects::
        // emitCloneDispatch} retains on its pass-through arm for this reason).
        if ($k === Node::KIND_CLONE) { return true; }
        // `+ - *` over a numeric cell run {@see Passes\EmitLlvmExpr::emitTaggedArith}:
        // the helper boxes a NEW cell on every path, a counted heap box past the
        // inline int form.
        if (($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL)
            && $n->type->isNumericCell()) { return true; }
        if (BitOp::mintsFresh($n)) { return true; }
        if ($k !== Node::KIND_CALL) { return false; }
        $fn = $n->function;
        // `json_encode` boxes a buffer `__mir_json_encf` just allocated;
        // `json_decode` boxes the value its parser just built. Both are fresh
        // whichever path ran — the native builtin (flags 0) and the stdlib body
        // alike — so the name answers for both.
        if ($fn === 'json_encode' || $fn === 'json_decode') { return true; }
        // `max`/`min` over ONE array delegate to the stdlib fold, and a PHP
        // body's cell return is +1 by the return convention — so the winner is
        // the caller's to drop, which is what lets the rebuilt argument array
        // be freed ({@see Passes\EmitLlvmBuiltins::biMinMax}).
        if ($fn === 'max' || $fn === 'min') { return true; }
        // The weak registry's way back to an object retains what it boxes
        // ({@see Passes\EmitLlvmBuiltins::biObjFromAddr}).
        if ($fn === '__mc_obj_from_addr') { return true; }
        // The CLASS C builtins ({@see Passes\EmitLlvmBuiltins::emitArrPtrArg}): the
        // result IS an element or a key of the argument, and the emitter now
        // retains it ({@see Passes\EmitLlvmBuiltins::cellEndpointRetain}) so the
        // argument can be freed under it. That +1 is the caller's to give
        // back. Naming them is the THIRD half of the one change — without it
        // the retain is a leak, and it is safe when the dispatch falls
        // through to a PHP body instead, which returns +1 by the return
        // convention anyway.
        foreach ([
            'array_first', 'array_last', 'array_key_first', 'array_key_last',
            'current', 'pos', 'key', 'reset', 'end', 'next', 'prev',
        ] as $cn) {
            if ($fn === $cn) { return true; }
        }
        if ($lastCallWasBuiltin) { return false; }
        return isset($this->ctx->moduleFns[$fn])
            && !($this->ctx->returnsByRef[$fn] ?? false);
    }

    /**
     * A builtin whose ARRAY result is a fresh allocation the caller owns
     * outright — every element minted or copied WITH a reference.
     *
     * A NAME list, like {@see EscapeSummaries::keepsNoArg}: a builtin has no
     * body for the module to answer for, and the conservative default (not
     * owned) is a LEAK of the whole array at every consumer. Anything absent
     * keeps that leak, which is the safe direction — the wrong direction here
     * frees an array a later reader still holds.
     *
     * `explode` / `str_split` / `preg_split` give every piece its own
     * `__mir_str_alloc`; `range` / `array_fill` mint scalars; `array_keys` and
     * `array_values` co-own each element they copy ({@see
     * Passes\EmitLlvmBuiltins::emitArrPtrArg}, class B). A builtin that hands back a
     * BORROWED array — or one of its elements — must never be listed.
     */
    public static function builtinMintsOwnedArray(string $fn): bool
    {
        $p = \strrpos($fn, chr(92));
        $bare = $p === false ? $fn : \substr($fn, $p + 1);
        foreach ([
            'explode', 'str_split', 'preg_split', 'array_keys', 'array_values',
            'range', 'array_fill', 'str_getcsv',
        ] as $n) {
            if ($n === $bare) { return true; }
        }
        return false;
    }

    /**
     * The release flavor of a fresh rc ARG temp (the emitter's helper
     * vocabulary: `str` / `obj` / `vecbuf` / `closure` / `cell` / …), or '' when
     * the argument is not an owned temp.
     *
     * ⚠ A STRING-typed operand answers '' here unless it is a normalized
     * conditional: fresh strings are {@see tempStrOwned}'s, and the call sites
     * that release them ask that first.
     */
    public function tempArgFlavor(Node $a, bool $lastCallWasBuiltin): string
    {
        // A normalized conditional is +1 from every arm, so a borrowed-arg temp
        // must be released after the call like any other fresh producer. Tested
        // first: its result type may be a UNION (which the obj/array gate below
        // would reject) and the flavor comes from condFlavor, not the arm.
        if ($this->condOwnedTemp($a)) {
            // A CELL result is released like any other owned temp. It was
            // exempted when this contract was written, with no reason recorded,
            // and the exemption disagreed with {@see tempCellOwned} two lines
            // down — which answers 'cell' for exactly this shape, an owned
            // cell-typed argument temp. `count($m ?? mk())` on a `mixed $m`
            // therefore stranded the whole assoc `mk()` built, once per call
            // (measured: 2.5 MB at 1k iterations, 131 MB at 400k).
            // `__mir_cell_drop` dispatches on the tag, so a scalar or `false`
            // payload is a no-op.
            return $this->condFlavor($a->type);
        }
        // A cell CALL result is owned by the caller under the same +1 return
        // convention ({@see tempCellOwned}); `f(json_encode($v))` leaked the
        // whole document. `__mir_cell_drop` dispatches on the tag, so a `false`
        // or an int payload is a no-op.
        if ($this->tempCellOwned($a, $lastCallWasBuiltin)) { return 'cell'; }
        // An erased array a declared-`array` callee hands back is +1 on every
        // path ({@see erasedArrayReturn}), raw or tagged.
        if (!($lastCallWasBuiltin && $a->kind === Node::KIND_CALL) && $this->erasedArrayCall($a)) { return self::ERASED_ARR; }
        // A closure LITERAL is a fresh +1: {@see Passes\EmitLlvmCalls::emitClosure}
        // allocates an env with its own lifetime header at rc 1, and the
        // callee co-owns whatever it keeps ({@see
        // Passes\EmitLlvmMemory::rcRetainByType}'s closure arm retains a BORROWED
        // closure on every alias / element / property store). Nobody freed
        // the argument, so `array_filter($t, "strlen")` — a string callable
        // coerced to a closure at lowering — allocated one env per call and
        // freed none. Only the LITERAL: a closure read out of a local or a
        // property is a borrow.
        if ($a->kind === Node::KIND_CLOSURE) { return 'closure'; }
        if ($a->kind === Node::KIND_CAUGHT_VALUE) { return 'obj'; }
        // …and so is a closure a CALL hands back, under the +1 return
        // convention rcRetainByType's closure arm already reads as a transfer:
        // `$reg->on($obj->makeHook())` retained it into the registry and the
        // call's own count was never given back.
        if (self::isClosureValueType($a->type)) {
            $ck = $a->kind;
            if ($ck === Node::KIND_METHOD_CALL || $ck === Node::KIND_STATIC_CALL || $ck === Node::KIND_INVOKE) { return 'closure'; }
            if ($ck === Node::KIND_CALL) {
                $cfn = $a->function;
                if (isset($this->ctx->moduleFns[$cfn]) && !($this->ctx->returnsByRef[$cfn] ?? false)) { return 'closure'; }
            }
            return '';
        }
        $tk = $a->type->kind;
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY) { return ''; }
        $k = $a->kind;
        // An array literal is always a fresh +1 (obj/vec/assoc alike). When its
        // ELEMENTS are arrays, each of those went through this same argument
        // path — a fresh one is registered here and released after the call, a
        // borrowed one retained and given back — so the literal is only the
        // BUFFER around them. Its release must not walk them: the repr-mode
        // `vec` release happened to drop nothing only while such a buffer
        // carried no repr bits, and dropping by the element hint freed
        // `array_merge($a, f())`'s `f()` twice.
        if ($k === Node::KIND_ARRAY_LIT) {
            $el = $a->type->element;
            if ($el !== null && $el->isArray()) { return $a->type->isAssoc() ? 'assocbuf' : 'vecbuf'; }
            return $this->releaseFlavor($a->type);
        }
        // An ASSOC result used to be exempted here, on the reading that
        // the borrowed-return test covered only obj/vec/string. It has covered
        // assoc since — "vec AND assoc: both are one rc'd buffer" — so the
        // exemption outlived its reason and made every assoc-returning
        // builtin body leak its whole result: `count(array_flip($t))` and
        // `count(array_combine($k, $v))` were 22-105 MB in the ownership
        // table where the same loop over the argument alone is 1.8.
        // An array `+` is `__mir_array_union`'s fresh buffer, a literal's twin
        // ({@see Passes\InferAllocKind} heaps it for the same reason).
        // A `clone` mints a fresh +1 exactly as `new` does: left out, a clone
        // passed as an argument (`f(clone $t)`, `$fixed[$i] = clone $v`) was
        // owned by nobody and lived until shutdown.
        $owned = $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE
              || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
              || ($k === Node::KIND_ADD && $tk === Type::KIND_ARRAY);
        if ($k === Node::KIND_CALL) {
            $fn = $a->function;
            $owned = isset($this->ctx->moduleFns[$fn]) && !($this->ctx->returnsByRef[$fn] ?? false);
            // …or a BUILTIN that mints a fresh array. A module body is evidence
            // a user BODY was called, and a codegen builtin has no body — so
            // `array_slice(explode($d, $s), 0, 2)` stranded the exploded vec:
            // the arg was owned by nobody and the release was never emitted.
            if (!$owned) { $owned = self::builtinMintsOwnedArray($fn); }
        }
        if (!$owned) { return ''; }
        return $this->releaseFlavor($a->type);
    }

    /**
     * The rc flavor a CONDITIONAL result is retained / released by, or '' when
     * it is not rc-managed. {@see releaseFlavor} plus the union mapping:
     * an all-object union rides a bare object pointer, so it drops like one —
     * but only when EVERY member is a real rc'd class (a #[Struct] / closure /
     * enum / Ffi\Ptr member has no rc header, and rc-managing one writes into
     * the allocator's metadata).
     */
    /**
     * An element of an all-rc-object UNION (`[$c ? new A : new C]`): it rides
     * a bare object pointer ({@see condFlavor}), so its buffer is owned, hinted
     * and dropped exactly as an `obj` element's. Answered '' it was the repr
     * walk over bits no producer stamps — every element leaked.
     */
    public function objUnionElem(Type $el): bool
    {
        return $el->kind === Type::KIND_UNION && $this->condFlavor($el) === 'obj';
    }

    public function condFlavor(Type $t): string
    {
        if ($t->kind !== Type::KIND_UNION) { return $this->releaseFlavor($t); }
        $atoms = $t->atoms;
        if (\count($atoms) === 0) { return ''; }
        foreach ($atoms as $a) {
            if ($a->kind !== Type::KIND_OBJ) { return ''; }
            $cls = $a->class ?? '';
            if ($cls === '' || $cls === 'Ffi\\Ptr') { return ''; }
            if (self::isClosureClass($cls) || $this->isEnumClass($cls)) { return ''; }
            if (isset($this->ctx->classes[$cls]) && $this->ctx->classes[$cls]->isStruct) { return ''; }
        }
        return 'obj';
    }

    /**
     * The `obj` / `str` / `buf` suffix for an array whose ELEMENT is an object,
     * decided by running the element through the scalar-object guards of
     * {@see releaseFlavor}.
     *
     * The element branches used to guard only enums, so `vec[Closure]` answered
     * `vecobj` and its release ran `__mir_rc_release` on a record with no rc
     * header — the word at ptr-8 is the allocator's metadata. `serialize([$c])`
     * trapped on it. A `#[Struct]` or `Ffi\Ptr` element had the same exposure,
     * and a `Generator` element wants the string-style rc path its scalar form
     * already asks for. One helper, so the two levels cannot disagree again.
     */
    public function elemObjFlavor(Type $el): string
    {
        $f = $this->releaseFlavor($el);
        if ($f === 'obj') { return 'obj'; }
        if ($f === 'str') { return 'str'; }
        // A closure element is owned by the buffer's CLO repr, which only the
        // repr walk reads ({@see \Compile\MemoryAbi::ARRAY_REPR_CLO}).
        if (self::isClosureValueType($el)) { return ''; }
        return 'buf';   // #[Struct] / Ffi\Ptr / enum ordinal: nothing to drop
    }

    /**
     * Flavor string for releasing an rc-managed value of type `$t`, or
     * '' when `$t` is not rc-managed (scalar / void / #[Struct] / closure).
     * The {@see Passes\EmitLlvm::rcReleaseReg} vocabulary.
     */
    public function releaseFlavor(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_STRING) { return 'str'; }
        // A CELL is tag-dispatched by __mir_cell_drop (scalars a no-op). Without
        // this it fell through to '' — so `unset($r)` on a `Foo|false` local
        // released NOTHING and its __destruct never ran.
        if ($k === Type::KIND_CELL) { return 'cell'; }
        if ($k === Type::KIND_OBJ) {
            $cls = $t->class ?? '';
            // `Ffi\Ptr` is a raw foreign address with NO rc header: the word at
            // ptr-8 is the allocator's own metadata, not a refcount. Releasing
            // one decrements that metadata in place and, at zero, hands the
            // block to the string pool — silently corrupting the heap until a
            // later free() trips a libmalloc assertion. Mirrors the guard in
            // rcRetainRawByType; without it a DISCARDED `\Runtime\Libc\memset(...)`
            // (any Ptr-returning FFI call used as a statement) corrupts the heap.
            if ($cls === 'Ffi\\Ptr') { return ''; }
            if ($cls !== '' && isset($this->ctx->classes[$cls]) && $this->ctx->classes[$cls]->isStruct) { return ''; }
            if (self::isClosureClass($cls)) { return ''; }
            if ($this->isEnumClass($cls)) { return ''; }
            // A Generator frame carries a string-style rc header (rc@-8, free
            // base = ptr-16) — release it via the str rc path so the frame
            // buffer is freed on its last reference.
            if ($cls === 'Generator') { return 'str'; }
            return 'obj';
        }
        if ($t->isVec()) {
            $el = $t->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'veccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ) { return 'vec' . $this->elemObjFlavor($el); }
            if ($el !== null && $this->objUnionElem($el)) { return 'vecobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'vecstr'; }
            // A concrete scalar element (int/float/bool/null) has nothing to
            // drop → buffer-only, skipping the repr-bit read. Only an ERASED
            // element (unknown) reaches the repr path.
            if ($el !== null && self::isNonRcScalarKind($el->kind)) { return 'vecbuf'; }
            return 'vec';
        }
        if ($t->isAssoc()) {
            $el = $t->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'assoccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ) { return 'assoc' . $this->elemObjFlavor($el); }
            if ($el !== null && $this->objUnionElem($el)) { return 'assocobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'assocstr'; }
            if ($el !== null && self::isNonRcScalarKind($el->kind)) { return 'assocbuf'; }
            return 'assoc';
        }
        return '';
    }

    // ── the return convention ──────────────────────────────────────────────

    /** The release class of an ERASED array word — raw buffer or tagged cell,
     *  split by tag at every retain and drop. */
    public const ERASED_ARR = 'erasedarr';
    /** {@see ERASED_ARR} released buffer-only on its raw half — an array an
     *  unproven callee may keep with its element refs ({@see elementSharedArgs}). */
    public const ERASED_BUF = 'erasedbuf';

    /** What a `return` does to take the caller's +1 ({@see returnRetain}). */
    public const RET_NONE = 0;
    /** `__mir_fiber_current()` — a borrowed object handed back by a call. */
    public const RET_OBJ = 1;
    /** The typed retain, by the value's type or the declared return. */
    public const RET_TYPED = 2;
    /** The payload of a value about to be boxed into the returned cell. */
    public const RET_CELL_PAYLOAD = 3;
    /** A cell word, by its tag. */
    public const RET_CELL_TAG = 4;
    /** An erased array word: a raw buffer or a tagged cell, split by tag. */
    public const RET_ERASED = 5;

    /**
     * Does `$fn` DECLARE a bare `array` / `?array` return? Such a function —
     * free, method, static or closure — hands back +1 on EVERY path: a
     * borrowed value is retained (by its tag when its type is erased), every
     * arm of a conditional it returns is normalized. Decided on the
     * declaration, so every copy and every module agrees, and so a caller of
     * any such callee owns what it stores ({@see erasedArrayCall}). A generator
     * stashes its value in the frame, a by-ref return hands out an address.
     */
    public static function erasedArrayReturn(FunctionDef $fn): bool
    {
        return $fn->returnArrayHinted && !$fn->isGenerator && !$fn->returnsByRef && $fn->ffiSymbol === null;
    }

    /** A call whose erased result is +1 by {@see erasedArrayReturn}: the callee
     *  is resolved to its declaration (a free function, a method or static
     *  method of a known class, a known closure). */
    public function erasedArrayCall(Node $v): bool
    {
        if ($v->type->kind !== Type::KIND_UNKNOWN) { return false; }
        $k = $v->kind;
        $sym = '';
        if ($k === Node::KIND_CALL) {
            $sym = self::asCall($v)->function;
            if (\in_array(\ltrim($sym, '\\'), $this->ctx->borrowingBuiltins, true)) { return false; }
        } elseif ($k === Node::KIND_METHOD_CALL) {
            $mc = self::asMethodCall($v);
            return $this->methodReturnsErasedArray($mc->object->type->class ?? '', $mc->method);
        } elseif ($k === Node::KIND_STATIC_CALL) {
            $sc = self::asStaticCall($v);
            return $this->methodReturnsErasedArray($sc->class, $sc->method);
        } elseif ($k === Node::KIND_INVOKE) {
            $sym = self::asInvoke($v)->callee->type->class ?? '';
        }
        return $sym !== '' && isset($this->ctx->erasedArrayFns[$sym]);
    }

    /**
     * Does `$class::$method` hand back an erased array at +1 whatever body the
     * call dispatches to? The body the class resolves to declares it, or a
     * bodiless (interface / abstract) declaration up its hierarchy does — php's
     * variance makes every override declare `array` too. A name some
     * declaration returns BY REFERENCE is never claimed: that override hands
     * back an address.
     */
    private function methodReturnsErasedArray(string $class, string $method): bool
    {
        $lm = \strtolower($method);
        if ($class === '') { return false; }
        if (isset($this->ctx->byRefMethodNames[$lm]) && $this->familyReturnsByRef(\ltrim($class, '\\'), $method, $lm)) { return false; }
        $sym = $this->methodSymbol($class, $method);
        if ($sym !== '' && isset($this->ctx->erasedArrayFns[$sym])) { return true; }
        if (\count($this->ctx->bareArrayMethods) === 0) { return false; }
        /** @var string[] $stack */
        $stack = [\ltrim($class, '\\')];
        /** @var array<string, bool> $seen */
        $seen = [];
        while (\count($stack) > 0) {
            $c = (string)\array_pop($stack);
            if ($c === '' || isset($seen[$c])) { continue; }
            $seen[$c] = true;
            if (isset($this->ctx->bareArrayMethods[$c . '::' . $lm])) { return true; }
            foreach ($this->ctx->interfaceAncestors[$c] ?? [] as $ia) { $stack[] = \ltrim($ia, '\\'); }
            $cd = $this->ctx->classes[$c] ?? null;
            if ($cd === null) { continue; }
            $stack[] = \ltrim($cd->parent, '\\');
            foreach ($cd->interfaces as $in) { $stack[] = \ltrim($in, '\\'); }
        }
        return false;
    }

    /**
     * Does any body a call on a `$class` receiver can dispatch to declare
     * `$method` returning BY REFERENCE? The receiver's ancestors and
     * interfaces, and every class of this module that extends or implements
     * it (closed world): that override hands back an address, not an owned
     * array, so the call is not claimed. An unrelated class's `&m()` is not
     * in the family.
     */
    private function familyReturnsByRef(string $class, string $method, string $lm): bool
    {
        /** @var string[] $stack */
        $stack = [$class];
        /** @var array<string, bool> $up */
        $up = [];
        while (\count($stack) > 0) {
            $c = (string)\array_pop($stack);
            if ($c === '' || isset($up[$c])) { continue; }
            $up[$c] = true;
            if ($this->declaresByRef($c, $method, $lm)) { return true; }
            foreach ($this->ctx->interfaceAncestors[$c] ?? [] as $ia) { $stack[] = \ltrim($ia, '\\'); }
            $cd = $this->ctx->classes[$c] ?? null;
            if ($cd === null) { continue; }
            $stack[] = \ltrim($cd->parent, '\\');
            foreach ($cd->interfaces as $in) { $stack[] = \ltrim($in, '\\'); }
        }
        foreach ($this->ctx->classes as $cn => $cd) {
            if (isset($up[$cn]) || !$this->declaresByRef($cn, $method, $lm)) { continue; }
            if ($this->isSubtypeOf($cn, $class)) { return true; }
        }
        return false;
    }

    private function declaresByRef(string $c, string $method, string $lm): bool
    {
        if (isset($this->ctx->byRefBodiless[$c . '::' . $lm])) { return true; }
        return $this->ctx->returnsByRef[$c . '__' . $method] ?? false;
    }

    /** `$name` extends or implements `$base`, transitively. */
    private function isSubtypeOf(string $name, string $base): bool
    {
        /** @var string[] $stack */
        $stack = [$name];
        /** @var array<string, bool> $seen */
        $seen = [];
        while (\count($stack) > 0) {
            $c = (string)\array_pop($stack);
            if ($c === '' || isset($seen[$c])) { continue; }
            if ($c === $base) { return true; }
            $seen[$c] = true;
            foreach ($this->ctx->interfaceAncestors[$c] ?? [] as $ia) { $stack[] = \ltrim($ia, '\\'); }
            $cd = $this->ctx->classes[$c] ?? null;
            if ($cd === null) { continue; }
            $stack[] = \ltrim($cd->parent, '\\');
            foreach ($cd->interfaces as $in) { $stack[] = \ltrim($in, '\\'); }
        }
        return false;
    }

    /** A return value that crosses the uniform closure ABI as a tagged cell. */
    public static function cellBoxableKind(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_STRING
            || $k === Type::KIND_NULL || $k === Type::KIND_CELL;
    }

    /** A CELL-element array slot receiving a CONCRETE-element array of the same
     *  shape — the return rebuilds it, boxing each element. */
    public static function needsCellify(?Type $slot, ?Type $val): bool
    {
        if ($slot === null || $val === null) { return false; }
        if (!$slot->isArray() || !$val->isArray()) { return false; }
        if ($slot->isAssoc() !== $val->isAssoc()) { return false; }
        $se = $slot->element;
        $ve = $val->element;
        if ($se === null || $ve === null) { return false; }
        if ($se->kind !== Type::KIND_CELL) { return false; }
        return $ve->kind !== Type::KIND_CELL && $ve->kind !== Type::KIND_UNKNOWN;
    }

    /** Is the returned conditional `$v` normalized to +1 in each arm — covered
     *  by the contract, or handed back by an erased-array return? */
    public function returnCondNormalized(Node $v, bool $erasedArray): bool
    {
        if (!CondOwn::isConditional($v)) { return false; }
        return $erasedArray || $this->condOwnedTemp($v);
    }

    /** The type a returned value's ownership is judged by: its own, or — erased
     *  or boxed — the declared return, which is what the caller assumes. */
    public function returnOwnershipType(Node $v, ?Type $retType): Type
    {
        $tk = $v->type->kind;
        if ($tk !== Type::KIND_UNKNOWN && $tk !== Type::KIND_CELL) { return $v->type; }
        return $retType ?? $v->type;
    }

    /**
     * Is a returned obj / array / string / closure value a BORROW that needs
     * the caller's +1? An owned producer (a call, `new`, `clone`, a literal, a
     * concat, a minting cast, a normalized conditional) and a MOVED local are
     * already +1.
     */
    public function returnBorrowsObj(Node $v, ?Type $retType, bool $moved, bool $erasedArray): bool
    {
        $t = $this->returnOwnershipType($v, $retType);
        $tk = $t->kind;
        $isArr = $t->isVec() || $t->isAssoc();
        if ($tk !== Type::KIND_OBJ && !$isArr && $tk !== Type::KIND_STRING && $tk !== Type::KIND_CLOSURE) { return false; }
        if ($tk === Type::KIND_OBJ) {
            $cls = $t->class ?? '';
            if ($cls !== '' && isset($this->ctx->classes[$cls]) && $this->ctx->classes[$cls]->isStruct) { return false; }
        }
        $k = $v->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE
            || BitOp::mintsFresh($v)) {
            return false;
        }
        if ($this->returnCondNormalized($v, $erasedArray)) { return false; }
        if ($tk === Type::KIND_OBJ && ($k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE)) { return false; }
        if ($tk === Type::KIND_OBJ && $v instanceof Cast && $v->target === 'object') { return false; }
        if ($isArr && ($k === Node::KIND_ARRAY_LIT || $k === Node::KIND_SPREAD)) { return false; }
        if ($tk === Type::KIND_STRING && ($k === Node::KIND_CONCAT || $k === Node::KIND_STRING_CONST)) { return false; }
        // `(string)$int` / `(string)$float` mint a fresh rc=1 buffer.
        if ($tk === Type::KIND_STRING && $k === Node::KIND_CAST) {
            $ok = self::asCast($v)->operand->type->kind;
            if ($ok === Type::KIND_INT || $ok === Type::KIND_FLOAT || $ok === Type::KIND_CELL) { return false; }
        }
        if ($k === Node::KIND_LOAD_LOCAL && $moved) { return false; }
        return true;
    }

    /**
     * Is a returned CELL / erased value a BORROW — the producer test of
     * {@see returnBorrowsObj} minus the type test an erased word cannot make?
     * In an erased-array return, an erased CALL result is +1 only when its
     * callee is known to return so ({@see erasedArrayCall}); any other is
     * retained (a leak at worst, never a +0 handed on as +1).
     */
    public function returnBorrowsCell(Node $v, bool $moved, bool $erasedArray): bool
    {
        $k = $v->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) {
            if ($erasedArray && $v->type->kind === Type::KIND_UNKNOWN) { return !$this->erasedArrayCall($v); }
            return false;
        }
        if ($k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE
            || $k === Node::KIND_ARRAY_LIT || $k === Node::KIND_SPREAD
            || $k === Node::KIND_CONCAT || $k === Node::KIND_STRING_CONST
            || BitOp::mintsFresh($v)) {
            return false;
        }
        if ($v instanceof Cast && $v->target === 'object') { return false; }
        if ($k === Node::KIND_LOAD_LOCAL && $moved) { return false; }
        if ($this->returnCondNormalized($v, $erasedArray)) { return false; }
        // A numeric op's cell is minted by its helper ({@see tempCellOwned}).
        if (($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL)
            && $v->type->isNumericCell()) { return false; }
        return true;
    }

    /** The one call that is not a +1: `__mir_fiber_current()` reads the running
     *  fiber out of a global its owner holds. */
    public static function callHandsBorrow(Node $v): bool
    {
        return $v instanceof Call && AliasOwn::builtinHandsBorrow($v->function);
    }

    /**
     * THE return-retain decision: which +1 `return <$v>` takes, in a function
     * returning `$retType` (`$closureAbi`: the uniform cell ABI of a closure /
     * trampoline; `$erasedArray`: {@see erasedArrayReturn}; `$moved`: `$v` is a
     * local the flow owns, handed on). {@see Passes\EmitLlvmModule::emitReturn}
     * executes it and {@see returnArmLocals} reads it — one answer.
     */
    public function returnRetain(Node $v, ?Type $retType, bool $closureAbi, bool $erasedArray, bool $moved): int
    {
        $vk = $v->type->kind;
        $retCell = $retType !== null && $retType->kind === Type::KIND_CELL;
        if ($closureAbi && self::cellBoxableKind($v->type)) {
            if ($this->returnBorrowsObj($v, $retType, $moved, $erasedArray)) { return self::RET_CELL_PAYLOAD; }
            if ($vk === Type::KIND_CELL && $this->returnBorrowsCell($v, $moved, $erasedArray)) { return self::RET_CELL_TAG; }
            return self::RET_NONE;
        }
        if ($closureAbi && $vk === Type::KIND_UNKNOWN) {
            return $erasedArray && $this->returnBorrowsCell($v, $moved, $erasedArray) ? self::RET_ERASED : self::RET_NONE;
        }
        if (self::needsCellify($retType, $v->type)) { return self::RET_NONE; }
        if ($retCell && $vk !== Type::KIND_CELL) {
            if ($this->returnBorrowsObj($v, $retType, $moved, $erasedArray)) { return self::RET_CELL_PAYLOAD; }
            if ($vk === Type::KIND_UNKNOWN && $this->returnBorrowsCell($v, $moved, $erasedArray)) { return self::RET_CELL_TAG; }
            return self::RET_NONE;
        }
        if ($retCell && $vk === Type::KIND_CELL && $this->returnBorrowsCell($v, $moved, $erasedArray)) {
            return self::RET_CELL_TAG;
        }
        if (self::callHandsBorrow($v)) { return self::RET_OBJ; }
        if ($this->returnBorrowsObj($v, $retType, $moved, $erasedArray)) { return self::RET_TYPED; }
        if ($erasedArray && $this->returnBorrowsCell($v, $moved, $erasedArray)) { return self::RET_ERASED; }
        return self::RET_NONE;
    }

    /**
     * The locals a `return <$v>` must NOT drop unconditionally: the arms of a
     * conditional the return takes no +1 on ({@see returnRetain} answers NONE
     * and the arms are not normalized), so the arm that ran is the word handed
     * back as it stands. The drop of each waits for the returned word
     * (identity at run time); every other owned local drops. `$noRetain` is a
     * return that takes no +1 at all (a generator's).
     *
     * @return array<string, bool>
     */
    public function returnArmLocals(Node $v, ?Type $retType, bool $closureAbi, bool $erasedArray, bool $noRetain): array
    {
        if (!CondOwn::isConditional($v)) { return []; }
        if ($noRetain) {
            if ($this->condOwnedTemp($v)) { return []; }
        } else {
            if ($this->returnCondNormalized($v, $erasedArray)) { return []; }
            if ($this->returnRetain($v, $retType, $closureAbi, $erasedArray, false) !== self::RET_NONE) { return []; }
        }
        /** @var array<string, bool> $out */
        $out = [];
        $this->armLocals($v, $out);
        return $out;
    }

    /** @param array<string, bool> $out */
    private function armLocals(Node $v, array &$out): void
    {
        if ($v->kind === Node::KIND_LOAD_LOCAL) {
            $out[self::asLoadLocal($v)->name] = true;
            return;
        }
        if (!CondOwn::isConditional($v) || $this->condOwnedTemp($v)) { return; }
        foreach (CondOwn::arms($v) as $arm) { $this->armLocals($arm, $out); }
    }

    // ── container stores ───────────────────────────────────────────────────

    /**
     * The element type a raw-repr value stored by `$se` is retained by, when its
     * own type names no rc kind: the destination's concrete element. Null for a
     * cell value (its co-ownership is the boxing path's) and for an erased
     * destination.
     */
    public static function storeRetainFallback(StoreElement $se): ?Type
    {
        if ($se->value->type->kind === Type::KIND_CELL) { return null; }
        $at = $se->array->type;
        if ($at->kind === Type::KIND_CELL || $at->kind === Type::KIND_UNKNOWN) { return null; }
        $el = $at->element;
        if ($el !== null && ($el->kind === Type::KIND_CELL || $el->kind === Type::KIND_UNKNOWN)) {
            return null;
        }
        return $el;
    }

    /**
     * The element type a CELL value is UNBOXED to before it lands in a
     * CONCRETE-element array — the per-element de-cellify: the raw payload is
     * then retained per THIS type. Null for a non-cell value or a cell / unknown
     * destination element (which stores the value boxed).
     */
    public static function storeElemDeCellifyType(StoreElement $se): ?Type
    {
        if ($se->value->type->kind !== Type::KIND_CELL) { return null; }
        $at = $se->array->type;
        if ($at->kind === Type::KIND_CELL || $at->kind === Type::KIND_UNKNOWN) { return null; }
        $el = $at->element;
        if ($el === null) { return null; }
        $ek = $el->kind;
        if ($ek === Type::KIND_CELL || $ek === Type::KIND_UNKNOWN) { return null; }
        return $el;
    }

    /** Both ends of this element store are erased: the value carries a cell /
     *  unknown and the destination's element channel names no type either. Such
     *  a store copies a WORD whose ownership nobody static can speak for, so the
     *  emitter retains it by its runtime tag. */
    public static function erasedElemCopy(StoreElement $se): bool
    {
        $vk = $se->value->type->kind;
        if ($vk !== Type::KIND_CELL && $vk !== Type::KIND_UNKNOWN) { return false; }
        $at = $se->array->type;
        if ($at->kind === Type::KIND_CELL || $at->kind === Type::KIND_UNKNOWN) { return true; }
        $el = $at->element;
        return $el === null || $el->kind === Type::KIND_CELL || $el->kind === Type::KIND_UNKNOWN;
    }

    /**
     * Does a StoreElement NaN-box its value into the slot? A cell BASE (a
     * `mixed` property / param holding the array) or a cell ELEMENT type both
     * store boxed, and that path co-owns the payload through
     * {@see Passes\EmitLlvm::retainCellPayload} instead of the typed retain.
     *
     * ⚠ The ONE owner of that question: the emitter reads it to pick the store
     * arm, and {@see containerStoreRetains} to pick the matching retain
     * predicate for {@see Passes\OwnershipFlow}'s move. Two copies drift, and a
     * drift here is a leak or a double free.
     *
     * ⚠ KNOWN GAP, deliberately NOT widened to KIND_UNKNOWN: the container's
     * repr nibble is fixed at allocation, so boxed values in a raw-repr vec make
     * the release path free tagged words (tests/aot/cases/array_erased_elem_repr_gap.php;
     * the parked element-repr epic).
     */
    public static function storeElemBoxesValue(StoreElement $se): bool
    {
        $at = $se->array->type;
        if ($at->kind === Type::KIND_CELL) { return true; }
        $et = $at->element;
        if ($et !== null && $et->kind === Type::KIND_CELL) { return true; }
        if ($se->value->type->kind === Type::KIND_CELL && ($et === null || $et->kind === Type::KIND_UNKNOWN)) { return true; }
        if ($se->value instanceof Call) {
            $fn = $se->value->function;
            $p = \strrpos($fn, \chr(92));
            $bare = $p === false ? $fn : \substr($fn, $p + 1);
            if ($bare === 'key' || $bare === 'current' || $bare === 'pos') { return true; }
        }
        return false;
    }

    /** As {@see storeElemBoxesValue} for an array LITERAL — its boxed values. */
    public static function litBoxesValues(ArrayLit $al): bool
    {
        $el = $al->type->element;
        return $el !== null && $el->kind === Type::KIND_CELL;
    }

    /**
     * The ClassDef whose layout an `$obj->prop` access resolves against, or
     * null: the static class when it declares the slot, else the first subclass
     * that does.
     */
    public function propHolder(Node $objExpr, string $prop): ?ClassDef
    {
        $cls = $objExpr->type->class ?? '';
        if ($cls === '' || !isset($this->ctx->classes[$cls])) { return null; }
        if ($this->ctx->classes[$cls]->propertyOffset($prop) >= 0) {
            return $this->ctx->classes[$cls];
        }
        return $this->subclassPropHolder($cls, $prop);
    }

    /**
     * The one subclass of `$base` that stands for every subclass declaring
     * `$prop` — or null when none does, or when their slots DISAGREE on
     * representation. Siblings may declare one name unrelated ways
     * (`IntLiteral::$value` int, `Spread::$value` Expr); then no declared type
     * is a fact, and the first declarer found was a silent wrong read, write and
     * ownership decision. A null here sends every consumer to the class_id
     * dispatch that boxes each holder's slot by its own type. Agreeing slots may
     * still sit at different OFFSETS; {@see EmitLlvmObjects::subclassPropOffset}
     * answers that separately.
     */
    public function subclassPropHolder(string $base, string $prop): ?ClassDef
    {
        $found = null;
        $ts = '';
        $sameType = true;
        $allObj = true;
        foreach ($this->ctx->classes as $cd) {
            if ($cd->name === $base) { continue; }
            if (!$this->classExtends($cd->name, $base)) { continue; }
            $o = $cd->propertyOffset($prop);
            if ($o < 0) { continue; }
            $pt = $cd->propertyTypes[$prop] ?? null;
            $t = $pt !== null ? $pt->toString() : '';
            if ($pt === null || $pt->kind !== Type::KIND_OBJ || isset($this->ctx->enums[$pt->class ?? ''])) {
                $allObj = false;
            }
            if ($found === null) { $found = $cd; $ts = $t; continue; }
            if ($t !== $ts) { $sameType = false; }
        }
        // The same join InferTypes types the read with (subclassPropType): object
        // slots share the raw-pointer representation and join to their union;
        // anything else must be one type, or the read is a cell that only the
        // per-holder dispatch can box.
        return ($sameType || $allObj) ? $found : null;
    }

    /** Whether class `$name` transitively extends `$base`. */
    public function classExtends(string $name, string $base): bool
    {
        $cur = $name;
        while ($cur !== '' && isset($this->ctx->classes[$cur])) {
            $p = $this->ctx->classes[$cur]->parent;
            if ($p === $base) { return true; }
            $cur = $p;
        }
        return false;
    }

    /**
     * The destination type a property store's OWNERSHIP is decided by — the
     * declared property type, except that an array-hinted slot whose hint
     * erased to KIND_UNKNOWN answers `vec[unknown]`, so it still reads as
     * rc-managed. The ONE owner, read by the emitter's retain and by
     * {@see containerStoreRetains}.
     */
    public function propStoreRetainType(StoreProperty $n): ?Type
    {
        $pcls = $n->object->type->class ?? '';
        $propType = ($pcls !== '' && isset($this->ctx->classes[$pcls]))
            ? ($this->ctx->classes[$pcls]->propertyTypes[$n->property] ?? null)
            : null;
        if ($propType === null || !$propType->isArray()) {
            $cd = $this->propHolder($n->object, $n->property);
            if ($cd !== null && ($cd->propertyArrayHinted[$n->property] ?? false)) {
                return Type::vec(Type::unknown());
            }
        }
        return $propType;
    }

    /**
     * Does a container store of `$valueNode` take its own +1 — the mirror of
     * the emitter's typed retain for a borrowed (LoadLocal) value? True iff the
     * value's effective type (its own, or the container's `$fallback` when
     * erased) is a non-struct, non-enum rc kind; a boxed CELL value and a
     * closure always co-own. When false the container holds the word WITHOUT a
     * count, so the local's reference is what keeps it: the store MOVES it
     * ({@see Passes\OwnershipFlow}).
     */
    public function containerStoreRetains(Node $valueNode, ?Type $fallback, bool $boxed = false): bool
    {
        $tk = $valueNode->type->kind;
        $cls = $valueNode->type->class ?? '';
        // A boxed store co-owns a cell or an erased word by retaining through its
        // tag ({@see Passes\EmitLlvm::retainCellPayload} probe-boxes a raw one).
        if ($boxed && ($tk === Type::KIND_CELL || $tk === Type::KIND_UNKNOWN)) { return true; }
        if ($tk === Type::KIND_CLOSURE || ($tk === Type::KIND_OBJ && self::isClosureClass($cls))) { return true; }
        if (($tk === Type::KIND_UNKNOWN || $tk === Type::KIND_CELL) && $fallback !== null) {
            $fk = $fallback->kind;
            if ($fk === Type::KIND_OBJ || $fk === Type::KIND_ARRAY || $fk === Type::KIND_STRING) {
                $tk = $fk;
                $cls = $fallback->class ?? '';
            }
        }
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY && $tk !== Type::KIND_STRING) { return false; }
        if ($tk === Type::KIND_OBJ) {
            if ($cls !== '' && isset($this->ctx->classes[$cls]) && $this->ctx->classes[$cls]->isStruct) { return false; }
            if (self::isClosureClass($cls)) { return false; }
            if ($this->isEnumClass($cls)) { return false; }
        }
        return true;
    }

    /**
     * The local values a container store hands over WITHOUT a retain — an
     * element / property store or an array literal whose value is a bare
     * local read the container does not co-own ({@see containerStoreRetains}).
     *
     * @return LoadLocal[]
     */
    public function containerMoves(Node $n): array
    {
        $k = $n->kind;
        /** @var LoadLocal[] $out */
        $out = [];
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = self::asStoreElement($n);
            $v = $se->value;
            if ($v->kind === Node::KIND_LOAD_LOCAL) {
                $boxed = self::storeElemBoxesValue($se);
                $fallback = self::storeElemDeCellifyType($se) ?? self::storeRetainFallback($se);
                $tagRetain = !$boxed && $fallback === null && self::erasedElemCopy($se);
                if (!$tagRetain && !$this->containerStoreRetains($v, $fallback, $boxed)) {
                    $out[] = self::asLoadLocal($v);
                }
            }
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            $sp = self::asStoreProperty($n);
            $v = $sp->value;
            $pcls = $sp->object->type->class ?? '';
            $declared = ($pcls !== '' && isset($this->ctx->classes[$pcls]))
                ? ($this->ctx->classes[$pcls]->propertyTypes[$sp->property] ?? null) : null;
            $boxed = $declared !== null && $declared->kind === Type::KIND_CELL;
            if ($v->kind === Node::KIND_LOAD_LOCAL
                && !$this->containerStoreRetains($v, $boxed ? $declared : $this->propStoreRetainType($sp), $boxed)) {
                $out[] = self::asLoadLocal($v);
            }
        } elseif ($k === Node::KIND_ARRAY_LIT) {
            // A literal element retains by the VALUE's own type (no element
            // fallback), or by its tag when the literal boxes.
            $al = self::asArrayLit($n);
            $boxed = self::litBoxesValues($al);
            foreach ($al->elements as $el) {
                $v = $el->value;
                if ($v->kind === Node::KIND_LOAD_LOCAL && !$this->containerStoreRetains($v, null, $boxed)) {
                    $out[] = self::asLoadLocal($v);
                }
            }
        }
        return $out;
    }

    // ── arguments a callee co-owns only as a buffer ─────────────────────────

    /**
     * The array locals an object-producing call hands a callee whose element
     * discipline is not PROVEN: a `new`, or an obj-typed call / method / static
     * call / invoke, receiving a vec / assoc of objects, strings or arrays at a
     * position that is not a by-value parameter of a known callee. Such a callee
     * may keep the buffer with the element refs it already holds, so the local's
     * own release gives back the buffer only (the parser `$args` UAF).
     *
     * A by-value array parameter of a KNOWN callee co-owns at element depth, so
     * the caller keeps its full release — `Lexer::tokenize()` → `new
     * Parser($toks)` leaked one ref per token until that was narrowed.
     *
     * @return string[]
     */
    public function elementSharedArgs(Node $n): array
    {
        $k = $n->kind;
        $sym = '';
        $args = [];
        if ($k === Node::KIND_NEW_OBJ) {
            $no = self::asNewObj($n);
            $sym = $this->methodSymbol($no->class, '__construct');
            $args = $no->args;
        } elseif ($n->type->kind === Type::KIND_OBJ) {
            if ($k === Node::KIND_CALL) {
                $c = self::asCall($n);
                $sym = $c->function;
                $args = $c->args;
            } elseif ($k === Node::KIND_METHOD_CALL) {
                $mc = self::asMethodCall($n);
                $sym = $this->methodSymbol($mc->object->type->class ?? '', $mc->method);
                $args = $mc->args;
            } elseif ($k === Node::KIND_STATIC_CALL) {
                $sc = self::asStaticCall($n);
                $sym = $this->methodSymbol($sc->class, $sc->method);
                $args = $sc->args;
            } elseif ($k === Node::KIND_INVOKE) {
                $args = self::asInvoke($n)->args;
            } else {
                return [];
            }
        } else {
            return [];
        }
        $refs = $sym !== '' ? ($this->ctx->paramByRef[$sym] ?? null) : null;
        $out = [];
        $pos = 0;
        foreach ($args as $a) {
            $i = $pos;
            $pos = $pos + 1;
            if ($a->kind !== Node::KIND_LOAD_LOCAL) { continue; }
            $t = $a->type;
            if (!$t->isVec() && !$t->isAssoc()) { continue; }
            $el = $t->element;
            if ($el === null
                || ($el->kind !== Type::KIND_OBJ && $el->kind !== Type::KIND_STRING
                    && $el->kind !== Type::KIND_ARRAY)) {
                continue;
            }
            if ($refs !== null && $i < \count($refs) && !$refs[$i]) { continue; }
            $out[] = self::asLoadLocal($a)->name;
        }
        return $out;
    }

    /** The emitted symbol of `$class::$method` as a direct call names it, or ''
     *  when the class declares no body for it. */
    private function methodSymbol(string $class, string $method): string
    {
        if ($class === '' || $method === '' || !isset($this->ctx->classes[$class])) { return ''; }
        $c = $class;
        $owner = '';
        while ($c !== '') {
            $cd = $this->ctx->classes[$c] ?? null;
            if ($cd === null) { return ''; }
            if (isset($cd->methodNames[$method])) { $owner = $c; break; }
            $c = $cd->parent;
        }
        if ($owner === '') { return ''; }
        $base = $owner . '__' . $method;
        if ($class !== $owner && isset($this->ctx->paramByRef[$base . '__lsb' . $class])) {
            return $base . '__lsb' . $class;
        }
        return $base;
    }

    private static function asStoreElement(Node $n): StoreElement { return $n; }
    private static function asStoreProperty(Node $n): StoreProperty { return $n; }
    private static function asArrayLit(Node $n): ArrayLit { return $n; }
    private static function asLoadLocal(Node $n): LoadLocal { return $n; }
    private static function asNewObj(Node $n): NewObj { return $n; }
    private static function asCall(Node $n): Call { return $n; }
    private static function asMethodCall(Node $n): MethodCall_ { return $n; }
    private static function asStaticCall(Node $n): StaticCall_ { return $n; }
    private static function asInvoke(Node $n): Invoke_ { return $n; }
    private static function asCast(Node $n): Cast { return $n; }
}
