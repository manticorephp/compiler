<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Call;
use Compile\Mir\Node;
use Compile\Mir\Type;

/**
 * PHP-builtin call emitters extracted from {@see EmitLlvm}: emitBuiltin's
 * dispatch plus every `bi*` handler and their cell-boxing / string-literal
 * helpers. Pure $this-bound methods; behaviour unchanged (compile-time
 * textual include). Split out 2026-06-08.
 */
trait EmitLlvmBuiltins
{
    /**
     * Recognise + emit a PHP builtin call. Returns null for an
     * unknown / user function so {@see emitCall} falls through to a
     * normal `@manticore_*` call. Builtins stay `Call` MIR nodes; only
     * inference ({@see InferTypes::builtinReturnType}) + this emitter
     * special-case them. Semantics track Zend where cheap.
     */
    private function emitBuiltin(Call $c): ?string
    {
        $name = \strtolower($c->function);
        if (\str_contains($name, '\\')) {
            $name = \substr($name, \strrpos($name, '\\') + 1);
        }
        $args = $c->args;
        // Routed to one `bi*` emitter per builtin. NB: a `match ($name)`
        // would read cleaner, but the self-host AST backend compares
        // `match` string arms by identity, so a computed subject
        // (`strtolower(...)` → fresh heap string) never matches a string
        // literal and always hits `default`. The `===` operator does a
        // value compare, so an if-chain is the working dispatch until
        // the self-host match string-compare bug is fixed.
        if ($name === 'manticore_box_int')            { return $this->biTaggedCall('__manticore_box_int', $args); }
        if ($name === 'manticore_unbox_int')          { return $this->biTaggedCall('__manticore_unbox_int', $args); }
        if ($name === 'manticore_tag')                { return $this->biTaggedCall('__manticore_tag', $args); }
        if ($name === 'strlen')                       { return $this->biStrlen($args); }
        if ($name === 'str_from_buffer')              { return $this->biStrFromBuffer($args); }
        if ($name === 'cstr_to_str')                  { return $this->biCstrToStr($args); }
        if ($name === '__mir_stdin')                  { return $this->biStdStream('stdin'); }
        if ($name === '__mir_stdout')                 { return $this->biStdStream('stdout'); }
        if ($name === '__mir_stderr')                 { return $this->biStdStream('stderr'); }
        if ($name === '__mir_argc')                   { return $this->biCliArgc(); }
        if ($name === '__mir_argv_at')                { return $this->biCliArgvAt($args); }
        if ($name === '__mir_to_cell')                { return $this->biToCell($args); }
        if ($name === 'count' || $name === 'sizeof')  { return $this->biCount($args); }
        if ($name === 'ord')                          { return $this->biOrd($args); }
        if ($name === 'chr')                          { return $this->biChr($args); }
        if ($name === 'abs')                          { return $this->biAbs($args); }
        if ($name === 'pow')                          { return $this->biPow($args); }
        if ($name === 'intdiv')                       { return $this->biIntdiv($args); }
        if ($name === 'floor')                        { return $this->biFloatUnary($args, 'llvm.floor.f64'); }
        if ($name === 'ceil')                         { return $this->biFloatUnary($args, 'llvm.ceil.f64'); }
        if ($name === 'sqrt')                         { return $this->biFloatUnary($args, 'llvm.sqrt.f64'); }
        if ($name === 'round')                        { return $this->biRound($args); }
        if ($name === 'fmod')                         { return $this->biFmod($args); }
        // Trig / exp / log. LLVM has intrinsics for sin/cos/exp/log/log10/log2;
        // the rest (tan, inverse trig, hyperbolic) are plain libm calls — same
        // `double NAME(double)` ABI, so biFloatUnary handles both by name.
        if ($name === 'sin')                          { return $this->biFloatUnary($args, 'llvm.sin.f64'); }
        if ($name === 'cos')                          { return $this->biFloatUnary($args, 'llvm.cos.f64'); }
        if ($name === 'tan')                          { return $this->biFloatUnary($args, 'tan'); }
        if ($name === 'asin')                         { return $this->biFloatUnary($args, 'asin'); }
        if ($name === 'acos')                         { return $this->biFloatUnary($args, 'acos'); }
        if ($name === 'atan')                         { return $this->biFloatUnary($args, 'atan'); }
        if ($name === 'sinh')                         { return $this->biFloatUnary($args, 'sinh'); }
        if ($name === 'cosh')                         { return $this->biFloatUnary($args, 'cosh'); }
        if ($name === 'tanh')                         { return $this->biFloatUnary($args, 'tanh'); }
        if ($name === 'exp')                          { return $this->biFloatUnary($args, 'llvm.exp.f64'); }
        if ($name === 'log10')                        { return $this->biFloatUnary($args, 'llvm.log10.f64'); }
        if ($name === 'log')                          { return $this->biLog($args); }
        if ($name === 'atan2')                        { return $this->biFloatBinary($args, 'atan2'); }
        if ($name === 'hypot')                        { return $this->biFloatBinary($args, 'hypot'); }
        if ($name === 'pi')                           { return $this->biPi(); }
        if ($name === 'deg2rad')                      { return $this->biFloatScale($args, '0x3F91DF46A2529D39'); }
        if ($name === 'rad2deg')                      { return $this->biFloatScale($args, '0x404CA5DC1A63C1F8'); }
        if ($name === 'intval')                       { return $this->biIntval($args); }
        if ($name === 'floatval')                     { return $this->biFloatval($args); }
        if ($name === '__mir_float_repr')             { return $this->biFloatRepr($args); }
        if ($name === 'is_null')                      { return $this->biIsType($args, 3, Type::KIND_NULL); }
        if ($name === 'is_int' || $name === 'is_integer' || $name === 'is_long') { return $this->biIsType($args, 1, Type::KIND_INT); }
        if ($name === 'is_string')                    { return $this->biIsType($args, 4, Type::KIND_STRING); }
        if ($name === 'is_float' || $name === 'is_double') { return $this->biIsType($args, 6, Type::KIND_FLOAT); }
        if ($name === 'is_bool')                      { return $this->biIsType($args, 2, Type::KIND_BOOL); }
        if ($name === 'is_array')                     { return $this->biIsType($args, 7, Type::KIND_ARRAY); }
        if ($name === 'is_object')                    { return $this->biIsType($args, 8, Type::KIND_OBJ); }
        if ($name === 'gettype')                      { return $this->biGettype($args, false); }
        if ($name === 'get_debug_type')               { return $this->biGettype($args, true); }
        if ($name === 'min')                          { return $this->biMinMax($args, 'slt'); }
        if ($name === 'max')                          { return $this->biMinMax($args, 'sgt'); }
        if ($name === 'dechex')                       { return $this->biDechex($args); }
        if ($name === 'substr')                       { return $this->biSubstr($args); }
        if ($name === 'str_repeat')                   { return $this->biStrRepeat($args); }
        if ($name === 'strtolower')                   { return $this->biCaseConv($args, '__mir_strtolower'); }
        if ($name === 'strtoupper')                   { return $this->biCaseConv($args, '__mir_strtoupper'); }
        if ($name === 'strpos')                       { return $this->biStrpos($args); }
        if ($name === 'explode' && \count($args) >= 2) { return $this->biExplode($args); }
        if ($name === 'print_r' && \count($args) >= 1) { return $this->biPrintR($args); }
        if ($name === 'implode' || $name === 'join')  { return $this->biImplode($args); }
        if ($name === 'sprintf')                      { return $this->biSprintf($args, false); }
        if ($name === 'printf')                       { return $this->biSprintf($args, true); }
        if ($name === 'exit' || $name === 'die')      { return $this->biExit($args); }
        if ($name === 'error_log')                    { return $this->biErrorLog($args); }
        if ($name === 'gc_collect_cycles')            { return $this->biGcCollect(); }
        if ($name === 'spl_object_id')                { return $this->biSplObjectId($args); }
        if ($name === 'var_dump')                     { return $this->biVarDump($args); }
        if ($name === 'get_class')                    { return $this->biGetClass($args); }
        if ($name === 'array_keys')                   { return $this->biArrayKeys($args); }
        if ($name === 'array_values') {
            $vt = $args[0]->type;
            if ($vt->kind === Type::KIND_CELL) { return $this->biArrayValues($args, null); }
            if ($vt->kind === Type::KIND_ARRAY && $vt->element !== null
                && $this->isConcreteCellKind($vt->element->kind)) {
                return $this->biArrayValues($args, $vt->element);
            }
            return null;
        }
        if ($name === 'array_pop')                    { return $this->biArrayPop($c); }
        if ($name === 'array_shift')                  { return $this->biArrayShift($c); }
        if ($name === 'array_unshift')                { return $this->biArrayUnshift($c); }
        if ($name === 'addslashes')                   { return $this->biAddslashes($args); }
        if ($name === '__mc_json_escape')             { return $this->biJsonEscape($args); }
        if ($name === '__mir_str_replace_one' && \count($args) === 3) { return $this->biStrReplaceOne($args); }
        if ($name === 'getenv')                       { return $this->biGetenv($args); }
        if ($name === 'get_object_vars')              { return $this->biGetObjectVars($args); }
        if ($name === 'var_export')                   { return $this->biVarExport($args); }
        // Reflection Tier-1: compile-time class queries folded from the static
        // class/enum table — no runtime metadata. Fold ONLY when the class (+
        // member name) is statically known (obj<C> type / string literal);
        // a dynamic arg conservatively folds to the not-found answer.
        if ($name === 'class_exists')                 { return $this->biClassExists($args, 'class'); }
        if ($name === 'enum_exists')                  { return $this->biClassExists($args, 'enum'); }
        if ($name === 'interface_exists')             { return $this->biClassExists($args, 'interface'); }
        if ($name === 'trait_exists')                 { return $this->biClassExists($args, 'trait'); }
        if ($name === 'method_exists')                { return $this->biMethodExists($args); }
        if ($name === 'property_exists')              { return $this->biPropertyExists($args); }
        if ($name === 'is_a')                         { return $this->biIsA($args, false); }
        if ($name === 'is_subclass_of')               { return $this->biIsA($args, true); }
        if ($name === 'get_parent_class')             { return $this->biGetParentClass($args); }
        if ($name === 'get_class_methods')            { return $this->biGetClassMethods($args); }
        return null;
    }

