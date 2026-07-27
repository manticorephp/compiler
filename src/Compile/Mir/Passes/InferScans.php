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
 * Whole-module pre-scans that build the per-local and per-property flag maps
 * inference reads (assoc locals, cell keys, float locals, …).
 *
 * A trait on the one {@see InferTypes} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait InferScans
{
    /**
     * Find every class property that is string-keyed (`$obj->prop[$k]=v`)
     * and retype it assoc[string, V] in its ClassDef. Runs once over the
     * whole module before per-function inference.
     */
    private function scanAssocProps(Module $module): bool
    {
        $changed = false;
        // "Class::prop" → value Type. Instance state, NOT a by-ref recursion
        // param: `array &$found` does not propagate correctly through the
        // nested `scanAssocPropsNode` recursion under self-host (the snapshot/
        // restore corrupts the buffer → set_str on garbage → abort). See
        // [[selfhost_array_ref_nesting]].
        $this->assocFound = [];
        foreach ($module->functions as $fn) {
            // Reuse $this->localTypes (well-typed array<string,Type>) for
            // param lookups so isStringKey / scanObjClass resolve element
            // types under self-host (a bare local map would not).
            $this->localTypes = [];
            foreach ($fn->params as $p) { $this->localTypes[$p->name] = $p->type; }
            $this->scanAssocPropsNode($fn->body);
        }
        foreach ($this->assocFound as $key => $valType) {
            $cut = \strpos($key, '::');
            if ($cut === false || $cut < 0) { continue; }
            $cls = \substr($key, 0, $cut);
            $prop = \substr($key, $cut + 2, \strlen($key) - $cut - 2);
            $cd = $this->classes[$cls] ?? null;
            if ($cd === null) { continue; }
            $cur = $cd->propertyTypes[$prop] ?? null;
            // Only promote an under-specified array prop (unknown / vec).
            if ($cur !== null
                && $cur->kind !== Type::KIND_UNKNOWN
                && !$cur->isVec()) {
                continue;
            }
            $v = $valType ?? ($cur !== null && $cur->isVec()
                ? ($cur->element ?? Type::unknown()) : Type::unknown());
            $cd->propertyTypes[$prop] = Type::assoc(Type::string_(), $v);
            $changed = true;
        }
        return $changed;
    }

    /**
     * Retype a bare-`array` property (lowered to `unknown`) to the vec /
     * assoc container kind of the constructor argument that initialises it.
     * A `new Foo($vec, ...)` where the promoted param `$vec` is stored into
     * property `vec` reveals the property is a vec even though its `array`
     * hint erased the element type. Without this the prop-store skips the
     * co-owner retain and the caller's local buffer is freed under it.
     */
    private function scanCtorPropContainers(Module $module): bool
    {
        $this->ctorPropChanged = false;
        foreach ($module->functions as $fn) {
            $this->scanCtorPropNode($fn->body, $module);
        }
        return $this->ctorPropChanged;
    }

    private function scanCtorPropNode(Node $n, Module $module): void
    {
        if ($n->kind === Node::KIND_NEW_OBJ) {
            $cd = $module->classes[$n->class] ?? null;
            // FunctionDef names join class + method with `__`; the ctor
            // method itself is `__construct`, so the mangled name carries
            // four underscores (`Class____construct`).
            $ctorName = $n->class . '____construct';
            if ($cd !== null) {
                // Collect ctor param names from the matching FunctionDef.
                // Searching $module->functions (a typed FunctionDef[] prop)
                // keeps each $cfn / $param class resolvable under self-host;
                // a `name → Param[]` map would erase it through the assoc.
                $pnames = [];
                if (\Compile\Stats::$on) {
                    \Compile\Stats::bump('scanCtorProp.new_sites', 1);
                    \Compile\Stats::bump('scanCtorProp.fns_scanned', \count($module->functions));
                }
                foreach ($module->functions as $cfn) {
                    if ($cfn->name === $ctorName) {
                        // The ctor's first param is the implicit `$this`;
                        // call args align with the rest, so drop it.
                        foreach ($cfn->params as $param) {
                            if ($param->name === 'this') { continue; }
                            $pnames[] = $param->name;
                        }
                    }
                }
                $np = \count($pnames);
                $i = 0;
                foreach ($n->args as $arg) {
                    $ak = $arg->type->kind;
                    $isCont = $ak === Type::KIND_ARRAY;
                    if ($i < $np && $isCont) {
                        // Index-match the param name via a counted walk.
                        $pname = '';
                        $j = 0;
                        foreach ($pnames as $cand) {
                            if ($j === $i) { $pname = $cand; }
                            $j = $j + 1;
                        }
                        if ($pname !== '' && isset($cd->propertyTypes[$pname])) {
                            $cur = $cd->propertyTypes[$pname];
                            // Adopt the arg's container type when the property
                            // is still unknown, OR upgrade an erased element
                            // (vec[unknown]) to the arg's concrete element so
                            // the more specific `new Foo($vecOfObj)` call wins
                            // over a `new Foo($vecOfUnknown)` regardless of
                            // which is visited first.
                            $take = $cur->kind === Type::KIND_UNKNOWN;
                            if (!$take && $cur->kind === $ak) {
                                $curElem = $cur->element;
                                $argElem = $arg->type->element;
                                $curUnk = $curElem === null
                                    || $curElem->kind === Type::KIND_UNKNOWN;
                                $argKnown = $argElem !== null
                                    && $argElem->kind !== Type::KIND_UNKNOWN;
                                $take = $curUnk && $argKnown;
                            }
                            if ($take) {
                                $cd->propertyTypes[$pname] = $arg->type;
                                $this->ctorPropChanged = true;
                            }
                        }
                    }
                    $i = $i + 1;
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->scanCtorPropNode($c, $module); }
    }

    /**
     * Infer `array` PROPERTY element types from getters that return
     * `$this->prop[$idx]` with a concrete declared return type. Sets the
     * ClassDef property to vec[returnType] so element reads / the +1
     * borrow-return retain type correctly.
     */
    private function scanPropElementReturns(Module $module): void
    {
        $this->propReturnsFound = [];     // "Class::prop" → element Type
        foreach ($module->functions as $fn) {
            $rt = $fn->returnType;
            if ($rt === null) { continue; }
            $rk = $rt->kind;
            if ($rk === Type::KIND_UNKNOWN || $rk === Type::KIND_VOID
                || $rk === Type::KIND_CELL) { continue; }
            // Owning class = the method's `$this` (param 0).
            $cls = '';
            if (\count($fn->params) > 0 && $fn->params[0]->name === 'this'
                && $fn->params[0]->type->kind === Type::KIND_OBJ
                && $fn->params[0]->type->class !== null) {
                $cls = $fn->params[0]->type->class;
            }
            if ($cls === '') { continue; }
            $this->findPropReturns($fn->body, $cls, $rt);
        }
        foreach ($this->propReturnsFound as $key => $elem) {
            $cut = \strpos($key, '::');
            if ($cut === false || $cut < 0) { continue; }
            $cls = \substr($key, 0, $cut);
            $prop = \substr($key, $cut + 2, \strlen($key) - $cut - 2);
            $cd = $this->classes[$cls] ?? null;
            if ($cd === null) { continue; }
            $cur = $cd->propertyTypes[$prop] ?? null;
            if ($cur !== null && $cur->kind !== Type::KIND_UNKNOWN
                && !($cur->isVec()
                    && ($cur->element === null || $cur->element->kind === Type::KIND_UNKNOWN))) {
                continue;
            }
            $cd->propertyTypes[$prop] = Type::vec($elem);
        }
    }

    private function scanCellElemProps(Module $module): void
    {
        $this->cellElemPropsFound = [];
        foreach ($module->functions as $fn) {
            $cls = '';
            if (\count($fn->params) > 0 && $fn->params[0]->name === 'this'
                && $fn->params[0]->type->kind === Type::KIND_OBJ
                && $fn->params[0]->type->class !== null) {
                $cls = $fn->params[0]->type->class;
            }
            if ($cls === '') { continue; }
            $this->findCellElemStores($fn->body, $cls);
        }
        foreach ($this->cellElemPropsFound as $key => $seen) {
            $cut = \strpos($key, '::');
            if ($cut === false || $cut < 0) { continue; }
            $cls = \substr($key, 0, $cut);
            $prop = \substr($key, $cut + 2, \strlen($key) - $cut - 2);
            $cd = $this->classes[$cls] ?? null;
            if ($cd === null) { continue; }
            $cur = $cd->propertyTypes[$prop] ?? null;
            if ($cur !== null && !$cur->isArray()) {
                // A whole cell-element ARRAY was stored: that type is exact —
                // keep its key shape rather than flattening to a vec.
                if ($seen instanceof Type && $cur->kind === Type::KIND_UNKNOWN
                    && ($cd->propertyArrayHinted[$prop] ?? false)) {
                    $cd->propertyTypes[$prop] = $seen;
                    continue;
                }
                // A bare `array` hint erases to KIND_UNKNOWN, so the slot is not
                // yet an array Type at all — but it IS an array at runtime, and
                // the store above proved its elements are cells. vec[cell] is the
                // honest shape: the unified runtime picks packed vs hashed on the
                // flags word, and inferForeach already tags a cell-element vec's
                // KEY as a cell, so a hashed one still reads its string keys.
                if ($cur->kind !== Type::KIND_UNKNOWN
                    || !($cd->propertyArrayHinted[$prop] ?? false)) { continue; }
                $cd->propertyTypes[$prop] = Type::vec(Type::cell());
                continue;
            }
            if ($cur === null) { continue; }
            if (($cur->element->kind ?? '') === Type::KIND_CELL) { continue; }
            // Preserve the key shape (assoc vs vec); only the element → cell.
            $cd->propertyTypes[$prop] = ($cur->key !== null)
                ? Type::assoc($cur->key, Type::cell())
                : Type::vec(Type::cell());
        }
    }

    /**
     * Type a still-erased array property from a WHOLE-array assignment
     * (`$this->prop = [1, "x"]` / `$this->prop = $typedArray`). A heterogeneous
     * literal types vec[cell]; without lifting that onto the DECLARED property
     * type, a later read (`$this->prop[$i]` / foreach) sees a bare `array` →
     * unknown and returns the raw i64 instead of dispatching on the cell tag (a
     * LOCAL `$a = [1,"x"]` already works — this is the property analogue). A
     * non-array assignment, or two assignments of different array shapes, leaves
     * the property erased.
     */
    private function scanPropTypeFromArrayAssign(Module $module): bool
    {
        /** @var array<string, Type> */
        $observed = [];   // "Class::prop" → array Type
        $unusable = [];   // "Class::prop" → true
        foreach ($module->functions as $fn) {
            $cls = '';
            if (\count($fn->params) > 0 && $fn->params[0]->name === 'this'
                && $fn->params[0]->type->kind === Type::KIND_OBJ
                && $fn->params[0]->type->class !== null) {
                $cls = $fn->params[0]->type->class;
            }
            if ($cls === '') { continue; }
            $this->collectPropArrayAssigns($fn->body, $cls, $observed, $unusable);
        }
        $changed = false;
        foreach ($observed as $key => $at) {
            if (isset($unusable[$key]) || $at === null) { continue; }
            $cut = \strpos($key, '::');
            if ($cut === false || $cut < 0) { continue; }
            $cls = \substr($key, 0, $cut);
            $prop = \substr($key, $cut + 2, \strlen($key) - $cut - 2);
            $cd = $this->classes[$cls] ?? null;
            if ($cd === null) { continue; }
            $cur = $cd->propertyTypes[$prop] ?? null;
            $isErased = $cur === null
                || $cur->kind === Type::KIND_UNKNOWN
                || ($cur->isArray()
                    && ($cur->element === null || $cur->element->kind === Type::KIND_UNKNOWN));
            if (!$isErased) { continue; }
            $cd->propertyTypes[$prop] = $at;
            $changed = true;
        }
        return $changed;
    }

    /**
     * Refine a still-erased array property to vec[T] / assoc[K,T] when every
     * `$this->prop[...] = v` stores the SAME concrete scalar/object element.
     * Runs post-inference (values settled), so an element sourced from a
     * now-typed param/foreach is seen concrete. A conflicting shape, or any
     * unknown/cell store, leaves it erased for the other scanners to handle.
     */
    private function scanPropElemFromStores(Module $module): bool
    {
        /** @var array<string, Type> */
        $observed = [];   // "Class::prop" → element Type (cell when mixed-but-boxable)
        $unusable = [];   // "Class::prop" → true
        foreach ($module->functions as $fn) {
            $cls = '';
            if (\count($fn->params) > 0 && $fn->params[0]->name === 'this'
                && $fn->params[0]->type->kind === Type::KIND_OBJ
                && $fn->params[0]->type->class !== null) {
                $cls = $fn->params[0]->type->class;
            }
            // A FREE function / top-level main has no `$this`, but it can still
            // fill another object's property (`$b->xs[] = "a"`) — the collector
            // resolves those from the receiver's type, so scan it too. `$cls`
            // stays '' and only gates the `$this->` arm.
            $this->collectPropElemStores($fn->body, $cls, $observed, $unusable);
        }
        $changed = false;
        foreach ($observed as $key => $elem) {
            if (isset($unusable[$key]) || $elem === null) { continue; }
            $ek = $elem->kind;
            $ok = $ek === Type::KIND_STRING || $ek === Type::KIND_INT
                || $ek === Type::KIND_FLOAT || $ek === Type::KIND_BOOL
                || $ek === Type::KIND_CELL
                || ($ek === Type::KIND_OBJ && $elem->class !== null);
            if (!$ok) { continue; }
            $cut = \strpos($key, '::');
            if ($cut === false || $cut < 0) { continue; }
            $cls = \substr($key, 0, $cut);
            $prop = \substr($key, $cut + 2, \strlen($key) - $cut - 2);
            $cd = $this->classes[$cls] ?? null;
            if ($cd === null) { continue; }
            $cur = $cd->propertyTypes[$prop] ?? null;
            // Only fill an ERASED slot — never override a concrete or cell element.
            $isErased = $cur === null
                || $cur->kind === Type::KIND_UNKNOWN
                || ($cur->isArray()
                    && ($cur->element === null || $cur->element->kind === Type::KIND_UNKNOWN));
            if (!$isErased) { continue; }
            $keyT = ($cur !== null && $cur->isArray()) ? $cur->key : null;
            $cd->propertyTypes[$prop] = $keyT !== null ? Type::assoc($keyT, $elem) : Type::vec($elem);
            $changed = true;
        }
        return $changed;
    }

    /**
     * The STATIC-property analogue of {@see scanPropElemFromStores}. A static's
     * element stores (`B::$xs[] = "a"`) mostly live OUTSIDE the declaring class
     * — top-level or a free function — so the lowering-time AST scan
     * ({@see LowerTypes::inferPropElemFromStores}, which only walks `$this->p`
     * inside `$decl->methods`, and skips statics outright) can never see them.
     * Refine here instead, keyed by the cell symbol every `StaticProp_` carries.
     *
     * Without this a `public static array $xs = []` filled from outside keeps an
     * erased element and the read guesses a repr: `implode` printed string
     * elements as `2.1e-314`, `var_dump` as the raw pointer int.
     */
    private function scanStaticPropElemFromStores(Module $module): bool
    {
        /** @var array<string, Type> */
        $observed = [];   // global cell symbol → element Type (cell when mixed)
        $unusable = [];   // global cell symbol → true
        foreach ($module->functions as $fn) {
            $this->collectStaticPropElemStores($fn->body, $observed, $unusable);
        }
        $changed = false;
        foreach ($observed as $g => $elem) {
            if (isset($unusable[$g])) { continue; }
            $ek = $elem->kind;
            $ok = $ek === Type::KIND_STRING || $ek === Type::KIND_INT
                || $ek === Type::KIND_FLOAT || $ek === Type::KIND_BOOL
                || $ek === Type::KIND_CELL
                || ($ek === Type::KIND_OBJ && $elem->class !== null);
            if (!$ok) { continue; }
            foreach ($module->functions as $fn) {
                if ($this->retypeStaticPropNodes($fn->body, $g, $elem)) { $changed = true; }
            }
        }
        return $changed;
    }

    /**
     * Retype every `StaticProp_` reading cell `$g` to vec/assoc of `$elem` —
     * but only where the slot is still ERASED. A default that already fixed a
     * concrete shape (`public static array $xs = [1,2]` → vec[int]) is left
     * alone, exactly as the instance-property scan only fills an erased slot.
     */
    private function retypeStaticPropNodes(Node $n, string $g, Type $elem): bool
    {
        $changed = false;
        if ($n->kind === Node::KIND_STATIC_PROP && $n->global === $g) {
            $cur = $n->type;
            if ($this->isErasedArrayType($cur)) {
                $keyT = $cur->isArray() ? $cur->key : null;
                $n->type = $keyT !== null ? Type::assoc($keyT, $elem) : Type::vec($elem);
                $changed = true;
            }
        }
        foreach (Walk::children($n) as $c) {
            if ($this->retypeStaticPropNodes($c, $g, $elem)) { $changed = true; }
        }
        return $changed;
    }

    /**
     * @param array<string, Type|null> $found
     */
    private function scanAssocPropsNode(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_PROPERTY_ACCESS
                && $se->index->kind !== Node::KIND_NULL_CONST
                && $this->isStringKey($se->index)) {
                $cls = $this->scanObjClass($se->array->object);
                if ($cls !== '') {
                    $key = $cls . '::' . $se->array->property;
                    if (!isset($this->assocFound[$key])) {
                        $this->assocFound[$key] = $se->value->type;
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) {
            $this->scanAssocPropsNode($c);
        }
    }

    /** Class of an object expression at scan time (`$this` → its obj type). */
    private function scanObjClass(Node $obj): string
    {
        if ($obj->kind === Node::KIND_LOAD_LOCAL) {
            $name = $obj->name;
            if (isset($this->localTypes[$name])) {
                $t = $this->localTypes[$name];
                if ($t->kind === Type::KIND_OBJ && $t->class !== null) {
                    return $t->class;
                }
            }
        }
        return '';
    }

    /**
     * Promote an array/unknown param to `mixed` (cell) when its value is stored
     * into a cell (`mixed`) property — `$this->field = $param`. The store keeps
     * the raw array as-is, so a later cell consumer (var_dump / foreach over the
     * field) mis-reads each raw entry as a tagged cell (a raw int has tag 0 →
     * SIGSEGV). Promoting the param to cell makes the CALL SITE box the
     * argument (its element type is known there, lost once inside the callee) —
     * exactly the path a declared `mixed $param` already takes. Type-directed
     * coercion at the argument boundary; the no-workaround fix for the
     * `array $x` → `mixed` field case.
     */
    private function scanParamCellSinks(FunctionDef $fn): void
    {
        $thisClass = '';
        foreach ($fn->params as $p) {
            if ($p->name === 'this' && $p->type->kind === Type::KIND_OBJ
                && $p->type->class !== null) {
                $thisClass = $p->type->class;
            }
        }
        if ($thisClass === '' || !isset($this->classes[$thisClass])) { return; }
        $cand = [];
        foreach ($fn->params as $idx => $p) {
            if ($p->variadic) { continue; }
            $k = $p->type->kind;
            if ($k === Type::KIND_ARRAY || $k === Type::KIND_UNKNOWN) {
                $cand[$p->name] = $idx;
            }
        }
        if (\count($cand) === 0) { return; }
        $this->cellPropNames = [];
        foreach ($this->classes[$thisClass]->propertyTypes as $pn => $pt) {
            if ($pt->kind === Type::KIND_CELL) { $this->cellPropNames[$pn] = true; }
        }
        if (\count($this->cellPropNames) === 0) { return; }
        $this->cellSinkParams = [];
        $this->collectCellSinkParams($fn->body, $cand);
        foreach ($this->cellSinkParams as $pname => $unused) {
            $idx = $cand[$pname];
            // Fetch the Param to a local before mutating its (mutable) `type` —
            // an indirect write through the readonly `$params` array is rejected
            // (mirrors scanParamElements).
            $param = $fn->params[$idx];
            $param->type = Type::cell();
            $this->localTypes[$pname] = Type::cell();
        }
    }

    private function scanParamElements(FunctionDef $fn): void
    {
        // Candidate params: array-ish with no known element type.
        $cand = [];                       // param name → index
        foreach ($fn->params as $idx => $p) {
            if ($p->variadic) { continue; }
            // arrayHinted, not just an erased type: isUnknownArrayElem is also
            // true for KIND_UNKNOWN, which is what an UNTYPED (`mixed`) param
            // erases to — and narrowing one of those to vec[T] is a lie. It is
            // how symfony's `StreamOutput::__construct($stream)` became
            // `array`, so is_resource($stream) folded false and every console
            // app threw "needs a stream as its first argument".
            if (!$p->arrayHinted) { continue; }
            if ($this->isUnknownArrayElem($p->type)) { $cand[$p->name] = $idx; }
        }
        if (\count($cand) === 0) { return; }
        // Pass 1: locals bound to `$param[$i]` (the element carriers).
        $this->elemLocalOf = [];
        $this->collectElemLocals($fn->body, $cand);
        // Pass 2: params whose elements are used as strings.
        $this->strParamsFound = [];
        $this->detectStringElemUse($fn->body, $cand);
        foreach ($this->strParamsFound as $pname => $unused) {
            $idx = $cand[$pname];
            // Fetch the Param object to a local before mutating its (mutable)
            // `type` — `$fn->params[$idx]->type = …` is an indirect write
            // through the readonly `$params` array property and is rejected.
            $param = $fn->params[$idx];
            $vt = Type::vec(Type::string_());
            $param->type = $vt;
            $this->localTypes[$pname] = $vt;
        }
    }

    /**
     * Refine each non-extern function's bare-`array` param to vec[T] when every
     * call site passes an array arg whose element is the SAME scalar T. Returns
     * true when any param changed (callers re-run inference). Conservative:
     * only scalar elements (string/int/float/bool — no rc), all-agree, and a
     * single observed kind; a disagreement or any non-array/unknown arg drops
     * the candidate.
     */
    private function scanCallSiteArrayElems(Module $module): bool
    {
        $cand = [];                      // "fn#idx" → true
        $refined = [];                   // "fn#idx" → true (heuristic vec[scalar])
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $idx = 0;
            foreach ($fn->params as $p) {
                // A prelude BY-REF array param must not be refined here — the
                // same premise violation as scanCallSiteRefParams (see the note
                // there): a prelude fn is emitted linkonce_odr into EVERY module
                // and coalesced to one copy, so this module's call sites are not
                // all of them. Refining made `sort(array &$arr)` compile as
                // vec[string] in one object (rc: __mir_array_alloc + release)
                // and vec[int] in another (arena: __mir_arena_enter) — one
                // symbol, 817 differing lines, two incompatible memory models;
                // whichever the linker kept was wrong for the other caller.
                // Leaving it erased is also what lets Monomorphize see the
                // dimension and clone a `$mono$` copy per concrete caller — the
                // specialization is still wanted, it just needs its own SYMBOL.
                //
                // BY-VALUE params are deliberately still refined: the same ODR
                // hazard exists in principle, but the refinement is load-bearing
                // for correctness today (`array_sum($g[5])` reads raw ints; an
                // erased param would read them as cells → garbage), and fixing
                // that properly means encoding the param types into the prelude
                // SYMBOL, which is a separate change.
                if ($fn->isPrelude && $p->byRef) { $idx = $idx + 1; continue; }
                // Same rule as scanParamElemUse: an UNTYPED param erases to
                // KIND_UNKNOWN, which isUnknownArrayElem also answers true for.
                // Only a param the source actually hinted `array` may be
                // narrowed from its call sites; `mixed` stays mixed.
                if (!$p->variadic && $p->arrayHinted && $this->isUnknownArrayElem($p->type)) {
                    $cand[$fn->name . '#' . (string)$idx] = true;
                } elseif (!$p->variadic && $p->type->isArray()) {
                    // A param the per-fn heuristic already refined (e.g. vec[string]
                    // from an ambiguous `$x=$p[$i]; $x[j]` subscript) stays
                    // re-examinable: if call sites CONCRETELY pass a nested array,
                    // that ground truth overrides the guess (see nbody `split`).
                    $cand[$fn->name . '#' . (string)$idx] = true;
                    $refined[$fn->name . '#' . (string)$idx] = true;
                }
                $idx = $idx + 1;
            }
        }
        if (\count($cand) === 0) { return false; }
        /** @var array<string, Type> */
        $observed = [];                  // "fn#idx" → value/element Type
        $conflict = [];                  // "fn#idx" → true
        /** @var array<string, Type> */
        $assocKey = [];                  // "fn#idx" → key Type (assoc-shaped arg)
        $shape = [];                     // "fn#idx" → 'v' (vec) | 'a' (assoc)
        $sawCell = [];                   // "fn#idx" → true (a vec[cell] arg seen)
        foreach ($module->functions as $fn) {
            $this->collectCallArgElems($fn->body, $cand, $observed, $conflict, $assocKey, $shape, $sawCell);
        }
        $changed = false;
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $idx = 0;
            foreach ($fn->params as $p) {
                $key = $fn->name . '#' . (string)$idx;
                $idx = $idx + 1;
                if (!isset($cand[$key])) { continue; }
                // A heterogeneous vec[cell] site cannot be specialized per-repr,
                // so cell is the param's floor: resolve to vec[cell] even when a
                // differing concrete site marked $conflict (which unset $observed
                // and would otherwise leave a wrong body guess in place). The
                // cell site then reads cells directly and Monomorphize clones
                // each CONCRETE site off this erased-cell param (m8). Idempotent:
                // skip when the param already carries a cell element.
                if (isset($sawCell[$key])) {
                    $cur = $p->type;
                    $already = $cur->isArray() && $cur->element !== null
                        && $cur->element->kind === Type::KIND_CELL;
                    if (!$already) {
                        $p->type = isset($assocKey[$key])
                            ? Type::assoc($assocKey[$key], Type::cell())
                            : Type::vec(Type::cell());
                        $changed = true;
                    }
                    continue;
                }
                if (isset($conflict[$key]) || !isset($observed[$key])) { continue; }
                // An already-refined param is only overridden by a NESTED-array
                // or a CELL observation — never fight a legitimate vec[string]
                // whose call sites pass scalar-element arrays. A call site that
                // concretely passes a heterogeneous vec[cell] is ground truth:
                // the runtime slots hold NaN-boxed cells, so a body-usage guess
                // of vec[string] (raw ptr slots) reads the boxed cell as a raw
                // string ptr → SIGSEGV (`f(array $a){ strlen($a[0]); }` fed a
                // `["x",1,2.5]`). Retype to vec[cell] so the read unboxes.
                if (isset($refined[$key]) && !$observed[$key]->isArray()
                    && $observed[$key]->kind !== Type::KIND_CELL) { continue; }
                $param = $fn->params[$idx - 1];
                $param->type = isset($assocKey[$key])
                    ? Type::assoc($assocKey[$key], $observed[$key])
                    : Type::vec($observed[$key]);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Refine an UNTYPED by-ref param (`&$p` with no type hint → cell) to the
     * concrete type every call site passes. Only pointer-carrying types
     * (string / array / object) are refined — those misread as a NaN-boxed
     * cell; int/float/bool by-ref already work through the raw i64 slot. A
     * conflicting or unobserved site leaves the param a cell.
     */
    private function scanCallSiteRefParams(Module $module): bool
    {
        $cand = [];                      // "fn#idx" → true
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            // A prelude function is emitted linkonce_odr into EVERY module that
            // uses it, and the linker keeps one copy — so this module's call
            // sites are not all of them, and the body it compiles may be the one
            // another object ends up running. Narrowing on the sites visible here
            // makes two objects define the same symbol with different bodies
            // (e.g. sort() over vec[int] takes the arena path while sort() over
            // vec[string] takes __mir_array_alloc + rc release), and whichever
            // the linker keeps is wrong for the other caller. The scan's whole
            // premise — that an unobserved site leaves the param a cell — only
            // holds for a symbol this module owns outright.
            if ($fn->isPrelude) { continue; }
            $idx = 0;
            foreach ($fn->params as $p) {
                if ($p->byRef && !$p->variadic
                    && ($p->type->kind === Type::KIND_CELL
                        || $p->type->kind === Type::KIND_UNKNOWN)) {
                    $cand[$fn->name . '#' . (string)$idx] = true;
                }
                $idx = $idx + 1;
            }
        }
        if (\count($cand) === 0) { return false; }
        /** @var array<string, Type> */
        $observed = [];                  // "fn#idx" → Type
        $conflict = [];                  // "fn#idx" → true
        foreach ($module->functions as $fn) {
            $this->collectRefArgTypes($fn->body, $cand, $observed, $conflict);
        }
        $changed = false;
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $idx = 0;
            foreach ($fn->params as $p) {
                $key = $fn->name . '#' . (string)$idx;
                $idx = $idx + 1;
                if (!isset($cand[$key])) { continue; }
                if (isset($conflict[$key]) || !isset($observed[$key])) { continue; }
                $t = $observed[$key];
                if ($t->kind === Type::KIND_STRING || $t->isArray()
                    || $t->kind === Type::KIND_OBJ) {
                    // Read the Param handle into a local BEFORE mutating it: a
                    // nested write `$fn->params[$i]->type = …` is rejected by Zend
                    // as an indirect modification of the readonly `$params` array
                    // (a plain read + index is fine). The self-built compiler does
                    // not enforce readonly, so this fired only under the Zend cold
                    // seed — and only once a stdlib fn first triggered a refinement
                    // (a recursive `array &$p` observed concrete). Param is shared
                    // by handle, so this still updates the entry in place.
                    $param = $fn->params[$idx - 1];
                    $param->type = $t;
                    $changed = true;
                }
            }
        }
        return $changed;
    }

    /**
     * Unify each `global $g` variable's type across ALL scopes. A global-backed
     * StaticLocalDecl_ is hard-lowered `int` ({@see LowerFromAst::lowerGlobal}),
     * so a function that only reads the global — `global $g; return $g;` with no
     * local store — keeps the int type and renders a string global as a raw
     * pointer int (and skips rc). Join the value type of every store into the
     * global (across every function + `__main`) and seed the map; the decl reads
     * it so pure-read scopes carry the real type. Returns true if any global
     * gained a non-int type (→ re-infer).
     */
    /**
     * Type each uninitialised `static $x;` from the stores it receives.
     *
     * `static $x;` lowers hard to `int` (there is no initialiser to type it
     * from), and unlike an ordinary local its value SURVIVES the call — so the
     * read at the top of the next call is not flow-reachable from the store
     * that filled it and keeps the int. symfony's ConsoleOutput is exactly
     * this: `static $stdout; if ($stdout) return $stdout; return $stdout =
     * \STDOUT;` handed the second caller the resource POINTER as an int, and
     * StreamOutput's is_resource() check threw.
     *
     * The join is per FUNCTION, keyed by the decl's cell (already unique per
     * function+var). Same shape as {@see scanGlobalTypes}; a global-backed decl
     * is skipped, that scan owns it.
     */
    private function scanStaticLocalTypes(Module $module): bool
    {
        $changed = false;
        foreach ($module->functions as $fn) {
            /** @var array<string,bool> $active */
            $active = [];
            /** @var array<string,string> $cells */
            $cells = [];
            $this->collectPlainStaticLocals($fn->body, $active, $cells);
            if (\count($active) === 0) { continue; }
            /** @var array<string,Type> $observed */
            $observed = [];
            /** @var array<string,Type> $elems */
            $elems = [];
            $elemBad = [];
            $strKey = [];
            $this->collectGlobalStoreTypes($fn->body, $active, $observed, $elems, $elemBad, $strKey);
            foreach ($observed as $name => $t) {
                if ($t->kind === Type::KIND_UNKNOWN) { continue; }
                $cell = $cells[$name] ?? '';
                if ($cell === '') { continue; }
                $prev = $this->staticLocalTypes[$cell] ?? null;
                if ($prev !== null && $prev->kind === $t->kind) { continue; }
                $this->staticLocalTypes[$cell] = $t;
                $changed = true;
            }
        }
        return $changed;
    }

    /** Uninitialised, non-global-backed static locals: name → true, name → cell.
     *  @param array<string,bool>   $active
     *  @param array<string,string> $cells */
    private function collectPlainStaticLocals(Node $n, array &$active, array &$cells): void
    {
        if ($n->kind === Node::KIND_STATIC_LOCAL_DECL) {
            $d = $n;
            if ($d->init === null && !\str_starts_with($d->cell, '@g_')) {
                $active[$d->name] = true;
                $cells[$d->name] = $d->cell;
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectPlainStaticLocals($c, $active, $cells); }
    }

    private function scanGlobalTypes(Module $module): bool
    {
        if (\count($module->globalVarNames) === 0) { return false; }
        foreach ($module->globalVarNames as $gname) { $this->mainGlobalNames[$gname] = true; }
        /** @var array<string, Type> */
        $observed = [];                  // var name → joined Type
        /** @var array<string, Type> */
        $elems = [];                     // var name → stored element Type
        $elemBad = [];                   // var name → element unusable
        $strKey = [];                    // var name → seen a string-keyed store
        foreach ($module->functions as $fn) {
            $active = [];                // names that are global-backed HERE
            $this->collectGlobalBacked($fn->body, $active);
            // `__main` binds every `global $x` name to the same `@g_x` cell
            // without a StaticLocalDecl_ ({@see EmitLlvmModule::emitFunction}),
            // so the decl walk finds nothing there and the top-level `$g = []`
            // that establishes the global's shape was invisible to this scan.
            if ($fn->name === '__main') {
                foreach ($module->globalVarNames as $gname) { $active[$gname] = true; }
            }
            if (\count($active) === 0) { continue; }
            $this->collectGlobalStoreTypes($fn->body, $active, $observed, $elems, $elemBad, $strKey);
        }
        $changed = false;
        // A global reached ONLY by appends (`$g = []` types the empty literal
        // unknown, so the join above records nothing) still has its element
        // evidence in $elems — walk both maps, not just $observed.
        $names = [];
        foreach ($observed as $name => $t) { $names[$name] = true; }
        foreach ($elems as $name => $t) { $names[$name] = true; }
        foreach ($names as $name => $_) {
            $t = $observed[$name] ?? null;
            // An array global whose element is still erased takes the element
            // joined from its appends — the `$g[] = v` shape carries the only
            // evidence of what the global holds. An append also PROVES the
            // global is an array, so a still-shapeless global qualifies too.
            $erasedArray = $t === null || $this->isErasedArrayType($t);
            if ($erasedArray && isset($elems[$name]) && !isset($elemBad[$name])) {
                $keyT = isset($strKey[$name])
                    ? Type::string_()
                    : (($t !== null && $t->isArray()) ? $t->key : null);
                $this->globalVarTypes[$name] = $keyT !== null
                    ? Type::assoc($keyT, $elems[$name])
                    : Type::vec($elems[$name]);
                $changed = true;
                continue;
            }
            if ($t === null) { continue; }
            $k = $t->kind;
            if ($k === Type::KIND_UNKNOWN || $k === Type::KIND_INT) { continue; }
            $prev = $this->globalVarTypes[$name] ?? null;
            if ($prev === null || $prev->kind !== $k) {
                $this->globalVarTypes[$name] = $t;
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * A local array whose element erased to UNKNOWN while a store writes an
     * already-CELL value into it (`$out = []; … $out[$k] = $v;` with `$v` an
     * erased foreach value). {@see coarseValueClass} can't see this: it is a
     * PRE-inference scan and `$v` is a variable read, so it classifies nothing
     * and the ≥2-distinct-kinds rule never fires on the single store. The local
     * then keeps vec[unknown], so every READ of it (`foreach ($out as $w)`) is a
     * raw i64 and `$w == $v` compares a boxed int against a boxed string BY BITS
     * — which is why array_unique stopped de-duplicating `1` against `"1"`.
     *
     * Runs after a first inference pass (when `$v` has a type) and records the
     * local so the next inferFunction seeds it vec[cell]. True when it found
     * something new — the driver re-infers.
     */
    private function scanLocalElemFromStores(Module $module): bool
    {
        $changed = false;
        foreach ($module->functions as $fn) {
            // A PRELUDE body is emitted linkonce_odr and SHARED with stdlib.o, so
            // it must never be specialized from module-local information: the
            // value type of `$out[] = $cb(...)` in array_map depends on THIS
            // module's callback, so one module would emit a vec[cell] body and
            // another a vec[string] body under the same symbol — the linker keeps
            // one and the other module's rc discipline is wrong (libmalloc abort).
            // A prelude local that needs a cell element says so at the source,
            // with a `/** @var mixed[] */` on its binding. See the prelude-linkage
            // note in docs/.
            if ($fn->isPrelude) { continue; }
            // A PARAM is the CALLER's array — its elements keep whatever
            // representation the caller built, and storing a cell into it can't
            // retroactively make them cells. Forcing vec[cell] on one made the
            // rc walkers drop raw string elements as cells (libmalloc abort in
            // stat_functions). Only a locally-CONSTRUCTED `[]` is ours to retype.
            $skip = [];
            foreach ($fn->params as $prm) { $skip[$prm->name] = true; }
            $lits = [];
            $this->scanArrayLitLocals($fn->body, $lits);
            $found = [];
            $this->scanLocalElemNode($fn->body, $found);
            // The same erasure with two CONCRETE stores instead of a cell one:
            // `$out[] = $v` in a foreach over a vec[int] and `$out[] = $w` in a
            // foreach over a vec[string] make a genuinely mixed array, but the
            // pre-inference coarseValueClass scan sees two variable reads and
            // classifies neither, so `$out` took the first store's element and
            // the second landed RAW (a string pointer read back as int).
            $classes = [];
            $this->scanLocalElemClasses($fn->body, $classes);
            foreach ($classes as $name => $seen) {
                if (\count($seen) >= 2) { $found[$name] = true; }
            }
            foreach ($found as $name => $unused) {
                if (isset($skip[$name]) || !isset($lits[$name])) { continue; }
                if (isset($this->forcedCellElemLocals[$fn->name][$name])) { continue; }
                $this->forcedCellElemLocals[$fn->name][$name] = true;
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * Post-inference value CLASSES stored into each local array, keyed by local
     * name. Same classing as the pre-inference {@see coarseValueClass} (int and
     * float collapse to `num`, they share the numeric-cell discipline) — but
     * read off the INFERRED type, so a variable read counts too. An erased or
     * cell value contributes nothing: it is either already right or a different
     * root cause.
     *
     * @param array<string, array<string,bool>> $out
     */
    private function scanLocalElemClasses(Node $n, array &$out): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_LOAD_LOCAL) {
                $cls = $this->typeValueClass($se->value->type);
                if ($cls !== '') { $out[$se->array->name][$cls] = true; }
            }
        }
        foreach (Walk::children($n) as $c) { $this->scanLocalElemClasses($c, $out); }
    }

    /** The {@see coarseValueClass} class of an INFERRED type, or '' when the type
     *  carries no repr commitment (unknown / cell / void). */
    private function typeValueClass(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_INT || $k === Type::KIND_FLOAT) { return 'num'; }
        if ($k === Type::KIND_STRING) { return 'string'; }
        if ($k === Type::KIND_BOOL) { return 'bool'; }
        if ($k === Type::KIND_NULL) { return 'null'; }
        if ($t->isArray()) { return 'array'; }
        if ($k === Type::KIND_OBJ) { return 'obj'; }
        return '';
    }

    /** Locals assigned an array LITERAL in this body — the ones whose element
     *  representation this function itself owns. @param array<string,bool> $out */
    private function scanArrayLitLocals(Node $n, array &$out): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL && $n->value->kind === Node::KIND_ARRAY_LIT) {
            $out[$n->name] = true;
        }
        foreach (Walk::children($n) as $c) { $this->scanArrayLitLocals($c, $out); }
    }

    /**
     * A LOCAL array handed BY-REF to a callee that APPENDS a FOREIGN element —
     * one the caller's buffer has no representation for:
     *
     *     function push_str(array &$arr): void { $arr[] = 'tail'; }
     *     $a = [1,2,3]; push_str($a);          // [3] => 4365925320
     *
     * This is NOT erasure. Monomorphize specializes the by-ref param to the
     * caller's `vec[int]` (by-ref IS specializable — the sorts depend on it),
     * and the body then writes a RAW string pointer into an int-repr buffer.
     * A by-ref param is only safely narrowed when the callee MOVES elements the
     * caller already put there; introducing a new element repr breaks the deal.
     *
     * The fix belongs on the CALLER: a local whose buffer is about to receive a
     * foreign element is a mixed array, so seed its element CELL and both sides
     * agree (Monomorphize then specializes the param to `vec[cell]`, which is
     * exactly right). Retyping the callee instead leaves the caller reading its
     * own buffer as `vec[int]` — the output stays wrong.
     *
     * Precision is the whole problem: gating on "callee param is erased" would
     * widen every array passed to `sort()` to a cell (perf + the rc discipline
     * of a prelude body). {@see foreignElemStores} answers the narrow question —
     * does the body store a value that came from NEITHER the array itself nor
     * another param (which Monomorphize co-specializes)?
     *
     * Records into `forcedCellElemLocals` like {@see scanLocalElemFromStores};
     * true when it found something new — the driver re-infers.
     */
    private function scanByRefElemWiden(Module $module): bool
    {
        $foreign = $this->buildForeignElemMap($module);
        if (\count($foreign) === 0) { return false; }
        $changed = false;
        foreach ($module->functions as $fn) {
            // A prelude body is linkonce_odr and shared across modules — never
            // specialize one from this module's call sites ({@see scanCallSiteRefParams}).
            if ($fn->isPrelude) { continue; }
            // Only a locally-CONSTRUCTED `[]` is ours to retype: a param is the
            // caller's array and its elements already have a representation.
            $skip = [];
            foreach ($fn->params as $prm) { $skip[$prm->name] = true; }
            $lits = [];
            $this->scanArrayLitLocals($fn->body, $lits);
            $found = [];
            $this->collectByRefWidenArgs($fn->body, $foreign, $found);
            foreach ($found as $name => $unused) {
                if (isset($skip[$name]) || !isset($lits[$name])) { continue; }
                if (isset($this->byRefCellElemLocals[$fn->name][$name])) { continue; }
                $this->byRefCellElemLocals[$fn->name][$name] = true;
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * What each by-ref array param can have APPENDED to it, as a flat token set
     * per `"fn#idx"`. Flat on purpose — a nested `array<int, Type>` is a known
     * self-host miscompile hazard ({@see Monomorphize::specialize}). Tokens:
     *
     *   `k:<kind>`      a FIXED kind — a literal, a concat, a call result;
     *   `p:<idx>:<f>`   the value comes from param `idx` (`f=1`: through an
     *                   element read, so the caller's ELEMENT kind is what lands);
     *   `v:<idx>:<f>`   the same for a VARIADIC pack — every trailing arg counts.
     *
     * A param source is not a foreign kind by itself: `array_push(array &$arr,
     * mixed ...$values)` appends whatever the caller passed, so only the call
     * site can tell whether that fits the caller's buffer.
     *
     * Fixpoint, because the hand-off is transitive: `outer(array &$a){ inner($a); }`
     * appends whatever `inner` appends.
     *
     * @return array<string, array<string,bool>>
     */
    private function buildForeignElemMap(Module $module): array
    {
        $map = [];
        $guard = 0;
        $changed = true;
        while ($changed && $guard < 4) {
            $changed = false;
            $guard = $guard + 1;
            foreach ($module->functions as $fn) {
                if ($fn->isExtern) { continue; }
                $idx = -1;
                foreach ($fn->params as $p) {
                    $idx = $idx + 1;
                    if (!$p->byRef || $p->variadic) { continue; }
                    $key = $fn->name . '#' . (string)$idx;
                    $tok = $this->foreignElemTokens($fn, $p->name, $map);
                    if (\count($tok) === 0) { continue; }
                    if (\count($tok) !== \count($map[$key] ?? [])) { $changed = true; }
                    $map[$key] = $tok;
                }
            }
        }
        return $map;
    }

    /**
     * The append tokens of ONE by-ref param. A store is EXEMPT when its value
     * came out of the array itself — directly (`$arr[$k] = $arr[$l]`) or through
     * a local filled from it (`$tmp[] = $arr[$i]` … `$arr[$k] = $tmp[$l]`, the
     * merge buffer every sort uses). That exemption is the whole reason the sort
     * family stays off the widening path.
     *
     * @param array<string, array<string,bool>> $map
     * @return array<string,bool>
     */
    private function foreignElemTokens(FunctionDef $fn, string $pname, array $map): array
    {
        // local name → "<param name>|<0|1 through an element read>"
        $origin = [];
        foreach ($fn->params as $prm) { $origin[$prm->name] = $prm->name . '|0'; }
        $paramIdx = [];
        $paramVariadic = [];
        $i = -1;
        foreach ($fn->params as $prm) {
            $i = $i + 1;
            $paramIdx[$prm->name] = $i;
            $paramVariadic[$prm->name] = $prm->variadic;
        }
        $guard = 0;
        while ($guard < 3 && $this->spreadElemOrigin($fn->body, $origin)) { $guard = $guard + 1; }
        $tokens = [];
        $this->collectForeignTokens($fn->body, $pname, $origin, $paramIdx, $paramVariadic, $map, $tokens);
        return $tokens;
    }

    /** One round of origin flow: a local filled out of a param-derived array (or
     *  bound by a foreach over one) carries that param's elements.
     *  @param array<string,string> $origin */
    private function spreadElemOrigin(Node $n, array &$origin): bool
    {
        $added = false;
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            if (!isset($origin[$n->name])) {
                $o = $this->originOf($n->value, $origin);
                if ($o !== null) { $origin[$n->name] = $o; $added = true; }
            }
        } elseif ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_LOAD_LOCAL && !isset($origin[$se->array->name])) {
                $o = $this->originOf($se->value, $origin);
                if ($o !== null) { $origin[$se->array->name] = $o; $added = true; }
            }
        } elseif ($n->kind === Node::KIND_FOREACH) {
            $fe = $n;
            $o = $this->originOf($fe->array, $origin);
            if ($o !== null && !isset($origin[$fe->valueVar])) {
                $parts = \explode('|', $o);
                $origin[$fe->valueVar] = $parts[0] . '|1';   // a foreach value IS an element
                $added = true;
            }
        }
        foreach (Walk::children($n) as $c) {
            if ($this->spreadElemOrigin($c, $origin)) { $added = true; }
        }
        return $added;
    }

    /** The param a value expression reads, as "<param>|<0|1 via element>", or
     *  null when it reads none. @param array<string,string> $origin */
    private function originOf(Node $n, array $origin): ?string
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL) {
            return $origin[$n->name] ?? null;
        }
        if ($n->kind === Node::KIND_ARRAY_ACCESS) {
            $o = $this->originOf($n->array, $origin);
            if ($o === null) { return null; }
            $parts = \explode('|', $o);
            return $parts[0] . '|1';
        }
        foreach (Walk::children($n) as $c) {
            $o = $this->originOf($c, $origin);
            if ($o !== null) { return $o; }
        }
        return null;
    }

    /**
     * @param array<string,string> $origin
     * @param array<string,int> $paramIdx
     * @param array<string,bool> $paramVariadic
     * @param array<string, array<string,bool>> $map
     * @param array<string,bool> $tokens
     */
    private function collectForeignTokens(
        Node $n,
        string $pname,
        array $origin,
        array $paramIdx,
        array $paramVariadic,
        array $map,
        array &$tokens,
    ): void {
        if ($n->kind === Node::KIND_STORE_LOCAL && $n->name === $pname) {
            // A WHOLE-array write-back (`$input = $out;`, the shape every rebuild
            // takes — array_splice). Judge it by the assigned TYPE, not by origin:
            // the rebuild buffer is filled from the param AND from elsewhere, so
            // it counts as param-derived even where it is not. An element kind
            // equal to the caller's is a no-op here, so a pure move-buffer
            // write-back still costs nothing.
            $at = $n->value->type;
            if ($at->isArray() && $at->element !== null) {
                $ak = $at->element->kind;
                if ($ak !== Type::KIND_UNKNOWN && $ak !== Type::KIND_VOID) {
                    $tokens['k:' . $ak] = true;
                }
            }
        } elseif ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_LOAD_LOCAL && $se->array->name === $pname) {
                $o = $this->originOf($se->value, $origin);
                if ($o === null) {
                    $vk = $se->value->type->kind;
                    if ($vk !== Type::KIND_UNKNOWN && $vk !== Type::KIND_VOID) {
                        $tokens['k:' . $vk] = true;
                    }
                } else {
                    $t = $this->srcToken($o, $pname, $paramIdx, $paramVariadic);
                    if ($t !== null) { $tokens[$t] = true; }
                }
            }
        } elseif ($n->kind === Node::KIND_CALL) {
            // Transitive hand-off: `outer(array &$a){ inner($a); }` appends
            // whatever `inner` appends. Re-express the callee's tokens in THIS
            // body's terms — a param source resolves through the argument we
            // actually pass, and a value we hand over out of our own array is
            // still a move, not a foreign append.
            $c = $n;
            $i = -1;
            foreach ($c->args as $a) {
                $i = $i + 1;
                if ($a->kind !== Node::KIND_LOAD_LOCAL || $a->name !== $pname) { continue; }
                $ck = $c->function . '#' . (string)$i;
                foreach ($map[$ck] ?? [] as $tok => $unused) {
                    if (\str_starts_with($tok, 'k:')) { $tokens[$tok] = true; continue; }
                    $parts = \explode(':', $tok);
                    $j = (int)$parts[1];
                    $flag = $parts[2];
                    if ($j >= \count($c->args)) { continue; }
                    $arg = $c->args[$j];
                    $o = $this->originOf($arg, $origin);
                    if ($o !== null) {
                        $ps = \explode('|', $o);
                        $eff = ($flag === '1' || $ps[1] === '1') ? '1' : '0';
                        $t = $this->srcToken($ps[0] . '|' . $eff, $pname, $paramIdx, $paramVariadic);
                        if ($t !== null) { $tokens[$t] = true; }
                        continue;
                    }
                    $at = $arg->type;
                    $ak = ($flag === '1' && $at->isArray() && $at->element !== null)
                        ? $at->element->kind
                        : $at->kind;
                    if ($ak !== Type::KIND_UNKNOWN && $ak !== Type::KIND_VOID) {
                        $tokens['k:' . $ak] = true;
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) {
            $this->collectForeignTokens($c, $pname, $origin, $paramIdx, $paramVariadic, $map, $tokens);
        }
    }

    /** A param-sourced append token, or null when the source IS the by-ref array
     *  itself (a move, never a widening).
     *  @param array<string,int> $paramIdx @param array<string,bool> $paramVariadic */
    private function srcToken(string $origin, string $pname, array $paramIdx, array $paramVariadic): ?string
    {
        $parts = \explode('|', $origin);
        $src = $parts[0];
        if ($src === $pname) { return null; }
        if (!isset($paramIdx[$src])) { return null; }
        $lead = ($paramVariadic[$src] ?? false) ? 'v:' : 'p:';
        return $lead . (string)$paramIdx[$src] . ':' . $parts[1];
    }

    /**
     * Call sites passing a plain local as the by-ref arg of a foreign-appending
     * param. The local needs widening only when an appended kind has no room in
     * its element type — an already-cell element boxes correctly, and an UNKNOWN
     * one is erased (a different root cause, {@see scanLocalElemFromStores}).
     *
     * @param array<string, array<string,bool>> $foreign
     * @param array<string,bool> $found
     */
    private function collectByRefWidenArgs(Node $n, array $foreign, array &$found): void
    {
        if ($n->kind === Node::KIND_CALL) {
            $c = $n;
            $i = -1;
            foreach ($c->args as $a) {
                $i = $i + 1;
                $key = $c->function . '#' . (string)$i;
                if (!isset($foreign[$key])) { continue; }
                if ($a->kind !== Node::KIND_LOAD_LOCAL) { continue; }
                $t = $a->type;
                if (!$t->isArray()) { continue; }
                $el = $t->element;
                if ($el === null) { continue; }
                $ek = $el->kind;
                if ($ek === Type::KIND_CELL || $ek === Type::KIND_UNKNOWN) { continue; }
                foreach ($foreign[$key] as $tok => $unused) {
                    if (\str_starts_with($tok, 'k:')) {
                        if (\substr($tok, 2) !== $ek) { $found[$a->name] = true; }
                        continue;
                    }
                    $parts = \explode(':', $tok);
                    $j = (int)$parts[1];
                    $flag = $parts[2];
                    $last = \str_starts_with($tok, 'v:') ? \count($c->args) - 1 : $j;
                    for ($k = $j; $k <= $last; $k = $k + 1) {
                        if ($k >= \count($c->args)) { break; }
                        $at = $c->args[$k]->type;
                        $ak = ($flag === '1' && $at->isArray() && $at->element !== null)
                            ? $at->element->kind
                            : $at->kind;
                        if ($ak === Type::KIND_UNKNOWN || $ak === Type::KIND_VOID) { continue; }
                        if ($ak !== $ek) { $found[$a->name] = true; }
                    }
                }
            }
        }
        foreach (Walk::children($n) as $ch) {
            $this->collectByRefWidenArgs($ch, $foreign, $found);
        }
    }

    /** A `vec[vec[scalar]]` — element is a concrete inner array whose own leaf is
     *  a concrete value kind (int/float/string/bool). Such a nested array reads and
     *  writes raw at every level; a nested element write needs no cell demotion. */
    private function isHomogeneousNestedScalarArray(Type $t): bool
    {
        $el = $t->element;
        if ($el === null || !$el->isArray()) { return false; }
        $leaf = $el->element;
        if ($leaf === null) { return false; }
        $k = $leaf->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL;
    }

    /** @param array<string,bool> $found */
    private function scanLocalElemNode(Node $n, array &$found): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_LOAD_LOCAL
                && $se->array->type->isArray()
                && $se->array->type->element !== null
                && $se->array->type->element->kind === Type::KIND_UNKNOWN
                && $se->value->type->kind === Type::KIND_CELL) {
                $found[$se->array->name] = true;
            }
            // Nested store `$a[k][...] = v` (any depth): the base is a chain of
            // ArrayAccess rooted at a local, so that local holds ARRAYS
            // (containers) — its element is a cell. Without this the local's
            // element stays UNKNOWN and the nested write-back stores the inner
            // array ptr RAW ({@see EmitLlvmBuiltins::vecWriteBack}) while var_dump
            // reads it back as a cell → garbage float. `$a[k][j]=v` auto-vivifies
            // `$a[k]=[]`.
            if ($se->array->kind === Node::KIND_ARRAY_ACCESS) {
                $root = $se->array->array;
                while ($root->kind === Node::KIND_ARRAY_ACCESS) { $root = $root->array; }
                // A HOMOGENEOUS nested array of a concrete scalar (`vec[vec[float]]`)
                // needs no cell demotion: every element is itself an inner array
                // (raw ptr) and every leaf a concrete scalar, so nested reads/writes,
                // var_dump AND a bare-`array` callee all agree on the raw repr.
                // Demoting it to vec[cell] boxes the inner arrays, which then fault
                // when passed to a bare-array param that reads elements raw (a
                // repr-conflict Monomorphize can't see). Only a heterogeneous or
                // auto-vivified (`[]` → unknown-element) container needs the cell.
                if ($root->kind === Node::KIND_LOAD_LOCAL && $root->type->isArray()
                    && !$this->isHomogeneousNestedScalarArray($root->type)) {
                    $found[$root->name] = true;
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->scanLocalElemNode($c, $found); }
    }

    private function scanFloatLocals(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $n;
            // Only a SELF-REFERENTIAL float store (`$s = $s + 1.5`, i.e. a `+=`
            // compound) makes a float slot — the loop-accumulator shape. A plain
            // `$x = 2.5` in one if/else branch must stay an int|float numeric
            // CELL (handled by cellMergeLocals), NOT be forced to float; forcing
            // it would make the int branch read float (`g(true)` -> float(9)).
            if ($this->valueIsFloatProducing($sl->value)
                && $this->valueReadsLocal($sl->value, $sl->name)) {
                $this->floatLocals[$sl->name] = true;
            }
        }
        foreach (Walk::children($n) as $c) { $this->scanFloatLocals($c); }
    }

    /**
     * Collect IncDec-target local names and locals assigned a string-producing
     * value (a string literal or a concat). Their intersection is a string local
     * that gets `++`'d — see {@see inferIncDec}.
     * @param array<string,bool> $incTargets
     * @param array<string,bool> $strAssigned
     */
    private function scanIncStrLocals(Node $n, array &$incTargets, array &$strAssigned): void
    {
        if ($n->kind === Node::KIND_INCDEC) {
            if ($n->op === '+') { $incTargets[$n->name] = true; }
        } elseif ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $n;
            $vk = $sl->value->kind;
            if ($vk === Node::KIND_STRING_CONST || $vk === Node::KIND_CONCAT) {
                $strAssigned[$sl->name] = true;
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->scanIncStrLocals($c, $incTargets, $strAssigned);
        }
    }

    private function scanAssocLocals(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_LOAD_LOCAL) {
                $name = $se->array->name;
                $this->recordDisqualified[$name] = true; // element-mutated → not a record
                if ($se->index->kind !== Node::KIND_NULL_CONST) {
                    if ($se->index->type->kind === Type::KIND_CELL) {                    // Dynamic int-or-string key (a tagged cell, e.g. an erased
                        // foreach key) → cell-keyed assoc, not a vec.
                        $this->assocLocals[$name] = true;
                        $this->cellKeyLocals[$name] = true;
                    } elseif ($this->isStringKey($se->index)) {
                        $this->assocLocals[$name] = true;
                        // A LITERAL string key (`$m["a"]=…`) — paired with a literal
                        // int key below it makes a mixed-key array (cell key). Only
                        // literals qualify: a variable int/string key is the
                        // generic dynamic case (handled by the CELL-key branch) and
                        // must not force every string-keyed map to a cell key.
                        if ($se->index->kind === Node::KIND_STRING_CONST) {
                            $this->strLitKeyLocals[$name] = true;
                        }
                    } elseif ($se->index->kind === Node::KIND_INT_CONST) {
                        $this->intKeyLocals[$name] = true;
                    }
                }
                // Track the coarse value KIND of every element store (any key,
                // incl. append) so a heterogeneous array seeds a CELL element.
                $cls = $this->coarseValueClass($se->value);
                if ($cls !== '') {
                    if (!isset($this->assocValClasses[$name])) { $this->assocValClasses[$name] = []; }
                    $this->assocValClasses[$name][$cls] = true;
                }
                // `$a[k] = []` — an empty inner array value (infers vec[unknown]).
                if ($se->value->kind === Node::KIND_ARRAY_LIT
                    && \count($se->value->elements) === 0) {
                    $this->emptyArrValLocals[$name] = true;
                }
            } elseif ($se->array->kind === Node::KIND_ARRAY_ACCESS) {
                // A nested store `$a[k][…] = v` where the value is a scalar: mark
                // the OUTER base local so an empty inner `[]` promotes to vec[cell].
                $base = $se->array->array;
                if ($base->kind === Node::KIND_LOAD_LOCAL) {
                    $bname = $base->name;
                    $this->recordDisqualified[$bname] = true; // nested mutation
                    $cls = $this->coarseValueClass($se->value);
                    if ($cls === 'num' || $cls === 'string' || $cls === 'bool' || $cls === 'null') {
                        $this->nestedScalarStoreLocals[$bname] = true;
                    }
                }
            }
        } elseif ($n->kind === Node::KIND_STORE_LOCAL) {
            // Seed the value-class set from an array-LITERAL assignment too, so a
            // later differing store promotes to a cell element: `$r = [1,2]` (num)
            // then `$r[0] = "a"` (string) is a genuinely mixed array — without the
            // literal's `num` the store's lone `string` looks homogeneous and the
            // string is written raw into a vec[int] (read back as garbage bits).
            $sl = $n;
            if ($sl->value->kind === Node::KIND_ARRAY_LIT) {
                $elems = $sl->value->elements;
                $allStr = \count($elems) > 0;
                foreach ($elems as $el) {
                    if ($el->value === null) { continue; }
                    $cls = $this->coarseValueClass($el->value);
                    if ($cls !== '') {
                        if (!isset($this->assocValClasses[$sl->name])) { $this->assocValClasses[$sl->name] = []; }
                        $this->assocValClasses[$sl->name][$cls] = true;
                    }
                    if ($el->key === null || $el->key->kind !== Node::KIND_STRING_CONST) {
                        $allStr = false;
                    }
                }
                // An all-string-literal-key literal is a record candidate; any
                // other literal shape (vec / dynamic keys) disqualifies the local.
                if ($allStr) { $this->recordLitLocals[$sl->name] = true; }
                else { $this->recordDisqualified[$sl->name] = true; }
            } else {
                // Assigned a non-literal value → can't be a static record.
                $this->recordDisqualified[$sl->name] = true;
            }
        }
        foreach (Walk::children($n) as $c) { $this->scanAssocLocals($c); }
    }

    /** Mark locals used as an array index/key — a merge-cell key does not
     *  render through the cell-key dispatch yet, so such names stay raw. */
    private function scanKeyUsedLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $this->markKeyLocal($n->index);
        } elseif ($k === Node::KIND_STORE_ELEMENT) {
            $this->markKeyLocal($n->index);
        }
        foreach (Walk::children($n) as $c) { $this->scanKeyUsedLocals($c); }
    }

    /** Mark locals used as an ARITHMETIC operand ({@see InferTypes::$arithUsedLocals}) —
     *  the signal that a null-seeded, UNKNOWN-bodied loop accumulator is NUMERIC
     *  (must carry a tag) rather than an object handle (rides raw as ptr 0). */
    private function scanArithUsedLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL
            || $k === Node::KIND_DIV || $k === Node::KIND_MOD) {
            $this->markArithLocal($n->left);
            $this->markArithLocal($n->right);
        } elseif ($k === Node::KIND_NEG) {
            $this->markArithLocal($n->operand);
        }
        foreach (Walk::children($n) as $c) { $this->scanArithUsedLocals($c); }
    }
}
