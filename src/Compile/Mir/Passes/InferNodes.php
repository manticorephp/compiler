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
 * One inference method per MIR node kind.
 *
 * A trait on the one {@see InferTypes} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait InferNodes
{
    /**
     * Infer one function to a fixpoint over the loop-cell promotions. A local a
     * loop re-kinds is only discovered by inferring the loop (a call's return
     * kind is invisible to a pre-scan), and the promotion must hold from the
     * name's FIRST store — so the body is re-inferred with the name seeded as a
     * cell. Bounded: each round promotes at least one new name, and a promoted
     * name never demotes, so the set only grows.
     */
    private function inferFunction(FunctionDef $fn): void
    {
        $this->cellLoopLocals = [];
        $this->floatLoopLocals = [];
        $rounds = 0;
        while (true) {
            $this->loopPromoGrew = false;
            $this->inferFunctionOnce($fn);
            $rounds = $rounds + 1;
            if (!$this->loopPromoGrew || $rounds >= 4) { break; }
            $this->coercePromotedParams($fn);
        }
    }

    /**
     * A promoted PARAM arrives RAW — the caller passed a concrete int/string, and
     * nothing in the body converts it before the first read. Prepend one
     * `$p = box($p)` (cell) / `$p = (float)$p` (widened numeric) at entry: the same
     * self-coercing store the if/else merge uses. Keyed per fn+param so the
     * module-level re-infers do not stack copies.
     */
    private function coercePromotedParams(FunctionDef $fn): void
    {
        foreach ($fn->params as $p) {
            $isCell = isset($this->cellLoopLocals[$p->name]);
            $isFloat = !$isCell && isset($this->floatLoopLocals[$p->name])
                && $p->type->kind === Type::KIND_INT;
            if (!$isCell && !$isFloat) { continue; }
            $key = $fn->name . '|' . $p->name;
            if (isset($this->cellLoopBoxedParams[$key])) { continue; }
            $this->cellLoopBoxedParams[$key] = true;
            $slot = $isCell ? Type::cell() : Type::float_();
            $stmts = [$this->boxBackStore($p->name, $p->type, $slot)];
            foreach ($fn->body->stmts as $s) { $stmts[] = $s; }
            $fn->body = new Block($stmts, Type::void());
        }
    }

    private function isParamName(FunctionDef $fn, string $name): bool
    {
        foreach ($fn->params as $p) {
            if ($p->name === $name) { return true; }
        }
        return false;
    }

    private function inferFunctionOnce(FunctionDef $fn): void
    {
        $this->inClosureBody = \str_starts_with($fn->name, '__closure_');
        $this->localTypes = [];
        $this->kindAliasOf = [];
        $this->currentParamTypes = [];
        foreach ($fn->params as $p) {
            $this->localTypes[$p->name] = $p->type;
            $this->currentParamTypes[$p->name] = $p->type;
        }
        // Closure body: seed its capture params (the first N) with the value
        // types observed at the capture site (recorded in pass 1). Safe — this
        // is the fn's own fresh localTypes (no snapshot held), not a restore.
        $clNode = $this->closureNodeByName[$fn->name] ?? null;
        if ($clNode !== null) {
            $ci = 0;
            foreach ($clNode->captures as $c) {
                $pn = $this->paramNameAt($fn, $ci);
                if ($pn !== '' && $c->type->kind !== Type::KIND_UNKNOWN) {
                    $this->localTypes[$pn] = $c->type;
                }
                $ci = $ci + 1;
            }
        }
        // Pre-scan: refine a bare `array $p` param to vec[string] when the
        // body uses its elements as strings (`$x=$p[$i]; $x==="..."` / `$x[0]`
        // / substr($x)). Without an element type `$p[$i]` is i64 and a string
        // subscript / `===` mis-compiles (vec-access reads string bytes as an
        // index → SIGSEGV). Self-host needs this for the argv/options chain.
        $this->scanParamElements($fn);
        // Pre-scan: promote an array/unknown param to cell when it's stored into
        // a `mixed` property, so the call site boxes the (typed) argument.
        $this->scanParamCellSinks($fn);
        // Pre-scan: a local that is ever string-keyed (`$m["k"] = v`) is an
        // assoc, even when it starts life as an empty `[]` (which would
        // otherwise infer vec[unknown] and emit a vec-layout buffer — a
        // string-key store on that buffer reads the key as an i64 index and
        // faults). Seeing the shape up front lets the empty literal allocate
        // an assoc buffer.
        $this->assocLocals = [];
        $this->cellKeyLocals = [];
        $this->intKeyLocals = [];
        $this->strLitKeyLocals = [];
        $this->cellElemLocals = [];
        $this->assocValClasses = [];
        $this->emptyArrValLocals = [];
        $this->nestedScalarStoreLocals = [];
        $this->nestedCellVecLocals = [];
        $this->recordLitLocals = [];
        $this->recordDisqualified = [];
        $this->recordLocals = [];
        $this->scanAssocLocals($fn->body);
        // A string local that is `++`'d rides a cell (numeric→int/float, else the
        // Perl-incremented string). Detect: the local is BOTH an IncDec target and
        // assigned a string-producing value (literal / concat).
        $this->incStrLocals = [];
        $incTargets = [];
        $strAssigned = [];
        $this->scanIncStrLocals($fn->body, $incTargets, $strAssigned);
        foreach ($incTargets as $name => $unused) {
            if (isset($strAssigned[$name])) { $this->incStrLocals[$name] = true; }
        }
        // A MIXED-key array — string-keyed ($m["a"]=…) AND int-keyed ($m[5]=…) —
        // must ride a tagged cell key end-to-end (key_cell_at boxes each entry by
        // its KIND), else foreach reads the int key as a string ptr → SIGSEGV.
        foreach ($this->intKeyLocals as $name => $unused) {
            if (isset($this->strLitKeyLocals[$name])) { $this->cellKeyLocals[$name] = true; }
        }
        // A record local = a candidate (all-string-key literal) never disqualified
        // (never element-mutated, never assigned a non-record value). Its type
        // keeps the {@see Type::record} shape (set by inferStoreLocal) — same assoc
        // repr, plus per-field types for a shape-aware consumer.
        foreach ($this->recordLitLocals as $name => $unused) {
            if (!isset($this->recordDisqualified[$name])) { $this->recordLocals[$name] = true; }
        }
        // A local whose element stores carry ≥2 distinct value kinds is a mixed
        // array: seed a CELL element up front so EVERY load/store sees it (a
        // single forward pass would leave the early stores typed by the
        // unrefined scalar element → stored raw → read back as garbage).
        foreach ($this->assocValClasses as $name => $classes) {
            // A `null` element is special: its raw repr (0) differs from its cell
            // repr (box_null), so even a HOMOGENEOUS-null local ($d[]=null only)
            // must ride a cell element — else the store writes raw 0 and a later
            // isset/`??`/read misdecodes it as float(0) (every other single kind
            // has raw==boxed and reconciles). So a lone `null` also forces a cell.
            if (\count($classes) < 2 && !isset($classes['null'])) { continue; }
            if (isset($this->recordLocals[$name])) { continue; } // record keeps its shape
            $this->cellElemLocals[$name] = true;
            if (isset($this->assocLocals[$name])) {
                $key = isset($this->cellKeyLocals[$name]) ? Type::cell() : Type::string_();
                $this->localTypes[$name] = Type::assoc($key, Type::cell());
            } else {
                $this->localTypes[$name] = Type::vec(Type::cell());
            }
        }
        // Nested-subscript mixed array: a local whose element is an inner array
        // built from an empty `[]` (→ vec[unknown]) that then receives a nested
        // SCALAR store (`$a[k][…] = "v"`) — the scalar would be written raw into
        // the untyped inner vec and read back as garbage. Promote the local's
        // VALUE element to vec[cell] so the inner store/read box. The empty-literal
        // gate excludes matmul (`$m[0]=[1,2]` — non-empty inner, concrete element).
        foreach ($this->emptyArrValLocals as $name => $unused) {
            if (!isset($this->nestedScalarStoreLocals[$name])) { continue; }
            $this->nestedCellVecLocals[$name] = true;
            $val = Type::vec(Type::cell());
            if (isset($this->assocLocals[$name])) {
                $key = isset($this->cellKeyLocals[$name]) ? Type::cell() : Type::string_();
                $this->localTypes[$name] = Type::assoc($key, $val);
            } else {
                $this->localTypes[$name] = Type::vec($val);
            }
        }
        // Float-slot pre-pass: a local that ever receives a float-producing value
        // is seeded FLOAT up front, so the body infers it float throughout — a
        // loop accumulator (`$s = 0; for(...) { $s += 1.5; }`) keeps the float
        // type the back-edge merge would otherwise erase (int ∪ float → unknown).
        // An array local (assoc) is never a float slot.
        $this->floatLocals = [];
        $this->scanFloatLocals($fn->body);
        // A loop-widened numeric (found by inferring a PREVIOUS round — a plain
        // `$f = 2.5` in the body, which the syntactic scan above deliberately
        // ignores) joins the float slots, so its pre-loop int store sitofp's.
        foreach ($this->floatLoopLocals as $fll => $unused) {
            $this->floatLocals[$fll] = true;
        }
        foreach ($this->floatLocals as $fln => $unused) {
            if (isset($this->assocLocals[$fln])) { continue; }
            // A loop-widened PARAM arrives raw int — its slot only becomes float
            // after the entry sitofp store, exactly as for a cell-promoted one.
            if (isset($this->floatLoopLocals[$fln]) && $this->isParamName($fn, $fln)) { continue; }
            $this->localTypes[$fln] = Type::float_();
        }
        $this->genValueType = null;
        $this->genKeyType = null;
        $this->fnReturnUnion = null;
        $this->cellMergeLocals = [];
        $this->keyUsedLocals = [];
        $this->scanKeyUsedLocals($fn->body);
        // LAST, so it wins over every seeding scan above (a float/assoc seed would
        // otherwise pin a slot the loop already proved polymorphic): a name a loop
        // re-kinds is a cell from function ENTRY — its reads dispatch by tag
        // everywhere, not merely after the loop. {@see loopMerge}
        //
        // A PARAM is the exception: it arrives raw, so its slot is only a cell
        // AFTER the entry box-back store ({@see coercePromotedParams}). Seeding it
        // cell here would type that store's own `box($p)` operand as an
        // already-boxed cell — the store would copy the raw bits through and the
        // first read would unbox garbage.
        foreach ($this->cellLoopLocals as $cln => $unused) {
            if ($this->isParamName($fn, $cln)) { continue; }
            $this->localTypes[$cln] = Type::cell();
        }
        $this->inferNode($fn->body);
        // A function returning a CLOSURE loses the concrete `obj<__closure_N>`
        // class otherwise — an undeclared return is `unknown`, a declared
        // `: \Closure` is the generic KIND_CLOSURE — so `$g = make(); $g()`
        // dynamic-dispatches and (for a generator closure) `foreach` can't find
        // the Generator sig. Narrow: when every return is the SAME closure
        // class, adopt it as the sig. Multi-closure returns union to unknown
        // (no `__closure_` class) and keep the declared type.
        $rk = $fn->returnType->kind;
        $u = $this->fnReturnUnion;
        if (($rk === Type::KIND_UNKNOWN || $rk === Type::KIND_CLOSURE) && $u !== null
            && $u->class !== null && \str_starts_with($u->class, '__closure_')) {
            $fn->returnType = $u;
            $this->sigs[$fn->name] = $u;
        }
        // An untyped return that actually yields a cell (e.g. `return $mixed`)
        // is `mixed` — adopt cell so the CALL expression is typed cell (callers
        // then unbox/dispatch by tag instead of reading the raw boxed bits, e.g.
        // `f() && g()` truthiness). Narrow only when the body truly returns a
        // cell (minimal cascade).
        if ($rk === Type::KIND_UNKNOWN && $u !== null && $u->kind === Type::KIND_CELL) {
            $fn->returnType = $u;
            $this->sigs[$fn->name] = $u;
        }
        // An untyped function whose every return agrees on ONE scalar kind
        // (`function f($x){ return "v=".$x; }` → string) otherwise keeps an
        // `unknown` sig, so the CALL expression is unknown and `echo f()` renders
        // the string ptr as a number (%d). Adopt the concrete scalar so callers
        // type the result. Scalars only — array/obj carry rc and are handled by
        // NarrowReturns / their own discipline; a mixed-path return already
        // collapsed to unknown (unionWith) above and is not adopted.
        if ($rk === Type::KIND_UNKNOWN && $u !== null
            && ($u->kind === Type::KIND_STRING || $u->kind === Type::KIND_INT
                || $u->kind === Type::KIND_FLOAT || $u->kind === Type::KIND_BOOL)) {
            $fn->returnType = $u;
            $this->sigs[$fn->name] = $u;
        }
        // An un-hinted factory whose every return is the SAME concrete object
        // class (`static function create() { return new static(); }`) keeps an
        // unknown sig otherwise, so a chained `create()->method()` reads the
        // result as a raw pointer. Narrow to that object type.
        if ($rk === Type::KIND_UNKNOWN && $u !== null
            && $u->kind === Type::KIND_OBJ && $u->class !== null) {
            $fn->returnType = $u;
            $this->sigs[$fn->name] = $u;
        }
        // A cell-KEYED assoc return (dynamic int-or-string keys, e.g. a rebuild
        // `$o[$k] = …` over an erased foreach) must reach the caller typed so a
        // `foreach ($r as $k => $v)` uses the tagged-key path (key_cell_at) and
        // preserves string AND int keys. NarrowReturns runs only AFTER InferTypes,
        // so without adopting it here the caller types the result vec/unknown and
        // reads a string-key pointer as a raw int. Narrow only this dynamic-key
        // assoc shape (other array returns stay with NarrowReturns).
        if ($rk === Type::KIND_UNKNOWN && $u !== null
            && $u->isAssoc() && $u->key !== null && $u->key->kind === Type::KIND_CELL) {
            $fn->returnType = $u;
            $this->sigs[$fn->name] = $u;
        }
        // A CLOSURE always boxes a scalar return to a tagged cell (uniform ABI,
        // {@see EmitLlvm::emitReturn}). A return still UNKNOWN after the above
        // narrowings is integer arithmetic on cell params (`fn($x) => $x * 2`):
        // type it CELL so a DIRECT `$dbl(21)` call reads the boxed result by tag
        // (echo / store / compare), matching the dynamic `callable` path
        // ({@see inferInvoke}, which already types that result cell). Closures
        // only — a named fn's unknown keeps the integer-raw convention the
        // self-build relies on.
        if ($fn->returnType->kind === Type::KIND_UNKNOWN
            && \str_starts_with($fn->name, '__closure_')) {
            $fn->returnType = Type::cell();
            $this->sigs[$fn->name] = Type::cell();
        }
        // A declared NUMERIC cell return (`int|float`) whose body agrees on ONE
        // concrete numeric kind — e.g. array_sum's float specialization always
        // returns float — narrows to that scalar so `return $sum` does NOT box
        // the value (box_float truncates the mantissa → 0.6 → 0.5999…). A
        // null-returning path would have collapsed $u to unknown above (not
        // adopted), so a `?int` stays a cell; numeric union only.
        if ($rk === Type::KIND_CELL && $fn->returnType->isNumericCell() && $u !== null
            && ($u->kind === Type::KIND_INT || $u->kind === Type::KIND_FLOAT)) {
            $fn->returnType = $u;
            $this->sigs[$fn->name] = $u;
        }
        // A generator's value element = union of its yield value types (and key
        // type); refine the returnType (and the cached sig) so foreach/callers
        // see Generator<TKey, TValue>.
        if ($fn->isGenerator) {
            // Prefer the inferred types; fall back to a declared
            // `Generator<K, V>` when the body yields nothing concrete.
            $dv = $fn->returnType->isGenerator() ? $fn->returnType->element : null;
            $dk = $fn->returnType->isGenerator() ? $fn->returnType->key : null;
            $vt = $this->genValueType ?? $dv ?? Type::unknown();
            $kt = $this->genKeyType ?? $dk;
            $fn->returnType = Type::generator($vt, $kt);
            $this->sigs[$fn->name] = $fn->returnType;
        }
    }

    private function inferNode(Node $node): Type
    {
        $kind = $node->kind;
        if ($kind === Node::KIND_INT_CONST
            || $kind === Node::KIND_FLOAT_CONST
            || $kind === Node::KIND_STRING_CONST
            || $kind === Node::KIND_BOOL_CONST
            || $kind === Node::KIND_NULL_CONST) {
            return $node->type;
        }
        if ($kind === Node::KIND_LOAD_LOCAL)  { return $this->inferLoadLocal($node); }
        if ($kind === Node::KIND_STORE_LOCAL) { return $this->inferStoreLocal($node); }
        if ($kind === Node::KIND_ADD)         { return $this->inferAdd($node); }
        if ($kind === Node::KIND_SUB)         { return $this->inferSub($node); }
        if ($kind === Node::KIND_MUL)         { return $this->inferMul($node); }
        if ($kind === Node::KIND_DIV)         { return $this->inferDiv($node); }
        if ($kind === Node::KIND_MOD)         { return $this->inferMod($node); }
        if ($kind === Node::KIND_NEG)         { return $this->inferNeg($node); }
        if ($kind === Node::KIND_NOT)         { return $this->inferNot($node); }
        if ($kind === Node::KIND_BITOP)       { return $this->inferBitOp($node); }
        if ($kind === Node::KIND_BITNOT)      { return $this->inferBitNot($node); }
        if ($kind === Node::KIND_CAST)        { return $this->inferCast($node); }
        if ($kind === Node::KIND_INSTANCEOF)  { return $this->inferInstanceof($node); }
        if ($kind === Node::KIND_NULLCOALESCE){ return $this->inferNullCoalesce($node); }
        if ($kind === Node::KIND_CLOSURE)     { return $this->inferClosure($node); }
        if ($kind === Node::KIND_INVOKE)      { return $this->inferInvoke($node); }
        if ($kind === Node::KIND_INCDEC)      { return $this->inferIncDec($node); }
        if ($kind === Node::KIND_STATIC_PROP) { return $node->type; }
        if ($kind === Node::KIND_STORE_STATIC_PROP) { return $this->inferStoreStaticProp($node); }
        if ($kind === Node::KIND_STATIC_LOCAL_DECL) { return $this->inferStaticLocalDecl($node); }
        if ($kind === Node::KIND_ISSET) { return $this->inferIsset($node); }
        if ($kind === Node::KIND_UNSET) { return $this->inferUnset($node); }
        if ($kind === Node::KIND_CLASS_NAME) { return $this->inferClassName($node); }
        if ($kind === Node::KIND_REF_ALIAS) { return $this->inferRefAlias($node); }
        if ($kind === Node::KIND_REF_BIND) { return $this->inferRefBind($node); }
        if ($kind === Node::KIND_REF_ADDR) { return $this->inferRefAddr($node); }
        if ($kind === Node::KIND_THROW) { return $this->inferThrow($node); }
        if ($kind === Node::KIND_TRY_CATCH) { return $this->inferTryCatch($node); }
        if ($kind === Node::KIND_TERNARY)     { return $this->inferTernary($node); }
        if ($kind === Node::KIND_CONCAT)      { return $this->inferConcat($node); }
        if ($kind === Node::KIND_CMP)         { return $this->inferCmp($node); }
        if ($kind === Node::KIND_ECHO)        { return $this->inferEcho($node); }
        if ($kind === Node::KIND_RETURN)      { return $this->inferReturn($node); }
        if ($kind === Node::KIND_CALL)        { return $this->inferCall($node); }
        if ($kind === Node::KIND_IF)          { return $this->inferIf($node); }
        if ($kind === Node::KIND_WHILE)       { return $this->inferWhile($node); }
        if ($kind === Node::KIND_FOR)         { return $this->inferFor($node); }
        if ($kind === Node::KIND_DOWHILE)     { return $this->inferDoWhile($node); }
        if ($kind === Node::KIND_FOREACH)     { return $this->inferForeach($node); }
        if ($kind === Node::KIND_SWITCH)      { return $this->inferSwitch($node); }
        if ($kind === Node::KIND_MATCH)       { return $this->inferMatch($node); }
        if ($kind === Node::KIND_BREAK
            || $kind === Node::KIND_CONTINUE
            || $kind === Node::KIND_GOTO
            || $kind === Node::KIND_LABEL) { return Type::void(); }
        if ($kind === Node::KIND_YIELD) {
            $y = $node;
            if ($y->key !== null) {
                $kt = $this->inferNode($y->key);
                $this->genKeyType = $this->genKeyType === null
                    ? $kt : $this->unionTypes($this->genKeyType, $kt);
            }
            if ($y->value !== null) {
                $vt = $this->inferNode($y->value);
                $this->genValueType = $this->genValueType === null
                    ? $vt : $this->unionTypes($this->genValueType, $vt);
            }
            return Type::cell();
        }
        if ($kind === Node::KIND_SPREAD)          { return $this->inferSpread($node); }
        if ($kind === Node::KIND_ARRAY_LIT)       { return $this->inferArrayLit($node); }
        if ($kind === Node::KIND_ARRAY_ACCESS)    { return $this->inferArrayAccess($node); }
        if ($kind === Node::KIND_STORE_ELEMENT)   { return $this->inferStoreElement($node); }
        if ($kind === Node::KIND_NEW_OBJ)         { return $this->inferNewObj($node); }
        if ($kind === Node::KIND_CLONE) {
            $cl = $node;
            $ot = $this->inferNode($cl->object);
            foreach ($cl->withProps as $pair) { $this->inferNode($pair->value); }
            $node->type = $ot;
            return $ot;
        }
        if ($kind === Node::KIND_DYN_PROP) { return $this->inferDynProp($node); }
        if ($kind === Node::KIND_STORE_DYN_PROP) { return $this->inferStoreDynProp($node); }
        if ($kind === Node::KIND_PROPERTY_ACCESS) { return $this->inferPropertyAccess($node); }
        if ($kind === Node::KIND_STORE_PROPERTY)  { return $this->inferStoreProperty($node); }
        if ($kind === Node::KIND_METHOD_CALL)     { return $this->inferMethodCall($node); }
        if ($kind === Node::KIND_STATIC_CALL)     { return $this->inferStaticCall($node); }
        if ($kind === Node::KIND_BLOCK)       { return $this->inferBlock($node); }
        return $node->type;
    }

    private function inferLoadLocal(LoadLocal $node): Type
    {
        $name = $node->name;
        if (isset($this->localTypes[$name])) {
            $node->type = $this->localTypes[$name];
        }
        return $node->type;
    }

    /** A store needing de-cellify: `$slotType` is a CONCRETE-element array
     *  (scalar/obj/nested-array element) and `$valueType` is a cell-element
     *  array. Mirrors EmitLlvmBuiltins::needsDeCellify (the emit side). */
    private function isDeCellifyStore(Type $slotType, Type $valueType): bool
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

    private function inferStoreLocal(StoreLocal $node): Type
    {
        $valueType = $this->inferNode($node->value);
        // Kind-alias tracking: a re-store to $name drops any stale alias; a
        // `$name = $obj->kind` binding records it so a later `$name === KIND_X`
        // narrows $obj. (Object-reassignment is caught downstream: the narrow
        // gate requires $obj to still be typed base `Node`.)
        unset($this->kindAliasOf[$node->name]);
        if ($node->value->kind === Node::KIND_PROPERTY_ACCESS) {
            $pv = $node->value;
            if ($pv->property === 'kind' && $pv->object->kind === Node::KIND_LOAD_LOCAL) {
                $this->kindAliasOf[$node->name] = $pv->object->name;
            }
        }
        // A `++`'d string local rides a CELL: box every assignment so the slot is
        // uniformly tagged (a string cell here; an int/float cell after `++`
        // promotes a numeric string). The store NODE typed cell + a concrete value
        // triggers emitStoreLocal's box-back. {@see inferIncDec}
        //
        // Same discipline for a local a LOOP re-kinds ({@see loopMerge}): the slot
        // is polymorphic across the back-edge, so every store boxes and every read
        // dispatches by tag.
        if (isset($this->cellLoopLocals[$node->name])) {
            $this->localTypes[$node->name] = Type::cell();
            $node->type = Type::cell();
            return $node->type;
        }
        if (isset($this->incStrLocals[$node->name])) {
            $this->localTypes[$node->name] = Type::cell();
            $node->type = Type::cell();
            return $node->type;
        }
        // De-cellify plant: a store to a concrete-element array PARAM from a
        // cell-element array value. The param's concrete type is authoritative
        // (a byref contract / typed slot), so keep it and mark the store NODE
        // with it — emitStoreLocal then rebuilds the value with each element
        // unboxed to the param's repr. Without this a cell-valued array bound
        // back to a typed param (uasort's `$arr = $new` undecorate) leaves boxed
        // values that a later typed read renders raw. {@see emitCellArrayToTyped}
        $pt = $this->currentParamTypes[$node->name] ?? null;
        if ($pt !== null && $this->isDeCellifyStore($pt, $valueType)) {
            $this->localTypes[$node->name] = $pt;
            $node->type = $pt;
            return $pt;
        }
        // An inline `/** @var T $x */` on the binding is authoritative: seed the
        // slot with the declared type (retyping an array-literal init to match
        // its shape) so later element reads resolve. Wins over the heuristics.
        if ($node->declaredType !== null) {
            $dt = $node->declaredType;
            if ($node->value->kind === Node::KIND_ARRAY_LIT && $dt->isArray()) {
                $node->value->type = $dt;
            }
            $this->localTypes[$node->name] = $dt;
            $node->type = $dt;
            return $dt;
        }
        // A record local (all-string-key literal, never mutated) keeps its
        // {@see Type::record} shape — the literal already carries the field
        // types; hold them on the slot so json_encode($local) can specialize.
        if (isset($this->recordLocals[$node->name]) && $valueType->isRecord()) {
            $this->localTypes[$node->name] = $valueType;
            $node->type = $valueType;
            return $valueType;
        }
        // An empty `[]` bound to a string-keyed local is an assoc, not a vec:
        // retype the literal so it emits an assoc buffer (its element type is
        // refined by the subsequent string-keyed stores in inferStoreElement).
        if (isset($this->assocLocals[$node->name])
            && $node->value->kind === Node::KIND_ARRAY_LIT
            && $valueType->isVec()
            && \count($node->value->elements) === 0) {
            // A cell-keyed local (`$o[$dynKey]=…`) carries dynamic int-or-string
            // keys → assoc[cell,*]; a plain string-keyed local → assoc[string,*].
            $keyType = isset($this->cellKeyLocals[$node->name]) ? Type::cell() : Type::string_();            $valueType = Type::assoc($keyType, Type::unknown());
            $node->value->type = $valueType;
        }
        // A nested-subscript mixed local (nestedCellVecLocals) keeps a vec[cell]
        // VALUE element across binding: the outer `[]` init must emit an array
        // whose elements are inner cell-vecs, so `$a[k][…] = scalar` boxes.
        if (isset($this->nestedCellVecLocals[$node->name]) && $valueType->isArray()) {
            $val = Type::vec(Type::cell());
            $keyType = isset($this->cellKeyLocals[$node->name]) ? Type::cell() : Type::string_();
            $shape = isset($this->assocLocals[$node->name])
                ? Type::assoc($keyType, $val) : Type::vec($val);
            $node->value->type = $shape;
            $this->localTypes[$node->name] = $shape;
            $node->type = $shape;
            return $shape;
        }
        // A mixed-array slot (cellElemLocals) keeps its CELL element when bound
        // to an array literal: the `[]`/`[…]` init would otherwise re-narrow the
        // slot to the literal's element, leaving later string/scalar stores
        // unboxed. Retype the literal to the cell shape so it emits a cell buffer.
        if (isset($this->cellElemLocals[$node->name]) && $valueType->isArray()) {
            $keyType = isset($this->cellKeyLocals[$node->name]) ? Type::cell() : Type::string_();
            $shape = isset($this->assocLocals[$node->name])
                ? Type::assoc($keyType, Type::cell()) : Type::vec(Type::cell());
            $node->value->type = $shape;
            $this->localTypes[$node->name] = $shape;
            $node->type = $shape;
            return $shape;
        }
        // A float-slot local keeps FLOAT even on an int/bool store: the store
        // NODE is typed float while its VALUE stays int — the precise (float
        // store, int value) combo emitStoreLocal coerces with a sitofp. Without
        // this a `$s = 0` init re-types the slot to int and the loop back-edge
        // merge erases it (int ∪ float → unknown → float bits read as garbage).
        if (isset($this->floatLocals[$node->name])
            && !isset($this->assocLocals[$node->name])
            && ($valueType->kind === Type::KIND_INT || $valueType->kind === Type::KIND_BOOL)) {
            $this->localTypes[$node->name] = Type::float_();
            $node->type = Type::float_();
            return Type::float_();
        }
        $this->localTypes[$node->name] = $valueType;
        $node->type = $valueType;
        return $valueType;
    }

    private function inferStoreStaticProp(StoreStaticProp_ $n): Type
    {
        $vt = $this->inferNode($n->value);
        $n->type = $vt;
        return $vt;
    }

    private function inferThrow(Throw_ $n): Type
    {
        $this->inferNode($n->value);
        return Type::void();
    }

    private function inferTryCatch(TryCatch_ $n): Type
    {
        foreach ($n->tryBody as $s) { $this->inferNode($s); }
        foreach ($n->catches as $c) {
            // Bind `$e` to the first declared catch type (obj<T>).
            if ($c->var !== null && \count($c->types) > 0) {
                $this->localTypes[$c->var] = Type::obj($c->types[0]);
            }
            foreach ($c->body as $s) { $this->inferNode($s); }
        }
        foreach ($n->finallyBody as $s) { $this->inferNode($s); }
        return Type::void();
    }

    private function inferRefBind(RefBind_ $node): Type
    {
        $t = $this->inferNode($node->call);
        $this->localTypes[$node->target] = $t;
        return Type::void();
    }

    private function inferRefAddr(\Compile\Mir\RefAddr_ $node): Type
    {
        $t = $this->inferNode($node->lvalue);
        $this->localTypes[$node->target] = $t;
        return Type::void();
    }

    private function inferRefAlias(RefAlias_ $n): Type
    {
        if (isset($this->localTypes[$n->source])) {
            $this->localTypes[$n->target] = $this->localTypes[$n->source];
        }
        return Type::void();
    }

    private function inferClassName(ClassName_ $n): Type
    {
        $this->inferNode($n->operand);
        $n->type = Type::string_();
        return $n->type;
    }

    private function inferIsset(Isset_ $n): Type
    {
        foreach ($n->targets as $t) { $this->inferNode($t); }
        $n->type = Type::bool_();
        return $n->type;
    }

    private function inferUnset(Unset_ $n): Type
    {
        foreach ($n->targets as $t) { $this->inferNode($t); }
        return Type::void();
    }

    private function inferStaticLocalDecl(StaticLocalDecl_ $n): Type
    {
        $t = $n->type;
        if ($n->init !== null) { $t = $this->inferNode($n->init); }
        // A global-backed decl (`global $g`) is hard-lowered `int`; seed its
        // unified cross-scope type ({@see scanGlobalTypes}) so a pure-read scope
        // (`global $g; return $g;`) carries the real string/obj/array type.
        elseif (\str_starts_with($n->cell, '@g_')
                && isset($this->globalVarTypes[$n->name])) {
            $t = $this->globalVarTypes[$n->name];
        }
        $this->localTypes[$n->name] = $t;
        $n->type = $t;
        return $t;
    }

    private function inferAdd(Add $n): Type { $t = $this->arithType($n->left, $n->right); $n->type = $t; return $t; }

    private function inferSub(Sub $n): Type { $t = $this->arithType($n->left, $n->right); $n->type = $t; return $t; }

    private function inferMul(Mul $n): Type { $t = $this->arithType($n->left, $n->right); $n->type = $t; return $t; }

    private function inferDiv(Div $n): Type
    {
        $this->inferNode($n->left);
        $this->inferNode($n->right);
        // PHP `/` is float UNLESS both operands are int and evenly divisible
        // (then int). A runtime int|float result would have to ride a numeric
        // cell, which cascades into array-index / concat / `+=` contexts that
        // expect a raw scalar (net regression for a low-value parity nit); the
        // literal case is folded in ConstFold instead. A variable `int / int`
        // stays float.
        $t = Type::float_();
        $n->type = $t;
        return $t;
    }

    private function inferMod(Mod $n): Type
    {
        $this->inferNode($n->left);
        $this->inferNode($n->right);
        $t = Type::int_();
        $n->type = $t;
        return $t;
    }

    private function inferNeg(Neg $node): Type
    {
        $t = $this->inferNode($node->operand);
        if ($t->kind === Type::KIND_FLOAT) {
            $node->type = Type::float_();
        } else if ($t->kind === Type::KIND_INT) {
            $node->type = Type::int_();
        } else if ($t->isNumericCell()) {
            // `-$x` on an int|float cell stays a dynamic numeric cell (EmitLlvm
            // negates via tagged_sub, preserving the runtime tag).
            $node->type = Type::numericCell();
        } else if ($t->kind === Type::KIND_CELL) {
            // A mixed/untyped operand is unboxed to int and negated as a raw
            // integer (EmitLlvm coerceArithOperand) → the result is an int.
            $node->type = Type::int_();
        }
        return $node->type;
    }

    private function inferNot(Not_ $node): Type
    {
        $this->inferNode($node->operand);
        return $node->type;
    }

    private function inferBitOp(\Compile\Mir\BitOp $node): Type
    {
        $this->inferNode($node->left);
        $this->inferNode($node->right);
        return $node->type; // integer bitwise → int
    }

    private function inferBitNot(\Compile\Mir\BitNot_ $node): Type
    {
        $this->inferNode($node->operand);
        return $node->type; // → int
    }

    private function inferConcat(Concat $node): Type
    {
        $this->inferNode($node->left);
        $this->inferNode($node->right);
        return $node->type; // always string
    }

    private function inferCmp(Cmp $node): Type
    {
        $this->inferNode($node->left);
        $this->inferNode($node->right);
        return $node->type;
    }

    private function inferEcho(Echo_ $node): Type
    {
        foreach ($node->exprs as $e) {
            $this->inferNode($e);
        }
        return $node->type;
    }

    private function inferReturn(Return_ $node): Type
    {
        $value = $node->value;
        if ($value !== null) {
            $rt = $this->inferNode($value);
            if ($this->fnReturnUnion === null) {
                $this->fnReturnUnion = $rt;
            } else {
                $merged = $this->fnReturnUnion->unionWith($rt);
                // Returns of DISTINCT concrete value kinds (`return "big"`;
                // `return 1.5`; `return $n`) collapse to `unknown`; unify on a
                // cell so the inferred return type is `mixed` (adopted below) —
                // emitReturn boxes each return, callers dispatch by tag. Mirrors
                // inferTernary/inferMatch.
                if ($merged->kind === Type::KIND_UNKNOWN
                    && ($this->fnReturnUnion->kind === Type::KIND_CELL
                        || $rt->kind === Type::KIND_CELL
                        || ($this->isValueKind($this->fnReturnUnion) && $this->isValueKind($rt)))) {
                    // All-numeric returns (int|float) → a numeric cell so the
                    // caller can arith-promote; else a plain mixed cell.
                    $merged = $this->unifyToCell($this->fnReturnUnion, $rt);
                }
                $this->fnReturnUnion = $merged;
            }
        }
        return $node->type;
    }

    private function inferBlock(Block $node): Type
    {
        foreach ($node->stmts as $s) {
            $this->inferNode($s);
        }
        return $node->type;
    }

    /**
     * Type the two branches independently against snapshots of the
     * incoming local map, then union per-local at the merge.
     */
    private function inferIf(If_ $node): Type
    {
        $this->inferNode($node->cond);
        $saved = $this->localTypes;
        // Flow-typing: narrow a local inside the then-branch from the guard.
        $this->narrowFromCond($node->cond);
        $this->inferNode($node->then);
        $thenLocals = $this->localTypes;
        // A branch that DIVERGES (ends in return/throw) never reaches the merge,
        // so its narrowed locals must not flow past the `if` — else a guard like
        // `if ($v instanceof C) { ...; return; }` leaves `$v` mistyped (non-cell)
        // for the fall-through, and a later cell op reads it raw → crash.
        $thenDiv = $this->blockDiverges($node->then);
        if ($node->else === null) {
            $this->planMergeShadow($node, $thenLocals, $saved, false);
            if ($thenDiv) {
                // `if (NEG) return/throw;` — the fall-through is the NEGATION of
                // the guard, so narrow as if the un-negated form held below
                // (`if ($x->kind !== KIND_X) return;` ⇒ `$x` IS X afterwards).
                $this->localTypes = $saved;
                $this->narrowFromNegatedCond($node->cond);
            } else {
                $this->localTypes = $this->mergeLocals($saved, $thenLocals);
            }
            return Type::void();
        }
        $this->localTypes = $saved;
        $this->inferNode($node->else);
        $elseLocals = $this->localTypes;
        $this->planMergeShadow($node, $thenLocals, $elseLocals, true);
        $elseDiv = $this->blockDiverges($node->else);
        if ($thenDiv && !$elseDiv)      { $this->localTypes = $elseLocals; }
        elseif ($elseDiv && !$thenDiv)  { $this->localTypes = $thenLocals; }
        else                            { $this->localTypes = $this->mergeLocals($thenLocals, $elseLocals); }
        return Type::void();
    }

    /**
     * Conservative while inference: type the body once against an
     * incoming snapshot, then union the post-body map with the
     * pre-loop map so locals reflect "may have entered". Loop
     * variants (a counter narrowing on each iteration) need a CFG +
     * fixed-point iteration, which lands in a later pass.
     */
    private function inferWhile(While_ $node): Type
    {
        $this->inferNode($node->cond);
        $saved = $this->localTypes;
        // The body runs with the loop condition holding — narrow from it (e.g.
        // `while ($n->kind === KIND_X) { … }` types `$n` as X inside). The merge
        // below unions back the un-narrowed pre-loop map, so it stays body-scoped.
        $this->narrowFromCond($node->cond);
        $this->inferNode($node->body);
        $merged = $this->loopMerge($saved, $this->localTypes);
        if ($this->localTypesWidened($saved, $merged)) {
            $this->localTypes = $merged;
            $this->narrowFromCond($node->cond);
            $this->inferNode($node->body);
            $merged = $this->loopMerge($saved, $this->localTypes);
        }
        $this->localTypes = $merged;
        return Type::void();
    }

    private function inferCast(Cast $node): Type
    {
        $this->inferNode($node->operand);
        return $node->type;
    }

    private function inferInstanceof(Instanceof_ $node): Type
    {
        $this->inferNode($node->operand);
        return $node->type;
    }

    private function inferNullCoalesce(NullCoalesce_ $node): Type
    {
        $lt = $this->inferNode($node->left);
        $rt = $this->inferNode($node->right);
        // `$a ?? $b` = `$a` when non-null, else `$b`. A null-typed left always
        // yields the fallback. An UNKNOWN-typed left carries no usable repr —
        // a `?string` local merges (null ∪ string) to unknown — so when the
        // fallback has a concrete repr, let IT drive the result type. Otherwise
        // the value rides as a raw i64 and renders as a number (and a string
        // key would store under an int slot). A null/unknown fallback keeps
        // the left's type (the historic behaviour).
        if ($lt->kind === Type::KIND_NULL) {
            $node->type = $rt;
        } elseif ($lt->kind === Type::KIND_UNKNOWN
            && $rt->kind !== Type::KIND_NULL && $rt->kind !== Type::KIND_UNKNOWN) {
            $node->type = $rt;
        } elseif ($lt->kind !== $rt->kind
            && ($this->isValueKind($lt) || $lt->kind === Type::KIND_CELL)
            && ($this->isValueKind($rt) || $rt->kind === Type::KIND_CELL)) {
            // Arms carry DIFFERENT concrete reprs (e.g. an int array element
            // `?? "default"` string, or a chained `?? (… ?? …)` cell): the result
            // rides as a tagged cell so a consumer (echo / var_dump) dispatches on
            // the arm actually taken. Without this a chosen string fallback's
            // pointer renders as an int (the missing-key `??` garbage) — the
            // array-access emit path already boxes both arms for a cell node.
            $node->type = $this->unifyToCell($lt, $rt);
        } elseif ($rt->kind === Type::KIND_NULL
            && $node->left->kind === Node::KIND_ARRAY_ACCESS
            && ($lt->kind === Type::KIND_INT || $lt->kind === Type::KIND_FLOAT
                || $lt->kind === Type::KIND_BOOL || $lt->kind === Type::KIND_UNKNOWN)) {
            // `$arr[$k] ?? null`: the key may be ABSENT. The emit path already
            // picks the null default by isset, but a raw-scalar result type would
            // coerce that null arm to 0 — colliding with a present 0 and defeating
            // `=== null` / var_dump. Ride a tagged cell so real null survives (a
            // present value boxes losslessly, unboxes on demand). Pointer-typed
            // lefts keep their type: a null pointer is already a sound 0.
            $node->type = Type::cell();
        } else {
            $node->type = $lt;
        }
        return $node->type;
    }

    private function inferIncDec(IncDec $node): Type
    {
        // `$s++` on a STRING local is Perl-style / numeric-string increment: the
        // slot rides a CELL (a numeric string → int/float; else the incremented
        // string) — pinned up front by scanIncStrLocals so every use boxes/unboxes.
        if (isset($this->incStrLocals[$node->name])) {
            $this->localTypes[$node->name] = Type::cell();
            $node->type = Type::cell();
            return $node->type;
        }
        // `$x++` reads + writes an int local; pin the slot to int.
        $this->localTypes[$node->name] = Type::int_();
        return Type::int_();
    }

    private function inferTernary(Ternary $node): Type
    {
        $this->inferNode($node->cond);
        $saved = $this->localTypes;
        // Flow-typing across the arms (short-circuit): the then-arm evaluates only
        // when `cond` holds, the else-arm only when it doesn't. This also narrows
        // the second conjunct of `A && B` (lowered to `Ternary(A, !!B, false)`),
        // so `A === ($x->kind===KIND_X)` types `$x` inside B. The merge below
        // unions the arms, so no narrowing leaks past the ternary.
        if ($node->then !== null) {
            $this->narrowFromCond($node->cond);
            $t = $this->inferNode($node->then);
        } else {
            $t = $node->cond->type;
        }
        $thenLocals = $this->localTypes;
        $this->localTypes = $saved;
        $this->narrowFromNegatedCond($node->cond);
        $e = $this->inferNode($node->else_);
        $this->localTypes = $this->mergeLocals($thenLocals, $this->localTypes);
        // A nullsafe desugar (`$o?->prop`) pairs its null arm with the value
        // branch as a NULLABLE cell so the null case renders as NULL (not the
        // value type's zero); emitTernary boxes the value branch. A PLAIN ternary
        // keeps the historical "null arm takes the other branch's type" (a
        // broad flip perturbs the self-host — clone lowering regressed).
        if ($t->kind === Type::KIND_NULL) {
            $node->type = ($node->nullable || $this->scalarNullArm($e)) ? $this->nullableOf($e) : $e;
        } elseif ($e->kind === Type::KIND_NULL) {
            $node->type = ($node->nullable || $this->scalarNullArm($t)) ? $this->nullableOf($t) : $t;
        }
        elseif (($t->kind === Type::KIND_OBJ || $t->kind === Type::KIND_UNION)
            && ($e->kind === Type::KIND_OBJ || $e->kind === Type::KIND_UNION)) {
            // Object arms (`cond ? new B : new C`, or one side already a union) →
            // a static `B|C` union, so the method-call site dispatches on the
            // runtime class_id instead of binding to the then-branch's class. A
            // single shared class collapses back to `obj<…>` in Type::union.
            $node->type = Type::union([$t, $e]);
        }
        elseif ($t->kind === $e->kind)        { $node->type = $t; }
        elseif ($t->kind === Type::KIND_CELL || $e->kind === Type::KIND_CELL) {
            // Heterogeneous branches where one side is a NaN-boxed cell
            // (e.g. `isset($m["k"]) ? $m["k"] : []` over a json_decode value).
            // Unify on the cell — the universal tagged repr — and let
            // emitTernary box the other branch. Without this the result types
            // `unknown` and the two branches store INCOMPATIBLE reprs (boxed
            // cell vs raw array ptr): a later `foreach`/echo reads one as the
            // other and faults. int|float → a numeric cell (arith can promote).
            $node->type = $this->unifyToCell($t, $e);
        }
        elseif ($this->isValueKind($t) && $this->isValueKind($e)) {
            // Distinct concrete value branches (`$b ? 3 : 2.5` = int|float,
            // int|string, obj|int, …): unify on a NaN-boxed cell so the value
            // carries its runtime tag; emitTernary boxes both branches. Without
            // this the result types `unknown` and e.g. the float rides as a raw
            // i64 → echo renders 0. A numeric (int|float) union stays arith-able.
            $node->type = $this->unifyToCell($t, $e);
        }
        else { $node->type = Type::unknown(); }
        return $node->type;
    }

    private function inferForeach(Foreach_ $node): Type
    {
        $at = $this->inferNode($node->array);
        $elem = Type::unknown();
        $keyT = Type::int_();
        if ($at->isGenerator()) {
            // `foreach ($gen as $k => $v)`: $v is the yielded value type,
            // $k the yielded key type (explicit) or the auto-int default.
            if ($at->element !== null) { $elem = $at->element; }
            if ($at->key !== null) { $keyT = $at->key; }
        } elseif ($at->isAssoc()
            || ($at->isArray() && $at->key !== null && $at->key->kind === Type::KIND_CELL)) {
            // Use the assoc's actual key type — a cell-keyed assoc (dynamic
            // int-or-string keys, incl. a MIXED literal-key array) yields a
            // tagged-cell key, not a string. `isAssoc()` is string-key-only, so
            // the cell-key case is matched explicitly (else keyT defaults to int
            // and the cell key prints as a raw integer).
            $keyT = $at->key ?? Type::string_();
            if ($at->element !== null) { $elem = $at->element; }
        } elseif ($at->isVec()) {
            if ($at->element !== null) { $elem = $at->element; }
        } elseif ($at->kind === Type::KIND_OBJ && ($at->class ?? '') !== ''
            && ($at->class ?? '') !== 'Generator') {
            // foreach over a Traversable object: drive its Iterator protocol.
            // An IteratorAggregate yields its getIterator() result's class.
            $cls = $at->class;
            if ($this->classImplementsT($cls, 'IteratorAggregate')
                && !$this->classImplementsT($cls, 'Iterator')) {
                $node->iterAggregate = true;
                $giCls = $this->resolveMethodClass($cls, 'getIterator');
                $giRet = $giCls !== '' ? ($this->sigs[$giCls . '__getIterator'] ?? null) : null;
                $node->iterClass = ($giRet !== null && $giRet->class !== null) ? $giRet->class : 'Iterator';
            } elseif ($this->classImplementsT($cls, 'Iterator')
                || $this->classImplementsT($cls, 'Traversable')) {
                $node->iterClass = $cls;
            }
            if ($node->iterClass !== '') {
                // value/key types = the iterator's current()/key() return types.
                // An interface iterClass (e.g. getIterator(): Iterator) has no
                // ClassDef — fall back to any implementer's sig.
                $ic = $node->iterClass;
                $elem = $this->iterMethodReturn($ic, 'current', $elem);
                $keyT = $this->iterMethodReturn($ic, 'key', $keyT);
            }
        }
        // Iterating a `mixed` (tagged cell) that holds an array: both the
        // value AND the key come back as tagged cells (a cell array's key is
        // int-OR-string at runtime, so it can't ride a raw i64 carrier).
        if ($at->kind === Type::KIND_CELL) {
            $elem = Type::cell();
            $keyT = Type::cell();
        } elseif ($at->kind === Type::KIND_UNKNOWN) {
            // An erased / bare-`array` source could be a packed vec OR a hashed
            // map at runtime — its key is int-OR-string. Type the KEY as a cell
            // (emitted via __mir_array_key_cell_at, NaN-boxed) so a downstream
            // `$out[$k] = …` dispatches int/string by tag (set_cell) instead of
            // misreading a string-key pointer as an int. The VALUE stays raw
            // (its element storage is unchanged — only the key is re-tagged).
            $keyT = Type::cell();
        }
        // An erased-element array (vec[cell] / vec[unknown] / cell-valued assoc)
        // may carry DYNAMIC int-OR-string keys at runtime — e.g. one built via a
        // cell-keyed `$o[$k] = …` over a bare-array foreach, which stays
        // statically `mixed[]` / erased even though it holds string keys. Its
        // foreach key must ride a tagged cell (key_cell_at), else the vec int-key
        // path reads a string-key pointer as a raw int. For a genuinely packed
        // list the key is 0..n and key_cell_at returns box_int(i) — still correct.
        if ($at->isVec()
            && ($elem->kind === Type::KIND_CELL || $elem->kind === Type::KIND_UNKNOWN)) {
            $keyT = Type::cell();
        }
        $saved = $this->localTypes;
        $this->localTypes[$node->valueVar] = $elem;
        if ($node->keyVar !== null) { $this->localTypes[$node->keyVar] = $keyT; }
        $this->inferNode($node->body);
        $merged = $this->loopMerge($saved, $this->localTypes);
        if ($this->localTypesWidened($saved, $merged)) {
            $this->localTypes = $merged;
            $this->localTypes[$node->valueVar] = $elem;
            if ($node->keyVar !== null) { $this->localTypes[$node->keyVar] = $keyT; }
            $this->inferNode($node->body);
            $merged = $this->loopMerge($saved, $this->localTypes);
        }
        $this->localTypes = $merged;
        return Type::void();
    }

    private function inferSwitch(Switch_ $node): Type
    {
        $this->inferNode($node->subject);
        $saved = $this->localTypes;
        foreach ($node->arms as $arm) {
            if ($arm->value !== null) { $this->inferNode($arm->value); }
            foreach ($arm->body as $s) { $this->inferNode($s); }
        }
        $this->localTypes = $saved;
        return Type::void();
    }

    private function inferMatch(Match_ $node): Type
    {
        $this->inferNode($node->subject);
        $result = Type::unknown();
        $first = true;
        foreach ($node->arms as $arm) {
            $conds = $arm->conds;
            if ($conds !== null) {
                foreach ($conds as $c) { $this->inferNode($c); }
            }
            $bt = $this->inferNode($arm->body);
            if ($first) { $result = $bt; $first = false; }
            elseif ($result->kind === $bt->kind) { /* keep */ }
            elseif ($result->kind === Type::KIND_CELL || $bt->kind === Type::KIND_CELL
                || ($this->isValueKind($result) && $this->isValueKind($bt))) {
                // Heterogeneous concrete arms (`match(...) { => "s", => 1.5, => $n }`
                // = string|float|int): unify on a NaN-boxed cell so each arm
                // carries its runtime tag; emitMatch boxes every arm. An all-
                // numeric (int|float) match stays a numeric cell (arith-able).
                $result = $this->unifyToCell($result, $bt);
            }
            else { $result = Type::unknown(); }
        }
        $node->type = $result;
        return $result;
    }

    /**
     * `for` — the back-edge merge is {@see loopMerge} (NOT the plain if/else
     * mergeLocals): a local the body re-kinds must widen numerically or promote
     * to a cell, and the body is re-inferred under the widened map so the reads
     * INSIDE it (which see the previous iteration's value) are typed on the
     * merged slot, not on the pre-loop one. Same discipline as inferForeach.
     */
    private function inferFor(For_ $node): Type
    {
        if ($node->init !== null) { $this->inferNode($node->init); }
        if ($node->cond !== null) { $this->inferNode($node->cond); }
        $saved = $this->localTypes;
        $this->inferNode($node->body);
        if ($node->step !== null) { $this->inferNode($node->step); }
        $merged = $this->loopMerge($saved, $this->localTypes);
        if ($this->localTypesWidened($saved, $merged)) {
            $this->localTypes = $merged;
            $this->inferNode($node->body);
            if ($node->step !== null) { $this->inferNode($node->step); }
            $merged = $this->loopMerge($saved, $this->localTypes);
        }
        $this->localTypes = $merged;
        return Type::void();
    }

    private function inferDoWhile(DoWhile_ $node): Type
    {
        $saved = $this->localTypes;
        $this->inferNode($node->body);
        $this->inferNode($node->cond);
        $merged = $this->loopMerge($saved, $this->localTypes);
        if ($this->localTypesWidened($saved, $merged)) {
            $this->localTypes = $merged;
            $this->inferNode($node->body);
            $this->inferNode($node->cond);
            $merged = $this->loopMerge($saved, $this->localTypes);
        }
        $this->localTypes = $merged;
        return Type::void();
    }

    /**
     * Array literal — classify by element shape:
     *   - all positional, homogeneous element type → `vec[T]`.
     *   - all positional, mixed types              → `vec[unknown]`.
     *   - any keyed element                        → `assoc[K, V]`
     *     where K is the union of all keys' types (string or int)
     *     and V is the union of all value types.
     *   - empty literal                            → `vec[unknown]`.
     */
    private function inferSpread(Spread_ $node): Type
    {
        $node->type = $this->inferNode($node->operand);
        return $node->type;
    }

    private function inferArrayLit(ArrayLit $node): Type
    {
        $hasKey = false;
        $keyType = null;
        $valType = null;
        $first = true;
        $concreteKinds = [];
        $subArrElemKinds = [];   // distinct concrete element kinds of array-typed values
        $allStrConstKeys = true; // every element keyed by a string literal → record
        $recordFields = [];      // string key → value Type (insertion order)
        foreach ($node->elements as $el) {
            if ($el->key !== null) {
                $hasKey = true;
                $kt = $this->inferNode($el->key);
                // keyType tracks the first KEYED element, not the first element
                // overall — a leading keyless spread (`[...$a, "x"=>1]`) leaves
                // keyType null when the first real key arrives.
                $keyType = $keyType === null ? $kt : $keyType->unionWith($kt);
                if ($el->key->kind !== Node::KIND_STRING_CONST) { $allStrConstKeys = false; }
            } else {
                $allStrConstKeys = false;
            }
            // A spread element contributes its source's element type.
            $vt = $this->inferNode($el->value);
            if ($el->value->kind === Node::KIND_SPREAD) {
                $st = $el->value->type;
                $vt = $st->element !== null ? $st->element : Type::unknown();
            }
            if ($vt->kind !== Type::KIND_UNKNOWN) { $concreteKinds[$vt->kind] = true; }
            if ($vt->isArray() && $vt->element !== null
                && $vt->element->kind !== Type::KIND_UNKNOWN) {
                $subArrElemKinds[$vt->element->kind] = true;
            }
            if ($allStrConstKeys && $el->key !== null
                && $el->key->kind === Node::KIND_STRING_CONST) {
                $recordFields[$el->key->value] = $vt;
            }
            $valType = $first ? $vt : $this->unionTypes($valType, $vt);
            $first = false;
        }
        // Values are ARRAYS whose element kinds DIFFER (e.g. vec[int] and
        // assoc[string,string]) — the merge erased the element to unknown, so a
        // shallow box would leak a raw string sub-element when the array is later
        // read as mixed (var_dump / print_r). Type the outer element CELL so each
        // sub-array is deep-boxed individually at its store with its own type.
        $hetSubArrays = \count($subArrElemKinds) >= 2;
        if ($first) {
            $node->type = Type::vec(Type::unknown());
            return $node->type;
        }
        if ($hasKey) {
            // Heterogeneous values (mixed → unknown) become NaN-boxed
            // cells so each entry carries its own runtime type tag.
            $vt = $valType ?? Type::unknown();
            if ($vt->kind === Type::KIND_UNKNOWN) { $vt = Type::cell(); }
            elseif ($vt->kind === Type::KIND_NULL) { $vt = Type::cell(); }
            elseif ($hetSubArrays && $vt->isArray()) { $vt = Type::cell(); }
            // All keys are string literals and the element shape is regular
            // (no het-sub-array cell coercion) → a RECORD: same assoc repr
            // ({@see Type::record} recomputes the same element), plus the
            // per-field types so a consumer (json_encode) can specialize.
            if ($allStrConstKeys && !$hetSubArrays && \count($recordFields) > 0) {
                $node->type = Type::record($recordFields, $vt);
                return $node->type;
            }
            $node->type = Type::assoc($keyType ?? Type::unknown(), $vt);
            return $node->type;
        }
        // Heterogeneous vec literal (≥2 distinct concrete element kinds, e.g.
        // `[1, "x"]`) → element is a NaN-boxed cell so each entry keeps its own
        // runtime tag; foreach/is_*/truthiness then dispatch by tag instead of
        // reading the raw i64. Homogeneous-unknown stays unknown (no boxing).
        $vt = $valType ?? Type::unknown();
        if ($vt->kind === Type::KIND_UNKNOWN && count($concreteKinds) >= 2) {
            $vt = Type::cell();
        } elseif ($vt->kind === Type::KIND_NULL) {
            $vt = Type::cell();
        } elseif ($hetSubArrays && $vt->isArray()) {
            $vt = Type::cell();
        }
        $node->type = Type::vec($vt);
        return $node->type;
    }

    private function inferArrayAccess(ArrayAccess_ $node): Type
    {
        $at = $this->inferNode($node->array);
        $this->inferNode($node->index);
        if ($at->isArray() && $at->element !== null) {
            $node->type = $at->element;
        }
        // Indexing a `mixed`/cell base (a nested json_decode value) yields a
        // cell: the element is itself a NaN-boxed value, so echo dispatches by
        // tag and a deeper `$m[$a][$b]` re-unboxes the next level.
        if ($at->kind === Type::KIND_CELL) {
            $node->type = Type::cell();
        }
        // `$s[$i]` on a string yields a 1-char string.
        if ($at->kind === Type::KIND_STRING) {
            $node->type = Type::string_();
        }
        // `$obj[$k]` on an ArrayAccess object → offsetGet()'s return type.
        if ($at->kind === Type::KIND_OBJ && ($at->class ?? '') !== ''
            && $this->classImplementsT($at->class, 'ArrayAccess')) {
            $node->type = $this->iterMethodReturn($at->class, 'offsetGet', Type::unknown());
        }
        return $node->type;
    }

    private function inferStoreElement(StoreElement $node): Type
    {
        $at = $this->inferNode($node->array);
        $this->inferNode($node->index);
        $vt = $this->inferNode($node->value);
        // `$out[] = v` on a vec local refines its element type, so a
        // freshly-`[]`-built vec picks up its element shape (e.g. cell
        // when appending boxed JSON values).
        if ($node->array->kind === Node::KIND_LOAD_LOCAL) {
            $name = $node->array->name;
            if ($at->isAssoc()
                || isset($this->assocLocals[$name]) && $at->isVec()) {
                // assoc local: refine the value element across string-keyed stores.
                $cur = $at->isAssoc() ? ($at->element ?? null) : null;
                $elem = isset($this->nestedCellVecLocals[$name])
                    ? Type::vec(Type::cell())
                    : (isset($this->cellElemLocals[$name])
                        ? Type::cell() : $this->arrayElemMerge($cur, $vt));
                // A MIXED-key local (cellKeyLocals) rides a tagged cell key — a
                // string-keyed store must NOT re-narrow it to assoc[string], which
                // would drop the int keys (foreach then reads an int key as a
                // string ptr → SIGSEGV). `isAssoc()` is string-key-only, so a
                // cell-keyed assoc reports isVec() and its key would otherwise
                // collapse to string here.
                $key = isset($this->cellKeyLocals[$name]) ? Type::cell()
                    : ($at->isAssoc() ? ($at->key ?? Type::string_()) : Type::string_());
                $this->localTypes[$name] = Type::assoc($key, $elem);
            } elseif ($at->isVec() || $at->kind === Type::KIND_UNKNOWN) {
                $cur = $at->element ?? null;
                $elem = isset($this->nestedCellVecLocals[$name])
                    ? Type::vec(Type::cell())
                    : (isset($this->cellElemLocals[$name])
                        ? Type::cell() : $this->arrayElemMerge($cur, $vt));
                $this->localTypes[$name] = Type::vec($elem);
            }
        }
        $node->type = $vt;
        return $vt;
    }

    private function inferNewObj(NewObj $node): Type
    {
        foreach ($node->args as $a) {
            $this->inferNode($a);
        }
        return $node->type;
    }

    private function inferDynProp(DynProp_ $node): Type
    {
        $this->inferNode($node->object);
        $this->inferNode($node->name);
        $node->type = Type::cell();
        return $node->type;
    }

    private function inferStoreDynProp(StoreDynProp_ $node): Type
    {
        $this->inferNode($node->object);
        $this->inferNode($node->name);
        $vt = $this->inferNode($node->value);
        $node->type = $vt;
        return $vt;
    }

    private function inferPropertyAccess(PropertyAccess_ $node): Type
    {
        $objType = $this->inferNode($node->object);
        // `$byte->value` on a `#[TypeDef]` receiver IS `$c` — the value and its one
        // property are the same scalar. Type it as the BARE carrier: reading the
        // property is where the TypeDef ends and a plain int/float begins, which
        // is exactly what lets `$this->v + 1` do arithmetic without CheckTypeDefs
        // objecting. (EmitLlvm emits the receiver itself; there is no load.)
        $td = $objType->typeDefClass();
        if ($td !== null && isset($this->typeDefs[$td])
            && $node->property === $this->typeDefs[$td]->typeDefProp) {
            $node->type = $objType->stripTypeDef();
            return $node->type;
        }
        // A property PATH narrowed by an enclosing `$local->prop instanceof C`
        // wins (the branch-local map; see narrowFromCond) — so a `mixed` prop
        // holding an object resolves `$local->prop->field` to a typed offset.
        $pk = $this->propPathKey($node);
        if ($pk !== null && isset($this->localTypes[$pk])) {
            $node->type = $this->localTypes[$pk];
            return $node->type;
        }
        // A nullable-enum CELL (`Enum::tryFrom(...)->name`) carries the enum
        // class — resolve `->name`/`->value` to its real type (emitEnumProp
        // unboxes the singleton), BEFORE the generic tagged-cell fallthrough.
        if ($objType->kind === Type::KIND_CELL && $objType->class !== null
            && isset($this->enums[$objType->class])) {
            $ed = $this->enums[$objType->class];
            $node->type = ($node->property === 'value' && $this->edBacking($ed) === 'int')
                ? Type::int_() : Type::string_();
            return $node->type;
        }
        // `$cell->prop` (tagged object, e.g. json_decode result) → cell.
        if ($objType->kind === Type::KIND_CELL) {
            $node->type = Type::cell();
            return $node->type;
        }
        if ($objType->kind === Type::KIND_UNION) {
            $pt = $this->unionPropType($objType, $node->property);
            if ($pt !== null) { $node->type = $pt; }
            return $node->type;
        }
        // Erased receiver (`$x->p` where `$x` lost its class): recover the value
        // as a tagged CELL — emitPropertyAccess routes such a read to
        // emitRawPropByClassId, which reads `$p` at its REAL per-holder offset
        // and boxes it by the slot's declared type. Typing the RESULT cell keeps
        // the codegen (boxed) and the consumer (echo/var_dump/=== dispatch on the
        // tag) in agreement, instead of the raw-i64 misread of a string/float slot.
        if ($objType->kind === Type::KIND_UNKNOWN) {
            $node->type = Type::cell();
            return $node->type;
        }
        // Enum ->name (string) / ->value (backing type).
        if ($objType->kind === Type::KIND_OBJ
            && $objType->class !== null
            && isset($this->enums[$objType->class])) {
            $ed = $this->enums[$objType->class];
            if ($node->property === 'value' && $this->edBacking($ed) === 'int') {
                $node->type = Type::int_();
            } else {
                $node->type = Type::string_();
            }
            return $node->type;
        }
        if ($objType->kind === Type::KIND_OBJ
            && $objType->class !== null
            && isset($this->classes[$objType->class])) {
            $cd = $this->classes[$objType->class];
            if (isset($cd->propertyTypes[$node->property])) {
                $node->type = $cd->propertyTypes[$node->property];
            } elseif ($cd->usesBag()) {
                // Undeclared property on a bag class → tagged cell.
                $node->type = Type::cell();
            } elseif ($this->classDefinesMagic($objType->class, '__get')) {
                // Undeclared property on a class with __get → __get's resolved
                // return type (a concrete string/int rides raw; a mixed return
                // rides a tagged cell). Matching the type is what lets `echo
                // $obj->magic` render correctly instead of a raw ptr-as-int.
                $rt = $this->magicReturnType($objType->class, '__get');
                $node->type = $rt ?? Type::cell();
            } else {
                // Subclass-only property read through a base-typed
                // object (`$stmt->decl` where `$stmt: Stmt` but the
                // runtime object is a `ClassStmt`). Borrow the type
                // from a subclass that declares it; EmitLlvm resolves
                // the matching layout offset the same way.
                $sub = $this->subclassPropType($objType->class, $node->property);
                if ($sub !== null) { $node->type = $sub; }
            }
        }
        return $node->type;
    }

    private function inferStoreProperty(StoreProperty $node): Type
    {
        $ot = $this->inferNode($node->object);
        $vt = $this->inferNode($node->value);
        // An empty `[]` (or vec) default stored into an assoc property must
        // emit an assoc buffer — otherwise the ctor lays out a vec and the
        // first string-keyed store faults. Retype the literal to match.
        if ($node->value->kind === Node::KIND_ARRAY_LIT
            && ($vt->isVec() || $vt->kind === Type::KIND_UNKNOWN)
            && $ot->kind === Type::KIND_OBJ && $ot->class !== null
            && isset($this->classes[$ot->class])) {
            $pt = $this->classes[$ot->class]->propertyTypes[$node->property] ?? null;
            $ptCell = $pt !== null && $pt->element !== null
                && $pt->element->kind === Type::KIND_CELL;
            if ($pt !== null && ($pt->isAssoc() || $ptCell)) {
                $node->value->type = $pt;
                $vt = $pt;
            }
        }
        $node->type = $vt;
        return $vt;
    }
}
