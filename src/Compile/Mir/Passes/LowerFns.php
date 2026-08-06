<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayElement_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\ClassDef;
use Compile\Mir\EnumDef;
use Compile\Mir\BoolConst;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Walk;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\Yield_;
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
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;
use Parser\Ast\Program;

/**
 * Functions, closures and callables: signatures, default-arg filling, capture
 * analysis, the invoke path.
 *
 * A trait on the one {@see LowerFromAst} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait LowerFns
{
    private function lowerFunction(\Parser\Ast\FunctionDecl $decl): FunctionDef
    {
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        $this->constCallables = [];
        $this->setCurrentLowerParams($decl->params);
        $this->scanStableCallables($decl->body->statements);
        // `#[RefOut('a', 'b')]` names the pure-output by-ref params (portable
        // across .sig + self-host parse); `@param-out T $x` is the PHPStan-side
        // equivalent.
        $refOutNames = $this->refOutParamNames($decl->attributes);
        $cellArgNames = $this->cellArgParamNames($decl->attributes);
        $params = [];
        foreach ($decl->params as $p) {
            $isVariadic = (bool)($p->variadic ?? false);
            // `T ...$xs` collects trailing args into a vec[T] the callee
            // sees as a single vec param (caller packs at the call site).
            $outType = $this->docTagType($decl->docComment, '@param-out', $p->name);
            $effHint = $this->effectiveHint(
                $p->typeHint,
                $outType ?? $this->docTagType($decl->docComment, '@param', $p->name),
            );
            $pt = $isVariadic
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerParamType($effHint);
            $fp = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVariadic,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
            $fp->arrayHinted = $this->isBareArrayHint($p->typeHint) || $pt->isArray();
            // A VARIADIC pack is genuinely 0..n — its vec is the compiler's own
            // and its keys are not in question.
            $fp->docList = !$isVariadic && $this->isElemOnlyArrayDoc($effHint);
            $fp->refOut = $outType !== null || isset($refOutNames[$p->name])
                || $this->paramHasRefOutAttr($p);
            $fp->cellArg = isset($cellArgNames[$p->name]) || $this->paramHasCellArgAttr($p);
            $params[] = $fp;
        }
        $this->currentLowerClass = '';
        $this->currentTypeParams = [];
        $this->currentLowerFn = $decl->name;
        // FFI: `#[Symbol('cSym')]` makes this a thin extern forward — the
        // body (a stock-PHP fallback like `$GLOBALS['argc']`) is never
        // lowered; EmitLlvm emits a wrapper that calls the C symbol.
        $ffiSymbol = $this->ffiSymbolOf($decl->attributes);
        if ($ffiSymbol !== null) {
            $fn = new FunctionDef(
                name: $decl->name,
                params: $params,
                returnType: $this->lowerTypeHint($decl->returnType),
                body: new Block([], Type::void()),
                returnsByRef: false,
            );
            $fn->ffiSymbol = $ffiSymbol;
            $fn->ffiWeak = $this->ffiIsWeak($decl->attributes);
            $fn->ffiLibrary = $this->ffiLibraryOf($decl->attributes);
            $fn->ffiVariadicFixed = $this->ffiVariadicFixed(
                $decl->attributes, \count($decl->params), $decl->span, $decl->name);
            // A PARAMETER-level `#[Ffi\CType]` names the C prototype's own type
            // for that argument; the PHP hint is only the fallback, and `int`
            // cannot distinguish C's char/short/int/long. Getting it right
            // matters most for a VARIADIC arg, where the callee's `va_arg(ap, T)`
            // reads a fixed width off the stack rather than a register the ABI
            // was free to leave dirty.
            $ctypes = [];
            foreach ($decl->params as $p) {
                $where = 'parameter $' . $p->name . ' of ' . $decl->name . '()';
                $tok = $this->paramCTypeToken($p, $where);
                $llvm = '';
                if ($tok !== '') {
                    $llvm = $this->ffiResolveCType($tok, $p->typeHint, false,
                        $where, $p->span);
                }
                $ctypes[] = $llvm !== '' ? $llvm : $this->ffiCType($p->typeHint);
            }
            $fn->ffiParamCTypes = $ctypes;
            // A FUNCTION-level `#[Ffi\CType]` states the C RETURN's real type,
            // which the PHP hint cannot: `int` covers C's char/short/int/long
            // alike, and the wrapper has to know the width to extend correctly.
            // Without it a C function returning -1 in w0 (`mov w0, #-1` zeroes
            // the top half) reads as 4294967295 through an i64 declare.
            $retTok = $this->ffiCTypeToken($decl->attributes,
                $decl->name . '()', $decl->span);
            $retLlvm = '';
            if ($retTok !== '') {
                $retLlvm = $this->ffiResolveCType($retTok, $decl->returnType, true,
                    $decl->name . '()', $decl->span);
            }
            $fn->ffiRetCType = $retLlvm !== ''
                ? $retLlvm
                : $this->ffiCType($decl->returnType);
            $fn->ffiRetUnsigned = $retLlvm !== ''
                && \Compile\Mir\FfiCTypes::isUnsigned($retTok);
            return $fn;
        }
        $savedSawYield = $this->sawYield;
        $this->sawYield = false;
        $savedSawFuncArgs = $this->sawFuncArgs;
        $this->sawFuncArgs = false;
        $loweredBody = $this->lowerBlockNode($decl->body);
        $isGen = $this->sawYield;
        $this->sawYield = $savedSawYield;
        $usesFuncArgs = $this->sawFuncArgs;
        $this->sawFuncArgs = $savedSawFuncArgs;
        if ($usesFuncArgs) {
            $loweredBody = $this->withFuncArgsPrologue($loweredBody, \count($params));
        }
        $fn = new FunctionDef(
            name: $decl->name,
            params: $params,
            returnType: $this->lowerTypeHint($this->effectiveHint(
                $decl->returnType,
                $this->docTagType($decl->docComment, '@return', ''),
            )),
            body: $loweredBody,
            returnsByRef: (bool)($decl->returnsByRef ?? false),
        );
        $fn->isGenerator = $isGen;
        $fn->usesFuncArgs = $usesFuncArgs;
        if ($fn->isGenerator) {
            // A generator CALL returns a Generator (its frame ptr); type it so
            // foreach / InferTypes route through the iterator-protocol path.
            // Keep a declared `: Generator<V>` element as the seed; InferTypes
            // refines it from the yield expressions.
            $declared = $fn->returnType;
            $elem = $declared->isGenerator() ? $declared->element : null;
            $fn->returnType = Type::generator($elem);
        }
        return $fn;
    }

    /**
     * Build a SIGNATURE-ONLY {@see FunctionDef} from a bundled-stdlib decl:
     * params (with defaults, for call-site filling) + return type, but an
     * empty body. EmitLlvm renders it as a `declare`; the body comes from the
     * linked stdlib.o. The body is deliberately never lowered — that avoids
     * both the per-program-merge codegen hazard and any output bloat.
     */
    private function lowerFunctionSignature(\Parser\Ast\FunctionDecl $decl): FunctionDef
    {
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        $this->currentLowerClass = '';
        $this->currentTypeParams = [];
        $this->currentLowerFn = $decl->name;
        // `#[RefOut('a', 'b')]` on the function names its pure-output by-ref
        // params (survives the .sig + self-host parse; a param-position marker
        // does not). `@param-out T $x` is the PHPStan-compatible equivalent.
        $refOutNames = $this->refOutParamNames($decl->attributes);
        $cellArgNames = $this->cellArgParamNames($decl->attributes);
        $params = [];
        foreach ($decl->params as $p) {
            $isVariadic = (bool)($p->variadic ?? false);
            // EXTERN sig (from the stdlib .sig): an EMPTY type ("") means the
            // stdlib ERASED it to unknown (a genuine `mixed` serializes as
            // "mixed" → cell). Lower it to UNKNOWN via lowerTypeHint, NOT
            // lowerParamType (whose null→cell default makes the CALLER box an
            // array arg to a cell while the raw-walking stdlib callee reads it
            // as a plain array pointer → tag deref SIGSEGV: array_key_exists /
            // array_slice on a concrete assoc). A user function's own untyped
            // param still routes through lowerParamType (mixed) elsewhere.
            // `@param-out T $x` (PHPStan's ref-out tag) both types the OUT value
            // and marks the param `#[RefOut]` — and, unlike a param attribute,
            // survives the interface `.sig`, so it is the portable signal.
            $outType = $this->docTagType($decl->docComment, '@param-out', $p->name);
            $pt = $isVariadic
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerTypeHint($this->effectiveHint(
                    $p->typeHint,
                    $outType ?? $this->docTagType($decl->docComment, '@param', $p->name),
                ));
            $fnp = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVariadic,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
            $fnp->refOut = $outType !== null || isset($refOutNames[$p->name]);
            // The `.sig`-carried CellArg flag (declsFromJson set $p->cellArg) is
            // the cross-module signal: a consumer sees only the interface, so this
            // is how fputcsv's element-consuming `$fields` reaches the caller.
            $fnp->cellArg = $this->paramCellArg($p) || isset($cellArgNames[$p->name]);
            $params[] = $fnp;
        }
        return new FunctionDef(
            name: $decl->name,
            params: $params,
            returnType: $this->lowerTypeHint($this->effectiveHint(
                $decl->returnType,
                $this->docTagType($decl->docComment, '@return', ''),
            )),
            body: new Block([], Type::void()),
            returnsByRef: (bool)($decl->returnsByRef ?? false),
        );
    }

    /**
     * True for a function name that {@see Passes\EmitLlvmBuiltins::emitBuiltin}
     * emits inline. Such a name must NOT be registered as a stdlib extern: the
     * builtin intercepts the call (so the extern declare would be dead) and,
     * worse, registering it would change default-arg filling at every call
     * site. Mirrors the emitBuiltin if-chain — keep in sync.
     */
    /**
     * A name `emitBuiltin` handles inline that {@see isCodegenBuiltin} does NOT
     * list — the two sets differ on purpose. isCodegenBuiltin decides whether to
     * skip injecting a stdlib extern; this one answers the different question
     * `function_exists` asks: will a call to this name resolve at all?
     *
     * Without it `function_exists('floor')`, `('var_dump')`, `('explode')`,
     * `('json_encode')` and forty others answered FALSE for functions that
     * plainly work, because a builtin is emitted inline and so is declared
     * nowhere. That is exactly the predicate the polyfill idiom tests.
     *
     * Internal names (`__mir_*`, `__mc_*`, `manticore_*`) are left out: nobody
     * writes them in a function_exists guard. `tools/audit/calibrate.sh` gates
     * the union of the two lists against the real dispatch, so adding a builtin
     * without updating one of them fails there rather than here.
     */
    /**
     * Function names the PRELUDE declares, whether or not this program pulled
     * the file in.
     *
     * The prelude is DEMAND-GATED on a mention that looks like use, and a name
     * appearing only inside `function_exists('x')` deliberately does not
     * count — injecting a whole prelude file because someone asked after it
     * would undo the gating. But then the answer must not be derived from
     * whether injection happened, or `function_exists('ob_start')` says false
     * in a program that would run ob_start perfectly well the moment it called
     * it. It measured its own gate. 23 names answered wrong that way, the whole
     * header/session/ob/error-handler surface among them.
     *
     * Answering true here is safe in the direction that matters: a program that
     * goes on to CALL the name makes a real mention, which injects the file.
     *
     * Hardcoded rather than scanned: listing prelude/ from inside the compiled
     * binary would add a cold-seed bootstrap dependency. `tools/audit/calibrate.sh`
     * derives this set from prelude/*.php and fails on drift — hardcode at
     * runtime, derive at gate time, the same contract {@see isEmitterInlineName}
     * has.
     */
    /** Whether the prelude declares $n (see {@see preludeProvidedNames}). */
    private function isPreludeProvidedName(string $n): bool
    {
        foreach ($this->preludeProvidedNames() as $k) {
            if ($k === $n) { return true; }
        }
        return false;
    }

    /** @return string[] */
    private function preludeProvidedNames(): array
    {
        return [
            'array_all', 'array_any', 'array_change_key_case', 'array_chunk', 'array_combine',
            'array_count_values', 'array_diff', 'array_diff_assoc', 'array_diff_key', 'array_diff_uassoc',
            'array_diff_ukey', 'array_fill_keys', 'array_filter', 'array_find', 'array_find_key',
            'array_flip', 'array_intersect', 'array_intersect_assoc', 'array_intersect_key', 'array_intersect_uassoc',
            'array_intersect_ukey', 'array_map', 'array_merge', 'array_merge_recursive', 'array_pad',
            'array_product', 'array_push', 'array_rand', 'array_reduce', 'array_replace',
            'array_replace_recursive', 'array_reverse', 'array_slice', 'array_splice', 'array_sum',
            'array_udiff', 'array_udiff_assoc', 'array_udiff_uassoc', 'array_uintersect', 'array_uintersect_assoc',
            'array_uintersect_uassoc', 'array_unique', 'array_walk', 'array_walk_recursive', 'arsort',
            'asort', 'assert', 'class_implements', 'date_add', 'date_create',
            'date_create_from_format', 'date_create_immutable', 'date_date_set', 'date_diff', 'date_format',
            'date_interval_create_from_date_string', 'date_interval_format', 'date_isodate_set', 'date_modify', 'date_offset_get',
            'date_parse', 'date_parse_from_format', 'date_sub', 'date_time_set', 'date_timestamp_get',
            'date_timestamp_set', 'date_timezone_get', 'date_timezone_set', 'error_get_last', 'error_reporting',
            'explode', 'get_declared_classes', 'get_declared_interfaces', 'get_declared_traits', 'get_defined_constants',
            'getopt', 'header', 'header_remove', 'headers_list', 'headers_sent',
            'http_response_code', 'iterator_apply', 'iterator_count', 'iterator_to_array', 'krsort',
            'ksort', 'ob_clean', 'ob_end_clean', 'ob_end_flush', 'ob_flush',
            'ob_get_clean', 'ob_get_contents', 'ob_get_flush', 'ob_get_length', 'ob_get_level',
            'ob_get_status', 'ob_implicit_flush', 'ob_list_handlers', 'ob_start', 'pack',
            'register_shutdown_function', 'restore_error_handler', 'restore_exception_handler', 'rsort', 'serialize',
            'session_abort', 'session_cache_expire', 'session_cache_limiter', 'session_commit', 'session_create_id',
            'session_decode', 'session_destroy', 'session_encode', 'session_gc', 'session_get_cookie_params',
            'session_id', 'session_module_name', 'session_name', 'session_regenerate_id', 'session_register_shutdown',
            'session_reset', 'session_save_path', 'session_set_cookie_params', 'session_set_save_handler', 'session_start',
            'session_status', 'session_unset', 'session_write_close', 'set_error_handler', 'set_exception_handler',
            'setcookie', 'setrawcookie', 'shuffle', 'sort', 'spl_autoload_functions',
            'spl_autoload_register', 'spl_autoload_unregister', 'str_split', 'timezone_location_get', 'timezone_name_get',
            'timezone_offset_get', 'timezone_open', 'timezone_transitions_get', 'uasort', 'uksort',
            'unpack', 'unserialize', 'usort',
        ];
    }

    /** Whether $n is emitted inline (see {@see emitterInlineNames}). */
    private function isEmitterInlineName(string $n): bool
    {
        foreach ($this->emitterInlineNames() as $k) {
            if ($k === $n) { return true; }
        }
        return false;
    }

    /** @return string[] */
    private function emitterInlineNames(): array
    {
        return [
            'acos', 'array_first', 'array_key_first', 'array_key_last',
            'array_keys', 'array_last', 'array_values', 'asin', 'atan',
            'atan2', 'ceil', 'cos', 'cosh', 'debug_backtrace', 'deg2rad',
            'exp', 'explode', 'floor', 'flush', 'fmod',
            'func_get_arg', 'func_get_args', 'func_num_args', 'hypot',
            'int_to_ptr', 'is_numeric', 'json_decode', 'json_encode', 'log',
            'log10', 'peek_i16', 'peek_i32', 'peek_i64', 'peek_i8',
            'peek_u16', 'peek_u32', 'peek_u8', 'pi', 'poke_i16', 'poke_i32',
            'poke_i64', 'poke_i8', 'print_r', 'ptr_offset',
            'ptr_to_int', 'rad2deg', 'round', 'sin', 'sinh', 'sqrt', 'tan',
            'tanh', 'var_dump',
        ];
    }

    private function isCodegenBuiltin(string $name): bool
    {
        $n = \strtolower($name);
        $pos = \strrpos($n, '\\');
        if ($pos !== false) { $n = \substr($n, $pos + 1); }
        return $n === 'strlen' || $n === 'count' || $n === 'sizeof'
            || $n === 'ord' || $n === 'chr' || $n === 'abs' || $n === 'pow'
            || $n === 'intdiv'
            || $n === 'intval' || $n === 'floatval'
            || $n === 'is_null' || $n === 'is_int' || $n === 'is_integer'
            || $n === 'is_long' || $n === 'is_string' || $n === 'is_float'
            || $n === 'is_double' || $n === 'is_bool' || $n === 'is_array'
            || $n === 'is_object' || $n === 'is_callable'
            || $n === 'gettype' || $n === 'get_debug_type'
            || $n === 'min' || $n === 'max' || $n === 'dechex'
            || $n === 'strcspn'
            || $n === '__float_bits' || $n === '__ugt' || $n === '__ryu_msp'
            || $n === 'substr' || $n === 'str_repeat'
            || $n === 'str_from_buffer' || $n === 'cstr_to_str' || $n === 'str_bytes'
            || $n === '__mir_stdin' || $n === '__mir_stdout' || $n === '__mir_stderr'
            || $n === '__mir_argc' || $n === '__mir_argv_at' || $n === '__mir_to_cell'
            || $n === '__mir_env_count' || $n === '__mir_env_at'
            || $n === '__mir_clock_ns'
            || $n === 'strtolower' || $n === 'strtoupper' || $n === 'strpos'
            || $n === 'implode' || $n === 'join'
            || $n === 'sprintf' || $n === 'printf'
            || $n === 'exit' || $n === 'die' || $n === 'error_log'
            || $n === 'gc_collect_cycles' || $n === 'spl_object_id'
            || $n === 'get_class' || $n === 'array_pop' || $n === 'array_shift'
            || $n === 'array_unshift' || $n === 'addslashes' || $n === 'getenv'
            || $n === 'putenv' || $n === '__mir_fn_exists'
            || $n === 'get_object_vars' || $n === 'var_export'
            || $n === 'class_exists' || $n === 'enum_exists'
            || $n === 'interface_exists' || $n === 'trait_exists'
            || $n === 'method_exists' || $n === 'property_exists'
            || $n === 'is_a' || $n === 'is_subclass_of'
            || $n === 'get_parent_class' || $n === 'get_class_methods'
            // The internal-pointer family. They read/write the cursor in the
            // array header, so they cannot be PHP helpers — and `reset`/`end`
            // are no longer stdlib functions for exactly that reason.
            || $n === 'current' || $n === 'pos' || $n === 'key'
            || $n === 'next' || $n === 'prev' || $n === 'reset' || $n === 'end'
            || $n === '__mir_float_repr';
    }

    /**
     * @param string[]            $capNames
     * @param \Parser\Ast\Param[] $declParams
     * @param array<string,bool>  $capByRef  capture name → by-reference?
     */
    private function finishClosure(array $capNames, array $declParams, Block $body, ?string $retHint, array $capByRef = [], bool $isGenerator = false, bool $returnsByRef = false, bool $usesFuncArgs = false): Node
    {
        // A closure / arrow fn in an instance method auto-binds `$this`
        // (PHP semantics — no `use ($this)` needed). If the body reads it
        // and it isn't already captured, prepend it so the closure fn gets
        // a `this` param; type it to the enclosing class so `$this->prop`
        // resolves inside the closure.
        $thisType = $this->currentLowerClass !== ''
            ? Type::obj($this->currentLowerClass) : Type::unknown();
        $hasThis = false;
        foreach ($capNames as $cn) { if ($cn === 'this') { $hasThis = true; } }
        if (!$hasThis && $this->nodeReadsThis($body)) {
            $prepended = ['this'];
            foreach ($capNames as $cn) { $prepended[] = $cn; }
            $capNames = $prepended;
        }
        $id = $this->closureCounter;
        $this->closureCounter = $this->closureCounter + 1;
        $fnName = '__closure_' . (string)$id;
        $params = [];
        foreach ($capNames as $cn) {
            $ptype = $cn === 'this' ? $thisType : Type::unknown();
            // A by-ref capture is passed (and unpacked) as a slot address —
            // mark the param byRef so the closure body derefs it (refLocals).
            $params[] = new Param(name: $cn, type: $ptype, byRef: $capByRef[$cn] ?? false, variadic: false);
        }
        foreach ($declParams as $p) {
            $params[] = new Param(
                name: $p->name,
                // Untyped closure param → cell (NOT unknown), matching a regular
                // untyped param. The uniform closure ABI passes every arg as a
                // tagged cell (so a dynamic `callable` dispatch works), so an
                // untyped param must carry the tag; an unknown-typed param would
                // read the raw bits and a string arg renders as its pointer.
                type: $this->lowerParamType($p->typeHint),
                byRef: (bool)($p->byRef ?? false),
                variadic: (bool)($p->variadic ?? false),
            );
        }
        $retType = $this->lowerTypeHint($retHint);
        if ($isGenerator) {
            // A generator closure CALL returns a Generator (its frame ptr);
            // type it so foreach / InferTypes route the iterator protocol.
            $elem = $retType->isGenerator() ? $retType->element : null;
            $retType = Type::generator($elem);
        }
        // `fn &()` / `function &()` returns by reference like the named form.
        // The callee half was always in place — {@see EmitLlvmModule::emitReturn}
        // yields `byRefAddrOf($v)` BEFORE the uniform-closure-ABI boxing — and
        // `$this->sigs->returnsByRef['__closure_N']` is recorded for every
        // FunctionDef, so what was missing sat at the CALL site: the invoke
        // return unboxed unconditionally ({@see EmitLlvmCalls::emitClosureStructInvoke}).
        $clFn = new FunctionDef(
            name: $fnName,
            params: $params,
            returnType: $retType,
            body: $body,
            returnsByRef: $returnsByRef,
        );
        $clFn->isGenerator = $isGenerator;
        $clFn->usesFuncArgs = $usesFuncArgs;
        $this->module->addFunction($clFn);
        $this->module->closureCaptures[$fnName] = \count($capNames);
        // Record whether capture slot 0 is `$this` — Closure::bind/->bindTo/
        // ->call inject the bound object there (see emit). Prepended first, so
        // it is always struct slot 1.
        $this->module->closureHasThis[$fnName] = ($capNames[0] ?? '') === 'this';
        $captures = [];
        $captureByRef = [];
        foreach ($capNames as $cn) {
            if ($cn === 'this' && $this->currentLowerClass === '') {
                // A top-level closure that reads `$this` has no enclosing object
                // to capture — the slot is a LATE-BOUND placeholder filled by
                // Closure::bind / ->bindTo / ->call. Capture NULL (0) so no
                // dangling `$this` read is emitted at the definition site.
                $captures[] = new NullConst(Type::unknown());
                $captureByRef[] = false;
                continue;
            }
            $ctype = $cn === 'this' ? $thisType : Type::unknown();
            $captures[] = new LoadLocal($cn, $ctype);
            $captureByRef[] = $capByRef[$cn] ?? false;
        }
        return new Closure_($id, $captures, Type::obj($fnName), $captureByRef);
    }

    /**
     * Assemble a closure value: leading capture params (`$capNames`, bound to
     * `$capVals`) followed by the call params, with body `return <callNode>`.
     * Used by the method/static/builtin first-class-callable lowering.
     *
     * @param Param[]  $mirParams
     * @param string[] $capNames
     * @param Type[]   $capTypes
     * @param Node[]   $capVals
     */
    private function buildClosureNode(array $mirParams, array $capNames, array $capTypes, array $capVals, Node $callNode, Type $ret): Node
    {
        $id = $this->closureCounter;
        $this->closureCounter = $id + 1;
        $fnName = '__closure_' . (string)$id;
        $params = [];
        $i = 0;
        foreach ($capNames as $cn) {
            $params[] = new Param(name: $cn, type: $capTypes[$i], byRef: false, variadic: false);
            $i = $i + 1;
        }
        foreach ($mirParams as $mp) { $params[] = $mp; }
        $clFn = new FunctionDef(
            name: $fnName,
            params: $params,
            returnType: $ret,
            body: new Block([new Return_($callNode, Type::void())], Type::void()),
        );
        $this->module->addFunction($clFn);
        $this->module->closureCaptures[$fnName] = \count($capNames);
        $byRef = [];
        foreach ($capNames as $cn) { $byRef[] = false; }
        return new Closure_($id, $capVals, Type::obj($fnName), $byRef);
    }

    /**
     * Convert a callable LITERAL argument (`"fn"`, `"C::m"`, `[$o,"m"]`,
     * `["C","m"]`) bound to a `callable`-typed parameter into a closure value,
     * so the callee can invoke it uniformly (e.g. `array_map("strtoupper",…)`).
     * Returns null when no conversion applies.
     */
    private function coerceCallableArg(?Type $pt, \Parser\Ast\Expr $arg): ?Node
    {
        if ($pt === null || $pt->kind !== Type::KIND_CLOSURE) { return null; }
        if ($arg->kind === 'StringLiteral') {
            $name = $this->strLitValue($arg);
            $cc = \strpos($name, '::');
            if ($cc !== false && $cc > 0) {
                $cls = \ltrim(\substr($name, 0, $cc), '\\');
                return $this->synthStaticClosure($cls, \substr($name, $cc + 2), $cls);
            }
            return $this->lowerFcc($name);
        }
        if ($arg->kind === 'ArrayLit') {
            $els = $this->arrayLitElements($arg);
            if (\count($els) !== 2) { return null; }
            $recvE = $this->elemValue($els[0]);
            $methE = $this->elemValue($els[1]);
            if ($methE->kind !== 'StringLiteral') { return null; }
            $m = $this->strLitValue($methE);
            if ($recvE->kind === 'StringLiteral') {
                $cls = \ltrim($this->strLitValue($recvE), '\\');
                return $this->synthStaticClosure($cls, $m, $cls);
            }
            return $this->synthMethodClosure($this->lowerExpr($recvE), $m);
        }
        return null;
    }

    private function lowerInvoke(\Parser\Ast\Invoke $expr): Node
    {
        // Literal string / array callable invoked directly: `"fn"(x)`,
        // `"C::m"(x)`, `[$o,"m"](x)`, `["C","m"](x)` → the matching call.
        $calleeAst = $expr->callee;
        $ck = $calleeAst->kind;
        // First-class callable on a value: `$code(...)` / `$obj->m(...)` reached
        // through Invoke. A string/array literal builds the concrete closure;
        // any other callable VALUE is already invokable, so it passes through
        // (identity for a closure — the common `$c(...)` normalise). NOTE: a raw
        // string/array callable held in a var stays that value rather than a
        // Closure object, so `instanceof Closure` on the result is not modelled.
        if (\count($expr->args) === 1 && $expr->args[0]->kind === 'Ellipsis') {
            $cc = $this->coerceCallableArg(Type::closure(), $calleeAst);
            return $cc !== null ? $cc : $this->lowerExpr($calleeAst);
        }
        if ($ck === 'StringLiteral') {
            return $this->lowerStringCallable($this->strLitValue($calleeAst), $expr->args);
        }
        if ($ck === 'ArrayLit') {
            $node = $this->lowerArrayCallable($calleeAst, $expr->args);
            if ($node !== null) { return $node; }
        }
        // A local tracked as holding a callable literal (straight-line).
        if ($ck === 'Variable') {
            $vn = $this->varName($calleeAst);
            if (isset($this->constCallables[$vn])) {
                return $this->lowerConstCallable($vn, $this->constCallables[$vn], $expr->args);
            }
            // A body-stable `str_set` (`$fn = cond ? 'preg_match_all' : 'preg_match'`)
            // that survived an intervening if/try. Dispatch on `$fn`'s runtime
            // value into the two DIRECT calls — a dynamic invoke cannot carry a
            // by-ref out-param, so preg_match's `$matches` came back undefined.
            $stableName = $this->stableCallables[$vn] ?? '';
            if ($stableName !== '') {
                return $this->lowerStrSetCallable($vn, $stableName,
                    $this->stableCallablesAlt[$vn] ?? '', $expr->args);
            }
        }
        $callee = $this->lowerExpr($calleeAst);
        $args = [];
        foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
        return new Invoke_($callee, $args, Type::unknown());
    }

    /**
     * Free `Variable` names referenced in an expression (best-effort
     * recursive walk over the common shapes). Returns a flat list;
     * caller de-dups.
     *
     * @return string[]
     */
    private function collectVars(\Parser\Ast\Expr $e): array
    {
        $k = $e->kind;
        if ($k === 'Variable') { return [$e->name]; }
        if ($k === 'BinaryOp') { return \array_merge($this->collectVars($e->left), $this->collectVars($e->right)); }
        if ($k === 'UnaryOp') { return $this->collectVars($e->operand); }
        if ($k === 'Ternary') {
            $out = $this->collectVars($e->condition);
            if ($e->then !== null) { $out = \array_merge($out, $this->collectVars($e->then)); }
            return \array_merge($out, $this->collectVars($e->else));
        }
        if ($k === 'ArrayAccess') {
            $out = $this->collectVars($e->array);
            if ($e->index !== null) { $out = \array_merge($out, $this->collectVars($e->index)); }
            return $out;
        }
        if ($k === 'PropertyAccess') { return $this->collectVars($e->object); }
        if ($k === 'Cast') { return $this->collectVars($e->operand); }
        if ($k === 'Call') {
            $out = [];
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        if ($k === 'MethodCall') {
            $out = $this->collectVars($e->object);
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        // Static call / `new` args carry free vars too — an arrow fn body of
        // `fn() => Helper::width(Helper::removeDecoration($formatter, $h))` captures
        // `$formatter` through the static-call argument (else it dangles).
        if ($k === 'StaticCall') {
            $out = [];
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        if ($k === 'New') {
            $out = [];
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        if ($k === 'Invoke') {
            $out = $this->collectVars($e->callee);
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        // A NESTED arrow fn contributes its OWN free vars (body vars minus its
        // params) to the enclosing scope — an inner `fn($c)=>$a+$b+$c` makes
        // `$a`/`$b` free in the middle `fn($b)=>…`, so 3+-level currying
        // captures the outer vars transitively instead of dangling.
        if ($k === 'ArrowFn') {
            $inner = [];
            foreach ($e->params as $p) { $inner[$p->name] = true; }
            $out = [];
            foreach ($this->collectVars($e->body) as $v) {
                if (!isset($inner[$v])) { $out[] = $v; }
            }
            return $out;
        }
        // `...$xs` in an argument list. Without this arm the spread OPERAND is
        // invisible to the free-variable scan, so `fn ($t) => $t->m(...$xs)`
        // captured nothing and `$xs` dangled inside the closure — symfony's
        // type-info spreads a VARIADIC parameter into exactly that shape
        // (`static fn (Type $t) => $t->isIdentifiedBy(...$identifiers)`).
        if ($k === 'Spread') { return $this->collectVars($e->value); }
        // A nested `function () use ($x) {}` makes each explicitly-captured var
        // free in the enclosing scope (its body runs in an isolated scope).
        if ($k === 'Closure') {
            $out = [];
            foreach ($e->uses as $u) { $out[] = $u->name; }
            return $out;
        }
        if ($k === 'NullCoalesce') {
            return \array_merge($this->collectVars($e->left), $this->collectVars($e->right));
        }
        if ($k === 'Instanceof') { return $this->collectVars($e->operand); }
        // ⚠ A plain `$x = …` target is a WRITE, and php captures nothing for it:
        // an arrow fn assigning to a name the enclosing scope does not have
        // simply creates its own local. Collecting the target made
        // `fn ($m) => ($parent = $c->getParentClass()) ? … : …` capture a
        // variable that never existed. A COMPLEX target still reads its base
        // (`$a[$k] = v` needs `$a`), and a COMPOUND assign reads the variable
        // itself, so those keep the target.
        if ($k === 'Assign') {
            $out = [];
            if ($e->target->kind !== 'Variable') { $out = $this->collectVars($e->target); }
            return \array_merge($out, $this->collectVars($e->value));
        }
        if ($k === 'CompoundAssign') {
            return \array_merge($this->collectVars($e->target), $this->collectVars($e->value));
        }
        if ($k === 'RefAssign') {
            $out = [];
            if ($e->target->kind !== 'Variable') { $out = $this->collectVars($e->target); }
            return \array_merge($out, $this->collectVars($e->source));
        }
        if ($k === 'IncDec') { return $this->collectVars($e->operand); }
        // `fn () => [$a, $b]` had NO arm at all and silently captured nothing.
        if ($k === 'ArrayLit') {
            $out = [];
            foreach ($e->elements as $el) {
                if ($el->key !== null) { $out = \array_merge($out, $this->collectVars($el->key)); }
                $out = \array_merge($out, $this->collectVars($el->value));
            }
            return $out;
        }
        if ($k === 'DynProp') {
            return \array_merge($this->collectVars($e->object), $this->collectVars($e->name));
        }
        if ($k === 'NewDyn') {
            $out = $this->collectVars($e->classExpr);
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        if ($k === 'Clone') {
            $out = $this->collectVars($e->object);
            if ($e->withProps !== null) { $out = \array_merge($out, $this->collectVars($e->withProps)); }
            return $out;
        }
        if ($k === 'Match') {
            $out = $this->collectVars($e->subject);
            foreach ($e->arms as $arm) {
                foreach ($arm->conds ?? [] as $c) { $out = \array_merge($out, $this->collectVars($c)); }
                $out = \array_merge($out, $this->collectVars($arm->body));
            }
            return $out;
        }
        if ($k === 'NamedArg') { return $this->collectVars($e->value); }
        if ($k === 'Yield') {
            $out = [];
            if ($e->key !== null) { $out = \array_merge($out, $this->collectVars($e->key)); }
            if ($e->value !== null) { $out = \array_merge($out, $this->collectVars($e->value)); }
            return $out;
        }
        if ($k === 'DynamicStaticAccess') { return $this->collectVars($e->receiver); }
        if ($k === 'DynamicStaticCall') {
            $out = $this->collectVars($e->receiver);
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        // Leaves — nothing to collect, named so the dispatch below can be
        // exhaustive.
        if ($k === 'IntLiteral' || $k === 'FloatLiteral' || $k === 'StringLiteral'
            || $k === 'BoolLiteral' || $k === 'NullLiteral' || $k === 'Identifier'
            || $k === 'MagicConstant' || $k === 'StaticAccess' || $k === 'Ellipsis') {
            return [];
        }
        // ⚠ EXHAUSTIVE ON PURPOSE. This used to `return []` for anything it did
        // not recognise, which is silent capture LOSS: an arrow fn whose body
        // held an unlisted shape compiled to a closure missing a capture, and
        // the failure surfaced far away as `MIR.verify: dangling local`. An
        // array literal — `fn () => [$a, $b]` — was one such shape for as long
        // as the list existed. Same lesson as NodeClone: a dispatch nobody can
        // forget to extend is the only kind worth having.
        throw new \RuntimeException(
            'MIR.lower: free-variable scan has no rule for expression kind ' . $k);
    }

    /**
     * Lower AST call args against a known parameter signature, filling
     * omitted trailing params with their default expression (or null),
     * reordering named args, and packing a trailing variadic into a vec.
     * Critical for `new`/method/static calls: the callee reads one slot
     * per param, so an omitted obj-typed default left uninitialized makes
     * the callee retain stack garbage.
     * @param \Parser\Ast\Param[] $params
     * @param \Parser\Ast\Expr[]  $astArgs
     * @return Node[]
     */
    /**
     * Lower one argument, converting a callable LITERAL into a closure when the
     * parameter at this position is `callable`-typed. lowerCallArgs does this on
     * its fast positional path, but every call that omits a DEFAULTED parameter
     * lands here instead — and then a string like `"strlen"` was passed through
     * as a plain string. The callee invokes a `callable` param through the
     * closure ABI, so it jumped to the address of the string's own bytes:
     * `array_filter($a, "strlen")` (three params, two arguments — symfony's
     * InputOption constructor) crashed on the literal "strlen".
     */
    private function lowerArgForParam(?\Parser\Ast\Param $p, \Parser\Ast\Expr $a): Node
    {
        if ($p !== null) {
            $conv = $this->coerceCallableArg($this->lowerParamType($this->paramTypeHint($p)), $a);
            if ($conv !== null) { return $conv; }
        }
        return $this->lowerExpr($a);
    }

    /**
     * The synthetic local holding this frame's real argument count, taken off
     * the side channel by the prologue. A plain local name (not an `@`-prefixed
     * emitter temp) so InferTypes types it and the usual local machinery
     * allocates it.
     */
    private function argcLocalName(): string { return '__mc_argc'; }

    /** Companion local holding the overflow arguments — those written past this
     *  frame's declared parameters, which have no local of their own. */
    private function argxLocalName(): string { return '__mc_argx'; }

    /**
     * `[$p0, $p1, …]` over the declared parameters of the body being lowered —
     * the argument vector `func_get_args()` / `func_get_arg($i)` index into.
     * Boxed to cells: the parameters are heterogeneously typed and a PHP
     * argument list is a `mixed` array.
     */
    private function funcArgsVector(): Node
    {
        $elems = [];
        $i = 0;
        foreach ($this->currentLowerParams as $pn) {
            $hint = $this->currentLowerParamHints[$i] ?? '';
            $pt = $hint !== '' ? $this->lowerParamType($hint) : Type::cell();
            $elems[] = new ArrayElement_(null, new LoadLocal($pn, $pt));
            $i = $i + 1;
        }
        $declared = new ArrayLit($elems, Type::vec(Type::cell()));
        // The parameters answer for the arguments that HAVE a local — and they
        // answer with the parameter's CURRENT value, which is what php >= 7
        // reports. Anything the caller wrote past them arrives separately.
        return new Call('__mc_func_args_join',
            [$declared, new LoadLocal($this->argxLocalName(), Type::vec(Type::cell()))],
            Type::vec(Type::cell()));
    }

    /**
     * The statement that opens any body using the func-args family:
     * `$__mc_argc = __mir_argc_take(<declared count>)`.
     *
     * It must run BEFORE the body's first nested call, which is what makes a
     * single global slot safe — nothing can execute between a call site's push
     * and this take, so generators and fibers need no per-frame stack. The
     * declared count is the fallback the builtin returns when the channel is
     * empty (-1), i.e. when this frame was entered from a call site that did
     * not push; that degrades to exactly the old declared-count answer rather
     * than to garbage.
     */
    private function funcArgsPrologue(int $declared): Node
    {
        return new StoreLocal(
            $this->argcLocalName(),
            new Call('__mir_fa_take', [new IntConst($declared, Type::int_())], Type::int_()),
            Type::int_(),
        );
    }

    /** `$body` with {@see funcArgsPrologue} spliced in as its first statements. */
    private function withFuncArgsPrologue(Block $body, int $declared): Block
    {
        $stmts = [
            $this->funcArgsPrologue($declared),
            // Taken in the same breath as the count, and for the same reason:
            // both slots must be emptied before anything else can run.
            new StoreLocal(
                $this->argxLocalName(),
                new Call('__mir_fa_takex', [], Type::vec(Type::cell())),
                Type::vec(Type::cell()),
            ),
        ];
        foreach ($body->stmts as $s) { $stmts[] = $s; }
        return new Block($stmts, $body->type);
    }

    private function defaultFillArgs(array $params, array $astArgs, string $selfClass = ''): array
    {
        $hasNamed = false;
        foreach ($astArgs as $a) {
            if ($a->kind === 'NamedArg') { $hasNamed = true; break; }
        }
        // Variadic last param: pack trailing positional args into a vec.
        $np = \count($params);
        if ($np > 0 && $this->paramVariadic($params[$np - 1])) {
            $vidx = $np - 1;
            $out = [];
            $packed = [];
            $i = 0;
            foreach ($astArgs as $a) {
                if ($i < $vidx) { $out[] = $this->lowerArgForParam($params[$i] ?? null, $a); }
                else { $packed[] = new ArrayElement_(null, $this->lowerExpr($a)); }
                $i = $i + 1;
            }
            $out[] = new ArrayLit($packed, Type::unknown());
            return $out;
        }
        // Resolve against the signature only when something is missing /
        // reordered; otherwise lower positionally.
        if (!$hasNamed && \count($astArgs) >= \count($params)) {
            $out = [];
            $i = 0;
            foreach ($astArgs as $a) {
                $out[] = $this->lowerArgForParam($params[$i] ?? null, $a);
                $i = $i + 1;
            }
            // Surplus arguments stay on the call — see lowerCallArgs for why
            // diverting them here was a regression.
            return $out;
        }
        // Dense parallel slots (sparse int-key isset is unreliable in
        // self-host, so pre-fill both lists to param count first).
        $slotNode = [];
        $slotSet = [];
        foreach ($params as $p) {
            $slotNode[] = new NullConst(Type::null_());
            $slotSet[] = false;
        }
        $pos = 0;
        foreach ($astArgs as $a) {
            if ($a->kind === 'NamedArg') {
                // `$a` is a base-Expr-typed loop var; NamedArg's `name` /
                // `value` sit at subclass offsets, so read them through a
                // typed param (self-host offset, T5 pattern).
                $an = $this->namedArgName($a);
                $av = $this->namedArgValue($a);
                $idx = 0;
                foreach ($params as $p) {
                    if ($this->paramName($p) === $an) {
                        $slotNode[$idx] = $this->lowerArgForParam($p, $av);
                        $slotSet[$idx] = true;
                        break;
                    }
                    $idx = $idx + 1;
                }
                continue;
            }
            $slotNode[$pos] = $this->lowerArgForParam($params[$pos] ?? null, $a);
            $slotSet[$pos] = true;
            $pos = $pos + 1;
        }
        $out = [];
        $i = 0;
        foreach ($params as $p) {
            $pd = $this->paramDefault($p);
            if ($slotSet[$i]) {
                $out[] = $slotNode[$i];
            } elseif ($pd !== null) {
                // A `self::CONST` / `parent::` / `static::` in an omitted param's
                // default resolves against the callee's DECLARING class, not the
                // call site — bind `self` to it while lowering (empty for plain
                // functions keeps the caller context).
                if ($selfClass !== '') {
                    $prevSelf = $this->currentLowerClass;
                    $this->currentLowerClass = $selfClass;
                    $out[] = $this->lowerExpr($pd);
                    $this->currentLowerClass = $prevSelf;
                } else {
                    $out[] = $this->lowerExpr($pd);
                }
            } else {
                $out[] = new NullConst(Type::null_());
            }
            $i = $i + 1;
        }
        return $out;
    }
}
