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
        foreach ($this->cellElemPropsFound as $key => $_) {
            $cut = \strpos($key, '::');
            if ($cut === false || $cut < 0) { continue; }
            $cls = \substr($key, 0, $cut);
            $prop = \substr($key, $cut + 2, \strlen($key) - $cut - 2);
            $cd = $this->classes[$cls] ?? null;
            if ($cd === null) { continue; }
            $cur = $cd->propertyTypes[$prop] ?? null;
            if ($cur === null || !$cur->isArray()) { continue; }
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
                if (!$p->variadic && $this->isUnknownArrayElem($p->type)) {
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
        foreach ($module->functions as $fn) {
            $this->collectCallArgElems($fn->body, $cand, $observed, $conflict, $assocKey, $shape);
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
                    $fn->params[$idx - 1]->type = $t;
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
            foreach ($found as $name => $unused) {
                if (isset($skip[$name]) || !isset($lits[$name])) { continue; }
                if (isset($this->forcedCellElemLocals[$fn->name][$name])) { continue; }
                $this->forcedCellElemLocals[$fn->name][$name] = true;
                $changed = true;
            }
        }
        return $changed;
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
}
