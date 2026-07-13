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
        $params = [];
        foreach ($decl->params as $p) {
            $isVariadic = (bool)($p->variadic ?? false);
            // `T ...$xs` collects trailing args into a vec[T] the callee
            // sees as a single vec param (caller packs at the call site).
            $pt = $isVariadic
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerParamType($this->effectiveHint(
                    $p->typeHint,
                    $this->docTagType($decl->docComment, '@param', $p->name),
                ));
            $fp = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVariadic,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
            $fp->arrayHinted = $this->isBareArrayHint($p->typeHint) || $pt->isArray();
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
            $ctypes = [];
            foreach ($decl->params as $p) { $ctypes[] = $this->ffiCType($p->typeHint); }
            $fn->ffiParamCTypes = $ctypes;
            $fn->ffiRetCType = $this->ffiCType($decl->returnType);
            return $fn;
        }
        $savedSawYield = $this->sawYield;
        $this->sawYield = false;
        $loweredBody = $this->lowerBlockNode($decl->body);
        $isGen = $this->sawYield;
        $this->sawYield = $savedSawYield;
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
            $pt = $isVariadic
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerTypeHint($this->effectiveHint(
                    $p->typeHint,
                    $this->docTagType($decl->docComment, '@param', $p->name),
                ));
            $params[] = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVariadic,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
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
            || $n === 'substr' || $n === 'str_repeat'
            || $n === 'str_from_buffer' || $n === 'cstr_to_str'
            || $n === '__mir_stdin' || $n === '__mir_stdout' || $n === '__mir_stderr'
            || $n === '__mir_argc' || $n === '__mir_argv_at' || $n === '__mir_to_cell'
            || $n === 'strtolower' || $n === 'strtoupper' || $n === 'strpos'
            || $n === 'implode' || $n === 'join'
            || $n === 'sprintf' || $n === 'printf'
            || $n === 'exit' || $n === 'die' || $n === 'error_log'
            || $n === 'gc_collect_cycles' || $n === 'spl_object_id'
            || $n === 'get_class' || $n === 'array_pop' || $n === 'array_shift'
            || $n === 'array_unshift' || $n === 'addslashes' || $n === 'getenv'
            || $n === 'get_object_vars' || $n === 'var_export'
            || $n === 'class_exists' || $n === 'enum_exists'
            || $n === 'interface_exists' || $n === 'trait_exists'
            || $n === 'method_exists' || $n === 'property_exists'
            || $n === 'is_a' || $n === 'is_subclass_of'
            || $n === 'get_parent_class' || $n === 'get_class_methods'
            || $n === '__mir_float_repr';
    }

    /**
     * @param string[]            $capNames
     * @param \Parser\Ast\Param[] $declParams
     * @param array<string,bool>  $capByRef  capture name → by-reference?
     */
    private function finishClosure(array $capNames, array $declParams, Block $body, ?string $retHint, array $capByRef = [], bool $isGenerator = false): Node
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
        $clFn = new FunctionDef(
            name: $fnName,
            params: $params,
            returnType: $retType,
            body: $body,
        );
        $clFn->isGenerator = $isGenerator;
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
            $info = $this->constCallables[$vn] ?? null;
            if ($info !== null) { return $this->lowerConstCallable($vn, $info, $expr->args); }
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
        // A nested `function () use ($x) {}` makes each explicitly-captured var
        // free in the enclosing scope (its body runs in an isolated scope).
        if ($k === 'Closure') {
            $out = [];
            foreach ($e->uses as $u) { $out[] = $u->name; }
            return $out;
        }
        return [];
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
    private function defaultFillArgs(array $params, array $astArgs): array
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
                if ($i < $vidx) { $out[] = $this->lowerExpr($a); }
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
            foreach ($astArgs as $a) { $out[] = $this->lowerExpr($a); }
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
                        $slotNode[$idx] = $this->lowerExpr($av);
                        $slotSet[$idx] = true;
                        break;
                    }
                    $idx = $idx + 1;
                }
                continue;
            }
            $slotNode[$pos] = $this->lowerExpr($a);
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
                $out[] = $this->lowerExpr($pd);
            } else {
                $out[] = new NullConst(Type::null_());
            }
            $i = $i + 1;
        }
        return $out;
    }
}
