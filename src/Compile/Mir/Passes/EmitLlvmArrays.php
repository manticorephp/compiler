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

    /**
     * Does this subscript key have to ride the CELL key channel — the one that
     * dispatches int-vs-string on the NaN tag at runtime?
     *
     * A cell key obviously does. So does an ERASED one: a key read out of an
     * erased container comes back NaN-boxed, and the int channel then hashed the
     * tag bits. symfony's `$commands[$name]`, where `$name` is an element of an
     * erased `$namespace['commands']`, missed every entry — `isset` answered
     * false and the lookup returned null, which the next `->getName()` faulted on.
     * The cell helpers treat an untagged word as an int key ({@see
     * EmitLlvmExpr::cellKeyRuntime} → `__mir_ckey_unbox_int`), so this is the
     * identity on a key that really was a raw int.
     */
    private function keyRidesCellChannel(Node $index): bool
    {
        $k = $index->type->kind;
        return $k === Type::KIND_CELL || $k === Type::KIND_UNKNOWN;
    }

    private function emitArrayLit(ArrayLit $n): string
    {
        $this->arena->vecAllocated = false;
        return $this->emitArrayLitUnified($n);
    }

    /**
     * Lower the just-emitted subject of a subscript / isset / unset to the array
     * POINTER it stands for.
     *
     * A CELL base obviously carries the pointer NaN-boxed. So does an UNKNOWN
     * one: an `IteratorAggregate` whose `getIterator()` is declared
     * `\Traversable` gives the foreach value var no type at all, and the word it
     * holds is the generator's shallow-boxed `current` — a TAGGED array. The raw
     * inttoptr then read the tag bits as an address, so `isset($row[$column])`
     * answered false for every cell and symfony's Table computed every column
     * width as 0 (` -- -- -- ` instead of ` -------- ----- ---------- `).
     * The 48-bit payload mask is the identity on an already-raw pointer, so this
     * is free for every other erased carrier.
     */
    private function arrayBaseToPtr(Type $baseType): string
    {
        $k = $baseType->kind;
        if ($k === Type::KIND_CELL || $k === Type::KIND_UNKNOWN) { return $this->cellToPtr(); }
        return $this->coerceToPtr();
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
            // A byte OFFSET, so a tagged index has to come out of its box: an
            // `int|false` strpos result carried into arithmetic reaches here as
            // a cell, and its NaN bits read as an i64 are a vast offset — the
            // helper's out-of-range arm then answers "" for every character.
            if ($aa->index->type->kind === Type::KIND_CELL) {
                $out .= $this->unboxCellInt($this->lastValue);
            }
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
        // `$erased[$k]` — a cell/unknown subject is an OBJECT, a STRING or an
        // ARRAY only at run time, so the static type cannot pick a path. One
        // dispatch on the NaN-box nibble decides all three ({@see
        // emitErasedIndexGet}).
        //
        // ⚠ This used to be TWO guards, and the first SHADOWED the second: the
        // ArrayAccess arm fired whenever ANY class in the module implements
        // ArrayAccess — true of every real application — which made the string
        // arm below it unreachable, and the string arm additionally demanded a
        // statically INT index, so it was skipped even where it did run.
        // symfony's generate_operator_regex.php is the witness on both counts:
        // `foreach ($ops as $op => $len)` over a vec[cell] types BOTH as cells,
        // and `$op[$len - 1]` handed a string pointer to __mir_array_get_int.
        // `$op[0]` worked, which is what made this look like a base-type bug.
        if ($aa->array->type->kind === Type::KIND_CELL
            || $aa->array->type->kind === Type::KIND_UNKNOWN) {
            return $this->emitErasedIndexGet($n, $aa);
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

    /**
     * Constant-expr data pointer of the immortal empty-array singleton — the
     * 56-byte header at `@__mir_empty_array + 8` (field 1, past the 8-byte tag),
     * mirroring `__mir_alloc_array_tagged`'s `base + 8`.
     */
    private function emptyArraySingletonPtr(): string
    {
        return 'getelementptr inbounds ({ i64, i64, i64, i64, i64, i64, i64, ptr }, '
             . 'ptr @__mir_empty_array, i64 0, i32 1)';
    }

    /**
     * The VALUE half of an array-LITERAL element, shared by the keyed and
     * unkeyed arms of {@see emitArrayLitUnified} and by both shapes of
     * {@see emitArrayLitDirect}.
     *
     * The literal's answer is NOT {@see emitStoreElemValue}'s: there is no
     * de-cellify (a literal has no pre-existing slot to agree with), and the
     * retain category is the literal's own. Same reason to keep it in one place
     * — a literal element is where a `&` first has to become a storable value.
     *
     * Leaves the value word in {@see elemValReg}.
     */
    private function emitArrayLitValue(Node $value, bool $cellVals): string
    {
        $out = $this->emitNode($value);
        if ($cellVals) { $out .= $this->retainCellPayload($value); }
        $out .= $cellVals ? $this->boxToCell($value->type, $value) : $this->coerceToI64();
        $val = $this->lastValue;
        if (!$cellVals) { $out .= $this->rcRetainByType($value, $val, null, 2); }
        $this->elemValReg = $val;
        return $out;
    }

    private function emitArrayLitUnified(ArrayLit $al): string
    {
        $cellVals = $this->litBoxesValues($al);
        $count = \count($al->elements);
        // A non-escaping scalar array (InferAllocKind → NoRefcount → ApplyMemory
        // Mode → Arena; gated by Debug::$arenaArrays) bump-allocates in the
        // arena and is bulk-freed at frame leave — no malloc/rc/free. Its grow /
        // promote / index ops self-route via the ARRAY_TAG_ARENA tag, and its
        // retain/release bail on that tag, so nothing else in codegen changes.
        $arena = $al->allocKind === \Compile\Mir\AllocationKind::ARENA;
        // An empty non-arena `[]` is the immortal singleton: no alloc, hand back
        // the one shared data ptr. First mutation COWs it (rc=1<<62 > 1 always
        // clones); release never frees it. Arena empties are grown in place under
        // their own tag, so they must NOT alias the shared heap singleton.
        if ($count === 0 && !$arena && \Compile\Debug::$emptyArraySingleton) {
            $this->lastValue = $this->emptyArraySingletonPtr();
            $this->lastValueType = 'ptr';
            return '';
        }
        $allocFn = $arena ? '__mir_array_alloc_arena' : '__mir_array_alloc';
        if ($arena) { $this->rt->needsArena = true; $this->arena->vecAllocated = true; }
        // A literal that carries a string key is hashed the moment its first
        // `set_str` runs — building it packed only to have that first insert
        // promote it costs a second allocation, a copy, and a free. Allocate it
        // hashed up front instead; the end state is identical. An arena literal
        // never gets here (arena needs an int key, {@see InferAllocKind::
        // isArenaEligibleType}), so the two paths cannot collide.
        if (!$arena && $this->litHasStringKey($al)) {
            $allocFn = '__mir_array_alloc_hashed';
        }
        // A literal whose shape is fully known — no spread, and either all
        // elements unkeyed or all keyed by DISTINCT string constants — needs
        // none of the generic insert machinery. set_str linear-scans the entries
        // to see whether the key is already there (it provably is not) and
        // set_int drops the bucket index, re-reads flags and re-checks capacity
        // on every element. Store the entries straight into the buffer the
        // allocation already sized, and write the length once at the end.
        $shape = $arena ? null : $this->litDirectShape($al);
        if ($shape !== null) {
            return $this->emitArrayLitDirect($al, $allocFn, $shape, $cellVals);
        }
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
                $out .= $this->emitArrayLitValue($el->value, $cellVals);
                $val = $this->elemValReg;
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
                $out .= $this->emitArrayLitValue($el->value, $cellVals);
                $val = $this->elemValReg;
                $cur = $this->ssa->allocReg();
                $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
                $next = $this->ssa->allocReg();
                $out .= '  ' . $next . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $val . ")\n";
                $out .= '  store ptr ' . $next . ', ptr ' . $slot . "\n";
            }
        }
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = load ptr, ptr ' . $slot . "\n";
        // Self-describe a cell-valued literal, and record the element SHAPE for
        // every rc-shaped one (see emitArrayLitDirect).
        if ($cellVals && $count > 0) { $out .= $this->emitReprStamp($res, \Compile\MemoryAbi::ARRAY_REPR_CELL); }
        $litHint = $this->elementHintCodeForType($al->type->element);
        if ($litHint !== null && $count > 0) { $out .= $this->emitElemHintStamp($res, $litHint); }
        $this->lastValue = $res;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * The literal shapes that can be stored straight into the buffer:
     * `'packed'` — every element unkeyed (a list); `'hashed'` — every element
     * keyed by a string CONSTANT, all distinct. Anything else (a spread, an int
     * or dynamic key, a mix, a duplicate key whose last-wins would need the
     * lookup) returns null and takes the generic path.
     */
    private function litDirectShape(ArrayLit $al): ?string
    {
        $n = \count($al->elements);
        if ($n === 0) { return null; }
        $keyed = 0;
        /** @var array<string,bool> $seen */
        $seen = [];
        foreach ($al->elements as $el) {
            if ($el->value->kind === Node::KIND_SPREAD) { return null; }
            if ($el->key === null) { continue; }
            if ($el->key->kind !== Node::KIND_STRING_CONST) { return null; }
            $k = $el->key->value;
            if (isset($seen[$k])) { return null; } // duplicate: last one wins
            $seen[$k] = true;
            $keyed = $keyed + 1;
        }
        if ($keyed === 0) { return 'packed'; }
        if ($keyed === $n) { return 'hashed'; }
        return null;
    }

    /**
     * Emit a known-shape literal as direct entry stores. The buffer comes back
     * from the allocation already big enough for every element (packed: cap =
     * count; hashed: {@see UnifiedArrayRuntime::emitAllocHashed} floors at 4),
     * so nothing here can grow or relocate it and the pointer needs no slot.
     * The length is written once, after the elements.
     */
    private function emitArrayLitDirect(ArrayLit $al, string $allocFn, string $shape, bool $cellVals): string
    {
        $count = \count($al->elements);
        $hdr = \Compile\MemoryAbi::ARRAY_HEADER_SIZE;
        $arr = $this->ssa->allocReg();
        $out  = '  ' . $arr . ' = call ptr @' . $allocFn . '(i64 ' . (string)$count . ")\n";
        foreach ($al->elements as $i => $el) {
            // NOT `$keyReg = null` then a string: a null-union local infers
            // unsoundly under the native self-build (the slot carries a raw
            // payload, and the concat below then prints a POINTER instead of the
            // symbol). Zend hides it completely. Keep the local a plain string.
            $keyReg = '';
            if ($shape === 'hashed') {
                $out .= $this->emitNode($el->key);
                $out .= $this->coerceToPtr();
                $keyReg = $this->lastValue;
            }
            $out .= $this->emitArrayLitValue($el->value, $cellVals);
            $val = $this->elemValReg;
            if ($shape === 'packed') {
                $off = $hdr + $i * \Compile\MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE;
                $p = $this->ssa->allocReg();
                $out .= '  ' . $p . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . (string)$off . "\n";
                $out .= '  store i64 ' . $val . ', ptr ' . $p . "\n";
                continue;
            }
            // The container owns its keys — the release path drops every hashed
            // string key, so each one is retained here exactly as set_str does.
            $this->rt->needsStrRc = true;
            $out .= '  call void @__mir_rc_retain_str(ptr ' . $keyReg . ")\n";
            $base = $hdr + $i * \Compile\MemoryAbi::ARRAY_ENTRY_SIZE;
            $kp = $this->ssa->allocReg();
            $out .= '  ' . $kp . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 '
                  . (string)($base + \Compile\MemoryAbi::ARRAY_ENTRY_KIND_OFFSET) . "\n";
            $out .= '  store i64 ' . (string)\Compile\MemoryAbi::ARRAY_KIND_STRING . ', ptr ' . $kp . "\n";
            $keyp = $this->ssa->allocReg();
            $out .= '  ' . $keyp . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 '
                  . (string)($base + \Compile\MemoryAbi::ARRAY_ENTRY_KEY_OFFSET) . "\n";
            $out .= '  store ptr ' . $keyReg . ', ptr ' . $keyp . "\n";
            $vp = $this->ssa->allocReg();
            $out .= '  ' . $vp . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 '
                  . (string)($base + \Compile\MemoryAbi::ARRAY_ENTRY_VALUE_OFFSET) . "\n";
            $out .= '  store i64 ' . $val . ', ptr ' . $vp . "\n";
        }
        $out .= '  store i64 ' . (string)$count . ', ptr ' . $arr . "\n"; // length
        if ($shape === 'packed') {
            // A packed array's next implicit int key tracks its length (set_int
            // keeps the two in step) — `$a[] =` on the result must continue from
            // count, not from 0.
            $ni = $this->ssa->allocReg();
            $out .= '  ' . $ni . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 '
                  . (string)\Compile\MemoryAbi::ARRAY_NEXT_INT_OFFSET . "\n";
            $out .= '  store i64 ' . (string)$count . ', ptr ' . $ni . "\n";
        }
        // A cell-valued literal self-describes its elements (repr CELL), so a
        // release/COW reaching it through the plain repr helper — e.g. this
        // record nested in an erased `vec[unknown]` — still drops its boxed
        // cells. (A typed-flavor release ignores the bits; no double drop.)
        // The HINT rides alongside for every rc-shaped element type: it says
        // what the elements are, so an erased READER can decode a raw one
        // (symfony's Table rows are a concrete `vec[string]` read as cells).
        if ($cellVals && $count > 0) { $out .= $this->emitReprStamp($arr, \Compile\MemoryAbi::ARRAY_REPR_CELL); }
        $litHint = $this->elementHintCodeForType($al->type->element);
        if ($litHint !== null && $count > 0) { $out .= $this->emitElemHintStamp($arr, $litHint); }
        $this->lastValue = $arr;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * True when a literal element carries a key that is statically a string —
     * the array is then certain to be hashed, so it can be allocated that way.
     * A spread contributes no key of its own (its own keys decide at runtime),
     * and an int / unknown key does not force hashing.
     */
    private function litHasStringKey(ArrayLit $al): bool
    {
        foreach ($al->elements as $el) {
            if ($el->key === null) { continue; }
            if ($el->key->kind === Node::KIND_STRING_CONST
                || $el->key->type->kind === Type::KIND_STRING) {
                return true;
            }
        }
        return false;
    }

    /**
     * Can an array of static type `$t` hold BOXED cells in slots a
     * concrete-element consumer will read raw? True for a declared
     * pointer-element array — the mismatch case — and false for a base that is
     * already cell/erased (its consumers decode anyway) so those pay no call.
     */
    private function elemMayBeCell(Type $t): bool
    {
        if (!$t->isArray()) { return false; }
        $el = $t->element;
        if ($el === null) { return false; }
        return $el->kind === Type::KIND_STRING || $el->kind === Type::KIND_OBJ;
    }

    /**
     * `$erased[$k]` where the subject's kind is only known at run time.
     *
     * The subject and the key are emitted EXACTLY ONCE and the branch follows,
     * so neither side's side effects run twice. Three arms, decided by the
     * NaN-box nibble: an OBJECT (8) calls offsetGet on its runtime class_id, a
     * STRING (4) indexes a byte, everything else keeps the array lookup this
     * path had before.
     *
     * ⚠ A RAW (unboxed) word stays on the array arm deliberately. There an
     * array and a string are both bare pointers, and only an ARRAY can be
     * identified POSITIVELY (its magic at ptr-8; a string carries its refcount
     * there, which no probe can tell from a small integer). "Not provably an
     * array" must therefore not be read as "string" — that would send real
     * arrays to __mir_str_char_at.
     */
    private function emitErasedIndexGet(Node $self, ArrayAccess_ $aa): string
    {
        $holders = $this->ifaceMethodHolders('ArrayAccess', 'offsetGet');
        $out = $this->emitNode($aa->array);
        $out .= $this->coerceToI64();
        $cv = $this->lastValue;

        $keyIsCell = $this->keyRidesCellChannel($aa->index);
        $keyIsString = $aa->index->type->kind === Type::KIND_STRING
            || $aa->index->kind === Node::KIND_STRING_CONST;
        $out .= $this->emitNode($aa->index);
        $out .= $keyIsString ? $this->coerceToPtr() : $this->coerceToI64();
        $key = $this->lastValue;
        // offsetGet takes `mixed $offset`, so the object arm needs the key BOXED.
        // Boxing is pure — doing it up front keeps both arms off a second emit.
        // Only worth emitting when there IS an object arm.
        $keyCell = $key;
        if (!$keyIsCell && $holders !== []) {
            $this->lastValue = $key;
            $this->lastValueType = $keyIsString ? 'ptr' : 'i64';
            $out .= $this->boxToCell($keyIsString ? Type::string_() : Type::int_());
            $keyCell = $this->lastValue;
        }

        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca i64\n";
        $isBox = $this->ssa->allocReg();
        $out .= '  ' . $isBox . ' = icmp ugt i64 ' . $cv . ", -4503599627370496\n";
        $ts = $this->ssa->allocReg();
        $out .= '  ' . $ts . ' = lshr i64 ' . $cv . ", 48\n";
        $nib = $this->ssa->allocReg();
        $out .= '  ' . $nib . ' = and i64 ' . $ts . ", 15\n";
        $arrL = $this->ssa->allocLabel('eidx.arr');
        $endL = $this->ssa->allocLabel('eidx.end');

        if ($holders !== []) {
            $objL = $this->ssa->allocLabel('eidx.obj');
            $notObjL = $this->ssa->allocLabel('eidx.notobj');
            $isObjNib = $this->ssa->allocReg();
            $out .= '  ' . $isObjNib . ' = icmp eq i64 ' . $nib . ", 8\n";
            $isObj = $this->ssa->allocReg();
            $out .= '  ' . $isObj . ' = and i1 ' . $isBox . ', ' . $isObjNib . "\n";
            $out .= '  br i1 ' . $isObj . ', label %' . $objL . ', label %' . $notObjL . "\n";

            $out .= $objL . ":\n";
            $pm = $this->ssa->allocReg();
            $out .= '  ' . $pm . ' = and i64 ' . $cv . ", 281474976710655\n";
            $pp = $this->ssa->allocReg();
            $out .= '  ' . $pp . ' = inttoptr i64 ' . $pm . " to ptr\n";
            $out .= $this->emitErasedIfaceCall($pp, 'ArrayAccess', 'offsetGet', [$keyCell]);
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
            $out .= '  br label %' . $endL . "\n";

            $out .= $notObjL . ":\n";
        }

        // A STRING subject indexes a byte. A string KEY is excluded: php has no
        // string offset by string key (`$s['id']` is a TypeError), so the array
        // path already answers that shape.
        if (!$keyIsString) {
            $strL = $this->ssa->allocLabel('eidx.str');
            $isStrNib = $this->ssa->allocReg();
            $out .= '  ' . $isStrNib . ' = icmp eq i64 ' . $nib . ", 4\n";
            $isStr = $this->ssa->allocReg();
            $out .= '  ' . $isStr . ' = and i1 ' . $isBox . ', ' . $isStrNib . "\n";
            $out .= '  br i1 ' . $isStr . ', label %' . $strL . ', label %' . $arrL . "\n";

            $out .= $strL . ":\n";
            $sm = $this->ssa->allocReg();
            $out .= '  ' . $sm . ' = and i64 ' . $cv . ", 281474976710655\n";
            $sp = $this->ssa->allocReg();
            $out .= '  ' . $sp . ' = inttoptr i64 ' . $sm . " to ptr\n";
            // The OFFSET rides the same erased channel as the subject, so unbox
            // it to a machine int before it can index bytes — this is the half
            // the old int-only guard silently skipped.
            $si = $key;
            if ($keyIsCell) {
                $this->lastValue = $key;
                $this->lastValueType = 'i64';
                $out .= $this->unboxCellToType(Type::int_());
                $si = $this->lastValue;
            }
            $ch = $this->ssa->allocReg();
            $out .= '  ' . $ch . ' = call ptr @__mir_str_char_at(ptr ' . $sp
                  . ', i64 ' . $si . ")\n";
            $this->lastValue = $ch;
            $this->lastValueType = 'ptr';
            // Re-box: the array arm yields a cell, so every arm must agree.
            $out .= $this->boxToCell(Type::string_());
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
            $out .= '  br label %' . $endL . "\n";
        } else {
            $out .= '  br label %' . $arrL . "\n";
        }

        $out .= $arrL . ":\n";
        $am = $this->ssa->allocReg();
        $out .= '  ' . $am . ' = and i64 ' . $cv . ", 281474976710655\n";
        $ap = $this->ssa->allocReg();
        $out .= '  ' . $ap . ' = inttoptr i64 ' . $am . " to ptr\n";
        $av = $this->ssa->allocReg();
        if ($keyIsCell) {
            $this->rt->needsCellKey = true;
            $out .= '  ' . $av . ' = call i64 @__mir_array_get_cell(ptr ' . $ap
                  . ', i64 ' . $key . ")\n";
        } elseif ($keyIsString) {
            $out .= '  ' . $av . ' = call i64 @__mir_array_get_str(ptr ' . $ap
                  . ', ptr ' . $key . $this->litKeyHashArgs($aa->index) . ")\n";
        } else {
            $out .= '  ' . $av . ' = call i64 @__mir_array_get_int(ptr ' . $ap
                  . ', i64 ' . $key . ")\n";
        }
        $out .= '  store i64 ' . $av . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $endL . "\n";

        $out .= $endL . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $slot . "\n";
        // Both arms are done with the key here (the register dominates the
        // join), so a fresh temp dies once ({@see EmitLlvm::keyTempRelease}).
        // The BOXED copy is the same pointer re-tagged, never a second buffer.
        if ($keyIsCell || $keyIsString) {
            $out .= $this->keyTempRelease($aa->index, $key, $keyIsCell);
        }
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        if ($self->type->kind === Type::KIND_FLOAT) {
            $rf = $this->ssa->allocReg();
            $out .= '  ' . $rf . ' = bitcast i64 ' . $r . " to double\n";
            $this->lastValue = $rf;
            $this->lastValueType = 'double';
        }
        return $out;
    }

    private function emitArrayAccessUnified(Node $self, ArrayAccess_ $aa): string
    {
        $out = $this->emitNode($aa->array);
        // A `mixed`/cell base (e.g. a nested value out of json_decode) — and an
        // ERASED one, which may hold the very same boxed word — carries the
        // array pointer NaN-boxed ({@see arrayBaseToPtr}).
        $out .= $this->arrayBaseToPtr($aa->array->type);
        $arrPtr = $this->lastValue;
        // A `mixed`/cell index (int-OR-string at runtime) → dispatch helper.
        $keyIsCell = $this->keyRidesCellChannel($aa->index);
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
        // A read keeps no key — drop a fresh one ({@see EmitLlvm::keyTempRelease}).
        if ($keyIsCell || $keyIsString) {
            $out .= $this->keyTempRelease($aa->index, $key, $keyIsCell);
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        // A REFERENCE element yields what it refers to. This is not the decode
        // the ⚠ note below refuses: that one would hand a consumer a CELL where
        // it was promised a raw word, inventing a representation. A ref deref
        // only ever REMOVES one — `$refs[0]` in php IS the referenced value, and
        // there is no rvalue spelling for the binding itself.
        if ($this->rt->needsRefCells) {
            $this->rt->needsTagged = true;
            $d = $this->ssa->allocReg();
            $out .= '  ' . $d . ' = call i64 @__manticore_deref(i64 ' . $reg . ")\n";
            $reg = $d;
            $this->lastValue = $reg;
        }
        // ⚠ The element is NOT decoded into a CELL by the array's hint here. Two
        // consumers proved a plain subscript cannot be: an UNKNOWN result is
        // deref'd raw (`function sset(array $x) { $x["k"] = "z"; return $x["k"]; }`
        // returns the word into a string slot), and a CELL result is written back
        // into a raw-repr array by the sort family. That decode lives where a
        // value is genuinely CONSUMED as a cell — array_pop/array_shift and
        // implode — until the store side learns to re-encode.
        //
        // The OTHER direction is sound and is done: a result the static type
        // already calls a STRING or an OBJECT must be a raw pointer, so if the
        // array says its slots are boxed cells, strip the tag. Nothing
        // downstream is told anything new — it was promised a pointer and now
        // gets one. Two witnesses: symfony's `$this->headers[0]` (a declared
        // `string[]` fed from cell rows) printed the tag bits, and a BY-REF
        // array param written back from an erased local (`$read = $nr;` in
        // stream_select) handed the caller boxed elements under a `vec[obj]`
        // static type — `$r[0] instanceof R` then inttoptr'd the tag.
        $rk = $self->type->kind;
        if (($rk === Type::KIND_STRING || $rk === Type::KIND_OBJ)
            && $this->elemMayBeCell($aa->array->type)) {
            $this->rt->needsElemUntag = true;
            $u = $this->ssa->allocReg();
            $out .= '  ' . $u . ' = call i64 @__mir_elem_untag(ptr ' . $arrPtr
                  . ', i64 ' . $reg . ")\n";
            $reg = $u;
            $this->lastValue = $reg;
        }
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
    /**
     * The element-repr code to stamp when an rc-managed value lands in an
     * ERASED (vec[unknown]) array — else null (scalar / erased / no-rc value, or
     * a concrete-element array that already carries a typed release flavor). The
     * value already gives the array a droppable +1 (a fresh producer transfers,
     * a borrow is retained — see {@see EmitLlvmMemory::rcRetainByType}); the
     * stamp lets the plain repr release drop it. Homogeneity is guaranteed
     * upstream: a heterogeneous erased array is promoted to vec[cell] (the
     * array-repr-conflict check), so it never reaches this raw path.
     *
     * ⚠ OWNERSHIP, not shape — a concrete-element array must NOT be stamped
     * here even though its elements are perfectly describable: `uasort` moves
     * them into a fresh buffer and writes it back WITHOUT retaining, so a
     * stamped source frees the strings the result still points at
     * (`natsort` over a `vec[string]`). Shape is the separate hint nibble,
     * {@see elementHintCodeForType}.
     */
    private function erasedReprCode(StoreElement $se): ?int
    {
        $at = $this->storeBaseReleaseType($se);
        // Only the erased path (element unknown) releases via the repr helper;
        // a concrete element uses vecstr/vecobj/veccell and must not be stamped.
        $erased = $at->kind === Type::KIND_UNKNOWN
            || ($at->element !== null && $at->element->kind === Type::KIND_UNKNOWN)
            || ($at->element === null && ($at->isVec() || $at->isAssoc()));
        if (!$erased) { return null; }
        $vt = $se->value->type;
        $vk = $vt->kind;
        if ($vk === Type::KIND_STRING) { return \Compile\MemoryAbi::ARRAY_REPR_STR; }
        if ($vk === Type::KIND_ARRAY) { return \Compile\MemoryAbi::ARRAY_REPR_ARR; }
        // NOT KIND_CELL: a raw CELL value stored into the erased else-branch is
        // NOT co-owned (rcRetainByType returns '' for a cell — a borrowed
        // string|false from readdir), so stamping CELL would drop a string the
        // array never retained (the stat_functions UAF). A cell value stays
        // unstamped (borrowed, its owner frees it); a genuinely-owned cell rides
        // the boxVal path (retainCellPayload) or a cell LITERAL self-stamps.
        if ($vk === Type::KIND_OBJ) {
            $cls = $vt->class ?? '';
            if ($cls === 'Ffi\\Ptr') { return null; }
            if ($cls === 'Generator') { return \Compile\MemoryAbi::ARRAY_REPR_STR; }
            if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return null; }
            if ($this->isClosureClass($cls)) { return null; }
            if ($this->isEnumClass($cls)) { return null; }
            return \Compile\MemoryAbi::ARRAY_REPR_OBJ;
        }
        return null;
    }

    /**
     * The type the RELEASE of this store's base will be chosen from — the type
     * pinned on the rc local at its FIRST owned store ({@see
     * InsertMemoryOps}), not the possibly-narrowed type at this site.
     *
     * The two must be read from ONE place or the array is left half-described.
     * `$out = []; …; if (…) { $out[$k] = ''; continue; } $out[$k2] = substr(…);`
     * is the shape that proved it: the first store still saw `assoc[string,
     * unknown]` and stamped STR, the second saw the NARROWED `assoc[string,
     * string]` and stamped nothing — and since only the second one ever runs,
     * the buffer reached its release with repr bits of zero while the release
     * itself was chosen from the erased type, i.e. the plain repr walk. It
     * dropped nothing: two leaked strings per call, the whole of `parseQuery`.
     *
     * Reading the local's recorded type here also keeps the ⚠ above intact — a
     * local whose recorded type is CONCRETE stays unstamped even where a store
     * site sees it erased, which is exactly the `uasort`/`natsort` write-back
     * the flavored release owns.
     */
    private function storeBaseReleaseType(StoreElement $se): Type
    {
        $base = $se->array;
        if ($base->kind !== Node::KIND_LOAD_LOCAL) { return $base->type; }
        $mo = $this->frame->rcObjLocals[$base->name] ?? null;
        if ($mo === null) { return $base->type; }
        $t = $mo->target;
        if ($t === null) { return $base->type; }
        return $t->type;
    }

    /**
     * The element-KIND HINT for an element type — what the elements ARE, said
     * without any claim about ownership ({@see MemoryAbi::ARRAY_ELEM_HINT_MASK}).
     * The exclusions mirror {@see EmitLlvm::discardReleaseFlavor}: a decoded
     * cell is later DROPPED by tag, so anything without the rc header that tag
     * implies — `#[Struct]`, `Ffi\Ptr`, a closure, an enum singleton, a
     * Generator frame (str-style header, object tag) — must stay hintless and
     * ride raw, exactly as it does today. Scalars have nothing to decode.
     */
    private function elementHintCodeForType(?Type $el): ?int
    {
        if ($el === null) { return null; }
        $k = $el->kind;
        if ($k === Type::KIND_STRING) { return \Compile\MemoryAbi::ARRAY_ELEM_HINT_STR; }
        if ($k === Type::KIND_ARRAY) { return \Compile\MemoryAbi::ARRAY_ELEM_HINT_ARR; }
        if ($k === Type::KIND_CELL) { return \Compile\MemoryAbi::ARRAY_ELEM_HINT_CELL; }
        if ($k === Type::KIND_OBJ) {
            $cls = $el->class;
            if ($cls === null) { $cls = ''; }
            if ($cls === 'Ffi\\Ptr' || $cls === 'Generator') { return null; }
            if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return null; }
            if ($this->isClosureClass($cls)) { return null; }
            if ($this->isEnumClass($cls)) { return null; }
            return \Compile\MemoryAbi::ARRAY_ELEM_HINT_OBJ;
        }
        return null;
    }

    /** The hint that matches an ownership repr code — the two nibbles are
     *  stamped together on the erased path so a reader can decode what the
     *  release will drop. */
    private function hintForReprCode(int $repr): ?int
    {
        if ($repr === \Compile\MemoryAbi::ARRAY_REPR_STR) { return \Compile\MemoryAbi::ARRAY_ELEM_HINT_STR; }
        if ($repr === \Compile\MemoryAbi::ARRAY_REPR_OBJ) { return \Compile\MemoryAbi::ARRAY_ELEM_HINT_OBJ; }
        if ($repr === \Compile\MemoryAbi::ARRAY_REPR_ARR) { return \Compile\MemoryAbi::ARRAY_ELEM_HINT_ARR; }
        if ($repr === \Compile\MemoryAbi::ARRAY_REPR_CELL) { return \Compile\MemoryAbi::ARRAY_ELEM_HINT_CELL; }
        return null;
    }

    /** Emit `flags = (flags & ~HINT_MASK) | code` on `$arrPtr` — record what
     *  the elements ARE. Ownership-free: no release/retain/cow reads it. */
    private function emitElemHintStamp(string $arrPtr, int $code): string
    {
        $fo = (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET;
        $fp = $this->ssa->allocReg();
        $out  = '  ' . $fp . ' = getelementptr inbounds i8, ptr ' . $arrPtr . ', i64 ' . $fo . "\n";
        $fl = $this->ssa->allocReg();
        $out .= '  ' . $fl . ' = load i64, ptr ' . $fp . "\n";
        $cl = $this->ssa->allocReg();
        $out .= '  ' . $cl . ' = and i64 ' . $fl . ', '
              . (string)(~\Compile\MemoryAbi::ARRAY_ELEM_HINT_MASK) . "\n";
        $nw = $this->ssa->allocReg();
        $out .= '  ' . $nw . ' = or i64 ' . $cl . ', ' . (string)$code . "\n";
        $out .= '  store i64 ' . $nw . ', ptr ' . $fp . "\n";
        return $out;
    }

    /** Emit `flags = (flags & ~REPR_MASK) | code` on `$arrPtr` — stamp the
     *  element repr. Homogeneous, so overwrite (not OR) is exact. */
    private function emitReprStamp(string $arrPtr, int $code): string
    {
        $fo = (string)\Compile\MemoryAbi::ARRAY_FLAGS_OFFSET;
        $fp = $this->ssa->allocReg();
        $out  = '  ' . $fp . ' = getelementptr inbounds i8, ptr ' . $arrPtr . ', i64 ' . $fo . "\n";
        $fl = $this->ssa->allocReg();
        $out .= '  ' . $fl . ' = load i64, ptr ' . $fp . "\n";
        $cl = $this->ssa->allocReg();
        $out .= '  ' . $cl . ' = and i64 ' . $fl . ', ' . (string)(~\Compile\MemoryAbi::ARRAY_REPR_MASK) . "\n";
        $nw = $this->ssa->allocReg();
        $out .= '  ' . $nw . ' = or i64 ' . $cl . ', ' . (string)$code . "\n";
        $out .= '  store i64 ' . $nw . ', ptr ' . $fp . "\n";
        return $out;
    }

    /**
     * `$base` (when given) is the container being mutated: a local whose
     * reference OWNS one element ref per element hands that ref to the cow,
     * which consumes the reference — so the cow must give it back. Without it
     * `$s = $this->map; $s[$k] = v;` in a loop strands every key and value on
     * the source buffer ({@see UnifiedArrayRuntime::emitCowVariant}).
     */
    private function cowSymbolFor(Type $t, ?Node $base = null): string
    {
        $sym = $this->cowSymbolPlain($t);
        if ($base !== null && $base->kind === Node::KIND_LOAD_LOCAL
            && isset($this->frame->ownElemLocals[$base->name])
            && ($sym === '@__mir_array_cow_str' || $sym === '@__mir_array_cow_obj'
                || $sym === '@__mir_array_cow_cell')) {
            return \str_replace('_cow_', '_cow_ownel_', $sym);
        }
        return $sym;
    }

    private function cowSymbolPlain(Type $t): string
    {
        // A genuinely-CELL carrier holds boxed cells → cow_cell. An ERASED
        // (unknown) array/element carries RAW homogeneous elements stamped with
        // a repr code → the repr cow co-owns exactly what the repr release drops
        // (cow_cell's cell_retain no-ops on a raw ptr → the clone wouldn't
        // co-own and both owners would double-drop).
        if ($t->kind === Type::KIND_CELL) { return '@__mir_array_cow_cell'; }
        if ($t->kind === Type::KIND_UNKNOWN) { return '@__mir_array_cow'; }
        $el = $t->element;
        if ($el === null) { return '@__mir_array_cow'; }
        $ek = $el->kind;
        if ($ek === Type::KIND_CELL) { return '@__mir_array_cow_cell'; }
        if ($ek === Type::KIND_UNKNOWN) { return '@__mir_array_cow'; }
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

    /**
     * The element type a CELL value must be UNBOXED to before it lands in a
     * CONCRETE-element array — the per-ELEMENT analogue of the whole-array
     * {@see needsDeCellify} reabstraction.
     *
     * Without it the tagged bits are stored raw into a slot whose retain /
     * release / read all treat them as that raw type, and a later
     * `__mir_array_retain_str` DEREFERENCES a NaN-boxed cell (a `?string`
     * ternary — `isset($a[$k]) ? $a[$k] : null`, which `nullableOf` lifts to a
     * cell — stored into a declared `array<string,string>`; it SIGSEGV'd the
     * self-host in `ClassDecl::__construct`). Once unboxed the payload is a bare
     * pointer, so the caller retains per THIS type instead of
     * {@see storeRetainFallback}'s null (which is right only for a value that
     * stays boxed — rc-bumping tagged bits would corrupt them).
     *
     * Null when nothing to do: a non-cell value, or a cell/unknown destination
     * element (which legitimately stores the value boxed).
     */
    private function storeElemDeCellifyType(StoreElement $se): ?Type
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

    /**
     * Does a StoreElement NaN-box its value into the slot? A cell BASE (a
     * `mixed` property / param holding the array) or a cell ELEMENT type both
     * store boxed, and that path co-owns the payload through
     * {@see EmitLlvm::retainCellPayload} instead of {@see rcRetainByType}.
     *
     * ⚠ The ONE owner of that question: {@see emitStoreElementUnified} reads it
     * to pick the arm, and {@see EmitLlvmMemory::collectTransferredLocals} reads
     * it to pick the matching retain predicate. Two copies drift, and a drift
     * here is a leak (pass says borrowed, emitter retains) or a double free.
     */
    private function storeElemBoxesValue(StoreElement $se): bool
    {
        $at = $se->array->type;
        if ($at->kind === Type::KIND_CELL) { return true; }
        $et = $at->element;
        // ⚠ KNOWN GAP, deliberately NOT widened to KIND_UNKNOWN here.
        //
        // The READ side decodes an `unknown` element as a TAGGED cell
        // ({@see arrayBaseToPtr}, {@see storeElemDeCellifyType} both pair
        // UNKNOWN with CELL), while this predicate stores raw — so a container
        // whose two ends are inferred apart disagrees about the repr. The shape
        // that shows it: `$a = []; $f = function () use (&$a) { $a[] = 'lit'; };`
        // leaves the outer local vec[unknown] while the closure body still sees
        // a `string`, and `echo $a[0]` then prints the ADDRESS (var_dump says
        // float(2.1E-314)). See tests/aot/cases/array_erased_elem_repr_gap.php.
        //
        // Making this arm return true for UNKNOWN was tried and does NOT work:
        // the container's repr nibble is fixed at ALLOCATION, so boxed values in
        // a raw-repr vec make the release path free tagged words — the self-host
        // gen-2 compiler segfaults on its own smoke test. Closing it needs the
        // erased element channel RETYPED to cell end-to-end, which is the parked
        // element-repr epic, not a change to this predicate.
        return $et !== null && $et->kind === Type::KIND_CELL;
    }

    /** As {@see storeElemBoxesValue} for an array LITERAL — its `$cellVals`. */
    private function litBoxesValues(ArrayLit $al): bool
    {
        $el = $al->type->element;
        return $el !== null && $el->kind === Type::KIND_CELL;
    }

    /**
     * The VALUE half of an element store, shared by all four arms of
     * {@see emitStoreElementUnified}.
     *
     * The arms differ only in their KEY channel — which key they emit, how they
     * coerce it, which `__mir_array_set_*` they call and what they release
     * afterwards. What lands in the slot is decided by `$se` alone, so it is one
     * answer, not four. Keeping it one is a precondition for teaching the store
     * a new element representation: honoured in three arms and missed in the
     * fourth is a silent wrong answer, which is the shape
     * {@see \Compile\Mir\Passes\EmitLlvmArrays} has already paid for once
     * (see docs/design/reference-cells.md).
     *
     * The caller must have emitted its key FIRST — both halves write
     * {@see lastValue} and both can have side effects, so the order is PHP's
     * evaluation order and not an implementation detail.
     *
     * Leaves the value word in {@see elemValReg}.
     */
    private function emitStoreElemValue(StoreElement $se, bool $boxVal): string
    {
        $out = $this->emitNode($se->value);
        if ($boxVal) {
            // A CELL slot keeps the payload BY POINTER — co-own it, exactly as
            // the cell array-literal path does. Without this the value is freed
            // by its source's release while the array still points at it:
            // `foreach (__mc_env() as $k => $v) { $out[$k] = $v; }` left every
            // $_SERVER value dangling the moment the temp subject was released.
            $out .= $this->retainCellPayload($se->value);
            $out .= $this->boxToCell($se->value->type, $se->value);
            $this->elemValReg = $this->lastValue;
            return $out;
        }
        $dcT = $this->storeElemDeCellifyType($se);
        if ($dcT !== null) { $out .= $this->unboxCellToType($dcT); }
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $out .= $this->rcRetainByType($se->value, $val, $dcT ?? $this->storeRetainFallback($se), 3);
        $this->elemValReg = $val;
        return $out;
    }

    /**
     * Write THROUGH a reference already sitting in the element slot.
     *
     * `$refs = [&$a]; $refs[0] = 10;` assigns to `$a` — the element holds a
     * binding, and a store to it is a store to what it binds. Overwriting the
     * cell instead loses the reference silently, which is the class of answer
     * this epic exists to remove.
     *
     * Branch-free on purpose. The alternative is a two-way split with a `phi`
     * for the array pointer, in EVERY key arm; this keeps the four arms the
     * straight-line code {@see emitStoreElemValue} just finished making them.
     *
     *  - the value handed to the set call becomes the ORIGINAL cell when the
     *    slot holds a reference, so the binding survives the store;
     *  - the write lands in the box when it is a reference and in a scratch
     *    word when it is not. The dead store costs one word and is what buys
     *    the absence of a branch.
     *
     * `$cur` is the element as it is NOW (the caller emits the matching
     * `__mir_array_get_*`). Leaves the value to store in {@see elemValReg}.
     */
    private function emitElemWriteThrough(string $cur, string $val): string
    {
        $istag = $this->ssa->allocReg();
        $sh = $this->ssa->allocReg();
        $nib = $this->ssa->allocReg();
        $isr = $this->ssa->allocReg();
        $both = $this->ssa->allocReg();
        $mask = $this->ssa->allocReg();
        $boxp = $this->ssa->allocReg();
        $scratch = $this->ssa->allocReg();
        $dst = $this->ssa->allocReg();
        $keep = $this->ssa->allocReg();
        $out  = '  ' . $scratch . " = alloca i64\n";
        $out .= '  ' . $istag . ' = icmp ugt i64 ' . $cur . ", -4503599627370496\n";
        $out .= '  ' . $sh . ' = lshr i64 ' . $cur . ", 48\n";
        $out .= '  ' . $nib . ' = and i64 ' . $sh . ", 15\n";
        $out .= '  ' . $isr . ' = icmp eq i64 ' . $nib . ', '
              . (string)\Compile\MemoryAbi::CELL_TAG_REF . "\n";
        $out .= '  ' . $both . ' = and i1 ' . $istag . ', ' . $isr . "\n";
        $out .= '  ' . $mask . ' = and i64 ' . $cur . ', '
              . (string)\Compile\MemoryAbi::CELL_PAYLOAD_MASK . "\n";
        $out .= '  ' . $boxp . ' = inttoptr i64 ' . $mask . " to ptr\n";
        $out .= '  ' . $dst . ' = select i1 ' . $both . ', ptr ' . $boxp
              . ', ptr ' . $scratch . "\n";
        $out .= '  store i64 ' . $val . ', ptr ' . $dst . "\n";
        $out .= '  ' . $keep . ' = select i1 ' . $both . ', i64 ' . $cur
              . ', i64 ' . $val . "\n";
        $this->elemValReg = $keep;
        return $out;
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
        // A STATIC property is a mutable shared slot exactly like the other two
        // (and {@see vecWriteBack} already threads the realloced buffer back
        // into its cell), so it COWs on the same terms.
        $cowFn = $this->cowSymbolFor($se->array->type, $se->array);
        if ($se->array->kind === Node::KIND_LOAD_LOCAL
            || $se->array->kind === Node::KIND_PROPERTY_ACCESS
            || $se->array->kind === Node::KIND_STATIC_PROP) {
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
        $keyIsCell = !$isAppend && $this->keyRidesCellChannel($se->index);
        $keyIsString = !$isAppend && !$keyIsCell
            && ($se->index->type->kind === Type::KIND_STRING
                || $se->index->kind === Node::KIND_STRING_CONST);
        // A cell-element container (a KIND_CELL base, OR an assoc/vec whose
        // ELEMENT type is a cell) stores every value NaN-boxed — like the
        // literal path's $cellVals. Without it a read-back / var_dump misreads
        // a raw i64 as a tagged cell (`$e["s"]="hi"; $e["a"]=[1,2]`).
        $boxVal = $this->storeElemBoxesValue($se);
        $next = $this->ssa->allocReg();
        if ($isAppend) {
            $out .= $this->emitStoreElemValue($se, $boxVal);
            $val = $this->elemValReg;
            $out .= '  ' . $next . ' = call ptr @__mir_array_append(ptr ' . $arrPtr . ', i64 ' . $val . ")\n";
        } elseif ($keyIsCell) {
            $this->rt->needsCellKey = true;
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToI64();
            $key = $this->lastValue;
            $out .= $this->emitStoreElemValue($se, $boxVal);
            $val = $this->elemValReg;
            if ($this->rt->needsRefCells) {
                $curE = $this->ssa->allocReg();
                $out .= '  ' . $curE . ' = call i64 @__mir_array_get_cell(ptr ' . $arrPtr . ', i64 ' . $key . ")\n";
                $out .= $this->emitElemWriteThrough($curE, $val);
                $val = $this->elemValReg;
            }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_cell(ptr ' . $arrPtr . ', i64 ' . $key . ', i64 ' . $val . ")\n";
            // set_cell's string arm RETAINS the stored key exactly like set_str
            // below — release our own +1 on a FRESH key cell (a mixed-returning
            // call / concat used directly as `$o[f()] = v`), or every dynamic
            // string key leaks. Borrowed producers (local / element / property
            // reads) stay untouched, balanced by their owner's release; scalar
            // cells no-op inside cell_drop.
            $ik = $se->index->kind;
            if ($ik === Node::KIND_CALL || $ik === Node::KIND_METHOD_CALL
                || $ik === Node::KIND_STATIC_CALL || $ik === Node::KIND_INVOKE
                || $ik === Node::KIND_CONCAT) {
                $this->rt->needsRc = true;
                $this->rt->needsStrRc = true;
                $out .= '  call void @__mir_cell_drop(i64 ' . $key . ")\n";
            }
        } elseif ($keyIsString) {
            $out .= $this->emitNode($se->index);
            $out .= $this->coerceToPtr();
            $key = $this->lastValue;
            $out .= $this->emitStoreElemValue($se, $boxVal);
            $val = $this->elemValReg;
            if ($this->rt->needsRefCells) {
                $curE = $this->ssa->allocReg();
                $out .= '  ' . $curE . ' = call i64 @__mir_array_get_str(ptr ' . $arrPtr . ', ptr ' . $key . $this->litKeyHashArgs($se->index) . ")\n";
                $out .= $this->emitElemWriteThrough($curE, $val);
                $val = $this->elemValReg;
            }
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
            $out .= $this->emitStoreElemValue($se, $boxVal);
            $val = $this->elemValReg;
            if ($this->rt->needsRefCells) {
                $curE = $this->ssa->allocReg();
                $out .= '  ' . $curE . ' = call i64 @__mir_array_get_int(ptr ' . $arrPtr . ', i64 ' . $idx . ")\n";
                $out .= $this->emitElemWriteThrough($curE, $val);
                $val = $this->elemValReg;
            }
            $out .= '  ' . $next . ' = call ptr @__mir_array_set_int(ptr ' . $arrPtr . ', i64 ' . $idx . ', i64 ' . $val . ")\n";
        }
        // Stamp the element repr on the persisted buffer ($next may be a
        // realloced / promoted / deimmortalised buffer) so the plain repr
        // release/retain/cow drop/co-own this erased array's raw elements.
        $reprCode = $this->erasedReprCode($se);
        if ($reprCode !== null) { $out .= $this->emitReprStamp($next, $reprCode); }
        // The SHAPE hint is stamped whatever the ownership answer was: an
        // erased store describes the value it just wrote, a concrete container
        // describes its declared element, and a boxed (cell) store says so.
        $hint = null;
        if ($reprCode !== null) {
            $hint = $this->hintForReprCode($reprCode);
        } elseif ($boxVal) {
            $hint = \Compile\MemoryAbi::ARRAY_ELEM_HINT_CELL;
        } else {
            $et = $se->array->type->element;
            if ($et !== null && $et->kind !== Type::KIND_UNKNOWN) {
                $hint = $this->elementHintCodeForType($et);
            } else {
                // An ERASED container is described by what actually lands in
                // it, and a value with no describable shape (a scalar, or an
                // erased word) CLEARS the hint rather than leaving a stale one:
                // a mixed array whose last claim was STR would hand a reader an
                // integer to dereference.
                $hint = $this->elementHintCodeForType($se->value->type);
                if ($hint === null) { $hint = 0; }
            }
        }
        if ($hint !== null) { $out .= $this->emitElemHintStamp($next, $hint); }
        $out .= $this->vecWriteBack($se->array, $next, $baseCell);
        $this->lastValue = $val;
        $this->lastValueType = 'i64';
        return $out;
    }
}
