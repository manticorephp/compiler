<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\StringConst;
use Compile\Mir\Walk;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
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
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;

/**
 * Call sites: resolving the callee, its return type, generics substitution and
 * the builtin signature table.
 *
 * A trait on the one {@see InferTypes} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait InferCalls
{
    private function inferCall(Call $node): Type
    {
        foreach ($node->args as $a) {
            $this->inferNode($a);
        }
        $callee = $node->function;
        // A tagged-cell builtin (`strpos` → int|false, `getenv` →
        // string|false) is emitted by EmitLlvm as the NaN-boxed builtin
        // even when a same-named stdlib function is in scope (emitBuiltin
        // wins in emitCall). Type it as the builtin (cell) FIRST so the
        // stdlib sig's plain `: int` / `: string` doesn't mask the tag —
        // otherwise `strpos(...) > 0` compares raw NaN-boxed bits and a
        // `=== false` miss-check never fires. Same for a cell-ARRAY builtin
        // (`array_keys` → vec[cell]): the codegen result carries per-element
        // NaN tags the stdlib sig's plain `array` erases (→ keys render as
        // raw int). Other builtins agree with their stdlib sig, so the
        // user-sig path below still wins for them.
        $bt = $this->builtinReturnType($callee, $node->args);
        if ($bt !== null && ($bt->kind === Type::KIND_CELL
            || ($bt->kind === Type::KIND_ARRAY && $bt->element !== null
                && $bt->element->kind === Type::KIND_CELL))) {
            $node->type = $bt;
            return $node->type;
        }
        if (isset($this->sigs[$callee])) {
            $node->type = $this->sigs[$callee];
            return $node->type;
        }
        if ($bt !== null) { $node->type = $bt; }
        return $node->type;
    }

    /**
     * Return type of a recognised builtin (see EmitLlvm::emitBuiltin),
     * or null for an unknown / user function. Keeps echo + coercion
     * formatting correct (int → %lld, float → %g, string → %s).
     *
     * @param Node[] $args
     */
    private function builtinReturnType(string $name, array $args): ?Type
    {
        $n = \strtolower($name);
        // Strip a leading namespace (`\substr` → `substr`) so a fully
        // qualified builtin call infers its real return type — matching
        // EmitLlvm::emitBuiltin's own normalisation. Without this the call
        // types as `unknown`, which mis-flavours an owned string result as
        // an obj rc-local (→ obj release on a str_alloc buffer → bad-free).
        $bs = \strrpos($n, '\\');
        if ($bs !== false) { $n = \substr($n, $bs + 1); }
        // CLI / stdio primitives: STDIN/OUT/ERR and a raw argv entry are libc
        // FILE*/char* handles (obj<Ffi\Ptr>); the captured argc is a plain int.
        if ($n === '__mir_stdin' || $n === '__mir_stdout'
            || $n === '__mir_stderr' || $n === '__mir_argv_at'
            || $n === '__mir_env_at' || $n === 'ptr_offset') {
            return Type::obj('Ffi\\Ptr');
        }
        if ($n === '__mir_argc' || $n === '__mir_env_count'
            || $n === '__mir_clock_ns') { return Type::int_(); }
        if ($n === '__mir_to_cell') { return Type::cell(); }
        if ($n === '__mir_enum_name') { return Type::string_(); }
        if ($n === 'strlen' || $n === 'count' || $n === 'sizeof'
            || $n === 'ord' || $n === 'intval' || $n === 'intdiv'
            || $n === 'printf' || $n === 'spl_object_id'
            || $n === 'strcspn'
            || $n === '__float_bits' || $n === '__ryu_msp'
            || $n === 'peek_i64' || $n === 'peek_i32' || $n === 'peek_i16'
            || $n === 'peek_i8'
            || $n === 'peek_u32' || $n === 'peek_u16' || $n === 'peek_u8'
            || $n === 'poke_i64' || $n === 'poke_i32' || $n === 'poke_i16'
            || $n === 'poke_i8'
            || $n === 'array_unshift' || $n === '__str_byte_at') {
            return Type::int_();
        }
        if ($n === '__ugt') { return Type::bool_(); }
        // min/max: a float operand makes the result a numericCell (the winner's
        // own type is preserved — {@see EmitLlvmBuiltins::biMinMax}); else int.
        if ($n === 'min' || $n === 'max') {
            foreach ($args as $a) {
                if ($a->type->kind === Type::KIND_FLOAT) { return Type::numericCell(); }
            }
            return Type::int_();
        }
        // pow / `**`: int when both operands are int (PHP returns int for a
        // non-negative int exponent), else float.
        if ($n === 'pow') {
            $bothInt = \count($args) === 2
                && $args[0]->type->kind === Type::KIND_INT
                && $args[1]->type->kind === Type::KIND_INT;
            return $bothInt ? Type::int_() : Type::float_();
        }
        // is_* type predicates return bool — echo prints "1"/"" (not "0"),
        // var_dump renders bool(...). Without this they type unknown → render
        // as int.
        if ($n === 'is_null' || $n === 'is_int' || $n === 'is_integer'
            || $n === 'is_long' || $n === 'is_string' || $n === 'is_float'
            || $n === 'is_double' || $n === 'is_bool' || $n === 'is_array'
            || $n === 'is_object' || $n === 'is_callable' || $n === 'is_numeric') {
            return Type::bool_();
        }
        if ($n === 'get_class') { return Type::string_(); }
        // Reflection Tier-1: existence/relationship queries fold to bool;
        // get_parent_class is string|false (cell); get_class_methods a
        // vec[cell] of name strings (mirrors array_keys).
        if ($n === 'class_exists' || $n === 'enum_exists'
            || $n === 'interface_exists' || $n === 'trait_exists'
            || $n === 'method_exists' || $n === 'property_exists'
            || $n === 'is_a' || $n === 'is_subclass_of') {
            return Type::bool_();
        }
        if ($n === 'get_parent_class') { return Type::cell(); }
        if ($n === 'get_class_methods') { return Type::vec(Type::cell()); }
        // array_keys → a fresh PACKED list of NaN-boxed keys (codegen builtin
        // {@see EmitLlvmBuiltins::biArrayKeys}); uniform cell elements work for
        // both a plain and a cell/`mixed` source.
        if ($n === 'array_keys') { return Type::vec(Type::cell()); }
        // debug_backtrace → a list of PHP-shaped frame assocs
        // ({file,line,function[,class,type]}, mixed values — `line` is an int),
        // built by the prelude `__mir_bt_frames` (codegen builtin
        // {@see EmitLlvmBuiltins::biDebugBacktrace}). Cell values match the frame
        // assoc's mixed int/string layout.
        if ($n === 'debug_backtrace') { return Type::vec(Type::assoc(Type::string_(), Type::cell())); }
        // array_first/array_last (8.5) + array_key_first/array_key_last — the
        // first/last value or key as a tagged cell, null on empty (codegen
        // builtin {@see EmitLlvmBuiltins::biArrayEndpoint}). A cell result lets
        // the key variants carry the full int|string|null union.
        if (($n === 'array_first' || $n === 'array_last'
            || $n === 'array_key_first' || $n === 'array_key_last')
            && \count($args) === 1) {
            // A homogeneous ENUM-case array: return the enum type (not a cell) so
            // `array_first($cases)->name` dispatches through emitEnumProp — an
            // enum case is an ordinal, not a boxable object cell.
            if ($n === 'array_first' || $n === 'array_last') {
                $at = $args[0]->type;
                $el = ($at->isVec() || $at->isAssoc()) ? $at->element : null;
                if ($el !== null && $el->kind === Type::KIND_OBJ
                    && $el->class !== null && isset($this->enums[$el->class])) {
                    return $el;
                }
            }
            return Type::cell();
        }
        // explode → a fresh vec of string segments (codegen builtin
        // {@see EmitLlvmBuiltins::biExplode}); the string element keeps implode /
        // foreach on the fast (non-cell) path.
        if ($n === 'explode') { return Type::vec(Type::string_()); }
        // array_values is a codegen builtin ({@see EmitLlvmBuiltins::biArrayValues})
        // for a CELL/`mixed` source OR a typed array with a concrete element
        // kind → vec[cell]. An unknown-element source falls through to the
        // stdlib; mirror the dispatch's gate so the types agree.
        if ($n === 'array_values' && \count($args) >= 1) {
            $at = $args[0]->type;
            if ($at->kind === Type::KIND_CELL) { return Type::vec(Type::cell()); }
            if ($at->kind === Type::KIND_ARRAY && $at->element !== null) {
                $ek = $at->element->kind;
                if ($ek === Type::KIND_INT || $ek === Type::KIND_STRING
                    || $ek === Type::KIND_FLOAT || $ek === Type::KIND_BOOL
                    || $ek === Type::KIND_OBJ || $ek === Type::KIND_CELL) {
                    return Type::vec(Type::cell());
                }
            }
        }
        // array_pop/array_shift yield the vec's element type so the
        // popped value echoes / flavours correctly.
        if ($n === 'array_pop' || $n === 'array_shift') {
            if (\count($args) >= 1 && $args[0]->type->element !== null) {
                return $args[0]->type->element;
            }
            return null;
        }
        // strpos is `int|false` — a tagged cell (Zend-faithful miss).
        if ($n === 'strpos') {
            return Type::cell();
        }
        // getenv is `string|false` — a tagged cell.
        if ($n === 'getenv') {
            return Type::cell();
        }
        // Math: floor/ceil/round/sqrt/fmod all return float in PHP (e.g.
        // floor(4.5) === 4.0). Emitted as LLVM intrinsics — no libm link.
        if ($n === 'floatval' || $n === 'floor' || $n === 'ceil'
            || $n === 'round' || $n === 'sqrt' || $n === 'fmod'
            || $n === 'sin' || $n === 'cos' || $n === 'tan'
            || $n === 'asin' || $n === 'acos' || $n === 'atan' || $n === 'atan2'
            || $n === 'sinh' || $n === 'cosh' || $n === 'tanh'
            || $n === 'exp' || $n === 'log' || $n === 'log10'
            || $n === 'hypot' || $n === 'pi' || $n === 'deg2rad' || $n === 'rad2deg') {
            return Type::float_();
        }
        if ($n === 'chr' || $n === 'dechex' || $n === 'substr'
            || $n === 'str_repeat' || $n === 'strtolower' || $n === 'strtoupper'
            || $n === 'sprintf' || $n === 'implode' || $n === 'join'
            || $n === 'addslashes' || $n === 'var_export' || $n === '__mc_json_escape'
            || $n === '__mir_str_replace_one'
            || $n === 'str_from_buffer' || $n === 'cstr_to_str'
            || $n === 'gettype' || $n === 'get_debug_type'
            || $n === '__mir_float_repr') {
            return Type::string_();
        }
        if ($n === 'get_object_vars') {
            return Type::assoc(Type::string_(), Type::cell());
        }
        if ($n === 'abs') {
            // abs preserves the argument's numeric type.
            if (\count($args) === 1 && $args[0]->type->kind === Type::KIND_FLOAT) {
                return Type::float_();
            }
            return Type::int_();
        }
        return null;
    }

    private function inferClosure(Closure_ $node): Type
    {
        foreach ($node->captures as $c) { $this->inferNode($c); }
        // Record the node so the second pass can type the closure body's
        // capture locals from these (now inferred) capture values. Just an
        // object-handle store (like fnByName) — no snapshot, no Type[] map.
        $this->closureNodeByName['__closure_' . (string)$node->id] = $node;
        $this->sawClosures = true;
        return $node->type;
    }

    private function inferInvoke(Invoke_ $node): Type
    {
        $ct = $this->inferNode($node->callee);
        foreach ($node->args as $a) { $this->inferNode($a); }
        // `$o->$m(args)` parses as Invoke(DynProp) — a DYNAMIC METHOD call, not a
        // property-then-invoke. Type it as a cell (the emitter dispatches on the
        // runtime method name and boxes each arm's result), so echo / var_dump /
        // string reads see the tagged value instead of a raw pointer.
        if ($node->callee instanceof DynProp_) {
            $node->type = Type::cell();
            return $node->type;
        }
        // A string-typed callee names a free function at runtime; the emitter
        // dispatches on the name and boxes each arm's result, so the invoke is a
        // cell (echo / var_dump read the tag instead of a raw pointer).
        if ($ct->kind === Type::KIND_STRING) {
            $node->type = Type::cell();
            return $node->type;
        }
        // callee type obj<__closure_N> → that fn's return type.
        if ($ct->class !== null && isset($this->sigs[$ct->class])) {
            $node->type = $this->sigs[$ct->class];
        } elseif ($ct->kind === Type::KIND_CLOSURE) {
            // A `callable(int): string` states its return type, so the invoke can
            // be typed concretely — the value still ARRIVES as a tagged cell under
            // the uniform closure ABI, and the emitter unboxes it to this type.
            //
            // A bare `callable` (an array_map / usort `$cb` param) says nothing:
            // the concrete closure isn't known, so the result stays a cell and the
            // caller reads it by tag.
            $node->type = $ct->closureReturn() ?? Type::cell();
        }
        // An invokable object (`$obj(...)` on a class with __invoke) → its
        // __invoke return type (the reroute happens in EmitLlvm).
        if ($ct->kind === Type::KIND_OBJ && $ct->class !== null
            && isset($this->classes[$ct->class])
            && $this->classDefinesMagic($ct->class, '__invoke')) {
            $rt = $this->magicReturnType($ct->class, '__invoke');
            $node->type = $rt ?? Type::cell();
        }
        return $node->type;
    }

    /** Whether `$cls` or an ancestor declares magic method `$m` (e.g. '__get'). */
    private function classDefinesMagic(string $cls, string $m): bool
    {
        $c = $cls;
        while ($c !== '' && isset($this->classes[$c])) {
            if (isset($this->classes[$c]->methodNames[$m])) { return true; }
            $c = $this->classes[$c]->parent;
        }
        return false;
    }

    /** Resolved return type of magic method `$m` on `$cls`/ancestor, or null. */
    private function magicReturnType(string $cls, string $m): ?Type
    {
        $c = $cls;
        while ($c !== '' && isset($this->classes[$c])) {
            $key = $c . '__' . $m;
            if (isset($this->sigs[$key])) { return $this->sigs[$key]; }
            $c = $this->classes[$c]->parent;
        }
        return null;
    }

    /** Return type of a concrete implementation of `$base::$method` (in `$base`
     *  itself, a subclass, or an interface implementer), or null. Types a call
     *  whose static class declares the method without a body — an abstract
     *  method or an interface. */
    private function concreteOverrideSig(string $base, string $method): ?Type
    {
        foreach ($this->classes as $cd) {
            if (!isset($this->sigs[$cd->name . '__' . $method])) { continue; }
            if ($cd->name === $base || $this->classImplementsT($cd->name, $base)) {
                return $this->sigs[$cd->name . '__' . $method];
            }
        }
        return null;
    }

    /**
     * The concrete return type of `$cls::$method` for a receiver that bound the
     * class's `@template` parameters (`Box<Tag>` → `@return T` becomes `Tag`).
     *
     * Null when the class is not generic, the receiver carries no binding, or
     * the method's return mentions no type variable — every one of which leaves
     * the existing erased sig in place, so a program that uses no generics is
     * completely unaffected.
     */
    /** Whether a value of this type travels as a boxed (tagged) cell. */
    private function isCellBoxed(Type $t): bool
    {
        return $t->kind === Type::KIND_CELL;
    }

    /**
     * The concrete return type of `$method` for a receiver that bound its class's
     * `@template` parameters (`Box<Tag>` → `@return T` becomes `Tag`).
     *
     * Climbs the inheritance chain: the generic method is often declared on a
     * generic BASE (`/** @extends Base<T> *\/ class Bag extends Base {}`), while
     * the receiver binds BAG's parameters. Each `@extends` re-maps the arguments
     * on the way up, so `Bag<float>` reaches `Base` as `Base<float>`.
     *
     * Null when nothing along the chain is generic — which leaves the erased
     * signature in place, so a program using no generics is untouched.
     */
    private function genericReturnType(string $cls, string $method, Type $recv): ?Type
    {
        $decl = $recv->class ?? '';
        $args = $recv->typeArgs;
        // No `<…>` at the use site: fall back to `@template T = int` defaults, so a
        // plain `Box` still types as the author said it should rather than erasing.
        if ($args === [] && isset($this->classes[$decl])) {
            $args = $this->defaultTypeArgs($this->classes[$decl]);
        }
        if ($args === []) { return null; }
        $seen = [];
        while ($decl !== '' && isset($this->classes[$decl]) && !isset($seen[$decl])) {
            $seen[$decl] = true;
            $cd = $this->classes[$decl];
            $bindings = $this->bindTypeParams($cd->typeParams, $args);
            if (isset($cd->genericReturns[$method])) {
                if ($bindings === []) { return null; }
                $g = $cd->genericReturns[$method];
                // An unbound leftover stays erased rather than leaking a typevar
                // into codegen, which knows nothing about KIND_TYPEVAR.
                return $g->substitute($bindings)->eraseTypeVars();
            }
            // Declared further up. Translate our arguments into the parent's.
            if ($cd->parent === '' || $cd->parentTypeArgs === []) { return null; }
            $next = [];
            foreach ($cd->parentTypeArgs as $pa) { $next[] = $pa->substitute($bindings); }
            $decl = $cd->parent;
            $args = $next;
        }
        return null;
    }

    /**
     * A generic class's `@template T = X` defaults, in parameter order. Empty
     * unless EVERY parameter has one — a partial default list cannot be matched
     * positionally against the parameters.
     *
     * @return Type[]
     */
    private function defaultTypeArgs(\Compile\Mir\ClassDef $cd): array
    {
        if ($cd->typeParams === []) { return []; }
        $out = [];
        foreach ($cd->typeParams as $p) {
            if (!isset($cd->typeParamDefaults[$p])) { return []; }
            $out[] = $cd->typeParamDefaults[$p];
        }
        return $out;
    }

    /**
     * Bind a class's type parameters to a use site's arguments, positionally.
     *
     * @param string[] $params
     * @param Type[]   $args
     * @return array<string, Type>
     */
    private function bindTypeParams(array $params, array $args): array
    {
        $out = [];
        $i = 0;
        $n = \count($args);
        foreach ($params as $p) {
            if ($i >= $n) { break; }
            $out[$p] = $args[$i];
            $i = $i + 1;
        }
        return $out;
    }

    private function inferMethodCall(MethodCall_ $node): Type
    {
        $objType = $this->inferNode($node->object);
        foreach ($node->args as $a) {
            $this->inferNode($a);
        }
        // A method on a `#[TypeDef]` receiver is a plain function of the scalar —
        // no dispatch, no vtable, nothing to be virtual over (the class is final
        // and has no runtime identity). Its return type comes from the same
        // `Class__method` signature every other call reads.
        $tdRecv = $objType->typeDefClass();
        if ($tdRecv !== null && isset($this->typeDefs[$tdRecv])) {
            $sig = $this->sigs[$tdRecv . '__' . $node->method] ?? null;
            $node->type = $sig ?? Type::unknown();
            return $node->type;
        }
        // Closure methods on a closure receiver: `->bindTo()` yields a (rebound)
        // closure; `->call()` invokes it and returns a tagged cell (uniform ABI).
        $recvCls = $objType->class ?? '';
        if ($objType->kind === Type::KIND_CLOSURE || \str_starts_with($recvCls, '__closure_')) {
            if ($node->method === 'bindTo') { $node->type = Type::closure(); return $node->type; }
            if ($node->method === 'call')   { $node->type = Type::cell();    return $node->type; }
        }
        // Generator iterator protocol: current()/send() yield the value type
        // (the Generator's element); key() an int; valid() a bool. next()/
        // rewind()/getReturn() are left as-is (void / unknown).
        if ($objType->isGenerator()) {
            $m = $node->method;
            if ($m === 'current' || $m === 'send' || $m === 'throw') {
                $node->type = $objType->element ?? Type::unknown();
            } elseif ($m === 'key') {
                $node->type = Type::int_();
            } elseif ($m === 'valid') {
                $node->type = Type::bool_();
            }
            return $node->type;
        }
        // Resolve the method's declared return type via the class
        // table — `Class__method` was registered with that type as
        // its sig during the function pre-pass.
        if ($objType->kind === Type::KIND_OBJ && $objType->class !== null) {
            // Method overloading: an unresolved instance method on a class with
            // __call → __call's return type (the reroute happens in EmitLlvm).
            if ($this->resolveMethodClass($objType->class, $node->method) === ''
                && $this->classDefinesMagic($objType->class, '__call')) {
                $rt = $this->magicReturnType($objType->class, '__call');
                $node->type = $rt ?? Type::cell();
                return $node->type;
            }
            $cls = $this->resolveMethodClass($objType->class, $node->method);
            // Interface-typed receiver (e.g. `\Throwable $e`): the interface has
            // no ClassDef, so borrow a return signature from an IMPLEMENTING
            // class. Matching on the method NAME alone picks whichever unrelated
            // class happens to declare one first and adopts ITS return type — a
            // `get()` colliding with some other class's `get(): float` typed every
            // result as a float, so a string came out as a double's bit pattern.
            if ($cls === '') {
                $recvIface = $objType->class;
                foreach ($this->classes as $cd) {
                    if (!isset($cd->methodNames[$node->method])) { continue; }
                    if ($this->classImplementsT($cd->name, $recvIface)) { $cls = $cd->name; break; }
                }
            }
            // No implementing class in this module (a cross-module interface, or
            // a built-in like \Throwable) — fall back to the old name-only match.
            if ($cls === '') {
                foreach ($this->classes as $cd) {
                    if (isset($cd->methodNames[$node->method])) { $cls = $cd->name; break; }
                }
            }
            if ($cls !== '') {
                $mangled = $cls . '__' . $node->method;
                if (isset($this->sigs[$mangled])) {
                    $node->type = $this->sigs[$mangled];
                } else {
                    // Abstract method (declared, no body → no sig of its own):
                    // adopt a concrete override's return type so the call result
                    // is typed (e.g. an abstract `: float` rendering raw bits).
                    $rt = $this->concreteOverrideSig($cls, $node->method);
                    if ($rt !== null) { $node->type = $rt; }
                }
                // Generic receiver (`Box<Tag>`): the sig above is the ERASED one
                // the shared body compiles against. Re-type the RESULT from the
                // method's un-erased `@return T` under this receiver's binding —
                // without it the call erases to unknown and `+` / `.` / echo take
                // the integer path, silently printing a pointer or a double's bits.
                // Generic receiver (`Box<float>`): the sig above is the ERASED
                // one the shared body compiles against — a boxed `cell`. Re-type
                // the RESULT from the method's un-erased `@return T` under this
                // receiver's binding, and mark it so the emitter unboxes.
                //
                // The cell alone is already CORRECT for strings/objects/ints (the
                // tag carries them), but a plain mixed cell keeps the INTEGER
                // path in arithmetic, so a `Box<float>` sum came back 0. Knowing
                // T here is what recovers the float.
                $gen = $this->genericReturnType($cls, $node->method, $objType);
                if ($gen !== null && $this->isCellBoxed($node->type)) {
                    // The value IS a cell — the shared body boxed it — and the
                    // cell already carries its tag, so strings / objects / ints
                    // come back right. What the binding adds is the cell's
                    // FLAVOR: a NUMERIC cell promotes by tag in arithmetic, while
                    // a plain mixed cell keeps the integer path (which read a
                    // float's tag as an int and produced 0). Retyping the result
                    // to the raw concrete type instead would be wrong — a string
                    // in a cell is tagged, not a bare pointer.
                    if ($gen->kind === Type::KIND_INT || $gen->kind === Type::KIND_FLOAT) {
                        $node->type = Type::numericCell();
                    }
                }
            }
        }
        // A cell/mixed receiver (`(A&B)|null`, a `mixed` holding an object) has
        // no static class — resolve the method's return type when every class
        // that declares it agrees, so `$cell->name()` reads its string result
        // correctly instead of as a raw pointer. Dispatch unboxes + class_id's
        // at runtime (EmitLlvm); a disagreement leaves the type unresolved.
        if ($objType->kind === Type::KIND_CELL) {
            $rt = $this->cellMethodReturn($node->method);
            if ($rt !== null) { $node->type = $rt; }
        }
        // A union receiver (`B|C`): resolve the method's return type from the
        // ATOMS — when every member agrees on the kind, the result is typed (so
        // a string-returning base method on `$union->name()` reads its pointer as
        // a string, not a raw int). Dispatch is the runtime class_id switch.
        if ($objType->kind === Type::KIND_UNION) {
            $rt = $this->unionMethodReturn($objType, $node->method);
            if ($rt !== null) { $node->type = $rt; }
        }
        return $node->type;
    }

    /** Whether `$class` (or an ancestor) implements `$iface`, transitively
     *  through the parent chain and interface inheritance. */
    private function classImplementsT(string $class, string $iface): bool
    {
        $seen = [];
        $stack = [$class];
        while ($stack !== []) {
            $c = \array_pop($stack);
            if ($c === '' || isset($seen[$c])) { continue; }
            $seen[$c] = true;
            if ($c === $iface) { return true; }
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { continue; }
            if ($cd->parent !== '') { $stack[] = $cd->parent; }
            foreach ($cd->interfaces as $i) { $stack[] = $i; }
        }
        return false;
    }

    /** Walk the parent chain for the class declaring `$method`. */
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

    private function inferStaticCall(StaticCall_ $node): Type
    {
        foreach ($node->args as $a) {
            $this->inferNode($a);
        }
        // Closure::bind($fn, $obj, $scope?) → a (rebound) closure value.
        if (\strtolower(\ltrim($node->class, '\\')) === 'closure' && $node->method === 'bind') {
            $node->type = Type::closure();
            return $node->type;
        }
        // Enum built-in `cases()` → list<Enum> (an enum value is carried as its
        // ordinal, so obj<Enum> is the right element type). from()/tryFrom() are
        // not yet implemented (throw / null-sentinel semantics).
        if (isset($this->enums[$node->class]) && $node->method === 'cases') {
            $node->type = Type::vec(Type::obj($node->class));
            return $node->type;
        }
        // `Enum::from($v)` → the enum (raw ordinal); `Enum::tryFrom($v)` → a
        // NULLABLE enum carried as a cell (box_null on miss, box_object(singleton)
        // on hit) — null can't be a raw ordinal (0 is a valid case).
        if (isset($this->enums[$node->class])
            && ($node->method === 'from' || $node->method === 'tryFrom')) {
            $node->type = $node->method === 'from'
                ? Type::obj($node->class)
                : new Type(Type::KIND_CELL, class: $node->class);
            return $node->type;
        }
        // Method overloading: an unresolved static method on a class with
        // __callStatic → __callStatic's return type (reroute is in EmitLlvm).
        if ($this->resolveMethodClass($node->class, $node->method) === ''
            && $this->classDefinesMagic($node->class, '__callStatic')) {
            $rt = $this->magicReturnType($node->class, '__callStatic');
            $node->type = $rt ?? Type::cell();
            return $node->type;
        }
        $cls = $this->resolveMethodClass($node->class, $node->method);
        if ($cls === '') { $cls = $node->class; }
        $sym = $cls . '__' . $node->method;
        if (isset($this->sigs[$sym])) { $node->type = $this->sigs[$sym]; }
        return $node->type;
    }
}