    /** @param Node[] $args */
    /**
     * NaN-box `$this->lastValue` (current type $t) into a tagged cell;
     * result i64 left in lastValue. Returns the IR.
     */
    private function boxToCell(Type $t): string
    {
        $this->needsTagged = true;
        $k = $t->kind;
        // Already a tagged cell — don't double-box.
        if ($k === Type::KIND_CELL) { return $this->coerceToI64(); }
        if ($k === Type::KIND_FLOAT) {
            $out = $this->coerceTo('double');
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_float(double ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($k === Type::KIND_STRING) {
            $out = $this->coerceToPtr();
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_ptr(ptr ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($k === Type::KIND_NULL) {
            $r = $this->allocSsa();
            $out = '  ' . $r . " = call i64 @__manticore_box_null()\n";
            return $this->finishI64($out, $r);
        }
        if ($t->isVec()) {
            $elem = $t->element;
            // A cell-wrapped array must carry cell elements. A homogeneous
            // vec (vec[int] etc.) is rebuilt with each element boxed so the
            // recursive consumers (var_dump / json_encode) see tagged cells.
            if ($elem !== null && $elem->kind !== Type::KIND_CELL
                && $elem->kind !== Type::KIND_UNKNOWN) {
                return $this->emitVecToCellArray($elem);
            }
            $out = $this->coerceToPtr();
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_array(ptr ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($t->isAssoc()) {
            $elem = $t->element;
            // A homogeneous-valued assoc (assoc[string,int] etc.) stores RAW
            // values; a cell consumer (var_dump / json_encode / a `mixed`
            // field) reads each as a tagged cell → a raw int has tag 0 → it
            // mis-dispatches (is_* all false → count() on an int → SIGSEGV).
            // Rebuild as a cell-assoc, boxing each value but keeping the keys
            // (mirrors the vec branch above).
            if ($elem !== null && $elem->kind !== Type::KIND_CELL
                && $elem->kind !== Type::KIND_UNKNOWN) {
                return $this->emitAssocToCellArrayUnified($elem);
            }
            $out = $this->coerceToPtr();
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_array(ptr ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($k === Type::KIND_OBJ || $k === Type::KIND_UNION) {
            // A union arm is a bare object pointer (all-object union) — box it as
            // an object cell so a tagged consumer (var_dump / a mixed param)
            // dispatches on the object tag and the class_id resolves the type.
            $out = $this->coerceToPtr();
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_object(ptr ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        $helper = ($k === Type::KIND_BOOL) ? '__manticore_box_bool' : '__manticore_box_int';
        $out = $this->coerceToI64();
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call i64 @' . $helper . '(i64 ' . $this->lastValue . ")\n";
        return $this->finishI64($out, $r);
    }

    /**
     * Strip a NaN-boxed cell (current lastValue, i64) to its payload
     * pointer: `(v & PAYLOAD_MASK)` then inttoptr. lastValue ← ptr.
     */
    private function cellToPtr(): string
    {
        $out = $this->coerceToI64();
        $m = $this->allocSsa();
        $out .= '  ' . $m . ' = and i64 ' . $this->lastValue . ", 281474976710655\n";
        $p = $this->allocSsa();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $m . " to ptr\n";
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Rebuild a homogeneous vec (ptr in lastValue) into a fresh
     * vec[cell], boxing each element per $elem; result boxed as an
     * ARRAY cell in lastValue.
     */
    private function emitVecToCellArray(Type $elem): string
    {
        return $this->emitVecToCellArrayUnified($elem);
    }

    /**
     * Unified PhpArray variant of {@see emitVecToCellArray} (--array=unified).
     *
     * A statically-"vec" array can be HASHED at runtime with sparse int keys
     * (`$o=[]; $o[1]=…; $o[3]=…` promotes via set_int, and array_filter keeps the
     * source indices) — a positional append rebuild would renumber those to
     * 0,1,2… So the rebuild preserves keys via the key-aware assoc path: for a
     * genuinely packed source the keys are 0..n and set_cell re-appends them
     * (the buffer stays packed), so this is correct AND no slower for the common
     * case. The two paths are identical now; vec delegates to assoc.
     */
    private function emitVecToCellArrayUnified(Type $elem): string
    {
        return $this->emitAssocToCellArrayUnified($elem);
    }

    /**
     * Rebuild a homogeneous-valued assoc (assoc[K,$elem], $elem a concrete
     * non-cell kind) into a fresh cell-assoc: each value boxed per $elem, KEYS
     * preserved (read NaN-boxed via `__mir_array_key_cell_at`, re-set via
     * `__mir_array_set_cell` which dispatches int/string by tag). Result boxed
     * as an ARRAY cell in lastValue. The assoc analogue of
     * {@see emitVecToCellArrayUnified}; without it a raw-valued assoc reaching
     * a cell consumer (var_dump) mis-dispatches each entry.
     */
    private function emitAssocToCellArrayUnified(Type $elem): string
    {
        $this->needsTagged = true;
        $this->needsCellKey = true;
        $out = $this->coerceToPtr();
        $rawSrc = $this->lastValue;
        // Empty `[]` → null ptr; redirect to the zero-word so len reads 0.
        $isNull = $this->allocSsa();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->allocSsa();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        $len = $this->allocSsa();
        $out .= '  ' . $len . ' = load i64, ptr ' . $src . "\n";
        $slot = $this->allocSsa();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->allocSsa();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $len . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->allocSsa();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->allocLabel('uac.cond');
        $body = $this->allocLabel('uac.body');
        $end  = $this->allocLabel('uac.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->allocSsa();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->allocSsa();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $kb = $this->allocSsa();
        $out .= '  ' . $kb . ' = call i64 @__mir_array_key_cell_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        $ev = $this->allocSsa();
        $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        $boxed = $this->allocSsa();
        $ek = $elem->kind;
        if ($ek === Type::KIND_STRING) {
            $ep = $this->allocSsa();
            $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_ptr(ptr ' . $ep . ")\n";
        } elseif ($ek === Type::KIND_FLOAT) {
            $ed = $this->allocSsa();
            $out .= '  ' . $ed . ' = bitcast i64 ' . $ev . " to double\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_float(double ' . $ed . ")\n";
        } elseif ($ek === Type::KIND_BOOL) {
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_bool(i64 ' . $ev . ")\n";
        } elseif ($ek === Type::KIND_OBJ) {
            $ep = $this->allocSsa();
            $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_object(ptr ' . $ep . ")\n";
        } elseif ($ek === Type::KIND_ARRAY) {
            // Nested array value → recursively rebuild as a cell-array (see the
            // vec variant) so its own elements render.
            $this->lastValue = $ev;
            $this->lastValueType = 'i64';
            $nestElem = $elem->element ?? Type::unknown();
            $out .= $elem->isAssoc()
                ? $this->emitAssocToCellArrayUnified($nestElem)
                : $this->emitVecToCellArrayUnified($nestElem);
            $boxed = $this->lastValue;
        } else {
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_int(i64 ' . $ev . ")\n";
        }
        $cur = $this->allocSsa();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->allocSsa();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_set_cell(ptr ' . $cur . ', i64 ' . $kb . ', i64 ' . $boxed . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->allocSsa();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->allocSsa();
        $out .= '  ' . $dst . ' = load ptr, ptr ' . $slot . "\n";
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call i64 @__manticore_box_array(ptr ' . $dst . ")\n";
        return $this->finishI64($out, $r);
    }

    /** @param Node[] $args  NaN-boxing helper call: i64 -> i64. */
    private function biTaggedCall(string $helper, array $args): string
    {
        $this->needsTagged = true;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call i64 @' . $helper . '(i64 ' . $this->lastValue . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * Emit `$arg` leaving a raw pointer in lastValue. A `mixed`/cell value
     * (NaN-boxed) used where a string/array/object pointer is expected is
     * unboxed (tag stripped) first — else a builtin like strlen derefs the
     * boxed bits → SIGSEGV. A non-cell coerces as usual.
     */
    private function emitPtrArg(Node $arg): string
    {
        $out = $this->emitNode($arg);
        if ($arg->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        // A null `?string` rides as ptr 0 (a NULL cell unboxes to payload 0);
        // string builtins must not deref it. Map a null ptr to the empty
        // string — PHP coerces null to "" for these (strlen("")=0, substr=").
        $ptr = $this->lastValue;
        $isNull = $this->allocSsa();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $ptr . ", null\n";
        $safe = $this->allocSsa();
        $out .= '  ' . $safe . ' = select i1 ' . $isNull
              . ', ptr ' . $this->strSymBytes('@.cstr.empty') . ', ptr ' . $ptr . "\n";
        $this->lastValue = $safe;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `str_from_buffer(\Ffi\Ptr $p, int $n): string` — the explicit raw-buffer
     * → headered-string conversion (known length, binary-safe). The ONLY way a
     * raw libc buffer (calloc/read) becomes a manticore string. Routes through
     * the central factory {@see stringCoreRuntime}.
     * @param Node[] $args
     */
    private function biStrFromBuffer(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $p = $this->lastValue;
        $out .= $this->emitIntArg($args[1]);
        $n = $this->lastValue;
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call ptr @__mir_str_new(ptr ' . $p . ', i64 ' . $n . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `cstr_to_str(\Ffi\Ptr $p): string` — NUL-terminated raw C-string →
     * headered string (the single libc-strlen boundary, in the central core).
     * For OS/FFI char* (argv entries, uname buffer) whose length isn't known.
     * @param Node[] $args
     */
    private function biCstrToStr(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $p = $this->lastValue;
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call ptr @__mir_str_from_cstr(ptr ' . $p . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `STDIN` / `STDOUT` / `STDERR` → libc's own FILE* global (a resource,
     * obj<Ffi\Ptr>). The `manticore_std*` accessor is emitted in the preamble
     * (needsStdStreams) loading the platform symbol.
     */
    private function biStdStream(string $stream): string
    {
        $this->needsStdStreams = true;
        $r = $this->allocSsa();
        $out = '  ' . $r . ' = call ptr @manticore_' . $stream . "()\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `__mir_to_cell($v)` → NaN-box $v to a tagged cell by its static type. Used
     * to store a nested array as a proper array-cell into an erased (`array`)
     * container — the raw store path keeps the boxed i64 intact, so a read-back
     * sees the ARRAY tag (getopt repeats: `$o[$k] = [$o[$k], $v]`).
     * @param Node[] $args
     */
    private function biToCell(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->boxToCell($args[0]->type);
        return $out;
    }

    /** `$argc` source: the captured process argc (preamble cli_argv block). */
    private function biCliArgc(): string
    {
        $this->needsCliArgv = true;
        $r = $this->allocSsa();
        $out = '  ' . $r . " = call i64 @manticore_cli_argc()\n";
        return $this->finishI64($out, $r);
    }

    /**
     * `__mir_argv_at($i)` → the i-th raw libc C-string (no rc header); the
     * caller copies it via cstr_to_str. NULL out of bounds.
     * @param Node[] $args
     */
    private function biCliArgvAt(array $args): string
    {
        $this->needsCliArgv = true;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $i = $this->lastValue;
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = call ptr @manticore_cli_argv(i64 ' . $i . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function biStrlen(array $args): string
    {
        // Binary-safe O(1): the central reader loads len@-16 (null-safe). Every
        // `string` is headered by construction (raw buffers are \Ffi\Ptr and
        // reach a string only via str_from_buffer / cstr_to_str).
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call i64 @__mir_strlen(ptr ' . $a0 . ")\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        return $this->finishI64($out, $reg);
    }

    /** @param Node[] $args  count/sizeof — vec length at byte offset 0. */
    private function biCount(array $args): string
    {
        // `count($obj)` on a Countable object → `$obj->count()`.
        if ($args[0]->type->kind === Type::KIND_OBJ
            && $this->classImplements($args[0]->type->class ?? '', 'Countable')) {
            $mc = new \Compile\Mir\MethodCall_($args[0], 'count', [], Type::int_());
            return $this->emitMethodCall($mc);
        }
        $out = $this->emitNode($args[0]);
        if ($args[0]->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        // An empty `[]` vec/assoc literal lowers to a null ptr; redirect a
        // null base to the zero-word so the length load reads 0 rather than
        // dereferencing address 0.
        $ptr = $this->lastValue;
        $isNull = $this->allocSsa();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $ptr . ", null\n";
        $safe = $this->allocSsa();
        $out .= '  ' . $safe . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $ptr . "\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = load i64, ptr ' . $safe . "\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * @param Node[] $args
     *
     * `array_keys($a)` — a fresh PACKED list of the source's keys, each
     * NaN-boxed via `__mir_array_key_cell_at` so the result is uniform for
     * BOTH a plain array (int indices → boxed int) and a cell/`mixed` array
     * (int-or-string keys → boxed int/ptr). This sidesteps the bare-`array`
     * type-erasure that made the stdlib `array_keys(array $a)` mis-coerce a
     * cell-backed `mixed` argument (the .sig encodes the lowered `unknown`
     * param, so the call site never strips the NaN tag → inttoptr fault).
     * Result type is `vec[cell]` ({@see InferTypes::builtinReturnType}).
     */
    private function biArrayKeys(array $args): string
    {
        $this->needsTagged = true;
        $out = $this->emitNode($args[0]);
        if ($args[0]->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        // An empty `[]` literal lowers to a null ptr; redirect to the
        // zero-word so the length load reads 0 (mirrors biCount).
        $rawSrc = $this->lastValue;
        $isNull = $this->allocSsa();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->allocSsa();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        $len = $this->allocSsa();
        $out .= '  ' . $len . ' = load i64, ptr ' . $src . "\n";
        $slot = $this->allocSsa();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->allocSsa();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $len . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->allocSsa();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->allocLabel('akeys.cond');
        $body = $this->allocLabel('akeys.body');
        $end  = $this->allocLabel('akeys.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->allocSsa();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->allocSsa();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $kb = $this->allocSsa();
        $out .= '  ' . $kb . ' = call i64 @__mir_array_key_cell_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        $cur = $this->allocSsa();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->allocSsa();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $kb . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->allocSsa();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->allocSsa();
        $out .= '  ' . $dst . ' = load ptr, ptr ' . $slot . "\n";
        $this->lastValue = $dst;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** A value kind that NaN-boxes to a single cell (so `array_values` can
     *  uniformly re-box a typed source's elements). Excludes UNKNOWN/NULL/etc. */
    private function isConcreteCellKind(string $k): bool
    {
        return $k === Type::KIND_INT || $k === Type::KIND_STRING
            || $k === Type::KIND_FLOAT || $k === Type::KIND_BOOL
            || $k === Type::KIND_OBJ || $k === Type::KIND_CELL;
    }

    /**
     * @param Node[] $args
     *
     * `array_values($a)` — a fresh PACKED, re-indexed list of the source's
     * values as `vec[cell]`. Two compile-time shapes (see the dispatch):
     *   - CELL/`mixed` source ($boxElem === null): values are ALREADY cells →
     *     copied as-is. Fixes the stdlib mis-coerce on a cell-backed argument
     *     (bare-`array` .sig erasure), the gap that drove the array_keys builtin.
     *   - typed array source ($boxElem = the element type): each raw value is
     *     re-boxed per its kind, so a `vec[int]`/`assoc[string,string]` etc.
     *     var_dumps correctly (the stdlib path returns an unknown-element
     *     `array` that the recursive var_dump can't render → SIGSEGV).
     * An unknown-element source is not routed here (→ stdlib).
     * Result type is `vec[cell]` ({@see InferTypes::builtinReturnType}).
     */
    private function biArrayValues(array $args, ?Type $boxElem): string
    {
        $this->needsTagged = true;
        $out = $this->emitNode($args[0]);
        if ($args[0]->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        // An empty `[]` literal lowers to a null ptr; redirect to the
        // zero-word so the length load reads 0 (mirrors biArrayKeys).
        $rawSrc = $this->lastValue;
        $isNull = $this->allocSsa();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->allocSsa();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        $len = $this->allocSsa();
        $out .= '  ' . $len . ' = load i64, ptr ' . $src . "\n";
        $slot = $this->allocSsa();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->allocSsa();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $len . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->allocSsa();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->allocLabel('avals.cond');
        $body = $this->allocLabel('avals.body');
        $end  = $this->allocLabel('avals.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->allocSsa();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->allocSsa();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $ev = $this->allocSsa();
        $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        // A cell source ($boxElem null) and a CELL-element typed source already
        // carry cells; a typed source re-boxes each raw value per its kind.
        $bv = $ev;
        if ($boxElem !== null && $boxElem->kind !== Type::KIND_CELL) {
            $bv = $this->allocSsa();
            $ek = $boxElem->kind;
            if ($ek === Type::KIND_STRING) {
                $ep = $this->allocSsa();
                $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_ptr(ptr ' . $ep . ")\n";
            } elseif ($ek === Type::KIND_FLOAT) {
                $ed = $this->allocSsa();
                $out .= '  ' . $ed . ' = bitcast i64 ' . $ev . " to double\n";
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_float(double ' . $ed . ")\n";
            } elseif ($ek === Type::KIND_BOOL) {
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_bool(i64 ' . $ev . ")\n";
            } elseif ($ek === Type::KIND_OBJ) {
                $ep = $this->allocSsa();
                $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_object(ptr ' . $ep . ")\n";
            } else {
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_int(i64 ' . $ev . ")\n";
            }
        }
        $cur = $this->allocSsa();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->allocSsa();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $bv . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->allocSsa();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->allocSsa();
        $out .= '  ' . $dst . ' = load ptr, ptr ' . $slot . "\n";
        $this->lastValue = $dst;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args */
    private function biOrd(array $args): string
    {
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $byte = $this->allocSsa();
        $out .= '  ' . $byte . ' = load i8, ptr ' . $a0 . "\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = zext i8 ' . $byte . " to i64\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        return $this->finishI64($out, $reg);
    }

    /** @param Node[] $args */
    private function biChr(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $t = $this->allocSsa();
        $out .= '  ' . $t . ' = trunc i64 ' . $this->lastValue . " to i8\n";
        $buf = $this->allocSsa();
        $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 2)\n";
        $out .= '  store i8 ' . $t . ', ptr ' . $buf . "\n";
        $nul = $this->allocSsa();
        $out .= '  ' . $nul . ' = getelementptr inbounds i8, ptr ' . $buf . ", i64 1\n";
        $out .= '  store i8 0, ptr ' . $nul . "\n";
        $this->lastValue = $buf; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args */
    private function biAbs(array $args): string
    {
        $isFloat = $args[0]->type->kind === Type::KIND_FLOAT;
        $out = $this->emitNode($args[0]);
        if ($isFloat) {
            $this->libcExtra['llvm.fabs.f64'] = 'declare double @llvm.fabs.f64(double)';
            $out .= $this->coerceTo('double');
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call double @llvm.fabs.f64(double ' . $this->lastValue . ")\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        $out .= $this->coerceToI64();
        $v = $this->lastValue;
        $neg = $this->allocSsa();
        $out .= '  ' . $neg . ' = sub i64 0, ' . $v . "\n";
        $isNeg = $this->allocSsa();
        $out .= '  ' . $isNeg . ' = icmp slt i64 ' . $v . ", 0\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = select i1 ' . $isNeg . ', i64 ' . $neg . ', i64 ' . $v . "\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `intdiv($a, $b)` → integer division truncating toward zero (LLVM `sdiv`
     * matches PHP's truncation). Both operands coerce to i64.
     *
     * @param Node[] $args
     */
    private function biIntdiv(array $args): ?string
    {
        if (\count($args) !== 2) { return null; }
        $out = $this->emitNode($args[0]); $out .= $this->coerceToI64(); $a = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceToI64(); $b = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = sdiv i64 ' . $a . ', ' . $b . "\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `pow($a, $b)` / `$a ** $b`. Both args int → integer power
     * (`__mir_ipow`, matching PHP's int result for a non-negative int
     * exponent; a negative int exponent yields 0 here vs PHP's float — a
     * documented edge). Otherwise → `llvm.pow.f64` on doubles.
     *
     * @param Node[] $args
     */
    private function biPow(array $args): ?string
    {
        if (\count($args) !== 2) { return null; }
        $bothInt = $args[0]->type->kind === Type::KIND_INT
            && $args[1]->type->kind === Type::KIND_INT;
        if ($bothInt) {
            $this->needsIpow = true;
            $out = $this->emitNode($args[0]); $out .= $this->coerceToI64(); $b = $this->lastValue;
            $out .= $this->emitNode($args[1]); $out .= $this->coerceToI64(); $e = $this->lastValue;
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call i64 @__mir_ipow(i64 ' . $b . ', i64 ' . $e . ")\n";
            return $this->finishI64($out, $reg);
        }
        $this->libcExtra['llvm.pow.f64'] = 'declare double @llvm.pow.f64(double, double)';
        $out = $this->emitNode($args[0]); $out .= $this->coerceTo('double'); $b = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceTo('double'); $e = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call double @llvm.pow.f64(double ' . $b . ', double ' . $e . ")\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** A unary float→float math builtin via an LLVM intrinsic (floor / ceil /
     *  sqrt). No libm link — clang lowers the intrinsic.
     *  @param Node[] $args */
    private function biFloatUnary(array $args, string $intrinsic): string
    {
        $this->libcExtra[$intrinsic] = 'declare double @' . $intrinsic . '(double)';
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceTo('double');
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call double @' . $intrinsic . '(double ' . $this->lastValue . ")\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /**
     * `round($x [, $precision])`. `llvm.round.f64` is round-half-away-from-zero,
     * matching PHP `round()` exactly at precision 0. Non-zero precision scales
     * by 10^p, rounds, and unscales (best-effort; the FP-edge correction PHP
     * applies is not replicated).
     * @param Node[] $args
     */
    private function biRound(array $args): string
    {
        $this->libcExtra['llvm.round.f64'] = 'declare double @llvm.round.f64(double)';
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceTo('double');
        $x = $this->lastValue;
        if (\count($args) < 2) {
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call double @llvm.round.f64(double ' . $x . ")\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        $this->libcExtra['llvm.pow.f64'] = 'declare double @llvm.pow.f64(double, double)';
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceTo('double');
        $p = $this->lastValue;
        $scale = $this->allocSsa();
        $out .= '  ' . $scale . ' = call double @llvm.pow.f64(double 1.000000e+01, double ' . $p . ")\n";
        $scaled = $this->allocSsa();
        $out .= '  ' . $scaled . ' = fmul double ' . $x . ', ' . $scale . "\n";
        // Pre-round the scaled value at 15 significant digits (snprintf+strtod)
        // to cancel the binary representation error before the final round —
        // PHP's php_round pre-rounding, so round(1.005, 2) → 1.01 not 1.
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $this->libcExtra['strtod'] = 'declare double @strtod(ptr, ptr)';
        $pbuf = $this->allocSsa();
        $out .= '  ' . $pbuf . " = alloca [40 x i8]\n";
        $out .= '  call i32 (ptr, i64, ptr, ...) @snprintf(ptr ' . $pbuf
              . ', i64 40, ptr @.fmt.p15, double ' . $scaled . ")\n";
        $cleaned = $this->allocSsa();
        $out .= '  ' . $cleaned . ' = call double @strtod(ptr ' . $pbuf . ", ptr null)\n";
        $rounded = $this->allocSsa();
        $out .= '  ' . $rounded . ' = call double @llvm.round.f64(double ' . $cleaned . ")\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = fdiv double ' . $rounded . ', ' . $scale . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `fmod($x, $y)` — float remainder via the LLVM `frem` instruction.
     *  @param Node[] $args */
    private function biFmod(array $args): string
    {
        $out = $this->emitNode($args[0]); $out .= $this->coerceTo('double'); $x = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceTo('double'); $y = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = frem double ' . $x . ', ' . $y . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** Two-arg float function (`atan2`, `hypot`) → `double NAME(double, double)`.
     * @param Node[] $args */
    private function biFloatBinary(array $args, string $fn): string
    {
        $this->libcExtra[$fn] = 'declare double @' . $fn . '(double, double)';
        $out = $this->emitNode($args[0]); $out .= $this->coerceTo('double'); $x = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceTo('double'); $y = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call double @' . $fn . '(double ' . $x . ', double ' . $y . ")\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `log($x)` natural log; `log($x, $base)` = log(x)/log(base). */
    private function biLog(array $args): string
    {
        $this->libcExtra['llvm.log.f64'] = 'declare double @llvm.log.f64(double)';
        $out = $this->emitNode($args[0]); $out .= $this->coerceTo('double'); $x = $this->lastValue;
        $lx = $this->allocSsa();
        $out .= '  ' . $lx . ' = call double @llvm.log.f64(double ' . $x . ")\n";
        if (\count($args) < 2) {
            $this->lastValue = $lx; $this->lastValueType = 'double';
            return $out;
        }
        $out .= $this->emitNode($args[1]); $out .= $this->coerceTo('double'); $b = $this->lastValue;
        $lb = $this->allocSsa();
        $out .= '  ' . $lb . ' = call double @llvm.log.f64(double ' . $b . ")\n";
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = fdiv double ' . $lx . ', ' . $lb . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `pi()` → the M_PI constant (no libm call). */
    private function biPi(): string
    {
        $reg = $this->allocSsa();
        $out = '  ' . $reg . ' = fadd double 0x400921FB54442D18, 0.000000e+00' . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `deg2rad`/`rad2deg` — multiply by a constant (pi/180 or 180/pi).
     * @param Node[] $args */
    private function biFloatScale(array $args, string $hexConst): string
    {
        $out = $this->emitNode($args[0]); $out .= $this->coerceTo('double'); $x = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = fmul double ' . $x . ', ' . $hexConst . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** @param Node[] $args */
    private function biIntval(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $ok = $args[0]->type->kind;
        if ($ok === Type::KIND_FLOAT) {
            $out .= $this->coerceTo('double');
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = fptosi double ' . $this->lastValue . " to i64\n";
            return $this->finishI64($out, $reg);
        }
        if ($ok === Type::KIND_STRING) {
            $this->needsStrtol = true;
            $out .= $this->coerceToPtr();
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call i64 @strtol(ptr ' . $this->lastValue . ', ptr null, i32 10)' . "\n";
            return $this->finishI64($out, $reg);
        }
        $out .= $this->coerceToI64();
        return $this->finishI64($out, $this->lastValue);
    }

    /** @param Node[] $args */
    private function biFloatval(array $args): string
    {
        $out = $this->emitNode($args[0]);
        if ($args[0]->type->kind === Type::KIND_FLOAT) {
            $out .= $this->coerceTo('double');
            $this->lastValueType = 'double';
            return $out;
        }
        $out .= $this->coerceToI64();
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = sitofp i64 ' . $this->lastValue . " to double\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /**
     * @param Node[] $args
     * Statically resolvable: the arg's MIR type tells `null` apart from
     * int 0 (both i64 0 in the carrier at runtime).
     */
    private function biIsNull(array $args): string
    {
        $this->lastValue = $args[0]->type->kind === Type::KIND_NULL ? '1' : '0';
        $this->lastValueType = 'i64';
        return '';
    }

    /** Data-bytes ptr for an interned string literal (header is 8B). */
    private function strRef(string $s): string
    {
        return $this->strLitId($this->internString($s));
    }

    /**
     * Constant-expr pointer to the data bytes of literal `@.str.<id>`.
     * Every string literal global carries the full 24-byte string header
     * `[cap, len, rc(-1 immortal)]`; the value everything sees is
     * `global + 24`. Opaque-pointer gep so no array length is needed at the
     * use site.
     */
    private function strLitId(int $id): string
    {
        return $this->strSymBytes('@.str.' . (string)$id);
    }

    /** `global + 24` bytes ptr for any headered string-literal symbol. */
    private function strSymBytes(string $sym): string
    {
        return 'getelementptr inbounds (i8, ptr ' . $sym . ', i64 24)';
    }

    /**
     * Definition of a headered string-literal global:
     * `{ i64 cap, i64 len, i64 rc, [L x i8] }` with rc `-1` (immortal —
     * retain/release skip it) and a binary-safe `len` (the content length,
     * read by strlen() / compare). Data bytes start at offset 24, reached via
     * {@see strSymBytes}.
     */
    private function strGlobalDef(string $sym, string $value): string
    {
        $bytes = $this->llvmStringBytes($value);
        $content = \strlen($value);
        $len = $content + 1; // bytes incl. NUL
        return $sym . ' = private unnamed_addr constant { i64, i64, i64, ['
            . (string)$len . ' x i8] } { i64 ' . (string)$content . ', i64 '
            . (string)$content . ', i64 -1, [' . (string)$len . ' x i8] c"'
            . $bytes . '\\00" }, align 8' . "\n";
    }

    /**
     * is_int / is_string / … — runtime tag compare on a tagged cell
     * arg, else a compile-time constant from the static type.
     * @param Node[] $args
     */
    private function biIsType(array $args, int $wantTag, string $kind): string
    {
        $a = $args[0];
        if ($a->type->kind === Type::KIND_CELL) {
            $this->needsTagged = true;
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $tg = $this->allocSsa();
            $out .= '  ' . $tg . ' = call i64 @__manticore_tag(i64 ' . $this->lastValue . ")\n";
            $eq = $this->allocSsa();
            $out .= '  ' . $eq . ' = icmp eq i64 ' . $tg . ', ' . (string)$wantTag . "\n";
            $z = $this->allocSsa();
            $out .= '  ' . $z . ' = zext i1 ' . $eq . " to i64\n";
            return $this->finishI64($out, $z);
        }
        $this->lastValue = ($a->type->kind === $kind) ? '1' : '0';
        $this->lastValueType = 'i64';
        return '';
    }

    /**
     * gettype / get_debug_type. Tagged cell → runtime tag→name select
     * chain; otherwise the name folds from the static type.
     * @param Node[] $args
     */
    private function biGettype(array $args, bool $debug): string
    {
        $a = $args[0];
        $nInt   = $debug ? 'int'    : 'integer';
        $nBool  = $debug ? 'bool'   : 'boolean';
        $nNull  = $debug ? 'null'   : 'NULL';
        $nFloat = $debug ? 'float'  : 'double';
        $nStr   = 'string';
        $nArr   = 'array';
        $nObj   = 'object';
        $nUnk   = $debug ? 'unknown' : 'unknown type';
        if ($a->type->kind === Type::KIND_CELL) {
            $this->needsTagged = true;
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $tg = $this->allocSsa();
            $out .= '  ' . $tg . ' = call i64 @__manticore_tag(i64 ' . $this->lastValue . ")\n";
            $e1 = $this->allocSsa(); $out .= '  ' . $e1 . ' = icmp eq i64 ' . $tg . ", 1\n";
            $e2 = $this->allocSsa(); $out .= '  ' . $e2 . ' = icmp eq i64 ' . $tg . ", 2\n";
            $e3 = $this->allocSsa(); $out .= '  ' . $e3 . ' = icmp eq i64 ' . $tg . ", 3\n";
            $e4 = $this->allocSsa(); $out .= '  ' . $e4 . ' = icmp eq i64 ' . $tg . ", 4\n";
            $e6 = $this->allocSsa(); $out .= '  ' . $e6 . ' = icmp eq i64 ' . $tg . ", 6\n";
            $e7 = $this->allocSsa(); $out .= '  ' . $e7 . ' = icmp eq i64 ' . $tg . ", 7\n";
            $e8 = $this->allocSsa(); $out .= '  ' . $e8 . ' = icmp eq i64 ' . $tg . ", 8\n";
            $r8 = $this->allocSsa(); $out .= '  ' . $r8 . ' = select i1 ' . $e8 . ', ptr ' . $this->strRef($nObj) . ', ptr ' . $this->strRef($nUnk) . "\n";
            $r7 = $this->allocSsa(); $out .= '  ' . $r7 . ' = select i1 ' . $e7 . ', ptr ' . $this->strRef($nArr) . ', ptr ' . $r8 . "\n";
            $r6 = $this->allocSsa(); $out .= '  ' . $r6 . ' = select i1 ' . $e6 . ', ptr ' . $this->strRef($nFloat) . ', ptr ' . $r7 . "\n";
            $r4 = $this->allocSsa(); $out .= '  ' . $r4 . ' = select i1 ' . $e4 . ', ptr ' . $this->strRef($nStr) . ', ptr ' . $r6 . "\n";
            $r3 = $this->allocSsa(); $out .= '  ' . $r3 . ' = select i1 ' . $e3 . ', ptr ' . $this->strRef($nNull) . ', ptr ' . $r4 . "\n";
            $r2 = $this->allocSsa(); $out .= '  ' . $r2 . ' = select i1 ' . $e2 . ', ptr ' . $this->strRef($nBool) . ', ptr ' . $r3 . "\n";
            $r1 = $this->allocSsa(); $out .= '  ' . $r1 . ' = select i1 ' . $e1 . ', ptr ' . $this->strRef($nInt) . ', ptr ' . $r2 . "\n";
            $this->lastValue = $r1;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $k = $a->type->kind;
        $name = $nUnk;
        if ($k === Type::KIND_INT) { $name = $nInt; }
        elseif ($k === Type::KIND_STRING) { $name = $nStr; }
        elseif ($k === Type::KIND_FLOAT) { $name = $nFloat; }
        elseif ($k === Type::KIND_BOOL) { $name = $nBool; }
        elseif ($k === Type::KIND_NULL) { $name = $nNull; }
        elseif ($k === Type::KIND_ARRAY) { $name = $nArr; }
        // get_debug_type names an object by its class; gettype → "object".
        elseif ($k === Type::KIND_OBJ) { $name = $debug && ($a->type->class ?? '') !== '' ? $a->type->class : $nObj; }
        elseif ($k === Type::KIND_CLOSURE) { $name = $debug ? 'Closure' : $nObj; }
        $this->lastValue = $this->strRef($name);
        $this->lastValueType = 'ptr';
        return '';
    }

    /** @param Node[] $args  min ($pred slt) / max ($pred sgt), n-ary. */
    private function biMinMax(array $args, string $pred): string
    {
        // A float operand needs a numeric compare that preserves the winner's
        // own type (PHP: max(1, 2.5) === 2.5, max(3, 1.5) === 3). Box each
        // operand, compare as doubles, select the winning boxed cell — the
        // result is a numericCell ({@see builtinReturnType}). All-int / all-cell
        // args keep the unchanged integer-compare path.
        $anyFloat = false;
        foreach ($args as $a) {
            if ($a->type->kind === Type::KIND_FLOAT) { $anyFloat = true; break; }
        }
        if ($anyFloat) {
            $this->needsTagged = true;
            $this->needsTaggedToFloat = true;
            $fpred = $pred === 'sgt' ? 'ogt' : 'olt';
            $out = $this->emitNode($args[0]);
            $out .= $this->boxToCell($args[0]->type);
            $acc = $this->lastValue;
            $accd = $this->allocSsa();
            $out .= '  ' . $accd . ' = call double @__manticore_tagged_to_double(i64 ' . $acc . ")\n";
            $count = \count($args);
            for ($i = 1; $i < $count; $i = $i + 1) {
                $out .= $this->emitNode($args[$i]);
                $out .= $this->boxToCell($args[$i]->type);
                $v = $this->lastValue;
                $vd = $this->allocSsa();
                $out .= '  ' . $vd . ' = call double @__manticore_tagged_to_double(i64 ' . $v . ")\n";
                $cmp = $this->allocSsa();
                $out .= '  ' . $cmp . ' = fcmp ' . $fpred . ' double ' . $vd . ', ' . $accd . "\n";
                $sel = $this->allocSsa();
                $out .= '  ' . $sel . ' = select i1 ' . $cmp . ', i64 ' . $v . ', i64 ' . $acc . "\n";
                $seld = $this->allocSsa();
                $out .= '  ' . $seld . ' = select i1 ' . $cmp . ', double ' . $vd . ', double ' . $accd . "\n";
                $acc = $sel;
                $accd = $seld;
            }
            $this->lastValue = $acc;
            $this->lastValueType = 'i64';
            return $out;
        }
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $acc = $this->lastValue;
        $count = \count($args);
        for ($i = 1; $i < $count; $i = $i + 1) {
            $out .= $this->emitNode($args[$i]);
            $out .= $this->coerceToI64();
            $v = $this->lastValue;
            $cmp = $this->allocSsa();
            $out .= '  ' . $cmp . ' = icmp ' . $pred . ' i64 ' . $v . ', ' . $acc . "\n";
            $sel = $this->allocSsa();
            $out .= '  ' . $sel . ' = select i1 ' . $cmp . ', i64 ' . $v . ', i64 ' . $acc . "\n";
            $acc = $sel;
        }
        return $this->finishI64($out, $acc);
    }

    /** @param Node[] $args */
    private function biDechex(array $args): string
    {
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $v = $this->lastValue;
        $buf = $this->allocSsa();
        $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 17)\n";
        $tmp = $this->allocSsa();
        $out .= '  ' . $tmp . ' = call i32 (ptr, i64, ptr, ...) @snprintf(ptr ' . $buf . ', i64 17, ptr @.fmt.x, i64 ' . $v . ")\n";
        $tl = $this->allocSsa();
        $out .= '  ' . $tl . ' = sext i32 ' . $tmp . " to i64\n";
        $out .= '  call void @__mir_str_set_len(ptr ' . $buf . ', i64 ' . $tl . ")\n";
        $this->lastValue = $buf; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  substr($s, $start[, $len]). */
    private function biSubstr(array $args): string
    {
        $this->needsSubstr = true;
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $s = $this->lastValue;
        $out .= $this->emitIntArg($args[1]);
        $start = $this->lastValue;
        // start / length normalization (negative offsets, clamping) lives in
        // the runtime @__mir_substr to match Zend exactly. `haveLen` = 0 means
        // "length omitted → to end of string".
        if (\count($args) >= 3) {
            $out .= $this->emitIntArg($args[2]);
            $len = $this->lastValue;
            $haveLen = '1';
        } else {
            $len = '0';
            $haveLen = '0';
        }
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_substr(ptr ' . $s . ', i64 ' . $start . ', i64 ' . $len . ', i64 ' . $haveLen . ")\n";
        $out .= $this->freeStrTemp($args[0], $s);
        $this->lastValue = $reg; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args */
    private function biStrRepeat(array $args): string
    {
        $this->needsStrRepeat = true;
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $s = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToI64();
        $n = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_repeat(ptr ' . $s . ', i64 ' . $n . ")\n";
        $out .= $this->freeStrTemp($args[0], $s);
        $this->lastValue = $reg; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  strtolower / strtoupper via $fn helper. */
    private function biCaseConv(array $args, string $fn): string
    {
        if ($fn === '__mir_strtolower') { $this->needsStrtolower = true; }
        else { $this->needsStrtoupper = true; }
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @' . $fn . '(ptr ' . $a0 . ")\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        $this->lastValue = $reg; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args */
    private function biStrpos(array $args): string
    {
        $this->needsStrpos = true;
        $this->libcExtra['strstr'] = 'declare ptr @strstr(ptr, ptr)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $h = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToPtr();
        $n = $this->lastValue;
        // Optional 3rd arg: byte offset to start searching from (default 0).
        $off = '0';
        if (\count($args) >= 3) {
            $out .= $this->emitNode($args[2]);
            $out .= $this->coerceToI64();
            $off = $this->lastValue;
        }
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call i64 @__mir_strpos(ptr ' . $h . ', ptr ' . $n
              . ', i64 ' . $off . ")\n";
        $out .= $this->freeStrTemp($args[0], $h);
        $out .= $this->freeStrTemp($args[1], $n);
        return $this->finishI64($out, $reg);
    }

    /** @param Node[] $args  print_r($v [, $return]) — echo form only. DEEP-boxes
     *  the value (a nested array's elements become tagged cells, so the recursive
     *  __mir_print_r reads real cells, not raw pointers) then calls the prelude
     *  backend. The `$return` arg is ignored; the echo form yields true (1). */
    private function biPrintR(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->boxToCell($args[0]->type);
        $bv = $this->lastValue;
        $out .= '  call i64 @manticore___mir_print_r(i64 ' . $bv . ', i64 0)' . "\n";
        $this->lastValue = '1';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args  explode($delim, $subject [, $limit]) → vec[string].
     *  A codegen builtin (single-scan __mir_str_explode) — replaces the prelude
     *  PHP explode's per-segment strpos-cell + substr-malloc + append overhead. */
    private function biExplode(array $args): string
    {
        $this->needsStrExplode = true;
        $this->libcExtra['strstr'] = 'declare ptr @strstr(ptr, ptr)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $out = $this->emitPtrArg($args[0]);
        $delim = $this->lastValue;
        $out .= $this->emitPtrArg($args[1]);
        $subj = $this->lastValue;
        if (\count($args) >= 3) {
            $out .= $this->emitNode($args[2]);
            $out .= $this->coerceToI64();
            $limit = $this->lastValue;
        } else {
            $limit = '9223372036854775807';
        }
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_explode(ptr ' . $delim
              . ', ptr ' . $subj . ', i64 ' . $limit . ")\n";
        $out .= $this->freeStrTemp($args[0], $delim);
        $out .= $this->freeStrTemp($args[1], $subj);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  implode($sep, $vec) / join. */
    private function biImplode(array $args): string
    {
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $sep = $this->lastValue;
        // A non-string element vec (int/float/mixed) is boxed into a cell-array
        // and joined via tagged_to_str per element — the raw C implode would
        // inttoptr a non-pointer value and fault. A known string vec keeps the
        // fast path.
        $elem = $args[1]->type->element ?? null;
        $useCell = $elem === null || $elem->kind !== Type::KIND_STRING;
        $out .= $this->emitNode($args[1]);
        if ($useCell) {
            $out .= $this->boxToCell($args[1]->type);
            $out .= $this->cellToPtr();
            $vec = $this->lastValue;
            $this->needsTaggedToStr = true;
            $this->needsImplodeCell = true;
            $reg = $this->allocSsa();
            $out .= '  ' . $reg . ' = call ptr @__mir_array_implode_cell(ptr ' . $sep . ', ptr ' . $vec . ")\n";
            $this->lastValue = $reg; $this->lastValueType = 'ptr';
            return $out;
        }
        $out .= $this->coerceToPtr();
        $vec = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_array_implode(ptr ' . $sep . ', ptr ' . $vec . ")\n";
        $this->lastValue = $reg; $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `var_dump($a, $b, …)` — dump each arg by its STATIC type. A typed float
     * goes straight to the shortest round-trip (`__mir_float_shortest`, PHP
     * serialize_precision=-1) instead of through the cell box, which truncates
     * the mantissa to 48 bits (the NaN-box is lossy for doubles). Every other
     * type boxes and recurses through the tagged `__mir_var_dump`.
     *
     * @param Node[] $args
     */
    private function biVarDump(array $args): string
    {
        $this->libcExtra['printf'] = 'declare i32 @printf(ptr, ...)';
        $out = '';
        foreach ($args as $a) {
            if ($a->type->kind === Type::KIND_FLOAT) {
                // Declared here (body-emission) so they precede the header's
                // declare block; setting them inside floatShortestImpl (runtime
                // block) would be too late.
                $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
                $this->libcExtra['strtod'] = 'declare double @strtod(ptr, ptr)';
                $this->needsFloatShortest = true;
                $out .= $this->emitNode($a);
                $out .= $this->coerceTo('double');
                $d = $this->lastValue;
                $fs = $this->allocSsa();
                $out .= '  ' . $fs . ' = call ptr @__mir_float_shortest(double ' . $d . ")\n";
                $out .= '  call i32 (ptr, ...) @printf(ptr @.fmt.vdfloat, ptr ' . $fs . ")\n";
            } else {
                $out .= $this->emitNode($a);
                $out .= $this->boxToCell($a->type);
                $bv = $this->lastValue;
                $out .= '  call i64 @manticore___mir_var_dump(i64 ' . $bv . ', i64 0)' . "\n";
            }
        }
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `__mir_float_repr($v)` — internal: the SHORTEST decimal that strtod
     * re-parses to the same double (PHP serialize_precision -1), used by the
     * recursive var_dump prelude for a float value. A plain `(string)$v` cast is
     * PHP precision-14 ("0.3"), which differs from var_dump's shortest
     * ("0.30000000000000004"). A cell arg is unboxed by tag to a double; a typed
     * float coerces directly. Returns a fresh string ptr.
     * @param Node[] $args
     */
    private function biFloatRepr(array $args): string
    {
        // Declared here (body-emission) so they precede the header declare block.
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $this->libcExtra['strtod'] = 'declare double @strtod(ptr, ptr)';
        $this->needsFloatShortest = true;
        $a = $args[0];
        $out = $this->emitNode($a);
        if ($a->type->kind === Type::KIND_CELL) {
            $this->needsTaggedToFloat = true;
            $d = $this->allocSsa();
            $out .= '  ' . $d . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $d; $this->lastValueType = 'double';
        } else {
            $out .= $this->coerceTo('double');
            $d = $this->lastValue;
        }
        $fs = $this->allocSsa();
        $out .= '  ' . $fs . ' = call ptr @__mir_float_shortest(double ' . $this->lastValue . ")\n";
        $this->lastValue = $fs;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * @param Node[] $args  sprintf / printf. The format must be a
     * string literal (translated PHP→C at compile time: %d→%lld,
     * %x→%llx, %s/%f kept). $toStdout=true → printf (returns length);
     * else snprintf into a 256-byte buffer (returns the string).
     */
    private function biSprintf(array $args, bool $toStdout): ?string
    {
        if ($args[0]->kind !== Node::KIND_STRING_CONST) { return null; }
        $fmt = $this->castStringConst($args[0])->value;
        // Translate specifiers + record per-arg conversion kind.
        $trans = '';
        $convs = [];
        $n = \strlen($fmt);
        $i = 0;
        while ($i < $n) {
            $ch = \substr($fmt, $i, 1);
            if ($ch !== '%') { $trans .= $ch; $i = $i + 1; continue; }
            $j = $i + 1;
            if (\substr($fmt, $j, 1) === '%') { $trans .= '%%'; $i = $j + 1; continue; }
            // Parse a full spec: %[flags][width][.precision]conv. The previous
            // single-char read dropped the arg on %.3f / %05d / %-8s etc.
            $prefix = '';
            while ($j < $n) {
                $c = \substr($fmt, $j, 1);
                if ($c === '-' || $c === '+' || $c === '0' || $c === '#') {
                    $prefix .= $c; $j = $j + 1;
                } elseif ($c === ' ') {
                    // PHP's space flag is space-PADDING (the default), not C's
                    // sign-space — drop it so `% d` matches PHP (`5`, not ` 5`).
                    $j = $j + 1;
                } else { break; }
            }
            while ($j < $n) {
                $o = \ord(\substr($fmt, $j, 1));
                if ($o >= 48 && $o <= 57) { $prefix .= \substr($fmt, $j, 1); $j = $j + 1; }
                else { break; }
            }
            if ($j < $n && \substr($fmt, $j, 1) === '.') {
                $prefix .= '.'; $j = $j + 1;
                while ($j < $n) {
                    $o = \ord(\substr($fmt, $j, 1));
                    if ($o >= 48 && $o <= 57) { $prefix .= \substr($fmt, $j, 1); $j = $j + 1; }
                    else { break; }
                }
            }
            if ($j >= $n) { $trans .= '%' . $prefix; $i = $j; continue; }
            $conv = \substr($fmt, $j, 1);
            $j = $j + 1;
            // i64 args carry the `ll` length modifier; doubles pass as-is.
            if ($conv === 'd' || $conv === 'i') { $trans .= '%' . $prefix . 'lld'; $convs[] = 'd'; }
            elseif ($conv === 'u') { $trans .= '%' . $prefix . 'llu'; $convs[] = 'd'; }
            elseif ($conv === 'x') { $trans .= '%' . $prefix . 'llx'; $convs[] = 'd'; }
            elseif ($conv === 'X') { $trans .= '%' . $prefix . 'llX'; $convs[] = 'd'; }
            elseif ($conv === 'o') { $trans .= '%' . $prefix . 'llo'; $convs[] = 'd'; }
            elseif ($conv === 'c') { $trans .= '%' . $prefix . 'c'; $convs[] = 'd'; }
            elseif ($conv === 's') { $trans .= '%' . $prefix . 's'; $convs[] = 's'; }
            elseif ($conv === 'f' || $conv === 'F' || $conv === 'e' || $conv === 'E'
                || $conv === 'g' || $conv === 'G') {
                $trans .= '%' . $prefix . $conv; $convs[] = 'f';
            } else {
                // Unknown conversion (e.g. PHP %b binary) — pass through, no arg.
                $trans .= '%' . $prefix . $conv;
            }
            $i = $j;
        }
        $fmtId = $this->internString($trans);
        $fmtPtr = $this->strLitId($fmtId);
        // Evaluate + coerce each consumed arg in order.
        $out = '';
        $vararg = '';
        $argN = \count($convs);
        for ($a = 0; $a < $argN; $a = $a + 1) {
            $argNode = $args[$a + 1];
            $out .= $this->emitNode($argNode);
            $conv = $convs[$a];
            if ($conv === 's') {
                // A `mixed`/cell `%s` arg → stringify by tag (int→"9", a string
                // cell→its bytes); a plain value coerces to a ptr directly.
                if ($argNode->type->kind === Type::KIND_CELL) {
                    $this->needsTaggedToStr = true;
                    $s = $this->allocSsa();
                    $out .= '  ' . $s . ' = call ptr @__manticore_tagged_to_str(i64 ' . $this->lastValue . ")\n";
                    $this->lastValue = $s; $this->lastValueType = 'ptr';
                } else {
                    $out .= $this->coerceToPtr();
                }
                $vararg .= ', ptr ' . $this->lastValue;
            }
            elseif ($conv === 'f') {
                // A `mixed`/cell `%f`/`%g`/`%e` arg → unbox to double BY TAG
                // (tagged_to_double); coerceTo('double') would sitofp the tagged
                // i64 bits (a NaN-boxed double read as an integer → garbage).
                if ($argNode->type->kind === Type::KIND_CELL) {
                    $this->needsTaggedToFloat = true;
                    $d = $this->allocSsa();
                    $out .= '  ' . $d . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
                    $this->lastValue = $d; $this->lastValueType = 'double';
                } else {
                    $out .= $this->coerceTo('double');
                }
                $vararg .= ', double ' . $this->lastValue;
            }
            else {
                // A `mixed`/cell `%d`/`%x`/… arg → unbox to i64 by tag (a boxed
                // int's payload, a float truncated) rather than passing the
                // tagged carrier bits straight to printf.
                if ($argNode->type->kind === Type::KIND_CELL) {
                    $this->needsTaggedToInt = true;
                    $this->needsStrtol = true;
                    $iv = $this->allocSsa();
                    $out .= '  ' . $iv . ' = call i64 @__manticore_tagged_to_int(i64 ' . $this->lastValue . ")\n";
                    $this->lastValue = $iv; $this->lastValueType = 'i64';
                } else {
                    $out .= $this->coerceToI64();
                }
                $vararg .= ', i64 ' . $this->lastValue;
            }
        }
        if ($toStdout) {
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call i32 (ptr, ...) @printf(ptr ' . $fmtPtr . $vararg . ")\n";
            $r2 = $this->allocSsa();
            $out .= '  ' . $r2 . ' = sext i32 ' . $r . " to i64\n";
            $this->lastValue = $r2; $this->lastValueType = 'i64';
            return $out;
        }
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $buf = $this->allocSsa();
        $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 256)\n";
        $tmp = $this->allocSsa();
        $out .= '  ' . $tmp . ' = call i32 (ptr, i64, ptr, ...) @snprintf(ptr ' . $buf . ', i64 256, ptr ' . $fmtPtr . $vararg . ")\n";
        $tl = $this->allocSsa();
        $out .= '  ' . $tl . ' = sext i32 ' . $tmp . " to i64\n";
        $ov = $this->allocSsa();
        $out .= '  ' . $ov . ' = icmp sgt i64 ' . $tl . ", 255\n";
        $cl = $this->allocSsa();
        $out .= '  ' . $cl . ' = select i1 ' . $ov . ', i64 255, i64 ' . $tl . "\n";
        $out .= '  call void @__mir_str_set_len(ptr ' . $buf . ', i64 ' . $cl . ")\n";
        $this->lastValue = $buf; $this->lastValueType = 'ptr';
        return $out;
    }

    /** `gc_collect_cycles()` → run the Bacon-Rajan collector, return freed count. */
    private function biGcCollect(): string
    {
        $this->needsCc = true;
        $this->needsRc = true;
        $reg = $this->allocSsa();
        $out = '  ' . $reg . " = call i64 @__manticore_cc_collect_cycles()\n";
        return $this->finishI64($out, $reg);
    }

    /** Set the i64 result + return the accumulated IR. */
    private function finishI64(string $out, string $reg): string
    {
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args  exit/die — libc exit(code); 0 when no arg. */
    private function biExit(array $args): string
    {
        $this->libcExtra['exit'] = 'declare void @exit(i32) noreturn';
        $code = '0';
        $out = '';
        if (\count($args) >= 1) {
            $out .= $this->emitNode($args[0]);
            // A string arg (`exit("msg")`) prints then exits 0; the
            // compiler only ever passes an int status, so coerce to i64.
            if ($args[0]->type->kind === Type::KIND_INT
                || $args[0]->type->kind === Type::KIND_BOOL) {
                $out .= $this->coerceToI64();
                $code = $this->allocSsa();
                $out .= '  ' . $code . ' = trunc i64 ' . $this->lastValue . " to i32\n";
            }
        }
        $out .= '  call void @exit(i32 ' . $code . ")\n";
        // exit is noreturn; leave a dummy value for any dead fall-through.
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args  error_log($msg) — write "$msg\n" to stderr (fd 2). */
    private function biErrorLog(array $args): string
    {
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $this->libcExtra['write']  = 'declare i64 @write(i32, ptr, i64)';
        $out = $this->emitPtrArg($args[0]);
        $msg = $this->lastValue;
        $len = $this->allocSsa();
        $out .= '  ' . $len . ' = call i64 @strlen(ptr ' . $msg . ")\n";
        $w = $this->allocSsa();
        $out .= '  ' . $w . ' = call i64 @write(i32 2, ptr ' . $msg . ', i64 ' . $len . ")\n";
        $nl = $this->allocSsa();
        $out .= '  ' . $nl . " = alloca i8\n";
        $out .= '  store i8 10, ptr ' . $nl . "\n";
        $w2 = $this->allocSsa();
        $out .= '  ' . $w2 . ' = call i64 @write(i32 2, ptr ' . $nl . ", i64 1)\n";
        $out .= $this->freeStrTemp($args[0], $msg);
        // PHP error_log returns true.
        $this->lastValue = '1';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args  spl_object_id($o) — the object pointer as a
     * stable per-object int (unique among live objects). */
    private function biSplObjectId(array $args): string
    {
        $out = $this->emitPtrArg($args[0]);
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
        return $this->finishI64($out, $reg);
    }

    /** @param Node[] $args  get_class($o) — the operand's class name. Uses
     * the static type (matches `::class`); the compiler's lone call site
     * has a precisely-typed receiver. */
    private function biGetClass(array $args): string
    {
        $cls = $args[0]->type->class ?? '';
        $cands = ($cls !== '' && isset($this->classes[$cls]))
            ? $this->selfAndDescendants($cls) : [];
        // Unknown class, or a monomorphic one (no subclass) — the static type
        // is exact, so emit the name literal directly.
        if (\count($cands) <= 1) {
            $out = $this->emitNode($args[0]);
            $this->lastValue = $this->strLitId($this->internString($cls));
            $this->lastValueType = 'ptr';
            return $out;
        }
        // Polymorphic receiver: read the runtime class_id from the object
        // header and switch to the actual class name (PHP's get_class is the
        // runtime class, not the static type — matters inside an inherited
        // method on a subclass instance).
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $objp = $this->lastValue;
        $out .= $this->emitLoadClassId($objp);
        $cid = $this->classIdReg;
        $res = $this->allocSsa();
        $out .= '  ' . $res . " = alloca ptr\n";
        $endL = $this->allocLabel('gc.end');
        $defL = $this->allocLabel('gc.def');
        $switch = '  switch i64 ' . $cid . ', label %' . $defL . " [\n";
        $bodies = '';
        foreach ($cands as $c) {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { continue; }
            $caseL = $this->allocLabel('gc.case');
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $caseL . "\n";
            $bodies .= $caseL . ":\n";
            $bodies .= '  store ptr ' . $this->strLitId($this->internString($c)) . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $endL . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $defL . ":\n";
        $out .= '  store ptr ' . $this->strLitId($this->internString($cls)) . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->allocSsa();
        $out .= '  ' . $loaded . ' = load ptr, ptr ' . $res . "\n";
        $this->lastValue = $loaded;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `array_pop($v)` — shrink the vec length in place (header[0]) and
     * return the last element. No realloc; the slot still points at the
     * same buffer so the caller sees the new length. A 0-length pop reads
     * a header slot (discarded by the select) and returns 0/null.
     * No CoW — matches the owned-worklist usage in the self-host source.
     */
    private function biArrayPop(Call $c): string
    {
        $out = $this->emitNode($c->args[0]);
        $out .= $this->coerceToPtr();
        $arr = $this->lastValue;
        $v = $this->allocSsa();
        $out .= '  ' . $v . ' = call i64 @__mir_array_pop(ptr ' . $arr . ")\n";
        return $out . $this->finishElem($c, $v);
    }

    /**
     * `array_shift($v)` — return element 0, slide the tail down one slot
     * (memmove, no-op on 0 bytes), decrement length in place. No realloc.
     */
    private function biArrayShift(Call $c): string
    {
        $this->libcExtra['memmove'] = 'declare ptr @memmove(ptr, ptr, i64)';
        $out = $this->emitNode($c->args[0]);
        $out .= $this->coerceToPtr();
        $arr = $this->lastValue;
        $v = $this->allocSsa();
        $out .= '  ' . $v . ' = call i64 @__mir_array_shift(ptr ' . $arr . ")\n";
        return $out . $this->finishElem($c, $v);
    }

    /**
     * `array_unshift($v, $x)` — prepend `$x`: realloc to len+1 capacity,
     * slide the existing elements right one slot (memmove), store `$x` at
     * index 0, bump length, write the relocated ptr back through the
     * array's local / property slot. Returns the new count. Single-value
     * form (the only shape the self-host source uses).
     */
    private function biArrayUnshift(Call $c): string
    {
        $arrNode = $c->args[0];
        $out = $this->emitNode($arrNode);
        $out .= $this->coerceToPtr();
        $arr = $this->lastValue;
        $out .= $this->emitNode($c->args[1]);
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $out .= $this->rcRetainByType($c->args[1], $val, null, 2);
        $new = $this->allocSsa();
        $out .= '  ' . $new . ' = call ptr @__mir_array_unshift(ptr ' . $arr . ', i64 ' . $val . ")\n";
        $out .= $this->vecWriteBack($arrNode, $new);
        $nl = $this->allocSsa();
        $out .= '  ' . $nl . ' = load i64, ptr ' . $new . "\n";
        $this->lastValue = $nl;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args  getenv($name) — `string|false` tagged cell:
     * a copy of the C env string, or boxed false when unset. */
    private function biGetenv(array $args): string
    {
        $this->needsTagged = true;
        $this->libcExtra['getenv'] = 'declare ptr @getenv(ptr)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $out = $this->emitPtrArg($args[0]);
        $nm = $this->lastValue;
        $ev = $this->allocSsa();
        $out .= '  ' . $ev . ' = call ptr @getenv(ptr ' . $nm . ")\n";
        $out .= $this->freeStrTemp($args[0], $nm);
        $isnull = $this->allocSsa();
        $out .= '  ' . $isnull . ' = icmp eq ptr ' . $ev . ", null\n";
        $lSet = $this->allocLabel('genv.set');
        $lNull = $this->allocLabel('genv.null');
        $lEnd = $this->allocLabel('genv.end');
        $out .= '  br i1 ' . $isnull . ', label %' . $lNull . ', label %' . $lSet . "\n";
        $out .= $lSet . ":\n";
        $len = $this->allocSsa();
        $out .= '  ' . $len . ' = call i64 @strlen(ptr ' . $ev . ")\n";
        $sz = $this->allocSsa();
        $out .= '  ' . $sz . ' = add i64 ' . $len . ", 1\n";
        $buf = $this->allocSsa();
        $out .= '  ' . $buf . ' = call ptr @__mir_str_alloc(i64 ' . $sz . ")\n";
        $mc = $this->allocSsa();
        $out .= '  ' . $mc . ' = call ptr @memcpy(ptr ' . $buf . ', ptr ' . $ev . ', i64 ' . $sz . ")\n";
        $sc = $this->allocSsa();
        $out .= '  ' . $sc . ' = call i64 @__manticore_box_ptr(ptr ' . $buf . ")\n";
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lNull . ":\n";
        $fc = $this->allocSsa();
        $out .= '  ' . $fc . " = call i64 @__manticore_box_bool(i64 0)\n";
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lEnd . ":\n";
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = phi i64 [' . $sc . ', %' . $lSet . '], ['
              . $fc . ', %' . $lNull . "]\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Ptr to an interned string literal. */
    private function litStr(string $s): string
    {
        return $this->strLitId($this->internString($s));
    }

    // --- Reflection Tier-1 (compile-time class queries, no runtime metadata) ---

    /** Resolve a class name from a reflection arg — a string literal or an
     *  obj-typed value. '' when not statically known (→ Tier-2). */
    private function reflClassName(Node $arg): string
    {
        if ($arg->kind === Node::KIND_STRING_CONST) {
            return \ltrim($this->castStringConst($arg)->value, '\\');
        }
        return \ltrim($arg->type->class ?? '', '\\');
    }

    /** A string-literal arg's value, or '' when not a literal. */
    private function reflLitStr(Node $arg): string
    {
        return $arg->kind === Node::KIND_STRING_CONST
            ? $this->castStringConst($arg)->value : '';
    }

    /** Emit each arg for its side effects, discard the values. @param Node[] $args */
    private function reflEvalArgs(array $args): string
    {
        $out = '';
        foreach ($args as $a) { $out .= $this->emitNode($a); }
        return $out;
    }

    private function biConstBool(string $out, bool $v): string
    {
        $this->lastValue = $v ? '1' : '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** class_exists / enum_exists / interface_exists / trait_exists — a
     *  static-table membership fold. `$kind` selects the table; class_exists
     *  also matches enums (PHP: an enum IS a class), never interfaces/traits. */
    private function biClassExists(array $args, string $kind): string
    {
        $out = $this->reflEvalArgs($args);
        $name = $this->reflClassName($args[0]);
        $exists = false;
        if ($name !== '') {
            if ($kind === 'enum') { $exists = isset($this->enums[$name]); }
            elseif ($kind === 'interface') { $exists = isset($this->interfaceNames[$name]); }
            elseif ($kind === 'trait') { $exists = isset($this->traitNames[$name]); }
            else { $exists = isset($this->classes[$name]) || isset($this->enums[$name]); }
        }
        return $this->biConstBool($out, $exists);
    }

    /** method_exists($obj|'C', 'm') — walk the parent chain for the method. */
    private function biMethodExists(array $args): string
    {
        $out = $this->reflEvalArgs($args);
        $cls = $this->reflClassName($args[0]);
        $m = $this->reflLitStr($args[1]);
        return $this->biConstBool($out, $cls !== '' && $m !== ''
            && $this->resolveMethodClass($cls, $m) !== '');
    }

    /** property_exists($obj|'C', 'p') — walk the parent chain for the prop. */
    private function biPropertyExists(array $args): string
    {
        $out = $this->reflEvalArgs($args);
        $cls = $this->reflClassName($args[0]);
        $p = $this->reflLitStr($args[1]);
        return $this->biConstBool($out, $cls !== '' && $p !== ''
            && $this->reflPropertyDecl($cls, $p) !== '');
    }

    /** The ancestor class that declares property $p, or '' if none. */
    private function reflPropertyDecl(string $cls, string $p): string
    {
        $c = $cls;
        while ($c !== '') {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { return ''; }
            if ($cd->propertyOffset($p) !== -1) { return $c; }
            $c = $cd->parent;
        }
        return '';
    }

    /** is_a($obj|'C', 'X') / is_subclass_of (strict — excludes the class itself).
     *  Reuses the instanceof ancestor+interface walk ({@see classIsA}). */
    private function biIsA(array $args, bool $strict): string
    {
        $out = $this->reflEvalArgs($args);
        $sub = $this->reflClassName($args[0]);
        $target = $this->reflClassName($args[1]);
        $r = $sub !== '' && $target !== ''
            && (!$strict || $sub !== $target)
            && $this->classIsA($sub, $target);
        return $this->biConstBool($out, $r);
    }

    /** get_parent_class($obj|'C') — parent name string, or boxed false. */
    private function biGetParentClass(array $args): string
    {
        $this->needsTagged = true;
        $out = \count($args) >= 1 ? $this->reflEvalArgs($args) : '';
        $cls = \count($args) >= 1 ? $this->reflClassName($args[0]) : '';
        $parent = ($cls !== '' && isset($this->classes[$cls]))
            ? \ltrim($this->classes[$cls]->parent, '\\') : '';
        $r = $this->allocSsa();
        if ($parent === '') {
            $out .= '  ' . $r . " = call i64 @__manticore_box_bool(i64 0)\n";
        } else {
            $out .= '  ' . $r . ' = call i64 @__manticore_box_ptr(ptr '
                  . $this->litStr($parent) . ")\n";
        }
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** get_class_methods($obj|'C') — vec[cell] of declared + inherited method
     *  names. The full list is known at compile time → a fixed append chain. */
    private function biGetClassMethods(array $args): string
    {
        $this->needsTagged = true;
        $out = $this->reflEvalArgs($args);
        $cls = $this->reflClassName($args[0]);
        $names = $this->reflAllMethods($cls);
        $cur = $this->allocSsa();
        $out .= '  ' . $cur . ' = call ptr @__mir_array_alloc(i64 '
              . (string)\count($names) . ")\n";
        foreach ($names as $nm) {
            $b = $this->allocSsa();
            $out .= '  ' . $b . ' = call i64 @__manticore_box_ptr(ptr '
                  . $this->litStr($nm) . ")\n";
            $nx = $this->allocSsa();
            $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr '
                  . $cur . ', i64 ' . $b . ")\n";
            $cur = $nx;
        }
        $this->lastValue = $cur;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** Declared + inherited method names (parent chain, child-first, deduped). */
    private function reflAllMethods(string $cls): array
    {
        $names = [];
        $seen = [];
        $c = $cls;
        while ($c !== '') {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { break; }
            foreach ($cd->methodNames as $m => $_) {
                if (!isset($seen[$m])) { $seen[$m] = true; $names[] = $m; }
            }
            $c = $cd->parent;
        }
        return $names;
    }

    /**
     * `get_object_vars($o)` — assoc[string, cell] of the object's declared
     * properties (name → boxed value). Walks the static class layout; an
     * unknown class yields an empty map. Cell-valued so a foreach over the
     * result sees tagged values.
     * @param Node[] $args
     */
    private function biGetObjectVars(array $args): string
    {
        $this->needsTagged = true;
        $obj = $args[0];
        $out = $this->emitNode($obj);
        $out .= $this->coerceToPtr();
        $objp = $this->lastValue;
        $initg = $this->allocSsa();
        $out .= '  ' . $initg . " = call ptr @__mir_array_alloc(i64 0)\n";
        $cur = $initg;
        $cls = $obj->type->class ?? '';
        if ($cls !== '' && isset($this->classes[$cls])) {
            $cd = $this->classes[$cls];
            foreach ($cd->propertyNames as $pn) {
                $pt = $cd->propertyTypes[$pn] ?? null;
                if ($pt === null) { continue; }
                $off = (string)$cd->propertyOffset($pn);
                $key = $this->litStr($pn);
                $g = $this->allocSsa();
                $out .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objp . ', i64 ' . $off . "\n";
                $v = $this->allocSsa();
                $out .= '  ' . $v . ' = load i64, ptr ' . $g . "\n";
                // Box the raw i64 carrier to a tagged cell per its static type.
                $this->lastValue = $v;
                $this->lastValueType = 'i64';
                $out .= $this->boxToCell($pt);
                $boxed = $this->lastValue;
                $next = $this->allocSsa();
                $out .= '  ' . $next . ' = call ptr @__mir_array_set_str(ptr '
                      . $cur . ', ptr ' . $key . ', i64 ' . $boxed . ", i64 0, i64 0)\n";
                $cur = $next;
            }
        }
        $this->lastValue = $cur;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `var_export($x, true)` — PHP-literal string. Scalar-accurate
     * (string→`'..'`, null→`NULL`, bool→`true`/`false`, int/float→text);
     * the self-host call sites pass `?string` / scalars in diagnostic
     * messages. The optional 2nd arg (always `true`) is ignored.
     * @param Node[] $args
     */
    private function biVarExport(array $args): string
    {
        $this->needsConcat = true;
        $k = $args[0]->type->kind;
        if ($k === Type::KIND_NULL) {
            $this->lastValue = $this->litStr('NULL');
            $this->lastValueType = 'ptr';
            return '';
        }
        if ($k === Type::KIND_BOOL) {
            $out = $this->emitNode($args[0]);
            $out .= $this->coerceToI64();
            $b = $this->allocSsa();
            $out .= '  ' . $b . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = select i1 ' . $b . ', ptr ' . $this->litStr('true')
                  . ', ptr ' . $this->litStr('false') . "\n";
            $this->lastValue = $r;
            $this->lastValueType = 'ptr';
            return $out;
        }
        if ($k === Type::KIND_INT) {
            $this->needsIntStr = true;
            $out = $this->emitNode($args[0]);
            $out .= $this->coerceToI64();
            $r = $this->allocSsa();
            $out .= '  ' . $r . ' = call ptr @__mir_int_to_str(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'ptr';
            return $out;
        }
        if ($k === Type::KIND_STRING) {
            // `'` . $s . `'`, with NULL when the (nullable) string is null.
            $out = $this->emitNode($args[0]);
            $out .= $this->coerceToPtr();
            $s = $this->lastValue;
            return $out . $this->wrapOrNull($s, "'", "'", 'NULL');
        }
        // Non-scalar (unused by the self-host call sites) — a safe marker.
        $out = $this->emitNode($args[0]);
        $this->lastValue = $this->litStr('?');
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Build `$pre . $s . $post`, or `$nullLit` when `$s` is a null ptr.
     * Leaves the result ptr in lastValue. Used by var_export/json_encode
     * for nullable strings (avoids strlen(null) in concat).
     */
    private function wrapOrNull(string $s, string $pre, string $post, string $nullLit): string
    {
        $isnull = $this->allocSsa();
        $out = '  ' . $isnull . ' = icmp eq ptr ' . $s . ", null\n";
        $lNull = $this->allocLabel('we.null');
        $lSet = $this->allocLabel('we.set');
        $lEnd = $this->allocLabel('we.end');
        $out .= '  br i1 ' . $isnull . ', label %' . $lNull . ', label %' . $lSet . "\n";
        $out .= $lSet . ":\n";
        $c1 = $this->allocSsa();
        $out .= '  ' . $c1 . ' = call ptr @__mir_concat(ptr ' . $this->litStr($pre) . ', ptr ' . $s . ")\n";
        $c2 = $this->allocSsa();
        $out .= '  ' . $c2 . ' = call ptr @__mir_concat(ptr ' . $c1 . ', ptr ' . $this->litStr($post) . ")\n";
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lNull . ":\n";
        $nl = $this->litStr($nullLit);
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lEnd . ":\n";
        $r = $this->allocSsa();
        $out .= '  ' . $r . ' = phi ptr [' . $c2 . ', %' . $lSet . '], [' . $nl . ', %' . $lNull . "]\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  addslashes($s) — backslash-escape ' " \. */
    private function biAddslashes(array $args): string
    {
        $this->needsAddslashes = true;
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_addslashes(ptr ' . $a0 . ")\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** Runtime: backslash-escape `'` `"` `\` (NUL handling is moot for a
     * strlen-scanned C string). Worst case doubles the length. */
    private function addslashesRuntime(): string
    {
        $out  = "\ndefine ptr @__mir_addslashes(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @strlen(ptr %s)\n";
        $out .= "  %cap0 = mul i64 %slen, 2\n";
        $out .= "  %cap = add i64 %cap0, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %cap)\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [0, %entry], [%i2, %cont]\n";
        $out .= "  %j = phi i64 [0, %entry], [%j2, %cont]\n";
        $out .= "  %done = icmp sge i64 %i, %slen\n";
        $out .= "  br i1 %done, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %sp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %c = load i8, ptr %sp\n";
        $out .= "  %isq = icmp eq i8 %c, 39\n";
        $out .= "  %isdq = icmp eq i8 %c, 34\n";
        $out .= "  %isbs = icmp eq i8 %c, 92\n";
        $out .= "  %q1 = or i1 %isq, %isdq\n";
        $out .= "  %spec = or i1 %q1, %isbs\n";
        $out .= "  br i1 %spec, label %esc, label %plain\n";
        $out .= "esc:\n";
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 92, ptr %dp\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %j1\n";
        $out .= "  store i8 %c, ptr %dp2\n";
        $out .= "  %je = add i64 %j1, 1\n";
        $out .= "  br label %cont\n";
        $out .= "plain:\n";
        $out .= "  %dp3 = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 %c, ptr %dp3\n";
        $out .= "  %jp = add i64 %j, 1\n";
        $out .= "  br label %cont\n";
        $out .= "cont:\n";
        $out .= "  %j2 = phi i64 [%je, %esc], [%jp, %plain]\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %j)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `__mc_json_escape($s)` — native replacement for the PHP stdlib escaper
     * (hot: called once per string key + string value while encoding). Escapes
     * `"` `\` and the C0 controls \b \t \n \f \r; other bytes pass through raw
     * (matches the PHP {@see \__mc_json_escape}). @param Node[] $args
     */
    private function biJsonEscape(array $args): string
    {
        $this->needsJsonEscape = true;
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_json_escape(ptr ' . $a0 . ")\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** Runtime: JSON-escape `"` `\` \b \t \n \f \r (worst case doubles len).
     * For `"`/`\` the escape byte is the char itself; the controls map to
     * their letter (b/t/n/f/r). All other bytes copy raw. */
    private function jsonEscapeRuntime(): string
    {
        $out  = "\ndefine ptr @__mir_json_escape(ptr %s) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @strlen(ptr %s)\n";
        $out .= "  %cap0 = mul i64 %slen, 2\n";
        $out .= "  %cap = add i64 %cap0, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %cap)\n";
        $out .= "  br label %loop\n";
        $out .= "loop:\n";
        $out .= "  %i = phi i64 [0, %entry], [%i2, %cont]\n";
        $out .= "  %j = phi i64 [0, %entry], [%j2, %cont]\n";
        $out .= "  %done = icmp sge i64 %i, %slen\n";
        $out .= "  br i1 %done, label %fin, label %body\n";
        $out .= "body:\n";
        $out .= "  %sp = getelementptr inbounds i8, ptr %s, i64 %i\n";
        $out .= "  %c = load i8, ptr %sp\n";
        $out .= "  %is34 = icmp eq i8 %c, 34\n";   // "
        $out .= "  %is92 = icmp eq i8 %c, 92\n";   // backslash
        $out .= "  %is10 = icmp eq i8 %c, 10\n";   // \n
        $out .= "  %is9  = icmp eq i8 %c, 9\n";    // \t
        $out .= "  %is13 = icmp eq i8 %c, 13\n";   // \r
        $out .= "  %is8  = icmp eq i8 %c, 8\n";    // \b
        $out .= "  %is12 = icmp eq i8 %c, 12\n";   // \f
        // Escape byte: char itself for " and \\; the letter for the controls.
        $out .= "  %e1 = select i1 %is10, i8 110, i8 %c\n";
        $out .= "  %e2 = select i1 %is9,  i8 116, i8 %e1\n";
        $out .= "  %e3 = select i1 %is13, i8 114, i8 %e2\n";
        $out .= "  %e4 = select i1 %is8,  i8 98,  i8 %e3\n";
        $out .= "  %e5 = select i1 %is12, i8 102, i8 %e4\n";
        $out .= "  %o1 = or i1 %is34, %is92\n";
        $out .= "  %o2 = or i1 %o1, %is10\n";
        $out .= "  %o3 = or i1 %o2, %is9\n";
        $out .= "  %o4 = or i1 %o3, %is13\n";
        $out .= "  %o5 = or i1 %o4, %is8\n";
        $out .= "  %spec = or i1 %o5, %is12\n";
        $out .= "  br i1 %spec, label %esc, label %plain\n";
        $out .= "esc:\n";
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 92, ptr %dp\n";
        $out .= "  %j1 = add i64 %j, 1\n";
        $out .= "  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %j1\n";
        $out .= "  store i8 %e5, ptr %dp2\n";
        $out .= "  %je = add i64 %j1, 1\n";
        $out .= "  br label %cont\n";
        $out .= "plain:\n";
        $out .= "  %dp3 = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 %c, ptr %dp3\n";
        $out .= "  %jp = add i64 %j, 1\n";
        $out .= "  br label %cont\n";
        $out .= "cont:\n";
        $out .= "  %j2 = phi i64 [%je, %esc], [%jp, %plain]\n";
        $out .= "  %i2 = add i64 %i, 1\n";
        $out .= "  br label %loop\n";
        $out .= "fin:\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %j\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %j)\n";
        $out .= "  ret ptr %buf\n";
        $out .= "}\n";
        return $out;
    }

    /**
     * `__mir_str_replace_one($search, $replace, $subject)` — native single
     * pair worker (the stdlib str_replace loop's inner call). Two passes:
     * count matches to size the output exactly, then memcpy each inter-match
     * chunk straight subject→out (no per-chunk `substr` malloc, unlike the PHP
     * worker). @param Node[] $args
     */
    private function biStrReplaceOne(array $args): string
    {
        $this->needsStrReplaceOne = true;
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $this->libcExtra['strstr'] = 'declare ptr @strstr(ptr, ptr)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $out = $this->emitPtrArg($args[0]);
        $se = $this->lastValue;
        $out .= $this->emitPtrArg($args[1]);
        $rp = $this->lastValue;
        $out .= $this->emitPtrArg($args[2]);
        $sj = $this->lastValue;
        $reg = $this->allocSsa();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_replace_one(ptr ' . $se
              . ', ptr ' . $rp . ', ptr ' . $sj . ")\n";
        $out .= $this->freeStrTemp($args[0], $se);
        $out .= $this->freeStrTemp($args[1], $rp);
        $out .= $this->freeStrTemp($args[2], $sj);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** Runtime: replace every non-overlapping `%se` in `%sj` with `%rp`, left
     * to right (PHP str_replace semantics; the replacement is never rescanned).
     * Always returns a FRESH string. Empty/absent search → a plain copy. */
    private function strReplaceOneRuntime(): string
    {
        $out  = "\ndefine ptr @__mir_str_replace_one(ptr %se, ptr %rp, ptr %sj) {\n";
        $out .= "entry:\n";
        $out .= "  %slen = call i64 @strlen(ptr %se)\n";
        $out .= "  %rlen = call i64 @strlen(ptr %rp)\n";
        $out .= "  %jlen = call i64 @strlen(ptr %sj)\n";
        // Empty search or search longer than subject → copy subject verbatim.
        $out .= "  %semp = icmp eq i64 %slen, 0\n";
        $out .= "  %stoolong = icmp ugt i64 %slen, %jlen\n";
        $out .= "  %nomatchposs = or i1 %semp, %stoolong\n";
        $out .= "  br i1 %nomatchposs, label %copy, label %count\n";
        // ── Pass 1: count matches ──
        $out .= "count:\n";
        $out .= "  br label %cloop\n";
        $out .= "cloop:\n";
        $out .= "  %cpos = phi i64 [0, %count], [%cpos2, %chit]\n";
        $out .= "  %ccnt = phi i64 [0, %count], [%ccnt1, %chit]\n";
        $out .= "  %cfrom = getelementptr inbounds i8, ptr %sj, i64 %cpos\n";
        $out .= "  %cf = call ptr @strstr(ptr %cfrom, ptr %se)\n";
        $out .= "  %cnull = icmp eq ptr %cf, null\n";
        $out .= "  br i1 %cnull, label %sized, label %chit\n";
        $out .= "chit:\n";
        $out .= "  %cfi = ptrtoint ptr %cf to i64\n";
        $out .= "  %sji = ptrtoint ptr %sj to i64\n";
        $out .= "  %choff = sub i64 %cfi, %sji\n";
        $out .= "  %cpos2 = add i64 %choff, %slen\n";
        $out .= "  %ccnt1 = add i64 %ccnt, 1\n";
        $out .= "  br label %cloop\n";
        // outlen = jlen + count*rlen - count*slen ; alloc outlen+1
        $out .= "sized:\n";
        $out .= "  %crep = mul i64 %ccnt, %rlen\n";
        $out .= "  %csea = mul i64 %ccnt, %slen\n";
        $out .= "  %o1 = add i64 %jlen, %crep\n";
        $out .= "  %outlen = sub i64 %o1, %csea\n";
        $out .= "  %ocap = add i64 %outlen, 1\n";
        $out .= "  %buf = call ptr @__mir_str_alloc(i64 %ocap)\n";
        // ── Pass 2: fill ──
        $out .= "  br label %floop\n";
        $out .= "floop:\n";
        $out .= "  %src = phi i64 [0, %sized], [%src2, %fhit]\n";
        $out .= "  %dst = phi i64 [0, %sized], [%dst2, %fhit]\n";
        $out .= "  %ffrom = getelementptr inbounds i8, ptr %sj, i64 %src\n";
        $out .= "  %ff = call ptr @strstr(ptr %ffrom, ptr %se)\n";
        $out .= "  %fnull = icmp eq ptr %ff, null\n";
        $out .= "  br i1 %fnull, label %tail, label %fhit\n";
        $out .= "fhit:\n";
        $out .= "  %ffi = ptrtoint ptr %ff to i64\n";
        $out .= "  %sji2 = ptrtoint ptr %sj to i64\n";
        $out .= "  %fhoff = sub i64 %ffi, %sji2\n";
        $out .= "  %chunk = sub i64 %fhoff, %src\n";
        // copy subject[src .. hit) then the replacement
        $out .= "  %dp = getelementptr inbounds i8, ptr %buf, i64 %dst\n";
        $out .= "  call ptr @memcpy(ptr %dp, ptr %ffrom, i64 %chunk)\n";
        $out .= "  %dst1 = add i64 %dst, %chunk\n";
        $out .= "  %dp2 = getelementptr inbounds i8, ptr %buf, i64 %dst1\n";
        $out .= "  call ptr @memcpy(ptr %dp2, ptr %rp, i64 %rlen)\n";
        $out .= "  %dst2 = add i64 %dst1, %rlen\n";
        $out .= "  %src2 = add i64 %fhoff, %slen\n";
        $out .= "  br label %floop\n";
        // tail: copy subject[src .. jlen)
        $out .= "tail:\n";
        $out .= "  %rem = sub i64 %jlen, %src\n";
        $out .= "  %dpt = getelementptr inbounds i8, ptr %buf, i64 %dst\n";
        $out .= "  call ptr @memcpy(ptr %dpt, ptr %ffrom, i64 %rem)\n";
        $out .= "  %fin = add i64 %dst, %rem\n";
        $out .= "  %np = getelementptr inbounds i8, ptr %buf, i64 %fin\n";
        $out .= "  store i8 0, ptr %np\n";
        $out .= "  call void @__mir_str_set_len(ptr %buf, i64 %fin)\n";
        $out .= "  ret ptr %buf\n";
        // ── copy path (empty/too-long search) ──
        $out .= "copy:\n";
        $out .= "  %ccap = add i64 %jlen, 1\n";
        $out .= "  %cbuf = call ptr @__mir_str_alloc(i64 %ccap)\n";
        $out .= "  call ptr @memcpy(ptr %cbuf, ptr %sj, i64 %jlen)\n";
        $out .= "  %cnp = getelementptr inbounds i8, ptr %cbuf, i64 %jlen\n";
        $out .= "  store i8 0, ptr %cnp\n";
        $out .= "  call void @__mir_str_set_len(ptr %cbuf, i64 %jlen)\n";
        $out .= "  ret ptr %cbuf\n";
        $out .= "}\n";
        return $out;
    }

    /** Write a relocated vec ptr back through `$arrNode`'s local or
     * property storage (shared by array_unshift / future realloc ops). */
    private function vecWriteBack(Node $arrNode, string $arr2, bool $asCell = false): string
    {
        if ($arrNode->kind === Node::KIND_LOAD_LOCAL) {
            $name = $this->castLoadLocal($arrNode)->name;
            if (!isset($this->slots[$name])) { return ''; }
            $asI = $this->allocSsa();
            $out = $this->packArrayBack($arr2, $asI, $asCell);
            if (isset($this->refLocals[$name])) {
                // By-ref param: the slot holds the CALLER's address — store the
                // (possibly realloced) buffer THROUGH it, so `$arr[] = …` on a
                // `&$arr` is visible to the caller. Writing the slot directly
                // would clobber the address and leave the caller's array stale.
                $addr = $this->allocSsa();
                $out .= '  ' . $addr . ' = load i64, ptr ' . $this->slots[$name] . "\n";
                $p = $this->allocSsa();
                $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
                $out .= '  store i64 ' . $asI . ', ptr ' . $p . "\n";
            } else {
                $out .= '  store i64 ' . $asI . ', ptr ' . $this->slots[$name] . "\n";
            }
            return $out;
        }
        if ($arrNode->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->castPropertyAccess($arrNode);
            $out = $this->emitNode($pa->object);
            $out .= $this->coerceToPtr();
            $objp = $this->lastValue;
            $off = $this->propertyOffset($pa->object, $pa->property);
            $g = $this->allocSsa();
            $out .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objp
                  . ', i64 ' . (string)$off . "\n";
            $asI = $this->allocSsa();
            $out .= $this->packArrayBack($arr2, $asI, $asCell);
            $out .= '  store i64 ' . $asI . ', ptr ' . $g . "\n";
            return $out;
        }
        // Nested element store (`$m[k][...] = v`): the modified inner array
        // must be re-stored into the parent at its key, and the (possibly
        // realloced) parent threaded further up the chain. Without this the
        // inner mutation lands on a copy the parent never sees.
        if ($arrNode->kind === Node::KIND_ARRAY_ACCESS) {
            $aa = $this->castArrayAccess($arrNode);
            $parentCell = $aa->array->type->kind === Type::KIND_CELL;
            $out = $this->emitNode($aa->array);
            $out .= $parentCell ? $this->cellToPtr() : $this->coerceToPtr();
            $parentPtr = $this->lastValue;
            // The inner array is stored back as the parent's element; box it
            // when the parent holds cells (mixed/cell element type).
            $innerCell = $aa->array->type->element !== null
                && $aa->array->type->element->kind === Type::KIND_CELL;
            $valI = $this->allocSsa();
            $out .= $this->packArrayBack($arr2, $valI, $innerCell);
            $keyIsCell = $aa->index->type->kind === Type::KIND_CELL;
            $keyIsString = !$keyIsCell
                && ($aa->index->type->kind === Type::KIND_STRING
                    || $aa->index->kind === Node::KIND_STRING_CONST);
            $parent2 = $this->allocSsa();
            if ($keyIsCell) {
                $this->needsCellKey = true;
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToI64();
                $key = $this->lastValue;
                $out .= '  ' . $parent2 . ' = call ptr @__mir_array_set_cell(ptr '
                      . $parentPtr . ', i64 ' . $key . ', i64 ' . $valI . ")\n";
            } elseif ($keyIsString) {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToPtr();
                $key = $this->lastValue;
                $out .= '  ' . $parent2 . ' = call ptr @__mir_array_set_str(ptr '
                      . $parentPtr . ', ptr ' . $key . ', i64 ' . $valI
                      . $this->litKeyHashArgs($aa->index) . ")\n";
            } else {
                $out .= $this->emitNode($aa->index);
                $out .= $this->coerceToI64();
                $idx = $this->lastValue;
                $out .= '  ' . $parent2 . ' = call ptr @__mir_array_set_int(ptr '
                      . $parentPtr . ', i64 ' . $idx . ', i64 ' . $valI . ")\n";
            }
            $out .= $this->vecWriteBack($aa->array, $parent2, $parentCell);
            return $out;
        }
        // `Class::$arr[k] = v` — thread the (possibly realloced) buffer back
        // into the static-property global cell, else the next read reloads the
        // stale pre-grow pointer (a first string-keyed store reallocs from the
        // empty `[]` default → the write is silently lost).
        if ($arrNode->kind === Node::KIND_STATIC_PROP) {
            $sp = $this->castStaticProp($arrNode);
            $asI = $this->allocSsa();
            $out = $this->packArrayBack($arr2, $asI, $asCell);
            $out .= '  store i64 ' . $asI . ', ptr ' . $sp->global . "\n";
            return $out;
        }
        return '';
    }

    /**
     * Emit the i64 written back into an array-holding slot. A cell-typed slot
     * (mixed property / param) must hold a NaN-boxed array cell, not a raw
     * pointer — else a later tag-checking consumer (is_array / var_dump)
     * misreads it; box back when $asCell. $reg is the pre-allocated dest SSA.
     */
    private function packArrayBack(string $ptr, string $reg, bool $asCell): string
    {
        if ($asCell) {
            $this->needsTagged = true;
            return '  ' . $reg . ' = call i64 @__manticore_box_array(ptr ' . $ptr . ")\n";
        }
        return '  ' . $reg . ' = ptrtoint ptr ' . $ptr . " to i64\n";
    }

    /**
     * Finish a builtin whose i64 result carries the vec element type:
     * bitcast to double when InferTypes flavoured the call float, else
     * leave the i64 carrier (ptr-flavoured types ride the i64 too).
     */
    private function finishElem(Call $c, string $reg): string
    {
        if ($c->type->kind === Type::KIND_FLOAT) {
            $f = $this->allocSsa();
            $this->lastValue = $f;
            $this->lastValueType = 'double';
            return '  ' . $f . ' = bitcast i64 ' . $reg . " to double\n";
        }
        $isPtr = $c->type->kind === Type::KIND_STRING
            || $c->type->kind === Type::KIND_ARRAY
            || $c->type->kind === Type::KIND_OBJ;
        if ($isPtr) {
            // The element rides the vec as an i64 carrier; surface it as a
            // real ptr so string/obj/array consumers (echo %s, etc.) type.
            $p = $this->allocSsa();
            $this->lastValue = $p;
            $this->lastValueType = 'ptr';
            return '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return '';
    }
}
