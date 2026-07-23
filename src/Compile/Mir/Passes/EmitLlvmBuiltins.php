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
        if ($name === '__str_byte_at')                { return $this->biStrByteAt($args); }
        if ($name === 'str_from_buffer')              { return $this->biStrFromBuffer($args); }
        if ($name === 'cstr_to_str')                  { return $this->biCstrToStr($args); }
        if ($name === 'ptr_offset')                   { return $this->biPtrOffset($args); }
        if ($name === 'int_to_ptr')                   { return $this->biIntToPtr($args); }
        if ($name === 'ptr_to_int')                   { return $this->biPtrToInt($args); }
        if ($name === 'peek_i64')                     { return $this->biPeek($args, 64, true); }
        if ($name === 'peek_i32')                     { return $this->biPeek($args, 32, true); }
        if ($name === 'peek_i16')                     { return $this->biPeek($args, 16, true); }
        if ($name === 'peek_i8')                      { return $this->biPeek($args, 8, true); }
        if ($name === 'peek_u32')                     { return $this->biPeek($args, 32, false); }
        if ($name === 'peek_u16')                     { return $this->biPeek($args, 16, false); }
        if ($name === 'peek_u8')                      { return $this->biPeek($args, 8, false); }
        if ($name === 'poke_i64')                     { return $this->biPoke($args, 64); }
        if ($name === 'poke_i32')                     { return $this->biPoke($args, 32); }
        if ($name === 'poke_i16')                     { return $this->biPoke($args, 16); }
        if ($name === 'poke_i8')                      { return $this->biPoke($args, 8); }
        if ($name === '__mc_errno')                   { return $this->biErrno(); }
        if ($name === '__mir_stdin')                  { return $this->biStdStream('stdin'); }
        if ($name === '__mir_stdout')                 { return $this->biStdStream('stdout'); }
        if ($name === '__mir_stderr')                 { return $this->biStdStream('stderr'); }
        if ($name === '__mir_argc')                   { return $this->biCliArgc(); }
        if ($name === '__mir_argv_at')                { return $this->biCliArgvAt($args); }
        if ($name === '__mir_env_count')              { return $this->biEnvCount(); }
        if ($name === '__mir_env_at')                 { return $this->biEnvAt($args); }
        if ($name === '__mir_clock_ns')               { return $this->biClockNs($args); }
        if ($name === '__mir_to_cell')                { return $this->biToCell($args); }
        if ($name === '__mir_fiber_make')             { return $this->biFiberMake($args); }
        if ($name === '__mir_fiber_jump')             { return $this->biFiberJump($args); }
        if ($name === '__mir_fiber_current')          { return $this->biFiberCurrent($args); }
        if ($name === '__mir_fiber_set_current')      { return $this->biFiberSetCurrent($args); }
        if ($name === '__mir_fiber_stack_alloc')      { return $this->biFiberStackAlloc($args); }
        if ($name === '__mir_fiber_stack_free')       { return $this->biFiberStackFree($args); }
        if ($name === '__mir_fiber_ctx_new')          { return $this->biFiberCtxNew($args); }
        if ($name === '__mir_fiber_ctx_save')         { return $this->biFiberCtxSave($args); }
        if ($name === '__mir_fiber_ctx_load')         { return $this->biFiberCtxLoad($args); }
        if ($name === '__mir_fiber_has_current')      { return $this->biFiberHasCurrent($args); }
        if ($name === '__mir_fiber_ctx_free')         { return $this->biFiberCtxFree($args); }
        if ($name === '__mir_fiber_main_ctx')         { return $this->biFiberMainCtx($args); }
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
        if ($name === 'is_numeric')                    { return $this->biIsNumeric($args); }
        if ($name === 'is_bool')                      { return $this->biIsType($args, 2, Type::KIND_BOOL); }
        if ($name === 'is_array')                     { return $this->biIsType($args, 7, Type::KIND_ARRAY); }
        if ($name === 'is_object')                    { return $this->biIsType($args, 8, Type::KIND_OBJ); }
        if ($name === 'is_callable')                  { return $this->biIsCallable($args); }
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
        if ($name === 'strcspn' && \count($args) >= 2) { return $this->biStrcspn($args); }
        if ($name === '__float_bits')                 { return $this->biFloatBits($args); }
        if ($name === '__ugt')                        { return $this->biUgt($args); }
        if ($name === '__ryu_msp')                    { return $this->biRyuMsp($args); }
        if ($name === 'explode' && \count($args) >= 2) { return $this->biExplode($args); }
        if ($name === 'print_r' && \count($args) >= 1) { return $this->biPrintR($args); }
        if ($name === 'implode' || $name === 'join')  { return $this->biImplode($args); }
        if ($name === 'sprintf')                      { return $this->biSprintf($args, false); }
        if ($name === 'printf')                       { return $this->biSprintf($args, true); }
        if ($name === '__mc_fmt_int')                 { return $this->biFmt1($args, 'i'); }
        if ($name === '__mc_fmt_float')               { return $this->biFmt1($args, 'f'); }
        if ($name === '__mc_fmt_str')                 { return $this->biFmt1($args, 's'); }
        if ($name === 'exit' || $name === 'die')      { return $this->biExit($args); }
        if ($name === 'error_log')                    { return $this->biErrorLog($args); }
        if ($name === 'gc_collect_cycles')            { return $this->biGcCollect(); }
        if ($name === 'spl_object_id')                { return $this->biSplObjectId($args); }
        // Reflection Tier-2 metadata reads. Internal (`__mc_` prefix) — the
        // public surface is prelude/reflection.php, which is generic code over
        // the data these return.
        if ($name === '__mc_refl_of')                 { return $this->biMcReflOf($args); }
        if ($name === '__mc_refl_name')               { return $this->biMcReflName($args); }
        if ($name === '__mc_refl_find')               { return $this->biMcReflFind($args); }
        if ($name === '__mc_refl_cap')                { return $this->biMcReflCap($args); }
        if ($name === '__mc_refl_slot')               { return $this->biMcReflSlot($args); }
        if ($name === '__mc_refl_member')             { return $this->biMcReflMember($args); }
        if ($name === '__mc_refl_parent')             { return $this->biMcReflParent($args); }
        if ($name === '__mc_refl_flags')              { return $this->biMcReflFlags($args); }
        if ($name === '__mc_refl_tramp')              { return $this->biMcReflTramp($args); }
        if ($name === '__mc_refl_ctor')               { return $this->biMcReflCtor($args); }
        if ($name === '__mc_refl_invoke')             { return $this->biMcReflInvoke($args); }
        if ($name === '__mc_refl_mrow')               { return $this->biMcReflMrow($args); }
        if ($name === '__mc_refl_row_nparams')        { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ROW_NPARAMS_OFFSET, false); }
        if ($name === '__mc_refl_row_params')         { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ROW_PARAMS_OFFSET, true); }
        if ($name === '__mc_refl_row_arity')          { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ROW_ARITY_OFFSET, false); }
        if ($name === '__mc_refl_param_name')         { return $this->emitReflParamField($args, \Compile\MemoryAbi::RMETA_PARAM_NAME_OFFSET, true); }
        if ($name === '__mc_refl_param_type')         { return $this->emitReflParamField($args, \Compile\MemoryAbi::RMETA_PARAM_TYPE_OFFSET, true); }
        if ($name === '__mc_refl_param_flags')        { return $this->emitReflParamField($args, \Compile\MemoryAbi::RMETA_PARAM_FLAGS_OFFSET, false); }
        if ($name === '__mc_refl_prow')               { return $this->biMcReflProw($args); }
        if ($name === '__mc_refl_prow_type')          { return $this->emitReflFieldI64($args, 0, true); }
        if ($name === '__mc_refl_prop_getter')        { return $this->emitReflFieldI64($args, 8, true); }
        if ($name === '__mc_refl_prop_setter')        { return $this->emitReflFieldI64($args, 16, true); }
        if ($name === '__mc_refl_call1')              { return $this->biMcReflCall1($args); }
        if ($name === '__mc_refl_prop_set')           { return $this->biMcReflPropSet($args); }
        if ($name === '__mc_refl_nprops')             { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_NPROPS_OFFSET, false); }
        if ($name === '__mc_refl_props_base')         { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_PROPS_OFFSET, true); }
        if ($name === '__mc_refl_nmethods')           { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_NMETHODS_OFFSET, false); }
        if ($name === '__mc_refl_methods_base')       { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_METHODS_OFFSET, true); }
        if ($name === '__mc_refl_row_name')           { return $this->emitReflParamField($args, \Compile\MemoryAbi::RMETA_ROW_NAME_OFFSET, true, \Compile\MemoryAbi::RMETA_ROW_SIZE); }
        if ($name === '__mc_refl_class_nattrs')       { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_NATTRS_OFFSET, false); }
        if ($name === '__mc_refl_class_attrs')        { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ATTRS_OFFSET, true); }
        if ($name === '__mc_refl_row_nattrs')         { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ROW_NATTRS_OFFSET, false); }
        if ($name === '__mc_refl_row_attrs')          { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ROW_ATTRS_OFFSET, true); }
        if ($name === '__mc_refl_attr_name')          { return $this->emitReflParamField($args, \Compile\MemoryAbi::RMETA_ATTR_NAME_OFFSET, true, \Compile\MemoryAbi::RMETA_ATTR_SIZE); }
        if ($name === '__mc_refl_attr_args')          { return $this->emitReflParamField($args, \Compile\MemoryAbi::RMETA_ATTR_ARGS_OFFSET, true, \Compile\MemoryAbi::RMETA_ATTR_SIZE); }
        if ($name === '__mc_refl_attr_new')           { return $this->emitReflParamField($args, \Compile\MemoryAbi::RMETA_ATTR_NEW_OFFSET, true, \Compile\MemoryAbi::RMETA_ATTR_SIZE); }
        if ($name === '__mc_refl_call0')              { return $this->biMcReflCall0($args); }
        if ($name === '__mc_refl_consts_fn')          { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_CONSTS_FN_OFFSET, true); }
        if ($name === '__mc_refl_ifaces_fn')          { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_IFACES_FN_OFFSET, true); }
        if ($name === '__mc_refl_row_rettype')        { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ROW_RETTYPE_OFFSET, true); }
        if ($name === '__mc_refl_row_tramp')          { return $this->emitReflFieldI64($args, \Compile\MemoryAbi::RMETA_ROW_TRAMP_OFFSET, true); }
        if ($name === '__mc_refl_fn_find')            { return $this->biMcReflFnFind($args); }
        if ($name === 'var_dump')                     { return $this->biVarDump($args); }
        if ($name === '__mir_enum_name')              { return $this->biEnumName($args); }
        if ($name === 'get_class')                    { return $this->biGetClass($args); }
        if ($name === 'array_keys') {
            return \count($args) >= 2 ? $this->biArrayKeysSearch($args) : $this->biArrayKeys($args);
        }
        if ($name === 'debug_backtrace')              { return $this->biDebugBacktrace(); }
        if ($name === 'array_first' && \count($args) === 1)     { return $this->biArrayEndpoint($args, false, false); }
        if ($name === 'array_last' && \count($args) === 1)      { return $this->biArrayEndpoint($args, true, false); }
        if ($name === 'array_key_first' && \count($args) === 1) { return $this->biArrayEndpoint($args, false, true); }
        if ($name === 'array_key_last' && \count($args) === 1)  { return $this->biArrayEndpoint($args, true, true); }
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
        if ($name === 'json_encode' && \count($args) === 1) { return $this->biJsonEncode($args); }
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
    /**
     * `__mir_enum_name($v)` — internal, called from the var_dump object prelude.
     * If the cell `$v` is an enum-case singleton, return its "<Enum>::<Case>"
     * string (so var_dump renders `enum(Enum::Case)`); else the empty string.
     * Reads the object's class descriptor (data+0 → class_id) and matches it
     * against each enum's stable class_id, then indexes `<Enum>__fqns[ordinal]`.
     * @param Node[] $args
     */
    private function biEnumName(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $cell = $this->lastValue;
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca ptr\n";
        $out .= '  store ptr ' . $this->strSymBytes('@.cstr.empty') . ', ptr ' . $res . "\n";
        $out .= $this->cellTagIr($cell);
        $tag = $this->cellTagReg;
        $isObj = $this->ssa->allocReg();
        $out .= '  ' . $isObj . ' = icmp eq i64 ' . $tag . ", 8\n";
        $objL = $this->ssa->allocLabel('en.obj');
        $doneL = $this->ssa->allocLabel('en.done');
        $out .= '  br i1 ' . $isObj . ', label %' . $objL . ', label %' . $doneL . "\n";
        $out .= $objL . ":\n";
        $m = $this->ssa->allocReg();
        $out .= '  ' . $m . ' = and i64 ' . $cell . ", 281474976710655\n";
        $dp = $this->ssa->allocReg();
        $out .= '  ' . $dp . ' = inttoptr i64 ' . $m . " to ptr\n";
        $descI = $this->ssa->allocReg();
        $out .= '  ' . $descI . ' = load i64, ptr ' . $dp . "\n";
        $descP = $this->ssa->allocReg();
        $out .= '  ' . $descP . ' = inttoptr i64 ' . $descI . " to ptr\n";
        $cid = $this->ssa->allocReg();
        $out .= '  ' . $cid . ' = load i64, ptr ' . $descP . "\n";
        foreach ($this->enums as $ename => $ed) {
            $ct = (string)\count($ed->caseNames);
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i64 ' . $cid . ', ' . (string)$ed->classId . "\n";
            $hitL = $this->ssa->allocLabel('en.hit');
            $nextL = $this->ssa->allocLabel('en.next');
            $out .= '  br i1 ' . $eq . ', label %' . $hitL . ', label %' . $nextL . "\n";
            $out .= $hitL . ":\n";
            $og = $this->ssa->allocReg();
            $out .= '  ' . $og . ' = getelementptr i8, ptr ' . $dp . ", i64 16\n";
            $ord = $this->ssa->allocReg();
            $out .= '  ' . $ord . ' = load i64, ptr ' . $og . "\n";
            $fg = $this->ssa->allocReg();
            $out .= '  ' . $fg . ' = getelementptr [' . $ct . ' x ptr], ptr @' . $this->mangle($ename) . '__fqns, i64 0, i64 ' . $ord . "\n";
            $fp = $this->ssa->allocReg();
            $out .= '  ' . $fp . ' = load ptr, ptr ' . $fg . "\n";
            $out .= '  store ptr ' . $fp . ', ptr ' . $res . "\n";
            $out .= '  br label %' . $doneL . "\n";
            $out .= $nextL . ":\n";
        }
        $out .= '  br label %' . $doneL . "\n";
        $out .= $doneL . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load ptr, ptr ' . $res . "\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function boxToCell(Type $t): string
    {
        $this->rt->needsTagged = true;
        $k = $t->kind;
        // Already a tagged cell — don't double-box.
        if ($k === Type::KIND_CELL) { return $this->coerceToI64(); }
        if ($k === Type::KIND_FLOAT) {
            $out = $this->coerceTo('double');
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_float(double ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($k === Type::KIND_STRING) {
            $out = $this->coerceToPtr();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_ptr(ptr ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($k === Type::KIND_NULL) {
            $r = $this->ssa->allocReg();
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
            $r = $this->ssa->allocReg();
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
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_array(ptr ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($k === Type::KIND_OBJ && $t->class !== null && isset($this->enums[$t->class])) {
            // An enum case is an ORDINAL — box the per-case SINGLETON (carrying
            // class identity), NOT the raw ordinal (box_object of a tiny int
            // faults every generic object consumer). See emitEnumCellSingletons.
            $out = $this->coerceToI64();
            $ord = $this->lastValue;
            $tbl = '@' . $this->mangle($t->class) . '__cases';
            $ct = (string)\count($this->enums[$t->class]->caseNames);
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = getelementptr [' . $ct . ' x i64], ptr ' . $tbl . ', i64 0, i64 ' . $ord . "\n";
            $dp = $this->ssa->allocReg();
            $out .= '  ' . $dp . ' = load i64, ptr ' . $g . "\n";
            $pp = $this->ssa->allocReg();
            $out .= '  ' . $pp . ' = inttoptr i64 ' . $dp . " to ptr\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_object(ptr ' . $pp . ")\n";
            return $this->finishI64($out, $r);
        }
        if ($k === Type::KIND_OBJ || $k === Type::KIND_UNION) {
            // A union arm is a bare object pointer (all-object union) — box it as
            // an object cell so a tagged consumer (var_dump / a mixed param)
            // dispatches on the object tag and the class_id resolves the type.
            $out = $this->coerceToPtr();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @__manticore_box_object(ptr ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $r);
        }
        $helper = ($k === Type::KIND_BOOL) ? '__manticore_box_bool' : '__manticore_box_int';
        $out = $this->coerceToI64();
        $r = $this->ssa->allocReg();
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
        $m = $this->ssa->allocReg();
        $out .= '  ' . $m . ' = and i64 ' . $this->lastValue . ", 281474976710655\n";
        $p = $this->ssa->allocReg();
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
    private function emitAssocToCellArrayUnified(Type $elem, bool $raw = false): string
    {
        $this->rt->needsTagged = true;
        $this->rt->needsCellKey = true;
        $out = $this->coerceToPtr();
        $rawSrc = $this->lastValue;
        // Empty `[]` → null ptr; redirect to the zero-word so len reads 0.
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->ssa->allocReg();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        // Compact out tombstones first (null-safe on the raw ptr): the cellify
        // walk copies each entry by physical index, so a hole would otherwise be
        // resurrected into the rebuilt cell-array (a var_dump / json of an
        // unset-then-boxed map showed the dead entry with a garbage key).
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = call i64 @__mir_array_live_len(ptr ' . $rawSrc . ")\n";
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->ssa->allocReg();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $len . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->ssa->allocReg();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->ssa->allocLabel('uac.cond');
        $body = $this->ssa->allocLabel('uac.body');
        $end  = $this->ssa->allocLabel('uac.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $kb = $this->ssa->allocReg();
        $out .= '  ' . $kb . ' = call i64 @__mir_array_key_cell_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        $boxed = $this->ssa->allocReg();
        $ek = $elem->kind;
        if ($ek === Type::KIND_STRING) {
            $ep = $this->ssa->allocReg();
            $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_ptr(ptr ' . $ep . ")\n";
        } elseif ($ek === Type::KIND_FLOAT) {
            $ed = $this->ssa->allocReg();
            $out .= '  ' . $ed . ' = bitcast i64 ' . $ev . " to double\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_float(double ' . $ed . ")\n";
        } elseif ($ek === Type::KIND_BOOL) {
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_bool(i64 ' . $ev . ")\n";
        } elseif ($ek === Type::KIND_OBJ) {
            $ep = $this->ssa->allocReg();
            $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_object(ptr ' . $ep . ")\n";
        } elseif ($ek === Type::KIND_ARRAY) {
            $nestElem = $elem->element ?? Type::unknown();
            if ($nestElem->kind === Type::KIND_CELL) {
                // The inner array is ALREADY cell-repr (its values are boxed
                // cells) — box it as a plain array cell. Rebuilding would re-box
                // each already-boxed cell (else-branch box_int) → double-box
                // garbage (a vec of mixed assocs read raw by var_dump/json).
                $ep = $this->ssa->allocReg();
                $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
                $out .= '  ' . $boxed . ' = call i64 @__manticore_box_array(ptr ' . $ep . ")\n";
            } else {
                // Nested array value → recursively rebuild as a cell-array (see
                // the vec variant) so its own concrete elements render.
                $this->lastValue = $ev;
                $this->lastValueType = 'i64';
                $out .= $elem->isAssoc()
                    ? $this->emitAssocToCellArrayUnified($nestElem)
                    : $this->emitVecToCellArrayUnified($nestElem);
                $boxed = $this->lastValue;
            }
        } else {
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_int(i64 ' . $ev . ")\n";
        }
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->ssa->allocReg();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_set_cell(ptr ' . $cur . ', i64 ' . $kb . ', i64 ' . $boxed . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->ssa->allocReg();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->ssa->allocReg();
        $out .= '  ' . $dst . ' = load ptr, ptr ' . $slot . "\n";
        // A NULL source is a `?array`'s null, not an empty array: the rebuild
        // above read it through the zero-word and would hand back `array(0) {}`,
        // which is what php prints for `[]` — not for NULL. Carry the null
        // through AS a null; `box_array` below already answers `box_null` for
        // ptr 0. `$isNull` is computed in the entry block, which dominates here,
        // so the select needs no extra control flow.
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = select i1 ' . $isNull
              . ', ptr null, ptr ' . $dst . "\n";
        if ($raw) {
            // The cell-element array itself, unboxed: a `vec[cell]` slot is
            // KIND_ARRAY and travels RAW, so a return/store boundary wants this
            // pointer, not a cell wrapping it. {@see emitCellifyArrayRaw}
            $this->lastValue = $res;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @__manticore_box_array(ptr ' . $res . ")\n";
        return $this->finishI64($out, $r);
    }

    /**
     * Cellify a concrete-element array and leave the result RAW — the same
     * rebuild as {@see emitAssocToCellArrayUnified}, stopping before the box.
     *
     * The boundary case: a function whose returns disagree on the element type
     * (`return [false,'loc']` beside `return ['body','']`) types `vec[cell]`, so
     * the arm that is still `vec[string]` must be rebuilt with every element
     * boxed — otherwise the reader unboxes a raw string pointer by tag.
     */
    private function emitCellifyArrayRaw(Type $elem): string
    {
        return $this->emitAssocToCellArrayUnified($elem, true);
    }

    /**
     * The REVERSE of {@see emitAssocToCellArrayUnified}: rebuild a cell-repr
     * array (its values boxed cells) into a fresh CONCRETE array of `$arrType`
     * — each value UNBOXED to the target element's raw representation, keys
     * preserved. Used at a store where a cell-element array is bound to a slot
     * whose declared type is a concrete-element array (the de-cellify boundary —
     * e.g. `uasort`'s `$arr = $new` writeback restoring the byref param's typed
     * representation). lastValue holds the source array cell/ptr on entry; the
     * boxed concrete array on exit.
     */
    private function emitCellArrayToTyped(Type $arrType): string
    {
        $this->rt->needsTagged = true;
        $this->rt->needsCellKey = true;
        $elem = $arrType->element ?? Type::unknown();
        $isAssoc = $arrType->isAssoc();
        $out = $this->coerceToPtr();
        $rawSrc = $this->lastValue;
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->ssa->allocReg();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = load i64, ptr ' . $src . "\n";
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->ssa->allocReg();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $len . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->ssa->allocReg();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->ssa->allocLabel('uct.cond');
        $body = $this->ssa->allocLabel('uct.body');
        $end  = $this->ssa->allocLabel('uct.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        // Unbox the boxed cell value to the element's raw representation.
        $raw = $this->ssa->allocReg();
        if ($elem->kind === Type::KIND_ARRAY && ($elem->element->kind ?? '') !== Type::KIND_CELL) {
            // Nested concrete array: recursively de-cellify.
            $this->lastValue = $ev;
            $this->lastValueType = 'i64';
            $out .= $this->emitCellArrayToTyped($elem);
            $raw = $this->lastValue;
        } else {
            $this->lastValue = $ev;
            $this->lastValueType = 'i64';
            $out .= $this->unboxCellToType($elem);
            if ($this->lastValueType === 'double') {
                $bc = $this->ssa->allocReg();
                $out .= '  ' . $bc . ' = bitcast double ' . $this->lastValue . " to i64\n";
                $raw = $bc;
            } else {
                $raw = $this->lastValue;
            }
        }
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->ssa->allocReg();
        if ($isAssoc) {
            $kb = $this->ssa->allocReg();
            $out .= '  ' . $kb . ' = call i64 @__mir_array_key_cell_at(ptr ' . $src . ', i64 ' . $i . ")\n";
            $out .= '  ' . $nx . ' = call ptr @__mir_array_set_cell(ptr ' . $cur . ', i64 ' . $kb . ', i64 ' . $raw . ")\n";
        } else {
            $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $raw . ")\n";
        }
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->ssa->allocReg();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->ssa->allocReg();
        $out .= '  ' . $dst . ' = load ptr, ptr ' . $slot . "\n";
        // Leave the RAW array pointer (concrete-array slot repr — arrays pass
        // raw), NOT a boxed array cell.
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = ptrtoint ptr ' . $dst . " to i64\n";
        return $this->finishI64($out, $r);
    }

    /** True when a store of a cell-element array into a `$slotType`-declared slot
     *  needs de-cellifying: the slot is a CONCRETE-element array (scalar/obj/
     *  nested-concrete element) while the value carries boxed cells. */
    private function needsDeCellify(Type $slotType, Type $valueType): bool
    {
        if (!$slotType->isArray() || !$valueType->isArray()) { return false; }
        $se = $slotType->element;
        $ve = $valueType->element;
        if ($se === null || $ve === null) { return false; }
        if ($ve->kind !== Type::KIND_CELL) { return false; }
        $sk = $se->kind;
        return $sk === Type::KIND_INT || $sk === Type::KIND_FLOAT
            || $sk === Type::KIND_STRING || $sk === Type::KIND_BOOL
            || $sk === Type::KIND_OBJ || $sk === Type::KIND_ARRAY;
    }

    /** A concrete OBJECT-element array being written back through a by-ref
     *  out-param whose element repr the `.sig` erased — the caller can only read
     *  cells, so box each element on store. Restricted to OBJ elements: a raw
     *  object pointer is the one concrete element the caller CANNOT interpret
     *  (it reads as a NaN-double). Scalar-element arrays round-trip raw and are
     *  left alone; a cell/unknown element is already self-describing. */
    private function needsRefOutCellify(Type $valueType): bool
    {
        if (!$valueType->isArray()) { return false; }
        $ve = $valueType->element;
        return $ve !== null && $ve->kind === Type::KIND_OBJ;
    }

    /**
     * The debug-backtrace builtin — a packed list of the active call frames'
     * NAMES, innermost first (from the runtime call-stack {@see needsBacktrace}).
     * V1 returns vec[string] of names (not PHP's assoc frames); count() and
     * `[0]` work. Reads @__mir_bt_depth / @__mir_bt_name filled by btPush.
     */
    private function biDebugBacktrace(): string
    {
        // Build the raw name + line vecs (innermost first), then hand them to
        // the prelude frame builder so the result matches getTrace()/PHP:
        // a list of {file, line, function[, class, type]} assoc frames.
        $out = $this->emitBtVec('@__mir_bt_name');
        $names = $this->lastValue;
        $out .= $this->emitBtVec('@__mir_bt_line');
        $lines = $this->lastValue;
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = ptrtoint ptr '
              . $this->strLitId($this->pool->intern($this->sourceFile)) . " to i64\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @manticore___mir_bt_frames(i64 '
              . $names . ', i64 ' . $lines . ', i64 ' . $fp . ")\n";
        return $this->finishI64($out, $r);
    }

    /** @param Node[] $args  NaN-boxing helper call: i64 -> i64. */
    private function biTaggedCall(string $helper, array $args): string
    {
        $this->rt->needsTagged = true;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $reg = $this->ssa->allocReg();
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
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $ptr . ", null\n";
        $safe = $this->ssa->allocReg();
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
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call ptr @__mir_str_new(ptr ' . $p . ', i64 ' . $n . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `ptr_offset(\Ffi\Ptr $p, int $n): \Ffi\Ptr` — the address $n bytes past
     * $p. The emitter-side counterpart of Ffi\Ptr::offset(): that method lives
     * in src/Ffi, which is outside the src/Runtime tree the stdlib library is
     * built from, so stdlib code cannot call it. Needed to reach an interior C
     * struct member (utsname.machine, dirent.d_name). Unsafe: no bounds check.
     * @param Node[] $args
     */
    /**
     * `int_to_ptr(int $addr): \Ffi\Ptr` — a raw address as a Ptr handle.
     * The inverse of reading `Ffi\Ptr::$address`, for a C struct member that is
     * ITSELF a pointer: glob_t.gl_pathv is a `char **`, so walking it means
     * turning two levels of i64 back into handles. ptr_offset cannot do this —
     * it only moves within a handle you already hold.
     * A Ptr is an i64 at runtime, so this is a representation-level no-op.
     * Unsafe: the address is not validated.
     * @param Node[] $args
     */
    /**
     * `ptr_to_int(\Ffi\Ptr $p): int` — a Ptr's raw address. The mirror of
     * int_to_ptr, and the reason a `\Resource` can hold its backing handle as a
     * plain int: `Ffi\Ptr::$address` is not reachable from the stdlib (src/Ffi
     * is outside the src/Runtime tree the library is built from), and a
     * Ptr-typed property would drag the foreign-handle rc exclusions into every
     * path that touches the object.
     * A Ptr is an i64 at runtime, so this is a representation-level no-op.
     * @param Node[] $args
     */
    private function biPtrToInt(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $this->lastValueType = 'i64';
        return $out;
    }

    private function biIntToPtr(array $args): string
    {
        $out = $this->emitIntArg($args[0]);
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $this->lastValue . " to ptr\n";
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function biPtrOffset(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $p = $this->lastValue;
        $out .= $this->emitIntArg($args[1]);
        $off = $this->lastValue;
        $gp = $this->ssa->allocReg();
        $out .= '  ' . $gp . ' = getelementptr i8, ptr ' . $p . ', i64 ' . $off . "\n";
        $this->lastValue = $gp;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `peek_iN(\Ffi\Ptr $p, int $off): int` — load a native integer of $bits
     * width from a raw address at a byte offset, widened to i64 ($signed →
     * sext, else zext). The read boundary for FFI out-params, pointer-array
     * results (a PCRE2 ovector of size_t pairs) and C struct fields, whose
     * widths vary per member and per host (`struct stat`'s st_mode is 2 bytes
     * on Darwin and 4 on Linux). Unsafe: no bounds check.
     * @param Node[] $args
     */
    private function biPeek(array $args, int $bits, bool $signed): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $p = $this->lastValue;
        $out .= $this->emitIntArg($args[1]);
        $off = $this->lastValue;
        $gp = $this->ssa->allocReg();
        $out .= '  ' . $gp . ' = getelementptr i8, ptr ' . $p . ', i64 ' . $off . "\n";
        $ty = 'i' . $bits;
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load ' . $ty . ', ptr ' . $gp . "\n";
        if ($bits < 64) {
            $w = $this->ssa->allocReg();
            $out .= '  ' . $w . ' = ' . ($signed ? 'sext' : 'zext') . ' ' . $ty
                  . ' ' . $r . " to i64\n";
            $r = $w;
        }
        return $this->finishI64($out, $r);
    }

    /**
     * `poke_iN(\Ffi\Ptr $p, int $off, int $v): int` — store the low $bits of
     * $v at a byte offset. The write counterpart of {@see biPeek}: the only
     * way to build a C struct (timeval, sockaddr, pollfd) for an FFI call,
     * since the FFI wrapper passes scalars only. Yields 0. Unsafe: no bounds
     * check.
     * @param Node[] $args
     */
    private function biPoke(array $args, int $bits): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $p = $this->lastValue;
        $out .= $this->emitIntArg($args[1]);
        $off = $this->lastValue;
        $out .= $this->emitIntArg($args[2]);
        $v = $this->lastValue;
        $gp = $this->ssa->allocReg();
        $out .= '  ' . $gp . ' = getelementptr i8, ptr ' . $p . ', i64 ' . $off . "\n";
        $ty = 'i' . $bits;
        $sv = $v;
        if ($bits < 64) {
            $sv = $this->ssa->allocReg();
            $out .= '  ' . $sv . ' = trunc i64 ' . $v . ' to ' . $ty . "\n";
        }
        $out .= '  store ' . $ty . ' ' . $sv . ', ptr ' . $gp . "\n";
        return $this->finishI64($out, '0');
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
        $r = $this->ssa->allocReg();
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
        $this->rt->needsStdStreams = true;
        $r = $this->ssa->allocReg();
        $out = '  ' . $r . ' = call ptr @manticore_' . $stream . "()\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `__mc_errno()` → the current thread's errno as an int.
     *
     * errno is not a symbol — it is `*<accessor>()`, and the accessor is named per
     * host: Darwin `__error()`, glibc/musl `__errno_location()`, both `int*`.
     * Binding both by name fails AT LINK on whichever host lacks the other, so the
     * choice moves to runtime via `extern_weak`: declare BOTH (an absent one's
     * address is null in the loaded image), null-test `@__error`, and call whichever
     * is present. One binary, both hosts, no per-target build and no C shim — the
     * mechanism that also closes the stat/__xstat gap on glibc < 2.33.
     */
    private function biErrno(): string
    {
        $this->libcExtra['__error'] = 'declare extern_weak ptr @__error()';
        $this->libcExtra['__errno_location'] = 'declare extern_weak ptr @__errno_location()';
        $slot = $this->ssa->allocReg();
        $out = '  ' . $slot . " = alloca ptr\n";
        $has = $this->ssa->allocReg();
        $out .= '  ' . $has . " = icmp ne ptr @__error, null\n";
        $dar = $this->ssa->allocLabel('errno.darwin');
        $gli = $this->ssa->allocLabel('errno.glibc');
        $done = $this->ssa->allocLabel('errno.done');
        $out .= '  br i1 ' . $has . ', label %' . $dar . ', label %' . $gli . "\n";
        $out .= $dar . ":\n";
        $p1 = $this->ssa->allocReg();
        $out .= '  ' . $p1 . " = call ptr @__error()\n";
        $out .= '  store ptr ' . $p1 . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $done . "\n";
        $out .= $gli . ":\n";
        $p2 = $this->ssa->allocReg();
        $out .= '  ' . $p2 . " = call ptr @__errno_location()\n";
        $out .= '  store ptr ' . $p2 . ', ptr ' . $slot . "\n";
        $out .= '  br label %' . $done . "\n";
        $out .= $done . ":\n";
        $ep = $this->ssa->allocReg();
        $out .= '  ' . $ep . ' = load ptr, ptr ' . $slot . "\n";
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = load i32, ptr ' . $ep . "\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = sext i32 ' . $ev . " to i64\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
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
        $this->rt->needsCliArgv = true;
        $r = $this->ssa->allocReg();
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
        $this->rt->needsCliArgv = true;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $i = $this->lastValue;
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call ptr @manticore_cli_argv(i64 ' . $i . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `$s[$i]` read as a raw byte — what {@see Passes\DemoteCharLocals} rewrites a
     * character read to once it has proved the character is never observed AS a
     * string. Not user-callable: the pass synthesizes it.
     *
     * @param \Compile\Mir\Node[] $args
     */
    private function biStrByteAt(array $args): string
    {
        $out = $this->emitPtrArg($args[0]);
        $s = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToI64();
        $i = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mir_str_byte_at(ptr ' . $s
              . ', i64 ' . $i . ")\n";
        $out .= $this->freeStrTemp($args[0], $s);
        return $this->finishI64($out, $reg);
    }

    /** `$_ENV` / `$_SERVER` source: how many "KEY=VALUE" entries `environ` has. */
    private function biEnvCount(): string
    {
        $this->rt->needsEnviron = true;
        $r = $this->ssa->allocReg();
        $out = '  ' . $r . " = call i64 @manticore_env_count()\n";
        return $this->finishI64($out, $r);
    }

    /**
     * `__mir_env_at($i)` → the i-th raw "KEY=VALUE" C-string (no rc header);
     * the caller copies it via cstr_to_str. Bounded by __mir_env_count().
     * @param Node[] $args
     */
    private function biEnvAt(array $args): string
    {
        $this->rt->needsEnviron = true;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $i = $this->lastValue;
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call ptr @manticore_env_at(i64 ' . $i . ")\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `__mir_clock_ns($clock)` → nanoseconds off a POSIX clock. The argument is a
     * LOGICAL clock (0 wall-clock, else monotonic); the runtime wrapper maps it to
     * the host's own id. Deliberately NOT resolved here: `host_os()` rides the
     * libc uname/calloc bindings, whose bodies are empty under the Zend seed, so
     * an emitter that calls it crashes the cold bootstrap.
     * @param Node[] $args
     */
    private function biClockNs(array $args): string
    {
        $this->rt->needsClock = true;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $id = $this->lastValue;
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @manticore_clock_ns(i64 ' . $id . ")\n";
        return $this->finishI64($out, $r);
    }

    private function biStrlen(array $args): string
    {
        // Binary-safe O(1): the central reader loads len@-16 (null-safe). Every
        // `string` is headered by construction (raw buffers are \Ffi\Ptr and
        // reach a string only via str_from_buffer / cstr_to_str).
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->ssa->allocReg();
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
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $ptr . ", null\n";
        $safe = $this->ssa->allocReg();
        $out .= '  ' . $safe . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $ptr . "\n";
        $physlen = $this->ssa->allocReg();
        $out .= '  ' . $physlen . ' = load i64, ptr ' . $safe . "\n";
        // count() is the LIVE element count: physical entries minus the
        // tombstone counter in the flags word (bits 8+). A never-unset array
        // has 0 there, so count == physical len as before. The flags load off
        // the zero-word (null path) is same-page and its value is discarded by
        // the select — count of an empty array stays 0.
        $flp = $this->ssa->allocReg();
        $out .= '  ' . $flp . ' = getelementptr inbounds i8, ptr ' . $safe . ', i64 '
              . (string) \Compile\MemoryAbi::ARRAY_FLAGS_OFFSET . "\n";
        $flags = $this->ssa->allocReg();
        $out .= '  ' . $flags . ' = load i64, ptr ' . $flp . "\n";
        $tomb0 = $this->ssa->allocReg();
        $out .= '  ' . $tomb0 . ' = lshr i64 ' . $flags . ", 8\n";
        $tomb = $this->ssa->allocReg();
        $out .= '  ' . $tomb . ' = select i1 ' . $isNull . ', i64 0, i64 ' . $tomb0 . "\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = sub i64 ' . $physlen . ', ' . $tomb . "\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `array_first` / `array_last` (PHP 8.5) and `array_key_first` /
     * `array_key_last`: the first/last VALUE (or KEY) of `$a` as a tagged cell,
     * `null` (box_null) on an empty array. A codegen builtin — it sees the
     * concrete array type at the call site, so the returned VALUE is boxed by the
     * source's static element kind. An erased PHP-level helper would box every
     * element as int (the reason the stdlib `array_key_last` was omitted); here
     * KEY variants read `__mir_array_key_cell_at`, already a NaN-boxed int|string,
     * so the full int|string|null union renders correctly. Result type is
     * `mixed`/cell ({@see InferTypes::builtinReturnType}).
     *
     * @param Node[] $args
     */
    private function biArrayEndpoint(array $args, bool $last, bool $wantKey): string
    {
        $this->rt->needsTagged = true;
        if ($wantKey) { $this->rt->needsCellKey = true; }
        $arrT = $args[0]->type;
        $out = $this->emitNode($args[0]);
        if ($arrT->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        // Empty `[]` → null ptr; redirect to the zero-word so len reads 0
        // (mirrors biCount / biArrayKeys).
        $rawSrc = $this->lastValue;
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->ssa->allocReg();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = load i64, ptr ' . $src . "\n";
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca i64\n";
        $empty = $this->ssa->allocLabel('ae.empty');
        $take  = $this->ssa->allocLabel('ae.take');
        $done  = $this->ssa->allocLabel('ae.done');
        $isEmpty = $this->ssa->allocReg();
        $out .= '  ' . $isEmpty . ' = icmp eq i64 ' . $len . ", 0\n";
        $out .= '  br i1 ' . $isEmpty . ', label %' . $empty . ', label %' . $take . "\n";
        // Empty → null cell.
        $out .= $empty . ":\n";
        $bn = $this->ssa->allocReg();
        $out .= '  ' . $bn . " = call i64 @__manticore_box_null()\n";
        $out .= '  store i64 ' . $bn . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $done . "\n";
        // Non-empty → index 0 (first) or len-1 (last).
        $out .= $take . ":\n";
        if ($last) {
            $idx = $this->ssa->allocReg();
            $out .= '  ' . $idx . ' = sub i64 ' . $len . ", 1\n";
        } else {
            $idx = '0';
        }
        if ($wantKey) {
            $boxed = $this->ssa->allocReg();
            $out .= '  ' . $boxed . ' = call i64 @__mir_array_key_cell_at(ptr ' . $src . ', i64 ' . $idx . ")\n";
        } else {
            $ev = $this->ssa->allocReg();
            $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $src . ', i64 ' . $idx . ")\n";
            $out .= $this->boxRawElem($ev, $arrT);
            $boxed = $this->lastValue;
        }
        $out .= '  store i64 ' . $boxed . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $done . "\n";
        $out .= $done . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = load i64, ptr ' . $res . "\n";
        return $this->finishI64($out, $r);
    }

    /**
     * Box a raw element value (i64 SSA `$ev`, as stored by __mir_array_value_at)
     * into a tagged cell per the source array's STATIC element kind; lastValue ←
     * the boxed i64. An already-cell element (heterogeneous / `mixed` source)
     * passes through untouched. Mirrors the element switch in
     * {@see emitAssocToCellArrayUnified}.
     */
    private function boxRawElem(string $ev, Type $arrT): string
    {
        $elem = ($arrT->isVec() || $arrT->isAssoc()) ? $arrT->element : null;
        return $this->boxRawValue($ev, $elem);
    }

    /**
     * Box a raw i64 value (`$ev`) into a tagged cell per its static Type `$t`;
     * lastValue ← the boxed i64. A cell/unknown/null type (untyped or already-
     * boxed value) passes through untouched. Shared by array_first/last element
     * boxing and cell-receiver property reads.
     */
    private function boxRawValue(string $ev, ?Type $t): string
    {
        $ek = ($t !== null) ? $t->kind : Type::KIND_UNKNOWN;
        // Already a tagged cell (heterogeneous / `mixed` / untyped) — passthrough.
        if ($ek === Type::KIND_CELL || $ek === Type::KIND_UNKNOWN) {
            $this->lastValue = $ev;
            $this->lastValueType = 'i64';
            return '';
        }
        // An enum case is an ORDINAL, not an object cell — pass it through raw
        // so `->name` / `->value` dispatch via emitEnumProp (the caller types the
        // result as the enum). box_object would tag the ordinal as a pointer.
        if ($ek === Type::KIND_OBJ && $t->class !== null && isset($this->enums[$t->class])) {
            $this->lastValue = $ev;
            $this->lastValueType = 'i64';
            return '';
        }
        $elem = $t;
        $this->rt->needsTagged = true;
        $boxed = $this->ssa->allocReg();
        $out = '';
        if ($ek === Type::KIND_STRING) {
            $ep = $this->ssa->allocReg();
            $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_ptr(ptr ' . $ep . ")\n";
        } elseif ($ek === Type::KIND_FLOAT) {
            $ed = $this->ssa->allocReg();
            $out .= '  ' . $ed . ' = bitcast i64 ' . $ev . " to double\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_float(double ' . $ed . ")\n";
        } elseif ($ek === Type::KIND_BOOL) {
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_bool(i64 ' . $ev . ")\n";
        } elseif ($ek === Type::KIND_OBJ) {
            $ep = $this->ssa->allocReg();
            $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_object(ptr ' . $ep . ")\n";
        } elseif ($ek === Type::KIND_ARRAY) {
            $nestElem = $elem->element ?? Type::unknown();
            if ($nestElem->kind === Type::KIND_CELL || $nestElem->kind === Type::KIND_UNKNOWN) {
                // Inner array already cell-repr (or erased) — box as a plain
                // array cell (mirrors emitAssocToCellArrayUnified's array branch).
                $ep = $this->ssa->allocReg();
                $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
                $out .= '  ' . $boxed . ' = call i64 @__manticore_box_array(ptr ' . $ep . ")\n";
            } else {
                // Nested concrete array → recursively rebuild as a cell-array so
                // its own elements render (else var_dump reads raw ints as float).
                $this->lastValue = $ev;
                $this->lastValueType = 'i64';
                $out .= $elem->isAssoc()
                    ? $this->emitAssocToCellArrayUnified($nestElem)
                    : $this->emitVecToCellArrayUnified($nestElem);
                $boxed = $this->lastValue;
            }
        } else {
            $out .= '  ' . $boxed . ' = call i64 @__manticore_box_int(i64 ' . $ev . ")\n";
        }
        $this->lastValue = $boxed;
        $this->lastValueType = 'i64';
        return $out;
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
    /**
     * `array_keys($arr, $search_value, $strict)` — the keys whose value matches.
     * The all-keys form stays the inline builtin below; this form defers to the
     * stdlib `__mc_array_keys_search`, which does the element compare in PHP
     * (and so rides the same juggling table as `==` / `===`). `$strict` is always
     * passed explicitly, so no default padding is needed here.
     * @param Node[] $args
     */
    private function biArrayKeysSearch(array $args): string
    {
        // Declare the extern ONLY when this module does not itself define it (a
        // self-contained build embeds the define; a second declare is a
        // redefinition error). Same discipline as the decbin bridge.
        if (!isset($this->definedFns[$this->mangle('__mc_array_keys_search')])) {
            $this->libcExtra['manticore___mc_array_keys_search'] =
                'declare i64 @manticore___mc_array_keys_search(i64, i64, i64)';
        }
        $out = $this->emitNode($args[0]);
        // boxToCell rebuilds a RAW vec (vec[int]) into a cell vec — the callee
        // walks it as cells, so a raw buffer would be misread element by element.
        $out .= $this->boxToCell($args[0]->type);
        $arr = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->boxToCell($args[1]->type);
        $needle = $this->lastValue;
        $strict = '0';
        if (isset($args[2])) {
            $out .= $this->emitNode($args[2]);
            $out .= $this->coerceToI64();
            $strict = $this->lastValue;
        }
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @manticore___mc_array_keys_search(i64 ' . $arr
              . ', i64 ' . $needle . ', i64 ' . $strict . ")\n";
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $r . " to ptr\n";
        $this->lastValue = $p;
        $this->lastValueType = 'ptr';
        return $out;
    }

    private function biArrayKeys(array $args): string
    {
        $this->rt->needsTagged = true;
        $out = $this->emitNode($args[0]);
        if ($args[0]->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        // An empty `[]` literal lowers to a null ptr; redirect to the
        // zero-word so the length load reads 0 (mirrors biCount).
        $rawSrc = $this->lastValue;
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->ssa->allocReg();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        // live_len compacts out tombstones (null-safe on the raw ptr) so the
        // walk emits only live keys; the redirected %src is used only in the
        // loop body, which never runs when len==0 (the null case).
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = call i64 @__mir_array_live_len(ptr ' . $rawSrc . ")\n";
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->ssa->allocReg();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $len . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->ssa->allocReg();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->ssa->allocLabel('akeys.cond');
        $body = $this->ssa->allocLabel('akeys.body');
        $end  = $this->ssa->allocLabel('akeys.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $kb = $this->ssa->allocReg();
        $out .= '  ' . $kb . ' = call i64 @__mir_array_key_cell_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->ssa->allocReg();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $kb . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->ssa->allocReg();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->ssa->allocReg();
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
        $this->rt->needsTagged = true;
        $out = $this->emitNode($args[0]);
        if ($args[0]->type->kind === Type::KIND_CELL) {
            $out .= $this->cellToPtr();
        } else {
            $out .= $this->coerceToPtr();
        }
        // An empty `[]` literal lowers to a null ptr; redirect to the
        // zero-word so the length load reads 0 (mirrors biArrayKeys).
        $rawSrc = $this->lastValue;
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq ptr ' . $rawSrc . ", null\n";
        $src = $this->ssa->allocReg();
        $out .= '  ' . $src . ' = select i1 ' . $isNull
              . ', ptr @__mir_zero_word, ptr ' . $rawSrc . "\n";
        // live_len compacts out tombstones (null-safe on the raw ptr).
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = call i64 @__mir_array_live_len(ptr ' . $rawSrc . ")\n";
        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca ptr\n";
        $nv = $this->ssa->allocReg();
        $out .= '  ' . $nv . ' = call ptr @__mir_array_alloc(i64 ' . $len . ")\n";
        $out .= '  store ptr ' . $nv . ', ptr ' . $slot . "\n";
        $iSlot = $this->ssa->allocReg();
        $out .= '  ' . $iSlot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $iSlot . "\n";
        $cond = $this->ssa->allocLabel('avals.cond');
        $body = $this->ssa->allocLabel('avals.body');
        $end  = $this->ssa->allocLabel('avals.end');
        $out .= '  br label %' . $cond . "\n" . $cond . ":\n";
        $i = $this->ssa->allocReg();
        $out .= '  ' . $i . ' = load i64, ptr ' . $iSlot . "\n";
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp slt i64 ' . $i . ', ' . $len . "\n";
        $out .= '  br i1 ' . $c . ', label %' . $body . ', label %' . $end . "\n";
        $out .= $body . ":\n";
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = call i64 @__mir_array_value_at(ptr ' . $src . ', i64 ' . $i . ")\n";
        // A cell source ($boxElem null) and a CELL-element typed source already
        // carry cells; a typed source re-boxes each raw value per its kind.
        $bv = $ev;
        if ($boxElem !== null && $boxElem->kind !== Type::KIND_CELL) {
            $bv = $this->ssa->allocReg();
            $ek = $boxElem->kind;
            if ($ek === Type::KIND_STRING) {
                $ep = $this->ssa->allocReg();
                $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_ptr(ptr ' . $ep . ")\n";
            } elseif ($ek === Type::KIND_FLOAT) {
                $ed = $this->ssa->allocReg();
                $out .= '  ' . $ed . ' = bitcast i64 ' . $ev . " to double\n";
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_float(double ' . $ed . ")\n";
            } elseif ($ek === Type::KIND_BOOL) {
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_bool(i64 ' . $ev . ")\n";
            } elseif ($ek === Type::KIND_OBJ) {
                $ep = $this->ssa->allocReg();
                $out .= '  ' . $ep . ' = inttoptr i64 ' . $ev . " to ptr\n";
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_object(ptr ' . $ep . ")\n";
            } else {
                $out .= '  ' . $bv . ' = call i64 @__manticore_box_int(i64 ' . $ev . ")\n";
            }
        }
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = load ptr, ptr ' . $slot . "\n";
        $nx = $this->ssa->allocReg();
        $out .= '  ' . $nx . ' = call ptr @__mir_array_append(ptr ' . $cur . ', i64 ' . $bv . ")\n";
        $out .= '  store ptr ' . $nx . ', ptr ' . $slot . "\n";
        $i2 = $this->ssa->allocReg();
        $out .= '  ' . $i2 . ' = add i64 ' . $i . ", 1\n";
        $out .= '  store i64 ' . $i2 . ', ptr ' . $iSlot . "\n";
        $out .= '  br label %' . $cond . "\n" . $end . ":\n";
        $dst = $this->ssa->allocReg();
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
        $byte = $this->ssa->allocReg();
        $out .= '  ' . $byte . ' = load i8, ptr ' . $a0 . "\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = zext i8 ' . $byte . " to i64\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        return $this->finishI64($out, $reg);
    }

    /** @param Node[] $args */
    private function biChr(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $t = $this->ssa->allocReg();
        $out .= '  ' . $t . ' = trunc i64 ' . $this->lastValue . " to i8\n";
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 2)\n";
        $out .= '  store i8 ' . $t . ', ptr ' . $buf . "\n";
        $nul = $this->ssa->allocReg();
        $out .= '  ' . $nul . ' = getelementptr inbounds i8, ptr ' . $buf . ", i64 1\n";
        $out .= '  store i8 0, ptr ' . $nul . "\n";
        $this->lastValue = $buf; $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Coerce a just-emitted BUILTIN ARGUMENT to i64.
     *
     * `coerceToI64()` decides from the LLVM type of the value, which cannot
     * tell an i64 holding an int from an i64 holding a TAGGED CELL. So a
     * numericCell argument — anything typed `?int`/`int|float`, e.g. the
     * `$x = $t === null ? 0 : $t;` an optional-parameter idiom produces —
     * reached the arithmetic as its raw tag bits, and intdiv/abs/pow/dechex/
     * str_repeat/strpos/explode/strcspn silently computed on nonsense
     * (str_repeat SIGSEGV'd on the bogus count).
     *
     * Arithmetic OPERATORS never had this bug because they route operands
     * through `coerceArithOperand`, which unboxes a cell (and PHP-coerces a
     * numeric string). Builtins now share exactly that path.
     */
    private function coerceIntArg(Node $arg): string
    {
        return $this->coerceArithOperand($arg, false);
    }

    /** @param Node[] $args */
    private function biAbs(array $args): string
    {
        $isFloat = $args[0]->type->kind === Type::KIND_FLOAT;
        $out = $this->emitNode($args[0]);
        if ($isFloat) {
            $this->libcExtra['llvm.fabs.f64'] = 'declare double @llvm.fabs.f64(double)';
            $out .= $this->coerceTo('double');
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call double @llvm.fabs.f64(double ' . $this->lastValue . ")\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        // A CELL (`?float` / `float|false`) keeps its int-or-float nature only
        // at runtime — the int path below would read a boxed double's bits as an
        // int. Dispatch by tag: fabs on a float payload, integer abs on an int,
        // re-boxing each so the result is a numeric cell (see InferCalls abs).
        if ($args[0]->type->kind === Type::KIND_CELL) {
            $this->rt->needsTagged = true;
            $this->rt->needsTaggedToFloat = true;
            $this->rt->needsTaggedToInt = true;
            $this->rt->needsStrtod = true;
            $this->rt->needsStrtol = true;
            $this->libcExtra['llvm.fabs.f64'] = 'declare double @llvm.fabs.f64(double)';
            $out .= $this->coerceToI64();
            $cell = $this->lastValue;
            $slot = $this->ssa->allocReg();
            $out .= '  ' . $slot . " = alloca i64\n";
            $out .= $this->cellTagIr($cell);
            $tag = $this->cellTagReg;
            $isFloat = $this->ssa->allocReg();
            $out .= '  ' . $isFloat . ' = icmp eq i64 ' . $tag . ", 6\n";
            $fL = $this->ssa->allocLabel('abs.f');
            $iL = $this->ssa->allocLabel('abs.i');
            $dL = $this->ssa->allocLabel('abs.done');
            $out .= '  br i1 ' . $isFloat . ', label %' . $fL . ', label %' . $iL . "\n";
            $out .= $fL . ":\n";
            $fd = $this->ssa->allocReg();
            $out .= '  ' . $fd . ' = call double @__manticore_tagged_to_double(i64 ' . $cell . ")\n";
            $fa = $this->ssa->allocReg();
            $out .= '  ' . $fa . ' = call double @llvm.fabs.f64(double ' . $fd . ")\n";
            $this->lastValue = $fa; $this->lastValueType = 'double';
            $out .= $this->boxToCell(Type::float_());
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
            $out .= '  br label %' . $dL . "\n";
            $out .= $iL . ":\n";
            $iv = $this->ssa->allocReg();
            $out .= '  ' . $iv . ' = call i64 @__manticore_tagged_to_int(i64 ' . $cell . ")\n";
            $ineg = $this->ssa->allocReg();
            $out .= '  ' . $ineg . ' = sub i64 0, ' . $iv . "\n";
            $ilt = $this->ssa->allocReg();
            $out .= '  ' . $ilt . ' = icmp slt i64 ' . $iv . ", 0\n";
            $ia = $this->ssa->allocReg();
            $out .= '  ' . $ia . ' = select i1 ' . $ilt . ', i64 ' . $ineg . ', i64 ' . $iv . "\n";
            $this->lastValue = $ia; $this->lastValueType = 'i64';
            $out .= $this->boxToCell(Type::int_());
            $out .= '  store i64 ' . $this->lastValue . ', ptr ' . $slot . "\n";
            $out .= '  br label %' . $dL . "\n";
            $out .= $dL . ":\n";
            $rv = $this->ssa->allocReg();
            $out .= '  ' . $rv . ' = load i64, ptr ' . $slot . "\n";
            $this->lastValue = $rv; $this->lastValueType = 'i64';
            return $out;
        }
        $out .= $this->coerceIntArg($args[0]);
        $v = $this->lastValue;
        $neg = $this->ssa->allocReg();
        $out .= '  ' . $neg . ' = sub i64 0, ' . $v . "\n";
        $isNeg = $this->ssa->allocReg();
        $out .= '  ' . $isNeg . ' = icmp slt i64 ' . $v . ", 0\n";
        $reg = $this->ssa->allocReg();
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
        $out = $this->emitNode($args[0]); $out .= $this->coerceIntArg($args[0]); $a = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceIntArg($args[1]); $b = $this->lastValue;
        $reg = $this->ssa->allocReg();
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
            $this->rt->needsIpow = true;
            $out = $this->emitNode($args[0]); $out .= $this->coerceIntArg($args[0]); $b = $this->lastValue;
            $out .= $this->emitNode($args[1]); $out .= $this->coerceIntArg($args[1]); $e = $this->lastValue;
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 @__mir_ipow(i64 ' . $b . ', i64 ' . $e . ")\n";
            return $this->finishI64($out, $reg);
        }
        // A numericCell operand lands here (it is not KIND_INT), so the double
        // conversion must UNBOX it via tagged_to_double rather than bitcast its
        // tagged bits — coerceDoubleOperand, the same path float arithmetic uses.
        $this->libcExtra['llvm.pow.f64'] = 'declare double @llvm.pow.f64(double, double)';
        $out = $this->emitNode($args[0]); $out .= $this->coerceDoubleOperand($args[0]); $b = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceDoubleOperand($args[1]); $e = $this->lastValue;
        $reg = $this->ssa->allocReg();
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
        // coerceDoubleOperand (not coerceTo('double')): a CELL arg (a
        // `float|false`/`?float` value) must be tag-decoded via tagged_to_double,
        // not sitofp'd — the raw NaN-boxed carrier is a garbage integer.
        $out .= $this->coerceDoubleOperand($args[0]);
        $reg = $this->ssa->allocReg();
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
        $out .= $this->coerceDoubleOperand($args[0]);
        $x = $this->lastValue;
        if (\count($args) < 2) {
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call double @llvm.round.f64(double ' . $x . ")\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        $this->libcExtra['llvm.pow.f64'] = 'declare double @llvm.pow.f64(double, double)';
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceDoubleOperand($args[1]);
        $p = $this->lastValue;
        $scale = $this->ssa->allocReg();
        $out .= '  ' . $scale . ' = call double @llvm.pow.f64(double 1.000000e+01, double ' . $p . ")\n";
        $scaled = $this->ssa->allocReg();
        $out .= '  ' . $scaled . ' = fmul double ' . $x . ', ' . $scale . "\n";
        // Pre-round the scaled value at 15 significant digits (snprintf+strtod)
        // to cancel the binary representation error before the final round —
        // PHP's php_round pre-rounding, so round(1.005, 2) → 1.01 not 1.
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $this->libcExtra['strtod'] = 'declare double @strtod(ptr, ptr)';
        $pbuf = $this->ssa->allocReg();
        $out .= '  ' . $pbuf . " = alloca [40 x i8]\n";
        $out .= '  call i32 (ptr, i64, ptr, ...) @snprintf(ptr ' . $pbuf
              . ', i64 40, ptr @.fmt.p15, double ' . $scaled . ")\n";
        $cleaned = $this->ssa->allocReg();
        $out .= '  ' . $cleaned . ' = call double @strtod(ptr ' . $pbuf . ", ptr null)\n";
        $rounded = $this->ssa->allocReg();
        $out .= '  ' . $rounded . ' = call double @llvm.round.f64(double ' . $cleaned . ")\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = fdiv double ' . $rounded . ', ' . $scale . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `fmod($x, $y)` — float remainder via the LLVM `frem` instruction.
     *  @param Node[] $args */
    private function biFmod(array $args): string
    {
        $out = $this->emitNode($args[0]); $out .= $this->coerceDoubleOperand($args[0]); $x = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceDoubleOperand($args[1]); $y = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = frem double ' . $x . ', ' . $y . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** Two-arg float function (`atan2`, `hypot`) → `double NAME(double, double)`.
     * @param Node[] $args */
    private function biFloatBinary(array $args, string $fn): string
    {
        $this->libcExtra[$fn] = 'declare double @' . $fn . '(double, double)';
        $out = $this->emitNode($args[0]); $out .= $this->coerceDoubleOperand($args[0]); $x = $this->lastValue;
        $out .= $this->emitNode($args[1]); $out .= $this->coerceDoubleOperand($args[1]); $y = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call double @' . $fn . '(double ' . $x . ', double ' . $y . ")\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `log($x)` natural log; `log($x, $base)` = log(x)/log(base). */
    private function biLog(array $args): string
    {
        $this->libcExtra['llvm.log.f64'] = 'declare double @llvm.log.f64(double)';
        $out = $this->emitNode($args[0]); $out .= $this->coerceDoubleOperand($args[0]); $x = $this->lastValue;
        $lx = $this->ssa->allocReg();
        $out .= '  ' . $lx . ' = call double @llvm.log.f64(double ' . $x . ")\n";
        if (\count($args) < 2) {
            $this->lastValue = $lx; $this->lastValueType = 'double';
            return $out;
        }
        $out .= $this->emitNode($args[1]); $out .= $this->coerceDoubleOperand($args[1]); $b = $this->lastValue;
        $lb = $this->ssa->allocReg();
        $out .= '  ' . $lb . ' = call double @llvm.log.f64(double ' . $b . ")\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = fdiv double ' . $lx . ', ' . $lb . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `pi()` → the M_PI constant (no libm call). */
    private function biPi(): string
    {
        $reg = $this->ssa->allocReg();
        $out = '  ' . $reg . ' = fadd double 0x400921FB54442D18, 0.000000e+00' . "\n";
        $this->lastValue = $reg; $this->lastValueType = 'double';
        return $out;
    }

    /** `deg2rad`/`rad2deg` — multiply by a constant (pi/180 or 180/pi).
     * @param Node[] $args */
    private function biFloatScale(array $args, string $hexConst): string
    {
        $out = $this->emitNode($args[0]); $out .= $this->coerceDoubleOperand($args[0]); $x = $this->lastValue;
        $reg = $this->ssa->allocReg();
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
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = fptosi double ' . $this->lastValue . " to i64\n";
            return $this->finishI64($out, $reg);
        }
        if ($ok === Type::KIND_STRING) {
            $this->rt->needsStrtol = true;
            $out .= $this->coerceToPtr();
            $strPtr = $this->lastValue;
            // `intval($s, $base)` — strtol handles every base natively (2/8/16,
            // and base 0 = auto-detect `0x`/`0` prefixes), matching PHP. Default
            // base 10 when the arg is omitted.
            $baseArg = 'i32 10';
            if (\count($args) > 1) {
                $out .= $this->emitNode($args[1]);
                $out .= $this->coerceToI64();
                $b32 = $this->ssa->allocReg();
                $out .= '  ' . $b32 . ' = trunc i64 ' . $this->lastValue . " to i32\n";
                $baseArg = 'i32 ' . $b32;
            }
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 @strtol(ptr ' . $strPtr . ', ptr null, ' . $baseArg . ")\n";
            return $this->finishI64($out, $reg);
        }
        // A CELL / UNKNOWN (a `mixed` arg) must be DECODED by tag, not read raw:
        // the i64 carrier is a NaN-boxed value, so coerceToI64 alone would return
        // the tagged bits. `__manticore_tagged_to_int` dispatches int/float/bool/
        // string (a string cell parses via strtol, hence needsStrtol) — the int
        // mirror of biFloatval's tagged_to_double arm.
        if ($ok === Type::KIND_CELL || $ok === Type::KIND_UNKNOWN) {
            $this->rt->needsTaggedToInt = true;
            $this->rt->needsStrtol = true;
            $out .= $this->coerceToI64();
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call i64 @__manticore_tagged_to_int(i64 ' . $this->lastValue . ")\n";
            return $this->finishI64($out, $reg);
        }
        $out .= $this->coerceToI64();
        return $this->finishI64($out, $this->lastValue);
    }

    /** @param Node[] $args */
    private function biFloatval(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $ak = $args[0]->type->kind;
        if ($ak === Type::KIND_FLOAT) {
            $out .= $this->coerceTo('double');
            $this->lastValueType = 'double';
            return $out;
        }
        // A STRING parses via strtod (`floatval("1.5")` → 1.5) — sitofp of the
        // raw string POINTER would hand back garbage. A CELL/UNKNOWN routes
        // through the tagged decoder (which itself strtod's a string cell).
        if ($ak === Type::KIND_STRING) {
            $this->libcExtra['strtod'] = 'declare double @strtod(ptr, ptr)';
            $out .= $this->coerceToPtr();
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call double @strtod(ptr ' . $this->lastValue . ", ptr null)\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        if ($ak === Type::KIND_CELL || $ak === Type::KIND_UNKNOWN) {
            $this->rt->needsTaggedToFloat = true;
            $out .= $this->coerceToI64();
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $reg; $this->lastValueType = 'double';
            return $out;
        }
        $out .= $this->coerceToI64();
        $reg = $this->ssa->allocReg();
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
        return $this->strLitId($this->pool->intern($s));
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

    /** Data ptr (`global + STRING_HEADER_SIZE`) for any headered string-literal symbol. */
    private function strSymBytes(string $sym): string
    {
        return 'getelementptr inbounds (i8, ptr ' . $sym
            . ', i64 ' . (string)\Compile\MemoryAbi::STRING_HEADER_SIZE . ')';
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
        // Header [hash, cap, len, rc]; hash is the compile-time FNV of the
        // content (bit-matches __mir_array_hash_str), so a literal map key never
        // hashes at runtime. rc -1 = immortal. Data at +STRING_HEADER_SIZE.
        $hash = $this->fnvHash64($value);
        return $sym . ' = private unnamed_addr constant { i64, i64, i64, i64, ['
            . (string)$len . ' x i8] } { i64 ' . (string)$hash . ', i64 '
            . (string)$content . ', i64 ' . (string)$content . ', i64 -1, ['
            . (string)$len . ' x i8] c"' . $bytes . '\\00" }, align 8' . "\n";
    }

    /**
     * is_int / is_string / … — runtime tag compare on a tagged cell
     * arg, else a compile-time constant from the static type.
     * @param Node[] $args
     */
    /** `is_numeric($v)` — int/float → true; a string → numeric-format check
     *  (`__mir_is_numeric_str`); a cell dispatches on its tag; else false.
     *  @param Node[] $args */
    private function biIsNumeric(array $args): string
    {
        $a = $args[0];
        $k = $a->type->kind;
        if ($k === Type::KIND_INT || $k === Type::KIND_FLOAT) {
            $this->lastValue = '1'; $this->lastValueType = 'i64'; return '';
        }
        if ($k === Type::KIND_STRING) {
            $this->rt->needsTaggedEq = true; // emits __mir_is_numeric_str
            $out = $this->emitNode($a);
            $out .= $this->coerceToPtr();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i1 @__mir_is_numeric_str(ptr ' . $this->lastValue . ")\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $r . " to i64\n";
            return $this->finishI64($out, $z);
        }
        if ($k === Type::KIND_CELL) {
            $this->rt->needsTagged = true;
            $this->rt->needsTaggedEq = true;
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $v = $this->lastValue;
            $tg = $this->ssa->allocReg();
            $out .= '  ' . $tg . ' = call i64 @__manticore_tag(i64 ' . $v . ")\n";
            $isI = $this->ssa->allocReg();
            $out .= '  ' . $isI . ' = icmp eq i64 ' . $tg . ", 1\n";
            $isF = $this->ssa->allocReg();
            $out .= '  ' . $isF . ' = icmp eq i64 ' . $tg . ", 6\n";
            $isNum = $this->ssa->allocReg();
            $out .= '  ' . $isNum . ' = or i1 ' . $isI . ', ' . $isF . "\n";
            $isS = $this->ssa->allocReg();
            $out .= '  ' . $isS . ' = icmp eq i64 ' . $tg . ", 4\n";
            $sp = $this->ssa->allocReg();
            $out .= '  ' . $sp . ' = and i64 ' . $v . ", 281474976710655\n";
            $spp = $this->ssa->allocReg();
            $out .= '  ' . $spp . ' = inttoptr i64 ' . $sp . " to ptr\n";
            $sn = $this->ssa->allocReg();
            $out .= '  ' . $sn . ' = call i1 @__mir_is_numeric_str(ptr ' . $spp . ")\n";
            $strNum = $this->ssa->allocReg();
            $out .= '  ' . $strNum . ' = and i1 ' . $isS . ', ' . $sn . "\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = or i1 ' . $isNum . ', ' . $strNum . "\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $r . " to i64\n";
            return $this->finishI64($out, $z);
        }
        $this->lastValue = '0'; $this->lastValueType = 'i64'; return '';
    }

    private function biIsType(array $args, int $wantTag, string $kind): string
    {
        $a = $args[0];
        if ($a->type->kind === Type::KIND_CELL) {
            $this->rt->needsTagged = true;
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $tg = $this->ssa->allocReg();
            $out .= '  ' . $tg . ' = call i64 @__manticore_tag(i64 ' . $this->lastValue . ")\n";
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i64 ' . $tg . ', ' . (string)$wantTag . "\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $eq . " to i64\n";
            return $this->finishI64($out, $z);
        }
        // An object/closure value can be a null (0) pointer at runtime — a plain
        // ternary null arm keeps the obj type (`$c ? new P() : null`), so both
        // is_null and is_object must runtime-check the pointer instead of
        // short-circuiting on the static obj type (which would answer null=never,
        // object=always). is_null → ptr==0; is_object → ptr!=0.
        if (($a->type->kind === Type::KIND_OBJ || $a->type->kind === Type::KIND_CLOSURE)
            && ($kind === Type::KIND_NULL || $kind === Type::KIND_OBJ)) {
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $pred = $kind === Type::KIND_NULL ? 'eq' : 'ne';
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = icmp ' . $pred . ' i64 ' . $this->lastValue . ", 0\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $cmp . " to i64\n";
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
            $this->rt->needsTagged = true;
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $tg = $this->ssa->allocReg();
            $out .= '  ' . $tg . ' = call i64 @__manticore_tag(i64 ' . $this->lastValue . ")\n";
            $e1 = $this->ssa->allocReg(); $out .= '  ' . $e1 . ' = icmp eq i64 ' . $tg . ", 1\n";
            $e2 = $this->ssa->allocReg(); $out .= '  ' . $e2 . ' = icmp eq i64 ' . $tg . ", 2\n";
            $e3 = $this->ssa->allocReg(); $out .= '  ' . $e3 . ' = icmp eq i64 ' . $tg . ", 3\n";
            $e4 = $this->ssa->allocReg(); $out .= '  ' . $e4 . ' = icmp eq i64 ' . $tg . ", 4\n";
            $e6 = $this->ssa->allocReg(); $out .= '  ' . $e6 . ' = icmp eq i64 ' . $tg . ", 6\n";
            $e7 = $this->ssa->allocReg(); $out .= '  ' . $e7 . ' = icmp eq i64 ' . $tg . ", 7\n";
            $e8 = $this->ssa->allocReg(); $out .= '  ' . $e8 . ' = icmp eq i64 ' . $tg . ", 8\n";
            // The object arm goes through a prelude fn: a \Resource is an object
            // to us but "resource" to php, and only a runtime CLASS check can
            // tell them apart — a tag-select chain cannot. Called unconditionally
            // (select evaluates both arms), which is safe: instanceof on a
            // non-object cell is false and the answer falls back to "object".
            $objName = $this->ssa->allocReg();
            $out .= '  ' . $objName . ' = call i64 @manticore___mir_obj_type_name(i64 '
                  . $this->lastValue . ', i64 ' . ($debug ? '1' : '0') . ")\n";
            $objPtr = $this->ssa->allocReg();
            $out .= '  ' . $objPtr . ' = inttoptr i64 ' . $objName . " to ptr\n";
            $r8 = $this->ssa->allocReg(); $out .= '  ' . $r8 . ' = select i1 ' . $e8 . ', ptr ' . $objPtr . ', ptr ' . $this->strRef($nUnk) . "\n";
            $r7 = $this->ssa->allocReg(); $out .= '  ' . $r7 . ' = select i1 ' . $e7 . ', ptr ' . $this->strRef($nArr) . ', ptr ' . $r8 . "\n";
            $r6 = $this->ssa->allocReg(); $out .= '  ' . $r6 . ' = select i1 ' . $e6 . ', ptr ' . $this->strRef($nFloat) . ', ptr ' . $r7 . "\n";
            $r4 = $this->ssa->allocReg(); $out .= '  ' . $r4 . ' = select i1 ' . $e4 . ', ptr ' . $this->strRef($nStr) . ', ptr ' . $r6 . "\n";
            $r3 = $this->ssa->allocReg(); $out .= '  ' . $r3 . ' = select i1 ' . $e3 . ', ptr ' . $this->strRef($nNull) . ', ptr ' . $r4 . "\n";
            $r2 = $this->ssa->allocReg(); $out .= '  ' . $r2 . ' = select i1 ' . $e2 . ', ptr ' . $this->strRef($nBool) . ', ptr ' . $r3 . "\n";
            $r1 = $this->ssa->allocReg(); $out .= '  ' . $r1 . ' = select i1 ' . $e1 . ', ptr ' . $this->strRef($nInt) . ', ptr ' . $r2 . "\n";
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
        // An obj value may be a null (0) pointer at runtime (a plain-ternary null
        // arm keeps the obj type) — runtime-select "NULL" over the object name.
        elseif ($k === Type::KIND_OBJ) {
            $objName = $debug && ($a->type->class ?? '') !== '' ? $a->type->class : $nObj;
            // A statically-typed \Resource: the class is known here, so fold the
            // name rather than call the prelude helper — the helper would have to
            // run unconditionally under the null-select below and would deref a
            // null receiver. Cost of folding: `closed` is a RUNTIME flag, so a
            // closed handle reports "resource"/"resource (stream)" where php says
            // "resource (closed)". The cell path — which is what `fopen(): \Resource|false`
            // actually produces — reads the flag and gets it exact.
            if (($a->type->class ?? '') === 'Resource') {
                $objName = $debug ? 'resource (stream)' : 'resource';
            }
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $isN = $this->ssa->allocReg();
            $out .= '  ' . $isN . ' = icmp eq i64 ' . $this->lastValue . ", 0\n";
            $sel = $this->ssa->allocReg();
            $out .= '  ' . $sel . ' = select i1 ' . $isN . ', ptr ' . $this->strRef($nNull) . ', ptr ' . $this->strRef($objName) . "\n";
            $this->lastValue = $sel;
            $this->lastValueType = 'ptr';
            return $out;
        }
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
        // Non-numeric operands: PHP orders strings and arrays too, and min/max
        // must hand back a value of that TYPE. The int path below unboxed an
        // array POINTER as an int, so `max([1,2],[1,3])` printed a raw address.
        // Arrays go through the chain-carrying array compare, NOT tagged_compare
        // — the latter's array arm assumes cell elements, and a `vec[int]` holds
        // raw ones. {@see InferCalls} min/max return type mirrors these rules.
        $allStr = true;
        $allArr = true;
        foreach ($args as $a) {
            if ($a->type->kind !== Type::KIND_STRING) { $allStr = false; }
            if ($a->type->kind !== Type::KIND_ARRAY)  { $allArr = false; }
        }
        $count = \count($args);
        // ONE array argument is the "max of its ELEMENTS" form (`max([1,2,3])`
        // is 3, not the array). The numeric paths below unboxed the array
        // POINTER as an int and printed a raw address; defer to the stdlib fold,
        // which compares the elements with `<` / `>` and so rides the same table.
        if ($count === 1 && $args[0]->type->kind === Type::KIND_ARRAY) {
            if (!isset($this->definedFns[$this->mangle('__mc_minmax_of')])) {
                $this->libcExtra['manticore___mc_minmax_of'] =
                    'declare i64 @manticore___mc_minmax_of(i64, i64)';
            }
            $out = $this->emitNode($args[0]);
            $out .= $this->boxToCell($args[0]->type);
            $arr = $this->lastValue;
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i64 @manticore___mc_minmax_of(i64 ' . $arr
                  . ', i64 ' . ($pred === 'sgt' ? '1' : '0') . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Two or more operands compare against each other.
        if ($allArr && $count >= 2) {
            $chains = [];
            $chainsOk = true;
            foreach ($args as $a) {
                $ch = $this->elemChainOf($a->type->element);
                if ($ch === self::EK_NONE) { $chainsOk = false; break; }
                $chains[] = $ch;
            }
            if ($chainsOk) {
                $this->rt->needsTaggedCompare = true;
                $fpred = $pred === 'sgt' ? 'sgt' : 'slt';
                $out = $this->emitNode($args[0]);
                $out .= $this->coerceToPtr();
                $acc = $this->lastValue;
                $accChain = $chains[0];
                for ($i = 1; $i < $count; $i = $i + 1) {
                    $out .= $this->emitNode($args[$i]);
                    $out .= $this->coerceToPtr();
                    $v = $this->lastValue;
                    $c = $this->ssa->allocReg();
                    $out .= '  ' . $c . ' = call i64 @__mir_array_compare(ptr ' . $v . ', i64 ' . $chains[$i]
                          . ', ptr ' . $acc . ', i64 ' . $accChain . ")\n";
                    $cmp = $this->ssa->allocReg();
                    $out .= '  ' . $cmp . ' = icmp ' . $fpred . ' i64 ' . $c . ", 0\n";
                    $sel = $this->ssa->allocReg();
                    $out .= '  ' . $sel . ' = select i1 ' . $cmp . ', ptr ' . $v . ', ptr ' . $acc . "\n";
                    $acc = $sel;
                }
                $this->lastValue = $acc;
                $this->lastValueType = 'ptr';
                return $out;
            }
        }
        if ($allStr && $count >= 2) {
            $this->rt->needsTaggedCompare = true;
            $fpred = $pred === 'sgt' ? 'sgt' : 'slt';
            $out = $this->emitNode($args[0]);
            $out .= $this->shallowBoxToCell($args[0]->type);
            $acc = $this->lastValue;
            for ($i = 1; $i < $count; $i = $i + 1) {
                $out .= $this->emitNode($args[$i]);
                $out .= $this->shallowBoxToCell($args[$i]->type);
                $v = $this->lastValue;
                $c = $this->ssa->allocReg();
                $out .= '  ' . $c . ' = call i64 @__manticore_tagged_compare(i64 ' . $v . ', i64 ' . $acc . ")\n";
                $cmp = $this->ssa->allocReg();
                $out .= '  ' . $cmp . ' = icmp ' . $fpred . ' i64 ' . $c . ", 0\n";
                $sel = $this->ssa->allocReg();
                $out .= '  ' . $sel . ' = select i1 ' . $cmp . ', i64 ' . $v . ', i64 ' . $acc . "\n";
                $acc = $sel;
            }
            // Unmask the winning cell back to the string pointer it boxes, so
            // the result stays a `string` rather than leaking a cell.
            $mp = $this->ssa->allocReg();
            $out .= '  ' . $mp . ' = and i64 ' . $acc . ", 281474976710655\n";
            $pp = $this->ssa->allocReg();
            $out .= '  ' . $pp . ' = inttoptr i64 ' . $mp . " to ptr\n";
            $this->lastValue = $pp;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $anyFloat = false;
        foreach ($args as $a) {
            if ($a->type->kind === Type::KIND_FLOAT) { $anyFloat = true; break; }
        }
        if ($anyFloat) {
            $this->rt->needsTagged = true;
            $this->rt->needsTaggedToFloat = true;
            $fpred = $pred === 'sgt' ? 'ogt' : 'olt';
            $out = $this->emitNode($args[0]);
            $out .= $this->boxToCell($args[0]->type);
            $acc = $this->lastValue;
            $accd = $this->ssa->allocReg();
            $out .= '  ' . $accd . ' = call double @__manticore_tagged_to_double(i64 ' . $acc . ")\n";
            $count = \count($args);
            for ($i = 1; $i < $count; $i = $i + 1) {
                $out .= $this->emitNode($args[$i]);
                $out .= $this->boxToCell($args[$i]->type);
                $v = $this->lastValue;
                $vd = $this->ssa->allocReg();
                $out .= '  ' . $vd . ' = call double @__manticore_tagged_to_double(i64 ' . $v . ")\n";
                $cmp = $this->ssa->allocReg();
                $out .= '  ' . $cmp . ' = fcmp ' . $fpred . ' double ' . $vd . ', ' . $accd . "\n";
                $sel = $this->ssa->allocReg();
                $out .= '  ' . $sel . ' = select i1 ' . $cmp . ', i64 ' . $v . ', i64 ' . $acc . "\n";
                $seld = $this->ssa->allocReg();
                $out .= '  ' . $seld . ' = select i1 ' . $cmp . ', double ' . $vd . ', double ' . $accd . "\n";
                $acc = $sel;
                $accd = $seld;
            }
            $this->lastValue = $acc;
            $this->lastValueType = 'i64';
            return $out;
        }
        // Integer compare. A CELL operand (e.g. a `?int`/numericCell arg like
        // `$offset + $length` in array_slice) carries its int NaN-boxed — its raw
        // i64 is meaningless in an icmp, so unbox it first (else min/max returns a
        // boxed cell read back as a garbage negative). `finishI64` keeps the
        // result a plain int (all-int path — no float operand).
        $out = $this->emitNode($args[0]);
        $out .= $this->minMaxOperandI64($args[0]);
        $acc = $this->lastValue;
        $count = \count($args);
        for ($i = 1; $i < $count; $i = $i + 1) {
            $out .= $this->emitNode($args[$i]);
            $out .= $this->minMaxOperandI64($args[$i]);
            $v = $this->lastValue;
            $cmp = $this->ssa->allocReg();
            $out .= '  ' . $cmp . ' = icmp ' . $pred . ' i64 ' . $v . ', ' . $acc . "\n";
            $sel = $this->ssa->allocReg();
            $out .= '  ' . $sel . ' = select i1 ' . $cmp . ', i64 ' . $v . ', i64 ' . $acc . "\n";
            $acc = $sel;
        }
        return $this->finishI64($out, $acc);
    }

    /** Coerce a min/max integer-path operand to a raw i64, unboxing a cell. */
    private function minMaxOperandI64(Node $a): string
    {
        $out = $this->coerceToI64();
        if ($a->type->kind !== Type::KIND_CELL) { return $out; }
        $this->rt->needsTagged = true;
        $u = $this->ssa->allocReg();
        $out .= '  ' . $u . ' = call i64 @__manticore_unbox_int(i64 ' . $this->lastValue . ")\n";
        $this->lastValue = $u;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args */
    private function biDechex(array $args): string
    {
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceIntArg($args[0]);
        $v = $this->lastValue;
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 17)\n";
        $tmp = $this->ssa->allocReg();
        $out .= '  ' . $tmp . ' = call i32 (ptr, i64, ptr, ...) @snprintf(ptr ' . $buf . ', i64 17, ptr @.fmt.x, i64 ' . $v . ")\n";
        $tl = $this->ssa->allocReg();
        $out .= '  ' . $tl . ' = sext i32 ' . $tmp . " to i64\n";
        $out .= '  call void @__mir_str_set_len(ptr ' . $buf . ', i64 ' . $tl . ")\n";
        $this->lastValue = $buf; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  substr($s, $start[, $len]). */
    private function biSubstr(array $args): string
    {
        $this->rt->needsSubstr = true;
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
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_substr(ptr ' . $s . ', i64 ' . $start . ', i64 ' . $len . ', i64 ' . $haveLen . ")\n";
        $out .= $this->freeStrTemp($args[0], $s);
        $this->lastValue = $reg; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args */
    private function biStrRepeat(array $args): string
    {
        $this->rt->needsStrRepeat = true;
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $s = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceIntArg($args[1]);
        $n = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_repeat(ptr ' . $s . ', i64 ' . $n . ")\n";
        $out .= $this->freeStrTemp($args[0], $s);
        $this->lastValue = $reg; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  strtolower / strtoupper via $fn helper. */
    private function biCaseConv(array $args, string $fn): string
    {
        if ($fn === '__mir_strtolower') { $this->rt->needsStrtolower = true; }
        else { $this->rt->needsStrtoupper = true; }
        $this->libcExtra['malloc'] = 'declare ptr @malloc(i64)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @' . $fn . '(ptr ' . $a0 . ")\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        $this->lastValue = $reg; $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args */
    private function biStrpos(array $args): string
    {
        $this->rt->needsStrpos = true;
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
            $out .= $this->coerceIntArg($args[2]);
            $off = $this->lastValue;
        }
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mir_strpos(ptr ' . $h . ', ptr ' . $n
              . ', i64 ' . $off . ")\n";
        $out .= $this->freeStrTemp($args[0], $h);
        $out .= $this->freeStrTemp($args[1], $n);
        return $this->finishI64($out, $reg);
    }

    /**
     * strcspn($s, $chars [, $offset [, $length]]) → int. The bounded, binary-safe
     * "scan until one of these bytes" primitive: one pass over the span, no
     * overshoot past `$length`. Backs the JSON parser's string scanner (a
     * per-byte PHP loop there costs a 1-char string temp per byte).
     * @param Node[] $args
     */
    private function biStrcspn(array $args): string
    {
        $this->rt->needsStrcspn = true;
        $this->rt->needsConcat = true;   // pulls __mir_strlen + the string decls
        $out = $this->emitPtrArg($args[0]);
        $s = $this->lastValue;
        $out .= $this->emitPtrArg($args[1]);
        $cs = $this->lastValue;
        $off = '0';
        if (\count($args) >= 3) {
            $out .= $this->emitNode($args[2]);
            $out .= $this->coerceIntArg($args[2]);
            $off = $this->lastValue;
        }
        $len = '0';
        $haveLen = '0';
        if (\count($args) >= 4) {
            $out .= $this->emitNode($args[3]);
            $out .= $this->coerceIntArg($args[3]);
            $len = $this->lastValue;
            $haveLen = '1';
        }
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mir_strcspn(ptr ' . $s . ', ptr ' . $cs
              . ', i64 ' . $off . ', i64 ' . $len . ', i64 ' . $haveLen . ")\n";
        $out .= $this->freeStrTemp($args[0], $s);
        $out .= $this->freeStrTemp($args[1], $cs);
        return $this->finishI64($out, $reg);
    }

    /** `__float_bits(float): int` — the IEEE-754 bit pattern of a double as an
     *  i64 (bitcast, no conversion). Backs the PHP shortest-float encoder.
     *  @param Node[] $args */
    private function biFloatBits(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceTo('double');
        $d = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = bitcast double ' . $d . " to i64\n";
        return $this->finishI64($out, $reg);
    }

    /** `__ugt(a, b): bool` — unsigned `a > b` on the raw 64-bit patterns (PHP's
     *  `>` is signed; Ryu's pow5Factor needs the unsigned compare).
     *  @param Node[] $args */
    private function biUgt(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $a = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToI64();
        $b = $this->lastValue;
        $c = $this->ssa->allocReg();
        $out .= '  ' . $c . ' = icmp ugt i64 ' . $a . ', ' . $b . "\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = zext i1 ' . $c . " to i64\n";
        return $this->finishI64($out, $reg);
    }

    /** `__ryu_msp(m, idx, j, inv): int` — Ryu mulShift over the pow5 table entry
     *  at `idx` (inverse when `inv`), the one 128-bit primitive the PHP encoder
     *  needs. {@see \Compile\Mir\RuntimeLibrary::ryuMsp}.
     *  @param Node[] $args */
    private function biRyuMsp(array $args): string
    {
        $this->rt->needsRyu = true;
        $vals = [];
        $out = '';
        for ($k = 0; $k < 4; $k = $k + 1) {
            $out .= $this->emitNode($args[$k]);
            $out .= $this->coerceToI64();
            $vals[] = $this->lastValue;
        }
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mir_ryu_msp(i64 ' . $vals[0]
              . ', i64 ' . $vals[1] . ', i64 ' . $vals[2] . ', i64 ' . $vals[3] . ")\n";
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
        $this->rt->needsStrExplode = true;
        $this->libcExtra['strstr'] = 'declare ptr @strstr(ptr, ptr)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $out = $this->emitPtrArg($args[0]);
        $delim = $this->lastValue;
        $out .= $this->emitPtrArg($args[1]);
        $subj = $this->lastValue;
        if (\count($args) >= 3) {
            $out .= $this->emitNode($args[2]);
            $out .= $this->coerceIntArg($args[2]);
            $limit = $this->lastValue;
        } else {
            $limit = '9223372036854775807';
        }
        $reg = $this->ssa->allocReg();
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
        // php implode has two forms: `implode($sep, $array)` and the one-arg
        // `implode($array)` (separator defaults to ""). In the one-arg form the
        // array is $args[0] — reading $args[1] here dereferenced a null node and
        // SIGSEGV'd the compiler.
        if (\count($args) >= 2) {
            $out = $this->emitPtrArg($args[0]);
            $sep = $this->lastValue;
            $arr = $args[1];
        } else {
            $out = '';
            $sep = $this->strSymBytes('@.cstr.empty');
            $arr = $args[0];
        }
        // A non-string element vec (int/float/mixed) is boxed into a cell-array
        // and joined via tagged_to_str per element — the raw C implode would
        // inttoptr a non-pointer value and fault. A known string vec keeps the
        // fast path.
        $elem = $arr->type->element ?? null;
        // A RAW-int element vec joins natively (digit-count + int_fmt straight
        // into an exact-size buffer) — no boxToCell whole-array rebuild, no
        // tagged_to_str temp per element. ($arr, not $args[1] — the one-arg
        // implode($array) form puts the array at $args[0].)
        if ($elem !== null && $elem->kind === Type::KIND_INT) {
            $out .= $this->emitNode($arr);
            $out .= $this->coerceToPtr();
            $vec = $this->lastValue;
            $this->rt->needsIntStr = true;
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call ptr @__mir_array_implode_int(ptr ' . $sep . ', ptr ' . $vec . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        // A raw double IS a valid cell (untagged bits are the float repr), so a
        // vec[float] joins via the cell runtime DIRECTLY — the boxToCell pass
        // over a float vec was an identity element copy of the whole array.
        if ($elem !== null && $elem->kind === Type::KIND_FLOAT) {
            $out .= $this->emitNode($arr);
            $out .= $this->coerceToPtr();
            $vec = $this->lastValue;
            $this->rt->needsTaggedToStr = true;
            $this->rt->needsImplodeCell = true;
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call ptr @__mir_array_implode_cell(ptr ' . $sep . ', ptr ' . $vec . ")\n";
            $this->lastValue = $reg;
            $this->lastValueType = 'ptr';
            return $out;
        }
        $useCell = $elem === null || $elem->kind !== Type::KIND_STRING;
        $out .= $this->emitNode($arr);
        if ($useCell) {
            $out .= $this->boxToCell($arr->type);
            $out .= $this->cellToPtr();
            $vec = $this->lastValue;
            $this->rt->needsTaggedToStr = true;
            $this->rt->needsImplodeCell = true;
            $reg = $this->ssa->allocReg();
            $out .= '  ' . $reg . ' = call ptr @__mir_array_implode_cell(ptr ' . $sep . ', ptr ' . $vec . ")\n";
            $this->lastValue = $reg; $this->lastValueType = 'ptr';
            return $out;
        }
        $out .= $this->coerceToPtr();
        $vec = $this->lastValue;
        $reg = $this->ssa->allocReg();
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
                // Shortest round-trip via the Ryu core (uppercase E, no forced
                // `.0` — var_dump form), byte-exact with php and faster than the
                // old snprintf-probe. The core takes the raw i64 bits.
                $out .= $this->emitNode($a);
                $out .= $this->coerceTo('double');
                $d = $this->lastValue;
                $bitsr = $this->ssa->allocReg();
                $out .= '  ' . $bitsr . ' = bitcast double ' . $d . " to i64\n";
                $fsi = $this->ssa->allocReg();
                $out .= '  ' . $fsi . ' = call i64 @manticore___mc_dtoa_core(i64 ' . $bitsr . ', i64 1, i64 0)' . "\n";
                $fs = $this->ssa->allocReg();
                $out .= '  ' . $fs . ' = inttoptr i64 ' . $fsi . " to ptr\n";
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
        $a = $args[0];
        $out = $this->emitNode($a);
        if ($a->type->kind === Type::KIND_CELL) {
            $this->rt->needsTaggedToFloat = true;
            $d = $this->ssa->allocReg();
            $out .= '  ' . $d . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $d; $this->lastValueType = 'double';
        } else {
            $out .= $this->coerceTo('double');
        }
        // Ryu shortest, var_dump form (uppercase E, no forced `.0`).
        $bitsr = $this->ssa->allocReg();
        $out .= '  ' . $bitsr . ' = bitcast double ' . $this->lastValue . " to i64\n";
        $fsi = $this->ssa->allocReg();
        $out .= '  ' . $fsi . ' = call i64 @manticore___mc_dtoa_core(i64 ' . $bitsr . ', i64 1, i64 0)' . "\n";
        $fs = $this->ssa->allocReg();
        $out .= '  ' . $fs . ' = inttoptr i64 ' . $fsi . " to ptr\n";
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
        $a0 = $args[0];
        // A RUNTIME (non-literal) format can't be translated at compile time —
        // pack the args and drive the runtime {@see \__mc_format} engine.
        if ($a0->kind !== Node::KIND_STRING_CONST) {
            return $this->biFormatRuntime($args, $toStdout);
        }
        $fmt = $a0->value;
        // Translate specifiers + record per-arg conversion kind.
        $trans = '';
        $convs = [];
        // Set when a `%e`/`%E`/`%g`/`%G` conversion is present: C pads the
        // exponent to 2 digits, PHP uses the minimum → post-fix the result.
        $hasExp = false;
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
                } elseif ($c === "'") {
                    // A `%'X` custom pad char has no C-snprintf equivalent — this
                    // compile-time translator can't express it, so drive the whole
                    // format through the runtime __mc_format engine, which does.
                    return $this->biFormatRuntime($args, $toStdout);
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
                if ($conv !== 'f' && $conv !== 'F') { $hasExp = true; }
            } elseif ($conv === 'b') {
                // PHP `%b` (binary) — C printf has no `%b`. Pre-convert the arg to
                // a binary string via decbin and emit it through `%s`; width/pad
                // flags in `$prefix` apply to the string (plain `%b` is exact).
                $trans .= '%' . $prefix . 's'; $convs[] = 'b';
            } else {
                // Unknown conversion — pass through, no arg.
                $trans .= '%' . $prefix . $conv;
            }
            $i = $j;
        }
        $argN = \count($convs);
        // A positional `%n$` spec or fewer args than conversions needs the
        // runtime {@see \__mc_format} engine (arg reordering / a missing arg is
        // "" in PHP) — the inline path can do neither, and would crash on an
        // out-of-range `$args[$a + 1]`.
        if (\count($args) < $argN + 1 || \strpos($fmt, '$') !== false) {
            return $this->biFormatRuntime($args, $toStdout);
        }
        $fmtId = $this->pool->intern($trans);
        $fmtPtr = $this->strLitId($fmtId);
        // Evaluate + coerce each consumed arg in order.
        $out = '';
        $vararg = '';
        for ($a = 0; $a < $argN; $a = $a + 1) {
            $argNode = $args[$a + 1];
            $out .= $this->emitNode($argNode);
            $conv = $convs[$a];
            if ($conv === 's') {
                // A `mixed`/cell `%s` arg → stringify by tag (int→"9", a string
                // cell→its bytes); a plain value coerces to a ptr directly.
                if ($argNode->type->kind === Type::KIND_CELL) {
                    $this->rt->needsTaggedToStr = true;
                    $s = $this->ssa->allocReg();
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
                    $this->rt->needsTaggedToFloat = true;
                    $d = $this->ssa->allocReg();
                    $out .= '  ' . $d . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
                    $this->lastValue = $d; $this->lastValueType = 'double';
                } else {
                    $out .= $this->coerceTo('double');
                }
                $vararg .= ', double ' . $this->lastValue;
            }
            elseif ($conv === 'b') {
                // `%b` → decbin(arg) string, passed through the `%s` slot. Declare
                // decbin as an extern ONLY when it is not defined in this module
                // (a linked stdlib.o); a self-contained build embeds its define,
                // and a second `declare` there is a redefinition error.
                if (!isset($this->definedFns[$this->mangle('decbin')])) {
                    $this->libcExtra['manticore_decbin'] = 'declare i64 @manticore_decbin(i64)';
                }
                if ($argNode->type->kind === Type::KIND_CELL) {
                    $this->rt->needsTaggedToInt = true;
                    $iv = $this->ssa->allocReg();
                    $out .= '  ' . $iv . ' = call i64 @__manticore_tagged_to_int(i64 ' . $this->lastValue . ")\n";
                    $this->lastValue = $iv;
                } else {
                    $out .= $this->coerceToI64();
                }
                $bs = $this->ssa->allocReg();
                $out .= '  ' . $bs . ' = call i64 @manticore_decbin(i64 ' . $this->lastValue . ")\n";
                $bp = $this->ssa->allocReg();
                $out .= '  ' . $bp . ' = inttoptr i64 ' . $bs . " to ptr\n";
                $vararg .= ', ptr ' . $bp;
            }
            else {
                // A `mixed`/cell `%d`/`%x`/… arg → unbox to i64 by tag (a boxed
                // int's payload, a float truncated) rather than passing the
                // tagged carrier bits straight to printf.
                if ($argNode->type->kind === Type::KIND_CELL) {
                    $this->rt->needsTaggedToInt = true;
                    $this->rt->needsStrtol = true;
                    $iv = $this->ssa->allocReg();
                    $out .= '  ' . $iv . ' = call i64 @__manticore_tagged_to_int(i64 ' . $this->lastValue . ")\n";
                    $this->lastValue = $iv; $this->lastValueType = 'i64';
                } else {
                    $out .= $this->coerceToI64();
                }
                $vararg .= ', i64 ' . $this->lastValue;
            }
        }
        // Fast path: direct printf to stdout when there is no exponent to fix.
        if ($toStdout && !$hasExp) {
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i32 (ptr, ...) @printf(ptr ' . $fmtPtr . $vararg . ")\n";
            $r2 = $this->ssa->allocReg();
            $out .= '  ' . $r2 . ' = sext i32 ' . $r . " to i64\n";
            $this->lastValue = $r2; $this->lastValueType = 'i64';
            return $out;
        }
        // Format into a buffer (sprintf; also printf when %e/%g needs fixing).
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 256)\n";
        $tmp = $this->ssa->allocReg();
        $out .= '  ' . $tmp . ' = call i32 (ptr, i64, ptr, ...) @snprintf(ptr ' . $buf . ', i64 256, ptr ' . $fmtPtr . $vararg . ")\n";
        $tl = $this->ssa->allocReg();
        $out .= '  ' . $tl . ' = sext i32 ' . $tmp . " to i64\n";
        $ov = $this->ssa->allocReg();
        $out .= '  ' . $ov . ' = icmp sgt i64 ' . $tl . ", 255\n";
        $cl = $this->ssa->allocReg();
        $out .= '  ' . $cl . ' = select i1 ' . $ov . ', i64 255, i64 ' . $tl . "\n";
        $out .= '  call void @__mir_str_set_len(ptr ' . $buf . ', i64 ' . $cl . ")\n";
        // PHP-style exponent (`e+03` → `e+3`): rewrite via the stdlib helper,
        // then release the intermediate snprintf buffer. Declare the extern only
        // when it is not defined in-module (self-contained build embeds it).
        if ($hasExp) {
            if (!isset($this->definedFns[$this->mangle('__mc_fix_exp')])) {
                $this->libcExtra['manticore___mc_fix_exp'] = 'declare i64 @manticore___mc_fix_exp(i64)';
            }
            $bi = $this->ssa->allocReg();
            $out .= '  ' . $bi . ' = ptrtoint ptr ' . $buf . " to i64\n";
            $fx = $this->ssa->allocReg();
            $out .= '  ' . $fx . ' = call i64 @manticore___mc_fix_exp(i64 ' . $bi . ")\n";
            $out .= $this->rcReleaseReg($bi, 'str');
            $fp = $this->ssa->allocReg();
            $out .= '  ' . $fp . ' = inttoptr i64 ' . $fx . " to ptr\n";
            $buf = $fp;
        }
        if ($toStdout) {
            // Print the fixed buffer, then release it (owned intermediate, not
            // returned). printf("%s", buf) returns the byte count.
            $pcts = $this->strLitId($this->pool->intern('%s'));
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call i32 (ptr, ...) @printf(ptr ' . $pcts . ', ptr ' . $buf . ")\n";
            if ($hasExp) {
                $bi2 = $this->ssa->allocReg();
                $out .= '  ' . $bi2 . ' = ptrtoint ptr ' . $buf . " to i64\n";
                $out .= $this->rcReleaseReg($bi2, 'str');
            }
            $r2 = $this->ssa->allocReg();
            $out .= '  ' . $r2 . ' = sext i32 ' . $r . " to i64\n";
            $this->lastValue = $r2; $this->lastValueType = 'i64';
            return $out;
        }
        $this->lastValue = $buf; $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Runtime single-conversion formatter: `__mc_fmt_int/float/str($cfmt, $val)`
     * → snprintf the ONE value through the already-C-translated spec `$cfmt`
     * (e.g. `%+08lld`, `%-10.3f`, `%.5s`) into a fresh string. The runtime
     * `sprintf`/`vsprintf`/`fprintf` engine ({@see \__mc_format}) parses the PHP
     * format itself and drives one of these per conversion — the moving-format
     * analogue of {@see biSprintf}'s compile-time inline. `$kind` picks the C
     * vararg type: i=i64, f=double, s=char*. snprintf is declared ONCE as
     * variadic so all three calls share a compatible `@snprintf`.
     */
    private function biFmt1(array $args, string $kind): ?string
    {
        if (\count($args) !== 2) { return null; }
        $this->libcExtra['snprintf'] = 'declare i32 @snprintf(ptr, i64, ptr, ...)';
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $fmtPtr = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $vk = $args[1]->type->kind;
        // The engine passes the RAW arg (a mixed/nullable element of `...$args`);
        // a tagged value (cell / erased unknown) is NaN-boxed and must be unboxed
        // BY TAG (never sitofp'd), so coerce to the i64 carrier first (ptr→i64)
        // then run the tagged decoder. A statically-concrete scalar coerces plain.
        $tagged = ($vk === Type::KIND_CELL || $vk === Type::KIND_UNKNOWN);
        if ($kind === 'f') {
            if ($tagged) {
                $this->rt->needsTaggedToFloat = true;
                $out .= $this->coerceToI64();
                $d = $this->ssa->allocReg();
                $out .= '  ' . $d . ' = call double @__manticore_tagged_to_double(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $d; $this->lastValueType = 'double';
            } else {
                $out .= $this->coerceTo('double');
            }
            $vtype = 'double';
        } elseif ($kind === 's') {
            if ($tagged) {
                $this->rt->needsTaggedToStr = true;
                $out .= $this->coerceToI64();
                $s = $this->ssa->allocReg();
                $out .= '  ' . $s . ' = call ptr @__manticore_tagged_to_str(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $s; $this->lastValueType = 'ptr';
            } else {
                $out .= $this->coerceToPtr();
            }
            $vtype = 'ptr';
        } else {
            if ($tagged) {
                $this->rt->needsTaggedToInt = true;
                $this->rt->needsStrtol = true; // tagged_to_int parses a string cell via strtol
                $out .= $this->coerceToI64();
                $iv = $this->ssa->allocReg();
                $out .= '  ' . $iv . ' = call i64 @__manticore_tagged_to_int(i64 ' . $this->lastValue . ")\n";
                $this->lastValue = $iv; $this->lastValueType = 'i64';
            } else {
                $out .= $this->coerceToI64();
            }
            $vtype = 'i64';
        }
        $val = $this->lastValue;
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . " = call ptr @__mir_str_alloc(i64 256)\n";
        $tmp = $this->ssa->allocReg();
        $out .= '  ' . $tmp . ' = call i32 (ptr, i64, ptr, ...) @snprintf(ptr ' . $buf
              . ', i64 256, ptr ' . $fmtPtr . ', ' . $vtype . ' ' . $val . ")\n";
        $tl = $this->ssa->allocReg();
        $out .= '  ' . $tl . ' = sext i32 ' . $tmp . " to i64\n";
        // snprintf returns the length it WOULD have written; clamp to the 255-byte
        // buffer so a huge width doesn't set a length past the allocation.
        $ov = $this->ssa->allocReg();
        $out .= '  ' . $ov . ' = icmp sgt i64 ' . $tl . ", 255\n";
        $cl = $this->ssa->allocReg();
        $out .= '  ' . $cl . ' = select i1 ' . $ov . ', i64 255, i64 ' . $tl . "\n";
        $out .= '  call void @__mir_str_set_len(ptr ' . $buf . ', i64 ' . $cl . ")\n";
        $this->lastValue = $buf; $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * Runtime `sprintf`/`printf` whose format the compiler cannot translate at
     * compile time (variable format, positional `%n$`, or fewer args than
     * conversions): pack the individual arg nodes into a packed cell array and
     * drive the stdlib {@see \__mc_format} engine. `sprintf($f, ...$arr)` passes
     * the spread's array straight through. printf echoes the result.
     */
    private function biFormatRuntime(array $args, bool $toStdout): string
    {
        // Spread `...$arr`: the array IS the argument list — hand it over as-is.
        if (\count($args) === 2 && $args[1]->kind === Node::KIND_SPREAD) {
            $out = $this->emitNode($args[0]);
            $out .= $this->coerceToPtr();
            $fmtI = $this->ssa->allocReg();
            $out .= '  ' . $fmtI . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
            $out .= $this->emitNode($args[1]->operand);
            $out .= $this->coerceToPtr();
            $arrI = $this->ssa->allocReg();
            $out .= '  ' . $arrI . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
            return $this->formatCallAndFinish($out, $fmtI, $arrI, $toStdout, false);
        }
        $hdr = \Compile\MemoryAbi::ARRAY_HEADER_SIZE;
        $esz = \Compile\MemoryAbi::ARRAY_PACKED_ELEMENT_SIZE;
        $count = \count($args) - 1;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $fmtI = $this->ssa->allocReg();
        $out .= '  ' . $fmtI . ' = ptrtoint ptr ' . $this->lastValue . " to i64\n";
        // Pack args[1..] into a packed CELL array (mirrors emitArrayLitDirect).
        $arr = $this->ssa->allocReg();
        $out .= '  ' . $arr . ' = call ptr @__mir_array_alloc(i64 ' . (string)$count . ")\n";
        for ($k = 1; $k <= $count; $k = $k + 1) {
            $el = $args[$k];
            $out .= $this->emitNode($el);
            $out .= $this->retainCellPayload($el);
            $out .= $this->boxToCell($el->type);
            $val = $this->lastValue;
            $off = $hdr + ($k - 1) * $esz;
            $p = $this->ssa->allocReg();
            $out .= '  ' . $p . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 ' . (string)$off . "\n";
            $out .= '  store i64 ' . $val . ', ptr ' . $p . "\n";
        }
        $out .= '  store i64 ' . (string)$count . ', ptr ' . $arr . "\n";
        $ni = $this->ssa->allocReg();
        $out .= '  ' . $ni . ' = getelementptr inbounds i8, ptr ' . $arr . ', i64 '
              . (string)\Compile\MemoryAbi::ARRAY_NEXT_INT_OFFSET . "\n";
        $out .= '  store i64 ' . (string)$count . ', ptr ' . $ni . "\n";
        $arrI = $this->ssa->allocReg();
        $out .= '  ' . $arrI . ' = ptrtoint ptr ' . $arr . " to i64\n";
        return $this->formatCallAndFinish($out, $fmtI, $arrI, $toStdout, true);
    }

    /** Emit the `__mc_format` call + printf/return tail shared by biFormatRuntime. */
    private function formatCallAndFinish(string $out, string $fmtI, string $arrI, bool $toStdout, bool $releaseArr): string
    {
        if (!isset($this->definedFns[$this->mangle('__mc_format')])) {
            $this->libcExtra['manticore___mc_format'] = 'declare i64 @manticore___mc_format(i64, i64)';
        }
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . ' = call i64 @manticore___mc_format(i64 ' . $fmtI . ', i64 ' . $arrI . ")\n";
        if ($releaseArr) {
            // The packed temp co-owned each boxed arg (retainCellPayload); dropping
            // it releases them, balancing the retain.
            $ap = $this->ssa->allocReg();
            $out .= '  ' . $ap . ' = inttoptr i64 ' . $arrI . " to ptr\n";
            $out .= '  call void @__mir_array_release_cell(ptr ' . $ap . ")\n";
        }
        if ($toStdout) {
            $rp = $this->ssa->allocReg();
            $out .= '  ' . $rp . ' = inttoptr i64 ' . $res . " to ptr\n";
            $pcts = $this->strLitId($this->pool->intern('%s'));
            $pr = $this->ssa->allocReg();
            $out .= '  ' . $pr . ' = call i32 (ptr, ...) @printf(ptr ' . $pcts . ', ptr ' . $rp . ")\n";
            $out .= $this->rcReleaseReg($res, 'str');
            $len = $this->ssa->allocReg();
            $out .= '  ' . $len . ' = sext i32 ' . $pr . " to i64\n";
            $this->lastValue = $len; $this->lastValueType = 'i64';
            return $out;
        }
        $rp = $this->ssa->allocReg();
        $out .= '  ' . $rp . ' = inttoptr i64 ' . $res . " to ptr\n";
        $this->lastValue = $rp; $this->lastValueType = 'ptr';
        return $out;
    }

    /** `gc_collect_cycles()` → run the Bacon-Rajan collector, return freed count. */
    private function biGcCollect(): string
    {
        $this->rt->needsCc = true;
        $this->rt->needsRc = true;
        $reg = $this->ssa->allocReg();
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
                $code = $this->ssa->allocReg();
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
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = call i64 @strlen(ptr ' . $msg . ")\n";
        $w = $this->ssa->allocReg();
        $out .= '  ' . $w . ' = call i64 @write(i32 2, ptr ' . $msg . ', i64 ' . $len . ")\n";
        $nl = $this->ssa->allocReg();
        $out .= '  ' . $nl . " = alloca i8\n";
        $out .= '  store i8 10, ptr ' . $nl . "\n";
        $w2 = $this->ssa->allocReg();
        $out .= '  ' . $w2 . ' = call i64 @write(i32 2, ptr ' . $nl . ", i64 1)\n";
        $out .= $this->freeStrTemp($args[0], $msg);
        // PHP error_log returns true.
        $this->lastValue = '1';
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `__mc_refl_of($o)` — the object's reflection metadata block as a raw
     * address, or 0 when the class has none (no reflection reaches it, or it is
     * a `#[Struct]` with no header at all).
     *
     * Object → descriptor (header slot 0) → rmeta (descriptor offset 16). The
     * same double indirection as {@see EmitLlvm::emitLoadClassId}, one field
     * further along. Internal: the surface is `prelude/reflection.php`.
     *
     * @param Node[] $args
     */
    private function biMcReflOf(array $args): string
    {
        $out = $this->emitPtrArg($args[0]);
        $objp = $this->lastValue;
        $descI = $this->ssa->allocReg();
        $out .= '  ' . $descI . ' = load i64, ptr ' . $objp . "\n";
        $descP = $this->ssa->allocReg();
        $out .= '  ' . $descP . ' = inttoptr i64 ' . $descI . " to ptr\n";
        $mp = $this->ssa->allocReg();
        $out .= '  ' . $mp . ' = getelementptr i8, ptr ' . $descP
              . ', i64 ' . (string)\Compile\MemoryAbi::DESCRIPTOR_RMETA_OFFSET . "\n";
        $m = $this->ssa->allocReg();
        $out .= '  ' . $m . ' = load ptr, ptr ' . $mp . "\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = ptrtoint ptr ' . $m . " to i64\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_cap()` — the index table's slot count, after forcing it built.
     *
     * With {@see biMcReflSlot}, this is how the prelude ENUMERATES the class
     * table (get_declared_classes and friends). Iterating the index rather than
     * the list is deliberate: the table is a flat array, so a walk is
     * cache-friendly and needs no node-chasing builtins. Empty slots read 0 and
     * are skipped.
     *
     * @param Node[] $args
     */
    private function biMcReflCap(array $args): string
    {
        $out = "  call ptr @__mc_refl_index()\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . " = load i64, ptr @__mc_refl_idx_cap\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_slot($i)` — the rmeta handle in index slot `$i`, or 0 if empty.
     *
     * No bounds check: the only caller is the prelude's own `0 .. cap-1` loop.
     * A null table (calloc failed) answers 0 for every slot, so enumeration
     * degrades to "no classes" rather than dereferencing null.
     *
     * @param Node[] $args
     */
    private function biMcReflSlot(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $i = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'sl.ok.' . $lbl;
        $noL = 'sl.no.' . $lbl;
        $endL = 'sl.end.' . $lbl;
        $tab = $this->ssa->allocReg();
        $out .= '  ' . $tab . " = load ptr, ptr @__mc_refl_idx\n";
        $tz = $this->ssa->allocReg();
        $out .= '  ' . $tz . ' = icmp eq ptr ' . $tab . ", null\n";
        $out .= '  br i1 ' . $tz . ', label %' . $noL . ', label %' . $okL . "\n";
        $out .= $noL . ":\n  br label %" . $endL . "\n";
        $out .= $okL . ":\n";
        $sp = $this->ssa->allocReg();
        $out .= '  ' . $sp . ' = getelementptr ptr, ptr ' . $tab . ', i64 ' . $i . "\n";
        $sv = $this->ssa->allocReg();
        $out .= '  ' . $sv . ' = load ptr, ptr ' . $sp . "\n";
        $si = $this->ssa->allocReg();
        $out .= '  ' . $si . ' = ptrtoint ptr ' . $sv . " to i64\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ 0, %' . $noL . ' ], [ ' . $si . ', %' . $okL . " ]\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `__mc_refl_find($name)` — an rmeta handle by class name, or 0.
     *
     * This is the one lookup a compile-time fold cannot do: the name is a
     * runtime string. It walks the registry the `@llvm.global_ctors` entries
     * built, which is what makes reflection work ACROSS separately-linked
     * objects — user.o and stdlib.o each register their own classes into the
     * same list, with no central table for anyone to forget to update.
     *
     * The arg is a `string`-typed value; its bytes are NUL-terminated, so strcmp
     * reads them directly. `coerceToPtr` is what makes the operand a `ptr`
     * whether it arrived as a runtime i64 or as a literal — a string CONSTANT is
     * already a `getelementptr` constexpr of type ptr, so an unconditional
     * `inttoptr i64` would be a type mismatch clang rejects.
     *
     * @param Node[] $args
     */
    private function biMcReflFind(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $sp = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mc_refl_find(ptr ' . $sp . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_fn_find($name)` — a free function's metadata-row address (as an
     * int) by name, or 0. The function-registry twin of {@see biMcReflFind}
     * (Ф5, ReflectionFunction).
     *
     * @param Node[] $args
     */
    private function biMcReflFnFind(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToPtr();
        $sp = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mc_refl_fn_find(ptr ' . $sp . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_member($h, $name, $wantMethods)` — a member's flags + 1, or 0
     * when absent. The `+1` keeps one i64 answering both "present?" and "what?"
     * (a public non-static member's flags are 0).
     *
     * @param Node[] $args
     */
    private function biMcReflMember(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToPtr();
        $np = $this->lastValue;
        $out .= $this->emitNode($args[2]);
        $w = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mc_refl_member(i64 ' . $h
              . ', ptr ' . $np . ', i64 ' . $w . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_flags($h)` — the class flags word (final/abstract/…), 0 for a
     * null handle.
     *
     * @param Node[] $args
     */
    private function biMcReflFlags(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'rf.ok.' . $lbl;
        $noL = 'rf.no.' . $lbl;
        $endL = 'rf.end.' . $lbl;
        $hz = $this->ssa->allocReg();
        $out .= '  ' . $hz . ' = icmp eq i64 ' . $h . ", 0\n";
        $out .= '  br i1 ' . $hz . ', label %' . $noL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $hp = $this->ssa->allocReg();
        $out .= '  ' . $hp . ' = inttoptr i64 ' . $h . " to ptr\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = getelementptr i8, ptr ' . $hp . ', i64 '
              . (string)\Compile\MemoryAbi::RMETA_FLAGS_OFFSET . "\n";
        $fv = $this->ssa->allocReg();
        $out .= '  ' . $fv . ' = load i64, ptr ' . $fp . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $noL . ":\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ ' . $fv . ', %' . $okL . ' ], [ 0, %' . $noL . " ]\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `__mc_refl_mrow($h, $name)` — a method row's address (as an int), or 0.
     * The prelude caches it, then reads nparams / params / arity off it via
     * {@see emitReflFieldI64}. Ф2d (ReflectionParameter).
     *
     * @param Node[] $args
     */
    private function biMcReflMrow(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToPtr();
        $np = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mc_refl_mrow(i64 ' . $h . ', ptr ' . $np . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_prow($h, $name)` — a property row's address (as an int), or 0.
     * The prelude's ReflectionProperty caches it, then reads the type + accessor
     * pointers off the extra struct at its `params` slot. Property-table twin of
     * {@see biMcReflMrow}.
     *
     * @param Node[] $args
     */
    private function biMcReflProw(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToPtr();
        $np = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mc_refl_prow(i64 ' . $h . ', ptr ' . $np . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_call1($fn, $obj)` — call a synthesized property GETTER
     * indirectly: `(i64 recv) -> i64 cell`. `$obj` is the receiver (untagged by
     * the getter's typed `$t`; ignored for a static property). 0 fn ⇒ 0 (the
     * prelude checks and throws first). Returns the boxed value cell.
     *
     * @param Node[] $args
     */
    private function biMcReflCall1(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $fn = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToI64();
        $recv = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'c1.ok.' . $lbl;
        $noL = 'c1.no.' . $lbl;
        $endL = 'c1.end.' . $lbl;
        $fz = $this->ssa->allocReg();
        $out .= '  ' . $fz . ' = icmp eq i64 ' . $fn . ", 0\n";
        $out .= '  br i1 ' . $fz . ', label %' . $noL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = inttoptr i64 ' . $fn . " to ptr\n";
        $cv = $this->ssa->allocReg();
        $out .= '  ' . $cv . ' = call i64 ' . $fp . '(i64 ' . $recv . ")\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $noL . ":\n  br label %" . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ ' . $cv . ', %' . $okL . ' ], [ 0, %' . $noL . " ]\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_call0($fn)` — call a synthesized nullary factory indirectly:
     * `() -> i64 cell`. The attribute args / newInstance factories. 0 fn ⇒ 0.
     *
     * @param Node[] $args
     */
    private function biMcReflCall0(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $fn = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'c0.ok.' . $lbl;
        $noL = 'c0.no.' . $lbl;
        $endL = 'c0.end.' . $lbl;
        $fz = $this->ssa->allocReg();
        $out .= '  ' . $fz . ' = icmp eq i64 ' . $fn . ", 0\n";
        $out .= '  br i1 ' . $fz . ', label %' . $noL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = inttoptr i64 ' . $fn . " to ptr\n";
        $cv = $this->ssa->allocReg();
        $out .= '  ' . $cv . ' = call i64 ' . $fp . "()\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $noL . ":\n  br label %" . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ ' . $cv . ', %' . $okL . ' ], [ 0, %' . $noL . " ]\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_prop_set($fn, $obj, $val)` — call a synthesized property SETTER
     * indirectly: `(i64 recv, i64 val cell) -> void`. No-op when fn is 0.
     *
     * @param Node[] $args
     */
    private function biMcReflPropSet(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $fn = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToI64();
        $recv = $this->lastValue;
        $out .= $this->emitNode($args[2]);
        $out .= $this->coerceToI64();
        $val = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'ps.ok.' . $lbl;
        $endL = 'ps.end.' . $lbl;
        $fz = $this->ssa->allocReg();
        $out .= '  ' . $fz . ' = icmp eq i64 ' . $fn . ", 0\n";
        $out .= '  br i1 ' . $fz . ', label %' . $endL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = inttoptr i64 ' . $fn . " to ptr\n";
        $out .= '  call void ' . $fp . '(i64 ' . $recv . ', i64 ' . $val . ")\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        // A void builtin still yields a value slot; 0 is never read.
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . " = add i64 0, 0\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * Load an i64 field (or a ptr field, ptrtoint'd) at `$off` from a row/rmeta
     * handle in `$args[0]`; 0 when the handle is null. The reader half of the
     * `__mc_refl_row_*` builtins.
     *
     * @param Node[] $args
     */
    private function emitReflFieldI64(array $args, int $off, bool $isPtr): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'rfl.ok.' . $lbl;
        $noL = 'rfl.no.' . $lbl;
        $endL = 'rfl.end.' . $lbl;
        $hz = $this->ssa->allocReg();
        $out .= '  ' . $hz . ' = icmp eq i64 ' . $h . ", 0\n";
        $out .= '  br i1 ' . $hz . ', label %' . $noL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $hp = $this->ssa->allocReg();
        $out .= '  ' . $hp . ' = inttoptr i64 ' . $h . " to ptr\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = getelementptr i8, ptr ' . $hp . ', i64 ' . (string)$off . "\n";
        $fv = $this->ssa->allocReg();
        if ($isPtr) {
            $pv = $this->ssa->allocReg();
            $out .= '  ' . $pv . ' = load ptr, ptr ' . $fp . "\n";
            $out .= '  ' . $fv . ' = ptrtoint ptr ' . $pv . " to i64\n";
        } else {
            $out .= '  ' . $fv . ' = load i64, ptr ' . $fp . "\n";
        }
        $out .= '  br label %' . $endL . "\n";
        $out .= $noL . ":\n  br label %" . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ ' . $fv . ', %' . $okL . ' ], [ 0, %' . $noL . " ]\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * Read a parameter entry's field at `$off`: `params_ptr + i*PARAM_SIZE + off`.
     * `$args[0]` is the params-table base (from `__mc_refl_row_params`), `$args[1]`
     * the index. Bounds are the caller's (the prelude's `0..nparams-1` loop). A
     * ptr field (name / type) is returned as an int the caller reads as a string.
     *
     * @param Node[] $args
     */
    private function emitReflParamField(array $args, int $off, bool $isPtr, ?int $stride = null): string
    {
        $stride = $stride ?? \Compile\MemoryAbi::RMETA_PARAM_SIZE;
        $out = $this->emitNode($args[0]);
        $base = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $idx = $this->lastValue;
        $bp = $this->ssa->allocReg();
        $out .= '  ' . $bp . ' = inttoptr i64 ' . $base . " to ptr\n";
        $eoff = $this->ssa->allocReg();
        $out .= '  ' . $eoff . ' = mul i64 ' . $idx . ', '
              . (string)$stride . "\n";
        $ep = $this->ssa->allocReg();
        $out .= '  ' . $ep . ' = getelementptr i8, ptr ' . $bp . ', i64 ' . $eoff . "\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = getelementptr i8, ptr ' . $ep . ', i64 ' . (string)$off . "\n";
        $reg = $this->ssa->allocReg();
        if ($isPtr) {
            $pv = $this->ssa->allocReg();
            $out .= '  ' . $pv . ' = load ptr, ptr ' . $fp . "\n";
            $out .= '  ' . $reg . ' = ptrtoint ptr ' . $pv . " to i64\n";
        } else {
            $out .= '  ' . $reg . ' = load i64, ptr ' . $fp . "\n";
        }
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_tramp($h, $name)` — a method's invoke-trampoline pointer (as an
     * int), or 0 when absent / not invokable. The prelude's ReflectionMethod
     * feeds this to {@see biMcReflInvoke}.
     *
     * @param Node[] $args
     */
    private function biMcReflTramp(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToPtr();
        $np = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 @__mc_refl_tramp(i64 ' . $h . ', ptr ' . $np . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_ctor($h)` — the class's constructor-trampoline pointer (as an
     * int), or 0 when the class is abstract / has no synthesized ctor trampoline.
     * `newInstance()` calls it via {@see biMcReflInvoke} with a null receiver.
     * A dedicated rmeta slot ({@see \Compile\MemoryAbi::RMETA_CTOR_TRAMP_OFFSET})
     * because a class with no user `__construct` still constructs.
     *
     * @param Node[] $args
     */
    private function biMcReflCtor(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'rc.ok.' . $lbl;
        $noL = 'rc.no.' . $lbl;
        $endL = 'rc.end.' . $lbl;
        $hz = $this->ssa->allocReg();
        $out .= '  ' . $hz . ' = icmp eq i64 ' . $h . ", 0\n";
        $out .= '  br i1 ' . $hz . ', label %' . $noL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $hp = $this->ssa->allocReg();
        $out .= '  ' . $hp . ' = inttoptr i64 ' . $h . " to ptr\n";
        $cp = $this->ssa->allocReg();
        $out .= '  ' . $cp . ' = getelementptr i8, ptr ' . $hp . ', i64 '
              . (string)\Compile\MemoryAbi::RMETA_CTOR_TRAMP_OFFSET . "\n";
        $cv = $this->ssa->allocReg();
        $out .= '  ' . $cv . ' = load ptr, ptr ' . $cp . "\n";
        $ci = $this->ssa->allocReg();
        $out .= '  ' . $ci . ' = ptrtoint ptr ' . $cv . " to i64\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $noL . ":\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ ' . $ci . ', %' . $okL . ' ], [ 0, %' . $noL . " ]\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `__mc_refl_invoke($tramp, $recv, $args)` — call a synthesized trampoline
     * indirectly: `(i64 recv, ptr args) -> i64 cell`. The trampoline untags the
     * receiver itself (its `$t` is a typed object), so `$recv` passes through as
     * the prelude holds it (0 for a static method / constructor). `$args` is the
     * boxed `mixed[]` argument array. Returns the boxed result cell.
     *
     * @param Node[] $args
     */
    private function biMcReflInvoke(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $tramp = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToI64();
        $recv = $this->lastValue;
        $out .= $this->emitNode($args[2]);
        $out .= $this->coerceToPtr();
        $ap = $this->lastValue;
        $argsI = $this->ssa->allocReg();
        $out .= '  ' . $argsI . ' = ptrtoint ptr ' . $ap . " to i64\n";
        $fp = $this->ssa->allocReg();
        $out .= '  ' . $fp . ' = inttoptr i64 ' . $tramp . " to ptr\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call i64 ' . $fp . '(i64 ' . $recv . ', i64 ' . $argsI . ")\n";
        return $this->finishI64($out, $reg);
    }

    /**
     * `__mc_refl_parent($h)` — the parent's rmeta handle, or 0 at the root.
     *
     * Resolved through the NAME (`find(parent_name)`), not the id: the registry
     * is name-keyed, and the parent's block may live in another object file
     * where a direct pointer would need a relocation the emitter cannot form.
     *
     * @param Node[] $args
     */
    private function biMcReflParent(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'rp.ok.' . $lbl;
        $noL = 'rp.no.' . $lbl;
        $endL = 'rp.end.' . $lbl;
        $hz = $this->ssa->allocReg();
        $out .= '  ' . $hz . ' = icmp eq i64 ' . $h . ", 0\n";
        $out .= '  br i1 ' . $hz . ', label %' . $noL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $hp = $this->ssa->allocReg();
        $out .= '  ' . $hp . ' = inttoptr i64 ' . $h . " to ptr\n";
        $pnp = $this->ssa->allocReg();
        $out .= '  ' . $pnp . ' = getelementptr i8, ptr ' . $hp . ', i64 '
              . (string)\Compile\MemoryAbi::RMETA_PARENT_NAME_OFFSET . "\n";
        $pn = $this->ssa->allocReg();
        $out .= '  ' . $pn . ' = load ptr, ptr ' . $pnp . "\n";
        $pz = $this->ssa->allocReg();
        $out .= '  ' . $pz . ' = icmp eq ptr ' . $pn . ", null\n";
        $findL = 'rp.find.' . $lbl;
        $out .= '  br i1 ' . $pz . ', label %' . $noL . ', label %' . $findL . "\n";
        $out .= $findL . ":\n";
        $fr = $this->ssa->allocReg();
        $out .= '  ' . $fr . ' = call i64 @__mc_refl_find(ptr ' . $pn . ")\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $noL . ":\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ ' . $fr . ', %' . $findL . ' ], [ 0, %' . $noL . " ]\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `__mc_refl_name($h)` — the class name out of an rmeta handle.
     *
     * The stored pointer is an immortal (`rc -1`) headered string literal, so it
     * is returned as-is: no copy, no retain, and nothing can free it under the
     * caller. A 0 handle yields an empty string rather than faulting — callers
     * come from PHP, where a wild read would be unexplainable.
     *
     * @param Node[] $args
     */
    private function biMcReflName(array $args): string
    {
        $out = $this->emitNode($args[0]);
        $h = $this->lastValue;
        $lbl = (string)$this->ssa->allocReg();
        $lbl = \str_replace('%', '', $lbl);
        $okL = 'rn.ok.' . $lbl;
        $nullL = 'rn.null.' . $lbl;
        $endL = 'rn.end.' . $lbl;
        $isNull = $this->ssa->allocReg();
        $out .= '  ' . $isNull . ' = icmp eq i64 ' . $h . ", 0\n";
        $out .= '  br i1 ' . $isNull . ', label %' . $nullL . ', label %' . $okL . "\n";
        $out .= $okL . ":\n";
        $hp = $this->ssa->allocReg();
        $out .= '  ' . $hp . ' = inttoptr i64 ' . $h . " to ptr\n";
        $np = $this->ssa->allocReg();
        $out .= '  ' . $np . ' = getelementptr i8, ptr ' . $hp
              . ', i64 ' . (string)\Compile\MemoryAbi::RMETA_NAME_OFFSET . "\n";
        $nv = $this->ssa->allocReg();
        $out .= '  ' . $nv . ' = load ptr, ptr ' . $np . "\n";
        $ni = $this->ssa->allocReg();
        $out .= '  ' . $ni . ' = ptrtoint ptr ' . $nv . " to i64\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $nullL . ":\n";
        $empty = $this->ssa->allocReg();
        $out .= '  ' . $empty . ' = ptrtoint ptr ' . $this->litStr('') . " to i64\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = phi i64 [ ' . $ni . ', %' . $okL . ' ], [ '
              . $empty . ', %' . $nullL . " ]\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args  spl_object_id($o) — the object pointer as a
     * stable per-object int (unique among live objects). */
    private function biSplObjectId(array $args): string
    {
        $out = $this->emitPtrArg($args[0]);
        $reg = $this->ssa->allocReg();
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
            $this->lastValue = $this->strLitId($this->pool->intern($this->displayClassName($cls)));
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
        $res = $this->ssa->allocReg();
        $out .= '  ' . $res . " = alloca ptr\n";
        $endL = $this->ssa->allocLabel('gc.end');
        $defL = $this->ssa->allocLabel('gc.def');
        $switch = '  switch i64 ' . $cid . ', label %' . $defL . " [\n";
        $bodies = '';
        foreach ($cands as $c) {
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { continue; }
            $caseL = $this->ssa->allocLabel('gc.case');
            $switch .= '    i64 ' . (string)$cd->classId . ', label %' . $caseL . "\n";
            $bodies .= $caseL . ":\n";
            $bodies .= '  store ptr ' . $this->strLitId($this->pool->intern($this->displayClassName($c))) . ', ptr ' . $res . "\n";
            $bodies .= '  br label %' . $endL . "\n";
        }
        $switch .= "  ]\n";
        $out .= $switch . $bodies;
        $out .= $defL . ":\n";
        $out .= '  store ptr ' . $this->strLitId($this->pool->intern($this->displayClassName($cls))) . ', ptr ' . $res . "\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $loaded = $this->ssa->allocReg();
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
        $v = $this->ssa->allocReg();
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
        $v = $this->ssa->allocReg();
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
        $new = $this->ssa->allocReg();
        $out .= '  ' . $new . ' = call ptr @__mir_array_unshift(ptr ' . $arr . ', i64 ' . $val . ")\n";
        $out .= $this->vecWriteBack($arrNode, $new);
        $nl = $this->ssa->allocReg();
        $out .= '  ' . $nl . ' = load i64, ptr ' . $new . "\n";
        $this->lastValue = $nl;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** @param Node[] $args  getenv($name) — `string|false` tagged cell:
     * a copy of the C env string, or boxed false when unset. */
    private function biGetenv(array $args): string
    {
        $this->rt->needsTagged = true;
        $this->libcExtra['getenv'] = 'declare ptr @getenv(ptr)';
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $out = $this->emitPtrArg($args[0]);
        $nm = $this->lastValue;
        $ev = $this->ssa->allocReg();
        $out .= '  ' . $ev . ' = call ptr @getenv(ptr ' . $nm . ")\n";
        $out .= $this->freeStrTemp($args[0], $nm);
        $isnull = $this->ssa->allocReg();
        $out .= '  ' . $isnull . ' = icmp eq ptr ' . $ev . ", null\n";
        $lSet = $this->ssa->allocLabel('genv.set');
        $lNull = $this->ssa->allocLabel('genv.null');
        $lEnd = $this->ssa->allocLabel('genv.end');
        $out .= '  br i1 ' . $isnull . ', label %' . $lNull . ', label %' . $lSet . "\n";
        $out .= $lSet . ":\n";
        $len = $this->ssa->allocReg();
        $out .= '  ' . $len . ' = call i64 @strlen(ptr ' . $ev . ")\n";
        $sz = $this->ssa->allocReg();
        $out .= '  ' . $sz . ' = add i64 ' . $len . ", 1\n";
        $buf = $this->ssa->allocReg();
        $out .= '  ' . $buf . ' = call ptr @__mir_str_alloc(i64 ' . $sz . ")\n";
        $mc = $this->ssa->allocReg();
        $out .= '  ' . $mc . ' = call ptr @memcpy(ptr ' . $buf . ', ptr ' . $ev . ', i64 ' . $sz . ")\n";
        $sc = $this->ssa->allocReg();
        $out .= '  ' . $sc . ' = call i64 @__manticore_box_ptr(ptr ' . $buf . ")\n";
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lNull . ":\n";
        $fc = $this->ssa->allocReg();
        $out .= '  ' . $fc . " = call i64 @__manticore_box_bool(i64 0)\n";
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lEnd . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = phi i64 [' . $sc . ', %' . $lSet . '], ['
              . $fc . ', %' . $lNull . "]\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Ptr to an interned string literal. */
    private function litStr(string $s): string
    {
        return $this->strLitId($this->pool->intern($s));
    }

    // --- Reflection Tier-1 (compile-time class queries, no runtime metadata) ---

    /** Resolve a class name from a reflection arg — a string literal or an
     *  obj-typed value. '' when not statically known (→ Tier-2). */
    private function reflClassName(Node $arg): string
    {
        if ($arg->kind === Node::KIND_STRING_CONST) {
            return \ltrim($arg->value, '\\');
        }
        return \ltrim($arg->type->class ?? '', '\\');
    }

    /** A string-literal arg's value, or '' when not a literal. */
    private function reflLitStr(Node $arg): string
    {
        return $arg->kind === Node::KIND_STRING_CONST
            ? $arg->value : '';
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
        $name = $this->reflClassName($args[0]);
        if ($name === '') {
            // A name known only at run time. This used to fold FALSE — a silent
            // WRONG ANSWER (`$n="F"."oo"; class_exists($n)` said false where php
            // says true), because there was no runtime class table to ask. There
            // is one now: the registry. ReflectAnalysis makes such a call site a
            // reflectAll root, so every class is in it.
            return $this->biExistsDynamic($args[0], $kind);
        }
        $out = $this->reflEvalArgs($args);
        $exists = false;
        if ($kind === 'enum') { $exists = isset($this->enums[$name]); }
        elseif ($kind === 'interface') { $exists = isset($this->interfaceNames[$name]); }
        elseif ($kind === 'trait') { $exists = isset($this->traitNames[$name]); }
        else { $exists = isset($this->classes[$name]) || isset($this->enums[$name]); }
        return $this->biConstBool($out, $exists);
    }

    /**
     * `class_exists($runtimeName)` and friends — answered from the registry.
     *
     * One table serves all four because php's own answers are not uniform and
     * the flags encode exactly that: an ENUM *is* a class (`class_exists('E')`
     * is true) while an INTERFACE and a TRAIT are not. So `class_exists` is
     * "registered AND not interface AND not trait", and each of the others is
     * its own bit.
     *
     * @param Node[] $args
     */
    private function biExistsDynamic(Node $arg, string $kind): string
    {
        $this->rt->needsStrcmp = true;
        $out = $this->emitNode($arg);
        $out .= $this->coerceToPtr();
        $h = $this->ssa->allocReg();
        $out .= '  ' . $h . ' = call i64 @__mc_refl_find(ptr ' . $this->lastValue . ")\n";
        $found = $this->ssa->allocReg();
        $out .= '  ' . $found . ' = icmp ne i64 ' . $h . ", 0\n";
        $lbl = \str_replace('%', '', (string)$this->ssa->allocReg());
        $okL = 'ex.ok.' . $lbl;
        $noL = 'ex.no.' . $lbl;
        $endL = 'ex.end.' . $lbl;
        // An explicit `no` arm: a phi names its PREDECESSOR BLOCK, and the block
        // we branch from here is whatever the caller was already emitting into —
        // not something this function can name.
        $out .= '  br i1 ' . $found . ', label %' . $okL . ', label %' . $noL . "\n";
        $out .= $noL . ":\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $okL . ":\n";
        // Load the flags inline. `__mc_refl_flags` is a BUILTIN — it emits IR at
        // a PHP call site — so there is no LLVM function by that name to call.
        // We are in the non-null arm, so the handle is known good.
        $hptr = $this->ssa->allocReg();
        $out .= '  ' . $hptr . ' = inttoptr i64 ' . $h . " to ptr\n";
        $flp = $this->ssa->allocReg();
        $out .= '  ' . $flp . ' = getelementptr i8, ptr ' . $hptr . ', i64 '
              . (string)\Compile\MemoryAbi::RMETA_FLAGS_OFFSET . "\n";
        $fl = $this->ssa->allocReg();
        $out .= '  ' . $fl . ' = load i64, ptr ' . $flp . "\n";
        $want = $this->ssa->allocReg();
        if ($kind === 'enum') {
            $out .= '  ' . $want . ' = and i64 ' . $fl . ', '
                  . (string)\Compile\MemoryAbi::RMETA_FLAG_ENUM . "\n";
        } elseif ($kind === 'interface') {
            $out .= '  ' . $want . ' = and i64 ' . $fl . ', '
                  . (string)\Compile\MemoryAbi::RMETA_FLAG_INTERFACE . "\n";
        } elseif ($kind === 'trait') {
            $out .= '  ' . $want . ' = and i64 ' . $fl . ', '
                  . (string)\Compile\MemoryAbi::RMETA_FLAG_TRAIT . "\n";
        } else {
            // A class is anything registered that is neither an interface nor a
            // trait — enums included, as php has it.
            $out .= '  ' . $want . ' = and i64 ' . $fl . ', '
                  . (string)(\Compile\MemoryAbi::RMETA_FLAG_INTERFACE
                             | \Compile\MemoryAbi::RMETA_FLAG_TRAIT) . "\n";
        }
        $bit = $this->ssa->allocReg();
        $cmp = $kind === '' || $kind === 'class' ? 'eq' : 'ne';
        $out .= '  ' . $bit . ' = icmp ' . $cmp . ' i64 ' . $want . ", 0\n";
        // i64, not i1: {@see biConstBool} — the static arm of this same builtin —
        // hands back '1'/'0' as i64, and the consumer boxes an i64.
        $bit64 = $this->ssa->allocReg();
        $out .= '  ' . $bit64 . ' = zext i1 ' . $bit . " to i64\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = phi i64 [ 0, %' . $noL . ' ], [ ' . $bit64 . ', %' . $okL . " ]\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** is_callable($x) — see {@see emitIsCallable} for the resolved forms. */
    /**
     * Is `$class::$method` declared `static`? Reads {@see ClassDef::$methodMeta},
     * which carries inherited methods, so no parent walk is needed here.
     *
     * Fails PERMISSIVE: an unknown class, or a method with no user declaration to
     * describe (a synthesised ctor, a property hook), answers true. Those entries
     * are compiler-internal, and folding is_callable to false on one would be a
     * silent wrong answer — the pre-metadata behavior is the safe floor.
     */
    private function methodIsStatic(string $class, string $method): bool
    {
        $cd = $this->classes[\ltrim($class, '\\')] ?? null;
        if ($cd === null) { return true; }
        $mm = $cd->methodMeta[$method] ?? null;
        if ($mm === null) { return true; }
        return $mm->isStatic;
    }

    private function biIsCallable(array $args): string
    {
        // Route through a `Node`-typed helper: reading `$args[0]->type->…` off
        // the untyped array element directly reads a wrong (unpinned) offset
        // under self-host; a typed param pins the node (same discipline as
        // reflClassName). Every concrete arm lives in {@see emitIsCallable}.
        return $this->emitIsCallable($args[0]);
    }

    /**
     * is_callable($x). Compile-time-resolvable forms:
     *  - a "fn" / "C::m" string literal → user-function / method existence fold
     *  - a [recv, "m"] two-element array literal → method_exists fold
     *  - a Closure value, or an object with __invoke → runtime ptr!=0 (a null-arm
     *    closure / null object is NOT callable)
     *  - a tagged cell carrying an object/closure (tag 8) → runtime tag check
     *  Any other static type folds to false. A NON-literal string that names a
     *  function only at runtime needs a runtime function registry we don't keep
     *  → folded false (documented gap; callable literals bound to a `callable`
     *  param are already lowered to closures upstream, so they hit the ptr path).
     *  The `'C::m'` / `['C', 'm']` class-name forms require the method to be
     *  STATIC: PHP 8 needs an instance otherwise. The `[$obj, 'm']` object form
     *  supplies one, so it folds on existence alone.
     *  Still folds on VISIBILITY-blind existence: `is_callable('C::privateStatic')`
     *  from outside C reports true where PHP reports false. Deciding it needs the
     *  calling scope (inside C the same expression IS true), which this emitter
     *  does not thread through. Tracked with the reflection epic.
     */
    private function emitIsCallable(Node $a): string
    {
        // "fn" / "C::m" string literal. A bare name matches a USER function only
        // (builtins/stdlib externs aren't in fnParamTypes — a name like 'strlen'
        // folds false; documented gap, matches the runtime-registry limitation).
        if ($a->kind === Node::KIND_STRING_CONST) {
            $s = $a->value;
            $sep = \strpos($s, '::');
            if ($sep !== false) {
                $c = \ltrim(\substr($s, 0, $sep), '\\');
                $m = \substr($s, $sep + 2);
                // No instance in a 'C::m' string ⇒ PHP 8 needs a static method.
                $ok = $this->resolveMethodClass($c, $m) !== '' && $this->methodIsStatic($c, $m);
            } else {
                $ok = isset($this->sigs->paramTypes[$s]) || isset($this->sigs->paramTypes[\ltrim($s, '\\')]);
            }
            return $this->biConstBool($this->emitNode($a), $ok);
        }
        // [recv, "method"] two-element array literal.
        if ($a->kind === Node::KIND_ARRAY_LIT) {
            if (\count($a->elements) === 2) {
                $recv = $a->elements[0]->value;
                $cls = $this->reflClassName($recv);
                $m   = $this->reflLitStr($a->elements[1]->value);
                $ok  = $cls !== '' && $m !== '' && $this->resolveMethodClass($cls, $m) !== '';
                // ['C', 'm'] names a CLASS — no instance, so PHP 8 needs a static
                // method. [$obj, 'm'] supplies one and stays existence-only.
                if ($ok && $recv->kind === Node::KIND_STRING_CONST && !$this->methodIsStatic($cls, $m)) {
                    $ok = false;
                }
                return $this->biConstBool($this->emitNode($a), $ok);
            }
        }
        $k = $a->type->kind;
        // A Closure — or an object whose class declares __invoke — is callable
        // iff its pointer is non-null. A closure value carries the object type
        // `obj<__closure_N>` (not KIND_CLOSURE), so match that class prefix too.
        $ptrCallable = ($k === Type::KIND_CLOSURE);
        if ($k === Type::KIND_OBJ) {
            $ocls = $a->type->class ?? '';
            if ($ocls !== ''
                && (\str_starts_with($ocls, '__closure_')
                    || $this->resolveMethodClass($ocls, '__invoke') !== '')) {
                $ptrCallable = true;
            }
        }
        if ($ptrCallable) {
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $ne = $this->ssa->allocReg();
            $out .= '  ' . $ne . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $ne . " to i64\n";
            return $this->finishI64($out, $z);
        }
        // A tagged cell that carries an object/closure (tag 8) is callable.
        if ($k === Type::KIND_CELL) {
            $this->rt->needsTagged = true;
            $out = $this->emitNode($a);
            $out .= $this->coerceToI64();
            $out .= $this->cellTagIr($this->lastValue);
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i64 ' . $this->cellTagReg . ", 8\n";
            $z = $this->ssa->allocReg();
            $out .= '  ' . $z . ' = zext i1 ' . $eq . " to i64\n";
            return $this->finishI64($out, $z);
        }
        // int/float/bool/null/plain object/non-literal string/array → not callable.
        return $this->biConstBool($this->emitNode($a), false);
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
        $target = $this->reflClassName($args[1]);
        // A runtime-valued target class (`$obj instanceof $cls`, lowered to is_a):
        // the name is only known at runtime, so enumerate the module's classes and
        // match the runtime string against each, testing the operand's class_id
        // against that class's is-a id set (same idiom as emitNewDynObj).
        if ($target === '' && $this->classArgIsRuntime($args[1])) {
            return $this->biIsADynamic($args, $strict);
        }
        $out = $this->reflEvalArgs($args);
        $sub = $this->reflClassName($args[0]);
        $r = $sub !== '' && $target !== ''
            && (!$strict || $sub !== $target)
            && $this->classIsA($sub, $target);
        return $this->biConstBool($out, $r);
    }

    /** A target-class arg named by a runtime value (string / cell / unknown),
     *  not a compile-time class name. */
    private function classArgIsRuntime(Node $arg): bool
    {
        $k = $arg->type->kind;
        return $k === Type::KIND_STRING || $k === Type::KIND_CELL
            || $k === Type::KIND_UNKNOWN;
    }

    /** is_a / is_subclass_of with a runtime target class name. Loads the
     *  operand's class_id once (guarding null / non-object cells / scalars),
     *  then ORs, over every module class C, `strcmp(name,"C")==0 AND class_id
     *  in idsOf(C)`. `idsOf` is C's is-a set (descendants + implementers); the
     *  strict form drops C's own id (proper-subclass only). */
    private function biIsADynamic(array $args, bool $strict): string
    {
        // A scalar operand (string/int/float/bool/null) is an instance of
        // nothing — the 2-arg is_a never treats a string as a class name.
        $ok = $args[0]->type->kind;
        if ($ok === Type::KIND_STRING || $ok === Type::KIND_INT
            || $ok === Type::KIND_FLOAT || $ok === Type::KIND_BOOL
            || $ok === Type::KIND_NULL) {
            $out = $this->reflEvalArgs($args);
            return $this->biConstBool($out, false);
        }
        $this->rt->needsStrcmp = true;
        $out = $this->emitNode($args[0]);
        $out .= $this->coerceToI64();
        $obj = $this->lastValue;
        $out .= $this->emitNode($args[1]);
        $out .= $this->coerceToI64();
        $strP = $this->ssa->allocReg();
        $out .= '  ' . $strP . ' = inttoptr i64 ' . $this->lastValue . " to ptr\n";

        $slot = $this->ssa->allocReg();
        $out .= '  ' . $slot . " = alloca i64\n";
        $out .= '  store i64 0, ptr ' . $slot . "\n";
        $objL = $this->ssa->allocLabel('isa.obj');
        $doneL = $this->ssa->allocLabel('isa.done');

        if ($ok === Type::KIND_CELL) {
            $out .= $this->cellTagIr($obj);
            $isObj = $this->ssa->allocReg();
            $out .= '  ' . $isObj . ' = icmp eq i64 ' . $this->cellTagReg . ", 8\n";
            $out .= '  br i1 ' . $isObj . ', label %' . $objL . ', label %' . $doneL . "\n";
            $out .= $objL . ":\n";
            $payload = $this->ssa->allocReg();
            $out .= '  ' . $payload . ' = and i64 ' . $obj . ", 281474976710655\n";
            $objp = $this->ssa->allocReg();
            $out .= '  ' . $objp . ' = inttoptr i64 ' . $payload . " to ptr\n";
        } else {
            $isNull = $this->ssa->allocReg();
            $out .= '  ' . $isNull . ' = icmp eq i64 ' . $obj . ", 0\n";
            $out .= '  br i1 ' . $isNull . ', label %' . $doneL . ', label %' . $objL . "\n";
            $out .= $objL . ":\n";
            $objp = $this->ssa->allocReg();
            $out .= '  ' . $objp . ' = inttoptr i64 ' . $obj . " to ptr\n";
        }
        $out .= $this->emitLoadClassId($objp);
        $cid = $this->classIdReg;
        // Candidate target names: every class AND interface — a target may be an
        // interface or ancestor the operand only is-a transitively (Dog is-a the
        // Animal interface). Interfaces live outside $this->classes.
        //
        // Iterate each assoc's KEYS DIRECTLY — deliberately not `$t =
        // array_keys($this->classes); foreach($ifaces) $t[] = $i;`. That
        // array_keys-plus-append combined list monomorphizes to a repr the
        // foreach then mis-reads (a garbage `$name` → a wild string-header read
        // in classIsA), the array-union miscompile family. See the arm helper.
        $acc = '';
        foreach ($this->classes as $name => $cd) {
            $arm = $this->emitInstanceofArm($name, $strP, $cid, $strict, $acc);
            $out .= $arm[0];
            $acc = $arm[1];
        }
        foreach ($this->interfaceNames as $name => $ignore) {
            $arm = $this->emitInstanceofArm($name, $strP, $cid, $strict, $acc);
            $out .= $arm[0];
            $acc = $arm[1];
        }
        if ($acc !== '') {
            $ext = $this->ssa->allocReg();
            $out .= '  ' . $ext . ' = zext i1 ' . $acc . " to i64\n";
            $out .= '  store i64 ' . $ext . ', ptr ' . $slot . "\n";
        }
        $out .= '  br label %' . $doneL . "\n";
        $out .= $doneL . ":\n";
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = load i64, ptr ' . $slot . "\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * One dynamic-instanceof arm for target class/interface `$name`: the whole
     * is-a is true when the operand's runtime name equals `$name` AND its class
     * id is one of `$name`'s subtype ids. Returns `[ir, newAcc]` (both strings)
     * — `$acc` threads the running OR chain. Extracted so
     * {@see biIsADynamic} can iterate `$this->classes` and `$this->interfaceNames`
     * KEYS directly instead of building an `array_keys()+append` list that
     * miscompiles (see the call site).
     *
     * @return string[] [irToAppend, newAcc]
     */
    private function emitInstanceofArm(string $name, string $strP, string $cid, bool $strict, string $acc): array
    {
        $ids = $this->instanceofMatchIds($name);
        if ($strict) {
            $ownId = isset($this->classes[$name]) ? $this->classes[$name]->classId : -1;
            $keep = [];
            foreach ($ids as $id) { if ($id !== $ownId) { $keep[] = $id; } }
            $ids = $keep;
        }
        if ($ids === []) { return ['', $acc]; }
        $lit = $this->strLitId($this->pool->intern($name));
        $cmp = $this->ssa->allocReg();
        $out = '  ' . $cmp . ' = call i32 @strcmp(ptr ' . $strP . ', ptr ' . $lit . ")\n";
        $nameEq = $this->ssa->allocReg();
        $out .= '  ' . $nameEq . ' = icmp eq i32 ' . $cmp . ", 0\n";
        $out .= $this->emitClassIdMatch($cid, $ids);
        $both = $this->ssa->allocReg();
        $out .= '  ' . $both . ' = and i1 ' . $nameEq . ', ' . $this->classIdMatchReg . "\n";
        if ($acc === '') { return [$out, $both]; }
        $or = $this->ssa->allocReg();
        $out .= '  ' . $or . ' = or i1 ' . $acc . ', ' . $both . "\n";
        return [$out, $or];
    }

    /** get_parent_class($obj|'C') — parent name string, or boxed false. */
    private function biGetParentClass(array $args): string
    {
        $this->rt->needsTagged = true;
        $out = \count($args) >= 1 ? $this->reflEvalArgs($args) : '';
        $cls = \count($args) >= 1 ? $this->reflClassName($args[0]) : '';
        $parent = ($cls !== '' && isset($this->classes[$cls]))
            ? \ltrim($this->classes[$cls]->parent, '\\') : '';
        $r = $this->ssa->allocReg();
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
        $this->rt->needsTagged = true;
        $out = $this->reflEvalArgs($args);
        $cls = $this->reflClassName($args[0]);
        $names = $this->reflAllMethods($cls);
        $cur = $this->ssa->allocReg();
        $out .= '  ' . $cur . ' = call ptr @__mir_array_alloc(i64 '
              . (string)\count($names) . ")\n";
        foreach ($names as $nm) {
            $b = $this->ssa->allocReg();
            $out .= '  ' . $b . ' = call i64 @__manticore_box_ptr(ptr '
                  . $this->litStr($nm) . ")\n";
            $nx = $this->ssa->allocReg();
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
        $this->rt->needsTagged = true;
        $obj = $args[0];
        $out = $this->emitNode($obj);
        $out .= $this->coerceToPtr();
        $objp = $this->lastValue;
        $initg = $this->ssa->allocReg();
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
                $g = $this->ssa->allocReg();
                $out .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objp . ', i64 ' . $off . "\n";
                $v = $this->ssa->allocReg();
                $out .= '  ' . $v . ' = load i64, ptr ' . $g . "\n";
                // Box the raw i64 carrier to a tagged cell per its static type.
                $this->lastValue = $v;
                $this->lastValueType = 'i64';
                $out .= $this->boxToCell($pt);
                $boxed = $this->lastValue;
                $next = $this->ssa->allocReg();
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
    /**
     * `var_export($v)` prints and returns null; `var_export($v, true)` returns
     * the string and prints nothing. Only the second form worked — the $return
     * argument was ignored and the string was always just handed back, so a
     * bare `var_export($v);` statement printed nothing at all.
     *
     * Echo mode yields "" rather than null, so `echo var_export(1);` still
     * prints exactly `1` (php.net echoes the null to the same effect).
     * @param Node[] $args
     */
    private function biVarExport(array $args): string
    {
        $out = $this->varExportStr($args);
        $wantString = \count($args) >= 2
            && $args[1]->kind === Node::KIND_BOOL_CONST
            && (bool)$args[1]->value;
        if ($wantString) {
            return $out;
        }
        $this->libcExtra['printf'] = 'declare i32 @printf(ptr, ...)';
        $out .= $this->coerceToPtr();
        $buf = $this->lastValue;
        $pcts = $this->strLitId($this->pool->intern('%s'));
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i32 (ptr, ...) @printf(ptr ' . $pcts
              . ', ptr ' . $buf . ")\n";
        $this->lastValue = $this->litStr('');
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  The var_export text for $args[0], as a ptr in lastValue. */
    private function varExportStr(array $args): string
    {
        $this->rt->needsConcat = true;
        $k = $args[0]->type->kind;
        if ($k === Type::KIND_NULL) {
            $this->lastValue = $this->litStr('NULL');
            $this->lastValueType = 'ptr';
            return '';
        }
        if ($k === Type::KIND_BOOL) {
            $out = $this->emitNode($args[0]);
            $out .= $this->coerceToI64();
            $b = $this->ssa->allocReg();
            $out .= '  ' . $b . ' = icmp ne i64 ' . $this->lastValue . ", 0\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = select i1 ' . $b . ', ptr ' . $this->litStr('true')
                  . ', ptr ' . $this->litStr('false') . "\n";
            $this->lastValue = $r;
            $this->lastValueType = 'ptr';
            return $out;
        }
        if ($k === Type::KIND_INT) {
            $this->rt->needsIntStr = true;
            $out = $this->emitNode($args[0]);
            $out .= $this->coerceToI64();
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = call ptr @__mir_int_to_str(i64 ' . $this->lastValue . ")\n";
            $this->lastValue = $r;
            $this->lastValueType = 'ptr';
            return $out;
        }
        if ($k === Type::KIND_FLOAT) {
            // Ryu shortest, var_export form: uppercase E AND a forced `.0` on an
            // integer-valued decimal (100.0 -> "100.0") so it re-parses as float.
            $out = $this->emitNode($args[0]);
            $out .= $this->coerceTo('double');
            $bitsr = $this->ssa->allocReg();
            $out .= '  ' . $bitsr . ' = bitcast double ' . $this->lastValue . " to i64\n";
            $fsi = $this->ssa->allocReg();
            $out .= '  ' . $fsi . ' = call i64 @manticore___mc_dtoa_core(i64 ' . $bitsr . ', i64 1, i64 1)' . "\n";
            $r = $this->ssa->allocReg();
            $out .= '  ' . $r . ' = inttoptr i64 ' . $fsi . " to ptr\n";
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
        // Arrays, `mixed` and unions: the type is only known from the NaN tag at
        // runtime, so hand off to the stdlib formatter rather than emitting a
        // recursive walk inline. boxToCell rebuilds a homogeneous array with its
        // elements boxed, which is exactly what that walk needs to read. Declare
        // the extern only when it is not defined in-module (a self-contained
        // build embeds the define; a second declare is a redefinition error).
        $out = $this->emitNode($args[0]);
        $out .= $this->boxToCell($args[0]->type);
        $cv = $this->lastValue;
        if (!isset($this->definedFns[$this->mangle('__mc_var_export_cell')])) {
            $this->libcExtra['manticore___mc_var_export_cell']
                = 'declare i64 @manticore___mc_var_export_cell(i64, i64)';
        }
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = call i64 @manticore___mc_var_export_cell(i64 '
              . $cv . ", i64 0)\n";
        $p = $this->ssa->allocReg();
        $out .= '  ' . $p . ' = inttoptr i64 ' . $r . " to ptr\n";
        $this->lastValue = $p;
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
        $isnull = $this->ssa->allocReg();
        $out = '  ' . $isnull . ' = icmp eq ptr ' . $s . ", null\n";
        $lNull = $this->ssa->allocLabel('we.null');
        $lSet = $this->ssa->allocLabel('we.set');
        $lEnd = $this->ssa->allocLabel('we.end');
        $out .= '  br i1 ' . $isnull . ', label %' . $lNull . ', label %' . $lSet . "\n";
        $out .= $lSet . ":\n";
        $c1 = $this->ssa->allocReg();
        $out .= '  ' . $c1 . ' = call ptr @__mir_concat(ptr ' . $this->litStr($pre) . ', ptr ' . $s . ")\n";
        $c2 = $this->ssa->allocReg();
        $out .= '  ' . $c2 . ' = call ptr @__mir_concat(ptr ' . $c1 . ', ptr ' . $this->litStr($post) . ")\n";
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lNull . ":\n";
        $nl = $this->litStr($nullLit);
        $out .= '  br label %' . $lEnd . "\n";
        $out .= $lEnd . ":\n";
        $r = $this->ssa->allocReg();
        $out .= '  ' . $r . ' = phi ptr [' . $c2 . ', %' . $lSet . '], [' . $nl . ', %' . $lNull . "]\n";
        $this->lastValue = $r;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** @param Node[] $args  addslashes($s) — backslash-escape ' " \. */
    private function biAddslashes(array $args): string
    {
        $this->rt->needsAddslashes = true;
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_addslashes(ptr ' . $a0 . ")\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
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
        $this->rt->needsJsonEscape = true;
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $out = $this->emitPtrArg($args[0]);
        $a0 = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_json_escape(ptr ' . $a0 . ")\n";
        $out .= $this->freeStrTemp($args[0], $a0);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /**
     * `json_encode($value)` — native single-buffer encoder. Boxes the arg to a
     * cell and recurses through `@__mir_json_enc`, which walks the value into ONE
     * growing buffer (php smart_str style): ints via `__mir_int_fmt` and string
     * escaping written straight into the buffer (no per-node temp), floats via
     * the Ryu shortest-decimal formatter `manticore___mc_dtoa_bits` (byte-exact
     * with php's serialize_precision=-1). An OBJECT cell falls back to the
     * compiled reference `@manticore___mc_json_enc` for parity. @param Node[] $args
     */
    private function biJsonEncode(array $args): string
    {
        $this->rt->needsJsonEnc = true;
        $this->rt->needsIntStr = true;    // __mir_int_len / __mir_int_fmt + unbox_int
        $this->rt->needsStrRc = true;     // __mir_rc_release_str (float / object temps)
        $this->rt->needsConcat = true;    // __mir_strlen + string runtime decls
        $this->rt->needsTagged = true;    // box helpers for boxToCell
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        // NB: manticore___mc_json_enc is auto-injected as a stdlib extern
        // (declare i64 @…(i64)); do NOT re-declare it here (signature clash).
        $out = $this->emitNode($args[0]);
        $out .= $this->boxToCell($args[0]->type);
        $cell = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_json_enc(i64 ' . $cell . ")\n";
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
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
        $this->rt->needsStrReplaceOne = true;
        $this->libcExtra['strlen'] = 'declare i64 @strlen(ptr)';
        $this->libcExtra['strstr'] = 'declare ptr @strstr(ptr, ptr)';
        $this->libcExtra['memchr'] = 'declare ptr @memchr(ptr, i32, i64)';
        $this->libcExtra['memcpy'] = 'declare ptr @memcpy(ptr, ptr, i64)';
        $out = $this->emitPtrArg($args[0]);
        $se = $this->lastValue;
        $out .= $this->emitPtrArg($args[1]);
        $rp = $this->lastValue;
        $out .= $this->emitPtrArg($args[2]);
        $sj = $this->lastValue;
        $reg = $this->ssa->allocReg();
        $out .= '  ' . $reg . ' = call ptr @__mir_str_replace_one(ptr ' . $se
              . ', ptr ' . $rp . ', ptr ' . $sj . ")\n";
        $out .= $this->freeStrTemp($args[0], $se);
        $out .= $this->freeStrTemp($args[1], $rp);
        $out .= $this->freeStrTemp($args[2], $sj);
        $this->lastValue = $reg;
        $this->lastValueType = 'ptr';
        return $out;
    }

    /** Write a relocated vec ptr back through `$arrNode`'s local or
     * property storage (shared by array_unshift / future realloc ops). */
    private function vecWriteBack(Node $arrNode, string $arr2, bool $asCell = false): string
    {
        if ($arrNode->kind === Node::KIND_LOAD_LOCAL) {
            $name = $arrNode->name;
            // Global-backed (`global $arr`): the (possibly realloced) buffer must
            // be stored back into the module cell so `$arr[] = …` inside one
            // function is visible to `__main` and every other `global $arr` scope.
            // Without this the append result is discarded and the global keeps the
            // stale base.
            if (isset($this->locals->globalBacked[$name])) {
                $asI = $this->ssa->allocReg();
                $out = $this->packArrayBack($arr2, $asI, $asCell);
                $out .= '  store i64 ' . $asI . ', ptr '
                      . $this->locals->globalBacked[$name] . "\n";
                return $out;
            }
            if (!isset($this->locals->slots[$name])) { return ''; }
            $asI = $this->ssa->allocReg();
            $out = $this->packArrayBack($arr2, $asI, $asCell);
            if (isset($this->locals->refLocals[$name])) {
                // By-ref param: the slot holds the CALLER's address — store the
                // (possibly realloced) buffer THROUGH it, so `$arr[] = …` on a
                // `&$arr` is visible to the caller. Writing the slot directly
                // would clobber the address and leave the caller's array stale.
                $addr = $this->ssa->allocReg();
                $out .= '  ' . $addr . ' = load i64, ptr ' . $this->locals->slots[$name] . "\n";
                $p = $this->ssa->allocReg();
                $out .= '  ' . $p . ' = inttoptr i64 ' . $addr . " to ptr\n";
                $out .= '  store i64 ' . $asI . ', ptr ' . $p . "\n";
            } else {
                $out .= '  store i64 ' . $asI . ', ptr ' . $this->locals->slots[$name] . "\n";
            }
            return $out;
        }
        if ($arrNode->kind === Node::KIND_PROPERTY_ACCESS) {
            $out = $this->emitNode($arrNode->object);
            $out .= $this->coerceToPtr();
            $objp = $this->lastValue;
            $off = $this->propertyOffset($arrNode->object, $arrNode->property);
            $g = $this->ssa->allocReg();
            $out .= '  ' . $g . ' = getelementptr inbounds i8, ptr ' . $objp
                  . ', i64 ' . (string)$off . "\n";
            $asI = $this->ssa->allocReg();
            $out .= $this->packArrayBack($arr2, $asI, $asCell);
            $out .= '  store i64 ' . $asI . ', ptr ' . $g . "\n";
            return $out;
        }
        // Nested element store (`$m[k][...] = v`): the modified inner array
        // must be re-stored into the parent at its key, and the (possibly
        // realloced) parent threaded further up the chain. Without this the
        // inner mutation lands on a copy the parent never sees.
        if ($arrNode->kind === Node::KIND_ARRAY_ACCESS) {
            $parentCell = $arrNode->array->type->kind === Type::KIND_CELL;
            $out = $this->emitNode($arrNode->array);
            $out .= $parentCell ? $this->cellToPtr() : $this->coerceToPtr();
            $parentPtr = $this->lastValue;
            // The inner array is stored back as the parent's element; box it
            // when the parent holds cells (mixed/cell element type). A parent
            // that is ITSELF a cell (`$g[a][b][c]`: the middle `$g[a]` reads out
            // of a cell array as a bare cell — {@see InferNodes::inferArrayAccess})
            // is a mixed container whose elements are cells, so box there too,
            // else the deeper level stores the middle array RAW → var_dump garbage.
            $innerCell = $parentCell
                || ($arrNode->array->type->element !== null
                    && $arrNode->array->type->element->kind === Type::KIND_CELL);
            $valI = $this->ssa->allocReg();
            $out .= $this->packArrayBack($arr2, $valI, $innerCell);
            $keyIsCell = $arrNode->index->type->kind === Type::KIND_CELL;
            $keyIsString = !$keyIsCell
                && ($arrNode->index->type->kind === Type::KIND_STRING
                    || $arrNode->index->kind === Node::KIND_STRING_CONST);
            $parent2 = $this->ssa->allocReg();
            if ($keyIsCell) {
                $this->rt->needsCellKey = true;
                $out .= $this->emitNode($arrNode->index);
                $out .= $this->coerceToI64();
                $key = $this->lastValue;
                $out .= '  ' . $parent2 . ' = call ptr @__mir_array_set_cell(ptr '
                      . $parentPtr . ', i64 ' . $key . ', i64 ' . $valI . ")\n";
            } elseif ($keyIsString) {
                $out .= $this->emitNode($arrNode->index);
                $out .= $this->coerceToPtr();
                $key = $this->lastValue;
                $out .= '  ' . $parent2 . ' = call ptr @__mir_array_set_str(ptr '
                      . $parentPtr . ', ptr ' . $key . ', i64 ' . $valI
                      . $this->litKeyHashArgs($arrNode->index) . ")\n";
            } else {
                $out .= $this->emitNode($arrNode->index);
                $out .= $this->coerceToI64();
                $idx = $this->lastValue;
                $out .= '  ' . $parent2 . ' = call ptr @__mir_array_set_int(ptr '
                      . $parentPtr . ', i64 ' . $idx . ', i64 ' . $valI . ")\n";
            }
            $out .= $this->vecWriteBack($arrNode->array, $parent2, $parentCell);
            return $out;
        }
        // `Class::$arr[k] = v` — thread the (possibly realloced) buffer back
        // into the static-property global cell, else the next read reloads the
        // stale pre-grow pointer (a first string-keyed store reallocs from the
        // empty `[]` default → the write is silently lost).
        if ($arrNode->kind === Node::KIND_STATIC_PROP) {
            $asI = $this->ssa->allocReg();
            $out = $this->packArrayBack($arr2, $asI, $asCell);
            $out .= '  store i64 ' . $asI . ', ptr ' . $arrNode->global . "\n";
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
            $this->rt->needsTagged = true;
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
            $f = $this->ssa->allocReg();
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
            $p = $this->ssa->allocReg();
            $this->lastValue = $p;
            $this->lastValueType = 'ptr';
            return '  ' . $p . ' = inttoptr i64 ' . $reg . " to ptr\n";
        }
        $this->lastValue = $reg;
        $this->lastValueType = 'i64';
        return '';
    }
}
