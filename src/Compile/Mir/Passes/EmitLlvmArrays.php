<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\Block;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\BoolConst;
use Compile\Mir\MethodCall_;
use Compile\Mir\NewObj;
use Compile\Mir\Clone_;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StaticCall_;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RuntimeFeatures;
use Compile\Mir\StringPool;
use Compile\Mir\SsaBuilder;
use Compile\Mir\GeneratorContext;
use Compile\Mir\ControlFlow;
use Compile\Mir\FunctionEmitFrame;
use Compile\Mir\FunctionSignatures;
use Compile\Mir\ArenaContext;
use Compile\Mir\LocalSlots;
use Compile\Mir\RuntimeLibrary;
use Compile\Mir\EmitVisitor;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Yield_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\While_;
use Compile\Runtime\BareHost;
use Compile\Runtime\UnifiedArrayRuntime;
use Codegen\Llvm\Module as LlvmModule;

/**
 * Arrays: literals, element reads and writes, and the vec / assoc paths of the
 * unified PhpArray runtime.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmArrays
{
    /**
     * Number of array-nesting levels to deep-copy for a sound by-value copy of
     * an array of type `$t`, or -1 when a copy is unsound (a cell/obj/unknown
     * element, whose sharing/rc a flat memcpy can't model). 0 = a leaf element
     * (scalar / string — a flat `__mir_array_copy` fully separates it). N = the
     * element is itself an N-deep VEC of copyable arrays (`vec[vec[int]]` → 1),
     * so each level must be cloned. Restricted to VEC element arrays — an assoc
     * (hashed) element can't be index-walked by the deep copier. */
    private function arrayCopyDepth(Type $t): int
    {
        $e = $t->element;
        if ($e === null) { return -1; }
        $k = $e->kind;
        if ($k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL) {
            return 0;   // flat copy sound for a vec OR an assoc of scalars
        }
        // A nested VEC element needs each level cloned — but the deep copier
        // index-walks the OUTER, so the outer must be a vec (a hashed assoc can't
        // be index-walked). An assoc-of-arrays is left borrowed.
        if ($k === Type::KIND_ARRAY && $e->isVec() && $t->isVec()) {
            $inner = $this->arrayCopyDepth($e);
            return $inner < 0 ? -1 : $inner + 1;
        }
        return -1;
    }

    /**
     * `$a[$k]` is by-ref addressable when it names an INT-keyed element of an
     * array container (a local, a global, or an object property). The runtime
     * ref-slot helper is int-only; string / cell keys and string-char indexing
     * (`$s[0]`) fall back to a value copy.
     */
    private function arrayElemAddressable(ArrayAccess_ $aa): bool
    {
        if (!$this->arrayElemKeyKind($aa->index)) { return false; }
        if (!$this->containerAddressable($aa->array)) { return false; }
        // Base must be a genuine (raw-pointer) array container. A bare-array
        // property read can infer UNKNOWN, so consult the declared prop type.
        if ($aa->array->type->isArray()) { return true; }
        if ($aa->array->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $aa->array;
            $cls = $pa->object->type->class ?? '';
            if ($cls !== '' && isset($this->classes[$cls])) {
                $pt = $this->classes[$cls]->propertyTypes[$pa->property] ?? null;
                if ($pt !== null && $pt->isArray()) { return true; }
            }
        }
        return false;
    }

    /** The runtime ref-slot helpers key on int or string; `true` when `$index`
     *  is one of those (a `null` append, cell, or float key is not addressable).
     *  Returns 'int' or 'str' for {@see byRefAddrOf}, or null when unsupported. */
    private function arrayElemKeyKind(Node $index): ?string
    {
        $k = $index->type->kind;
        if ($k === Type::KIND_INT || $index->kind === Node::KIND_INT_CONST) {
            return 'int';
        }
        if ($k === Type::KIND_STRING || $index->kind === Node::KIND_STRING_CONST) {
            return 'str';
        }
        return null;
    }

    private function emitArrayLit(ArrayLit $n): string
    {
        $this->arena->vecAllocated = false;
        return $this->emitArrayLitUnified($n);
    }

    private function emitArrayAccess(ArrayAccess_ $n): string
    {
        $aa = $n;
        // `$s[$i]` on a string → fresh 1-char string. Negative index counts
        // from the end; out-of-range yields "" (both handled by the helper).
        if ($aa->array->type->kind === Type::KIND_STRING) {
            $out = $this->emitNode($aa->array);
            $out .= $this->coerceToPtr();
            $base = $this->lastValue;
            $out .= $this->emitNode($aa->index);
            $out .= $this->coerceToI64();
            $idx = $this->lastValue;
            $buf = $this->ssa->allocReg();
            $out .= '  ' . $buf . ' = call ptr @__mir_str_char_at(ptr '
                  . $base . ', i64 ' . $idx . ")\n";
            $this->lastValue = $buf;
            $this->lastValueType = 'ptr';
            return $out;
        }
        // `$obj[$k]` on an ArrayAccess object → `$obj->offsetGet($k)`.
        if ($aa->array->type->kind === Type::KIND_OBJ
            && $this->classImplements($aa->array->type->class ?? '', 'ArrayAccess')) {
            $mc = new \Compile\Mir\MethodCall_($aa->array, 'offsetGet', [$aa->index], $n->type);
            return $this->emitMethodCall($mc);
        }
        return $this->emitArrayAccessUnified($n, $aa);
    }

    private function emitStoreElement(StoreElement $n): string
    {
        $se = $n;
        // `$s[$i] = $c` on a string → set byte $i to $c's first byte and write
        // the new string back into the base. Growing past the end pads with
        // spaces; negative $i counts from the end (all in the helper). The `[]`
        // append form is a PHP error on strings, so it stays out of this path.
        if ($se->array->type->kind === Type::KIND_STRING
            && $se->index->kind !== Node::KIND_NULL_CONST
            && $se->value->type->kind === Type::KIND_STRING) {
            $out = $this->emitNode($se->array);
            $out .= $this->coerceToPtr();
            $base = $this->lastValue;
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToI64();
            $idx = $this->lastValue;
            $out .= $this->emitNode($se->value);
            $out .= $this->coerceToPtr();
            $chs = $this->lastValue;
            $nw = $this->ssa->allocReg();
            $out .= '  ' . $nw . ' = call ptr @__mir_str_set_char(ptr ' . $base
                  . ', i64 ' . $idx . ', ptr ' . $chs . ")\n";
            $out .= $this->vecWriteBack($se->array, $nw, false);
            $this->lastValue = $nw;
            $this->lastValueType = 'ptr';
            return $out;
        }
        // `$obj[$k] = $v` / `$obj[] = $v` on an ArrayAccess object →
        // `$obj->offsetSet($k, $v)`. The append form `$obj[]=` already lowered
        // its index to a NullConst, so `$se->index` is the right key as-is.
        if ($se->array->type->kind === Type::KIND_OBJ
            && $this->classImplements($se->array->type->class ?? '', 'ArrayAccess')) {
            $mc = new \Compile\Mir\MethodCall_($se->array, 'offsetSet', [$se->index, $se->value], Type::void());
            return $this->emitMethodCall($mc);
        }
        return $this->emitStoreElementUnified($se);
    }

    private function emitArrayLitUnified(ArrayLit $al): string
    {
        $cellVals = $al->type->element !== null
            && $al->type->element->kind === Type::KIND_CELL;
        $count = \count($al->elements);
        // A non-escaping scalar array (InferAllocKind → NoRefcount → ApplyMemory
        // Mode → Arena; gated by Debug::$arenaArrays) bump-allocates in the
        // arena and is bulk-freed at frame leave — no malloc/rc/free. Its grow /
        // promote / index ops self-route via the ARRAY_TAG_ARENA tag, and its
        // retain/release bail on that tag, so nothing else in codegen changes.
        $arena = $al->allocKind === \Compile\Mir\AllocationKind::ARENA;
        $allocFn = $arena ? '__mir_array_alloc_arena' : '__mir_array_alloc';
        if ($arena) { $this->rt->needsArena = true; $this->arena->vecAllocated = true; }
        $slot = $this->ssa->allocReg();
        $out  = '  ' . $slot . " = alloca ptr\n";
        $init = $this->ssa->allocReg();
        $out .= '  ' . $init . ' = call ptr @' . $allocFn . '(i64 ' . (string)$count . ")\n";
        $out .= '  store ptr ' . $init . ', ptr ' . $slot . "\n";
        foreach ($al->elements as $el) {
            if ($el->value->kind === Node::KIND_SPREAD) {
                $out .= $this->emitArraySpreadUnified($slot, $el->value);
                continue;
            }
            if ($el->key !== null) {
                $keyIsString = $el->key->type->kind === Type::KIND_STRING
                    || $el->key->kind === Node::KIND_STRING_CONST;
                $out .= $this->emitNode($el->key);
                $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
                $keyReg = $this->lastValue;
                $out .= $this->emitNode($el->value);
                if ($cellVals) { $out .= $this->retainCellPayload($el->value); }
                $out .= $cellVals ? $this->boxToCell($el->value->type) : $this->coerceToI64();
                $val = $this->lastValue;
                if (!$cellVals) { $out .= $this->rcRetainByType($el->value, $val, null, 2); }
                $cur = $this->ssa->allocReg();
                $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
                $next = $this->ssa->allocReg();
                if ($keyIsString) {
                    $out .= '  ' . $next . ' = call ptr @__mir_array_set_str(ptr ' . $cur . ', ptr ' . $keyReg . ', i64 ' . $val . $this->litKeyHashArgs($el->key) . ")\n";
                    // Release our +1 on a fresh key temp — set_str retained its own
                    // (see the StoreElement path); a literal / local key is untouched.
                    $out .= $this->concatTempRelease($el->key, $keyReg);
                } else {
                    $out .= '  ' . $next . ' = call ptr @__mir_array_set_int(ptr ' . $cur . ', i64 ' . $keyReg . ', i64 ' . $val . ")\n";
                }
                $out .= '  store ptr ' . $next . ', ptr ' . $slot . "\n";
            } else {
                $out .= $this->emitNode($el->value);
                if ($cellVals) { $out .= $this->retainCellPayload($el->value); }
                $out .= $cellVals ? $this->boxToCell($el->value->type) : $this->coerceToI64();
                $val = $this->lastValue;
                if (!$cellVals) { $out .= $this->rcRetainByType($el->value, $val, null, 2); }
                $cur = $this->ssa->allocReg();
                $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
                $next = $this->ssa->allocReg();
                $out .= '  ' . $next . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $val . ")\n";
                $out .= '  store ptr ' . $next . ', ptr ' . $slot . "\n";
            }
        }
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = load ptr, ptr ' . $slot . "\n";
        $this->lastValue = $res;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function emitArrayAccessUnified(Node $self, ArrayAccess_ $aa): string
    {
        $out = $this->emitNode($aa->array);
        // A `mixed`/cell base (e.g. a nested value out of json_decode) carries
        // the array pointer NaN-boxed — strip the tag to the payload ptr, not
        // a raw inttoptr of the boxed bits (which faults in __mir_array_get).
        if ($aa->array->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        $arrPtr = $this->lastValue;
        // A `mixed`/cell index (int-OR-string at runtime) → dispatch helper.
        $keyIsCell = $aa->index->type->kind === Type::KIND_CELL;
        $keyIsString = $aa->index->type->kind === Type::KIND_STRING
            || $aa->index->kind === Node::KIND_STRING_CONST;
        $out .= $this->emitNode($aa->index);
        $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
        $key = $this->lastValue;
        $reg = $this->ssa->allocReg();
        if ($keyIsCell) {
            $this->rt->needsCellKey = true;
            $out .= '  ' . $reg . ' = call i64 @__mir_array_get_cell(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
        } elseif ($keyIsString) {
            $out .= '  ' . $reg . ' = call i64 @__mir_array_get_str(ptr ' . $arrPtr . ', ptr ' . $key . $this->litKeyHashArgs($aa->index) . ")\n";
        } else {
            $out .= '  ' . $reg . ' = call i64 @__mir_array_get_int(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        if ($self->type->kind === Type::KIND_FLOAT) {
            $regF = $this->ssa->allocReg();
            $out .= '  ' . $regF . ' = bitcast i64 ' . $reg . " to double\n";
            $this->lastValue = $regF;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    /**
     * The `__mir_array_cow*` variant for a container of static type `$t` — the
     * exact counterpart of {@see EmitLlvm::discardReleaseFlavor}'s choice, so
     * a cow retains what the release will drop. An erased / cell base carries
     * NaN-boxed elements → the cell variant.
     */
    private function cowSymbolFor(Type $t): string
    {
        if ($t->kind === Type::KIND_CELL || $t->kind === Type::KIND_UNKNOWN) {
            return '@__mir_array_cow_cell';
        }
        $el = $t->element;
        if ($el === null) { return '@__mir_array_cow'; }
        $ek = $el->kind;
        if ($ek === Type::KIND_CELL || $ek === Type::KIND_UNKNOWN) { return '@__mir_array_cow_cell'; }
        if ($ek === Type::KIND_STRING) { return '@__mir_array_cow_str'; }
        if ($ek === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) {
            return '@__mir_array_cow_obj';
        }
        return '@__mir_array_cow';
    }

    /**
     * Element-type fallback for a StoreElement's retain / transfer decision.
     *
     * The destination's element type decides the DEPTH — but only for a value
     * that travels RAW. A cell-typed value is NaN-boxed (boxToCell co-owns its
     * payload), and retaining it as the element type would rc-bump the tagged
     * bits, not a pointer: `$_SERVER[$k] = $v` came back with a corrupted PATH.
     * An UNKNOWN value is raw, and that is the one the element type must cover
     * (`$out[] = $s` off a bare-`array` property, whose caller sees `Node[]`).
     */
    private function storeRetainFallback(StoreElement $se): ?Type
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

    private function emitStoreElementUnified(StoreElement $se): string
    {
        // A `mixed`/cell base (mixed property / param holding an array) carries
        // the array pointer NaN-boxed — strip the tag to the payload ptr, and
        // re-box on write-back so the slot stays a valid array cell (mirrors the
        // read path in emitArrayAccessUnified). Without this the store inttoptr's
        // the boxed bits → SIGSEGV in __mir_array_append/set.
        $baseCell = $se->array->type->kind === Type::KIND_CELL;
        $out = $this->emitNode($se->array);
        $out .= $baseCell ? $this->cellToPtr() : $this->coerceToPtr();
        $arrPtr = $this->lastValue;
        // COW shared buffers (PHP array value semantics) before mutating. The
        // clone co-owns every key / value it now shares with the source, so the
        // variant must match the element type exactly the way the release
        // variant does — else the two buffers drop the same refs twice.
        $cowFn = $this->cowSymbolFor($se->array->type);
        if ($se->array->kind === Node::KIND_LOAD_LOCAL
            || $se->array->kind === Node::KIND_PROPERTY_ACCESS) {
            $cow = $this->ssa->allocReg();
            $out .= '  ' . $cow . ' = call ptr ' . $cowFn . '(ptr ' . $arrPtr . ")\n";
            $out .= $this->vecWriteBack($se->array, $cow, $baseCell);
            $arrPtr = $cow;
        } elseif ($se->array->kind === Node::KIND_ARRAY_ACCESS) {
            $cow = $this->ssa->allocReg();
            $out .= '  ' . $cow . ' = call ptr ' . $cowFn . '(ptr ' . $arrPtr . ")\n";
            $arrPtr = $cow;
        }
        $isAppend = $se->index->kind === Node::KIND_NULL_CONST;
        $keyIsCell = !$isAppend && $se->index->type->kind === Type::KIND_CELL;
        $keyIsString = !$isAppend && !$keyIsCell
            && ($se->index->type->kind === Type::KIND_STRING
                || $se->index->kind === Node::KIND_STRING_CONST);
        // A cell-element container (a KIND_CELL base, OR an assoc/vec whose
        // ELEMENT type is a cell) stores every value NaN-boxed — like the
        // literal path's $cellVals. Without it a read-back / var_dump misreads
        // a raw i64 as a tagged cell (`$e["s"]="hi"; $e["a"]=[1,2]`).
        $elemCell = !$baseCell
            && ($et = $se->array->type->element) !== null
            && $et->kind === Type::KIND_CELL;
        $boxVal = $baseCell || $elemCell;
        $next = $this->ssa->allocReg();
        if ($isAppend) {
            $out .= $this->emitNode($se->value);
            if ($boxVal) {
                // A CELL slot keeps the payload BY POINTER — co-own it, exactly as
                // the cell array-literal path does. Without this the value is freed
                // by its source's release while the array still points at it:
                // `foreach (__mc_env() as $k => $v) { $out[$k] = $v; }` left every
                // $_SERVER value dangling the moment the temp subject was released.
                $out .= $this->retainCellPayload($se->value);
                $out .= $this->boxToCell($se->value->type);
                $val = $this->lastValue;
            }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, $this->storeRetainFallback($se), 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_append(ptr ' . $arrPtr . ', i64 ' . $val . ")\n";
        } elseif ($keyIsCell) {
            $this->rt->needsCellKey = true;
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToI64();
            $key = $this->lastValue;
            $out .= $this->emitNode($se->value);
            if ($boxVal) {
                // A CELL slot keeps the payload BY POINTER — co-own it, exactly as
                // the cell array-literal path does. Without this the value is freed
                // by its source's release while the array still points at it:
                // `foreach (__mc_env() as $k => $v) { $out[$k] = $v; }` left every
                // $_SERVER value dangling the moment the temp subject was released.
                $out .= $this->retainCellPayload($se->value);
                $out .= $this->boxToCell($se->value->type);
                $val = $this->lastValue;
            }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, $this->storeRetainFallback($se), 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_cell(ptr ' . $arrPtr . ', i64 ' . $key . ', i64 ' . $val . ")\n";
        } elseif ($keyIsString) {
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToPtr();
            $key = $this->lastValue;
            $out .= $this->emitNode($se->value);
            if ($boxVal) {
                // A CELL slot keeps the payload BY POINTER — co-own it, exactly as
                // the cell array-literal path does. Without this the value is freed
                // by its source's release while the array still points at it:
                // `foreach (__mc_env() as $k => $v) { $out[$k] = $v; }` left every
                // $_SERVER value dangling the moment the temp subject was released.
                $out .= $this->retainCellPayload($se->value);
                $out .= $this->boxToCell($se->value->type);
                $val = $this->lastValue;
            }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, $this->storeRetainFallback($se), 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_str(ptr ' . $arrPtr . ', ptr ' . $key . ', i64 ' . $val . $this->litKeyHashArgs($se->index) . ")\n";
            // set_str RETAINS the stored key (append) — release our own +1 on a
            // fresh key temp (`$m["k".$i]`), or it leaks (borrowed locals/literals
            // stay untouched, balanced by their own later release). Without this the
            // string keys of a reassigned/dropped map are never reclaimed.
            $out .= $this->concatTempRelease($se->index, $key);
        } else {
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToI64();
            $idx = $this->lastValue;
            $out .= $this->emitNode($se->value);
            if ($boxVal) {
                // A CELL slot keeps the payload BY POINTER — co-own it, exactly as
                // the cell array-literal path does. Without this the value is freed
                // by its source's release while the array still points at it:
                // `foreach (__mc_env() as $k => $v) { $out[$k] = $v; }` left every
                // $_SERVER value dangling the moment the temp subject was released.
                $out .= $this->retainCellPayload($se->value);
                $out .= $this->boxToCell($se->value->type);
                $val = $this->lastValue;
            }
            else { $out .= $this->coerceToI64(); $val = $this->lastValue; $out .= $this->rcRetainByType($se->value, $val, $this->storeRetainFallback($se), 3); }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_int(ptr ' . $arrPtr . ', i64 ' . $idx . ', i64 ' . $val . ")\n";
        }
        $out .= $this->vecWriteBack($se->array, $next, $baseCell);
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }
}
