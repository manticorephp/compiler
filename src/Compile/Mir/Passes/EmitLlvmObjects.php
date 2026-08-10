<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Node;
use Compile\Mir\Type;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\ClassDef;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;

/**
 * Object / member-access emitters extracted from {@see EmitLlvm}: new, property
 * & dynamic-property access/store, static props/calls, virtual dispatch, method
 * calls, refs (alias/bind), isset/unset, class-name, and the property-offset /
 * subclass / extends resolution helpers. Pure $this-bound; behaviour unchanged.
 * Split out 2026-06-08.
 */
trait EmitLlvmObjects
{
    // ── Objects ────────────────────────────────────────────────
    //
    // Instance layout:
    //   offset 0  : ptr  class descriptor ({i64 class_id, ptr drop_fn})
    //   offset 8  : i64  refcount
    //   offset 16+: properties (8 bytes each, decl order)
    //
    // class_id and drop are read THROUGH the descriptor so both compose
    // across separately-linked objects. `new Foo(args)` → malloc(size) +
    // write header + zero props + call `Foo____construct(i64 thisptr, args…)`.

    /**
     * Allocate an object and initialise its header and property slots. Shared by
     * `new C(…)` and `new $cls(…)`, which differ only in how the class is chosen.
     * Leaves the object POINTER in {@see $lastValue}.
     */
    private function emitObjAllocInit(?\Compile\Mir\ClassDef $cd): string
    {
        $size = $cd === null ? 16 : $cd->instanceSize();
        $isStruct = $cd !== null && $cd->isStruct;
        // Header slot count before properties: 2 (class_id + rc) for a
        // normal object, 0 for a `#[Struct]` value type.
        $hdr = $isStruct ? 0 : 2;
        $obj = $this->ssa->allocReg();
        $out = '  ' . $obj . ' = call ptr @__mir_alloc_tagged(i64 ' . (string)$size . ")\n";
        if ($isStruct) {
            // A struct has NO header — property slot 0 sits at +0 and there is
            // no rc at +8. Leaving the allocator's RC_TAG_MAGIC at ptr-8 would
            // advertise a header it does not have, and anything that self-routes
            // on that tag (cell_drop, the raw `instanceof` carrier check) reads a
            // property as a descriptor. Restamp with the struct sentinel.
            $stp = $this->ssa->allocReg();
            $out .= '  ' . $stp . ' = getelementptr inbounds i8, ptr ' . $obj . ", i64 -8\n";
            $out .= '  store i64 ' . (string)\Compile\MemoryAbi::STRUCT_TAG_MAGIC
                  . ', ptr ' . $stp . "\n";
        }
        if (!$isStruct) {
            // header[0] = class descriptor ptr ({class_id, drop_fn}); class_id
            // and drop are read THROUGH it so drops compose across objects.
            $out .= '  store i64 ' . $this->lib->descSlotValue($cd) . ', ptr ' . $obj . "\n";
            // header[1] = refcount = 1
            $rcGep = $this->ssa->allocReg();
            $out .= '  ' . $rcGep . ' = getelementptr inbounds i64, ptr ' . $obj . ", i64 1\n";
            $out .= '  store i64 1, ptr ' . $rcGep . "\n";
        }
        // zero each property slot. A CELL (mixed / nullable-scalar `?int`)
        // property must default to a NaN-boxed NULL, not raw 0 — else a read /
        // var_dump dispatches on tag 0 (an invalid cell) and faults.
        if ($cd !== null) {
            foreach ($cd->propertyNames as $pname) {
                // Byte offset from the class's own layout, and a store of exactly
                // the slot's WIDTH. This used to stride `i64` units — one word per
                // property — which wrote four words into a class whose four narrow
                // slots occupy four BYTES, corrupting the heap past the allocation.
                // The layout has one owner; nothing may re-derive it.
                $pGep = $this->ssa->allocReg();
                $out .= '  ' . $pGep . ' = getelementptr inbounds i8, ptr '
                      . $obj . ', i64 ' . (string)$cd->propertyOffset($pname) . "\n";
                $ptype = $cd->propertyTypes[$pname] ?? null;
                // A self-describing cell prop (scalar-nullable OR a `mixed` prop
                // only ever holding scalars) defaults to a boxed NULL so a read
                // dispatches by tag, not on a raw 0. A cell-array backing slot
                // (`$__s`, ever stored an array) stays raw 0. A narrow slot can
                // never be a cell — a NaN-boxed tag does not fit in a byte — so it
                // simply zeroes.
                $w = $cd->propertyWidth($pname);
                if ($w !== 8) {
                    $out .= '  store i' . (string)($w * 8) . ' 0, ptr ' . $pGep . "\n";
                    continue;
                }
                $initVal = $this->cellPropBoxed($ptype, $cd->name, $pname)
                    ? '-3659174697238528' : '0';
                $out .= '  store i64 ' . $initVal . ', ptr ' . $pGep . "\n";
            }
            // Dynamic-property bag starts null (assoc_set allocates lazily).
            if ($cd->usesBag()) {
                $bGep = $this->ssa->allocReg();
                $out .= '  ' . $bGep . ' = getelementptr inbounds i8, ptr '
                      . $obj . ', i64 ' . (string)$cd->bagOffset() . "\n";
                $out .= '  store i64 0, ptr ' . $bGep . "\n";
            }
        }
        $this->lastValue = $obj;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `new $cls(args)` — the class is named by a value, so the choice is made at
     * runtime: compare the name against every class whose constructor takes this
     * many arguments, and construct the one that matches.
     *
     * The arguments are evaluated ONCE, before the comparison chain. An arm may
     * box or coerce its own copy of a register, but the argument EXPRESSIONS must
     * not run once per candidate class — that would repeat their side effects.
     *
     * No match yields a null object, which is PHP's "Class not found" — the read
     * that follows faults rather than silently constructing the wrong thing.
     */
    private function emitNewDynObj(\Compile\Mir\NewDynObj $n): string
    {
        $out = $this->emitNode($n->classExpr);
        $out .= $this->coerceToI64();
        $nameI = $this->lastValue;
        $namePtr = $this->ssa->allocReg();
        $out .= '  ' . $namePtr . ' = inttoptr i64 ' . $nameI . " to ptr\n";
        $this->rt->needsStrcmp = true;

        $argRegs = [];
        $argKinds = [];
        $fixedArgs = [];
        // `new $cls(...$arr)`: the pack's length is a run-time property, so each
        // candidate ctor arm expands it against its OWN params. Emitting the
        // Spread_ node as an ordinary argument produced no value at all, so the
        // ctor was handed the stale lastValue — the CLASS NAME — as its first
        // parameter (`new $cls(...['C'])` constructed with 'R').
        $spreadArr = '';
        $spreadElem = null;
        foreach ($n->args as $a) {
            if ($a->kind === Node::KIND_SPREAD) {
                $out .= $this->emitNode($a->operand);
                $out .= $this->coerceToPtr();
                $spreadArr = $this->lastValue;
                $spreadElem = $a->operand->type->element ?? null;
                continue;
            }
            $out .= $this->emitNode($a);
            $argRegs[] = $this->lastValue;
            $argKinds[] = $this->lastValueType;
            $fixedArgs[] = $a;
        }
        $argc = \count($fixedArgs);

        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca i64\n";
        $endL = $this->ssa->allocLabel('newdyn.end');

        foreach ($this->classes as $cd) {
            if ($cd->isStruct) { continue; }
            $ctorClass = $this->resolveMethodClass($cd->name, '__construct');
            $ptypes = [];
            $tmask = [];
            $ahmask = [];
            $need = 0;
            if ($ctorClass !== '') {
                $ptypes = $this->sigs->paramTypes[$ctorClass . '____construct'] ?? [];
                $ahmask = $this->sigs->arrayHintedParams[$ctorClass . '____construct'] ?? [];
                $tmask = $this->sigs->taggedParams[$ctorClass . '____construct'] ?? [];
                // Param 0 is the implicit `$this`.
                $need = \count($ptypes) - 1;
                if ($need < 0) { $need = 0; }
            }
            // Accept a defaulted constructor (argc <= total): the trailing
            // optional params are default-filled below. Exact-arity sites are
            // unchanged (no padding). Matches lowerNewDynExpr's relaxed set.
            if ($argc > $need) { continue; }

            $hitL = $this->ssa->allocLabel('newdyn.hit');
            $nextL = $this->ssa->allocLabel('newdyn.next');
            $lit = $this->strLitId($this->pool->intern($cd->name));
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = call i32 @strcmp(ptr ' . $namePtr . ', ptr ' . $lit . ")\n";
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i32 ' . $cmp . ", 0\n";
            $out .= '  br i1 ' . $eq . ', label %' . $hitL . ', label %' . $nextL . "\n";
            $out .= $hitL . ":\n";
            $out .= $this->emitObjAllocInit($cd);
            $objPtr = $this->lastValue;
            $objInt = $this->ssa->allocReg();
            $out .= '  ' . $objInt . ' = ptrtoint ptr ' . $objPtr . " to i64\n";
            if ($ctorClass !== '') {
                $argList = 'i64 ' . $objInt;
                $ai = 0;
                foreach ($fixedArgs as $a) {
                    $this->lastValue = $argRegs[$ai];
                    $this->lastValueType = $argKinds[$ai];
                    if (($tmask[$ai + 1] ?? false) && $a->type->kind !== Type::KIND_CELL) {
                        $out .= $this->boxToCell($a->type);
                    } else {
                        $out .= $this->coerceToI64();
                        $out .= $this->unboxCellArg($a, $ptypes, $ai + 1, $ahmask);
                    }
                    $argList .= ', i64 ' . $this->lastValue;
                    $ai = $ai + 1;
                }
                if ($spreadArr !== '') {
                    // The pack covers every param past the fixed prefix; each
                    // defaulted one it does not reach falls back to its default.
                    [$sir, $sregs] = $this->emitSpreadFill(
                        $spreadArr, $argc + 1, $ptypes, $tmask, $spreadElem,
                        $ctorClass . '____construct');
                    $out .= $sir;
                    foreach ($sregs as $rg) { $argList .= ', i64 ' . $rg; }
                } else {
                    // Default-fill the trailing optional ctor params (param 0 is
                    // `$this`, provided args cover [1 .. argc]).
                    $out .= $this->emitDefaultArgPad($ctorClass . '____construct', $argc + 1, true);
                    $argList .= $this->lastPadArgs;
                }
                // func_get_args() channel: what the SOURCE wrote, before the pad
                // above widened the list. Kept across the merge with the spread
                // arm — the two are independent, and dropping this makes a ctor
                // reading func_get_arg() see the padded arity instead.
                $out .= $this->faPush($this->lsbTarget($ctorClass, '__construct', $cd->name),
                    $n->srcArgc, $n->args, 1);
                $cr = $this->ssa->allocReg();
                $out .= '  ' . $cr . ' = call i64 @manticore_'
                      . $this->mangle($this->lsbTarget($ctorClass, '__construct', $cd->name))
                      . '(' . $argList . ")\n";
            }
            // The MIR node is typed CELL (`new_dyn %n() : cell`), so the value
            // has to BE one — this stored the bare `ptrtoint` instead, and every
            // consumer that checks the tag rather than masking it saw a
            // non-object: `get_class(new $cls())` answered '' because its tag!=8
            // arm is the default. Property reads hid it, because cellToPtr's
            // 48-bit mask leaves a raw pointer unchanged.
            //
            // Boxed HERE and not at the join: the miss path below stores 0 for a
            // name no class matched, and a 0 payload under an object tag would
            // send get_class's class_id load to address 0. Left raw, that 0 still
            // fails the tag check and falls to the '' arm, which is the behaviour
            // php's "Class not found" case degrades to here.
            if ($n->type->kind === Type::KIND_CELL) {
                $this->rt->needsTagged = true;
                $bx = $this->ssa->allocReg();
                $out .= '  ' . $bx . ' = call i64 @__manticore_box_object(ptr ' . $objPtr . ")\n";
                $out .= '  store i64 ' . $bx . ', ptr ' . $slot . "\n";
            } else {
                $out .= '  store i64 ' . $objInt . ', ptr ' . $slot . "\n";
            }
            $out .= '  br label %' . $endL . "\n";
            $out .= $nextL . ":\n";
        }
        $out .= '  store i64 0, ptr ' . $slot . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = load i64, ptr ' . $slot . "\n";
        $this->lastValue = $res;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitNewObj(\Compile\Mir\NewObj $n): string
    {
        $cd = $this->classes[$n->class] ?? null;
        $out = $this->emitObjAllocInit($cd);
        $obj = $this->lastValue;
        // ctor call — resolve through the parent chain (a subclass
        // with no ctor inherits its parent's).
        // `__mc_new_uninit('C')`: no CONSTRUCTOR BODY runs, but the declared
        // property defaults do — that is exactly what php's unserialize does, so
        // a property the stream omits keeps its default rather than reading 0.
        // The defaults live in `C____mc_defaults`, emitted beside the ctor by
        // LowerClasses when the program unserialises at all.
        if ($n->bare) {
            $defSym = $n->class . '____mc_defaults';
            if (isset($this->sigs->paramTypes[$defSym])) {
                $oi = $this->ssa->allocReg();
                $out .= '  ' . $oi . ' = ptrtoint ptr ' . $obj . " to i64\n";
                $dr = $this->ssa->allocReg();
                $out .= '  ' . $dr . ' = call i64 @manticore_' . $this->mangle($defSym)
                      . '(i64 ' . $oi . ")\n";
            }
        }
        $ctorClass = $n->bare ? '' : $this->resolveMethodClass($n->class, '__construct');
        if ($ctorClass !== '') {
            $objInt = $this->ssa->allocReg();
            $out .= '  ' . $objInt . ' = ptrtoint ptr ' . $obj . " to i64\n";
            $argList = 'i64 ' . $objInt;
            $argTemps = [];
            $cellBoxSlots = [];
            $cellBoxTmps = [];
            $cellBoxTypes = [];
            $reboxSlots = [];
            $reboxTmps = [];
            // Ctor param 0 is the implicit `$this`, so call arg `ai` maps to
            // param `ai + 1` — unbox a cell arg bound to a scalar param.
            $ptypes = $this->sigs->paramTypes[$ctorClass . '____construct'] ?? [];
            $ahmask = $this->sigs->arrayHintedParams[$ctorClass . '____construct'] ?? [];
            $tmask = $this->sigs->taggedParams[$ctorClass . '____construct'] ?? [];
            // A ctor takes by-ref params like any other method, and this loop
            // did not honour them: `new ConsoleSectionOutput($s, $this->sections,
            // …)` against `array &$sections` handed over the array POINTER, and
            // the callee's array_unshift wrote the relocated buffer back through
            // it — into the empty-array singleton's LENGTH field, which every
            // later `= []` then copied as a length of 0xa77…, so the first
            // append to any such array wrote off the end of the world.
            $mask = $this->sigs->refParams[$ctorClass . '____construct'] ?? [];
            $ai = 0;
            foreach ($n->args as $a) {
                // `new C(...$arr)`: expand the pack across the ctor's remaining
                // params (param 0 is `$this`). Without an arm here the Spread_
                // node emitted no value and the ctor read whatever lastValue
                // still held, so `new R(...['C'])` constructed from garbage.
                if ($a->kind === Node::KIND_SPREAD) {
                    $out .= $this->emitNode($a->operand);
                    $out .= $this->coerceToPtr();
                    [$sir, $sregs] = $this->emitSpreadFill(
                        $this->lastValue, $ai + 1, $ptypes, $tmask,
                        $a->operand->type->element ?? null, $ctorClass . '____construct');
                    $out .= $sir;
                    foreach ($sregs as $rg) { $argList .= ', i64 ' . $rg; }
                    $ai = \count($ptypes) - 1;
                    continue;
                }
                if ($this->argIsByRef($mask, $ai + 1, $a)
                    && $this->isByRefAddressable($a)
                    && $this->byRefNeedsCellUnbox($a, $ptypes, $ai + 1)) {
                    // A CELL lvalue (a vivified out-variable) bound to a
                    // raw-payload by-ref param: hand over an untagged scratch
                    // slot and re-box what the ctor left. Passing the cell slot
                    // makes the ctor dereference the tag bits.
                    $out .= $this->emitByRefCellUnboxArg($a);
                    $reboxSlots[] = $this->refBoxSlot;
                    $reboxTmps[] = $this->refBoxTmp;
                } elseif ($this->argIsByRef($mask, $ai + 1, $a)
                    && $this->isByRefAddressable($a)
                    && $this->byRefNeedsCellBox($a, $ptypes, $ai + 1)) {
                    // The mirror: a concrete lvalue bound to a `mixed &$var`.
                    $out .= $this->emitByRefCellBox($a);
                    $cellBoxSlots[] = $this->refBoxSlot;
                    $cellBoxTmps[] = $this->refBoxTmp;
                    $cellBoxTypes[] = $a->type;
                } elseif ($this->argIsByRef($mask, $ai + 1, $a)) {
                    $out .= $this->emitByRefArg($a);
                } elseif (($tmask[$ai + 1] ?? false) && $a->type->kind !== Type::KIND_CELL) {
                    // Tagged (mixed/union) ctor param: NaN-box the arg by its
                    // static type so the ctor reads the runtime tag.
                    $out .= $this->emitNode($a);
                    $out .= $this->boxToCell($a->type);
                } else {
                    $out .= $this->emitNode($a);
                    // An int/bool arg to a declared `float` ctor param converts
                    // numerically (sitofp) — else the integer bits cross the i64
                    // ABI carrier and the property reads a garbage double
                    // (`new C($i)` for `__construct(float $x)`). Mirrors emitCall.
                    $pt = $ptypes[$ai + 1] ?? null;
                    if ($pt !== null && $pt->kind === Type::KIND_FLOAT
                        && ($a->type->kind === Type::KIND_INT || $a->type->kind === Type::KIND_BOOL)) {
                        $out .= $this->coerceToI64();
                        $d = $this->ssa->allocReg();
                        $out .= '  ' . $d . ' = sitofp i64 ' . $this->lastValue . " to double\n";
                        $this->lastValue = $d;
                        $this->lastValueType = 'double';
                    }
                    $out .= $this->coerceToI64();
                    $out .= $this->unboxCellArg($a, $ptypes, $ai + 1, $ahmask);
                    if ($this->isFreshStringTemp($a)) { $argTemps[] = $this->lastValue; }
                }
                $argList .= ', i64 ' . $this->lastValue;
                $ai = $ai + 1;
            }
            // Late static binding: `new C()` constructs with `static == C` even
            // when the ctor body is inherited — route to the C specialisation.
            $ctorTarget = $this->lsbTarget($ctorClass, '__construct', $n->class);
            // Push a frame for the ctor call so __construct's entry btNameFix
            // stamps its OWN (soon-popped) slot, not the caller's top frame.
            // Popped before the throwable capture below, so — like PHP — the
            // constructor never appears in the trace.
            $out .= $this->btPush('__construct', $n->line);
            $out .= $this->faPush($ctorTarget, $n->srcArgc, $n->args, 1);
            $cr = $this->ssa->allocReg();
            $out .= '  ' . $cr . ' = call i64 @manticore_' . $this->mangle($ctorTarget)
                  . '(' . $argList . ")\n";
            $out .= $this->btPop();
            $out .= $this->emitByRefCellRebox($reboxSlots, $reboxTmps);
            $ci = 0;
            foreach ($cellBoxTmps as $ctmp) {
                $out .= $this->emitByRefCellWriteBack($ctmp, $cellBoxSlots[$ci], $cellBoxTypes[$ci]);
                $ci = $ci + 1;
            }
            // Free fresh string-temp ctor args (the ctor retained any it
            // stored into a property), matching emitCall.
            $out .= $this->freeStrArgTemps($argTemps);
        }
        // Capture the thrown location + call stack into a Throwable at `new`
        // (PHP records these at construction), when the program queries a trace.
        if ($this->rt->needsBacktrace && $cd !== null
            && $this->classImplements($n->class, 'Throwable')) {
            $out .= $this->emitThrowableCapture($obj, $n);
        }
        $this->lastValue = $obj;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Store the current call stack (@__mir_bt_name / @__mir_bt_line, innermost
     * first) into a freshly-constructed Throwable's traceNames / traceLines, and
     * the `new` site's line/file into line/file. `$no` is the NewObj.
     */
    private function emitThrowableCapture(string $obj, \Compile\Mir\NewObj $no): string
    {
        $cd = $this->classes[$no->class];
        $lineOff = $cd->propertyOffset('line');
        $fileOff = $cd->propertyOffset('file');
        $nmOff = $cd->propertyOffset('traceNames');
        $lnOff = $cd->propertyOffset('traceLines');
        $out = '';
        // line = the `new` site line; file = the source path.
        $lp = $this->ssa->allocReg();
        $out .= '  ' . $lp . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$lineOff . "\n";
        $out .= '  store i64 ' . (string)$no->line . ', ptr ' . $lp . "\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$fileOff . "\n";
        $fstr = $this->ssa->allocReg();
        $out .= '  ' . $fstr . ' = ptrtoint ptr ' . $this->strLitId($this->pool->intern($this->sourceFile)) . " to i64\n";
        $out .= '  store i64 ' . $fstr . ', ptr ' . $fp . "\n";
        // Two packed vecs of the active frames, innermost first.
        $out .= $this->emitBtVec('@__mir_bt_name');
        $namesVec = $this->lastValue;
        $np = $this->ssa->allocReg();
        $out .= '  ' . $np . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$nmOff . "\n";
        $out .= '  store i64 ' . $namesVec . ', ptr ' . $np . "\n";
        $out .= $this->emitBtVec('@__mir_bt_line');
        $linesVec = $this->lastValue;
        $lnp = $this->ssa->allocReg();
        $out .= '  ' . $lnp . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$lnOff . "\n";
        $out .= '  store i64 ' . $linesVec . ', ptr ' . $lnp . "\n";
        return $out;
    }

    /**
     * The concrete classes a value of interface/unknown type `$iface` can be at
     * runtime — the candidates a `clone` has to dispatch over. Structs and
     * enums are excluded (they carry no rc header and clone as values).
     *
     * @return string[]
     */
    private function cloneImplementers(string $iface): array
    {
        if ($iface === '') { return []; }
        $out = [];
        foreach ($this->classes as $name => $cd) {
            if ($cd->isStruct) { continue; }
            if ($this->isEnumClass($name) || $this->isClosureClass($name)) { continue; }
            if ($name === $iface) { continue; }
            if (!$this->classImplements($name, $iface)) { continue; }
            $out[] = $name;
        }
        return $out;
    }

    /**
     * `clone` over an interface-typed receiver: compare the instance's
     * descriptor word (slot 0) against each candidate's and run that class's
     * own clone. The default arm keeps the historical pass-through, so an
     * implementer this module cannot see behaves exactly as it did.
     *
     * @param string[] $impls
     */
    private function emitCloneDispatch(\Compile\Mir\Clone_ $n, string $src, array $impls): string
    {
        $slot = $this->ssa->allocReg();
        $out  = '  ' . $slot . " = alloca i64\n";
        $sp = $this->ssa->allocReg();
        $out .= '  ' . $sp . ' = ptrtoint ptr ' . $src . " to i64\n";
        $out .= '  store i64 ' . $sp . ', ptr ' . $slot . "\n";
        $desc = $this->ssa->allocReg();
        $out .= '  ' . $desc . ' = load i64, ptr ' . $src . "\n";
        $endL = $this->ssa->allocLabel('clonedyn.end');
        foreach ($impls as $name) {
            $cdN = $this->classes[$name];
            $hit = $this->ssa->allocLabel('clonedyn.hit');
            $next = $this->ssa->allocLabel('clonedyn.next');
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i64 ' . $desc . ', '
                  . $this->lib->descSlotValue($cdN) . "\n";
            $out .= '  br i1 ' . $eq . ', label %' . $hit . ', label %' . $next . "\n";
            $out .= $hit . ":\n";
            $out .= $this->emitCloneOfClass($n, $cdN, $name, $src);
            $ci = $this->ssa->allocReg();
            $out .= '  ' . $ci . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
            $out .= '  store i64 ' . $ci . ', ptr ' . $slot . "\n";
            $out .= '  br label %' . $endL . "\n";
            $out .= $next . ":\n";
        }
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = load i64, ptr ' . $slot . "\n";
        $rp = $this->ssa->allocReg();
        $out .= '  ' . $rp . ' = inttoptr i64 ' . $res . " to ptr\n";
        $this->lastValue = $rp;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `clone $obj` — allocate a fresh instance of the same class, shallow-copy
     * every property slot (co-owning rc-managed values: both objects share an
     * object/string/array handle), then call `__clone()` if defined. PHP 8.5
     * clone-with overrides land on the copy after `__clone`.
     */
    private function emitClone(\Compile\Mir\Clone_ $n): string
    {
        $cls = $n->object->type->class ?? '';
        $cd = ($cls !== '' && isset($this->classes[$cls])) ? $this->classes[$cls] : null;
        $out = $this->emitNode($n->object);
        $out .= $this->coerceToPtr();
        $src = $this->lastValue;
        if ($cd === null || $cd->isStruct) {
            // An INTERFACE-typed receiver has no ClassDef, and passing the
            // pointer through made `clone` the IDENTITY — the "copy" was the
            // original, so a write through it hit the shared object. symfony's
            // SymfonyStyle does `new TrimmedBufferOutput(…, false, clone
            // $output->getFormatter())` and then `setDecorated(false)` on that
            // "clone", which switched the REAL console formatter off: the whole
            // app rendered without colour. Dispatch on the runtime class id and
            // clone each implementer concretely; an unknown one still passes
            // through, exactly as before.
            $impls = $cd === null ? $this->cloneImplementers($cls) : [];
            if ($impls !== []) { return $out . $this->emitCloneDispatch($n, $src, $impls); }
            $this->lastValue = $src; $this->lastValueType = 'ptr';
            return $out;
        }
        return $out . $this->emitCloneOfClass($n, $cd, $cls, $src);
    }

    /**
     * The clone body for a KNOWN class: fresh instance, shallow-copied slots
     * (arrays copied by value, other rc handles co-owned), `__clone()`, then the
     * PHP 8.5 clone-with overrides. Shared by the static path and the
     * interface dispatch ({@see emitCloneDispatch}).
     */
    private function emitCloneOfClass(\Compile\Mir\Clone_ $n, $cd, string $cls, string $src): string
    {
        $out = '';
        $size = $cd->instanceSize();
        $new = $this->ssa->allocReg();
        $out .= '  ' . $new . ' = call ptr @__mir_alloc_tagged(i64 ' . (string)$size . ")\n";
        $out .= '  store i64 ' . $this->lib->descSlotValue($cd) . ', ptr ' . $new . "\n";
        $rcGep = $this->ssa->allocReg();
        $out .= '  ' . $rcGep . ' = getelementptr inbounds i64, ptr ' . $new . ", i64 1\n";
        $out .= '  store i64 1, ptr ' . $rcGep . "\n";
        // Copy each property slot; co-own rc-managed values (shallow copy).
        foreach ($cd->propertyNames as $pname) {
            $off = $cd->propertyOffset($pname);
            $sg = $this->ssa->allocReg();
            $out .= '  ' . $sg . ' = getelementptr inbounds i8, ptr ' . $src . ', i64 ' . (string)$off . "\n";
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = load i64, ptr ' . $sg . "\n";
            $dg = $this->ssa->allocReg();
            $out .= '  ' . $dg . ' = getelementptr inbounds i8, ptr ' . $new . ', i64 ' . (string)$off . "\n";
            $pt = $cd->propertyTypes[$pname] ?? null;
            // PHP arrays are VALUES: `clone` must copy each array property (a
            // fresh rc=1 owned buffer, no extra retain), not co-own the handle —
            // else a mutation on the clone (`$b->items[] = x`) aliases the
            // original. Fires for a typed array AND a bare `array` hint whose
            // element erased to unknown (still an array at runtime). A cell
            // (heterogeneous) element takes the tag-aware copy so a boxed inner
            // array separates too; a null slot passes through (copy is NULL-safe).
            // ⛔ NOT for a slot the ref-cell promotion retyped to a CELL. The
            // `array` hint is the SOURCE-level one and survives the promotion,
            // so a `private array $data` bound by `[&$this->data]` still looked
            // array-shaped here and the copy inttoptr'd a NaN-boxed word —
            // SIGSEGV on `clone`, with nibble 7 (ARRAY) still in the address.
            //
            // Copying the cell is also what php DOES: a property holding a
            // reference is SHARED with the clone, not duplicated. Measured on
            // the witness shape — the clone's `__clone` increments
            // `$this->clonesCount` and the ORIGINAL sees it. Deep-copying the
            // array would have broken that even if the pointer had been valid.
            $promoted = $pt !== null && $pt->kind === Type::KIND_CELL;
            $arrHint = !$promoted
                && (($cd->propertyArrayHinted[$pname] ?? false)
                    || ($pt !== null && $pt->isArray()));
            if ($arrHint) {
                $vp = $this->ssa->allocReg();
                $out .= '  ' . $vp . ' = inttoptr i64 ' . $v . " to ptr\n";
                $isCellElem = $pt !== null && $pt->element !== null
                    && $pt->element->kind === Type::KIND_CELL;
                $cp = $this->ssa->allocReg();
                $fn = $isCellElem ? '__mir_array_copy_cells' : '__mir_array_copy';
                $out .= '  ' . $cp . ' = call ptr @' . $fn . '(ptr ' . $vp . ")\n";
                $cpi = $this->ssa->allocReg();
                $out .= '  ' . $cpi . ' = ptrtoint ptr ' . $cp . " to i64\n";
                $out .= '  store i64 ' . $cpi . ', ptr ' . $dg . "\n";
            } else {
                $out .= '  store i64 ' . $v . ', ptr ' . $dg . "\n";
                $out .= $this->rcRetainRawByType($v, $pt);
            }
        }
        // Dynamic-property bag: shallow-share the same assoc pointer.
        if ($cd->usesBag()) {
            $bo = $cd->bagOffset();
            $sg = $this->ssa->allocReg();
            $out .= '  ' . $sg . ' = getelementptr inbounds i8, ptr ' . $src . ', i64 ' . (string)$bo . "\n";
            $bv = $this->ssa->allocReg();
            $out .= '  ' . $bv . ' = load i64, ptr ' . $sg . "\n";
            $dg = $this->ssa->allocReg();
            $out .= '  ' . $dg . ' = getelementptr inbounds i8, ptr ' . $new . ', i64 ' . (string)$bo . "\n";
            $out .= '  store i64 ' . $bv . ', ptr ' . $dg . "\n";
        }
        // __clone() hook on the fresh copy.
        $cloneCls = $this->resolveMethodClass($cls, '__clone');
        if ($cloneCls !== '') {
            $ni = $this->ssa->allocReg();
            $out .= '  ' . $ni . ' = ptrtoint ptr ' . $new . " to i64\n";
            $cr = $this->ssa->allocReg();
            $out .= '  ' . $cr . ' = call i64 @manticore_' . $this->mangle($cloneCls)
                  . '____clone(i64 ' . $ni . ")\n";
        }
        // PHP 8.5 clone-with overrides applied last.
        foreach ($n->withProps as $pair) {
            $off = $cd->propertyOffset($pair->name);
            if ($off < 0) { continue; }
            $pt = $cd->propertyTypes[$pair->name] ?? null;
            $out .= $this->emitNode($pair->value);
            if ($pt !== null && $pt->kind === Type::KIND_CELL
                && $pair->value->type->kind !== Type::KIND_CELL) {
                $out .= $this->boxToCell($pair->value->type, $pair->value);
            } else {
                $out .= $this->coerceToI64();
            }
            $pv = $this->lastValue;
            $out .= $this->rcRetainByType($pair->value, $pv, $pt, 4);
            $dg = $this->ssa->allocReg();
            $out .= '  ' . $dg . ' = getelementptr inbounds i8, ptr ' . $new . ', i64 ' . (string)$off . "\n";
            $out .= '  store i64 ' . $pv . ', ptr ' . $dg . "\n";
        }
        $this->lastValue = $new; $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitPropertyAccess(PropertyAccess_ $n): string
    {
        $pa = $n;
        // `$byte->value` on a `#[TypeDef]`: the value IS the property. No load, no
        // offset — emit the receiver and stop.
        $td = $pa->object->type->typeDefClass();
        if ($td !== null && isset($this->typeDefs[$td])
            && $pa->property === $this->typeDefs[$td]->typeDefProp) {
            return $this->emitNode($pa->object);
        }
        // Enum case `->name` / `->value` → index the per-enum global
        // table by the case ordinal.
        $ecls = $pa->object->type->class ?? '';
        if ($ecls !== '' && isset($this->enums[$ecls])) {
            return $this->emitEnumProp($pa, $ecls);
        }
        // `$cell->prop` — a tagged object cell (array_first over an obj array,
        // a `mixed` field, a json_decode stdClass): resolve the holder's slot.
        if ($pa->object->type->kind === Type::KIND_CELL) {
            return $this->emitCellPropertyRead($pa);
        }
        if ($pa->object->type->kind === Type::KIND_UNION) {
            return $this->emitUnionPropertyAccess($pa);
        }
        // PHP 8.4 property hook: a get hook replaces the read, UNLESS we are
        // emitting this property's own hook (then read the backing slot direct,
        // no infinite re-entry).
        if ($ecls !== '' && isset($this->classes[$ecls])
            && isset($this->classes[$ecls]->propHooks[$pa->property])) {
            $hk = $this->classes[$ecls]->propHooks[$pa->property];
            if ($hk['get'] !== '' && !$this->insideOwnHook($hk)) {
                return $this->emitHookGet($pa->object, $hk['get'], $n->type);
            }
        }
        // Dynamic property on a bag-bearing class (stdClass / dynamic):
        // an undeclared name reads from the property-bag assoc.
        $bcls = $pa->object->type->class ?? '';
        if ($bcls !== '' && isset($this->classes[$bcls])
            && $this->classes[$bcls]->usesBag()
            && $this->classes[$bcls]->propertyOffset($pa->property) === -1) {
            $bcd = $this->classes[$bcls];
            $out = $this->emitNode($pa->object);
            $out .= $this->coerceToPtr();
            $objPtr = $this->lastValue;
            $bg = $this->ssa->allocReg();
            $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$bcd->bagOffset() . "\n";
            $bagI = $this->ssa->allocReg();
            $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
            $bagP = $this->ssa->allocReg();
            $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
            $kid = $this->pool->intern($pa->property);
            $reg = $this->ssa->allocReg();
                        $out .= '  ' . $reg . ' = call i64 @__mir_array_get_str(ptr ' . $bagP
                  . ', ptr ' . $this->strLitId($kid) . ", i64 0, i64 0)\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Property overloading: an undeclared property on a class that defines
        // __get routes through `$obj->__get('name')`.
        $gcls = $pa->object->type->class ?? '';
        if ($gcls !== '' && isset($this->classes[$gcls])
            && $this->classes[$gcls]->propertyOffset($pa->property) === -1) {
            $getCls = $this->resolveMethodClass($gcls, '__get');
            if ($getCls !== '') {
                $out = $this->emitNode($pa->object);
                $out .= $this->coerceToPtr();
                return $out . $this->emitMagicCall($getCls, '__get', $this->lastValue, $pa->property, null);
            }
        }
        if (($pa->object->type->class ?? '') === '' && \getenv('MANTICORE_UNKNOWN_PROP_TRACE')) {
            \error_log("UNKPROP\tfn=" . $this->frame->name
                . "\t->" . $pa->property . "\trkind=" . $pa->object->type->kind
                . "\tL" . ($pa->object->line ?: $n->line));
        }
        // A KIND_UNKNOWN receiver (inference lost the class) can't use a static
        // property offset — blind-reading slot 16 mis-slots / SIGSEGVs whenever
        // the real holder lays $prop elsewhere. Recover the class at runtime from
        // the object's class_id and read $prop's REAL per-holder offset.
        if ($pa->object->type->kind === Type::KIND_UNKNOWN) {
            return $this->emitRawPropByClassId($pa);
        }
        // The class is KNOWN but does not put `$prop` anywhere — neither on
        // itself nor on any subclass. A static offset here is not a fact, it is
        // the slot-16 default, and 16 is the first field of whatever object
        // actually arrives. That is a silent WRONG READ, which is strictly
        // worse than the runtime dispatch that already exists for the erased
        // receiver, so take that instead.
        //
        // A closure is the shape that makes this reachable in correct code:
        // `Closure::bind($fn, $obj, $scope)` rebinds `$this` to a class the
        // body was not written in, and lowering types `$this` from the LEXICAL
        // class ({@see LowerFns::finishClosure}). symfony's MicroKernelTrait
        // does exactly that — `fn &() => $this->instanceof` sits in the app
        // Kernel and is bound to a PhpFileLoader.
        if ($this->propertyOffsetOrNull($pa->object, $pa->property) === null) {
            return $this->emitRawPropByClassId($pa);
        }
        $out = $this->emitNode($pa->object);
        $out .= $this->coerceToPtr();
        $objPtr = $this->lastValue;
        $offset = $this->propertyOffset($pa->object, $pa->property);
        $gep = $this->ssa->allocReg();
        $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr '
              . $objPtr . ', i64 ' . (string)$offset . "\n";
        $out .= $this->emitSlotLoad(
            $gep,
            $this->slotHolder($pa->object, $pa->property),
            $pa->property,
            $n->type,
        );
        $loaded = $this->lastValue;
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $loaded . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        } elseif ($n->type->kind === Type::KIND_OBJ) {
            // An obj-typed property read whose slot may hold a boxed cell (a
            // `mixed` prop holding an object, or one narrowed via a property-path
            // instanceof) — strip the tag so the result is a clean obj ptr. The
            // 48-bit mask is identity on a real heap ptr (no-op for a raw obj).
            $masked = $this->ssa->allocReg();
            $out .= '  ' . $masked . ' = and i64 ' . $loaded . ", 281474976710655\n";
            $this->lastValue = $masked;
        }
        return $out;
    }

    /**
     * `$cell->prop` on a tagged object cell whose static class is erased (a
     * `mixed` value, an array_first over an obj array, a json_decode stdClass).
     * The runtime class is recovered from the object's class_id:
     *
     *  - No class declares `$prop` as a fixed slot → a dynamic-property bag
     *    object (stdClass); read the bag by name (the historical json path).
     *  - Exactly one fixed holder and NO bag class exists → the cell can only be
     *    that class; load its slot directly (static fast path, no class_id read).
     *  - Otherwise → a class_id switch: each fixed holder reads its OWN slot
     *    (boxed by that slot's declared type); the default falls back to the bag
     *    read (stdClass dynamic prop) or a null cell.
     *
     * Every arm yields a tagged cell so the erased-type consumer (echo /
     * var_dump / arithmetic) dispatches on the runtime tag.
     */
    private function emitCellPropertyRead(PropertyAccess_ $pa): string
    {
        $prop = $pa->property;
        $fixed = [];
        $hasBag = false;
        foreach ($this->classes as $cd) {
            if ($cd->propertyOffset($prop) >= 0) { $fixed[] = $cd; }
            if ($cd->usesBag()) { $hasBag = true; }
        }
        $out = $this->emitNode($pa->object);
        $out .= $this->cellToPtr();
        $objPtr = $this->lastValue;
        // An ENUM case reaching here through an erased carrier — `$e->name` where
        // `$e` is `mixed` (a `unserialize()` result, a mixed param, a generator
        // yield). The singleton is not a normal object: it has no `name` slot,
        // its ordinal sits at +16, and the value comes from a global table (the
        // typed path, emitEnumProp, already knows this). Without arms here the
        // read fell through to a bag read on a non-bag object and faulted.
        $enumArms = [];
        if ($prop === 'name' || $prop === 'value') {
            foreach ($this->enums as $ename => $ed) {
                if ($prop === 'value' && $this->edBacking($ed) === '') { continue; }
                $enumArms[$ename] = $ed;
            }
        }
        // Property overloading on an ERASED receiver: a class that declares
        // __get but not $prop answers through the method, exactly as the typed
        // path already does.
        $magic = $this->magicPropHolders($prop, '__get');
        // Pure dynamic-bag receiver — no concrete holder of $prop.
        if (\count($fixed) === 0 && $enumArms === [] && $magic === []) {
            return $out . $this->emitBagReadByClassId($pa, $objPtr);
        }
        // Static fast path: a single holder and no bag class anywhere, so the
        // cell can only be that class — read the slot with no class_id switch.
        if (\count($fixed) === 1 && !$hasBag && $enumArms === [] && $magic === []) {
            return $out . $this->emitFixedPropLoad($objPtr, $fixed[0], $prop);
        }
        // Runtime dispatch on the object's class_id.
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $end = $this->ssa->allocLabel('cp.end');
        $def = $this->ssa->allocLabel('cp.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        // Classes sharing a slot share an arm {@see canonArm}.
        /** @var array<string, string> */
        $armSeen = [];
        foreach ($fixed as $cd) {
            $lbl = $this->ssa->allocLabel('cp.case');
            $arm = $this->emitFixedPropLoad($objPtr, $cd, $prop);
            $arm .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $arm .= '  br label %' . $end . "\n";
            $key = $this->canonArm($arm);
            if (isset($armSeen[$key])) {
                $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $armSeen[$key] . "\n";
                continue;
            }
            $armSeen[$key] = $lbl;
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n" . $arm;
        }
        foreach ($enumArms as $ename => $ed) {
            $lbl = $this->ssa->allocLabel('cp.enum');
            $switch .= '    i64 ' . (string)$ed->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $bodies .= $this->emitEnumCellPropLoad($objPtr, $ename, $ed, $prop);
            $bodies .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        foreach ($magic as $cname => $declCls) {
            $lbl = $this->ssa->allocLabel('cp.magic');
            $switch .= '    i64 ' . (string)$this->classes[$cname]->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $bodies .= $this->emitMagicGetCell($declCls, $objPtr, $prop);
            $bodies .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $def . ":\n";
        if ($hasBag) {
            $out .= $this->emitBagReadByClassId($pa, $objPtr);
        } else {
            $this->rt->needsTagged = true;
            $bn = $this->ssa->allocReg();
            $out .= '  ' . $bn . " = call i64 @__manticore_box_null()\n";
            $this->lastValue = $bn;
        }
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Whether any NON-enum class declares (or inherits) `$method`. Gates the
     *  cell-receiver ordinal unbox: an enum method's `$this` is an ordinal, and
     *  that repr is only safe when no object candidate shares the name. */
    private function nonEnumDeclares(string $method): bool
    {
        foreach ($this->classes as $cd) {
            if (isset($this->enums[$cd->name])) { continue; }
            if ($this->resolveMethodClass($cd->name, $method) !== '') { return true; }
        }
        return false;
    }

    /** Whether ANY class in the table declares (or inherits) `$method`. */
    private function anyClassDeclares(string $method): bool
    {
        foreach ($this->classes as $cd) {
            if ($this->resolveMethodClass($cd->name, $method) !== '') { return true; }
        }
        return false;
    }

    /**
     * Classes whose instances answer `$prop` through a magic method instead of a
     * slot: they do not declare `$prop`, but they or an ancestor declare
     * `$method`. Keyed by the CONCRETE class — its class_id is what the runtime
     * switch matches — mapping to the class that actually DECLARES the method,
     * which is the symbol to call.
     *
     * This is what lifts the "statically known receiver" limit: the typed path
     * has always routed an undeclared property through __get/__set, but an
     * erased receiver had no arm and fell through to a bag read (or a raw slot-16
     * load) instead.
     *
     * A bag class is EXCLUDED. php consults the dynamic property first and only
     * reaches __get when the name is absent; the bag read here has no "absent"
     * answer to branch on, so keeping the existing bag behaviour is the honest
     * choice rather than silently preferring the magic method.
     *
     * @return array<string,string>
     */
    private function magicPropHolders(string $prop, string $method): array
    {
        $holders = [];
        foreach ($this->classes as $cd) {
            if ($cd->propertyOffset($prop) >= 0) { continue; }
            if ($cd->isStruct || $cd->usesBag()) { continue; }
            if ($this->isClosureClass($cd->name) || $this->isEnumClass($cd->name)) { continue; }
            $decl = $this->resolveMethodClass($cd->name, $method);
            if ($decl === '') { continue; }
            $holders[$cd->name] = $decl;
        }
        return $holders;
    }

    /**
     * Load `$prop` from `$objPtr` at `$cd`'s fixed slot and box it to a tagged
     * cell by the slot's declared type (an untyped / cell slot already holds a
     * cell → passthrough). lastValue ← the cell.
     */
    private function emitFixedPropLoad(string $objPtr, ClassDef $cd, string $prop): string
    {
        $off = $cd->propertyOffset($prop);
        $pt = $cd->propertyTypes[$prop] ?? null;
        $gep = $this->ssa->allocReg();
        $out = '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr
             . ', i64 ' . (string)$off . "\n";
        $out .= $this->emitSlotLoad($gep, $cd, $prop, $pt ?? Type::unknown());
        $ld = $this->lastValue;
        // This caller's contract is a TAGGED CELL, and two slot shapes are ones
        // boxRawValue deliberately hands back RAW (its other caller wants that):
        //  - an ENUM slot holds an ORDINAL, passed through so a TYPED consumer can
        //    index the case tables; an erased reader needs the singleton, or
        //    `$e->s === Suit::Spades` compares an ordinal against a cell.
        //  - a bare `array` hint erases its element to UNKNOWN, and an unknown is
        //    passed through as if already boxed — so the raw buffer pointer got
        //    var_dumped as a double, float(2.1490356046E-314).
        if ($pt !== null && $this->isEnumType($pt)) {
            $this->rt->needsTagged = true;
            $pp = '';
            $out .= $this->emitEnumSingletonPtr((string)$pt->class, $ld, $pp);
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_object(ptr ' . $pp . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        if (($cd->propertyArrayHinted[$prop] ?? false)
            && ($pt === null || $pt->kind === Type::KIND_UNKNOWN)) {
            $this->rt->needsTagged = true;
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $ld . " to ptr\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_array(ptr ' . $p . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->boxRawValue($ld, $pt);
        return $out;
    }

    /**
     * The bag read for a receiver whose class is only known at runtime: dispatch
     * on class_id over the classes that ACTUALLY have a bag, each at its OWN
     * offset, and answer a null cell for anything else.
     *
     * The unconditional version read stdClass's offset on whatever arrived. On a
     * bag-less object that slot is a declared property, so `isset($p->undeclared)`
     * with an erased `$p` handed `int $real` to __mir_array_get_str as an assoc
     * pointer and SIGSEGV'd.
     */
    private function emitBagReadByClassId(PropertyAccess_ $pa, string $objPtr): string
    {
        $bagCds = [];
        foreach ($this->classes as $cd) {
            if ($cd->usesBag()) { $bagCds[] = $cd; }
        }
        $this->rt->needsTagged = true;
        if ($bagCds === []) {
            $bn = $this->ssa->allocReg();
            $out = '  ' . $bn . " = call i64 @__manticore_box_null()\n";
            $this->lastValue = $bn;
            $this->lastValueType = 'i64';
            return $out;
        }
        $res = $this->ssa->allocReg();
        $out = '  ' . $res . " = alloca i64\n";
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $end = $this->ssa->allocLabel('bagr.end');
        $def = $this->ssa->allocLabel('bagr.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        $kid = $this->pool->intern($pa->property);
        foreach ($bagCds as $cd) {
            $lbl = $this->ssa->allocLabel('bagr.case');
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $g = $this->ssa->allocReg();
            $bodies .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objPtr
                     . ', i64 ' . (string)$cd->bagOffset() . "\n";
            $bi = $this->ssa->allocReg();
            $bodies .= '  ' . $bi . ' = load i64, ptr ' . $g . "\n";
            $bp = $this->ssa->allocReg();
            $bodies .= '  ' . $bp . ' = inttoptr i64 ' . $bi . " to ptr\n";
            $rv = $this->ssa->allocReg();
            $bodies .= '  ' . $rv . ' = call i64 @__mir_array_get_str(ptr ' . $bp
                     . ', ptr ' . $this->strLitId($kid) . ", i64 0, i64 0)\n";
            $bodies .= '  store i64 ' . $rv . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies . $def . ":\n";
        $bn = $this->ssa->allocReg();
        $out .= '  ' . $bn . " = call i64 @__manticore_box_null()\n";
        $out .= '  store i64 ' . $bn . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** The dynamic-property bag read (`__mir_array_get_str` by name) given an
     *  already-unboxed object pointer; lastValue ← the cell value. */
    private function emitBagReadInto(PropertyAccess_ $pa, string $objPtr): string
    {
        $std = $this->classes['stdClass'] ?? null;
        $bagOff = $std === null ? 16 : $std->bagOffset();
        $out = $this->emitBagPtr($pa->object, $objPtr, $bagOff);
        $bagP = $this->bagPtrReg;
        $kid = $this->pool->intern($pa->property);
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mir_array_get_str(ptr ' . $bagP
              . ', ptr ' . $this->strLitId($kid) . ", i64 0, i64 0)\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `$x->prop` where `$x` is KIND_UNKNOWN. Recover the runtime class from the
     * object's class_id and read `$prop` at its REAL per-holder offset, BOXED by
     * that slot's declared type so the result is a self-describing tagged cell
     * (var_dump / echo dispatch on the tag; a raw load would render a string
     * slot as its pointer-as-int). `cellToPtr` normalises both a raw obj ptr and
     * a boxed-obj cell. Replaces the old blind offset-16 read (correct only for a
     * single-property class). A class_id with no fixed holder falls back to slot
     * 16 raw, and a no-holder property to a null cell.
     */
    private function emitRawPropByClassId(PropertyAccess_ $pa): string
    {
        $prop = $pa->property;
        $fixed = [];
        foreach ($this->classes as $cd) {
            if ($cd->propertyOffset($prop) >= 0) { $fixed[] = $cd; }
        }
        // Property overloading on an ERASED receiver — see magicPropHolders.
        $magic = $this->magicPropHolders($prop, '__get');
        $out = $this->emitNode($pa->object);
        $out .= $this->cellToPtr();
        $objPtr = $this->lastValue;
        // No known holder → nothing better than the historical raw slot-16 read.
        if (\count($fixed) === 0 && $magic === []) {
            $gep = $this->ssa->allocReg();
            $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr . ", i64 16\n";
            $ld = $this->ssa->allocReg();
            $out .= '  ' . $ld . ' = load i64, ptr ' . $gep . "\n";
            $this->lastValue = $ld;
            $this->lastValueType = 'i64';
            return $out;
        }
        // A single holder → its real offset, boxed by its declared type.
        if (\count($fixed) === 1 && $magic === []) {
            return $out . $this->emitFixedPropLoad($objPtr, $fixed[0], $prop);
        }
        // NO unconditional shortcut for a single magic holder: the receiver is
        // ERASED, so "one class declares __get" says nothing about the class in
        // hand. Calling it anyway ran Ovl::__get with a Plain2 `$this` and read
        // that object's slot 0 as an array. Always dispatch on class_id.
        // Dispatch on class_id: each holder reads (and boxes) its OWN slot.
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $end = $this->ssa->allocLabel('rp.end');
        $def = $this->ssa->allocLabel('rp.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        /** @var array<string, string> {@see canonArm} */
        $armSeen = [];
        foreach ($fixed as $cd) {
            $lbl = $this->ssa->allocLabel('rp.case');
            $arm = $this->emitFixedPropLoad($objPtr, $cd, $prop);
            $arm .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $arm .= '  br label %' . $end . "\n";
            $key = $this->canonArm($arm);
            if (isset($armSeen[$key])) {
                $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $armSeen[$key] . "\n";
                continue;
            }
            $armSeen[$key] = $lbl;
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n" . $arm;
        }
        foreach ($magic as $cname => $declCls) {
            $lbl = $this->ssa->allocLabel('rp.magic');
            $switch .= '    i64 ' . (string)$this->classes[$cname]->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $bodies .= $this->emitMagicGetCell($declCls, $objPtr, $prop);
            $bodies .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $def . ":\n";
        $gep = $this->ssa->allocReg();
        $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr . ", i64 16\n";
        $ld = $this->ssa->allocReg();
        $out .= '  ' . $ld . ' = load i64, ptr ' . $gep . "\n";
        $out .= '  store i64 ' . $ld . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * The ADDRESS of `$prop` as i64 in lastValue, for a receiver whose layout
     * is not statically knowable; null when NO class in the module declares
     * `$prop`, since then there is no slot to point at and the caller must fall
     * back to a value copy.
     *
     * The address twin of {@see emitRawPropByClassId}. A by-ref RETURN of
     * `$this->prop` out of a closure that `Closure::bind` rebinds to a foreign
     * scope needs a real slot address — the static offset there is the slot-16
     * guess, and handing THAT out as an alias corrupts an unrelated field.
     */
    private function emitPropAddrByClassId(Node $objExpr, string $prop): ?string
    {
        $fixed = [];
        foreach ($this->classes as $cd) {
            if ($cd->propertyOffset($prop) >= 0) { $fixed[] = $cd; }
        }
        if ($fixed === []) { return null; }
        $out = $this->emitNode($objExpr);
        $out .= $this->cellToPtr();
        $objPtr = $this->lastValue;
        // One holder → its real offset, no dispatch.
        if (\count($fixed) === 1) {
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$fixed[0]->propertyOffset($prop) . "\n";
            $addr = $this->ssa->allocReg();
            $out .= '  ' . $addr . ' = ptrtoint ptr ' . $g . " to i64\n";
            $this->lastValue = $addr;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $end = $this->ssa->allocLabel('pad.end');
        $def = $this->ssa->allocLabel('pad.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        foreach ($fixed as $cd) {
            $lbl = $this->ssa->allocLabel('pad.case');
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $g = $this->ssa->allocReg();
            $bodies .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objPtr
                     . ', i64 ' . (string)$cd->propertyOffset($prop) . "\n";
            $gi = $this->ssa->allocReg();
            $bodies .= '  ' . $gi . ' = ptrtoint ptr ' . $g . " to i64\n";
            $bodies .= '  store i64 ' . $gi . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        // A class_id no holder matches keeps the historical slot-16 address.
        $out .= $def . ":\n";
        $dg = $this->ssa->allocReg();
        $out .= '  ' . $dg . ' = getelementptr inbounds i8, ptr ' . $objPtr . ", i64 16\n";
        $dgi = $this->ssa->allocReg();
        $out .= '  ' . $dgi . ' = ptrtoint ptr ' . $dg . " to i64\n";
        $out .= '  store i64 ' . $dgi . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `$cell->prop = v` on a receiver whose static class is erased (a `mixed`
     * value, a `new $cls()` result, a classless dynamic-name store). Store mirror
     * of {@see emitCellPropertyRead}: recover the runtime class from the object's
     * class_id and write `$prop`'s REAL per-holder slot in that slot's declared
     * repr. Replaces the old blind offset-16 store — writing a scalar slot as a
     * bag pointer, a wild write / SIGSEGV. A class_id matching no fixed holder
     * falls back to the dynamic bag (stdClass) or a no-op — never slot 16.
     */
    private function emitCellStoreProperty(\Compile\Mir\StoreProperty $n): string
    {
        $prop = $n->property;
        $fixed = [];
        $hasBag = false;
        foreach ($this->classes as $cd) {
            if ($cd->propertyOffset($prop) >= 0) { $fixed[] = $cd; }
            if ($cd->usesBag()) { $hasBag = true; }
        }
        $out = $this->emitObjPtrOf($n->object);
        $objPtr = $this->lastValue;
        // Evaluate the RHS once; keep both a boxed-cell form (for cell slots) and
        // the payload retained once (the object takes an owning reference — only
        // one arm runs at runtime, so a single retain is correct).
        $out .= $this->emitNode($n->value);
        $out .= $this->coerceToI64();
        $raw = $this->lastValue;
        $out .= $this->rcRetainByType($n->value, $raw, $n->value->type, 4);
        $this->lastValue = $raw;
        $this->lastValueType = 'i64';
        $out .= $this->boxToCell($n->value->type, $n->value);
        $cellVal = $this->lastValue;
        // Property overloading on an ERASED receiver: a class that declares
        // __set but not $prop takes the write through the method.
        $magic = $this->magicPropHolders($prop, '__set');
        // No known holder → __set, bag store (stdClass), or drop; never offset-16.
        if (\count($fixed) === 0 && $magic === []) {
            if ($hasBag) { $out .= $this->emitCellBagStore($n, $objPtr, $cellVal); }
            $this->lastValue = $cellVal;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Single holder and no bag anywhere → the cell can only be that class.
        if (\count($fixed) === 1 && !$hasBag && $magic === []) {
            $out .= $this->emitCellSlotStore($objPtr, $fixed[0], $prop, $cellVal);
            $this->lastValue = $cellVal;
            $this->lastValueType = 'i64';
            return $out;
        }
        // NO unconditional shortcut for a single magic holder — the receiver is
        // ERASED, so one declarer says nothing about the class in hand. Always
        // dispatch on class_id.
        // Runtime dispatch on the object's class_id — each holder stores its slot.
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $end = $this->ssa->allocLabel('cs.end');
        $def = $this->ssa->allocLabel('cs.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        $seen = [];
        /** @var array<string, string> {@see canonArm} */
        $armSeen = [];
        foreach ($fixed as $cd) {
            if (isset($seen[$cd->name])) { continue; }
            $seen[$cd->name] = true;
            $lbl = $this->ssa->allocLabel('cs.case');
            $arm = $this->emitCellSlotStore($objPtr, $cd, $prop, $cellVal);
            $arm .= '  br label %' . $end . "\n";
            $key = $this->canonArm($arm);
            if (isset($armSeen[$key])) {
                $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $armSeen[$key] . "\n";
                continue;
            }
            $armSeen[$key] = $lbl;
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n" . $arm;
        }
        foreach ($magic as $cname => $declCls) {
            if (isset($seen[$cname])) { continue; }
            $seen[$cname] = true;
            $lbl = $this->ssa->allocLabel('cs.magic');
            $switch .= '    i64 ' . (string)$this->classes[$cname]->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $bodies .= $this->emitMagicCall($declCls, '__set', $objPtr, $prop, $cellVal);
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $def . ":\n";
        if ($hasBag) { $out .= $this->emitCellBagStore($n, $objPtr, $cellVal); }
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $this->lastValue = $cellVal;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Store the already-boxed `$cellVal` into `$cd`'s fixed `$prop` slot, in the
     *  slot's declared repr: a cell-boxed slot keeps the cell; a raw slot unboxes
     *  it (int/bool/string/obj/array/enum), a float slot to its bit pattern. */
    private function emitCellSlotStore(string $objPtr, ClassDef $cd, string $prop, string $cellVal): string
    {
        $pt = $cd->propertyTypes[$prop] ?? Type::cell();
        $off = $cd->propertyOffset($prop);
        $gep = $this->ssa->allocReg();
        $out = '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr
             . ', i64 ' . (string)$off . "\n";
        if ($this->cellPropBoxed($pt, $cd->name, $prop)) {
            return $out . $this->emitSlotStore($gep, $cd, $prop, $cellVal);
        }
        $this->lastValue = $cellVal;
        $this->lastValueType = 'i64';
        // A bare `array` hint erased to KIND_UNKNOWN still holds a RAW pointer —
        // unbox it as an array, or the tag rides into a slot the readers deref.
        // Same rule as the class-typed store path (see slotIsArrayHinted).
        $out .= $this->unboxCellToType(
            (!$pt->isArray() && ($cd->propertyArrayHinted[$prop] ?? false))
                ? Type::vec(Type::unknown())
                : $pt,
        );
        $val = $this->lastValue;
        if ($this->lastValueType === 'double') {
            $bits = $this->ssa->allocReg();
            $out .= '  ' . $bits . ' = bitcast double ' . $val . " to i64\n";
            $val = $bits;
        }
        return $out . $this->emitSlotStore($gep, $cd, $prop, $val);
    }

    /**
     * Evaluate a dynamic-property (bag) store's RHS and leave the BOXED cell in
     * lastValue, having taken the object's co-owning reference FIRST — on the
     * RAW pointer, because a tagged cell mis-locates the rc header.
     *
     * A fixed slot has done this since forever (emitStoreProperty's
     * `rcRetainByType`, and emitCellStoreProperty before its class_id switch);
     * the two BAG arms boxed and stored without it, so the bag held a borrowed
     * buffer. It survived exactly as long as some local still named the value:
     * `$o->k = $heapString` inside a function, with the object outliving the
     * frame, read back as whatever reused the freed buffer.
     */
    private function emitBagStoreValue(Node $value): string
    {
        $out = $this->emitNode($value);
        $k = $value->type->kind;
        if ($k === Type::KIND_OBJ || $k === Type::KIND_ARRAY
            || $k === Type::KIND_STRING || $k === Type::KIND_UNION) {
            $out .= $this->coerceToI64();
            $raw = $this->lastValue;
            $out .= $this->rcRetainByType($value, $raw, $value->type, 4);
            $this->lastValue = $raw;
            $this->lastValueType = 'i64';
        }
        return $out . $this->boxToCell($value->type, $value);
    }

    /** Default-arm dynamic-bag store (`__mir_array_set_str` by the static prop
     *  name) for a classless receiver whose runtime class is a bag object. */
    private function emitCellBagStore(\Compile\Mir\StoreProperty $n, string $objPtr, string $cellVal): string
    {
        $std = $this->classes['stdClass'] ?? null;
        $bagOff = $std === null ? 16 : $std->bagOffset();
        $out = $this->emitBagPtr($n->object, $objPtr, $bagOff);
        $bagP = $this->bagPtrReg;
        $bg = $this->bagSlotReg;
        $kid = $this->pool->intern($n->property);
        $nb = $this->ssa->allocReg();
        $out .= '  ' . $nb . ' = call ptr @__mir_array_set_str(ptr ' . $bagP
              . ', ptr ' . $this->strLitId($kid) . ', i64 ' . $cellVal . ", i64 0, i64 0)\n";
        $nbI = $this->ssa->allocReg();
        $out .= '  ' . $nbI . ' = ptrtoint ptr ' . $nb . " to i64\n";
        $out .= '  store i64 ' . $nbI . ', ptr ' . $bg . "\n";
        return $out;
    }

    /** All declared (fixed-slot) properties across every class, name => Type,
     *  collapsing a kind-disagreement across holders to a cell. The candidate
     *  map for a classless dynamic-name store/read.
     *  @return array<string, Type> */
    private function allDeclaredPropTypes(): array
    {
        $out = [];
        foreach ($this->classes as $cd) {
            foreach ($this->declaredPropTypes($cd) as $p => $pt) {
                if (!isset($out[$p])) { $out[$p] = $pt; }
                elseif ($out[$p]->kind !== $pt->kind) { $out[$p] = Type::cell(); }
            }
        }
        return $out;
    }

    /** True while emitting `$prop`'s own get/set hook — its `$this->$prop`
     *  accesses read/write the backing slot directly (no hook re-entry).
     *  @param array<string, string> $hk 'get'/'set' => the hook's fn name */
    private function insideOwnHook(array $hk): bool
    {
        return ($hk['get'] !== '' && $this->frame->name === $hk['get'])
            || ($hk['set'] !== '' && $this->frame->name === $hk['set']);
    }

    /** Emit a property get-hook call: `<hookSym>($this)` → the hooked value,
     *  coerced from the i64 carrier to `$resultType`. */
    private function emitHookGet(Node $objNode, string $hookSym, Type $resultType): string
    {
        $out = $this->emitNode($objNode);
        $out .= $this->coerceToI64();
        $thisArg = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($hookSym)
              . '(i64 ' . $thisArg . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($resultType->kind === Type::KIND_FLOAT) {
            $rf = $this->ssa->allocReg();
            $out .= '  ' . $rf . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $rf;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    /** Emit a property set-hook call: `<hookSym>($this, $value)`. The
     *  assignment expression yields the assigned value. */
    private function emitHookSet(Node $objNode, string $hookSym, Node $valueNode): string
    {
        $out = $this->emitNode($objNode);
        $out .= $this->coerceToI64();
        $thisArg = $this->lastValue;
        $out .= $this->emitNode($valueNode);
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($hookSym)
              . '(i64 ' . $thisArg . ', i64 ' . $val . ")\n";
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `$e->name` / `$e->value` for an enum case reached through an ERASED
     * receiver: `$objPtr` is the untagged singleton, whose ordinal lives at +16
     * (the layout emitEnumCellSingletons writes). Same tables as
     * {@see emitEnumProp}, but the result is BOXED — every other arm of
     * {@see emitCellPropertyRead} yields a cell and the caller reads one.
     */
    private function emitEnumCellPropLoad(string $objPtr, string $ecls, \Compile\Mir\EnumDef $ed, string $prop): string
    {
        $this->rt->needsTagged = true;
        $n = (string)\count($ed->caseNames);
        $g0 = $this->ssa->allocReg();
        $out = '  ' . $g0 . ' = getelementptr i8, ptr ' . $objPtr . ", i64 16\n";
        $ord = $this->ssa->allocReg();
        $out .= '  ' . $ord . ' = load i64, ptr ' . $g0 . "\n";
        if ($prop === 'value' && $this->edBacking($ed) === 'int') {
            $gep = $this->ssa->allocReg();
            $out .= '  ' . $gep . ' = getelementptr inbounds [' . $n . ' x i64], ptr @'
                  . $this->mangle($ecls) . '__values, i64 0, i64 ' . $ord . "\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = load i64, ptr ' . $gep . "\n";
            $b = $this->ssa->allocReg();
            $out .= '  ' . $b . ' = call i64 @__manticore_box_int(i64 ' . $r . ")\n";
            $this->lastValue = $b; $this->lastValueType = 'i64';
            return $out;
        }
        $table = ($prop === 'value') ? '__values' : '__names';
        $gep = $this->ssa->allocReg();
        $out .= '  ' . $gep . ' = getelementptr inbounds [' . $n . ' x ptr], ptr @'
              . $this->mangle($ecls) . $table . ', i64 0, i64 ' . $ord . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load ptr, ptr ' . $gep . "\n";
        $b = $this->ssa->allocReg();
        $out .= '  ' . $b . ' = call i64 @__manticore_box_ptr(ptr ' . $r . ")\n";
        $this->lastValue = $b; $this->lastValueType = 'i64';
        return $out;
    }

    /** `$enumCase->name` / `->value` via the global tables. */
    private function emitEnumProp(PropertyAccess_ $pa, string $ecls): string
    {
        $ed = $this->enums[$ecls];
        $n = \count($ed->caseNames);
        $out = $this->emitNode($pa->object);
        $out .= $this->coerceToI64();
        $ord = $this->lastValue;
        // A nullable-enum CELL receiver (`Enum::tryFrom(...)->name`) carries
        // box_object(singleton), not a raw ordinal — mask to the data ptr and
        // load the ordinal at +16 (mirrors emitEnumCellSingletons' layout).
        if ($pa->object->type->kind === Type::KIND_CELL) {
            $m = $this->ssa->allocReg();
            $out .= '  ' . $m . ' = and i64 ' . $ord . ", 281474976710655\n";
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $m . " to ptr\n";
            $g0 = $this->ssa->allocReg();
            $out .= '  ' . $g0 . ' = getelementptr i8, ptr ' . $p . ", i64 16\n";
            $ordR = $this->ssa->allocReg();
            $out .= '  ' . $ordR . ' = load i64, ptr ' . $g0 . "\n";
            $ord = $ordR;
        }
        // The enum tables are emitted under the mangled FQN (EmitLlvmModule);
        // the key lookup above uses the raw name, the symbol must match emit.
        $eclsSym = $this->mangle($ecls);
        if ($pa->property === 'value' && $this->edBacking($ed) === 'int') {
            $gep = $this->ssa->allocReg();
            $out .= '  ' . $gep . ' = getelementptr inbounds [' . (string)$n . ' x i64], ptr @'
                  . $this->mangle($ecls) . '__values, i64 0, i64 ' . $ord . "\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = load i64, ptr ' . $gep . "\n";
            $this->lastValue = $r; $this->lastValueType = 'i64';
            return $out;
        }
        // 'name', or string-backed 'value' → a ptr array.
        $table = ($pa->property === 'value') ? '__values' : '__names';
        $gep = $this->ssa->allocReg();
        $out .= '  ' . $gep . ' = getelementptr inbounds [' . (string)$n . ' x ptr], ptr @'
              . $this->mangle($ecls) . $table . ', i64 0, i64 ' . $ord . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load ptr, ptr ' . $gep . "\n";
        $this->lastValue = $r; $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Emit a magic-method call `$obj-><method>($name[, $value])` for property /
     * method overloading (__get/__set/__isset/__unset). The property name rides
     * as an interned string ptr; lastValue ← the i64 result (a void method like
     * __set returns a dummy 0). All user methods emit as `define i64`.
     */
    private function emitMagicCall(string $methodCls, string $method, string $objPtrReg, string $propName, ?string $valArg): string
    {
        $oi = $this->ssa->allocReg();
        $out = '  ' . $oi . ' = ptrtoint ptr ' . $objPtrReg . " to i64\n";
        $kid = $this->pool->intern($propName);
        $si = $this->ssa->allocReg();
        $out .= '  ' . $si . ' = ptrtoint ptr ' . $this->strLitId($kid) . " to i64\n";
        $args = 'i64 ' . $oi . ', i64 ' . $si;
        if ($valArg !== null) { $args .= ', i64 ' . $valArg; }
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @manticore_' . $this->mangle($methodCls)
              . '__' . $method . '(' . $args . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Classes implementing `$iface` that actually resolve `$method` — the arm
     *  set for {@see emitErasedIfaceCall}. @return array<string,string> class => declarer */
    private function ifaceMethodHolders(string $iface, string $method): array
    {
        $holders = [];
        foreach ($this->classes as $cd) {
            if ($cd->isStruct || $this->isClosureClass($cd->name) || $this->isEnumClass($cd->name)) {
                continue;
            }
            if (!$this->classImplements($cd->name, $iface)) { continue; }
            $decl = $this->resolveMethodClass($cd->name, $method);
            if ($decl === '') { continue; }
            $holders[$cd->name] = $decl;
        }
        return $holders;
    }

    /**
     * Call `$method` on an ERASED receiver — a raw object pointer whose class is
     * only known at runtime — by dispatching on its class_id across every class
     * implementing `$iface`. `$argRegs` are already-emitted i64 args (cells for a
     * `mixed` parameter). lastValue ← the i64 result; an unmatched class_id
     * yields 0.
     *
     * `ArrayAccess` and `Countable` were dispatched ONLY from a statically known
     * class, so the moment the receiver erased — and `simplexml_load_string`'s
     * `SimpleXMLElement|false` erases to a cell immediately — `$sxe['id']` fell
     * through to `__mir_array_get_str` on an OBJECT pointer and `count($sxe->x)`
     * loaded the object header as a vec length. Same shape as the `__get` arms
     * in {@see emitRawPropByClassId}: one arm per class, the declarer resolved
     * per class so an override still wins.
     */
    private function emitErasedIfaceCall(string $objPtr, string $iface, string $method,
                                         array $argRegs): string
    {
        $holders = $this->ifaceMethodHolders($iface, $method);
        $oi = $this->ssa->allocReg();
        $out = '  ' . $oi . ' = ptrtoint ptr ' . $objPtr . " to i64\n";
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $end = $this->ssa->allocLabel('ei.end');
        $def = $this->ssa->allocLabel('ei.default');
        $out .= $this->emitLoadClassId($objPtr);
        $switch = '  switch i64 ' . $this->classIdReg . ', label %' . $def . " [\n";
        $bodies = '';
        foreach ($holders as $cname => $declCls) {
            $lbl = $this->ssa->allocLabel('ei.case');
            $switch .= '    i64 ' . (string)$this->classes[$cname]->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $args = 'i64 ' . $oi;
            foreach ($argRegs as $ar) { $args .= ', i64 ' . $ar; }
            $r = $this->ssa->allocReg();
            $bodies .= '  ' . $r . ' = call i64 @manticore_' . $this->mangle($declCls)
                     . '__' . $method . '(' . $args . ")\n";
            $bodies .= '  store i64 ' . $r . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $def . ":\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $rr = $this->ssa->allocReg();
        $out .= '  ' . $rr . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $rr;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `__get` on an ERASED receiver, boxed to a tagged cell by the method's
     * DECLARED return type. lastValue ← the cell.
     *
     * The class_id switches around it hand every other arm through
     * {@see emitFixedPropLoad}, whose contract is a self-describing cell; the
     * magic arm used to store `emitMagicCall`'s RAW i64 beside them. A `__get`
     * returning an object therefore put a bare pointer into a cell channel, and
     * the consumer read it untagged: `(string)$sxe->title` rendered the pointer
     * as a denormal double (2.15E-314) because the cell→string dispatch saw no
     * NaN-box and fell to the float arm.
     */
    private function emitMagicGetCell(string $declCls, string $objPtrReg, string $prop): string
    {
        $out = $this->emitMagicCall($declCls, '__get', $objPtrReg, $prop, null);
        return $out . $this->boxRawValue($this->lastValue,
            $this->sigs->returnType[$declCls . '____get'] ?? null);
    }

    /** The class in `$cls`'s ancestry that DECLARES `$prop` as `readonly`, or ''
     *  — only that class's scope may write the slot. */
    private function readonlyDeclClass(string $cls, string $prop): string
    {
        $c = $cls;
        while ($c !== '' && isset($this->classes[$c])) {
            if ($this->classes[$c]->propertyReadonly[$prop] ?? false) { return $c; }
            $c = $this->classes[$c]->parent;
        }
        return '';
    }

    private function emitStoreProperty(\Compile\Mir\StoreProperty $n): string
    {
        // A UNION receiver has no single layout: dispatch the store on the runtime
        // class_id to each atom's slot offset (mirrors emitUnionPropertyAccess).
        if ($n->object->type->kind === Type::KIND_UNION) {
            return $this->emitUnionStoreProperty($n);
        }
        // A classless receiver (an erased `mixed` / `object` value with no static
        // class) has no static slot offset: recover the runtime class from the
        // object header and store its REAL slot. The old fall-through blind-wrote
        // slot 16 as a bag pointer → a wild write / SIGSEGV. Gate on "not a KNOWN
        // class" via isset (NOT `class === ''`): a null class field reads back as
        // garbage under the native self-build, so `=== ''` is false there while
        // `isset($this->classes[garbage])` is reliably false.
        $scls = $n->object->type->class ?? '';
        if (!($scls !== '' && isset($this->classes[$scls]))
            && ($n->object->type->kind === Type::KIND_CELL
                || $n->object->type->kind === Type::KIND_UNKNOWN
                || $n->object->type->kind === Type::KIND_OBJ)) {
            return $this->emitCellStoreProperty($n);
        }
        // A write to a `readonly` property from OUTSIDE its declaring class scope
        // is a fatal Error (PHP throws a catchable `Error`). Types are resolved
        // by now, so if the receiver's class chain declares this property
        // `readonly` in a class the CURRENT function (`Class__method`) is not part
        // of, evaluate the RHS (side effects) then throw in place of the store.
        // A write inside the class (constructor init) proceeds; single-init is not
        // enforced (that needs flow analysis).
        $roCls = $n->object->type->class ?? '';
        if ($roCls !== '') {
            $roDecl = $this->readonlyDeclClass($roCls, $n->property);
            // The generated unserialize filler is exempt: php's unserialize
            // INITIALISES a readonly property on a freshly-created object, and
            // the filler runs in declaring scope by construction (it is written
            // from the class table, one arm per class, and reached only from
            // `unserialize`). The guard is a frame-NAME prefix test, not a scope
            // model, so the exemption has to be spelled out here.
            if ($roDecl !== '' && !\str_starts_with($this->frame->name, $roDecl . '__')
                && !\str_starts_with($this->frame->name, '__mc_unser_set')) {
                $out = $this->emitNode($n->value);
                $msg = 'Cannot modify readonly property ' . $roDecl . '::$' . $n->property;
                // Supply every ctor arg — emitNewObj does NOT pad defaults (that
                // happens at AST→MIR); a short arg list leaves `code`/`previous`
                // as garbage registers and the ctor retains a bogus `previous`.
                $throw = new \Compile\Mir\Throw_(
                    new \Compile\Mir\NewObj('Error', [
                        new \Compile\Mir\StringConst($msg, Type::string_()),
                        new \Compile\Mir\IntConst(0, Type::int_()),
                        new \Compile\Mir\NullConst(Type::obj('Throwable')),
                    ], Type::obj('Error')),
                    Type::void(),
                );
                return $out . $this->emitNode($throw);
            }
        }
        // PHP 8.4 property hook: a set hook replaces the write (unless bypassed —
        // default init — or we are inside this property's own hook).
        $hcls = $n->object->type->class ?? '';
        if (!$n->bypassHook && $hcls !== '' && isset($this->classes[$hcls])
            && isset($this->classes[$hcls]->propHooks[$n->property])) {
            $hk = $this->classes[$hcls]->propHooks[$n->property];
            if ($hk['set'] !== '' && !$this->insideOwnHook($hk)) {
                return $this->emitHookSet($n->object, $hk['set'], $n->value);
            }
        }
        // Dynamic property on a bag class → set the boxed value in the
        // property-bag assoc, threading any realloc back to the slot.
        $bcls = $n->object->type->class ?? '';
        if ($bcls !== '' && isset($this->classes[$bcls])
            && $this->classes[$bcls]->usesBag()
            && $this->classes[$bcls]->propertyOffset($n->property) === -1) {
            $bcd = $this->classes[$bcls];
            $out = $this->emitNode($n->object);
            $out .= $this->coerceToPtr();
            $objPtr = $this->lastValue;
            $bg = $this->ssa->allocReg();
            $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$bcd->bagOffset() . "\n";
            $bagI = $this->ssa->allocReg();
            $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
            $bagP = $this->ssa->allocReg();
            $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
            $out .= $this->emitBagStoreValue($n->value);
            $val = $this->lastValue;
            $kid = $this->pool->intern($n->property);
            $nb = $this->ssa->allocReg();
                        $out .= '  ' . $nb . ' = call ptr @__mir_array_set_str(ptr ' . $bagP
                  . ', ptr ' . $this->strLitId($kid) . ', i64 ' . $val . ", i64 0, i64 0)\n";
            $nbI = $this->ssa->allocReg();
            $out .= '  ' . $nbI . ' = ptrtoint ptr ' . $nb . " to i64\n";
            $out .= '  store i64 ' . $nbI . ', ptr ' . $bg . "\n";
            $this->lastValue = $val;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Property overloading: writing an undeclared property on a class that
        // defines __set routes through `$obj->__set('name', $value)`.
        $scls = $n->object->type->class ?? '';
        if ($scls !== '' && isset($this->classes[$scls])
            && $this->classes[$scls]->propertyOffset($n->property) === -1) {
            $setCls = $this->resolveMethodClass($scls, '__set');
            if ($setCls !== '') {
                $out = $this->emitNode($n->object);
                $out .= $this->coerceToPtr();
                $objPtr = $this->lastValue;
                $out .= $this->emitNode($n->value);
                $out .= $this->boxToCell($n->value->type, $n->value);
                $val = $this->lastValue;
                $out .= $this->emitMagicCall($setCls, '__set', $objPtr, $n->property, $val);
                $this->lastValue = $val;
                $this->lastValueType = 'i64';
                return $out;
            }
        }
        // The class is KNOWN but puts `$prop` nowhere: a static offset would be
        // the slot-16 default, i.e. a blind write into the first field of
        // whatever object actually arrives. The class_id dispatch above is the
        // honest answer, and it is the same one the classless receiver takes.
        // Mirrors the READ side in {@see emitPropertyAccess}; a closure rebound
        // by `Closure::bind` to a foreign scope is what makes this reachable.
        if ($this->propertyOffsetOrNull($n->object, $n->property) === null) {
            return $this->emitCellStoreProperty($n);
        }
        // Amortized `$this->s .= …` — the property analogue of the local
        // self-append in {@see EmitLlvmLocals::emitStoreLocal}, and worth as much:
        // without it a string accumulated into a property is a fresh O(n) concat
        // per append, i.e. QUADRATIC. Measured on 40k×64B appends: 9215 ms here
        // against php's 2380 ms, while the same loop over a LOCAL was 0 ms. Every
        // class that builds a string — serializer, template, response body,
        // ByteBuffer — sits on this shape.
        $self = $this->selfAppendProperty($n);
        if ($self !== null) {
            return $self;
        }
        $out = $this->emitNode($n->object);
        $out .= $this->coerceToPtr();
        $objPtr = $this->lastValue;
        // The object now owns a second reference to a vec / obj value.
        // When the stored value's own type is erased (a bare-`array`
        // param lowers to unknown), fall back to the property's declared
        // type so a vec/assoc/obj property still co-owns the buffer —
        // otherwise the source local's scope-exit release frees it.
        $pcls = $n->object->type->class ?? '';
        $propType = ($pcls !== '' && isset($this->classes[$pcls]))
            ? ($this->classes[$pcls]->propertyTypes[$n->property] ?? null)
            : null;
        $out .= $this->emitNode($n->value);
        // A self-describing cell property (scalar-nullable OR a `mixed` prop
        // whose every store boxes in place) NaN-boxes the value so the slot is
        // tag-dispatchable (var_dump / `=== null`, null distinct from 0). An
        // rc-managed payload (string/object) is retained on the RAW pointer
        // BEFORE boxing — a tagged cell would mis-locate the rc header. A cell
        // -array backing slot keeps the raw store + rc co-own.
        // An assignment is an EXPRESSION, and its value is the value ASSIGNED —
        // not the slot's storage encoding. `$res` therefore tracks the value in
        // the repr `$n->type` promises (the RHS type), while `$val` is what the
        // slot receives. They diverge exactly when the slot boxes: returning the
        // boxed word left `$v = ($o->mixedProp = 'x')` handing a NaN-tagged
        // pointer to a consumer typed `string`, which inttoptr'd and dereferenced
        // it. Same invariant emitStoreLocal keeps.
        if ($this->cellPropBoxed($propType, $pcls, $n->property)) {
            $vk = $n->value->type->kind;
            if ($vk === Type::KIND_CELL) {
                // Already a boxed cell — store as-is (repr already agrees).
                $out .= $this->coerceToI64();
                $val = $this->lastValue;
                $res = $val;
                $resTy = 'i64';
            } elseif ($vk === Type::KIND_STRING || $vk === Type::KIND_OBJ) {
                // rc-managed payload (string/object) — retain the RAW ptr before
                // boxing (a tagged cell would mis-locate the rc header).
                $out .= $this->coerceToI64();
                $raw = $this->lastValue;
                $out .= $this->rcRetainByType($n->value, $raw, $propType, 4);
                $this->lastValue = $raw;
                $this->lastValueType = 'i64';
                $out .= $this->boxToCell($n->value->type, $n->value);
                $val = $this->lastValue;
                $res = $raw;
                $resTy = 'i64';
            } else {
                // Non-rc scalar (int/float/bool/null) — box, no retain. The
                // pre-box register is captured in its NATURAL repr (a float is
                // still a double here; coercing first would hand box_float an
                // integer bit pattern).
                $res = $this->lastValue;
                $resTy = $this->lastValueType;
                $out .= $this->boxToCell($n->value->type, $n->value);
                $val = $this->lastValue;
            }
        } else {
            // A CELL value stored into a CONCRETE-typed property must be
            // UNBOXED, or the tagged bits land in the slot and read back as
            // nonsense: `private int $n` assigned from an `int|object` union
            // parameter returned -4222124650659837 instead of 3. This is the
            // property-slot analogue of the cell unboxing already done on the
            // `return` path and per-element in emitStoreElementUnified;
            // unboxCellToType no-ops for a non-concrete target.
            if ($n->value->type->kind === Type::KIND_CELL) {
                if ($this->slotIsArrayHinted($n->object, $n->property, $propType)) {
                    // A bare `array` hint erases to KIND_UNKNOWN (LowerTypes has no
                    // branch for it), so unboxCellToType would no-op and the TAGGED
                    // word would land in a slot every reader treats as a raw
                    // pointer — `$this->aliases = is_array($a) ? $a : $list;` with
                    // an `iterable` param stored a cell, and the getter's caller
                    // inttoptr'd 0xFFF7… straight into the array runtime. Strip to
                    // the payload, the same repr `clone` already assumes for an
                    // array-hinted slot.
                    $out .= $this->unboxCellToType(Type::vec(Type::unknown()));
                } elseif ($propType !== null) {
                    $out .= $this->unboxCellToType($propType);
                }
            }
            $out .= $this->coerceToI64();
            $val = $this->lastValue;
            $out .= $this->rcRetainByType($n->value, $val, $this->propStoreRetainType($n), 4);
            $res = $val;
            $resTy = 'i64';
        }
        $offset = $this->propertyOffset($n->object, $n->property);
        $gep = $this->ssa->allocReg();
        $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr '
              . $objPtr . ', i64 ' . (string)$offset . "\n";
        $out .= $this->emitSlotStore(
            $gep,
            $this->slotHolder($n->object, $n->property),
            $n->property,
            $val,
        );
        $this->lastValue = $res;
        $this->lastValueType = $resTy;
        return $out;
    }

    /**
     * IR for `$o->s = $o->s . rhs` as an in-place amortized append, or null when
     * the store is not that shape.
     *
     * The property twin of {@see EmitLlvmLocals::emitSelfAppend}, sharing its
     * helper and its ownership contract: `__mir_str_append` mutates in place when
     * the slot is sole owner with spare capacity, else grows and RELEASES the old
     * buffer — so this path deliberately emits no release-before-overwrite and no
     * retain. The slot holds the object's single reference, which is exactly the
     * rc==1 the in-place path needs.
     *
     * The gates are narrow on purpose:
     *   - the receiver must be the SAME plain local on both sides, so the object
     *     is evaluated once and the read and the write cannot address different
     *     objects (`$a->s = $b->s . x` is not an append);
     *   - the slot must be a plain full-width string, never a boxed cell property
     *     (a tagged word is not a string pointer) and never a narrowed slot;
     *   - the property must have a real offset — a dynamic/bag property has
     *     already been routed above, and the offset fallback would blind-write
     *     slot 16.
     * Anything else falls through to the general store, which is merely slower.
     */
    private function selfAppendProperty(\Compile\Mir\StoreProperty $n): ?string
    {
        $sv = $n->value;
        if ($sv->kind !== Node::KIND_CONCAT || $sv->type->kind !== Type::KIND_STRING) {
            return null;
        }
        if ($n->object->kind !== Node::KIND_LOAD_LOCAL) {
            return null;
        }
        $cls = $n->object->type->class ?? '';
        if ($cls === '' || !isset($this->classes[$cls])) { return null; }
        $cd = $this->classes[$cls];
        if ($cd->propertyOffset($n->property) < 0) { return null; }
        if ($cd->propertyWidth($n->property) !== 8) { return null; }
        $propType = $cd->propertyTypes[$n->property] ?? null;
        if ($propType === null || $propType->kind !== Type::KIND_STRING) { return null; }
        if ($this->cellPropBoxed($propType, $cls, $n->property)) { return null; }

        $ops = [];
        $this->flattenConcat($sv, $ops);
        $ops = $this->mergeAdjacentStrConsts($ops);
        if (\count($ops) < 2) { return null; }
        $op0 = $ops[0];
        if ($op0->kind !== Node::KIND_PROPERTY_ACCESS
            || $op0->property !== $n->property
            || $op0->type->kind !== Type::KIND_STRING
            || $op0->object->kind !== Node::KIND_LOAD_LOCAL
            || $op0->object->name !== $n->object->name) {
            return null;
        }
        // Rebuild `a . b . …` as ONE right-hand operand, exactly as the local
        // path does, so a multi-way self-append still takes the fast route.
        $rest = $ops[1];
        $k = \count($ops);
        for ($j = 2; $j < $k; $j = $j + 1) {
            $rest = new \Compile\Mir\Concat($rest, $ops[$j]);
        }

        $this->rt->needsStrAppend = true;
        $this->rt->needsStrRc = true;
        $this->rt->needsConcat = true;
        $out = $this->emitNode($n->object);
        $out .= $this->coerceToPtr();
        $objPtr = $this->lastValue;
        // The rhs is evaluated BEFORE the slot is loaded: it may itself touch the
        // property (`$this->s = $this->s . $this->flush()`), and the append must
        // see the slot as that left behind it.
        $out .= $this->emitNode($rest);
        $out .= $this->coerceToStr($rest, false);
        $rp = $this->lastValue;
        $gep = $this->ssa->allocReg();
        $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr
              . ', i64 ' . (string)$cd->propertyOffset($n->property) . "\n";
        $curI = $this->ssa->allocReg();
        $out .= '  ' . $curI . ' = load i64, ptr ' . $gep . "\n";
        $curP = $this->ssa->allocReg();
        $out .= '  ' . $curP . ' = inttoptr i64 ' . $curI . " to ptr\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_append(ptr ' . $curP
              . ', ptr ' . $rp . ")\n";
        $out .= $this->concatTempRelease($rest, $rp);
        $ri = $this->ssa->allocReg();
        $out .= '  ' . $ri . ' = ptrtoint ptr ' . $reg . " to i64\n";
        $out .= '  store i64 ' . $ri . ', ptr ' . $gep . "\n";
        $this->lastValue = $ri;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Whether `$def` renders as a LINK-TIME constant, i.e. whether
     * {@see globalInit} can express it. Anything else (an array literal
     * default — `public static array $xs = []` — the only non-constant a
     * global cell can carry) falls back to `0` there and MUST instead be
     * built at run time by {@see emitGlobalRuntimeInits}. Without that the
     * cell stays 0 and the first `Class::$xs[] = v` appends onto a NULL
     * array pointer (SIGSEGV), while a read renders `int(0)`, not `[]`.
     */
    private function globalInitIsConst(Node $def): bool
    {
        $k = $def->kind;
        return $k === Node::KIND_INT_CONST || $k === Node::KIND_BOOL_CONST
            || $k === Node::KIND_STRING_CONST || $k === Node::KIND_FLOAT_CONST;
    }

    /**
     * Constant initialiser text for a module global cell. Cells are
     * uniform i64; string defaults become a `ptrtoint` constexpr of
     * an interned `@.str.N`, floats the raw 64-bit pattern.
     */
    private function globalInit(Node $def): string
    {
        $k = $def->kind;
        if ($k === Node::KIND_INT_CONST)  { return (string)$def->value; }
        if ($k === Node::KIND_BOOL_CONST) { return $def->value ? '1' : '0'; }
        if ($k === Node::KIND_STRING_CONST) {
            $id = $this->pool->intern($def->value);
            return 'ptrtoint (ptr ' . $this->strLitId($id) . ' to i64)';
        }
        if ($k === Node::KIND_FLOAT_CONST) {
            return 'bitcast (double ' . $this->formatFloat($def->value) . ' to i64)';
        }
        return '0';
    }

    private function emitStaticLocalDecl(\Compile\Mir\StaticLocalDecl_ $n): string
    {
        if ($n->guard === '' || $n->init === null) {
            return '';
        }
        // Once-init guard: `if (guard == 0) { cell = init; guard = 1; }`.
        $g = $this->ssa->allocReg();
        $cond = $this->ssa->allocReg();
        $doLbl = $this->ssa->allocLabel('slinit');
        $skipLbl = $this->ssa->allocLabel('slskip');
        $out = '  ' . $g . ' = load i64, ptr ' . $n->guard . "\n";
        $out .= '  ' . $cond . ' = icmp eq i64 ' . $g . ", 0\n";
        $out .= '  br i1 ' . $cond . ', label %' . $doLbl . ', label %' . $skipLbl . "\n";
        $out .= $doLbl . ":\n";
        $out .= $this->emitNode($n->init);
        $out .= $this->coerceToI64();
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $n->cell . "\n";
        $out .= '  store i64 1, ptr ' . $n->guard . "\n";
        $out .= '  br label %' . $skipLbl . "\n";
        $out .= $skipLbl . ":\n";
        return $out;
    }


    /** `$y = &$x` — point the target's slot at the source's slot. */
    private function emitRefAlias(RefAlias_ $n): string
    {
        if (isset($this->locals->globalBacked[$n->source])) {
            // The source is not a stack slot at all: a `global $x` name — and a
            // SUPERGLOBAL is only an implicit one — is backed by a module cell
            // ({@see LocalSlots::$globalBacked}), which both Load and Store
            // consult AHEAD of `$slots`. Aliasing therefore means naming the
            // same cell; matching on `$slots` alone left the target owning its
            // own alloca, so every write through the alias was invisible to the
            // global and to every other scope sharing it.
            // `$session = &$_SESSION;` (symfony NativeSessionStorage) is this.
            $this->locals->globalBacked[$n->target] = $this->locals->globalBacked[$n->source];
        } elseif (isset($this->locals->slots[$n->source])) {
            // Remember what the target owned, so `unset($target)` can hand it
            // back instead of zeroing the slot both names now share.
            $this->locals->aliasLocals[$n->target] = $this->locals->slots[$n->target] ?? '';
            $this->locals->slots[$n->target] = $this->locals->slots[$n->source];
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return '';
    }

    /** `$r = &fn(...)` — store the by-ref return address into $r's slot. */
    private function emitRefBind(RefBind_ $n): string
    {
        if (!isset($this->locals->slots[$n->target])) {
            $slot = $this->ssa->allocReg();
            $this->locals->slots[$n->target] = $slot;
            $out = '  ' . $slot . " = alloca i64\n";
        } else {
            $out = '';
        }
        $this->rawRefCall = true;
        $out .= $this->emitNode($n->call);
        $this->rawRefCall = false;
        $out .= $this->coerceToI64();
        $addr = $this->lastValue;
        $out .= '  store i64 ' . $addr . ', ptr ' . $this->locals->slots[$n->target] . "\n";
        $this->locals->refLocals[$n->target] = true;
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** `$r = &$obj->prop` / `$r = &$a[$k]` — store the container slot's ADDRESS
     *  into $r's slot and mark $r a ref local, so later reads/writes of $r deref
     *  the address (aliasing the property / element). Falls back to a value copy
     *  when the lvalue is not addressable (unknown class / non-vec element). */
    private function emitRefAddr(RefAddr_ $n): string
    {
        if (!isset($this->locals->slots[$n->target])) {
            $slot = $this->ssa->allocReg();
            $this->locals->slots[$n->target] = $slot;
            $out = '  ' . $slot . " = alloca i64\n";
        } else {
            $out = '';
        }
        $addrIr = $this->byRefAddrOf($n->lvalue);
        if ($addrIr === null) {
            // Not addressable — degrade to a value copy (non-crashing).
            $out .= $this->emitNode($n->lvalue);
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->locals->slots[$n->target] . "\n";
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $addrIr;
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->locals->slots[$n->target] . "\n";
        $this->locals->refLocals[$n->target] = true;
        // The TARGET's type, not just its address. A store through a reference
        // has to match what the aliased slot's own reader expects, and the store
        // path already knows how to do that — it just keyed off refParamTypes,
        // which until now only a by-ref PARAMETER filled in. Without this a
        // reference to a cell slot (`public static mixed $x`) wrote a raw word
        // into a self-describing slot and the next tag-decoding read saw a
        // denormal. The address was never the hard part.
        $this->locals->refParamTypes[$n->target] = $n->lvalue->type;
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `&<lvalue>` as a VALUE — a cell tagged {@see \Compile\MemoryAbi::
     * CELL_TAG_REF} whose payload is the address of the reference's box.
     *
     * The create seam is small because the address is not the hard part and
     * never was ({@see emitRefAddr}'s own lesson): {@see byRefAddrOf} already
     * answers it for a local, a property, a static property and an element, and
     * a promoted local's slot already holds its BOX address rather than its
     * value, so the same arm yields the box. All this adds is the tag.
     *
     * A source that is not addressable is REFUSED rather than degraded. A silent
     * value copy is what `[&$a, &$b]` did before it was made an error, and every
     * write through the reference would be lost — see docs/design/
     * reference-cells.md and finding `parser-ref-in-array-literal`.
     */
    private function emitRefCell(\Compile\Mir\RefCell_ $n): string
    {
        // ⚠ Via Walk, not `$n->refSource`: this file is a TRAIT, and a field read
        // narrowed inside a trait does not resolve to the right offset natively.
        // See the note in EmitLlvmLocals::preallocateLocals.
        $rcKids = \Compile\Mir\Walk::children($n);
        $addrIr = $this->byRefAddrOf($rcKids[0]);
        if ($addrIr === null) {
            throw new \RuntimeException(
                'unsupported: cannot take a storable reference to this expression '
                . '— it has no address, so the reference would be a copy and every '
                . 'write through it would be lost'
            );
        }
        $out = $addrIr;
        $this->rt->needsRefCells = true;
        $m = $this->ssa->allocReg();
        $out .= '  ' . $m . ' = and i64 ' . $this->lastValue . ', '
              . (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = or i64 ' . $m . ', '
              . (string)\Compile\MemoryAbi::CELL_REF_TAG_BITS . "\n";
        $this->lastValue = $c;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Bag byte offset for an object node's class (stdClass default). */
    private function bagOffsetOf(Node $obj): int
    {
        $cls = $obj->type->class ?? '';
        if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->usesBag()) {
            return $this->classes[$cls]->bagOffset();
        }
        $std = $this->classes['stdClass'] ?? null;
        return $std === null ? 16 : $std->bagOffset();
    }

    /**
     * Emit `$obj` and leave its INSTANCE POINTER in lastValue. A CELL receiver
     * carries a NaN-boxed word, so it must have the tag stripped — a bare
     * `coerceToPtr` inttoptr's the tagged value and every offset computed from
     * it (a bag slot, a class_id load) lands in the weeds.
     *
     * The one owner of "object node → pointer"; {@see emitCellStoreProperty} had
     * the only correct copy of this and the two dyn-property dispatch fallbacks
     * did not, which made `$o->$k = $v` on a `mixed` stdClass a wild write.
     */
    private function emitObjPtrOf(Node $obj): string
    {
        $out = $this->emitNode($obj);
        $k = $obj->type->kind;
        if ($k === Type::KIND_CELL || $k === Type::KIND_UNKNOWN) {
            return $out . $this->cellToPtr();
        }
        return $out . $this->coerceToPtr();
    }

    /** Emit the object → bag-assoc ptr; leaves bag ptr + slot gep regs. */
    private function emitBagPtr(Node $objNode, string $objPtr, int $bagOff): string
    {
        $bg = $this->ssa->allocReg();
        $out = '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $objPtr
             . ', i64 ' . (string)$bagOff . "\n";
        $bagI = $this->ssa->allocReg();
        $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
        $bagP = $this->ssa->allocReg();
        $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
        $this->bagSlotReg = $bg;
        $this->bagPtrReg = $bagP;
        return $out;
    }

    /** `$o->$name` — read the boxed value from the bag by runtime key. */
    private function emitDynProp(DynProp_ $n): string
    {
        // A statically-typed receiver: `$o->$name` reads a DECLARED property when
        // the runtime name matches one (PHP checks declared slots before the
        // dynamic bag). Match the name against each declared property's offset,
        // then fall back to the bag / null.
        $cls = $n->object->type->class ?? '';
        if ($cls !== '' && isset($this->classes[$cls])) {
            $cd = $this->classes[$cls];
            return $this->emitDynPropDispatch($n, $this->declaredPropTypes($cd), $cd);
        }
        // A UNION receiver (`new $cls()` over several classes): gather the declared
        // properties across the atoms; each arm's PropertyAccess_ dispatches on the
        // runtime class_id (emitUnionPropertyAccess).
        if ($n->object->type->kind === Type::KIND_UNION) {
            $props = $this->unionPropTypes($n->object->type);
            if ($props !== []) { return $this->emitDynPropDispatch($n, $props, null); }
        }
        // Classless / erased receiver: match the runtime name against EVERY class's
        // declared properties; each arm's PropertyAccess_ dispatches on the object's
        // class_id (emitCellPropertyRead / emitRawPropByClassId, both boxing to a
        // cell), with a stdClass bag fallback for an undeclared name. Replaces the
        // old blind by-name bag read, which rendered a fixed-slot property's raw
        // pointer as an int.
        $bagCd = $this->classes['stdClass'] ?? null;
        return $this->emitDynPropDispatch($n, $this->allDeclaredPropTypes(), $bagCd);
    }

    /** Declared properties (offset >= 0) of a class as name => Type.
     *  @return array<string, Type> */
    private function declaredPropTypes(ClassDef $cd): array
    {
        $out = [];
        foreach ($cd->propertyNames as $p) {
            if ($cd->propertyOffset($p) < 0) { continue; }
            $out[$p] = $cd->propertyTypes[$p] ?? Type::cell();
        }
        return $out;
    }

    /** Declared properties across a union's atoms, name => Type. Atoms that
     *  disagree on a property's kind collapse it to a cell.
     *  @return array<string, Type> */
    private function unionPropTypes(Type $u): array
    {
        $out = [];
        foreach ($u->atoms as $atom) {
            $cn = $atom->class ?? '';
            if ($cn === '' || !isset($this->classes[$cn])) { continue; }
            foreach ($this->declaredPropTypes($this->classes[$cn]) as $p => $pt) {
                if (!isset($out[$p])) { $out[$p] = $pt; }
                elseif ($out[$p]->kind !== $pt->kind) { $out[$p] = Type::cell(); }
            }
        }
        return $out;
    }

    /** `$o->$name` on a receiver with a known member set (a class or a union):
     *  strcmp the runtime name against each declared property and reuse the full
     *  property-read path (hooks, subclass offsets, float/obj coercion, union
     *  class_id dispatch) for the match, boxing its result to a cell; otherwise
     *  fall back to the dynamic bag ($bagCd) or null. One arm runs, so re-emitting
     *  the object per arm evaluates it once at runtime.
     *  @param array<string, Type> $propTypes */
    private function emitDynPropDispatch(DynProp_ $n, array $propTypes, ?ClassDef $bagCd): string
    {
        $this->rt->needsStrcmp = true;
        $out = $this->emitDynMemberKey($n->name);
        $keyP = $this->lastValue;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $endL = $this->ssa->allocLabel('dynp.end');
        foreach ($propTypes as $p => $pt) {
            $hitL = $this->ssa->allocLabel('dynp.hit');
            $nextL = $this->ssa->allocLabel('dynp.next');
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = call i32 @strcmp(ptr ' . $keyP . ', ptr ' . $this->litStr($p) . ")\n";
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i32 ' . $cmp . ", 0\n";
            $out .= '  br i1 ' . $eq . ', label %' . $hitL . ', label %' . $nextL . "\n";
            $out .= $hitL . ":\n";
            $pa = new PropertyAccess_($n->object, $p, $pt);
            $out .= $this->emitPropertyAccess($pa);
            // A CELL / UNKNOWN receiver's read (emitCellPropertyRead /
            // emitRawPropByClassId) ALREADY yields a boxed cell; a typed or union
            // receiver's read yields the raw slot value that must be boxed here.
            // Boxing an already-boxed cell double-boxes (an int cell re-read raw).
            $rk = $n->object->type->kind;
            if ($rk !== Type::KIND_CELL && $rk !== Type::KIND_UNKNOWN) {
                $out .= $this->boxToCell($pt);
            }
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $endL . "\n";
            $out .= $nextL . ":\n";
        }
        if ($bagCd !== null && $bagCd->usesBag()) {
            $out .= $this->emitObjPtrOf($n->object);
            $objPtr = $this->lastValue;
            $out .= $this->emitBagPtr($n->object, $objPtr, $bagCd->bagOffset());
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 @__mir_array_get_str(ptr ' . $this->bagPtrReg
                  . ', ptr ' . $keyP . ", i64 0, i64 0)\n";
            $out .= '  store i64 ' . $reg . ', ptr ' . $res . "\n";
        }
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** `$o->$m(args)` on a known-class receiver: strcmp the runtime name against
     *  each method the class declares/inherits and reuse the full method-call
     *  path for the match (arg boxing, virtual dispatch, LSB), boxing the result
     *  to a cell; an undeclared name falls back to __call, else null. One arm
     *  runs, so re-emitting receiver/args per arm evaluates each once. */
    /** Method names dispatchable on a receiver type — a single class (self +
     *  ancestors) or a union (across the atoms) — as name => return Type.
     *  Atoms disagreeing on a method's return kind collapse it to a cell.
     *  Null when the receiver has no static class set (cell / unknown). */
    private function dynMethodCandidates(Type $t, int $argc): ?array
    {
        $roots = [];
        $single = $t->class ?? '';
        if ($single !== '' && isset($this->classes[$single])) {
            $roots = [$single];
        } elseif ($t->kind === Type::KIND_UNION) {
            foreach ($t->atoms as $atom) {
                $cn = $atom->class ?? '';
                if ($cn !== '' && isset($this->classes[$cn])) { $roots[] = $cn; }
            }
        }
        if ($roots === []) { return $this->classlessMethodCandidates($argc); }
        $out = [];
        foreach ($roots as $root) {
            $c = $root;
            while ($c !== '' && isset($this->classes[$c])) {
                foreach ($this->classes[$c]->methodNames as $m => $_) {
                    if ($m === '__construct' || $m === '__call') { continue; }
                    $holder = $this->resolveMethodClass($root, $m);
                    if ($holder === '') { continue; }
                    if (!$this->methodTakesArgc($holder, $m, $argc)) { continue; }
                    $rt = $this->sigs->returnType[$holder . '__' . $m] ?? Type::cell();
                    if (!isset($out[$m])) { $out[$m] = $rt; }
                    elseif ($out[$m]->kind !== $rt->kind) { $out[$m] = Type::cell(); }
                }
                $c = $this->classes[$c]->parent;
            }
        }
        return $out;
    }

    /** Methods dispatchable on a CLASSLESS (cell / unknown) receiver: every
     *  method across all classes, as name => return Type. Implementers that
     *  disagree on the return kind collapse to a cell — the emit-side dispatch
     *  boxes each arm's return by its own type (emitMethodCall's per-arm boxing
     *  when the result is cell), so the merged value is a uniform cell. */
    private function classlessMethodCandidates(int $argc): array
    {
        $out = [];
        foreach ($this->classes as $cd) {
            foreach ($cd->methodNames as $m => $_) {
                if ($m === '__construct' || $m === '__call') { continue; }
                $holder = $this->resolveMethodClass($cd->name, $m);
                if ($holder === '') { continue; }
                if (!$this->methodTakesArgc($holder, $m, $argc)) { continue; }
                $rt = $this->sigs->returnType[$holder . '__' . $m] ?? Type::cell();
                if (!isset($out[$m])) { $out[$m] = $rt; }
                elseif ($out[$m]->kind !== $rt->kind) { $out[$m] = Type::cell(); }
            }
        }
        return $out;
    }

    /**
     * Can `$holder::$m` be called with exactly `$argc` arguments?
     *
     * A dynamic name is dispatched by an inline `strcmp` chain, one arm per
     * candidate NAME, and on an erased receiver the candidate set is every
     * method of every class — 312 arms and 272 KB of IR for `prelude/ob.php`'s
     * 15-line `__mc_ob_call`, in a module with 99 classes. Arity is the one
     * thing the call site knows for certain about the target, and it cuts that
     * set hard: an ob handler is invoked with two arguments, so no zero-arg
     * accessor can ever be what `$o->$m($buf, $phase)` reaches.
     *
     * ⚠ This is a real semantic edge, not only a size fix. php throws
     * ArgumentCountError when a method is called with too few arguments; the
     * dispatch's default arm answers null. Dropping the arm moves the wrong
     * answer from one shape to the other and neither matches Zend — the fix for
     * that is a throw in the default arm, which is a separate change.
     *
     * Fails OPEN: a method with no recorded meta (an interface method, an
     * imported class) keeps its arm — and so does an argc of -1, the call site
     * saying it does not know ({@see siteArgc}).
     */
    private function methodTakesArgc(string $holder, string $m, int $argc): bool
    {
        if ($argc < 0) { return true; }
        $hd = $this->classes[$holder] ?? null;
        if ($hd === null) { return true; }
        if (!isset($hd->methodMeta[$m])) { return true; }
        $mm = $this->asMethodMeta($hd->methodMeta[$m]);
        $total = 0;
        foreach ($mm->params as $pm) {
            $p = $this->asParamMeta($pm);
            if ($p->variadic) { return $argc >= $mm->requiredParams(); }
            $total = $total + 1;
        }
        return $argc >= $mm->requiredParams() && $argc <= $total;
    }

    /**
     * How many arguments a call site passes, or -1 when it cannot be known
     * statically. A `...$arr` argument is ONE node carrying a RUN-TIME number of
     * values, so counting nodes answers a different question than the arity
     * filter asks — `$o->$m(...$args)` counted as one argument and every 0-arg
     * and 2+-arg candidate lost its arm, leaving the default to answer null in
     * silence (`__mc_call_shutdown`, so a `[$obj,'close']` shutdown callback
     * simply never ran).
     *
     * @param Node[] $args
     */
    private function siteArgc(array $args): int
    {
        foreach ($args as $a) {
            if ($a->kind === Node::KIND_SPREAD) { return -1; }
        }
        return \count($args);
    }

    /** Read a MethodMeta through a TYPED local: a field read off an untyped
     *  slot resolves the wrong offset under the self-host. */
    private function asMethodMeta(\Compile\Mir\MethodMeta $mm): \Compile\Mir\MethodMeta
    {
        return $mm;
    }

    /** {@see asMethodMeta} — same reason. */
    private function asParamMeta(\Compile\Mir\ParamMeta $p): \Compile\Mir\ParamMeta
    {
        return $p;
    }

    /**
     * The runtime NAME of a dynamic member (`$o->$m`, `$o->$m()`, `$o->$m = v`)
     * as a `ptr` fit for strcmp. lastValue ← ptr.
     *
     * A name read out of an erased slot — `$cb = [$obj, "hi"]; $m = $cb[1];` —
     * is a NaN-boxed CELL, and coerceToPtr is a bare inttoptr: strcmp then
     * dereferenced the tag bits and the program segfaulted. Mask the payload out
     * whenever the static type is not already a concrete string. Masking is
     * identity for a raw heap pointer (user-space VAs fit in the payload bits),
     * so the erased/unknown case is safe from both directions.
     */
    private function emitDynMemberKey(Node $name): string
    {
        $out = $this->emitNode($name);
        $out .= $name->type->kind === Type::KIND_STRING
            ? $this->coerceToPtr() : $this->cellToPtr();
        return $out;
    }

    private function emitDynMethodCall(\Compile\Mir\DynProp_ $dp, \Compile\Mir\Invoke_ $iv): string
    {
        $recv = $dp->object;
        $nameNode = $dp->name;
        $methods = $this->dynMethodCandidates($recv->type, $this->siteArgc($iv->args));
        if ($methods === null) {
            // Erased receiver (cell/unknown): no static class set to enumerate.
            // Evaluate the name for side effects and yield null (open case).
            $out = $this->emitNode($nameNode);
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return $out;
        }
        $this->rt->needsStrcmp = true;
        $out = $this->emitDynMemberKey($nameNode);
        $keyP = $this->lastValue;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $endL = $this->ssa->allocLabel('dynm.end');
        foreach ($methods as $m => $retT) {
            $hitL = $this->ssa->allocLabel('dynm.hit');
            $nextL = $this->ssa->allocLabel('dynm.next');
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = call i32 @strcmp(ptr ' . $keyP . ', ptr ' . $this->litStr($m) . ")\n";
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i32 ' . $cmp . ", 0\n";
            $out .= '  br i1 ' . $eq . ', label %' . $hitL . ', label %' . $nextL . "\n";
            $out .= $hitL . ":\n";
            // $recv keeps its type (single class OR union), so emitMethodCall
            // dispatches monomorphically or via the runtime class_id switch.
            $call = new \Compile\Mir\MethodCall_($recv, $m, $iv->args, $retT);
            $out .= $this->emitMethodCall($call);
            $out .= $this->boxToCell($retT);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $endL . "\n";
            $out .= $nextL . ":\n";
        }
        // Undeclared method: __call('name', [args]) — single-class receiver only.
        $cls = $recv->type->class ?? '';
        if ($cls !== '' && isset($this->classes[$cls])
            && $this->resolveMethodClass($cls, '__call') !== '') {
            $elems = [];
            foreach ($iv->args as $a) { $elems[] = new \Compile\Mir\ArrayElement_(null, $a); }
            $argsArr = new \Compile\Mir\ArrayLit($elems, Type::vec(Type::cell()));
            $call = new \Compile\Mir\MethodCall_($recv, '__call', [$nameNode, $argsArr], Type::cell());
            $out .= $this->emitMethodCall($call);
            $out .= $this->boxToCell(Type::cell());
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        }
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** `$o->$name = v` — set the boxed value in the bag by runtime key. */
    private function emitStoreDynProp(StoreDynProp_ $n): string
    {
        // Typed receiver: a runtime name matching a DECLARED property writes that
        // slot (with the full store semantics — readonly guard, rc retain, float
        // truncation, set hooks), falling back to the bag for an undeclared name.
        $cls = $n->object->type->class ?? '';
        if ($cls !== '' && isset($this->classes[$cls])) {
            $cd = $this->classes[$cls];
            return $this->emitStoreDynPropDispatch($n, $this->declaredPropTypes($cd), $cd);
        }
        // A UNION receiver: match the name against the atoms' declared properties;
        // each arm's StoreProperty_ dispatches the store on the runtime class_id
        // (emitUnionStoreProperty). WITHOUT this the classless bag path below reads
        // a scalar prop slot as a bag pointer → a wild write (segfault).
        if ($n->object->type->kind === Type::KIND_UNION) {
            $props = $this->unionPropTypes($n->object->type);
            if ($props !== []) { return $this->emitStoreDynPropDispatch($n, $props, null); }
        }
        // Classless / erased receiver: match the runtime name against EVERY class's
        // declared properties; each arm's StoreProperty dispatches on the object's
        // class_id (emitCellStoreProperty) to the real slot, with a stdClass bag
        // fallback for an undeclared name. WITHOUT this the removed bag path below
        // wrote slot 16 as a bag pointer on a fixed-slot object → a wild write.
        $bagCd = $this->classes['stdClass'] ?? null;
        return $this->emitStoreDynPropDispatch($n, $this->allDeclaredPropTypes(), $bagCd);
    }

    /** `$o->$name = v` on a receiver with a known member set (a class or a union):
     *  strcmp the runtime name against each declared property and reuse the full
     *  property-store path (readonly guard, rc retain, float truncation, set
     *  hooks, union class_id dispatch) for the match; otherwise fall back to the
     *  dynamic bag ($bagCd) or drop. Only one arm runs, so re-emitting object/value
     *  per arm evaluates each exactly once at runtime.
     *  @param array<string, Type> $propTypes */
    private function emitStoreDynPropDispatch(StoreDynProp_ $n, array $propTypes, ?ClassDef $bagCd): string
    {
        $this->rt->needsStrcmp = true;
        $out = $this->emitDynMemberKey($n->name);
        $keyP = $this->lastValue;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $endL = $this->ssa->allocLabel('dynsp.end');
        foreach ($propTypes as $p => $pt) {
            $hitL = $this->ssa->allocLabel('dynsp.hit');
            $nextL = $this->ssa->allocLabel('dynsp.next');
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = call i32 @strcmp(ptr ' . $keyP . ', ptr ' . $this->litStr($p) . ")\n";
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i32 ' . $cmp . ", 0\n";
            $out .= '  br i1 ' . $eq . ', label %' . $hitL . ', label %' . $nextL . "\n";
            $out .= $hitL . ":\n";
            $sp = new \Compile\Mir\StoreProperty($n->object, $p, $n->value, $pt);
            $out .= $this->emitStoreProperty($sp);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $endL . "\n";
            $out .= $nextL . ":\n";
        }
        if ($bagCd !== null && $bagCd->usesBag()) {
            $out .= $this->emitObjPtrOf($n->object);
            $objPtr = $this->lastValue;
            $out .= $this->emitBagPtr($n->object, $objPtr, $bagCd->bagOffset());
            $bagP = $this->bagPtrReg;
            $bg = $this->bagSlotReg;
            $out .= $this->emitBagStoreValue($n->value);
            $val = $this->lastValue;
            $nb = $this->ssa->allocReg();
            $out .= '  ' . $nb . ' = call ptr @__mir_array_set_str(ptr ' . $bagP
                  . ', ptr ' . $keyP . ', i64 ' . $val . ", i64 0, i64 0)\n";
            $nbI = $this->ssa->allocReg();
            $out .= '  ' . $nbI . ' = ptrtoint ptr ' . $nb . " to i64\n";
            $out .= '  store i64 ' . $nbI . ', ptr ' . $bg . "\n";
            $out .= '  store i64 ' . $val . ', ptr ' . $res . "\n";
        }
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * The name PHP must see for a class. A REIFIED generic specialization
     * (`Box$of$float`) reports its origin — `Box` — everywhere a name is
     * observable: `get_class()`, `::class`, var_dump. The spec name is an
     * internal artefact of the layout, never a PHP-visible identity.
     */
    private function displayClassName(string $cls): string
    {
        $cd = $this->classes[$cls] ?? null;
        if ($cd === null) { return $cls; }
        return $cd->display();
    }

    private function emitClassName(ClassName_ $n): string
    {
        $cls = $this->displayClassName($n->operand->type->class ?? '');
        $id = $this->pool->intern($cls);
        $this->lastValue = $this->strLitId($id);
        $this->lastValueType = 'ptr';
        return '';
    }

    private function emitIsset(Isset_ $n): string
    {
        $out = '';
        $acc = '1';
        $first = true;
        foreach ($n->targets as $t) {
            $out .= $this->emitIssetTarget($t);
            $cur = $this->lastValue;
            if ($first) { $acc = $cur; $first = false; continue; }
            $a = $this->ssa->allocReg();
            $out .= '  ' . $a . ' = and i64 ' . $acc . ', ' . $cur . "\n";
            $acc = $a;
        }
        $this->lastValue = $acc;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `isset($x->prop)` where `$x`'s class is erased and SOME class answers
     * `$prop` through __isset. Dispatch on class_id: a magic holder takes the
     * method, everything else takes the ordinary read-and-test-non-null path.
     *
     * Without this the erased receiver fell through to the generic read, which
     * (now that __get has an erased arm) would call __get instead of __isset —
     * php calls __isset, and the two are free to disagree.
     *
     * @param array<string,string> $magic
     */
    private function emitIssetMagicByClassId(PropertyAccess_ $pa, array $magic): string
    {
        $res = $this->ssa->allocReg();
        $out = '  ' . $res . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $res . "\n";
        $out .= $this->emitObjPtrOf($pa->object);
        $objPtr = $this->lastValue;
        $end = $this->ssa->allocLabel('ism.end');
        // A null receiver is not set, and reading its class_id is a wild load.
        $isnull = $this->ssa->allocReg();
        $out .= '  ' . $isnull . ' = icmp eq ptr ' . $objPtr . ", null\n";
        $live = $this->ssa->allocLabel('ism.live');
        $out .= '  br i1 ' . $isnull . ', label %' . $end . ', label %' . $live . "\n";
        $out .= $live . ":\n";
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $def = $this->ssa->allocLabel('ism.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        foreach ($magic as $cname => $declCls) {
            $lbl = $this->ssa->allocLabel('ism.case');
            $switch .= '    i64 ' . (string)$this->classes[$cname]->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $bodies .= $this->emitMagicCall($declCls, '__isset', $objPtr, $pa->property, null);
            $bodies .= $this->coerceToI64();
            $c = $this->ssa->allocReg();
            $bodies .= '  ' . $c . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
            $z = $this->ssa->allocReg();
            $bodies .= '  ' . $z . ' = zext i1 ' . $c . " to i64\n";
            $bodies .= '  store i64 ' . $z . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies . $def . ":\n";
        // Not a magic holder at runtime — the ordinary read, set iff the value is
        // neither a null pointer nor the boxed-NULL sentinel. Re-emitting the
        // receiver is safe: exactly one arm runs.
        $out .= $this->emitNode($pa);
        $out .= $this->coerceToI64();
        $rv = $this->lastValue;
        $nz = $this->ssa->allocReg();
        $out .= '  ' . $nz . ' = icmp ne i64 ' . $rv . ", 0\n";
        $nnul = $this->ssa->allocReg();
        $out .= '  ' . $nnul . ' = icmp ne i64 ' . $rv . ", -3659174697238528\n";
        $setc = $this->ssa->allocReg();
        $out .= '  ' . $setc . ' = and i1 ' . $nz . ', ' . $nnul . "\n";
        $setz = $this->ssa->allocReg();
        $out .= '  ' . $setz . ' = zext i1 ' . $setc . " to i64\n";
        $out .= '  store i64 ' . $setz . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Leave an i64 `0|1` in lastValue for whether `$t` is set. */
    /**
     * `isset($erased[$k])` — branch on the runtime tag, because the static type
     * says nothing. A STRING answers php's string-offset rule (the same
     * `__mir_str_offset_isset` the statically-typed arm calls); anything else
     * takes the array path through the VALIDATED {@see arrayPtrOrEmptyIr}, which
     * hands back the zero word for a non-array instead of a wild pointer.
     *
     * php answers false for a non-numeric offset on a string, which is what the
     * string arm does with a string key.
     */
    private function emitErasedIssetElem(\Compile\Mir\ArrayAccess_ $aa): string
    {
        $out = $this->emitNode($aa->array);
        $out .= $this->coerceToI64();
        $cv = $this->lastValue;
        $keyIsCell = $this->keyRidesCellChannel($aa->index);
        $keyIsString = $aa->index->type->kind === Type::KIND_STRING
            || $aa->index->kind === Node::KIND_STRING_CONST;
        $out .= $this->emitNode($aa->index);
        $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
        $key = $this->lastValue;

        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca i64\n";
        $isBox = $this->ssa->allocReg();
        $out .= '  ' . $isBox . ' = icmp ugt i64 ' . $cv . ", -4503599627370496\n";
        $ts = $this->ssa->allocReg();
        $out .= '  ' . $ts . ' = lshr i64 ' . $cv . ", 48\n";
        $nib = $this->ssa->allocReg();
        $out .= '  ' . $nib . ' = and i64 ' . $ts . ", 15\n";
        $isStrNib = $this->ssa->allocReg();
        $out .= '  ' . $isStrNib . ' = icmp eq i64 ' . $nib . ", 4\n";
        $isStr = $this->ssa->allocReg();
        $out .= '  ' . $isStr . ' = and i1 ' . $isBox . ', ' . $isStrNib . "\n";
        $strL = $this->ssa->allocLabel('iss.str');
        $arrL = $this->ssa->allocLabel('iss.arr');
        $endL = $this->ssa->allocLabel('iss.end');
        $out .= '  br i1 ' . $isStr . ', label %' . $strL . ', label %' . $arrL . "\n";

        $out .= $strL . ":\n";
        if ($keyIsString) {
            $out .= '  store i64 0, ptr ' . $slot . "\n";
        } else {
            $sp0 = $this->ssa->allocReg();
            $out .= '  ' . $sp0 . ' = and i64 ' . $cv . ", 281474976710655\n";
            $sp = $this->ssa->allocReg();
            $out .= '  ' . $sp . ' = inttoptr i64 ' . $sp0 . " to ptr\n";
            $ki = $key;
            if ($keyIsCell) {
                $this->rt->needsCellKey = true;
                $ki = $this->ssa->allocReg();
                $out .= '  ' . $ki . ' = call i64 @__mir_ckey_unbox_int(i64 ' . $key . ")\n";
            }
            $ok = $this->ssa->allocReg();
            $out .= '  ' . $ok . ' = call i1 @__mir_str_offset_isset(ptr ' . $sp
                  . ', i64 ' . $ki . ")\n";
            $okz = $this->ssa->allocReg();
            $out .= '  ' . $okz . ' = zext i1 ' . $ok . " to i64\n";
            $out .= '  store i64 ' . $okz . ', ptr ' . $slot . "\n";
        }
        $out .= '  br label %' . $endL . "\n";

        $out .= $arrL . ":\n";
        $out .= $this->arrayPtrOrEmptyIr($cv);
        $arr = $this->arrayPtrReg;
        $r = $this->ssa->allocReg();
        $val = $this->ssa->allocReg();
        if ($keyIsCell) {
            $this->rt->needsCellKey = true;
            $out .= '  ' . $r . ' = call i64 @__mir_array_isset_cell(ptr ' . $arr . ', i64 ' . $key . ")\n";
            $out .= '  ' . $val . ' = call i64 @__mir_array_get_cell(ptr ' . $arr . ', i64 ' . $key . ")\n";
        } elseif ($keyIsString) {
            $out .= '  ' . $r . ' = call i64 @__mir_array_isset_str(ptr ' . $arr . ', ptr ' . $key . ", i64 0, i64 0)\n";
            $out .= '  ' . $val . ' = call i64 @__mir_array_get_str(ptr ' . $arr . ', ptr ' . $key . ", i64 0, i64 0)\n";
        } else {
            $out .= '  ' . $r . ' = call i64 @__mir_array_isset_int(ptr ' . $arr . ', i64 ' . $key . ")\n";
            $out .= '  ' . $val . ' = call i64 @__mir_array_get_int(ptr ' . $arr . ', i64 ' . $key . ")\n";
        }
        // Present-but-NULL is unset, the same mask the typed arm applies.
        $nn = $this->ssa->allocReg();
        $out .= '  ' . $nn . ' = icmp ne i64 ' . $val . ", -3659174697238528\n";
        $nnz = $this->ssa->allocReg();
        $out .= '  ' . $nnz . ' = zext i1 ' . $nn . " to i64\n";
        $rr = $this->ssa->allocReg();
        $out .= '  ' . $rr . ' = and i64 ' . $r . ', ' . $nnz . "\n";
        $out .= '  store i64 ' . $rr . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $endL . "\n";

        $out .= $endL . ":\n";
        if ($keyIsCell || $keyIsString) {
            $out .= $this->keyTempRelease($aa->index, $key, $keyIsCell);
        }
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = load i64, ptr ' . $slot . "\n";
        $this->lastValue = $res;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitIssetTarget(Node $t): string
    {
        if ($t->kind === Node::KIND_ARRAY_ACCESS) {
            $aa = $t;
            // `isset($obj[$k])` on an ArrayAccess object → `offsetExists()`.
            if ($aa->array->type->kind === Type::KIND_OBJ
                && $this->classImplements($aa->array->type->class ?? '', 'ArrayAccess')) {
                $mc = new \Compile\Mir\MethodCall_($aa->array, 'offsetExists', [$aa->index], Type::bool_());
                $out = $this->emitMethodCall($mc);
                $out .= $this->coerceToI64();
                $cmp = $this->ssa->allocReg();
                $out .= '  ' . $cmp . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                $z = $this->ssa->allocReg();
                $out .= '  ' . $z . ' = zext i1 ' . $cmp . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
            // An ERASED base is not known to be an array, and the array path
            // below masks the word to a pointer and DEREFERENCES it. A
            // single-character string element — `$tokens[$j]` in symfony's
            // findClass(), where token_get_all yields bare `";"` strings between
            // the `[id, text, line]` triples — was read as an array header:
            // SIGSEGV, on main as much as here. Classify at runtime instead.
            if ($aa->array->type->kind === Type::KIND_CELL
                || $aa->array->type->kind === Type::KIND_UNKNOWN) {
                return $this->emitErasedIssetElem($aa);
            }
            if ($aa->array->type->kind !== Type::KIND_STRING) {
                $out = $this->emitNode($aa->array);
                // A `mixed`/cell base (a json_decode value) — and an ERASED one,
                // which may hold the very same boxed word — carries the array
                // pointer NaN-boxed ({@see EmitLlvmArrays::arrayBaseToPtr}).
                $out .= $this->arrayBaseToPtr($aa->array->type);
                $arr = $this->lastValue;
                $keyIsCell = $this->keyRidesCellChannel($aa->index);
                $keyIsString = $aa->index->type->kind === Type::KIND_STRING
                    || $aa->index->kind === Node::KIND_STRING_CONST;
                $out .= $this->emitNode($aa->index);
                $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
                $key = $this->lastValue;
                $r = $this->ssa->allocReg();
                if ($keyIsCell) {
                    $this->rt->needsCellKey = true;
                    $out .= '  ' . $r . ' = call i64 @__mir_array_isset_cell(ptr ' . $arr . ', i64 ' . $key . ")\n";
                } elseif ($keyIsString) {
                    $out .= '  ' . $r . ' = call i64 @__mir_array_isset_str(ptr ' . $arr . ', ptr ' . $key . ", i64 0, i64 0)\n";
                } else {
                    $out .= '  ' . $r . ' = call i64 @__mir_array_isset_int(ptr ' . $arr . ', i64 ' . $key . ")\n";
                }
                // PHP isset()/`??` treat a PRESENT-but-NULL value as unset — zero
                // the presence bit when the stored value is a boxed NULL (the get
                // reuses the already-emitted arr/key; a miss returns a non-NULL
                // default and is masked by the presence bit anyway). A raw-valued
                // array never holds the NULL sentinel, so the check is a no-op
                // there. `array_key_exists` keeps pure presence (a different path).
                $val = $this->ssa->allocReg();
                if ($keyIsCell) {
                    $out .= '  ' . $val . ' = call i64 @__mir_array_get_cell(ptr ' . $arr . ', i64 ' . $key . ")\n";
                } elseif ($keyIsString) {
                    $out .= '  ' . $val . ' = call i64 @__mir_array_get_str(ptr ' . $arr . ', ptr ' . $key . ", i64 0, i64 0)\n";
                } else {
                    $out .= '  ' . $val . ' = call i64 @__mir_array_get_int(ptr ' . $arr . ', i64 ' . $key . ")\n";
                }
                // Both calls above are done with the key — drop a fresh one
                // ({@see EmitLlvm::keyTempRelease}); `isset($m["k" . $i])`
                // leaked exactly as the plain read did.
                if ($keyIsCell || $keyIsString) {
                    $out .= $this->keyTempRelease($aa->index, $key, $keyIsCell);
                }
                $nn = $this->ssa->allocReg();
                $out .= '  ' . $nn . ' = icmp ne i64 ' . $val . ", -3659174697238528\n"; // != box_null
                $nnz = $this->ssa->allocReg();
                $out .= '  ' . $nnz . ' = zext i1 ' . $nn . " to i64\n";
                $rr = $this->ssa->allocReg();
                $out .= '  ' . $rr . ' = and i64 ' . $r . ', ' . $nnz . "\n";
                $this->lastValue = $rr;
                $this->lastValueType = 'i64';
                return $out;
            }
            // String receiver: isset($s[$i]) — the binary-safe length lives in
            // the header (at ptr-16), NOT at ptr (that's the first data byte),
            // and a negative offset counts from the end — the helper does both.
            $out = $this->emitNode($aa->array);
            $out .= $this->coerceToPtr();
            $arr = $this->lastValue;
            $out .= $this->emitNode($aa->index);
            $out .= $this->coerceToI64();
            $idx = $this->lastValue;
            $ok = $this->ssa->allocReg();
            $out .= '  ' . $ok . ' = call i1 @__mir_str_offset_isset(ptr ' . $arr
                  . ', i64 ' . $idx . ")\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $ok . " to i64\n";
            $this->lastValue = $z;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Property overloading: `isset($obj->undeclaredProp)` on a class that
        // defines __isset routes through `$obj->__isset('name')`.
        if ($t->kind === Node::KIND_PROPERTY_ACCESS) {
            $ipa = $t;
            $icls = $ipa->object->type->class ?? '';
            if ($icls !== '' && isset($this->classes[$icls])
                && $this->classes[$icls]->propertyOffset($ipa->property) === -1) {
                $isCls = $this->resolveMethodClass($icls, '__isset');
                if ($isCls !== '') {
                    $out = $this->emitNode($ipa->object);
                    $out .= $this->coerceToPtr();
                    $out .= $this->emitMagicCall($isCls, '__isset', $this->lastValue, $ipa->property, null);
                    $out .= $this->coerceToI64();
                    $cmp = $this->ssa->allocReg();
                    $out .= '  ' . $cmp . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                    $z = $this->ssa->allocReg();
                    $out .= '  ' . $z . ' = zext i1 ' . $cmp . " to i64\n";
                    $this->lastValue = $z; $this->lastValueType = 'i64';
                    return $out;
                }
            }
            // Same thing with the receiver's class ERASED — dispatch at runtime.
            // Gate on "not a KNOWN class", never on `=== ''`: the nullable
            // `type->class` reads as garbage under the native self-build, so the
            // string compare is false there while the isset is reliable.
            if (!isset($this->classes[$icls])) {
                $im = $this->magicPropHolders($ipa->property, '__isset');
                if ($im !== []) { return $this->emitIssetMagicByClassId($ipa, $im); }
            }
        }
        // Property / dynamic-property isset: reading the field derefs the
        // receiver, so `isset($n->x)` with a null `$n` faults. Guard the
        // receiver — a null one makes isset false without the deref. Only
        // when the receiver is a pure local (safe to re-emit in the live
        // branch). Result threads through a slot to avoid a phi predecessor
        // mismatch if the field read appends its own blocks.
        if (($t->kind === Node::KIND_PROPERTY_ACCESS || $t->kind === Node::KIND_DYN_PROP)) {
            if ($t->kind === Node::KIND_PROPERTY_ACCESS) {
                $objNode = $t->object;
            } elseif ($t->kind === Node::KIND_DYN_PROP) {
                $objNode = $t->object;
            } else {
                $objNode = $t;
            }
            if ($objNode->kind === Node::KIND_LOAD_LOCAL) {
                $rSlot = $this->ssa->allocReg();
                $out = '  ' . $rSlot . " = alloca i64\n";
                $out .= $this->emitNode($objNode);
                $out .= $this->coerceToPtr();
                $objPtr = $this->lastValue;
                $isnull = $this->ssa->allocReg();
                $out .= '  ' . $isnull . ' = icmp eq ptr ' . $objPtr . ", null\n";
                $lNull = $this->ssa->allocLabel('iss.null');
                $lRead = $this->ssa->allocLabel('iss.read');
                $lEnd = $this->ssa->allocLabel('iss.end');
                $out .= '  br i1 ' . $isnull . ', label %' . $lNull
                      . ', label %' . $lRead . "\n";
                $out .= $lRead . ":\n";
                $out .= $this->emitNode($t);
                $out .= $this->coerceToI64();
                $rv = $this->lastValue;
                // Set iff non-null: a null POINTER is 0 (`?string`/`?obj`), a
                // null SCALAR is the boxed-NULL sentinel (`?int`/`?float`/`?bool`
                // ride a numeric cell). PHP isset() is false for either.
                $nz = $this->ssa->allocReg();
                $out .= '  ' . $nz . ' = icmp ne i64 ' . $rv . ", 0\n";
                $nnul = $this->ssa->allocReg();
                $out .= '  ' . $nnul . ' = icmp ne i64 ' . $rv . ", -3659174697238528\n";
                $setc = $this->ssa->allocReg();
                $out .= '  ' . $setc . ' = and i1 ' . $nz . ', ' . $nnul . "\n";
                $setz = $this->ssa->allocReg();
                $out .= '  ' . $setz . ' = zext i1 ' . $setc . " to i64\n";
                $out .= '  store i64 ' . $setz . ', ptr ' . $rSlot . "\n";
                $out .= '  br label %' . $lEnd . "\n";
                $out .= $lNull . ":\n";
                $out .= '  store i64 0, ptr ' . $rSlot . "\n";
                $out .= '  br label %' . $lEnd . "\n";
                $out .= $lEnd . ":\n";
                $z = $this->ssa->allocReg();
                $out .= '  ' . $z . ' = load i64, ptr ' . $rSlot . "\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
        }
        // Default (var / property): the i64 carrier is non-zero iff set.
        // A null was stored as 0; an unset var slot was zeroed.
        $out = $this->emitNode($t);
        $out .= $this->coerceToI64();
        $v = $this->lastValue;
        $cmp = $this->ssa->allocReg();
        $out .= '  ' . $cmp . ' = icmp ne i64 ' . $v . ", 0\n";
        $z = $this->ssa->allocReg();
        $out .= '  ' . $z . ' = zext i1 ' . $cmp . " to i64\n";
        $this->lastValue = $z;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitUnset(Unset_ $n): string
    {
        $out = '';
        foreach ($n->targets as $t) {
            if ($t->kind === Node::KIND_LOAD_LOCAL) {
                $name = $t->name;
                // Release the held rc value first (drops to rc 0 → __destruct),
                // THEN zero the slot — a later scope-exit release re-loads 0 and
                // no-ops, so no double free.
                //
                // Only a local that OWNS its value may be released here. The
                // owned set is the one InsertMemoryOps built — every name it
                // gave a scope-exit `rc_release` ({@see EmitLlvm::collectRcObjLocals},
                // which is also what pays the entry retain for a param). A
                // BORROWED local releases a reference it never took: `$sel =
                // $bag->one; unset($sel);` ran Node_::__destruct while the bag
                // still held the object, and `$bag->one->name` then read freed
                // memory. It stayed hidden because `$c ? $bag->one : …` pays the
                // conditional contract's +1 — until a compile-time condition
                // folded the ternary away ({@see LowerFromAst::lowerTernary}) and
                // left the release with nothing to balance it.
                // `unset($ref)` where `$ref = &$x` breaks THAT BINDING and
                // nothing else — php leaves `$x` untouched. Here the alias
                // shares `$x`'s slot ({@see emitRefAlias}), so zeroing it wiped
                // the source: `$ref = &$bag; $ref[$k] = $v; unset($ref);` in a
                // loop stored 0 into `$bag` every iteration and the array read
                // back empty. Hand the name its own slot back (or none), release
                // nothing — an alias never owned the value.
                if (isset($this->locals->aliasLocals[$name])) {
                    $prev = $this->locals->aliasLocals[$name];
                    unset($this->locals->aliasLocals[$name]);
                    if ($prev !== '') {
                        $this->locals->slots[$name] = $prev;
                    } else {
                        unset($this->locals->slots[$name]);
                    }
                    continue;
                }
                $flavor = $this->discardReleaseFlavor($t->type);
                if (isset($this->locals->globalBacked[$name])) {
                    if ($flavor !== '') { $out .= $this->rcReleaseSlot($this->locals->globalBacked[$name], $flavor); }
                    $out .= '  store i64 0, ptr ' . $this->locals->globalBacked[$name] . "\n";
                } elseif (isset($this->locals->slots[$name])) {
                    if ($flavor !== '' && isset($this->frame->rcObjLocals[$name])) {
                        $out .= $this->rcReleaseSlot($this->locals->slots[$name], $flavor);
                    }
                    $out .= '  store i64 0, ptr ' . $this->locals->slots[$name] . "\n";
                }
            }
            if ($t->kind === Node::KIND_ARRAY_ACCESS) {
                $aa = $t;
                // `unset($obj[$k])` on an ArrayAccess object → `offsetUnset()`.
                if ($aa->array->type->kind === Type::KIND_OBJ
                    && $this->classImplements($aa->array->type->class ?? '', 'ArrayAccess')) {
                    $mc = new \Compile\Mir\MethodCall_($aa->array, 'offsetUnset', [$aa->index], Type::void());
                    $out .= $this->emitMethodCall($mc);
                } elseif ($aa->array->type->kind !== Type::KIND_STRING) {
                    $baseCell = $aa->array->type->kind === Type::KIND_CELL;
                    $out .= $this->emitNode($aa->array);
                    $out .= $this->arrayBaseToPtr($aa->array->type);
                    $arrPtr = $this->lastValue;
                    $keyIsCell = $this->keyRidesCellChannel($aa->index);
                    $keyIsString = $aa->index->type->kind === Type::KIND_STRING
                        || $aa->index->kind === Node::KIND_STRING_CONST;
                    $out .= $this->emitNode($aa->index);
                    $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
                    $key = $this->lastValue;
                    if ($keyIsCell) {
                        $this->rt->needsCellKey = true;
                        $out .= '  call void @__mir_array_unset_cell(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
                    } elseif ($keyIsString) {
                        $out .= '  call void @__mir_array_unset_str(ptr ' . $arrPtr . ', ptr ' . $key . ")\n";
                    } elseif ($this->unsetBaseIsWritable($aa->array)) {
                        // An INT key on a PACKED buffer has to promote to the
                        // hashed layout first — that is the only one that can
                        // hold a hole, and PHP's unset never reindexes. The
                        // promote RELOCATES, so this needs the write-back path
                        // and therefore a base we can store through; a nested
                        // base (`$a[0][1]`) keeps the old in-place call, where a
                        // packed unset is still the historical no-op.
                        $r = $this->ssa->allocReg();
                        $out .= '  ' . $r . ' = call ptr @__mir_array_unset_at(ptr '
                              . $arrPtr . ', i64 ' . $key . ")\n";
                        $out .= $this->vecWriteBack($aa->array, $r, $baseCell);
                    } else {
                        $out .= '  call void @__mir_array_unset_int(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
                    }
                    // The unset helpers release the STORED key (their own +1);
                    // this drops the caller's lookup temp
                    // ({@see EmitLlvm::keyTempRelease}).
                    if ($keyIsCell || $keyIsString) {
                        $out .= $this->keyTempRelease($aa->index, $key, $keyIsCell);
                    }
                }
            }
            // Property overloading: `unset($obj->undeclaredProp)` on a class
            // that defines __unset routes through `$obj->__unset('name')`.
            if ($t->kind === Node::KIND_PROPERTY_ACCESS) {
                $upa = $t;
                $ucls = $upa->object->type->class ?? '';
                if ($ucls !== '' && isset($this->classes[$ucls])
                    && $this->classes[$ucls]->propertyOffset($upa->property) === -1) {
                    $unCls = $this->resolveMethodClass($ucls, '__unset');
                    if ($unCls !== '') {
                        $out .= $this->emitNode($upa->object);
                        $out .= $this->coerceToPtr();
                        $out .= $this->emitMagicCall($unCls, '__unset', $this->lastValue, $upa->property, null);
                    }
                }
                // Same thing with the receiver's class ERASED — dispatch at
                // runtime. An unset of a DECLARED slot stays the no-op it has
                // always been, so the default arm has nothing to do. Gate on
                // "not a KNOWN class", never on `=== ''` — see the isset note.
                if (!isset($this->classes[$ucls])) {
                    $um = $this->magicPropHolders($upa->property, '__unset');
                    if ($um !== []) {
                        $out .= $this->emitObjPtrOf($upa->object);
                        $objPtr = $this->lastValue;
                        $endU = $this->ssa->allocLabel('unm.end');
                        $isnullU = $this->ssa->allocReg();
                        $out .= '  ' . $isnullU . ' = icmp eq ptr ' . $objPtr . ", null\n";
                        $liveU = $this->ssa->allocLabel('unm.live');
                        $out .= '  br i1 ' . $isnullU . ', label %' . $endU . ', label %' . $liveU . "\n";
                        $out .= $liveU . ":\n";
                        $out .= $this->emitLoadClassId($objPtr);
                        $defU = $this->ssa->allocLabel('unm.default');
                        $sw = '  switch i64 ' . $this->classIdReg . ', label %' . $defU . " [\n";
                        $bd = '';
                        foreach ($um as $cnameU => $declU) {
                            $lblU = $this->ssa->allocLabel('unm.case');
                            $sw .= '    i64 ' . (string)$this->classes[$cnameU]->classId . ', label %' . $lblU . "\n";
                            $bd .= $lblU . ":\n";
                            $bd .= $this->emitMagicCall($declU, '__unset', $objPtr, $upa->property, null);
                            $bd .= '  br label %' . $endU . "\n";
                        }
                        $sw .= "  ]\n";
                        $out .= $sw . $bd . $defU . ":\n";
                        $out .= '  br label %' . $endU . "\n";
                        $out .= $endU . ":\n";
                    }
                }
            }
            // vec element unset still deferred (needs hole / shift semantics).
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** A base {@see vecWriteBack} can store a relocated buffer through: a plain
     *  local (including a by-ref param or a `global`) or an object property. */
    private function unsetBaseIsWritable(Node $base): bool
    {
        if ($base->kind === Node::KIND_LOAD_LOCAL) {
            return isset($this->locals->slots[$base->name])
                || isset($this->locals->globalBacked[$base->name]);
        }
        return $base->kind === Node::KIND_PROPERTY_ACCESS;
    }

    private function emitStaticProp(\Compile\Mir\StaticProp_ $n): string
    {
        $reg = $this->ssa->allocReg();
        $out = '  ' . $reg . ' = load i64, ptr ' . $n->global . "\n";
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        } else {
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
        }
        return $out;
    }

    private function emitStoreStaticProp(\Compile\Mir\StoreStaticProp_ $n): string
    {
        $out = $this->emitNode($n->value);
        // A CELL-declared slot (`public static mixed $x`) is self-describing: the
        // READ side decodes it by tag, so the store must NaN-box. Storing raw made
        // every scalar come back as a denormal float — `C::$s = 42; C::$s === 42`
        // was false, and `AsyncHook::clear()`'s `= null` left every hook reading as
        // NON-null, so fclose() after async() called through a null closure.
        // Arrays/objects/closures ride raw here exactly as they do at a call
        // boundary ({@see EmitLlvm::isCellBoxableArg}) and exactly as an INSTANCE
        // cell-property does — so `is_array()`/`get_debug_type()` on an array in a
        // `mixed` static prop still lie (count() and the element reads work). That
        // is the repr-consistency epic's cell-array-backing-slot problem, not this
        // store's: boxing would rebuild the array and change its identity.
        $dk = $n->declared === null ? null : $n->declared->kind;
        $box = ($dk === Type::KIND_CELL || $dk === Type::KIND_UNKNOWN)
            && $this->isCellBoxableArg($n->value->type);
        // A float is boxed from the DOUBLE register: coercing to i64 first would
        // hand box_float the bit pattern as an integer (1.5 stored as 4.6e18).
        // Floats are not rc-managed, so nothing is owed to the retain below.
        //
        // An assignment is an EXPRESSION, and its value is the value ASSIGNED,
        // not the slot's storage encoding — so what escapes in `lastValue` must
        // match `$n->type` (the RHS type), which is what every consumer trusts.
        // Returning the BOXED word made `$v = (M::$d = 'x')` hand a NaN-tagged
        // pointer to a consumer typed `string`: it inttoptr'd and dereferenced
        // it (SIGSEGV), and `$v = (M::$i = 42)` silently read -4222124650659798.
        // emitStoreLocal keeps this invariant; this is the same rule.
        if ($box && $n->value->type->kind === Type::KIND_FLOAT) {
            $res = $this->lastValue;
            $resTy = $this->lastValueType;
            $out .= $this->boxToCell($n->value->type, $n->value);
            $val = $this->lastValue;
            $out .= '  store i64 ' . $val . ', ptr ' . $n->global . "\n";
            $this->lastValue = $res;
            $this->lastValueType = $resTy;
            return $out;
        }
        // The mirror image of the box above, and it was missing: a CELL value
        // stored into a CONCRETELY-typed slot must be UNBOXED, or the tagged bits
        // land in a slot every reader treats as that raw type. `public static int
        // $m` written through an erased closure parameter read back as
        // -4222124650655744, and `public static string $s` SIGSEGV'd — the reader
        // inttoptr'd the tag. The INSTANCE property store has done this for a
        // while ({@see emitStoreProperty}); only the static path never learned it,
        // which is why a typed instance slot was right and a typed static slot was
        // not through the very same channel.
        //
        // Concrete only. A CELL or UNKNOWN declaration is the box arm's business
        // above, and UNKNOWN is also the unhinted-static slot whose encoding
        // depends on the kind STORED — that one is the element-repr epic, not a
        // store this predicate can settle.
        $dcT = null;
        if ($n->value->type->kind === Type::KIND_CELL && $n->declared !== null
            && $dk !== Type::KIND_CELL && $dk !== Type::KIND_UNKNOWN) {
            $dcT = $n->declared;
            $out .= $this->unboxCellToType($dcT);
        }
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $res = $val;
        // A static prop is a program-lifetime owner of an obj value. The retain
        // happens on the RAW pointer, BEFORE any boxing — a tagged cell would
        // mis-locate the rc header (same rule as the instance-property store).
        // Once unboxed the payload IS raw, so the retain is chosen from the
        // declared type instead of the cell's null (which retains nothing).
        $out .= $this->rcRetainByType($n->value, $val, $dcT, 5);
        if ($box) {
            $out .= $this->boxToCell($n->value->type, $n->value);
            $val = $this->lastValue;
        }
        $out .= '  store i64 ' . $val . ', ptr ' . $n->global . "\n";
        $this->lastValue = $res;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Resolve the emitted function name for a method call honouring late
     * static binding. `$owner` declares the body; `$scope` is the called
     * class (`static::`). When `$owner` has a per-descendant specialisation
     * for `$scope` (`<owner>__<method>__lsb<scope>`), return it; otherwise the
     * plain `<owner>__<method>` (which already binds `static == owner`).
     */
    private function lsbTarget(string $owner, string $method, string $scope): string
    {
        $base = $owner . '__' . $method;
        if ($scope !== '' && $scope !== $owner) {
            $spec = $base . '__lsb' . $scope;
            if (isset($this->sigs->paramTypes[$spec])) { return $spec; }
        }
        return $base;
    }

    /**
     * `Enum::cases()` → a fresh vec of every case's ordinal (0..N-1) in
     * declaration order, element type obj<Enum>. N is a compile-time constant,
     * so the appends are unrolled (matches the __mir_array_alloc + append idiom
     * used by array_keys). lastValue ← the vec ptr.
     */
    private function emitEnumCases(string $enum): string
    {
        $ed = $this->enums[$enum];
        $n = \count($ed->caseNames);
        $cur = $this->ssa->allocReg();
        $out = '  ' . $cur . ' = call ptr @__mir_array_alloc(i64 ' . (string)$n . ")\n";
        $i = 0;
        while ($i < $n) {
            $nx = $this->ssa->allocReg();
            $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur
                  . ', i64 ' . (string)$i . ")\n";
            $cur = $nx;
            $i = $i + 1;
        }
        $this->lastValue = $cur;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Backed-enum `from($v)` / `tryFrom($v)`. Unrolled scan of the constant
     * `@<Enum>__values` table: a hit yields the case (from → raw ordinal;
     * tryFrom → box_object(singleton), a nullable-enum cell); a miss throws a
     * catchable `ValueError` (from) or yields box_null (tryFrom).
     */
    private function emitEnumFrom(string $enum, Node $arg, bool $try): string
    {
        $ed = $this->enums[$enum];
        $n = \count($ed->caseNames);
        $isStr = $this->edBacking($ed) === 'string';
        $out = $this->emitNode($arg);
        $out .= $isStr ? $this->coerceToPtr() : $this->coerceToI64();
        $needle = $this->lastValue;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $done = $this->ssa->allocLabel('efrom.done');
        $vt = $isStr ? 'ptr' : 'i64';
        for ($i = 0; $i < $n; $i = $i + 1) {
            $hit = $this->ssa->allocLabel('efrom.hit');
            $nextL = $this->ssa->allocLabel('efrom.next');
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = getelementptr [' . (string)$n . ' x ' . $vt . '], ptr @'
                  . $this->mangle($enum) . '__values, i64 0, i64 ' . (string)$i . "\n";
            $v = $this->ssa->allocReg();
            $out .= '  ' . $v . ' = load ' . $vt . ', ptr ' . $g . "\n";
            $eq = $this->ssa->allocReg();
            if ($isStr) {
                $out .= '  ' . $eq . ' = call i1 @__mir_str_eq(ptr ' . $needle . ', ptr ' . $v . ")\n";
            } else {
                $out .= '  ' . $eq . ' = icmp eq i64 ' . $needle . ', ' . $v . "\n";
            }
            $out .= '  br i1 ' . $eq . ', label %' . $hit . ', label %' . $nextL . "\n";
            $out .= $hit . ":\n";
            if ($try) {
                $cg = $this->ssa->allocReg();
                $out .= '  ' . $cg . ' = getelementptr [' . (string)$n . ' x i64], ptr @'
                      . $this->mangle($enum) . '__cases, i64 0, i64 ' . (string)$i . "\n";
                $dp = $this->ssa->allocReg();
                $out .= '  ' . $dp . ' = load i64, ptr ' . $cg . "\n";
                $pp = $this->ssa->allocReg();
                $out .= '  ' . $pp . ' = inttoptr i64 ' . $dp . " to ptr\n";
                $bx = $this->ssa->allocReg();
                $out .= '  ' . $bx . ' = call i64 @__manticore_box_object(ptr ' . $pp . ")\n";
                $out .= '  store i64 ' . $bx . ', ptr ' . $res . "\n";
            } else {
                $out .= '  store i64 ' . (string)$i . ', ptr ' . $res . "\n";
            }
            $out .= '  br label %' . $done . "\n";
            $out .= $nextL . ":\n";
        }
        // Fell through every case → miss.
        if ($try) {
            $out .= '  store i64 -3659174697238528, ptr ' . $res . "\n"; // box_null
            $out .= '  br label %' . $done . "\n";
        } else {
            // PHP's exact message: `"<v>" is not a valid backing value for enum
            // <Name>` (string values quoted; int values bare) — built at runtime
            // so getMessage() matches. Re-emits $arg (miss path only).
            $tail = new \Compile\Mir\StringConst(
                ' is not a valid backing value for enum ' . $enum, Type::string_());
            if ($isStr) {
                $msgNode = new \Compile\Mir\Concat(
                    new \Compile\Mir\Concat(
                        new \Compile\Mir\StringConst('"', Type::string_()), $arg),
                    new \Compile\Mir\Concat(
                        new \Compile\Mir\StringConst('"', Type::string_()), $tail));
            } else {
                $msgNode = new \Compile\Mir\Concat($arg, $tail);
            }
            $throw = new \Compile\Mir\Throw_(
                new \Compile\Mir\NewObj('ValueError', [
                    $msgNode,
                    new \Compile\Mir\IntConst(0, Type::int_()),
                    new \Compile\Mir\NullConst(Type::obj('Throwable')),
                ], Type::obj('ValueError')),
                Type::void(),
            );
            // emitNode(Throw_) longjmps + `unreachable`, then leaves a trailing
            // empty `dead.N:` block — terminate it into `done` so the label that
            // follows is well-formed (the branch is itself dead: the throw never
            // returns). `res` is unset on this path but never loaded live.
            $out .= $this->emitNode($throw);
            $out .= '  br label %' . $done . "\n";
        }
        $out .= $done . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Copy a closure's env struct, rebinding the `$this` slot (struct slot 1)
     *  to `$objNode`. Shared by Closure::bind / ->bindTo / ->call. The bound
     *  value carries the same fn ptr (slot 0), so an invoke dispatches through
     *  it; class_id dispatch resolves `$this->prop` against the new object. */
    private function emitClosureRebind(Node $fnNode, Node $objNode): string
    {
        $out = $this->emitNode($fnNode);
        $out .= $this->coerceToPtr();
        $src = $this->lastValue;
        // Env size from the static closure type; a dynamic (unknown) closure
        // falls back to a `$this`-only env (fn ptr + one slot) — the common
        // bind target `function () { … $this … }`.
        $fnName = $fnNode->type->class ?? '';
        $cnt = $this->closureCaptures[$fnName] ?? 1;
        $slots = 1 + $cnt;
        // The copy gets its own lifetime header, taken FROM THE SOURCE AT
        // RUNTIME: the source's magic says whether it owns anything, and its
        // retain/drop pair describes exactly what. Reading them off the header
        // instead of naming `__closure_N__mc_retain` here keeps this correct for
        // a dynamic rebind — and independent of which function the compiler
        // happens to emit first. A source with no header copies a zero magic,
        // and every helper then leaves the copy alone: the old
        // never-freed behaviour, which leaks but cannot dangle.
        $this->rt->needsClosureRc = true;
        $hdr = \Compile\MemoryAbi::STRING_HEADER_SIZE;
        $base = $this->ssa->allocReg();
        $out .= '  ' . $base . ' = call ptr @__mir_alloc(i64 '
              . (string)($hdr + 8 * $slots) . ")\n";
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . ' = getelementptr inbounds i8, ptr ' . $base
              . ', i64 ' . (string)$hdr . "\n";
        $srcMagic = $this->closureHdrLoad($src, \Compile\MemoryAbi::STRING_HASH_OFFSET);
        $out .= $this->closureHdrLoadOut;
        $srcRet = $this->closureHdrLoad($src, \Compile\MemoryAbi::CLOSURE_RETAIN_OFFSET);
        $out .= $this->closureHdrLoadOut;
        $srcDrop = $this->closureHdrLoad($src, \Compile\MemoryAbi::CLOSURE_DROP_OFFSET);
        $out .= $this->closureHdrLoadOut;
        $isOurs = $this->ssa->allocReg();
        $out .= '  ' . $isOurs . ' = icmp eq i64 ' . $srcMagic . ', '
              . (string)\Compile\MemoryAbi::CLOSURE_TAG_MAGIC . "\n";
        $magicV = $this->ssa->allocReg();
        $out .= '  ' . $magicV . ' = select i1 ' . $isOurs . ', i64 '
              . (string)\Compile\MemoryAbi::CLOSURE_TAG_MAGIC . ", i64 0\n";
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::STRING_HASH_OFFSET, $magicV);
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::CLOSURE_RETAIN_OFFSET, $srcRet);
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::CLOSURE_DROP_OFFSET, $srcDrop);
        $out .= $this->closureHdrStore($buf, \Compile\MemoryAbi::STRING_RC_OFFSET, '1');
        for ($i = 0; $i < $slots; $i = $i + 1) {
            $sg = $this->ssa->allocReg();
            $out .= '  ' . $sg . ' = getelementptr inbounds i64, ptr ' . $src . ', i64 ' . (string)$i . "\n";
            $sv = $this->ssa->allocReg();
            $out .= '  ' . $sv . ' = load i64, ptr ' . $sg . "\n";
            $dg = $this->ssa->allocReg();
            $out .= '  ' . $dg . ' = getelementptr inbounds i64, ptr ' . $buf . ', i64 ' . (string)$i . "\n";
            $out .= '  store i64 ' . $sv . ', ptr ' . $dg . "\n";
        }
        $hasThis = $this->closureHasThis[$fnName] ?? true;
        if ($hasThis && $cnt >= 1) {
            $out .= $this->emitNode($objNode);
            $out .= $this->coerceToI64();
            $objV = $this->lastValue;
            $tg = $this->ssa->allocReg();
            $out .= '  ' . $tg . ' = getelementptr inbounds i64, ptr ' . $buf . ", i64 1\n";
            $out .= '  store i64 ' . $objV . ', ptr ' . $tg . "\n";
        }
        // AFTER the `$this` slot is settled, so the retain co-owns the object
        // this copy actually holds and not the one it replaced — one +1 per
        // slot the copy's own drop will later release. Indirect through the
        // header, and skipped entirely when the source had none.
        $retL = $this->ssa->allocLabel('cbind.ret');
        $endL = $this->ssa->allocLabel('cbind.end');
        $hasRet = $this->ssa->allocReg();
        $out .= '  ' . $hasRet . ' = icmp ne i64 ' . $srcRet . ", 0\n";
        $out .= '  br i1 ' . $hasRet . ', label %' . $retL . ', label %' . $endL . "\n";
        $out .= $retL . ":\n";
        $rf = $this->ssa->allocReg();
        $out .= '  ' . $rf . ' = inttoptr i64 ' . $srcRet . " to ptr\n";
        $out .= '  call void ' . $rf . '(ptr ' . $buf . ")\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $this->lastValue = $buf;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** A receiver's static class name, or '' when it has none. `Type::$class` is
     *  meaningful only on an OBJ type — reading it off a cell through `?? ''`
     *  hands back a raw 0 that does not compare equal to null natively. */
    private function staticClassOf(\Compile\Mir\Node $recv): string
    {
        $t = $recv->type;
        if ($t->kind !== Type::KIND_OBJ) { return ''; }
        return \ltrim($t->class ?? '', '\\');
    }

    /**
     * The `#[\Deprecated]` line for a method / static-method call, or ''.
     * Keyed by the DECLARING class, so an inherited call resolves to the
     * declaration that actually runs — exactly what php reports.
     */
    private function deprecatedMethodDiag(string $class, string $method, int $line): string
    {
        if ($class === '') { return ''; }
        $decl = $this->resolveMethodClass($class, $method);
        if ($decl === '') { $decl = $class; }
        $msg = $this->deprecatedMethods[$decl . '::' . $method] ?? '';
        if ($msg === '') { return ''; }
        return $this->emitDiagnosticLine('Deprecated', $msg, $line);
    }

    private function emitStaticCall(\Compile\Mir\StaticCall_ $n): string
    {
        $depDiag = $this->deprecatedMethodDiag(\ltrim($n->class, '\\'), $n->method, $n->line);
        if ($depDiag !== '') { return $depDiag . $this->emitStaticCallInner($n); }
        return $this->emitStaticCallInner($n);
    }

    private function emitStaticCallInner(\Compile\Mir\StaticCall_ $n): string
    {
        // Closure::bind($fn, $obj, $scope?) → a copy of $fn's env with the
        // `$this` slot rebound to $obj (scope resolved by class_id dispatch).
        if (\strtolower(\ltrim($n->class, '\\')) === 'closure' && $n->method === 'bind'
            && \count($n->args) >= 2) {
            return $this->emitClosureRebind($n->args[0], $n->args[1]);
        }
        // Enum built-in `cases()` — a list of every case in declaration order.
        // An enum value is carried as its ordinal, so the list is [0..N-1] with
        // element type obj<Enum> (typed in InferTypes::inferStaticCall).
        if (isset($this->enums[$n->class]) && $n->method === 'cases'
            && \count($n->args) === 0) {
            return $this->emitEnumCases($n->class);
        }
        // Backed-enum `from($v)` / `tryFrom($v)` — value→case lookup.
        if (isset($this->enums[$n->class]) && \count($n->args) === 1
            && ($n->method === 'from' || $n->method === 'tryFrom')) {
            return $this->emitEnumFrom($n->class, $n->args[0], $n->method === 'tryFrom');
        }
        // Method overloading: an unresolved static method on a class that
        // defines __callStatic reroutes to `Class::__callStatic('name', [args])`.
        if ($this->resolveMethodClass($n->class, $n->method) === ''
            && $this->resolveMethodClass($n->class, '__callStatic') !== '') {
            $elems = [];
            foreach ($n->args as $a) { $elems[] = new \Compile\Mir\ArrayElement_(null, $a); }
            $argsArr = new \Compile\Mir\ArrayLit($elems, Type::vec(Type::cell()));
            $nameNode = new \Compile\Mir\StringConst($n->method, Type::string_());
            $call = new \Compile\Mir\StaticCall_($n->class, '__callStatic', [$nameNode, $argsArr], $n->type);
            return $this->emitStaticCall($call);
        }
        $out = '';
        $argList = '';
        $first = true;
        $argTemps = [];
        $reboxSlots = [];
        $reboxTmps = [];
        $cls = $this->resolveMethodClass($n->class, $n->method);
        if ($cls === '') { $cls = $n->class; }
        // Nothing defines it — anywhere. The INSTANCE path has answered this
        // with php's own runtime Error since the tier-2 link
        // ({@see methodHasNoImpl}); the STATIC path named its callee on faith,
        // so the module carried a symbol with no definition and clang failed
        // the whole build over a branch that may never run.
        //
        // `self::assertThat(…)` inside symfony/framework-bundle's
        // BrowserKitAssertionsTrait is the tier-4 witness: the name comes from
        // PHPUnit's Assert, which `--no-dev` leaves out, and `self::` binds to
        // the USING class — so the module asked for
        // `KernelTestCase::assertThat` and 46 IR parts each failed with
        // `use of undefined value`.
        //
        // Same policy as the undefined-FUNCTION traps the build already
        // reports: php raises when the call is REACHED, so the build must not
        // die for it.
        if ($this->methodHasNoImpl($cls, $n->method)) {
            $name = $cls !== '' ? $cls : ($n->class !== '' ? $n->class : 'object');
            $thr = new \Compile\Mir\Call(
                '__mir_throw_error',
                [new \Compile\Mir\StringConst(
                    'Call to undefined method ' . $name . '::' . $n->method . '()',
                    Type::string_())],
                Type::cell(),
            );
            return $out . $this->emitNode($thr);
        }
        // Late static binding: route to the per-descendant specialisation
        // matching the called class (`$n->staticClass`) when one exists.
        $lsbScope = $n->staticClass !== '' ? $n->staticClass : $n->class;
        $target = $this->lsbTarget($cls, $n->method, $lsbScope);
        // By-ref mask of the resolved callee. Static-call args already align
        // with params (a selfish instance call prepends `$this` at lowering),
        // so arg index `ai` maps to param `ai` — forward the slot address for
        // a by-ref param instead of the dereferenced value.
        $mask = $this->sigs->refParams[$cls . '__' . $n->method] ?? [];
        $ptypes = $this->sigs->paramTypes[$cls . '__' . $n->method] ?? [];
        $ahmask = $this->sigs->arrayHintedParams[$cls . '__' . $n->method] ?? [];
        $tmask = $this->sigs->taggedParams[$cls . '__' . $n->method] ?? [];
        $cellBoxSlots = [];
        $cellBoxTmps = [];
        $cellBoxTypes = [];
        $ai = 0;
        foreach ($this->faCallArgs($target, $n->args) as $a) {
            // `Cls::m(...$arr)`: expand across the method's declared params
            // (a static call has no implicit `$this`, so arg `ai` is param `ai`).
            if ($a->kind === Node::KIND_SPREAD) {
                $out .= $this->emitNode($a->operand);
                $out .= $this->coerceToPtr();
                $arr = $this->lastValue;
                $elemType = $a->operand->type->element ?? null;
                [$sir, $sregs] = $this->emitSpreadFill($arr, $ai, $ptypes, $tmask, $elemType,
                                                       $cls . '__' . $n->method);
                $out .= $sir;
                foreach ($sregs as $rg) {
                    if (!$first) { $argList .= ', '; }
                    $first = false;
                    $argList .= 'i64 ' . $rg;
                }
                $ai = \count($ptypes);
                continue;
            }
            if (!$first) { $argList .= ', '; }
            $first = false;
            if ($this->argIsByRef($mask, $ai, $a) && $this->isByRefAddressable($a)
                && $this->byRefNeedsCellUnbox($a, $ptypes, $ai)
            ) {
                // Cell lvalue → raw-payload by-ref param; see
                // emitByRefCellUnboxArg. A vivified out-variable is exactly this.
                $out .= $this->emitByRefCellUnboxArg($a);
                $argList .= 'i64 ' . $this->lastValue;
                $reboxSlots[] = $this->refBoxSlot;
                $reboxTmps[] = $this->refBoxTmp;
            } elseif ($this->argIsByRef($mask, $ai, $a) && $this->isByRefAddressable($a)
                && $this->byRefNeedsCellBox($a, $ptypes, $ai)
            ) {
                // Raw lvalue → `mixed &$var` param; see emitByRefCellBox.
                $out .= $this->emitByRefCellBox($a);
                $argList .= 'i64 ' . $this->lastValue;
                $cellBoxSlots[] = $this->refBoxSlot;
                $cellBoxTmps[] = $this->refBoxTmp;
                $cellBoxTypes[] = $a->type;
            } elseif ($this->argIsByRef($mask, $ai, $a)) {
                $out .= $this->emitByRefArg($a);
                $argList .= 'i64 ' . $this->lastValue;
            } elseif (($tmask[$ai] ?? false) && $a->type->kind !== Type::KIND_CELL) {
                // Tagged (mixed/union) param: NaN-box the arg by its static type.
                $out .= $this->emitNode($a);
                $out .= $this->boxToCell($a->type);
                $argList .= 'i64 ' . $this->lastValue;
            } else {
                $out .= $this->emitNode($a);
                $out .= $this->coerceToI64();
                $out .= $this->unboxCellArg($a, $ptypes, $ai, $ahmask);
                $argList .= 'i64 ' . $this->lastValue;
                if ($this->isFreshStringTemp($a)) { $argTemps[] = $this->lastValue; }
            }
            $ai = $ai + 1;
        }
        $out .= $this->surplusArgEffects($target, $n->args);
        // Catch-all default pad (mirrors emitMethodCall); a static call is
        // usually lower-filled, but an unresolved-at-lowering callee may
        // arrive short — never leave a trailing optional unset.
        $out .= $this->emitDefaultArgPad($cls . '__' . $n->method, $ai, !$first);
        $argList .= $this->lastPadArgs;
        $btName = '';
        if ($this->rt->needsBacktrace) {
            $btName = $n->class . '::' . $n->method;
            $out .= $this->btPush($btName, $n->line);
        }
        $out .= $this->faPush($target, $n->srcArgc, $n->args);
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($target)
              . '(' . $argList . ")\n";
        if ($btName !== '') { $out .= $this->btPop(); }
        $out .= $this->emitByRefCellRebox($reboxSlots, $reboxTmps);
        $out .= $this->freeStrArgTemps($argTemps);
        $ci = 0;
        foreach ($cellBoxTmps as $ctmp) {
            $out .= $this->emitByRefCellWriteBack($ctmp, $cellBoxSlots[$ci], $cellBoxTypes[$ci]);
            $ci = $ci + 1;
        }
        // By-ref return (`static function &m()`): the callee yields the slot
        // ADDRESS; deref in value context, keep raw under rawRefCall (RefBind).
        if (($this->sigs->returnsByRef[$target] ?? false) && !$this->rawRefCall) {
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
            $dv = $this->ssa->allocReg();
            $out .= '  ' . $dv . ' = load i64, ptr ' . $p . "\n";
            // Value context takes a COPY ({@see byRefValueCopyRetainIr}).
            $out .= $this->byRefValueCopyRetainIr($dv);
            $reg = $dv;
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    /**
     * `$class` plus every class that (transitively) extends it. Used to
     * enumerate the runtime types that could reach a virtual call site.
     *
     * @return string[]
     */
    private function selfAndDescendants(string $class): array
    {
        if ($class === '') { return []; }
        $result = [$class];
        // Iterate values + use `$cd->name` (self-host assoc-key foreach
        // yields i64, which would push garbage descendant names).
        foreach ($this->classes as $cd) {
            $nm = $cd->name;
            if ($nm === $class) { continue; }
            // classIsA also follows the ORIGIN edge, so a reified specialization
            // counts as a descendant of the generic class it specializes — which
            // is what puts it in the dispatch switch of an erased receiver.
            if ($cd->originClass !== '' && $this->classIsA($nm, $class)) {
                $result[] = $nm;
                continue;
            }
            $c = $cd->parent;
            while ($c !== '') {
                if ($c === $class) { $result[] = $nm; break; }
                $pc = $this->classes[$c] ?? null;
                $c = $pc !== null ? $pc->parent : '';
            }
        }
        return $result;
    }

    /**
     * Re-coerce the shared dispatch argument list for ONE arm, and leave the
     * arm's list in {@see $vdArmList}. Returns the fixup IR to emit inside that
     * arm's block (empty when the arm already agrees).
     *
     * The call site coerces its arguments once, against the FALLBACK's
     * signature. Candidates reached through an ERASED receiver are matched by
     * method NAME, so they can be entirely unrelated classes whose parameter
     * REPRS differ — a raw `string` in one, a `string|int` CELL in another. The
     * arm that disagrees then reads the other's representation.
     *
     * Re-coercion runs from the repr the site ACTUALLY emitted, never from the
     * fallback's declared type: symfony reaches `InputDefinition::hasArgument`
     * (`string|int`) through a fallback declaring plain `string`, so boxing
     * `$c - 1` by the declaration tagged an int -1 as a POINTER and the callee
     * dereferenced 0xffffffffffff.
     *
     * Only the cell-vs-raw axis is fixed here: that is the one that mis-reads a
     * value outright. An UNKNOWN on either side is left alone — there is nothing
     * to box by, and guessing is what put a raw pointer in a cell slot before.
     *
     * @param array<int, Type> $srcTypes the repr each arg was emitted in
     * @param array<int, Type> $cTypes   this arm's own signature
     */
    private function vdArmArgs(string $argList, array $srcTypes, array $cTypes, string $sym = ''): string
    {
        $this->vdArmList = $argList;
        // ARITY first, and OUTSIDE the repr early-return: a site that emitted no
        // per-argument types still hands every arm the fallback's list length.
        if (\count($cTypes) === 0 || \count($srcTypes) === 0) {
            $only = $this->vdArmArity(\explode(', ', $argList), $cTypes, $sym);
            return $only . $this->vdArmSpread($sym, $cTypes);
        }
        $parts = \explode(', ', $argList);
        $out = '';
        $changed = false;
        $n = \count($parts);
        $i = 1;                       // index 0 is the implicit `$this`
        while ($i < $n) {
            $src = $srcTypes[$i] ?? null;
            $ct = $cTypes[$i] ?? null;
            if ($src !== null && $ct !== null
                && $src->kind !== Type::KIND_UNKNOWN && $ct->kind !== Type::KIND_UNKNOWN
                && \str_starts_with($parts[$i], 'i64 ')) {
                $srcCell = $src->kind === Type::KIND_CELL;
                $ctCell = $ct->kind === Type::KIND_CELL;
                if ($srcCell !== $ctCell) {
                    $this->lastValue = \substr($parts[$i], 4);
                    $this->lastValueType = 'i64';
                    $out .= $ctCell ? $this->boxToCell($src) : $this->unboxCellToType($ct);
                    $out .= $this->coerceToI64();
                    $parts[$i] = 'i64 ' . $this->lastValue;
                    $changed = true;
                }
            }
            $i = $i + 1;
        }
        if ($changed) { $this->vdArmList = \implode(', ', $parts); }
        $out .= $this->vdArmArity($parts, $cTypes, $sym);
        return $out . $this->vdArmSpread($sym, $cTypes);
    }

    /**
     * Make the arm's list as LONG as this candidate's own signature — the other
     * half of {@see vdArmArgs}, and for the same reason: the shared list was
     * built for the FALLBACK.
     *
     * Candidates reached through an erased receiver are matched by NAME, so
     * their arities differ as freely as their reprs do. `$o->__construct()` on
     * an erased `$o` emitted ONE list sized for the widest candidate
     * (`Exception::__construct`, four parameters) and handed it to every arm, so
     * `Row::__construct($this, $tag = '-')` was called as `(…, %arg, 0, 0)`:
     * two arguments too many, and `$tag` taking a placeholder ZERO instead of
     * its default. That is what made pdo's `fetchObject('Row')` build a row
     * whose promoted `$tag` was the empty string — via `makeObject`'s
     * `$o->__construct()`.
     *
     * SHORT arm → default-pad it from the candidate's own declaration; LONG arm
     * → truncate, exactly as {@see EmitLlvm::faCallArgs} does for a direct call.
     * A spread arm is left to {@see vdArmSpread}, which fills against the same
     * signature.
     *
     * @param string[]         $parts  the arm's argument list, already re-coerced
     * @param array<int, Type> $cTypes this arm's own signature
     */
    private function vdArmArity(array $parts, array $cTypes, string $sym): string
    {
        if ($sym === '' || $this->spreadTail !== null) { return ''; }
        $want = \count($this->sigs->paramTypes[$sym] ?? $cTypes);
        if ($want === 0) { return ''; }
        // Back to what the SITE wrote: the tail past that is the FALLBACK's
        // defaults, and this arm's own defaults are a different answer. Keeping
        // them is what handed `Row::__construct($tag = '-')` the placeholder
        // Exception::$message slot and left `$tag` empty.
        $have = \count($parts);
        $site = $this->vdSiteArgc;
        if ($site > 0 && $site < $have) {
            $kept = [];
            $i = 0;
            while ($i < $site) { $kept[] = $parts[$i]; $i = $i + 1; }
            $parts = $kept;
            $have = $site;
        }
        if ($have === $want) {
            $this->vdArmList = \implode(', ', $parts);
            return '';
        }
        if ($have > $want) {
            // Still too long: a candidate narrower than the site's own call,
            // truncated as {@see EmitLlvm::faCallArgs} does for a direct call.
            $kept = [];
            $i = 0;
            while ($i < $want) { $kept[] = $parts[$i]; $i = $i + 1; }
            $this->vdArmList = \implode(', ', $kept);
            return '';
        }
        $out = $this->emitDefaultArgPad($sym, $have, true);
        $this->vdArmList = \implode(', ', $parts) . $this->lastPadArgs;
        return $out;
    }

    /**
     * Append the pending `...$arr` tail to {@see $vdArmList}, expanded against
     * `$sym`'s OWN parameters — its arity, its tagged mask, its defaults. A
     * no-op when the call site had no spread.
     * @param array<int, Type> $cTypes
     */
    private function vdArmSpread(string $sym, array $cTypes): string
    {
        if ($this->spreadTail === null || $sym === '') { return ''; }
        $tail = $this->spreadTail;
        $ptypes = \count($cTypes) > 0 ? $cTypes : ($this->sigs->paramTypes[$sym] ?? []);
        $tmask = $this->sigs->taggedParams[$sym] ?? [];
        // Cleared across the fill: a default expression is lowered here too, and
        // a nested call must not inherit this site's pending tail.
        $this->spreadTail = null;
        [$out, $regs] = $this->emitSpreadFill($tail[0], $tail[1], $ptypes, $tmask, $tail[2], $sym);
        $this->spreadTail = $tail;
        foreach ($regs as $rg) { $this->vdArmList .= ', i64 ' . $rg; }
        return $out;
    }

    /**
     * Emit a class_id switch for a polymorphic method call. Returns the
     * IR and leaves the i64 result reg in `$this->vdResult` (avoids a
     * `&$out` accumulator — self-host drops by-ref writes). Args are
     * already evaluated into `$argList`; they dominate every case.
     *
     * @param string[]              $cands
     * @param array<string, string> $targets    candidate class → declaring class
     * @param array<string, bool>   $erasedSyms symbol → emitted-as-erased
     */
    private function emitVirtualDispatch(string $thisArg, string $argList, array $cands, array $targets, string $fallback, string $method, bool $boxCell = false, array $erasedSyms = [], array $argOutTypes = []): string
    {
        $objp = $this->ssa->allocReg();
        $out = '  ' . $objp . ' = inttoptr i64 ' . $thisArg . " to ptr\n";
        $out .= $this->emitLoadClassId($objp);
        $cid = $this->classIdReg;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $endLabel = $this->ssa->allocLabel('vd.end');
        $defLabel = $this->ssa->allocLabel('vd.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $defLabel . " [\n";
        $bodies = '';
        // Group the candidates that produce the SAME arm before emitting any.
        //
        // An inherited method is the common case, not the exception: 27 classes
        // deriving from Exception/Error all resolve `getMessage` to one of two
        // symbols, and emitting one block each duplicated the call, the boxing
        // and the branch 27 times. LLVM lets many `i64 X, label %L` entries share
        // a destination, so the switch TABLE still names every class id while the
        // bodies collapse to one per distinct callee. This is the single biggest
        // IR-volume term at a megamorphic site — `__mc_ob_call`'s 312 dynamic-name
        // arms each carried a full copy of one of these switches.
        //
        // The key is everything the body depends on: the callee symbol, and — for
        // a cell-typed merge — the class whose DECLARED return type decides the
        // boxing. Two candidates agreeing on both emit byte-identical arms.
        /** @var array<string, string> arm key → block label */
        $armLabel = [];
        /** @var array<string, string> arm key → the candidate class to emit it from */
        $armCand = [];
        /** @var string[] arm keys, in first-seen order */
        $armOrder = [];
        // Dedupe on the CLASS ID as well as on the arm. The candidate list is
        // built from NAMES, and two of them can denote one runtime type — a
        // reified specialization counts as a descendant of the class it
        // specializes ({@see selfAndDescendants} follows the ORIGIN edge) — so
        // the same class_id reached the switch twice and clang rejected the
        // whole module with `duplicate case value`. Grouping by arm does NOT
        // subsume this: two names sharing a class_id can still resolve to
        // different arms, and each would emit its own case entry for that id.
        // The first name wins, matching the first-declarer rule the union path
        // already applies by name.
        $seenCid = [];
        foreach ($cands as $c) {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { continue; }
            if (isset($seenCid[$cd->classId])) { continue; }
            $seenCid[$cd->classId] = true;
            $tgt = $targets[$c];
            $rtKey = '';
            if ($boxCell && !isset($erasedSyms[$tgt])) {
                $rtKey = $this->resolveMethodClass($c, $method);
            }
            $key = $tgt . '|' . $rtKey;
            if (!isset($armLabel[$key])) {
                $armLabel[$key] = $this->ssa->allocLabel('vd.case');
                $armCand[$key] = $c;
                $armOrder[] = $key;
            }
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $armLabel[$key] . "\n";
        }
        foreach ($armOrder as $key) {
            $c = $armCand[$key];
            $caseLabel = $armLabel[$key];
            $r = $this->ssa->allocReg();
            $bodies .= $caseLabel . ":\n";
            // The shared $argList was coerced ONCE, to the FALLBACK's signature.
            // Candidates reached through an erased receiver need not agree on it:
            // symfony's closure sees `Input::getArgument(string)` and
            // `InputDefinition::getArgument(string|int)` — six raw-string arms and
            // one CELL arm — so the single `cell_to_strptr` the call site emitted
            // handed the InputDefinition arm a raw pointer in a cell parameter.
            // Re-coerce per arm where the repr disagrees.
            $bodies .= $this->vdArmArgs($argList, $argOutTypes,
                                        $this->sigs->paramTypes[$targets[$c]] ?? [], $targets[$c]);
            $bodies .= '  ' . $r . ' = call i64 @manticore_' . $this->mangle($targets[$c])
                     . '(' . $this->vdArmList . ")\n";
            // Cell-typed result over candidates whose declared returns DISAGREE:
            // box each arm's raw return by its OWN return type so the merged value
            // is a uniform, self-describing cell (a mixed-repr raw merge would read
            // e.g. one arm's string pointer as an int). A candidate already
            // returning a cell passes through.
            if ($boxCell && !isset($erasedSyms[$targets[$c]])) {
                $rc = $this->resolveMethodClass($c, $method);
                $rt = ($rc !== '' ? ($this->sigs->returnType[$rc . '__' . $method] ?? null) : null);
                $bodies .= $this->boxRawValue($r, $rt);
                $r = $this->lastValue;
            }
            $bodies .= '  store i64 ' . $r . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $endLabel . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $rd = $this->ssa->allocReg();
        $out .= $defLabel . ":\n";
        // The default arm is a candidate like any other — it too can declare a
        // repr the site did not emit.
        $out .= $this->vdArmArgs($argList, $argOutTypes,
                                 $this->sigs->paramTypes[$fallback] ?? [], $fallback);
        $out .= '  ' . $rd . ' = call i64 @manticore_' . $this->mangle($fallback)
              . '(' . $this->vdArmList . ")\n";
        if ($boxCell) {
            $out .= $this->boxRawValue($rd, $this->sigs->returnType[$fallback] ?? null);
            $rd = $this->lastValue;
        }
        $out .= '  store i64 ' . $rd . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->vdResult = $loaded;
        return $out;
    }

    /**
     * A concrete-element array argument bound to a cell-element array param
     * (`mixed[]`) — the boundary that needs each element boxed. False for a
     * cell/unknown-element arg (already boxed / erased) or a non-array param.
     */
    private function cellArrayParamNeedsBoxing(?Type $param, Type $arg): bool
    {
        if ($param === null || !$param->isArray()) { return false; }
        $pe = $param->element;
        if ($pe === null || $pe->kind !== Type::KIND_CELL) { return false; }
        if (!$arg->isArray()) { return false; }
        $ae = $arg->element;
        return $ae !== null && $ae->kind !== Type::KIND_CELL && $ae->kind !== Type::KIND_UNKNOWN;
    }

    /**
     * The entry point of `$class::$method` a caller that knows only the erased
     * origin must use. For a reified specialization that is its erased thunk
     * (raw double in, tagged cell out); for every other class, the symbol it was
     * already given. Falls back to the raw entry if no thunk was emitted —
     * specializing did not move the ABI, so there is nothing to adapt.
     */
    private function erasedEntry(string $class, string $static, string $method, string $full): string
    {
        // The receiver's static type IS the specialization — the site speaks its
        // raw representation, so call the raw entry. This is also what keeps the
        // thunk itself from recursing: its body calls `$this->m()` on a
        // spec-typed `$this`, which lands here.
        if ($class === $static) { return $full; }
        $cd = $this->classes[$class] ?? null;
        if ($cd === null || $cd->originClass === '') { return $full; }
        $thunk = $class . '__' . $method . '$erased';
        return isset($this->sigs->paramTypes[$thunk]) ? $thunk : $full;
    }

    /**
     * Walk a class's parent chain to find the one that actually
     * declares `$method`. Returns '' when no ancestor declares it.
     */
    /**
     * Whether NOTHING in the compiled world can serve `$method` on a receiver
     * whose static type is `$class` — the question the DEFAULT dispatch arm was
     * answering on faith.
     *
     * Mirrors how the candidate set below is built, deliberately and in the same
     * order, so the predicate and the arms cannot disagree: a KNOWN class walks
     * its own chain, and an unknown or absent one (an interface, a `new $cls()`
     * union, a fully erased receiver) is served by any class that resolves the
     * name, which is exactly the scan the dispatch runs.
     *
     * Conservative on purpose — anything that might resolve makes it answer
     * FALSE. `__call` is not consulted here because both of its reroutes have
     * already run by the time this is asked.
     */
    private function methodHasNoImpl(string $class, string $method): bool
    {
        if ($method === '') { return false; }
        if ($class !== '' && isset($this->classes[$class])) {
            if ($this->methodResolvesToBody($class, $method)) { return false; }
            // A DESCENDANT may declare it even when the base does not — the
            // switch arms are built from exactly this set.
            foreach ($this->selfAndDescendants($class) as $d) {
                if ($this->methodResolvesToBody($d, $method)) { return false; }
            }
            return true;
        }
        foreach ($this->classes as $cd) {
            if ($this->methodResolvesToBody($cd->name, $method)) { return false; }
        }
        return true;
    }

    /**
     * Whether `$class::$method` resolves to a method with an emitted BODY.
     *
     * A declaration is not an implementation. `$this->initializeBundles()`
     * inside symfony/dependency-injection's `abstract class AbstractKernel`
     * resolves to that class's own ABSTRACT declaration, and asking only
     * whether something declared the name answered yes — so the call was
     * emitted direct, to a symbol nothing defines and nothing declares, and
     * clang rejected the entire module with `use of undefined value`. Every
     * concrete Kernel lives in framework-bundle, a package the tier excludes;
     * with none of them compiled, no instance of the class can exist and the
     * call is unreachable, which is exactly what the undefined-method throw
     * says. When a concrete subclass IS compiled it answers here and the call
     * dispatches normally.
     *
     * `paramTypes` is the emitter's ground truth for "a body exists" — the
     * dispatch-arm builder drops candidates on this same test. The LSB target
     * is accepted too, since a late-static-bound method is emitted under its
     * specialised symbol.
     */
    private function methodResolvesToBody(string $class, string $method): bool
    {
        $d = $this->resolveMethodClass($class, $method);
        if ($d === '') { return false; }
        if (isset($this->sigs->paramTypes[$d . '__' . $method])) { return true; }
        return isset($this->sigs->paramTypes[$this->lsbTarget($d, $method, $class)]);
    }

    private function resolveMethodClass(string $class, string $method): string
    {
        $c = $class;
        while ($c !== '') {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { return ''; }
            if (isset($cd->methodNames[$method])) { return $c; }
            $c = $cd->parent;
        }
        return '';
    }

    /**
     * The Generator iterator protocol as method calls on a frame ptr:
     * current()/key()/getReturn() read a frame slot; next()/rewind() drive
     * one resume; valid() primes a fresh generator then tests `state != -1`;
     * send($v) stores the inbound value and resumes, returning the new
     * current. Frame: [resume_fn@0, state@8, current@16, key@24, nextkey@32,
     * sent@40, retval@48].
     */
    private function emitGeneratorMethod(\Compile\Mir\MethodCall_ $mc): string
    {
        $out = $this->emitNode($mc->object);
        $out .= $this->coerceToPtr();
        $g = $this->lastValue;
        $m = $mc->method;
        // current/key/valid/rewind all observe the FIRST element, so they
        // prime a fresh generator (advance to the first yield) — PHP rewinds
        // implicitly on first access. next/send drive an already-primed one.
        // `current`@16 holds a tagged cell ({@see EmitLlvmGenerator::emitYield});
        // every reader unboxes to its own declared type. InferCalls types
        // current()/send()/throw() as the generator's element, so `$mc->type` IS
        // that channel — and for a cell/erased one the unbox is a no-op, which
        // leaves the self-describing cell the runtime classifiers want.
        if ($m === 'current') { $out .= $this->genPrimeIfFresh($g); $out .= $this->genFieldLoad($g, 16); $out .= $this->unboxCellToType($mc->type); $out .= $this->coerceToI64(); return $this->finishI64($out, $this->lastValue); }
        if ($m === 'key')     { $out .= $this->genPrimeIfFresh($g); $out .= $this->genFieldLoad($g, 24); return $this->finishI64($out, $this->lastValue); }
        if ($m === 'getReturn') { $out .= $this->genFieldLoad($g, 48); return $this->finishI64($out, $this->lastValue); }
        if ($m === 'rewind') { $out .= $this->genPrimeIfFresh($g); return $this->finishI64($out, '0'); }
        if ($m === 'next')   { $out .= $this->genResumeCall($g); return $this->finishI64($out, '0'); }
        if ($m === 'send') {
            $sentPtr = $this->ssa->allocReg();
            $out .= '  ' . $sentPtr . ' = getelementptr inbounds i8, ptr ' . $g . ", i64 40\n";
            if (\count($mc->args) >= 1) {
                // The yield expression is cell-typed — box the sent value so
                // `$x = yield` reads a valid cell (var_dump/echo correct).
                $out .= $this->emitNode($mc->args[0]);
                $out .= $this->boxToCell($mc->args[0]->type);
                $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $sentPtr . "\n";
            }
            $out .= $this->genResumeCall($g);
            $out .= $this->genFieldLoad($g, 16);
            $out .= $this->unboxCellToType($mc->type);
            $out .= $this->coerceToI64();
            return $this->finishI64($out, $this->lastValue);
        }
        if ($m === 'throw') {
            // Inject `$e` at the suspended yield: prime a fresh generator (so
            // it is parked at a yield), stash the exception in the pending-throw
            // global, then resume — the yield resume-point check raises it.
            // Returns the next yielded value (or propagates if uncaught).
            $out .= $this->genPrimeIfFresh($g);
            if (\count($mc->args) >= 1) {
                $out .= $this->emitNode($mc->args[0]);
                $out .= $this->coerceToPtr();
                $out .= '  store ptr ' . $this->lastValue . ", ptr @__mir_gen_throw\n";
            }
            $out .= $this->genResumeCall($g);
            $out .= $this->genFieldLoad($g, 16);
            $out .= $this->unboxCellToType($mc->type);
            $out .= $this->coerceToI64();
            return $this->finishI64($out, $this->lastValue);
        }
        if ($m === 'valid') {
            $out .= $this->genPrimeIfFresh($g);
            $statePtr = $this->ssa->allocReg();
            $out .= '  ' . $statePtr . ' = getelementptr inbounds i8, ptr ' . $g . ", i64 8\n";
            $st2 = $this->ssa->allocReg();
            $out .= '  ' . $st2 . ' = load i64, ptr ' . $statePtr . "\n";
            $ne = $this->ssa->allocReg();
            $out .= '  ' . $ne . ' = icmp ne i64 ' . $st2 . ", -1\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $ne . " to i64\n";
            return $this->finishI64($out, $z);
        }
        throw new \RuntimeException('EmitLlvm: unsupported Generator method ' . $m);
    }

    /** Resume a generator once iff it is not yet started (state == 0). */
    private function genPrimeIfFresh(string $g): string
    {
        $statePtr = $this->ssa->allocReg();
        $out = '  ' . $statePtr . ' = getelementptr inbounds i8, ptr ' . $g . ", i64 8\n";
        $st = $this->ssa->allocReg();
        $out .= '  ' . $st . ' = load i64, ptr ' . $statePtr . "\n";
        $fresh = $this->ssa->allocReg();
        $out .= '  ' . $fresh . ' = icmp eq i64 ' . $st . ", 0\n";
        $doL = $this->ssa->allocLabel('gm.prime');
        $skL = $this->ssa->allocLabel('gm.primed');
        $out .= '  br i1 ' . $fresh . ', label %' . $doL . ', label %' . $skL . "\n";
        $out .= $doL . ":\n" . $this->genResumeCall($g) . '  br label %' . $skL . "\n";
        $out .= $skL . ":\n";
        return $out;
    }

    /** Emit `load (frame + off)` into a fresh reg; sets $this->lastValue. */
    private function genFieldLoad(string $g, int $off): string
    {
        $p = $this->ssa->allocReg();
        $out = '  ' . $p . ' = getelementptr inbounds i8, ptr ' . $g . ', i64 ' . (string)$off . "\n";
        $v = $this->ssa->allocReg();
        $out .= '  ' . $v . ' = load i64, ptr ' . $p . "\n";
        $this->lastValue = $v;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * A class_id-switch arm's body reduced to a form that ignores which SSA
     * registers it happened to be given — two arms with the same canonical form
     * compute the same thing and can share one block.
     *
     * A property dispatch emits one arm per CLASS, but the arm is a function of
     * the SLOT (offset, width, signedness, declared type, array-hint), not of the
     * class: every class inheriting a property at the same offset emits a
     * byte-identical block down to the register numbering. On an erased receiver
     * with a deep hierarchy that is the bulk of the site.
     *
     * Only registers DEFINED inside the arm are renamed — an outer register
     * (the receiver pointer, the result slot, the merge label) keeps its name and
     * must therefore match exactly for two arms to be judged equal. That is what
     * makes this sound rather than a heuristic: identical remaining text plus a
     * consistent renaming of purely local names is the same straight-line block.
     */
    private function canonArm(string $body): string
    {
        // Pass 1: the names this block defines, in definition order.
        /** @var array<string, string> */
        $ren = [];
        $n = 0;
        $lines = \explode("\n", $body);
        foreach ($lines as $l) {
            $t = \ltrim($l);
            if ($t === '' || $t[0] !== '%') { continue; }
            $eq = \strpos($t, ' = ');
            if ($eq === false) { continue; }
            $name = \substr($t, 0, $eq);
            if (isset($ren[$name])) { continue; }
            $ren[$name] = '%#' . (string)$n;
            $n = $n + 1;
        }
        if ($ren === []) { return $body; }
        // Pass 2: rewrite every occurrence of those names. Hand-rolled rather
        // than a regex: `%r1` must not match inside `%r12`, and this runs per
        // dispatch arm on every polymorphic site in the module.
        $out = '';
        $len = \strlen($body);
        $i = 0;
        while ($i < $len) {
            $c = $body[$i];
            if ($c !== '%') { $out = $out . $c; $i = $i + 1; continue; }
            $j = $i + 1;
            while ($j < $len) {
                $d = $body[$j];
                if (($d >= 'a' && $d <= 'z') || ($d >= 'A' && $d <= 'Z')
                    || ($d >= '0' && $d <= '9') || $d === '_' || $d === '.') {
                    $j = $j + 1;
                    continue;
                }
                break;
            }
            $tok = \substr($body, $i, $j - $i);
            $out = $out . ($ren[$tok] ?? $tok);
            $i = $j;
        }
        return $out;
    }

    /** Whether `$cls` (or an ancestor) implements `$iface`. */
    private function classImplementsIface(string $cls, string $iface): bool
    {
        $seen = [];
        $stack = [$cls];
        while ($stack !== []) {
            $c = \ltrim((string)\array_pop($stack), '\\');
            if ($c === '' || isset($seen[$c])) { continue; }
            $seen[$c] = true;
            if ($c === $iface) { return true; }
            if (!isset($this->classes[$c])) { continue; }
            $cd = $this->classes[$c];
            foreach ($cd->interfaces as $i) { $stack[] = $i; }
            if ($cd->parent !== '') { $stack[] = $cd->parent; }
        }
        return false;
    }

    private function emitMethodCall(\Compile\Mir\MethodCall_ $n): string
    {
        $depDiag = $this->deprecatedMethodDiag($this->staticClassOf($n->object), $n->method, $n->line);
        if ($depDiag !== '') { return $depDiag . $this->emitMethodCallInner($n); }
        return $this->emitMethodCallInner($n);
    }

    private function emitMethodCallInner(\Compile\Mir\MethodCall_ $n): string
    {
        $mc = $n;
        // A method on a `#[TypeDef]` receiver: a direct call with the scalar as
        // the first argument. Nothing to dispatch on — the class is final and has
        // no runtime identity. Routed through the ordinary Call path so the
        // callee's declared param types drive the arg coercions.
        $td = $mc->object->type->typeDefClass();
        if ($td !== null && isset($this->typeDefs[$td])) {
            $tdArgs = [$mc->object];
            foreach ($mc->args as $a) { $tdArgs[] = $a; }
            return $this->emitNode(
                new \Compile\Mir\Call($td . '__' . $mc->method, $tdArgs, $n->type),
            );
        }
        if ($this->isGeneratorType($mc->object->type)) {
            return $this->emitGeneratorMethod($mc);
        }
        // Closure methods. `$fn->bindTo($obj, $scope?)` rebinds `$this`;
        // `$fn->call($obj, ...args)` rebinds then invokes in one step. Gated on
        // a closure receiver so a user class's own `call`/`bindTo` is untouched.
        $recvCls = $mc->object->type->class ?? '';
        $isClosureRecv = $mc->object->type->kind === Type::KIND_CLOSURE
            || \str_starts_with($recvCls, '__closure_');
        if ($isClosureRecv && $mc->method === 'bindTo' && \count($mc->args) >= 1) {
            return $this->emitClosureRebind($mc->object, $mc->args[0]);
        }
        if ($isClosureRecv && $mc->method === 'call' && \count($mc->args) >= 1) {
            $out = $this->emitClosureRebind($mc->object, $mc->args[0]);
            $bound = $this->lastValue;
            $argList = 'ptr ' . $bound;
            $argTypes = 'ptr';
            $k = \count($mc->args);
            // ⚠ `->call()` does NOT bind by reference, and neither do we. Its
            // own signature is `call(?object $newThis, mixed ...$args)`, so the
            // arguments are already by-value copies by the time they are
            // forwarded — php warns "Argument #N must be passed by reference,
            // value given" and passes the value. Teaching this site the dynamic
            // by-ref mask would bind where the oracle does not. Verified
            // against php 8.5 in tests/aot/cases/closure_byref_rebind.php.
            $fpi = $this->ssa->allocReg();
            $out .= '  ' . $fpi . ' = load i64, ptr ' . $bound . "\n";
            $callGate = $this->closureRefGate($k - 1);
            $callMask = '';
            if ($callGate !== 0) {
                $out .= $this->closureRefMaskChain($fpi);
                $callMask = $this->lastValue;
            }
            for ($ai = 1; $ai < $k; $ai = $ai + 1) {
                $a = $mc->args[$ai];
                $slot = $ai - 1;
                if ($callMask !== '' && ($callGate & (1 << $slot)) !== 0) {
                    // Still BY VALUE — only the callee's unconditional deref is
                    // accommodated. See emitDynByRefValueArg.
                    $out .= $this->emitDynByRefValueArg($a, $callMask, $slot);
                } else {
                    $out .= $this->emitNode($a);
                    if ($this->isCellBoxableArg($a->type)) { $out .= $this->boxToCell($a->type); }
                    else { $out .= $this->coerceToI64(); }
                }
                $argList .= ', i64 ' . $this->lastValue;
                $argTypes .= ', i64';
            }
            $fp = $this->ssa->allocReg();
            $out .= '  ' . $fp . ' = inttoptr i64 ' . $fpi . " to ptr\n";
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 (' . $argTypes . ') ' . $fp . '(' . $argList . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            if ($this->isCellScalarParam($n->type)) { $out .= $this->unboxCellToType($n->type); }
            return $out;
        }
        // Method overloading: an unresolved instance method on a class that
        // defines __call reroutes to `$obj->__call('name', [args])` — rebuilt as
        // a real MethodCall so the normal arg-boxing / dispatch path applies.
        $mcStatic = $mc->object->type->class ?? '';
        if ($mcStatic !== '' && isset($this->classes[$mcStatic])
            && $this->resolveMethodClass($mcStatic, $mc->method) === ''
            && $this->resolveMethodClass($mcStatic, '__call') !== '') {
            $elems = [];
            foreach ($mc->args as $a) { $elems[] = new \Compile\Mir\ArrayElement_(null, $a); }
            $argsArr = new \Compile\Mir\ArrayLit($elems, Type::vec(Type::cell()));
            $nameNode = new \Compile\Mir\StringConst($mc->method, Type::string_());
            $call = new \Compile\Mir\MethodCall_($mc->object, '__call', [$nameNode, $argsArr], $mc->type);
            return $this->emitMethodCall($call);
        }
        // Same reroute with the receiver's class ERASED. Only when NO class in
        // the table declares the method: then every possible runtime receiver
        // answers through __call, and the rewritten call's own virtual dispatch
        // picks the right declarer. (`__call` is a real method, so that dispatch
        // never lands back here — no recursion.)
        //
        // The MIXED case — some classes declare the method, others only __call —
        // is NOT handled: the two shapes need different argument lists, so they
        // cannot share one switch, and the arg emission below is already built
        // against the resolved callee's signature. Recorded in docs/ROADMAP.md.
        if (!isset($this->classes[$mcStatic]) && $this->anyClassDeclares('__call')
            && !$this->anyClassDeclares($mc->method)) {
            $elems = [];
            foreach ($mc->args as $a) { $elems[] = new \Compile\Mir\ArrayElement_(null, $a); }
            $argsArr = new \Compile\Mir\ArrayLit($elems, Type::vec(Type::cell()));
            $nameNode = new \Compile\Mir\StringConst($mc->method, Type::string_());
            $call = new \Compile\Mir\MethodCall_($mc->object, '__call', [$nameNode, $argsArr], $mc->type);
            return $this->emitMethodCall($call);
        }
        $out = $this->emitNode($mc->object);
        $out .= $this->coerceToI64();
        $thisArg = $this->lastValue;
        // A `mixed`/cell receiver carries a NaN-boxed object — strip the tag to
        // the raw object pointer so both the `$this` arg and the class_id
        // virtual dispatch read the object, not the boxed bits (else SIGSEGV).
        // UNKNOWN counts: an untyped property (`public $defn;`) is stored boxed
        // but reads back as unknown, and the class_id load then dereferenced the
        // OBJECT tag itself. Masking a raw pointer is a no-op — every userspace
        // address fits in the 48 payload bits, which is why the `$this` slot is
        // masked unconditionally too.
        if ($mc->object->type->kind === Type::KIND_CELL
            || $mc->object->type->kind === Type::KIND_UNKNOWN) {
            $unb = $this->ssa->allocReg();
            $out .= '  ' . $unb . ' = and i64 ' . $thisArg . ", 281474976710655\n";
            $thisArg = $unb;
        }
        $argList = 'i64 ' . $thisArg;
        $argTemps = [];
        $cellBoxSlots = [];
        $reboxSlots = [];
        $reboxTmps = [];
        $cellBoxTmps = [];
        $static = $mc->object->type->class ?? '';
        $fallback = $this->resolveMethodClass($static, $mc->method);
        if ($fallback === '') { $fallback = $static; }
        if ($static !== '' && !isset($this->classes[$static])) {
            foreach ($this->classes as $cd) {
                if (!$this->classImplementsIface($cd->name, $static)) { continue; }
                $r = $this->resolveMethodClass($cd->name, $mc->method);
                if ($r !== '') { $fallback = $r; break; }
            }
            if ($fallback === $static) {
                foreach ($this->classes as $cd) {
                    $r = $this->resolveMethodClass($cd->name, $mc->method);
                    if ($r !== '') { $fallback = $r; break; }
                }
            }
        }
        // A fully ERASED receiver (`public $defn;` with no declared type) leaves
        // $static empty, so neither branch above ran and the parameter tables
        // below were looked up under an empty key: the site coerced its args
        // against an EMPTY signature and handed a `string|int` CELL param a RAW
        // int. symfony's ArgvInput reaches InputDefinition::hasArgument that way
        // and the -1 of `hasArgument($c - 1)` was read as a NaN-boxed pointer.
        // The class_id switch below already scans every class for the method,
        // and in the same order — resolve here too, so the ONE coercion the call
        // site emits speaks the ABI the arms were selected for.
        if ($static === '' && $fallback === '') {
            foreach ($this->classes as $cd) {
                $r = $this->resolveMethodClass($cd->name, $mc->method);
                if ($r !== '') { $fallback = $r; break; }
            }
        }
        // An ENUM method takes its case ORDINAL as `$this`, not a pointer. A
        // cell receiver (`?Enum` is a cell — an ordinal cannot carry null, see
        // LowerTypes::classHintType) holds box_object(singleton), and the mask
        // above left the SINGLETON POINTER in $thisArg: `$this === Enum::Case`
        // then compared a pointer against an ordinal and answered false for
        // every case. Load the ordinal the singleton records, the same +16 slot
        // unboxCellToType's enum arm reads.
        //
        // Only when the enum is the SOLE declarer of the name: with a non-enum
        // candidate in play the class_id switch below dispatches on $thisArg as
        // an object, and an ordinal has no header to read.
        if ($fallback !== '' && isset($this->enums[$fallback])
            && ($mc->object->type->kind === Type::KIND_CELL
                || $mc->object->type->kind === Type::KIND_UNKNOWN)
            && !$this->nonEnumDeclares($mc->method)) {
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $thisArg . " to ptr\n";
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = getelementptr i8, ptr ' . $p . ", i64 16\n";
            $ord = $this->ssa->allocReg();
            $out .= '  ' . $ord . ' = load i64, ptr ' . $g . "\n";
            $thisArg = $ord;
            $argList = 'i64 ' . $thisArg;
        }
        // Nothing implements this method — anywhere. Every switch ARM below is
        // already dropped when its candidate cannot resolve the method (see the
        // `$t === ''` skip), but the DEFAULT arm still named a callee on faith,
        // so the module got a symbol nothing defines and clang failed the build.
        // php answers that at RUNTIME, when the call is reached, so this does
        // too — a build must not die over a branch that never runs.
        //
        // symfony/cache reaches it through an OPTIONAL dependency: a
        // `MessageBusInterface` property with symfony/messenger absent leaves
        // the hint pointing at a type no compiled class implements, and asking
        // its result for `->last(...)` put an undefined `Http\Response::last`
        // in the module and failed the whole tier-2 link.
        //
        // Placed HERE, before the arguments are emitted, because php does not
        // evaluate them: `$e->alsoMissing(mark('one'))` raises without ever
        // running `mark`. The RECEIVER is already emitted above, which php also
        // does — it has to, to know the class it is complaining about. Both
        // `__call` reroutes ran far above, so their absence is settled.
        if ($this->methodHasNoImpl($static, $mc->method)) {
            $name = $static !== '' ? $static : 'object';
            $thr = new \Compile\Mir\Call(
                '__mir_throw_error',
                [new \Compile\Mir\StringConst(
                    'Call to undefined method ' . $name . '::' . $mc->method . '()',
                    Type::string_())],
                Type::cell(),
            );
            return $out . $this->emitNode($thr);
        }
        // By-ref mask of the resolved callee. A method's param 0 is the
        // implicit `$this`, so call arg index `ai` maps to param `ai + 1` —
        // forward the slot address rather than the dereferenced value, or a
        // recursive `array &$param` call corrupts (the callee re-derefs it).
        $mask = $this->sigs->refParams[$fallback . '__' . $mc->method] ?? [];
        $ptypes = $this->sigs->paramTypes[$fallback . '__' . $mc->method] ?? [];
        $tmask = $this->sigs->taggedParams[$fallback . '__' . $mc->method] ?? [];
        $ahmask = $this->sigs->arrayHintedParams[$fallback . '__' . $mc->method] ?? [];
        // The repr each argument is ACTUALLY emitted in, indexed like the
        // parameter list (0 is `$this`). A virtual-dispatch arm that disagrees
        // with the fallback's signature has to re-coerce, and it can only do
        // that from what the site really produced — the fallback's DECLARED
        // type is a different thing, and reading `$c - 1` as the `string` the
        // fallback declares is how an int -1 became a pointer. An index left
        // unset (by-ref, spread, pre-boxed array) is skipped by the fixup.
        $argOutTypes = [];
        // Raw caller lvalue → cell by-ref param: scratch slots to write back
        // after the call, parallel with the caller's own types.
        $cellBoxSlots = [];
        $cellBoxTmps = [];
        $cellBoxTypes = [];
        $ai = 0;
        foreach ($this->faCallArgsRecv($fallback . '__' . $mc->method, $mc->args) as $a) {
            // `$obj->m(...$arr)`: expand across the method's declared params
            // (index 0 is `$this`, so call arg `ai` is param `ai+1`).
            if ($a->kind === Node::KIND_SPREAD) {
                // NOT expanded here: the pack's tail is filled from the CALLEE's
                // defaults, and an erased receiver's arms need not share the
                // fallback's signature — `$o->__construct(...['C'])` took some
                // unrelated class's `= 0` for its second param. Each arm (and
                // the single-target path) expands it against its own params.
                $out .= $this->emitNode($a->operand);
                $out .= $this->coerceToPtr();
                $this->spreadTail = [$this->lastValue, $ai + 1, $a->operand->type->element ?? null];
                $ai = \count($ptypes) - 1;
                continue;
            }
            if ($this->argIsByRef($mask, $ai + 1, $a)
                && $this->isByRefAddressable($a)
                && $this->byRefNeedsCellUnbox($a, $ptypes, $ai + 1)
            ) {
                // A CELL lvalue handed to a raw-payload by-ref param — what a
                // VIVIFIED out-variable always is. Hand over an untagged scratch
                // slot and re-box afterwards; passing the cell slot itself makes
                // the callee dereference the tag bits and `$obj->fill(1, $out)`
                // read back float(6.36E-314).
                $out .= $this->emitByRefCellUnboxArg($a);
                $argList .= ', i64 ' . $this->lastValue;
                $reboxSlots[] = $this->refBoxSlot;
                $reboxTmps[] = $this->refBoxTmp;
            } elseif ($this->argIsByRef($mask, $ai + 1, $a)
                && $this->isByRefAddressable($a)
                && $this->byRefNeedsCellBox($a, $ptypes, $ai + 1)
            ) {
                // A raw lvalue handed to a `mixed &$var` param: box into a
                // scratch cell and put back what the callee left. Without it
                // `PDOStatement::bindParam(mixed &$var)` read an `int 3` as
                // float(1.5E-323) and bound that.
                $out .= $this->emitByRefCellBox($a);
                $argList .= ', i64 ' . $this->lastValue;
                $cellBoxSlots[] = $this->refBoxSlot;
                $cellBoxTmps[] = $this->refBoxTmp;
                $cellBoxTypes[] = $a->type;
            } elseif ($this->argIsByRef($mask, $ai + 1, $a)) {
                $out .= $this->emitByRefArg($a);
                $argList .= ', i64 ' . $this->lastValue;
            } elseif (($tmask[$ai + 1] ?? false) && $a->type->kind !== Type::KIND_CELL) {
                // A tagged (mixed/union) param: NaN-box the arg by its static
                // type so the callee reads its runtime tag — mirrors the
                // free-function call path (else a `mixed $x` method param
                // receives a raw array/string and mis-reads it).
                $out .= $this->emitNode($a);
                $out .= $this->boxToCell($a->type);
                $argList .= ', i64 ' . $this->lastValue;
                $argOutTypes[$ai + 1] = Type::cell();
            } elseif ($this->cellArrayParamNeedsBoxing($ptypes[$ai + 1] ?? null, $a->type)) {
                // A concrete-element array (vec[int] …) passed to a cell-element
                // array param (`mixed[]`): rebuild it with each element boxed,
                // then untag the resulting array cell back to the raw vec[cell]
                // pointer the param ABI expects. This is the reflection
                // invokeArgs / newInstanceArgs path — the only way a runtime
                // array reaches a trampoline's `vec[cell]` args param, and it
                // cannot go through Monomorphize (a method param is never
                // specialized; the indirect trampoline call is invisible anyway).
                $out .= $this->emitNode($a);
                $out .= $this->boxToCell($a->type);
                $raw = $this->ssa->allocReg();
                $out .= '  ' . $raw . ' = and i64 ' . $this->lastValue
                      . ", 281474976710655\n";   // PAYLOAD_MASK: array cell → raw ptr
                $argList .= ', i64 ' . $raw;
            } else {
                $out .= $this->emitNode($a);
                $out .= $this->coerceToI64();
                $out .= $this->unboxCellArg($a, $ptypes, $ai + 1, $ahmask);
                $argList .= ', i64 ' . $this->lastValue;
                // unboxCellArg lowers a CELL arg to the param's repr; everything
                // else crosses in the argument's own.
                $pt = $ptypes[$ai + 1] ?? null;
                $argOutTypes[$ai + 1] = ($a->type->kind === Type::KIND_CELL && $pt !== null)
                    ? $pt : $a->type;
                if ($this->isFreshStringTemp($a)) { $argTemps[] = $this->lastValue; }
            }
            $ai = $ai + 1;
        }
        $out .= $this->surplusArgEffects($fallback . '__' . $mc->method, $mc->args, 1);
        // Pad omitted trailing optionals: a typed-receiver call (`$x->m()`)
        // isn't default-filled at lowering (class unknown pre-InferTypes),
        // so the callee would read an uninitialized arg register. Param 0 is
        // `$this`, so provided params cover indices [0 .. $ai].
        $out .= $this->emitDefaultArgPad($fallback . '__' . $mc->method, $ai + 1, true);
        $argList .= $this->lastPadArgs;
        // The pad above is the FALLBACK's. Record what the site really wrote so
        // a dispatch arm can cut back to it ({@see vdArmArity}).
        $this->vdSiteArgc = $ai + 1;

        // Virtual dispatch: if any descendant of the static type
        // resolves `$method` to a different class, switch on the
        // runtime class_id. Monomorphic sites stay direct calls.
        $cands = $this->selfAndDescendants($static);
        // A union receiver (`B|C`): candidates are exactly the union's atoms and
        // their descendants — a PRECISE set, not every class declaring the method
        // (the classless fallback below). The fallback impl is the first atom that
        // resolves it.
        $isUnion = $mc->object->type->kind === Type::KIND_UNION;
        if ($isUnion) {
            // Dedupe: atoms can be in a subclass relation (`A|B` with B extends
            // A), so A's descendants already include B — a duplicate class_id
            // would emit a duplicate switch case.
            $cands = [];
            $seen = [];
            foreach ($mc->object->type->atoms as $atom) {
                foreach ($this->selfAndDescendants($atom->class ?? '') as $d) {
                    if (!isset($seen[$d])) { $seen[$d] = true; $cands[] = $d; }
                }
            }
            if ($fallback === '' || $fallback === $static) {
                foreach ($mc->object->type->atoms as $atom) {
                    $r = $this->resolveMethodClass($atom->class ?? '', $mc->method);
                    if ($r !== '') { $fallback = $r; break; }
                }
            }
        }
        // Interface-typed / unknown receiver (e.g. `catch (\Throwable $e)`):
        // candidates are every class that resolves `$method`, since such
        // a receiver isn't reachable via the extends chain. The thrown
        // object's runtime class_id selects the right impl.
        if (!$isUnion && !isset($this->classes[$static])) {
            $firstImpl = '';
            \Compile\Stats::bump('dispatch.iface_sites', 1);
            \Compile\Stats::bump('dispatch.iface_classes_scanned', \count($this->classes));
            foreach ($this->classes as $cd) {
                if ($this->resolveMethodClass($cd->name, $mc->method) !== '') {
                    $cands[] = $cd->name;
                    if ($firstImpl === '') { $firstImpl = $cd->name; }
                }
            }
            if ($fallback === $static && $firstImpl !== '') {
                $r = $this->resolveMethodClass($firstImpl, $mc->method);
                if ($r !== '') { $fallback = $r; }
            }
        }
        // Each candidate maps to the function honouring its own late-static
        // scope: a B object reaching A's LSB method runs `A__M__lsbB`. This
        // also makes inherited LSB methods polymorphic — distinct specialised
        // targets force the runtime class_id switch below even with no
        // override (so `static::` binds to the real object's class).
        $targets = [];
        $distinct = [];
        $liveCands = [];
        // Symbols that are ERASED THUNKS (erasedEntry rewrote them): a thunk
        // already boxes its result to a cell, so the per-arm cell-boxing below
        // must NOT box it again (double-box → a raw int read as a NaN cell).
        $erasedSyms = [];
        foreach ($cands as $c) {
            $t = $this->resolveMethodClass($c, $mc->method);
            // A candidate that neither declares nor inherits the method is
            // unreachable at this call site (calling it when the runtime object
            // IS that class fatals in PHP), so it gets no switch case. This
            // arises from a broad `new $cls()` union whose atoms are every
            // ctor-arity match, most of which lack the invoked method — falling
            // back to an unrelated impl would emit a call to a `Class__method`
            // symbol that was never defined.
            if ($t === '') { continue; }
            $full = $this->lsbTarget($t, $mc->method, $c);
            // A REIFIED specialization reached through its erased origin: this
            // switch only exists because the receiver's static type is a
            // SUPERTYPE of the candidate (a spec has no subclasses of its own, so
            // a spec-typed receiver dispatches monomorphically and never gets
            // here). The site therefore reads the result — and passes its args —
            // in the ORIGIN's erased representation, which the spec's raw entry
            // does not speak. Call its erased thunk instead (see LowerReify).
            $fullPre = $full;
            $full = $this->erasedEntry($c, $static, $mc->method, $full);
            if ($full !== $fullPre) { $erasedSyms[$full] = true; }
            // An ABSTRACT method (declared, no emitted body) has no function —
            // an abstract class is never instantiated, so its switch case is
            // dead and would reference an undefined symbol. Drop the candidate.
            if (!isset($this->sigs->paramTypes[$full])) { continue; }
            $liveCands[] = $c;
            $targets[$c] = $full;
            if (\Compile\Stats::$on) { \Compile\Stats::bump('dispatch.in_array_probes', \count($distinct)); }
            if (!\in_array($full, $distinct, true)) { $distinct[] = $full; }
        }
        \Compile\Stats::bump('dispatch.arms_emitted', \count($distinct));
        $fallbackFull = $this->lsbTarget($fallback, $mc->method, $static);
        // The static receiver's own method may be abstract (`$this->m()` inside
        // an abstract base) — fall back to a concrete implementation.
        if (!isset($this->sigs->paramTypes[$fallbackFull])) {
            $fallbackFull = $distinct[0] ?? $fallbackFull;
        }
        $btName = '';
        if ($this->rt->needsBacktrace) {
            // Push a bare method-name placeholder + the call-site line. The
            // callee overwrites the name with "Class->method" at its entry
            // (a stable receiver class isn't available here under the self-host).
            $btName = $mc->method;
            $out .= $this->btPush($btName, $n->line);
        }
        $faCands = $distinct;
        $faCands[] = $fallbackFull;
        $out .= $this->faPushAny($faCands, $mc->srcArgc, $mc->args, 1);
        // A CELL result over candidates whose declared returns disagree: each
        // dispatch arm boxes its raw return by its own type (a mixed-repr raw
        // merge would mis-read). The result is then a uniform self-describing cell.
        $boxCell = $n->type->kind === Type::KIND_CELL;
        if (\count($distinct) <= 1) {
            $sym = $distinct[0] ?? $fallbackFull;
            // Absent-optional-dependency method call: no candidate resolved the
            // method AND the receiver's static class is genuinely ABSENT (never
            // compiled — an EventDispatcher / ext type that was not installed). A
            // real call is php's "undefined method" Error, and every such site is
            // dead null-guarded integration code. Degrade to null rather than a
            // direct call to an undefined `@manticore_<empty>__<method>` symbol (a
            // link error). The `!isset($this->classes[$static])` guard keeps this
            // OFF known classes — a legit trampoline / cross-module callee whose
            // sig is not in paramTypes must NOT be nulled (2-gen safety).
            if ($distinct === [] && !isset($this->sigs->paramTypes[$sym])
                && ($static === '' || !isset($this->classes[$static]))) {
                if ($btName !== '') { $out .= $this->btPop(); }
                $this->spreadTail = null;
                $this->lastValue = '0';
                $this->lastValueType = 'i64';
                return $out;
            }
            // The single resolved target need not be the one the arguments were
            // coerced for either (an erased receiver picks the fallback by
            // method name, then resolves exactly one live candidate).
            $out .= $this->vdArmArgs($argList, $argOutTypes, $this->sigs->paramTypes[$sym] ?? [], $sym);
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($sym)
                  . '(' . $this->vdArmList . ")\n";
            // An erased thunk already returns a cell; boxing it again double-boxes.
            if ($boxCell && !isset($erasedSyms[$sym])) {
                $out .= $this->boxRawValue($reg, $this->sigs->returnType[$sym] ?? null);
                $reg = $this->lastValue;
            }
        } else {
            $out .= $this->emitVirtualDispatch($thisArg, $argList, $liveCands, $targets, $fallbackFull, $mc->method, $boxCell, $erasedSyms, $argOutTypes);
            $reg = $this->vdResult;
        }
        $this->spreadTail = null;
        if ($btName !== '') { $out .= $this->btPop(); }
        $out .= $this->emitByRefCellRebox($reboxSlots, $reboxTmps);
        $out .= $this->freeStrArgTemps($argTemps);
        $ci = 0;
        foreach ($cellBoxTmps as $ctmp) {
            $out .= $this->emitByRefCellWriteBack($ctmp, $cellBoxSlots[$ci], $cellBoxTypes[$ci]);
            $ci = $ci + 1;
        }
        // By-ref return (`function &m()`): the callee yields the field/slot
        // ADDRESS as i64. In value context deref it; a `$r = &$obj->m()`
        // (rawRefCall) keeps the raw address so RefBind can alias through it.
        if (($this->sigs->returnsByRef[$fallbackFull] ?? false) && !$this->rawRefCall) {
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
            $dv = $this->ssa->allocReg();
            $out .= '  ' . $dv . ' = load i64, ptr ' . $p . "\n";
            // Value context takes a COPY ({@see byRefValueCopyRetainIr}).
            $out .= $this->byRefValueCopyRetainIr($dv);
            $reg = $dv;
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    /**
     * Release fresh string-temp call args (collected as i64 carriers) now
     * the callee has read + retained any it kept. Shared by emitCall /
     * emitNewObj / emitMethodCall / emitStaticCall.
     * @param string[] $i64regs
     */
    private function freeStrArgTemps(array $i64regs): string
    {
        $out = '';
        foreach ($i64regs as $tv) {
            $tp = $this->ssa->allocReg();
            $out .= '  ' . $tp . ' = inttoptr i64 ' . $tv . " to ptr\n";
            $out .= '  call void @__mir_rc_release_str(ptr ' . $tp . ")\n";
            $this->rt->needsStrRc = true;
        }
        return $out;
    }

    private function emitUnionPropertyAccess(PropertyAccess_ $pa): string
    {
        $atoms = $pa->object->type->atoms;
        $offByClass = [];
        $firstOff = -1;
        $agree = true;
        foreach ($atoms as $atom) {
            $ac = $atom->class ?? '';
            $cd = $this->classes[$ac] ?? null;
            $o = $cd !== null ? $cd->propertyOffset($pa->property) : -1;
            if ($o < 0) { $o = 16; }
            $offByClass[$ac] = $o;
            if ($firstOff === -1) { $firstOff = $o; }
            elseif ($firstOff !== $o) { $agree = false; }
        }
        $out = $this->emitNode($pa->object);
        $out .= $this->coerceToPtr();
        $objPtr = $this->lastValue;
        if ($agree) {
            $gep = $this->ssa->allocReg();
            $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$firstOff . "\n";
            $loaded = $this->ssa->allocReg();
            $out .= '  ' . $loaded . ' = load i64, ptr ' . $gep . "\n";
            $this->lastValue = $loaded;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $endL = $this->ssa->allocLabel('up.end');
        $defL = $this->ssa->allocLabel('up.def');
        $switch = '  switch i64 ' . $cid . ', label %' . $defL . " [\n";
        $bodies = '';
        $seen = [];
        foreach ($atoms as $atom) {
            $ac = $atom->class ?? '';
            foreach ($this->selfAndDescendants($ac) as $c) {
                $cd = $this->classes[$c] ?? null;
                if ($cd === null || isset($seen[$c])) { continue; }
                $seen[$c] = true;
                $caseL = $this->ssa->allocLabel('up.case');
                $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $caseL . "\n";
                $g = $this->ssa->allocReg();
                $bodies .= $caseL . ":\n";
                $bodies .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objPtr
                         . ', i64 ' . (string)$offByClass[$ac] . "\n";
                $vv = $this->ssa->allocReg();
                $bodies .= '  ' . $vv . ' = load i64, ptr ' . $g . "\n";
                $bodies .= '  store i64 ' . $vv . ', ptr ' . $res . "\n";
                $bodies .= '  br label %' . $endL . "\n";
            }
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $gd = $this->ssa->allocReg();
        $out .= $defL . ":\n";
        $out .= '  ' . $gd . ' = getelementptr inbounds i8, ptr ' . $objPtr
              . ', i64 ' . (string)$firstOff . "\n";
        $vd = $this->ssa->allocReg();
        $out .= '  ' . $vd . ' = load i64, ptr ' . $gd . "\n";
        $out .= '  store i64 ' . $vd . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->ssa->allocReg();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** `$union->prop = v` — the store analogue of {@see emitUnionPropertyAccess}.
     *  Writes the value to the correct slot: one offset when every atom agrees,
     *  else a runtime class_id switch to each atom's offset. The value is co-owned
     *  (rc retain) once, before the store, since the object now holds a reference. */
    private function emitUnionStoreProperty(\Compile\Mir\StoreProperty $n): string
    {
        $atoms = $n->object->type->atoms;
        $offByClass = [];
        $firstOff = -1;
        $agree = true;
        foreach ($atoms as $atom) {
            $ac = $atom->class ?? '';
            $cd = $this->classes[$ac] ?? null;
            $o = $cd !== null ? $cd->propertyOffset($n->property) : -1;
            if ($o < 0) { $o = 16; }
            $offByClass[$ac] = $o;
            if ($firstOff === -1) { $firstOff = $o; }
            elseif ($firstOff !== $o) { $agree = false; }
        }
        $out = $this->emitNode($n->object);
        $out .= $this->coerceToPtr();
        $objPtr = $this->lastValue;
        $out .= $this->emitNode($n->value);
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $out .= $this->rcRetainByType($n->value, $val, $n->type, 4);
        if ($agree) {
            $gep = $this->ssa->allocReg();
            $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$firstOff . "\n";
            $out .= '  store i64 ' . $val . ', ptr ' . $gep . "\n";
            $this->lastValue = $val;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $endL = $this->ssa->allocLabel('us.end');
        $defL = $this->ssa->allocLabel('us.def');
        $switch = '  switch i64 ' . $cid . ', label %' . $defL . " [\n";
        $bodies = '';
        $seen = [];
        foreach ($atoms as $atom) {
            $ac = $atom->class ?? '';
            foreach ($this->selfAndDescendants($ac) as $c) {
                $cd = $this->classes[$c] ?? null;
                if ($cd === null || isset($seen[$c])) { continue; }
                $seen[$c] = true;
                $caseL = $this->ssa->allocLabel('us.case');
                $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $caseL . "\n";
                $g = $this->ssa->allocReg();
                $bodies .= $caseL . ":\n";
                $bodies .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objPtr
                         . ', i64 ' . (string)$offByClass[$ac] . "\n";
                $bodies .= '  store i64 ' . $val . ', ptr ' . $g . "\n";
                $bodies .= '  br label %' . $endL . "\n";
            }
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $gd = $this->ssa->allocReg();
        $out .= $defL . ":\n";
        $out .= '  ' . $gd . ' = getelementptr inbounds i8, ptr ' . $objPtr
              . ', i64 ' . (string)$firstOff . "\n";
        $out .= '  store i64 ' . $val . ', ptr ' . $gd . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Byte offset of `$prop` on `$objExpr`, resolved through the
     * object's inferred `obj<Class>` type and the class table.
     * Falls back to offset 16 (first slot) when the class is
     * unknown — keeps codegen progressing rather than aborting,
     * though such a fallback only stays correct for single-property
     * classes.
     */
    /**
     * The ClassDef whose layout an `$obj->prop` access resolves against, or null.
     * The same walk `propertyOffset` does — the width of a slot and the offset of
     * a slot must never be read from different classes.
     */
    private function slotHolder(Node $objExpr, string $prop): ?ClassDef
    {
        $cls = $objExpr->type->class ?? '';
        if ($cls === '' || !isset($this->classes[$cls])) { return null; }
        if ($this->classes[$cls]->propertyOffset($prop) >= 0) {
            return $this->classes[$cls];
        }
        $sub = $this->subclassPropHolder($cls, $prop);
        return $sub;
    }

    /**
     * Whether the slot behind `$objExpr->$prop` holds a RAW array pointer.
     *
     * True for a declared array type AND for a bare `array` hint that erased to
     * KIND_UNKNOWN — the latter is the case the store path has to know about,
     * since `unboxCellToType` has nothing to unbox an `unknown` to and would
     * leave a NaN-tagged word in a slot every reader inttoptr's directly. The
     * hint is read from the SAME ClassDef the offset and width come from
     * ({@see slotHolder}), and `emitObjClone` uses the identical predicate to
     * decide that an array property is copied rather than co-owned.
     */
    /**
     * The destination type a property store's OWNERSHIP is decided by — the
     * declared property type, except that an array-hinted slot whose hint erased
     * to KIND_UNKNOWN answers `vec[unknown]` so it still reads as rc-managed.
     *
     * ⚠ The ONE owner of that question, for the same reason
     * {@see EmitLlvmArrays::storeElemBoxesValue} is: {@see emitStoreProperty}
     * reads it to emit the retain and {@see EmitLlvmMemory::collectTransferredLocals}
     * reads it to decide the source local's scope-exit release. Two copies drift,
     * and a drift here is a leak (pass says borrowed, emitter retains) or a
     * double free.
     *
     * Why the array hint has to be recovered at all: a bare `array` / `?array`
     * hint lowers to KIND_UNKNOWN, and a CONSTRUCTOR's parameter is erased too —
     * a `new C(…)` site never feeds the call-site element refinement that a
     * method call does. Both sides then looked non-rc, the store took NO
     * reference, and the object held a BORROWED buffer that the caller's
     * scope-exit release freed. The same store written as a method retains.
     */
    private function propStoreRetainType(\Compile\Mir\StoreProperty $n): ?Type
    {
        $pcls = $n->object->type->class ?? '';
        $propType = ($pcls !== '' && isset($this->classes[$pcls]))
            ? ($this->classes[$pcls]->propertyTypes[$n->property] ?? null)
            : null;
        if (($propType === null || !$propType->isArray())
            && $this->slotIsArrayHinted($n->object, $n->property, $propType)) {
            return Type::vec(Type::unknown());
        }
        return $propType;
    }

    private function slotIsArrayHinted(Node $objExpr, string $prop, ?Type $propType): bool
    {
        if ($propType !== null && $propType->isArray()) { return true; }
        $cd = $this->slotHolder($objExpr, $prop);
        if ($cd === null) { return false; }
        return $cd->propertyArrayHinted[$prop] ?? false;
    }

    /**
     * Load a property slot at its declared WIDTH, widened back to the carrier.
     *
     * A full-word slot is a plain `load i64` — what every property was before
     * `#[TypeDef(repr: …)]` could narrow one. A narrow slot loads exactly its own
     * bytes and widens: sign-extending for `i8`/`i16`/`i32`, zero-extending for
     * the unsigned reprs, and `fpext`ing an `f32` back to the double the rest of
     * the compiler works in. The VALUE the program sees is identical either way —
     * only the bytes on the heap differ, which is the whole point.
     */
    private function emitSlotLoad(string $gep, ?ClassDef $cd, string $prop, Type $t): string
    {
        $w = $cd !== null ? $cd->propertyWidth($prop) : 8;
        if ($w === 8) {
            $reg = $this->ssa->allocReg();
            $this->lastValue = $reg;
            $this->lastValueType = 'i64';
            return '  ' . $reg . ' = load i64, ptr ' . $gep . "\n";
        }
        // ALWAYS hands back i64 BITS, exactly as a full-word load does — every
        // caller then applies its own coercion (a float property bitcasts, an
        // object property masks the tag). An f32 that arrived here as a `double`
        // would be bitcast a second time and come out as garbage.
        if ($cd->propertyFloat32[$prop] ?? false) {
            $f = $this->ssa->allocReg();
            $d = $this->ssa->allocReg();
            $bits64 = $this->ssa->allocReg();
            $this->lastValue = $bits64;
            $this->lastValueType = 'i64';
            return '  ' . $f . ' = load float, ptr ' . $gep . "\n"
                 . '  ' . $d . ' = fpext float ' . $f . " to double\n"
                 . '  ' . $bits64 . ' = bitcast double ' . $d . " to i64\n";
        }
        $bits = (string)($w * 8);
        $nreg = $this->ssa->allocReg();
        $ext = $this->ssa->allocReg();
        $op = ($cd->propertySigned[$prop] ?? false) ? 'sext' : 'zext';
        $this->lastValue = $ext;
        $this->lastValueType = 'i64';
        return '  ' . $nreg . ' = load i' . $bits . ', ptr ' . $gep . "\n"
             . '  ' . $ext . ' = ' . $op . ' i' . $bits . ' ' . $nreg . " to i64\n";
    }

    /**
     * Store into a property slot at its declared WIDTH. The value arrives as the
     * carrier (i64, or a double for a float) and is truncated to the slot.
     *
     * Truncation is not a loss the program can observe: a `#[TypeDef]` value can
     * only have come from its own normaliser, which is what put it in range.
     */
    private function emitSlotStore(string $gep, ?ClassDef $cd, string $prop, string $val): string
    {
        $w = $cd !== null ? $cd->propertyWidth($prop) : 8;
        if ($w === 8) {
            return '  store i64 ' . $val . ', ptr ' . $gep . "\n";
        }
        if ($cd->propertyFloat32[$prop] ?? false) {
            $d = $this->ssa->allocReg();
            $f = $this->ssa->allocReg();
            return '  ' . $d . ' = bitcast i64 ' . $val . " to double\n"
                 . '  ' . $f . ' = fptrunc double ' . $d . " to float\n"
                 . '  store float ' . $f . ', ptr ' . $gep . "\n";
        }
        $bits = (string)($w * 8);
        $t = $this->ssa->allocReg();
        return '  ' . $t . ' = trunc i64 ' . $val . ' to i' . $bits . "\n"
             . '  store i' . $bits . ' ' . $t . ', ptr ' . $gep . "\n";
    }

    /**
     * The STATIC offset of `$prop` on `$objExpr`'s class, or null when no class
     * in the module puts it anywhere — i.e. when a static offset is a GUESS.
     *
     * The nullable form is the single source of truth; {@see propertyOffset}
     * is it plus the legacy slot-16 default. Callers that can do better on a
     * miss ask THIS one, so the "is a static offset knowable?" question and the
     * offset itself can never drift apart — the failure shape this audit has
     * paid for repeatedly (a guard and the thing it guards computed twice).
     */
    private function propertyOffsetOrNull(Node $objExpr, string $prop): ?int
    {
        $cls = $objExpr->type->class ?? '';
        if ($cls !== '' && isset($this->classes[$cls])) {
            $off = $this->classes[$cls]->propertyOffset($prop);
            if ($off >= 0) { return $off; }
            // `$prop` is declared only on a subclass: the static type
            // is a base class (e.g. `Stmt`) but the runtime object is
            // a subclass (`ClassStmt`) that adds `$prop`. Subclasses
            // prepend the parent's fields, so any subclass declaring
            // `$prop` carries it at a layout-consistent offset — borrow
            // it instead of falling through to the wrong slot 16 (which
            // would alias the base class's first property).
            $sub = $this->subclassPropOffset($cls, $prop);
            if ($sub >= 0) { return $sub; }
        }
        return null;
    }

    private function propertyOffset(Node $objExpr, string $prop): int
    {
        $off = $this->propertyOffsetOrNull($objExpr, $prop);
        return $off !== null ? $off : 16;
    }

    /**
     * Offset of `$prop` as declared by some subclass of `$base`, or -1
     * when no subclass declares it. Resolves base-typed reads of a
     * subclass-only field (`$stmt->decl` where `$stmt: Stmt` but the
     * object is a `ClassStmt`).
     */
    private function subclassPropOffset(string $base, string $prop): int
    {
        foreach ($this->classes as $cd) {
            if ($cd->name === $base) { continue; }
            if (!$this->classExtends($cd->name, $base)) { continue; }
            $off = $cd->propertyOffset($prop);
            if ($off >= 0) { return $off; }
        }
        return -1;
    }

    /** The subclass whose layout `subclassPropOffset` borrows — same walk, so the
     *  slot's WIDTH is read from the very class its OFFSET came from. */
    private function subclassPropHolder(string $base, string $prop): ?ClassDef
    {
        foreach ($this->classes as $cd) {
            if ($cd->name === $base) { continue; }
            if (!$this->classExtends($cd->name, $base)) { continue; }
            if ($cd->propertyOffset($prop) >= 0) { return $cd; }
        }
        return null;
    }

    /** Whether class `$name` transitively extends `$base`. */
    private function classExtends(string $name, string $base): bool
    {
        $cur = $name;
        while ($cur !== '' && isset($this->classes[$cur])) {
            $p = $this->classes[$cur]->parent;
            if ($p === $base) { return true; }
            $cur = $p;
        }
        return false;
    }
}
