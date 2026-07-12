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

    private function emitNewObj(\Compile\Mir\NewObj $n): string
    {
        $cd = $this->classes[$n->class] ?? null;
        $size = $cd === null ? 16 : $cd->instanceSize();
        $isStruct = $cd !== null && $cd->isStruct;
        // Header slot count before properties: 2 (class_id + rc) for a
        // normal object, 0 for a `#[Struct]` value type.
        $hdr = $isStruct ? 0 : 2;
        $obj = $this->allocSsa();
        $out = '  ' . $obj . ' = call ptr @__mir_alloc_tagged(i64 ' . (string)$size . ")\n";
        if (!$isStruct) {
            // header[0] = class descriptor ptr ({class_id, drop_fn}); class_id
            // and drop are read THROUGH it so drops compose across objects.
            $out .= '  store i64 ' . $this->descSlotValue($cd) . ', ptr ' . $obj . "\n";
            // header[1] = refcount = 1
            $rcGep = $this->allocSsa();
            $out .= '  ' . $rcGep . ' = getelementptr inbounds i64, ptr ' . $obj . ", i64 1\n";
            $out .= '  store i64 1, ptr ' . $rcGep . "\n";
        }
        // zero each property slot. A CELL (mixed / nullable-scalar `?int`)
        // property must default to a NaN-boxed NULL, not raw 0 — else a read /
        // var_dump dispatches on tag 0 (an invalid cell) and faults.
        if ($cd !== null) {
            $pi = 0;
            foreach ($cd->propertyNames as $pname) {
                $pGep = $this->allocSsa();
                $out .= '  ' . $pGep . ' = getelementptr inbounds i64, ptr '
                      . $obj . ', i64 ' . (string)($pi + $hdr) . "\n";
                $ptype = $cd->propertyTypes[$pname] ?? null;
                // A self-describing cell prop (scalar-nullable OR a `mixed` prop
                // only ever holding scalars) defaults to a boxed NULL so a read
                // dispatches by tag, not on a raw 0. A cell-array backing slot
                // (`$__s`, ever stored an array) stays raw 0.
                $initVal = $this->cellPropBoxed($ptype, $pname)
                    ? '-3659174697238528' : '0';
                $out .= '  store i64 ' . $initVal . ', ptr ' . $pGep . "\n";
                $pi = $pi + 1;
            }
            // Dynamic-property bag starts null (assoc_set allocates lazily).
            if ($cd->usesBag()) {
                $bGep = $this->allocSsa();
                $out .= '  ' . $bGep . ' = getelementptr inbounds i8, ptr '
                      . $obj . ', i64 ' . (string)$cd->bagOffset() . "\n";
                $out .= '  store i64 0, ptr ' . $bGep . "\n";
            }
        }
        // ctor call — resolve through the parent chain (a subclass
        // with no ctor inherits its parent's).
        $ctorClass = $this->resolveMethodClass($n->class, '__construct');
        if ($ctorClass !== '') {
            $objInt = $this->allocSsa();
            $out .= '  ' . $objInt . ' = ptrtoint ptr ' . $obj . " to i64\n";
            $argList = 'i64 ' . $objInt;
            $argTemps = [];
            // Ctor param 0 is the implicit `$this`, so call arg `ai` maps to
            // param `ai + 1` — unbox a cell arg bound to a scalar param.
            $ptypes = $this->fnParamTypes[$ctorClass . '____construct'] ?? [];
            $tmask = $this->fnTaggedParams[$ctorClass . '____construct'] ?? [];
            $ai = 0;
            foreach ($n->args as $a) {
                if (($tmask[$ai + 1] ?? false) && $a->type->kind !== Type::KIND_CELL) {
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
                        $d = $this->allocSsa();
                        $out .= '  ' . $d . ' = sitofp i64 ' . $this->lastValue . " to double\n";
                        $this->lastValue = $d;
                        $this->lastValueType = 'double';
                    }
                    $out .= $this->coerceToI64();
                    $out .= $this->unboxCellArg($a, $ptypes, $ai + 1);
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
            $cr = $this->allocSsa();
            $out .= '  ' . $cr . ' = call i64 @manticore_' . $this->mangle($ctorTarget)
                  . '(' . $argList . ")\n";
            $out .= $this->btPop();
            // Free fresh string-temp ctor args (the ctor retained any it
            // stored into a property), matching emitCall.
            $out .= $this->freeStrArgTemps($argTemps);
        }
        // Capture the thrown location + call stack into a Throwable at `new`
        // (PHP records these at construction), when the program queries a trace.
        if ($this->needsBacktrace && $cd !== null
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
        $lp = $this->allocSsa();
        $out .= '  ' . $lp . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$lineOff . "\n";
        $out .= '  store i64 ' . (string)$no->line . ', ptr ' . $lp . "\n";
        $fp = $this->allocSsa();
        $out .= '  ' . $fp . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$fileOff . "\n";
        $fstr = $this->allocSsa();
        $out .= '  ' . $fstr . ' = ptrtoint ptr ' . $this->strLitId($this->internString($this->sourceFile)) . " to i64\n";
        $out .= '  store i64 ' . $fstr . ', ptr ' . $fp . "\n";
        // Two packed vecs of the active frames, innermost first.
        $out .= $this->emitBtVec('@__mir_bt_name');
        $namesVec = $this->lastValue;
        $np = $this->allocSsa();
        $out .= '  ' . $np . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$nmOff . "\n";
        $out .= '  store i64 ' . $namesVec . ', ptr ' . $np . "\n";
        $out .= $this->emitBtVec('@__mir_bt_line');
        $linesVec = $this->lastValue;
        $lnp = $this->allocSsa();
        $out .= '  ' . $lnp . ' = getelementptr inbounds i8, ptr ' . $obj . ', i64 ' . (string)$lnOff . "\n";
        $out .= '  store i64 ' . $linesVec . ', ptr ' . $lnp . "\n";
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
            // Unknown / value-type class — nothing to deep-manage; pass through.
            $this->lastValue = $src; $this->lastValueType = 'ptr';
            return $out;
        }
        $size = $cd->instanceSize();
        $new = $this->allocSsa();
        $out .= '  ' . $new . ' = call ptr @__mir_alloc_tagged(i64 ' . (string)$size . ")\n";
        $out .= '  store i64 ' . $this->descSlotValue($cd) . ', ptr ' . $new . "\n";
        $rcGep = $this->allocSsa();
        $out .= '  ' . $rcGep . ' = getelementptr inbounds i64, ptr ' . $new . ", i64 1\n";
        $out .= '  store i64 1, ptr ' . $rcGep . "\n";
        // Copy each property slot; co-own rc-managed values (shallow copy).
        foreach ($cd->propertyNames as $pname) {
            $off = $cd->propertyOffset($pname);
            $sg = $this->allocSsa();
            $out .= '  ' . $sg . ' = getelementptr inbounds i8, ptr ' . $src . ', i64 ' . (string)$off . "\n";
            $v = $this->allocSsa();
            $out .= '  ' . $v . ' = load i64, ptr ' . $sg . "\n";
            $dg = $this->allocSsa();
            $out .= '  ' . $dg . ' = getelementptr inbounds i8, ptr ' . $new . ', i64 ' . (string)$off . "\n";
            $pt = $cd->propertyTypes[$pname] ?? null;
            // PHP arrays are VALUES: `clone` must copy each array property (a
            // fresh rc=1 owned buffer, no extra retain), not co-own the handle —
            // else a mutation on the clone (`$b->items[] = x`) aliases the
            // original. Fires for a typed array AND a bare `array` hint whose
            // element erased to unknown (still an array at runtime). A cell
            // (heterogeneous) element takes the tag-aware copy so a boxed inner
            // array separates too; a null slot passes through (copy is NULL-safe).
            $arrHint = ($cd->propertyArrayHinted[$pname] ?? false)
                || ($pt !== null && $pt->isArray());
            if ($arrHint) {
                $vp = $this->allocSsa();
                $out .= '  ' . $vp . ' = inttoptr i64 ' . $v . " to ptr\n";
                $isCellElem = $pt !== null && $pt->element !== null
                    && $pt->element->kind === Type::KIND_CELL;
                $cp = $this->allocSsa();
                $fn = $isCellElem ? '__mir_array_copy_cells' : '__mir_array_copy';
                $out .= '  ' . $cp . ' = call ptr @' . $fn . '(ptr ' . $vp . ")\n";
                $cpi = $this->allocSsa();
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
            $sg = $this->allocSsa();
            $out .= '  ' . $sg . ' = getelementptr inbounds i8, ptr ' . $src . ', i64 ' . (string)$bo . "\n";
            $bv = $this->allocSsa();
            $out .= '  ' . $bv . ' = load i64, ptr ' . $sg . "\n";
            $dg = $this->allocSsa();
            $out .= '  ' . $dg . ' = getelementptr inbounds i8, ptr ' . $new . ', i64 ' . (string)$bo . "\n";
            $out .= '  store i64 ' . $bv . ', ptr ' . $dg . "\n";
        }
        // __clone() hook on the fresh copy.
        $cloneCls = $this->resolveMethodClass($cls, '__clone');
        if ($cloneCls !== '') {
            $ni = $this->allocSsa();
            $out .= '  ' . $ni . ' = ptrtoint ptr ' . $new . " to i64\n";
            $cr = $this->allocSsa();
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
                $out .= $this->boxToCell($pair->value->type);
            } else {
                $out .= $this->coerceToI64();
            }
            $pv = $this->lastValue;
            $out .= $this->rcRetainByType($pair->value, $pv, $pt, 4);
            $dg = $this->allocSsa();
            $out .= '  ' . $dg . ' = getelementptr inbounds i8, ptr ' . $new . ', i64 ' . (string)$off . "\n";
            $out .= '  store i64 ' . $pv . ', ptr ' . $dg . "\n";
        }
        $this->lastValue = $new; $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitPropertyAccess(Node $n): string
    {
        $pa = $this->castPropertyAccess($n);
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
            $bg = $this->allocSsa();
            $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$bcd->bagOffset() . "\n";
            $bagI = $this->allocSsa();
            $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
            $bagP = $this->allocSsa();
            $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
            $kid = $this->internString($pa->property);
            $reg = $this->allocSsa();
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
            \error_log("UNKPROP\tfn=" . $this->currentFnName
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
        $out = $this->emitNode($pa->object);
        $out .= $this->coerceToPtr();
        $objPtr = $this->lastValue;
        $offset = $this->propertyOffset($pa->object, $pa->property);
        $gep = $this->allocSsa();
        $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr '
              . $objPtr . ', i64 ' . (string)$offset . "\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $gep . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $loaded . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        } elseif ($n->type->kind === Type::KIND_OBJ) {
            // An obj-typed property read whose slot may hold a boxed cell (a
            // `mixed` prop holding an object, or one narrowed via a property-path
            // instanceof) — strip the tag so the result is a clean obj ptr. The
            // 48-bit mask is identity on a real heap ptr (no-op for a raw obj).
            $masked = $this->allocSsa();
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
        // Pure dynamic-bag receiver — no concrete holder of $prop.
        if (\count($fixed) === 0) {
            return $out . $this->emitBagReadInto($pa, $objPtr);
        }
        // Static fast path: a single holder and no bag class anywhere, so the
        // cell can only be that class — read the slot with no class_id switch.
        if (\count($fixed) === 1 && !$hasBag) {
            return $out . $this->emitFixedPropLoad($objPtr, $fixed[0], $prop);
        }
        // Runtime dispatch on the object's class_id.
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $res = $this->allocSsa();
        $out .= '  ' . $res . " = alloca i64\n";
        $end = $this->allocLabel('cp.end');
        $def = $this->allocLabel('cp.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        foreach ($fixed as $cd) {
            $lbl = $this->allocLabel('cp.case');
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $bodies .= $this->emitFixedPropLoad($objPtr, $cd, $prop);
            $bodies .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $def . ":\n";
        if ($hasBag) {
            $out .= $this->emitBagReadInto($pa, $objPtr);
        } else {
            $this->needsTagged = true;
            $bn = $this->allocSsa();
            $out .= '  ' . $bn . " = call i64 @__manticore_box_null()\n";
            $this->lastValue = $bn;
        }
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Load `$prop` from `$objPtr` at `$cd`'s fixed slot and box it to a tagged
     * cell by the slot's declared type (an untyped / cell slot already holds a
     * cell → passthrough). lastValue ← the cell.
     */
    private function emitFixedPropLoad(string $objPtr, ClassDef $cd, string $prop): string
    {
        $off = $cd->propertyOffset($prop);
        $gep = $this->allocSsa();
        $out = '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr
             . ', i64 ' . (string)$off . "\n";
        $ld = $this->allocSsa();
        $out .= '  ' . $ld . ' = load i64, ptr ' . $gep . "\n";
        $out .= $this->boxRawValue($ld, $cd->propertyTypes[$prop] ?? null);
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
        $kid = $this->internString($pa->property);
        $reg = $this->allocSsa();
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
        $out = $this->emitNode($pa->object);
        $out .= $this->cellToPtr();
        $objPtr = $this->lastValue;
        // No known holder → nothing better than the historical raw slot-16 read.
        if (\count($fixed) === 0) {
            $gep = $this->allocSsa();
            $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr . ", i64 16\n";
            $ld = $this->allocSsa();
            $out .= '  ' . $ld . ' = load i64, ptr ' . $gep . "\n";
            $this->lastValue = $ld;
            $this->lastValueType = 'i64';
            return $out;
        }
        // A single holder → its real offset, boxed by its declared type.
        if (\count($fixed) === 1) {
            return $out . $this->emitFixedPropLoad($objPtr, $fixed[0], $prop);
        }
        // Dispatch on class_id: each holder reads (and boxes) its OWN slot.
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $res = $this->allocSsa();
        $out .= '  ' . $res . " = alloca i64\n";
        $end = $this->allocLabel('rp.end');
        $def = $this->allocLabel('rp.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $def . " [\n";
        $bodies = '';
        foreach ($fixed as $cd) {
            $lbl = $this->allocLabel('rp.case');
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $lbl . "\n";
            $bodies .= $lbl . ":\n";
            $bodies .= $this->emitFixedPropLoad($objPtr, $cd, $prop);
            $bodies .= '  store i64 ' . $this->lastValue . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $end . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $def . ":\n";
        $gep = $this->allocSsa();
        $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr . ", i64 16\n";
        $ld = $this->allocSsa();
        $out .= '  ' . $ld . ' = load i64, ptr ' . $gep . "\n";
        $out .= '  store i64 ' . $ld . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $end . "\n";
        $out .= $end . ":\n";
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** True while emitting `$prop`'s own get/set hook — its `$this->$prop`
     *  accesses read/write the backing slot directly (no hook re-entry). */
    private function insideOwnHook(array $hk): bool
    {
        return ($hk['get'] !== '' && $this->currentFnName === $hk['get'])
            || ($hk['set'] !== '' && $this->currentFnName === $hk['set']);
    }

    /** Emit a property get-hook call: `<hookSym>($this)` → the hooked value,
     *  coerced from the i64 carrier to `$resultType`. */
    private function emitHookGet(Node $objNode, string $hookSym, Type $resultType): string
    {
        $out = $this->emitNode($objNode);
        $out .= $this->coerceToI64();
        $thisArg = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($hookSym)
              . '(i64 ' . $thisArg . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($resultType->kind === Type::KIND_FLOAT) {
            $rf = $this->allocSsa();
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
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($hookSym)
              . '(i64 ' . $thisArg . ', i64 ' . $val . ")\n";
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
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
            $m = $this->allocSsa();
            $out .= '  ' . $m . ' = and i64 ' . $ord . ", 281474976710655\n";
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $m . " to ptr\n";
            $g0 = $this->allocSsa();
            $out .= '  ' . $g0 . ' = getelementptr i8, ptr ' . $p . ", i64 16\n";
            $ordR = $this->allocSsa();
            $out .= '  ' . $ordR . ' = load i64, ptr ' . $g0 . "\n";
            $ord = $ordR;
        }
        if ($pa->property === 'value' && $this->edBacking($ed) === 'int') {
            $gep = $this->allocSsa();
            $out .= '  ' . $gep . ' = getelementptr inbounds [' . (string)$n . ' x i64], ptr @'
                  . $ecls . '__values, i64 0, i64 ' . $ord . "\n";
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = load i64, ptr ' . $gep . "\n";
            $this->lastValue = $r; $this->lastValueType = 'i64';
            return $out;
        }
        // 'name', or string-backed 'value' → a ptr array.
        $table = ($pa->property === 'value') ? '__values' : '__names';
        $gep = $this->allocSsa();
        $out .= '  ' . $gep . ' = getelementptr inbounds [' . (string)$n . ' x ptr], ptr @'
              . $ecls . $table . ', i64 0, i64 ' . $ord . "\n";
        $r = $this->allocSsa();
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
        $oi = $this->allocSsa();
        $out = '  ' . $oi . ' = ptrtoint ptr ' . $objPtrReg . " to i64\n";
        $kid = $this->internString($propName);
        $si = $this->allocSsa();
        $out .= '  ' . $si . ' = ptrtoint ptr ' . $this->strLitId($kid) . " to i64\n";
        $args = 'i64 ' . $oi . ', i64 ' . $si;
        if ($valArg !== null) { $args .= ', i64 ' . $valArg; }
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call i64 @manticore_' . $this->mangle($methodCls)
              . '__' . $method . '(' . $args . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
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
            if ($roDecl !== '' && !\str_starts_with($this->currentFnName, $roDecl . '__')) {
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
            $bg = $this->allocSsa();
            $out .= '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$bcd->bagOffset() . "\n";
            $bagI = $this->allocSsa();
            $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
            $bagP = $this->allocSsa();
            $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
            $out .= $this->emitNode($n->value);
            $out .= $this->boxToCell($n->value->type);
            $val = $this->lastValue;
            $kid = $this->internString($n->property);
            $nb = $this->allocSsa();
                        $out .= '  ' . $nb . ' = call ptr @__mir_array_set_str(ptr ' . $bagP
                  . ', ptr ' . $this->strLitId($kid) . ', i64 ' . $val . ", i64 0, i64 0)\n";
            $nbI = $this->allocSsa();
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
                $out .= $this->boxToCell($n->value->type);
                $val = $this->lastValue;
                $out .= $this->emitMagicCall($setCls, '__set', $objPtr, $n->property, $val);
                $this->lastValue = $val;
                $this->lastValueType = 'i64';
                return $out;
            }
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
        if ($this->cellPropBoxed($propType, $n->property)) {
            $vk = $n->value->type->kind;
            if ($vk === Type::KIND_CELL) {
                // Already a boxed cell — store as-is.
                $out .= $this->coerceToI64();
                $val = $this->lastValue;
            } elseif ($vk === Type::KIND_STRING || $vk === Type::KIND_OBJ) {
                // rc-managed payload (string/object) — retain the RAW ptr before
                // boxing (a tagged cell would mis-locate the rc header).
                $out .= $this->coerceToI64();
                $raw = $this->lastValue;
                $out .= $this->rcRetainByType($n->value, $raw, $propType, 4);
                $this->lastValue = $raw;
                $this->lastValueType = 'i64';
                $out .= $this->boxToCell($n->value->type);
                $val = $this->lastValue;
            } else {
                // Non-rc scalar (int/float/bool/null) — box, no retain.
                $out .= $this->boxToCell($n->value->type);
                $val = $this->lastValue;
            }
        } else {
            $out .= $this->coerceToI64();
            $val = $this->lastValue;
            $out .= $this->rcRetainByType($n->value, $val, $propType, 4);
        }
        $offset = $this->propertyOffset($n->object, $n->property);
        $gep = $this->allocSsa();
        $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr '
              . $objPtr . ', i64 ' . (string)$offset . "\n";
        $out .= '  store i64 ' . $val . ', ptr ' . $gep . "\n";
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
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
            $id = $this->internString($def->value);
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
        $g = $this->allocSsa();
        $cond = $this->allocSsa();
        $doLbl = $this->allocLabel('slinit');
        $skipLbl = $this->allocLabel('slskip');
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
        if (isset($this->slots[$n->source])) {
            $this->slots[$n->target] = $this->slots[$n->source];
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return '';
    }

    /** `$r = &fn(...)` — store the by-ref return address into $r's slot. */
    private function emitRefBind(RefBind_ $n): string
    {
        if (!isset($this->slots[$n->target])) {
            $slot = $this->allocSsa();
            $this->slots[$n->target] = $slot;
            $out = '  ' . $slot . " = alloca i64\n";
        } else {
            $out = '';
        }
        $this->rawRefCall = true;
        $out .= $this->emitNode($n->call);
        $this->rawRefCall = false;
        $out .= $this->coerceToI64();
        $addr = $this->lastValue;
        $out .= '  store i64 ' . $addr . ', ptr ' . $this->slots[$n->target] . "\n";
        $this->refLocals[$n->target] = true;
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
        if (!isset($this->slots[$n->target])) {
            $slot = $this->allocSsa();
            $this->slots[$n->target] = $slot;
            $out = '  ' . $slot . " = alloca i64\n";
        } else {
            $out = '';
        }
        $addrIr = $this->byRefAddrOf($n->lvalue);
        if ($addrIr === null) {
            // Not addressable — degrade to a value copy (non-crashing).
            $out .= $this->emitNode($n->lvalue);
            $out .= $this->coerceToI64();
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->slots[$n->target] . "\n";
            $this->lastValue = '0';
            $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $addrIr;
        $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $this->slots[$n->target] . "\n";
        $this->refLocals[$n->target] = true;
        $this->lastValue = '0';
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

    /** Emit the object → bag-assoc ptr; leaves bag ptr + slot gep regs. */
    private function emitBagPtr(Node $objNode, string $objPtr, int $bagOff): string
    {
        $bg = $this->allocSsa();
        $out = '  ' . $bg . ' = getelementptr inbounds i8, ptr ' . $objPtr
             . ', i64 ' . (string)$bagOff . "\n";
        $bagI = $this->allocSsa();
        $out .= '  ' . $bagI . ' = load i64, ptr ' . $bg . "\n";
        $bagP = $this->allocSsa();
        $out .= '  ' . $bagP . ' = inttoptr i64 ' . $bagI . " to ptr\n";
        $this->bagSlotReg = $bg;
        $this->bagPtrReg = $bagP;
        return $out;
    }

    /** `$o->$name` — read the boxed value from the bag by runtime key. */
    private function emitDynProp(DynProp_ $n): string
    {
        $out = $this->emitNode($n->object);
        if ($n->object->type->kind === Type::KIND_CELL) { $out .= $this->cellToPtr(); }
        else { $out .= $this->coerceToPtr(); }
        $objPtr = $this->lastValue;
        $out .= $this->emitBagPtr($n->object, $objPtr, $this->bagOffsetOf($n->object));
        $bagP = $this->bagPtrReg;
        $out .= $this->emitNode($n->name);
        $out .= $this->coerceToPtr();
        $keyP = $this->lastValue;
        $reg = $this->allocSsa();
                $out .= '  ' . $reg . ' = call i64 @__mir_array_get_str(ptr ' . $bagP
              . ', ptr ' . $keyP . ", i64 0, i64 0)\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** `$o->$name = v` — set the boxed value in the bag by runtime key. */
    private function emitStoreDynProp(StoreDynProp_ $n): string
    {
        $out = $this->emitNode($n->object);
        if ($n->object->type->kind === Type::KIND_CELL) { $out .= $this->cellToPtr(); }
        else { $out .= $this->coerceToPtr(); }
        $objPtr = $this->lastValue;
        $out .= $this->emitBagPtr($n->object, $objPtr, $this->bagOffsetOf($n->object));
        $bagP = $this->bagPtrReg;
        $bg = $this->bagSlotReg;
        $out .= $this->emitNode($n->name);
        $out .= $this->coerceToPtr();
        $keyP = $this->lastValue;
        $out .= $this->emitNode($n->value);
        $out .= $this->boxToCell($n->value->type);
        $val = $this->lastValue;
        $nb = $this->allocSsa();
                $out .= '  ' . $nb . ' = call ptr @__mir_array_set_str(ptr ' . $bagP
              . ', ptr ' . $keyP . ', i64 ' . $val . ", i64 0, i64 0)\n";
        $nbI = $this->allocSsa();
        $out .= '  ' . $nbI . ' = ptrtoint ptr ' . $nb . " to i64\n";
        $out .= '  store i64 ' . $nbI . ', ptr ' . $bg . "\n";
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function castDynProp(Node $n): DynProp_ { return $n; }

    private function emitClassName(ClassName_ $n): string
    {
        $cls = $n->operand->type->class ?? '';
        $id = $this->internString($cls);
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
            $a = $this->allocSsa();
            $out .= '  ' . $a . ' = and i64 ' . $acc . ', ' . $cur . "\n";
            $acc = $a;
        }
        $this->lastValue = $acc;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Leave an i64 `0|1` in lastValue for whether `$t` is set. */
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
                $cmp = $this->allocSsa();
                $out .= '  ' . $cmp . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                $z = $this->allocSsa();
                $out .= '  ' . $z . ' = zext i1 ' . $cmp . " to i64\n";
                $this->lastValue = $z;
                $this->lastValueType = 'i64';
                return $out;
            }
            if ($aa->array->type->kind !== Type::KIND_STRING) {
                $out = $this->emitNode($aa->array);
                // A `mixed`/cell base (e.g. a json_decode value) carries the
                // array pointer NaN-boxed — strip the tag, don't inttoptr the
                // boxed bits (which faults in __mir_array_isset_*).
                if ($aa->array->type->kind === Type::KIND_CELL) {
                    $out .= $this->cellToPtr();
                } else {
                    $out .= $this->coerceToPtr();
                }
                $arr = $this->lastValue;
                $keyIsCell = $aa->index->type->kind === Type::KIND_CELL;
                $keyIsString = $aa->index->type->kind === Type::KIND_STRING
                    || $aa->index->kind === Node::KIND_STRING_CONST;
                $out .= $this->emitNode($aa->index);
                $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
                $key = $this->lastValue;
                $r = $this->allocSsa();
                if ($keyIsCell) {
                    $this->needsCellKey = true;
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
                $val = $this->allocSsa();
                if ($keyIsCell) {
                    $out .= '  ' . $val . ' = call i64 @__mir_array_get_cell(ptr ' . $arr . ', i64 ' . $key . ")\n";
                } elseif ($keyIsString) {
                    $out .= '  ' . $val . ' = call i64 @__mir_array_get_str(ptr ' . $arr . ', ptr ' . $key . ", i64 0, i64 0)\n";
                } else {
                    $out .= '  ' . $val . ' = call i64 @__mir_array_get_int(ptr ' . $arr . ', i64 ' . $key . ")\n";
                }
                $nn = $this->allocSsa();
                $out .= '  ' . $nn . ' = icmp ne i64 ' . $val . ", -3659174697238528\n"; // != box_null
                $nnz = $this->allocSsa();
                $out .= '  ' . $nnz . ' = zext i1 ' . $nn . " to i64\n";
                $rr = $this->allocSsa();
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
            $ok = $this->allocSsa();
            $out .= '  ' . $ok . ' = call i1 @__mir_str_offset_isset(ptr ' . $arr
                  . ', i64 ' . $idx . ")\n";
            $z = $this->allocSsa();
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
                    $cmp = $this->allocSsa();
                    $out .= '  ' . $cmp . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
                    $z = $this->allocSsa();
                    $out .= '  ' . $z . ' = zext i1 ' . $cmp . " to i64\n";
                    $this->lastValue = $z; $this->lastValueType = 'i64';
                    return $out;
                }
            }
        }
        // Property / dynamic-property isset: reading the field derefs the
        // receiver, so `isset($n->x)` with a null `$n` faults. Guard the
        // receiver — a null one makes isset false without the deref. Only
        // when the receiver is a pure local (safe to re-emit in the live
        // branch). Result threads through a slot to avoid a phi predecessor
        // mismatch if the field read appends its own blocks.
        if (($t->kind === Node::KIND_PROPERTY_ACCESS || $t->kind === Node::KIND_DYN_PROP)) {
            $objNode = $t->kind === Node::KIND_PROPERTY_ACCESS
                ? $t->object
                : $this->castDynProp($t)->object;
            if ($objNode->kind === Node::KIND_LOAD_LOCAL) {
                $rSlot = $this->allocSsa();
                $out = '  ' . $rSlot . " = alloca i64\n";
                $out .= $this->emitNode($objNode);
                $out .= $this->coerceToPtr();
                $objPtr = $this->lastValue;
                $isnull = $this->allocSsa();
                $out .= '  ' . $isnull . ' = icmp eq ptr ' . $objPtr . ", null\n";
                $lNull = $this->allocLabel('iss.null');
                $lRead = $this->allocLabel('iss.read');
                $lEnd = $this->allocLabel('iss.end');
                $out .= '  br i1 ' . $isnull . ', label %' . $lNull
                      . ', label %' . $lRead . "\n";
                $out .= $lRead . ":\n";
                $out .= $this->emitNode($t);
                $out .= $this->coerceToI64();
                $rv = $this->lastValue;
                // Set iff non-null: a null POINTER is 0 (`?string`/`?obj`), a
                // null SCALAR is the boxed-NULL sentinel (`?int`/`?float`/`?bool`
                // ride a numeric cell). PHP isset() is false for either.
                $nz = $this->allocSsa();
                $out .= '  ' . $nz . ' = icmp ne i64 ' . $rv . ", 0\n";
                $nnul = $this->allocSsa();
                $out .= '  ' . $nnul . ' = icmp ne i64 ' . $rv . ", -3659174697238528\n";
                $setc = $this->allocSsa();
                $out .= '  ' . $setc . ' = and i1 ' . $nz . ', ' . $nnul . "\n";
                $setz = $this->allocSsa();
                $out .= '  ' . $setz . ' = zext i1 ' . $setc . " to i64\n";
                $out .= '  store i64 ' . $setz . ', ptr ' . $rSlot . "\n";
                $out .= '  br label %' . $lEnd . "\n";
                $out .= $lNull . ":\n";
                $out .= '  store i64 0, ptr ' . $rSlot . "\n";
                $out .= '  br label %' . $lEnd . "\n";
                $out .= $lEnd . ":\n";
                $z = $this->allocSsa();
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
        $cmp = $this->allocSsa();
        $out .= '  ' . $cmp . ' = icmp ne i64 ' . $v . ", 0\n";
        $z = $this->allocSsa();
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
                $flavor = $this->discardReleaseFlavor($t->type);
                if (isset($this->globalBackedLocals[$name])) {
                    if ($flavor !== '') { $out .= $this->rcReleaseSlot($this->globalBackedLocals[$name], $flavor); }
                    $out .= '  store i64 0, ptr ' . $this->globalBackedLocals[$name] . "\n";
                } elseif (isset($this->slots[$name])) {
                    if ($flavor !== '') { $out .= $this->rcReleaseSlot($this->slots[$name], $flavor); }
                    $out .= '  store i64 0, ptr ' . $this->slots[$name] . "\n";
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
                    $out .= $baseCell ? $this->cellToPtr() : $this->coerceToPtr();
                    $arrPtr = $this->lastValue;
                    $keyIsCell = $aa->index->type->kind === Type::KIND_CELL;
                    $keyIsString = $aa->index->type->kind === Type::KIND_STRING
                        || $aa->index->kind === Node::KIND_STRING_CONST;
                    $out .= $this->emitNode($aa->index);
                    $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
                    $key = $this->lastValue;
                    if ($keyIsCell) {
                        $this->needsCellKey = true;
                        $out .= '  call void @__mir_array_unset_cell(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
                    } elseif ($keyIsString) {
                        $out .= '  call void @__mir_array_unset_str(ptr ' . $arrPtr . ', ptr ' . $key . ")\n";
                    } else {
                        $out .= '  call void @__mir_array_unset_int(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
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
            }
            // vec element unset still deferred (needs hole / shift semantics).
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitStaticProp(\Compile\Mir\StaticProp_ $n): string
    {
        $reg = $this->allocSsa();
        $out = '  ' . $reg . ' = load i64, ptr ' . $n->global . "\n";
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
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
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        // A static prop is a program-lifetime owner of an obj value.
        $out .= $this->rcRetainByType($n->value, $val, null, 5);
        $out .= '  store i64 ' . $val . ', ptr ' . $n->global . "\n";
        $this->lastValue = $val;
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
            if (isset($this->fnParamTypes[$spec])) { return $spec; }
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
        $cur = $this->allocSsa();
        $out = '  ' . $cur . ' = call ptr @__mir_array_alloc(i64 ' . (string)$n . ")\n";
        $i = 0;
        while ($i < $n) {
            $nx = $this->allocSsa();
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
        $res = $this->allocSsa();
        $out .= '  ' . $res . " = alloca i64\n";
        $done = $this->allocLabel('efrom.done');
        $vt = $isStr ? 'ptr' : 'i64';
        for ($i = 0; $i < $n; $i = $i + 1) {
            $hit = $this->allocLabel('efrom.hit');
            $nextL = $this->allocLabel('efrom.next');
            $g = $this->allocSsa();
            $out .= '  ' . $g . ' = getelementptr [' . (string)$n . ' x ' . $vt . '], ptr @'
                  . $enum . '__values, i64 0, i64 ' . (string)$i . "\n";
            $v = $this->allocSsa();
            $out .= '  ' . $v . ' = load ' . $vt . ', ptr ' . $g . "\n";
            $eq = $this->allocSsa();
            if ($isStr) {
                $out .= '  ' . $eq . ' = call i1 @__mir_str_eq(ptr ' . $needle . ', ptr ' . $v . ")\n";
            } else {
                $out .= '  ' . $eq . ' = icmp eq i64 ' . $needle . ', ' . $v . "\n";
            }
            $out .= '  br i1 ' . $eq . ', label %' . $hit . ', label %' . $nextL . "\n";
            $out .= $hit . ":\n";
            if ($try) {
                $cg = $this->allocSsa();
                $out .= '  ' . $cg . ' = getelementptr [' . (string)$n . ' x i64], ptr @'
                      . $enum . '__cases, i64 0, i64 ' . (string)$i . "\n";
                $dp = $this->allocSsa();
                $out .= '  ' . $dp . ' = load i64, ptr ' . $cg . "\n";
                $pp = $this->allocSsa();
                $out .= '  ' . $pp . ' = inttoptr i64 ' . $dp . " to ptr\n";
                $bx = $this->allocSsa();
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
        $r = $this->allocSsa();
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
        $buf = $this->allocSsa();
        $out .= '  ' . $buf . ' = call ptr @__mir_alloc(i64 ' . (string)(8 * $slots) . ")\n";
        for ($i = 0; $i < $slots; $i = $i + 1) {
            $sg = $this->allocSsa();
            $out .= '  ' . $sg . ' = getelementptr inbounds i64, ptr ' . $src . ', i64 ' . (string)$i . "\n";
            $sv = $this->allocSsa();
            $out .= '  ' . $sv . ' = load i64, ptr ' . $sg . "\n";
            $dg = $this->allocSsa();
            $out .= '  ' . $dg . ' = getelementptr inbounds i64, ptr ' . $buf . ', i64 ' . (string)$i . "\n";
            $out .= '  store i64 ' . $sv . ', ptr ' . $dg . "\n";
        }
        $hasThis = $this->closureHasThis[$fnName] ?? true;
        if ($hasThis && $cnt >= 1) {
            $out .= $this->emitNode($objNode);
            $out .= $this->coerceToI64();
            $objV = $this->lastValue;
            $tg = $this->allocSsa();
            $out .= '  ' . $tg . ' = getelementptr inbounds i64, ptr ' . $buf . ", i64 1\n";
            $out .= '  store i64 ' . $objV . ', ptr ' . $tg . "\n";
        }
        $this->lastValue = $buf;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitStaticCall(\Compile\Mir\StaticCall_ $n): string
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
        $cls = $this->resolveMethodClass($n->class, $n->method);
        if ($cls === '') { $cls = $n->class; }
        // Late static binding: route to the per-descendant specialisation
        // matching the called class (`$n->staticClass`) when one exists.
        $lsbScope = $n->staticClass !== '' ? $n->staticClass : $n->class;
        $target = $this->lsbTarget($cls, $n->method, $lsbScope);
        // By-ref mask of the resolved callee. Static-call args already align
        // with params (a selfish instance call prepends `$this` at lowering),
        // so arg index `ai` maps to param `ai` — forward the slot address for
        // a by-ref param instead of the dereferenced value.
        $mask = $this->fnRefParams[$cls . '__' . $n->method] ?? [];
        $ptypes = $this->fnParamTypes[$cls . '__' . $n->method] ?? [];
        $tmask = $this->fnTaggedParams[$cls . '__' . $n->method] ?? [];
        $ai = 0;
        foreach ($n->args as $a) {
            if (!$first) { $argList .= ', '; }
            $first = false;
            if ($this->argIsByRef($mask, $ai, $a)) {
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
                $out .= $this->unboxCellArg($a, $ptypes, $ai);
                $argList .= 'i64 ' . $this->lastValue;
                if ($this->isFreshStringTemp($a)) { $argTemps[] = $this->lastValue; }
            }
            $ai = $ai + 1;
        }
        // Catch-all default pad (mirrors emitMethodCall); a static call is
        // usually lower-filled, but an unresolved-at-lowering callee may
        // arrive short — never leave a trailing optional unset.
        $out .= $this->emitDefaultArgPad($cls . '__' . $n->method, $ai, !$first);
        $argList .= $this->lastPadArgs;
        $btName = '';
        if ($this->needsBacktrace) {
            $btName = $n->class . '::' . $n->method;
            $out .= $this->btPush($btName, $n->line);
        }
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($target)
              . '(' . $argList . ")\n";
        if ($btName !== '') { $out .= $this->btPop(); }
        $out .= $this->freeStrArgTemps($argTemps);
        // By-ref return (`static function &m()`): the callee yields the slot
        // ADDRESS; deref in value context, keep raw under rawRefCall (RefBind).
        if (($this->fnReturnsByRef[$target] ?? false) && !$this->rawRefCall) {
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
            $dv = $this->allocSsa();
            $out .= '  ' . $dv . ' = load i64, ptr ' . $p . "\n";
            $reg = $dv;
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
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
     * Emit a class_id switch for a polymorphic method call. Returns the
     * IR and leaves the i64 result reg in `$this->vdResult` (avoids a
     * `&$out` accumulator — self-host drops by-ref writes). Args are
     * already evaluated into `$argList`; they dominate every case.
     *
     * @param string[]              $cands
     * @param array<string, string> $targets candidate class → declaring class
     */
    private function emitVirtualDispatch(string $thisArg, string $argList, array $cands, array $targets, string $fallback, string $method): string
    {
        $objp = $this->allocSsa();
        $out = '  ' . $objp . ' = inttoptr i64 ' . $thisArg . " to ptr\n";
        $out .= $this->emitLoadClassId($objp);
        $cid = $this->classIdReg;
        $res = $this->allocSsa();
        $out .= '  ' . $res . " = alloca i64\n";
        $endLabel = $this->allocLabel('vd.end');
        $defLabel = $this->allocLabel('vd.default');
        $switch = '  switch i64 ' . $cid . ', label %' . $defLabel . " [\n";
        $bodies = '';
        foreach ($cands as $c) {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { continue; }
            $caseLabel = $this->allocLabel('vd.case');
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $caseLabel . "\n";
            $r = $this->allocSsa();
            $bodies .= $caseLabel . ":\n";
            $bodies .= '  ' . $r . ' = call i64 @manticore_' . $this->mangle($targets[$c])
                     . '(' . $argList . ")\n";
            $bodies .= '  store i64 ' . $r . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $endLabel . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $rd = $this->allocSsa();
        $out .= $defLabel . ":\n";
        $out .= '  ' . $rd . ' = call i64 @manticore_' . $this->mangle($fallback)
              . '(' . $argList . ")\n";
        $out .= '  store i64 ' . $rd . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endLabel . "\n";
        $out .= $endLabel . ":\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->vdResult = $loaded;
        return $out;
    }

    /**
     * Walk a class's parent chain to find the one that actually
     * declares `$method`. Returns '' when no ancestor declares it.
     */
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
        if ($m === 'current') { $out .= $this->genPrimeIfFresh($g); $out .= $this->genFieldLoad($g, 16); return $this->finishI64($out, $this->lastValue); }
        if ($m === 'key')     { $out .= $this->genPrimeIfFresh($g); $out .= $this->genFieldLoad($g, 24); return $this->finishI64($out, $this->lastValue); }
        if ($m === 'getReturn') { $out .= $this->genFieldLoad($g, 48); return $this->finishI64($out, $this->lastValue); }
        if ($m === 'rewind') { $out .= $this->genPrimeIfFresh($g); return $this->finishI64($out, '0'); }
        if ($m === 'next')   { $out .= $this->genResumeCall($g); return $this->finishI64($out, '0'); }
        if ($m === 'send') {
            $sentPtr = $this->allocSsa();
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
            return $this->finishI64($out, $this->lastValue);
        }
        if ($m === 'valid') {
            $out .= $this->genPrimeIfFresh($g);
            $statePtr = $this->allocSsa();
            $out .= '  ' . $statePtr . ' = getelementptr inbounds i8, ptr ' . $g . ", i64 8\n";
            $st2 = $this->allocSsa();
            $out .= '  ' . $st2 . ' = load i64, ptr ' . $statePtr . "\n";
            $ne = $this->allocSsa();
            $out .= '  ' . $ne . ' = icmp ne i64 ' . $st2 . ", -1\n";
            $z = $this->allocSsa();
            $out .= '  ' . $z . ' = zext i1 ' . $ne . " to i64\n";
            return $this->finishI64($out, $z);
        }
        throw new \RuntimeException('EmitLlvm: unsupported Generator method ' . $m);
    }

    /** Resume a generator once iff it is not yet started (state == 0). */
    private function genPrimeIfFresh(string $g): string
    {
        $statePtr = $this->allocSsa();
        $out = '  ' . $statePtr . ' = getelementptr inbounds i8, ptr ' . $g . ", i64 8\n";
        $st = $this->allocSsa();
        $out .= '  ' . $st . ' = load i64, ptr ' . $statePtr . "\n";
        $fresh = $this->allocSsa();
        $out .= '  ' . $fresh . ' = icmp eq i64 ' . $st . ", 0\n";
        $doL = $this->allocLabel('gm.prime');
        $skL = $this->allocLabel('gm.primed');
        $out .= '  br i1 ' . $fresh . ', label %' . $doL . ', label %' . $skL . "\n";
        $out .= $doL . ":\n" . $this->genResumeCall($g) . '  br label %' . $skL . "\n";
        $out .= $skL . ":\n";
        return $out;
    }

    /** Emit `load (frame + off)` into a fresh reg; sets $this->lastValue. */
    private function genFieldLoad(string $g, int $off): string
    {
        $p = $this->allocSsa();
        $out = '  ' . $p . ' = getelementptr inbounds i8, ptr ' . $g . ', i64 ' . (string)$off . "\n";
        $v = $this->allocSsa();
        $out .= '  ' . $v . ' = load i64, ptr ' . $p . "\n";
        $this->lastValue = $v;
        $this->lastValueType = 'i64';
        return $out;
    }

    private function emitMethodCall(Node $n): string
    {
        $mc = $this->castMethodCall($n);
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
            for ($ai = 1; $ai < $k; $ai = $ai + 1) {
                $a = $mc->args[$ai];
                $out .= $this->emitNode($a);
                if ($this->isCellBoxableArg($a->type)) { $out .= $this->boxToCell($a->type); }
                else { $out .= $this->coerceToI64(); }
                $argList .= ', i64 ' . $this->lastValue;
                $argTypes .= ', i64';
            }
            $fpi = $this->allocSsa();
            $out .= '  ' . $fpi . ' = load i64, ptr ' . $bound . "\n";
            $fp = $this->allocSsa();
            $out .= '  ' . $fp . ' = inttoptr i64 ' . $fpi . " to ptr\n";
            $reg = $this->allocSsa();
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
        $out = $this->emitNode($mc->object);
        $out .= $this->coerceToI64();
        $thisArg = $this->lastValue;
        // A `mixed`/cell receiver carries a NaN-boxed object — strip the tag to
        // the raw object pointer so both the `$this` arg and the class_id
        // virtual dispatch read the object, not the boxed bits (else SIGSEGV).
        if ($mc->object->type->kind === Type::KIND_CELL) {
            $unb = $this->allocSsa();
            $out .= '  ' . $unb . ' = and i64 ' . $thisArg . ", 281474976710655\n";
            $thisArg = $unb;
        }
        $argList = 'i64 ' . $thisArg;
        $argTemps = [];
        $static = $mc->object->type->class ?? '';
        $fallback = $this->resolveMethodClass($static, $mc->method);
        if ($fallback === '') { $fallback = $static; }
        // By-ref mask of the resolved callee. A method's param 0 is the
        // implicit `$this`, so call arg index `ai` maps to param `ai + 1` —
        // forward the slot address rather than the dereferenced value, or a
        // recursive `array &$param` call corrupts (the callee re-derefs it).
        $mask = $this->fnRefParams[$fallback . '__' . $mc->method] ?? [];
        $ptypes = $this->fnParamTypes[$fallback . '__' . $mc->method] ?? [];
        $tmask = $this->fnTaggedParams[$fallback . '__' . $mc->method] ?? [];
        $ai = 0;
        foreach ($mc->args as $a) {
            if ($this->argIsByRef($mask, $ai + 1, $a)) {
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
            } else {
                $out .= $this->emitNode($a);
                $out .= $this->coerceToI64();
                $out .= $this->unboxCellArg($a, $ptypes, $ai + 1);
                $argList .= ', i64 ' . $this->lastValue;
                if ($this->isFreshStringTemp($a)) { $argTemps[] = $this->lastValue; }
            }
            $ai = $ai + 1;
        }
        // Pad omitted trailing optionals: a typed-receiver call (`$x->m()`)
        // isn't default-filled at lowering (class unknown pre-InferTypes),
        // so the callee would read an uninitialized arg register. Param 0 is
        // `$this`, so provided params cover indices [0 .. $ai].
        $out .= $this->emitDefaultArgPad($fallback . '__' . $mc->method, $ai + 1, true);
        $argList .= $this->lastPadArgs;

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
        foreach ($cands as $c) {
            $t = $this->resolveMethodClass($c, $mc->method);
            if ($t === '') { $t = $fallback; }
            $full = $this->lsbTarget($t, $mc->method, $c);
            // An ABSTRACT method (declared, no emitted body) has no function —
            // an abstract class is never instantiated, so its switch case is
            // dead and would reference an undefined symbol. Drop the candidate.
            if (!isset($this->fnParamTypes[$full])) { continue; }
            $liveCands[] = $c;
            $targets[$c] = $full;
            if (!\in_array($full, $distinct, true)) { $distinct[] = $full; }
        }
        $fallbackFull = $this->lsbTarget($fallback, $mc->method, $static);
        // The static receiver's own method may be abstract (`$this->m()` inside
        // an abstract base) — fall back to a concrete implementation.
        if (!isset($this->fnParamTypes[$fallbackFull])) {
            $fallbackFull = $distinct[0] ?? $fallbackFull;
        }
        $btName = '';
        if ($this->needsBacktrace) {
            // Push a bare method-name placeholder + the call-site line. The
            // callee overwrites the name with "Class->method" at its entry
            // (a stable receiver class isn't available here under the self-host).
            $btName = $mc->method;
            $out .= $this->btPush($btName, $n->line);
        }
        if (\count($distinct) <= 1) {
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call i64 @manticore_' . $this->mangle($distinct[0] ?? $fallbackFull)
                  . '(' . $argList . ")\n";
        } else {
            $out .= $this->emitVirtualDispatch($thisArg, $argList, $liveCands, $targets, $fallbackFull, $mc->method);
            $reg = $this->vdResult;
        }
        if ($btName !== '') { $out .= $this->btPop(); }
        $out .= $this->freeStrArgTemps($argTemps);
        // By-ref return (`function &m()`): the callee yields the field/slot
        // ADDRESS as i64. In value context deref it; a `$r = &$obj->m()`
        // (rawRefCall) keeps the raw address so RefBind can alias through it.
        if (($this->fnReturnsByRef[$fallbackFull] ?? false) && !$this->rawRefCall) {
            $p = $this->allocSsa();
            $out .= '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
            $dv = $this->allocSsa();
            $out .= '  ' . $dv . ' = load i64, ptr ' . $p . "\n";
            $reg = $dv;
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($n->type->kind === Type::KIND_FLOAT) {
            $regF = $this->allocSsa();
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
            $tp = $this->allocSsa();
            $out .= '  ' . $tp . ' = inttoptr i64 ' . $tv . " to ptr\n";
            $out .= '  call void @__mir_rc_release_str(ptr ' . $tp . ")\n";
            $this->needsStrRc = true;
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
            $gep = $this->allocSsa();
            $out .= '  ' . $gep . ' = getelementptr inbounds i8, ptr ' . $objPtr
                  . ', i64 ' . (string)$firstOff . "\n";
            $loaded = $this->allocSsa();
            $out .= '  ' . $loaded . ' = load i64, ptr ' . $gep . "\n";
            $this->lastValue = $loaded;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->emitLoadClassId($objPtr);
        $cid = $this->classIdReg;
        $res = $this->allocSsa();
        $out .= '  ' . $res . " = alloca i64\n";
        $endL = $this->allocLabel('up.end');
        $defL = $this->allocLabel('up.def');
        $switch = '  switch i64 ' . $cid . ', label %' . $defL . " [\n";
        $bodies = '';
        $seen = [];
        foreach ($atoms as $atom) {
            $ac = $atom->class ?? '';
            foreach ($this->selfAndDescendants($ac) as $c) {
                $cd = $this->classes[$c] ?? null;
                if ($cd === null || isset($seen[$c])) { continue; }
                $seen[$c] = true;
                $caseL = $this->allocLabel('up.case');
                $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $caseL . "\n";
                $g = $this->allocSsa();
                $bodies .= $caseL . ":\n";
                $bodies .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objPtr
                         . ', i64 ' . (string)$offByClass[$ac] . "\n";
                $vv = $this->allocSsa();
                $bodies .= '  ' . $vv . ' = load i64, ptr ' . $g . "\n";
                $bodies .= '  store i64 ' . $vv . ', ptr ' . $res . "\n";
                $bodies .= '  br label %' . $endL . "\n";
            }
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $gd = $this->allocSsa();
        $out .= $defL . ":\n";
        $out .= '  ' . $gd . ' = getelementptr inbounds i8, ptr ' . $objPtr
              . ', i64 ' . (string)$firstOff . "\n";
        $vd = $this->allocSsa();
        $out .= '  ' . $vd . ' = load i64, ptr ' . $gd . "\n";
        $out .= '  store i64 ' . $vd . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load i64, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
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
    private function propertyOffset(Node $objExpr, string $prop): int
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
        return 16;
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
