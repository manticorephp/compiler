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
 * Intra-procedural type-inference pass.
 *
 * Refines every node's `$type` from `Type::unknown()` to a concrete
 * lattice node a later optimiser can key on, threading a `name → Type`
 * map for locals through the function body.
 *
 * Why the local map lives on `$this` and not in a `&$localTypes`
 * parameter: self-host pre-scan currently doesn't propagate nested
 * `array &$param` snapshots cleanly — copying a by-ref-bound array
 * into a fresh local inside a nested call doesn't actually
 * decouple it, and subsequent merges then read stale state. Holding
 * the map on the instance lets us snapshot it with a simple
 * `$saved = $this->localTypes;` value assignment and restore /
 * merge by re-assigning, which works.
 */
final class InferTypes implements Pass
{
    public const NAME = 'infer-types';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [LowerFromAst::NAME]; }

    /** @var array<string, Type> */
    private array $localTypes = [];
    /** @var array<string, Type> "Class::prop" → value type, built by
     *  {@see scanAssocProps} (instance state, not a by-ref recursion param). */
    private array $assocFound = [];
    /** @var array<string, Type> "Class::prop" → element type, built by
     *  {@see findPropReturns} (instance state — by-ref recursion is unsound
     *  under self-host, [[selfhost_array_ref_nesting]]). */
    private array $propReturnsFound = [];
    /** @var array<string, bool> param name → used-as-string, built by
     *  {@see detectStringElemUse} (instance state, same reason). */
    private array $strParamsFound = [];
    /** @var array<string,bool> locals that receive a string-keyed element store */
    private array $assocLocals = [];
    /** @var array<string,bool> locals stored with a CELL (dynamic int-or-string)
     *  key — an erased-source rebuild like `$o[$k]=…` over a bare-array foreach.
     *  Typed assoc[cell,*] so the key rides a tagged cell (key_cell_at/set_cell)
     *  end-to-end, preserving int AND string keys through the array. */
    private array $cellKeyLocals = [];
    /** @var array<string,bool> locals that receive a definitively INT-keyed element
     *  store (`$m[5]=…`). When a local has BOTH an int key here AND a string key
     *  ({@see $assocLocals}), it is a MIXED-key array → promoted to cellKeyLocals
     *  so foreach reads key_cell_at (a tagged int|string cell) instead of key_at
     *  (raw i64) — else the int key is read as a string ptr and faults. */
    private array $intKeyLocals = [];
    /** @var array<string,bool> locals that receive a LITERAL string-keyed store
     *  (`$m["a"]=…`); paired with {@see $intKeyLocals} → a mixed-key cell array. */
    private array $strLitKeyLocals = [];
    /** @var array<string,bool> array locals whose element stores carry ≥2 distinct
     *  value KINDS (e.g. int + string + array) → a genuinely mixed array, so its
     *  element is seeded CELL up front and held there. Without the pre-seed, a
     *  single-pass refinement leaves the EARLY stores typed by the unrefined
     *  (scalar) element → they store raw and read back as garbage. */
    private array $cellElemLocals = [];
    /** @var array<string,array<string,bool>> per-array-local set of coarse store
     *  value classes, collected pre-inference to detect heterogeneity. */
    private array $assocValClasses = [];
    /** @var array<string,bool> array locals whose element is an inner array built
     *  from an EMPTY `[]` literal (`$a[k] = []`) — the inner element infers
     *  vec[unknown] (raw). Paired with {@see $nestedScalarStoreLocals}. */
    private array $emptyArrValLocals = [];
    /** @var array<string,bool> array locals receiving a nested SCALAR store into a
     *  subscript element (`$a[k][…] = scalar`). Combined with a prior empty inner
     *  `[]` this makes the inner array genuinely mixed → its element must be a
     *  CELL, else the scalar is written raw into a vec[unknown] and read as
     *  garbage. Distinct from the flat mixed case ({@see $cellElemLocals}): here
     *  the local's VALUE is a vec[cell], not a bare cell. */
    private array $nestedScalarStoreLocals = [];
    /** @var array<string,bool> array locals promoted to a vec-of-cell VALUE element
     *  (nested subscript case). Held across binding + store refinement. */
    private array $nestedCellVecLocals = [];
    /** @var array<string,bool> locals ever assigned an all-string-literal-key
     *  array literal (a record CANDIDATE). */
    private array $recordLitLocals = [];
    /** @var array<string,bool> locals disqualified from record shape — element-
     *  mutated (`$x[k]=…`) or assigned a non-record value. */
    private array $recordDisqualified = [];
    /** @var array<string,bool> locals whose type keeps a {@see Type::record}
     *  shape (candidate ∧ not disqualified) — held across binding so a consumer
     *  (json_encode) can specialize by field type. */
    private array $recordLocals = [];
    /** @var array<string,bool> scalar locals that ever receive a float-producing
     *  value — a float SLOT, so an int init/store (`$s = 0` before a `$s += 1.5`
     *  loop) is coerced, not bit-stored, and a loop back-edge can't erase it to
     *  unknown (int ∪ float). Seeded before body inference. */
    private array $floatLocals = [];
    /** @var array<string,bool> locals whose if/else branches bind them to
     *  distinct scalar kinds — flow-promoted to a cell at the merge (this fn).
     *  Each merging branch gets an appended self-boxing `$x = box($x)` so the
     *  slot is a self-describing cell AFTER the if; reads before/inside the
     *  branches stay concrete (forward inference), reads after read the cell. */
    private array $cellMergeLocals = [];
    /** @var array<string,bool> locals used as an array INDEX/KEY anywhere in the
     *  fn — ineligible for cell-merge promotion (the cell-key store/access path
     *  does not yet render a NaN-boxed key, so a merge-cell key mis-dispatches). */
    private array $keyUsedLocals = [];
    /** Set when scanCtorPropContainers retypes a property (triggers re-infer). */
    private bool $ctorPropChanged = false;

    /** @var array<string, Type> */
    private array $sigs = [];

    /** @var array<string, FunctionDef> name → def (for closure body re-infer) */
    private array $fnByName = [];

    /**
     * Closure fn name → its `Closure_` node (an object handle, like
     * {@see $fnByName} — NOT a Type[] array / Param mutation, both of which
     * mis-free under self-host). Filled by {@see inferClosure};
     * {@see inferFunction} reads the (already inferred) capture value types
     * from it to seed the closure body's capture locals. A second inference
     * pass picks these up. No localTypes snapshot/restore (the heisenbug zone).
     * @var array<string, Closure_>
     */
    private array $closureNodeByName = [];

    /** True once any closure node was recorded (gates the second pass). */
    private bool $sawClosures = false;

    /** True while inferring a CLOSURE body — gates cell+cell arithmetic to the
     *  runtime-promoting tagged path (a named fn's untyped-param arithmetic keeps
     *  the integer-raw path the self-build relies on). */
    private bool $inClosureBody = false;

    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $classes = [];

    /** @var array<string, \Compile\Mir\EnumDef> */
    private array $enums = [];

    public function run(Module $module): Module
    {
        $this->sigs = [];
        $this->classes = $module->classes;
        $this->enums = $module->enums;
        $this->fnByName = [];
        $this->closureNodeByName = [];
        $this->sawClosures = false;
        foreach ($module->functions as $fn) {
            $this->sigs[$fn->name] = $fn->returnType;
            $this->fnByName[$fn->name] = $fn;
        }
        // Module pre-scan: a class property string-keyed anywhere
        // (`$this->prop[$k] = v`) is an assoc, not a vec. Retype it in the
        // ClassDef up front so its `[]` default + every load/store use the
        // assoc layout — the property analogue of the assocLocals scan.
        // Without this a bare `array $p = []` infers vec and a string-key
        // store reads the key ptr as an i64 index → wild gep → SIGBUS.
        $this->scanAssocProps($module);
        // Module pre-scan: a getter `M(): T { return $this->prop[$i]; }`
        // reveals that `prop` is a vec[T]. Without the element type the
        // borrowed-element return isn't +1-retained (isBorrowedObjReturn
        // sees `unknown`), so the caller over-releases the shared element —
        // e.g. peek() freeing the Parser token vec out from under itself.
        $this->scanPropElementReturns($module);
        // Module pre-scan: an array property that ever receives a MIXED/cell
        // value element store (`$this->prop[$k] = $mixed`, e.g. an ArrayAccess
        // `offsetSet(mixed $v)`) physically holds NaN-boxed cells, so its element
        // type must be CELL — else a read (`$this->prop[$k]`) returns a cell but
        // is typed scalar, and a `mixed`-return / `??` re-boxes it → double-box
        // (previously MASKED by the 48-bit truncation; see
        // representation_consistency_root).
        $this->scanCellElemProps($module);
        foreach ($module->functions as $fn) {
            $this->inferFunction($fn);
        }
        // Call-site element inference: refine a bare-`array` param to vec[T]
        // when every call passes an array arg with the SAME scalar element T —
        // a user helper called as `f(["x","y"])`. Without it the element erases
        // to unknown, so a foreach value renders as a raw ptr and an
        // element-used-as-key lands under a positional int. Externs (the
        // separately-linked stdlib) can't be specialized this way.
        if ($this->scanCallSiteArrayElems($module)) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
        }
        // Element-as-key erasure: `$o=[]; foreach($a as $v){ $o[$v]=1; }` with a
        // now-typed string foreach value `$v` must make `$o` an ASSOC, not a vec.
        // scanAssocLocals runs at the START of inferFunction (before `$v` is
        // typed by inferForeach), so the first pass leaves `$o` a vec and a
        // string-key store appends positional ints. Once the value type is set
        // on the index node (prior passes), re-infer: scanAssocLocals reads the
        // node ->type and flips `$o`'s `[]` literal to assoc. Bounded loop —
        // each flip removes the vec base, so it converges in one iteration.
        $guard = 0;
        while ($guard < 4 && $this->hasUntypedAssocKeyStore($module)) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
            $guard = $guard + 1;
        }
        // Post-inference: a constructor argument that is a known vec/assoc
        // reveals the destination property's container kind even when the
        // promoted param is a bare `array` (lowered to unknown). Retype the
        // property so EmitLlvm's prop-store co-owns the buffer (otherwise
        // the caller's local is freed while the object still points at it).
        // When it changes a property type, re-run inference so the new
        // element type propagates into reads (`foreach $obj->prop as $x`
        // and the subsequent append now see obj elements → rc them).
        $changed = $this->scanCtorPropContainers($module);
        // Second assoc-prop scan now that node types are inferred: a
        // string-keyed store whose key is a property read / method call
        // (`$this->classDecls[$decl->name] = …`) only reveals itself as
        // an assoc once `$decl->name`'s type resolved to string. Without
        // this the property keeps its `[]` vec layout while stores take
        // the assoc path → assoc_cow reads past the 16-byte vec buffer.
        if ($this->scanAssocProps($module)) { $changed = true; }
        if ($changed) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
        }
        // Second pass once capture sites are typed: closure bodies re-infer
        // with their capture locals seeded (see inferFunction) — a captured
        // array/string/obj is now typed, not read as a raw int.
        if ($this->sawClosures) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function inferFunction(FunctionDef $fn): void
    {
        $this->inClosureBody = \str_starts_with($fn->name, '__closure_');
        $this->localTypes = [];
        foreach ($fn->params as $p) {
            $this->localTypes[$p->name] = $p->type;
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
            if (\count($classes) < 2) { continue; }
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
        foreach ($this->floatLocals as $fln => $unused) {
            if (!isset($this->assocLocals[$fln])) {
                $this->localTypes[$fln] = Type::float_();
            }
        }
        $this->genValueType = null;
        $this->genKeyType = null;
        $this->fnReturnUnion = null;
        $this->cellMergeLocals = [];
        $this->keyUsedLocals = [];
        $this->scanKeyUsedLocals($fn->body);
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

    /** @var ?Type running union of return-value types in the current fn body */
    private ?Type $fnReturnUnion = null;

    /** @var ?Type accumulated union of the current generator's yield values */
    private ?Type $genValueType = null;
    /** @var ?Type accumulated union of the current generator's explicit keys */
    private ?Type $genKeyType = null;

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
            $no = $this->asNewObj($n);
            $cd = $module->classes[$no->class] ?? null;
            // FunctionDef names join class + method with `__`; the ctor
            // method itself is `__construct`, so the mangled name carries
            // four underscores (`Class____construct`).
            $ctorName = $no->class . '____construct';
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
                foreach ($no->args as $arg) {
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

    /** @var array<string,bool> "Class::prop" → receives a mixed-value elem store */
    private array $cellElemPropsFound = [];

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

    private function findCellElemStores(Node $n, string $cls): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            if ($se->array->kind === Node::KIND_PROPERTY_ACCESS
                && $se->index->kind !== Node::KIND_NULL_CONST) {
                $pa = $this->asPropertyAccess($se->array);
                if ($pa->object->kind === Node::KIND_LOAD_LOCAL
                    && $this->asLoadLocal($pa->object)->name === 'this') {
                    // A definitely-mixed stored value (a `mixed` param / cell):
                    // the slot holds NaN-boxed cells.
                    $vk = $se->value->type->kind;
                    if ($vk === Type::KIND_CELL) {
                        $this->cellElemPropsFound[$cls . '::' . $pa->property] = true;
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->findCellElemStores($c, $cls); }
    }

    private function findPropReturns(Node $n, string $cls, Type $rt): void
    {
        if ($n->kind === Node::KIND_RETURN) {
            $rv = $this->asReturn($n)->value;
            if ($rv !== null && $rv->kind === Node::KIND_ARRAY_ACCESS) {
                $aa = $this->asArrayAccess($rv);
                if ($aa->array->kind === Node::KIND_PROPERTY_ACCESS
                    && $aa->index->kind !== Node::KIND_NULL_CONST) {
                    $pa = $this->asPropertyAccess($aa->array);
                    if ($pa->object->kind === Node::KIND_LOAD_LOCAL
                        && $this->asLoadLocal($pa->object)->name === 'this') {
                        $this->propReturnsFound[$cls . '::' . $pa->property] = $rt;
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->findPropReturns($c, $cls, $rt); }
    }

    /**
     * @param array<string, Type|null> $found
     */
    private function scanAssocPropsNode(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            if ($se->array->kind === Node::KIND_PROPERTY_ACCESS
                && $se->index->kind !== Node::KIND_NULL_CONST
                && $this->isStringKey($se->index)) {
                $pa = $this->asPropertyAccess($se->array);
                $cls = $this->scanObjClass($pa->object);
                if ($cls !== '') {
                    $key = $cls . '::' . $pa->property;
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
            $name = $this->asLoadLocal($obj)->name;
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
     * Refine `array`/vec-unknown params to vec[string] from in-body usage.
     * @var array<string, string> element-local name → owning param name
     */
    private array $elemLocalOf = [];

    /** @var array<string, bool> param names promoted to cell (this fn) */
    private array $cellSinkParams = [];
    /** @var array<string, bool> cell ("mixed") property names of the receiver */
    private array $cellPropNames = [];

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

    /** @param array<string,int> $cand */
    private function collectCellSinkParams(Node $n, array $cand): void
    {
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            $sp = $this->asStoreProperty($n);
            if ($sp->object->kind === Node::KIND_LOAD_LOCAL
                && $this->asLoadLocal($sp->object)->name === 'this'
                && $sp->value->kind === Node::KIND_LOAD_LOCAL
                && isset($this->cellPropNames[$sp->property])) {
                $vn = $this->asLoadLocal($sp->value)->name;
                if (isset($cand[$vn])) { $this->cellSinkParams[$vn] = true; }
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectCellSinkParams($c, $cand); }
    }

    private function asStoreProperty(StoreProperty $n): StoreProperty { return $n; }

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

    private function isUnknownArrayElem(Type $t): bool
    {
        if ($t->kind === Type::KIND_UNKNOWN) { return true; }
        if ($t->isVec()) {
            $e = $t->element;
            return $e === null || $e->kind === Type::KIND_UNKNOWN;
        }
        return false;
    }

    /** @param array<string,int> $cand */
    private function collectElemLocals(Node $n, array $cand): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
            $v = $sl->value;
            if ($v->kind === Node::KIND_ARRAY_ACCESS) {
                $aa = $this->asArrayAccess($v);
                if ($aa->array->kind === Node::KIND_LOAD_LOCAL
                    && $aa->index->kind !== Node::KIND_NULL_CONST) {
                    $arrName = $this->asLoadLocal($aa->array)->name;
                    if (isset($cand[$arrName])) {
                        $this->elemLocalOf[$sl->name] = $arrName;
                    }
                }
            }
        } elseif ($n->kind === Node::KIND_FOREACH) {
            // `foreach ($param as $v)` — the value var carries an element.
            $fe = $this->asForeach($n);
            if ($fe->array->kind === Node::KIND_LOAD_LOCAL) {
                $arrName = $this->asLoadLocal($fe->array)->name;
                if (isset($cand[$arrName])) {
                    $this->elemLocalOf[$fe->valueVar] = $arrName;
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectElemLocals($c, $cand); }
    }

    /**
     * @param array<string,int> $cand
     */
    private function detectStringElemUse(Node $n, array $cand): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_CMP) {
            $c = $this->asCmp($n);
            $lp = $this->paramOfElemRef($c->left, $cand);
            $rp = $this->paramOfElemRef($c->right, $cand);
            if ($lp !== '' && $this->isStringOperand($c->right)) { $this->strParamsFound[$lp] = true; }
            if ($rp !== '' && $this->isStringOperand($c->left))  { $this->strParamsFound[$rp] = true; }
        } elseif ($k === Node::KIND_CONCAT) {
            $c = $this->asConcat($n);
            $lp = $this->paramOfElemRef($c->left, $cand);
            $rp = $this->paramOfElemRef($c->right, $cand);
            if ($lp !== '') { $this->strParamsFound[$lp] = true; }
            if ($rp !== '') { $this->strParamsFound[$rp] = true; }
        } elseif ($k === Node::KIND_ARRAY_ACCESS) {
            // `$x[...]` where $x is an element-local → $x is a string (char
            // subscript), so its param is vec[string].
            $aa = $this->asArrayAccess($n);
            if ($aa->array->kind === Node::KIND_LOAD_LOCAL) {
                $nm = $this->asLoadLocal($aa->array)->name;
                if (isset($this->elemLocalOf[$nm])) { $this->strParamsFound[$this->elemLocalOf[$nm]] = true; }
            }
        } elseif ($k === Node::KIND_CAST) {
            // `(string)$elem` — the element is used in a string context.
            $cast = $this->asCast($n);
            if ($cast->target === 'string') {
                $p = $this->paramOfElemRef($cast->operand, $cand);
                if ($p !== '') { $this->strParamsFound[$p] = true; }
            }
        } elseif ($k === Node::KIND_CALL) {
            $call = $this->asCall($n);
            if ($this->isStringArgBuiltin($call->function)) {
                foreach ($call->args as $a) {
                    $p = $this->paramOfElemRef($a, $cand);
                    if ($p !== '') { $this->strParamsFound[$p] = true; }
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->detectStringElemUse($c, $cand); }
    }

    /** Param name if `$node` is a param-element ref (`$elemLocal` or
     * `$param[$i]`), else ''. @param array<string,int> $cand */
    private function paramOfElemRef(Node $node, array $cand): string
    {
        if ($node->kind === Node::KIND_LOAD_LOCAL) {
            $nm = $this->asLoadLocal($node)->name;
            return $this->elemLocalOf[$nm] ?? '';
        }
        if ($node->kind === Node::KIND_ARRAY_ACCESS) {
            $aa = $this->asArrayAccess($node);
            if ($aa->array->kind === Node::KIND_LOAD_LOCAL
                && $aa->index->kind !== Node::KIND_NULL_CONST) {
                $nm = $this->asLoadLocal($aa->array)->name;
                if (isset($cand[$nm])) { return $nm; }
            }
        }
        return '';
    }

    private function isStringOperand(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_STRING_CONST) { return true; }
        if ($k === Node::KIND_CONCAT) { return true; }
        return $n->type->kind === Type::KIND_STRING;
    }

    private function isStringArgBuiltin(string $fn): bool
    {
        $pos = \strrpos($fn, '\\');
        $bare = $pos === false ? $fn : \substr($fn, $pos + 1);
        $bare = \strtolower($bare);
        return $bare === 'substr' || $bare === 'strlen' || $bare === 'strpos'
            || $bare === 'str_contains' || $bare === 'str_starts_with'
            || $bare === 'str_ends_with' || $bare === 'trim' || $bare === 'ltrim'
            || $bare === 'rtrim' || $bare === 'strtolower' || $bare === 'strtoupper';
    }

    private function asConcat(Node $n): Concat { return $n; }
    private function asCmp(Node $n): Cmp { return $n; }
    private function asArrayAccess(Node $n): ArrayAccess_ { return $n; }
    private function asCall(Node $n): Call { return $n; }

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
        $observed = [];                  // "fn#idx" → Type (scalar element)
        $conflict = [];                  // "fn#idx" → true
        foreach ($module->functions as $fn) {
            $this->collectCallArgElems($fn->body, $cand, $observed, $conflict);
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
                // observation — never fight a legitimate vec[string] whose call
                // sites pass scalar-element arrays.
                if (isset($refined[$key]) && !$observed[$key]->isArray()) { continue; }
                $param = $fn->params[$idx - 1];
                $param->type = Type::vec($observed[$key]);
                $changed = true;
            }
        }
        return $changed;
    }

    /**
     * @param array<string,bool> $cand
     * @param array<string,Type> $observed
     * @param array<string,bool> $conflict
     */
    private function collectCallArgElems(Node $n, array $cand, array &$observed, array &$conflict): void
    {
        if ($n->kind === Node::KIND_CALL) {
            $c = $this->asCall($n);
            $i = 0;
            foreach ($c->args as $a) {
                $key = $c->function . '#' . (string)$i;
                $i = $i + 1;
                if (!isset($cand[$key]) || isset($conflict[$key])) { continue; }
                // A self-recursive / forwarded arg whose OWN element type is
                // still unknown (a recursive `f($a,...)` passing its own bare
                // `array` param, or a vec[unknown]) carries NO element info — it
                // IS the type being inferred. Skip it instead of conflicting:
                // counting the param's own unresolved state as a conflict would
                // poison a concrete observation from another call site. (Recursive
                // quicksort's `&$a` erased to int → `<` compiled as an integer
                // compare on string pointers; see preserve_known_type_principle.)
                if ($this->isUnknownArrayElem($a->type)) { continue; }
                if (!$a->type->isVec()) { $conflict[$key] = true; continue; }
                $elem = $a->type->element;
                $ek = $elem === null ? '' : $elem->kind;
                // Scalar elements refine to vec[T]; a CELL element (a
                // heterogeneous literal `[42,"x",3]` → vec[cell], every element
                // box_*'d) refines to vec[cell] so `$a[$i]` reads a tagged cell
                // and the return stays a cell (a consumer dispatches by tag
                // instead of box_int'ing a boxed string back to int(ptr)). The
                // P3 $cell fallback for genuinely-heterogeneous args.
                // A NESTED array element (vec[vec[T]] / vec[assoc]) refines too,
                // so `$b[$i][$j]` carries the inner type instead of erasing to
                // unknown — without this a nested float read is compiled as an
                // integer op on the raw double bits (see nbody).
                $nested = $elem !== null && $elem->isArray();
                $refinable = $ek === Type::KIND_STRING || $ek === Type::KIND_INT
                    || $ek === Type::KIND_FLOAT || $ek === Type::KIND_BOOL
                    || $ek === Type::KIND_CELL || $nested;
                if (!$refinable) { $conflict[$key] = true; continue; }
                if (!isset($observed[$key])) { $observed[$key] = $elem; }
                elseif (!$this->sameElemShape($observed[$key], $elem)) { unset($observed[$key]); $conflict[$key] = true; }
            }
        }
        foreach (Walk::children($n) as $ch) {
            $this->collectCallArgElems($ch, $cand, $observed, $conflict);
        }
    }

    /** Two observed call-site element types agree for refinement: same kind,
     *  and for a nested array the one-level-deeper element kind matches too
     *  (so vec[float] and vec[int] conflict instead of merging). */
    private function sameElemShape(Type $a, Type $b): bool
    {
        if ($a->kind !== $b->kind) { return false; }
        if ($a->isArray()) {
            $ae = $a->element;
            $be = $b->element;
            if ($ae === null || $be === null) { return $ae === $be; }
            return $ae->kind === $be->kind;
        }
        return true;
    }

    private function asStoreLocal(Node $n): StoreLocal { return $n; }
    private function asReturn(Node $n): Return_ { return $n; }
    private function asYield(Node $n): \Compile\Mir\Yield_ { return $n; }
    private function asForeach(Node $n): Foreach_ { return $n; }
    private function asCast(Node $n): Cast { return $n; }
    private function asNewObj(Node $n): NewObj { return $n; }
    private function asClone(Node $n): \Compile\Mir\Clone_ { return $n; }

    /** True when any fn stores into a vec-typed local base under a STRING-typed
     *  key — an assoc that erased to a vec because the key (a foreach value)
     *  was typed only after scanAssocLocals already ran. A re-infer flips it. */
    private function hasUntypedAssocKeyStore(Module $module): bool
    {
        foreach ($module->functions as $fn) {
            if ($this->bodyHasUntypedAssocKeyStore($fn->body)) { return true; }
        }
        return false;
    }

    private function bodyHasUntypedAssocKeyStore(Node $n): bool
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            if ($se->array->kind === Node::KIND_LOAD_LOCAL
                && $se->array->type->isVec()
                && $se->index->kind !== Node::KIND_NULL_CONST
                && ($se->index->type->kind === Type::KIND_STRING
                    || $se->index->type->kind === Type::KIND_CELL)) {
                return true;
            }
        }
        foreach (Walk::children($n) as $c) {
            if ($this->bodyHasUntypedAssocKeyStore($c)) { return true; }
        }
        return false;
    }

    private function scanFloatLocals(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
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

    private function valueReadsLocal(Node $v, string $name): bool
    {
        if ($v->kind === Node::KIND_LOAD_LOCAL && $this->asLoadLocal($v)->name === $name) {
            return true;
        }
        foreach (Walk::children($v) as $c) {
            if ($this->valueReadsLocal($c, $name)) { return true; }
        }
        return false;
    }

    /** A value expression that SYNTACTICALLY yields a float: a float literal, a
     *  `(float)` cast, a float-returning math builtin, or arithmetic with any of
     *  those as an operand (`$s + 1.5`). Recursion is limited to numeric ops so a
     *  float used only as a call arg / index does not falsely promote the target. */
    private function valueIsFloatProducing(Node $v): bool
    {
        $k = $v->kind;
        if ($k === Node::KIND_FLOAT_CONST) { return true; }
        if ($k === Node::KIND_CAST) { return $this->asCast($v)->target === 'float'; }
        if ($k === Node::KIND_CALL) { return $this->isFloatReturningBuiltin($this->asCall($v)->function); }
        if ($k === Node::KIND_ADD || $k === Node::KIND_SUB || $k === Node::KIND_MUL
            || $k === Node::KIND_DIV || $k === Node::KIND_NEG) {
            foreach (Walk::children($v) as $c) {
                if ($this->valueIsFloatProducing($c)) { return true; }
            }
        }
        return false;
    }

    private function isFloatReturningBuiltin(string $fn): bool
    {
        $pos = \strrpos($fn, '\\');
        $n = $pos === false ? $fn : \substr($fn, $pos + 1);
        $n = \strtolower($n);
        return $n === 'floatval' || $n === 'sqrt' || $n === 'floor' || $n === 'ceil'
            || $n === 'round' || $n === 'fmod' || $n === 'sin' || $n === 'cos'
            || $n === 'tan' || $n === 'asin' || $n === 'acos' || $n === 'atan'
            || $n === 'atan2' || $n === 'sinh' || $n === 'cosh' || $n === 'tanh'
            || $n === 'exp' || $n === 'log' || $n === 'log10' || $n === 'hypot'
            || $n === 'pi' || $n === 'deg2rad' || $n === 'rad2deg';
    }

    private function scanAssocLocals(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            if ($se->array->kind === Node::KIND_LOAD_LOCAL) {
                $name = $this->asLoadLocal($se->array)->name;
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
                    && \count($this->asArrayLit($se->value)->elements) === 0) {
                    $this->emptyArrValLocals[$name] = true;
                }
            } elseif ($se->array->kind === Node::KIND_ARRAY_ACCESS) {
                // A nested store `$a[k][…] = v` where the value is a scalar: mark
                // the OUTER base local so an empty inner `[]` promotes to vec[cell].
                $base = $this->asArrayAccess($se->array)->array;
                if ($base->kind === Node::KIND_LOAD_LOCAL) {
                    $bname = $this->asLoadLocal($base)->name;
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
            $sl = $this->asStoreLocal($n);
            if ($sl->value->kind === Node::KIND_ARRAY_LIT) {
                $elems = $this->asArrayLit($sl->value)->elements;
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

    /**
     * Coarse, pre-inference value class of a stored element value — only for
     * nodes whose kind fixes the type (literals, array/new). int+float collapse
     * to `num` (they share the numeric-cell discipline); anything unclassifiable
     * (a call / var / property read) returns '' and is ignored. ≥2 distinct
     * classes on one array ⇒ a genuinely mixed array (seed a cell element).
     */
    private function coarseValueClass(Node $v): string
    {
        $k = $v->kind;
        if ($k === Node::KIND_INT_CONST || $k === Node::KIND_FLOAT_CONST) { return 'num'; }
        if ($k === Node::KIND_STRING_CONST || $k === Node::KIND_CONCAT) { return 'string'; }
        if ($k === Node::KIND_BOOL_CONST) { return 'bool'; }
        if ($k === Node::KIND_NULL_CONST) { return 'null'; }
        if ($k === Node::KIND_ARRAY_LIT) { return 'array'; }
        if ($k === Node::KIND_NEW_OBJ) { return 'obj'; }
        return '';
    }

    /** A definitively string-typed element key (no type mutation). */
    private function isStringKey(Node $idx): bool
    {
        $k = $idx->kind;
        if ($k === Node::KIND_STRING_CONST) { return true; }
        if ($k === Node::KIND_CONCAT)       { return true; }
        if ($k === Node::KIND_INT_CONST)    { return false; }
        if ($k === Node::KIND_CAST)         { return $idx->type->kind === Type::KIND_STRING; }
        if ($k === Node::KIND_LOAD_LOCAL) {
            $t = $this->localTypes[$this->asLoadLocal($idx)->name] ?? null;
            if ($t !== null && $t->kind === Type::KIND_STRING) { return true; }
        }
        // Any other expression whose inferred type resolved to string —
        // a property read (`$decl->name`), method call, ternary, etc.
        // Set only after the first inference pass; the pre-inference
        // scan sees unknown here and falls through (no false positive).
        return $idx->type->kind === Type::KIND_STRING;
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
            || $kind === Node::KIND_CONTINUE) { return Type::void(); }
        if ($kind === Node::KIND_YIELD) {
            $y = $this->asYield($node);
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
            $cl = $this->asClone($node);
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

    private function inferStoreLocal(StoreLocal $node): Type
    {
        $valueType = $this->inferNode($node->value);
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
            && \count($this->asArrayLit($node->value)->elements) === 0) {
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

    private function inferRefBind(Node $node): Type
    {
        $n = $this->asRefBind($node);
        $t = $this->inferNode($n->call);
        $this->localTypes[$n->target] = $t;
        return Type::void();
    }

    private function asRefBind(Node $n): RefBind_ { return $n; }

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

    private function arithType(Node $left, Node $right): Type
    {
        $lt = $this->inferNode($left);
        $rt = $this->inferNode($right);
        if ($lt->kind === Type::KIND_FLOAT || $rt->kind === Type::KIND_FLOAT) {
            return Type::float_();
        }
        if ($lt->kind === Type::KIND_INT && $rt->kind === Type::KIND_INT) {
            return Type::int_();
        }
        // A NUMERIC cell operand (int|float union) keeps the result a numeric
        // cell so emitArith routes to the runtime promoting tagged-arith. A
        // PLAIN mixed cell is NOT numeric → falls through to unknown (the
        // integer path), which the compiler's own untyped-param arithmetic
        // relies on — broadening to every cell SIGKILLs the self-build.
        if (($lt->isNumericCell() || $rt->isNumericCell())
            && $this->isNumericish($lt) && $this->isNumericish($rt)) {
            return Type::numericCell();
        }
        // Inside a CLOSURE body, a plain cell + cell (untyped callback params,
        // e.g. array_reduce's `fn($c, $x) => $c + $x`) is a runtime numeric op:
        // type it numericCell so emitArith routes to tagged-arith, which promotes
        // to float iff either operand is float AT RUNTIME (a raw int add would
        // truncate a float carry). Named-fn untyped-param arithmetic keeps the
        // integer-raw path (the self-build relies on it — see $inClosureBody).
        if ($this->inClosureBody
            && $lt->kind === Type::KIND_CELL && $rt->kind === Type::KIND_CELL) {
            return Type::numericCell();
        }
        return Type::unknown();
    }

    private function inferNeg(Neg $node): Type
    {
        $t = $this->inferNode($node->operand);
        if ($t->kind === Type::KIND_FLOAT) {
            $node->type = Type::float_();
        } else if ($t->kind === Type::KIND_INT) {
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
            || $n === '__mir_stderr' || $n === '__mir_argv_at') {
            return Type::obj('Ffi\\Ptr');
        }
        if ($n === '__mir_argc') { return Type::int_(); }
        if ($n === '__mir_to_cell') { return Type::cell(); }
        if ($n === 'strlen' || $n === 'count' || $n === 'sizeof'
            || $n === 'ord' || $n === 'intval' || $n === 'intdiv'
            || $n === 'printf' || $n === 'spl_object_id'
            || $n === 'array_unshift') {
            return Type::int_();
        }
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
            || $n === 'is_object') {
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
            $this->localTypes = $thenDiv ? $saved : $this->mergeLocals($saved, $thenLocals);
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

    /** A statement/block that always exits its enclosing flow (return/throw). */
    private function blockDiverges(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_RETURN || $k === Node::KIND_THROW) { return true; }
        if ($k === Node::KIND_BLOCK) {
            $stmts = $this->asBlock($n)->stmts;
            $c = \count($stmts);
            if ($c === 0) { return false; }
            return $this->blockDiverges($stmts[$c - 1]);
        }
        return false;
    }

    private function asBlock(Node $n): Block { return $n; }

    /**
     * Flow-sensitive cell promotion at an if/else merge. A local bound to
     * DISTINCT scalar kinds on the two paths (`if ($b) $x = 1.5; else $x = "hi";`)
     * can't ride the single i64 slot raw past the merge. Append a self-boxing
     * `$x = box($x)` at the END of each path so the slot holds a NaN-boxed cell
     * after the if; mark the name so mergeLocals types post-merge reads cell.
     * Reads BEFORE/INSIDE the branches stay concrete (the box is last), and a
     * later concrete re-assignment re-narrows the slot — so the original name
     * keeps its reps everywhere else (no whole-name promotion, no array/key
     * reuse hazard). Scalar-only: a string used raw stays raw on its own paths.
     * @param array<string,Type> $thenLocals @param array<string,Type> $otherLocals
     */
    private function planMergeShadow(If_ $node, array $thenLocals, array $otherLocals, bool $hasElse): void
    {
        foreach ($thenLocals as $name => $tT) {
            if (!isset($otherLocals[$name])) { continue; }
            $oT = $otherLocals[$name];
            if (!$this->isScalarOrCell($tT) || !$this->isScalarOrCell($oT)) { continue; }
            if ($tT->kind === $oT->kind) { continue; }
            if (isset($this->cellMergeLocals[$name])) { continue; }
            if (isset($this->keyUsedLocals[$name])) { continue; }
            $this->cellMergeLocals[$name] = true;
            $node->then->stmts[] = $this->boxBackStore($name, $tT);
            if ($hasElse) {
                $node->else->stmts[] = $this->boxBackStore($name, $oT);
            } else {
                $node->else = new Block([$this->boxBackStore($name, $oT)], Type::void());
            }
        }
    }

    /** `$name = box($name)`: a StoreLocal typed cell whose value is the concrete
     *  read of $name — the (store cell + value concrete) combo EmitLlvm boxes. */
    private function boxBackStore(string $name, Type $concrete): StoreLocal
    {
        return new StoreLocal($name, new LoadLocal($name, $concrete), Type::cell());
    }

    /** A scalar value kind (or an already-boxed cell) — boxable into a slot. */
    private function isScalarOrCell(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL
            || $k === Type::KIND_CELL;
    }

    /** Mark locals used as an array index/key — a merge-cell key does not
     *  render through the cell-key dispatch yet, so such names stay raw. */
    private function scanKeyUsedLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $this->markKeyLocal($this->asArrayAccess($n)->index);
        } elseif ($k === Node::KIND_STORE_ELEMENT) {
            $this->markKeyLocal($this->asStoreElement($n)->index);
        }
        foreach (Walk::children($n) as $c) { $this->scanKeyUsedLocals($c); }
    }

    private function markKeyLocal(Node $idx): void
    {
        if ($idx->kind === Node::KIND_LOAD_LOCAL) {
            $this->keyUsedLocals[$this->asLoadLocal($idx)->name] = true;
        }
    }

    /**
     * Flow-narrow a local inside a then-branch from its guard condition.
     *   - `$x instanceof C` → obj<C> (subclass dispatch; both ptr repr).
     * NOTE: `is_int/string/…($x)` narrowing of an `unknown` local is UNSOUND —
     * `unknown` can mask a NaN-boxed cell (a heterogeneous-literal element
     * erased through a bare `array` param), so retyping to a scalar makes
     * codegen read the boxed bits raw → segfault. Scalar narrowing of a cell
     * needs an unbox at the narrow point (union/dispatch work), not here.
     */
    private function narrowFromCond(Node $cond): void
    {
        if ($cond->kind === Node::KIND_INSTANCEOF) {
            $io = $this->asInstanceof($cond);
            if ($io->operand->kind === Node::KIND_LOAD_LOCAL) {
                $name = $this->asLoadLocal($io->operand)->name;
                $this->localTypes[$name] = Type::obj($io->class);
            } else {
                // `$obj->prop instanceof C` → narrow the property PATH within the
                // branch (keyed in localTypes so inferIf scopes it). Lets a
                // `mixed` prop holding an object resolve `$obj->prop->field` to a
                // typed offset (the prop is boxed; emitPropertyAccess unmasks it).
                $pk = $this->propPathKey($io->operand);
                if ($pk !== null) { $this->localTypes[$pk] = Type::obj($io->class); }
            }
        }
    }

    /** Narrowing key for `$local->prop` (NUL-joined; '' never in a name), or null. */
    private function propPathKey(Node $node): ?string
    {
        if ($node->kind !== Node::KIND_PROPERTY_ACCESS) { return null; }
        $pa = $this->asPropertyAccess($node);
        if ($pa->object->kind !== Node::KIND_LOAD_LOCAL) { return null; }
        // Separator MUST be NUL-free: this key indexes the `localTypes` assoc,
        // whose string-key compare is not reliably binary-safe across every
        // self-host layout (a `\0` made `"c\0value"` collide with `"c"`, so the
        // path-narrowing branch handed `$c->value` the type of `$c` itself —
        // e.g. `$case->value->typed()` mis-dispatched to the OUTER class). `->`
        // cannot appear in a PHP identifier, so base/property can't collide.
        return $this->asLoadLocal($pa->object)->name . "->" . $pa->property;
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
        $this->inferNode($node->body);
        $this->localTypes = $this->mergeLocals($saved, $this->localTypes);
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

    /** Name of the param at index `$i` (counted walk — T5-safe). */
    private function paramNameAt(FunctionDef $cf, int $i): string
    {
        $j = 0;
        foreach ($cf->params as $p) {
            if ($j === $i) { return $p->name; }
            $j = $j + 1;
        }
        return '';
    }

    private function inferInvoke(Invoke_ $node): Type
    {
        $ct = $this->inferNode($node->callee);
        foreach ($node->args as $a) { $this->inferNode($a); }
        // callee type obj<__closure_N> → that fn's return type.
        if ($ct->class !== null && isset($this->sigs[$ct->class])) {
            $node->type = $this->sigs[$ct->class];
        } elseif ($ct->kind === Type::KIND_CLOSURE) {
            // A generic `callable` (e.g. an array_map/usort `$cb` param) is
            // dispatched dynamically — the concrete closure isn't known. Under
            // the uniform closure ABI it returns a tagged cell, so type the
            // result cell (the caller reads it by tag instead of as raw bits).
            $node->type = Type::cell();
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
        } else {
            $node->type = $lt;
        }
        return $node->type;
    }

    private function asInstanceof(Instanceof_ $n): Instanceof_ { return $n; }
    private function asLoadLocal(LoadLocal $n): LoadLocal { return $n; }
    private function asStoreElement(StoreElement $n): StoreElement { return $n; }
    private function asPropertyAccess(PropertyAccess_ $n): PropertyAccess_ { return $n; }
    private function asArrayLit(ArrayLit $n): ArrayLit { return $n; }
    private function asStringConst(StringConst $n): StringConst { return $n; }

    private function inferIncDec(IncDec $node): Type
    {
        // `$x++` reads + writes an int local; pin the slot to int.
        $this->localTypes[$node->name] = Type::int_();
        return Type::int_();
    }

    /** A concrete value-carrying kind (boxable into a cell). */
    private function isValueKind(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL
            || $k === Type::KIND_ARRAY || $k === Type::KIND_OBJ;
    }

    /** int / float / an already-numeric cell — a numeric arithmetic arm. */
    private function isNumericish(Type $t): bool
    {
        return $t->kind === Type::KIND_INT || $t->kind === Type::KIND_FLOAT
            || $t->isNumericCell();
    }

    /** Cell type for a value union of two arms: a NUMERIC cell (int|float) when
     *  both arms are numeric so arithmetic can promote at runtime, else a plain
     *  mixed cell. Same NaN-boxed repr either way (every cell consumer matches
     *  on KIND_CELL); the numeric flag only gates the cell-arith path. */
    private function unifyToCell(Type $a, Type $b): Type
    {
        if ($this->isNumericish($a) && $this->isNumericish($b)) {
            return Type::numericCell();
        }
        return Type::cell();
    }

    private function inferTernary(Ternary $node): Type
    {
        $this->inferNode($node->cond);
        $saved = $this->localTypes;
        if ($node->then !== null) { $t = $this->inferNode($node->then); }
        else { $t = $node->cond->type; }
        $thenLocals = $this->localTypes;
        $this->localTypes = $saved;
        $e = $this->inferNode($node->else_);
        $this->localTypes = $this->mergeLocals($thenLocals, $this->localTypes);
        // A null branch carries no value type — let the other branch drive
        // the result repr (`$x?->m()` desugars to `… ? null : $x->m()`, and
        // the non-null type is what a concat / echo must render).
        if ($t->kind === Type::KIND_NULL)     { $node->type = $e; }
        elseif ($e->kind === Type::KIND_NULL) { $node->type = $t; }
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

    /**
     * True only when a loop-carried local was numerically widened int → float
     * across the body — the ONE case that needs a body re-inference (so the
     * accumulator's in-body LOADS adopt the float type). Restricted to this
     * promotion on purpose: re-inferring for any type change (e.g. an array
     * accumulator's element refining) perturbs the closure/cell paths in
     * prelude helpers (array_map) for no benefit.
     *
     * @param array<string, Type> $a
     * @param array<string, Type> $b
     */
    private function localTypesWidened(array $a, array $b): bool
    {
        foreach ($a as $name => $type) {
            if ($type->kind !== Type::KIND_INT) { continue; }
            if (isset($b[$name]) && $b[$name]->kind === Type::KIND_FLOAT) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, Type> $saved
     * @param array<string, Type> $body
     * @return array<string, Type>
     */
    private function loopMerge(array $saved, array $body): array
    {
        $out = $this->mergeLocals($saved, $body);
        foreach ($saved as $name => $st) {
            if (!isset($body[$name])) { continue; }
            $w = $this->widenNumeric($st, $body[$name]);
            if ($w !== null) { $out[$name] = $w; }
        }
        return $out;
    }

    private function widenNumeric(Type $a, Type $b): ?Type
    {
        $ak = $a->kind;
        $bk = $b->kind;
        $aNum = $ak === Type::KIND_INT || $ak === Type::KIND_FLOAT;
        $bNum = $bk === Type::KIND_INT || $bk === Type::KIND_FLOAT;
        if (!$aNum || !$bNum) { return null; }
        if ($ak === Type::KIND_FLOAT || $bk === Type::KIND_FLOAT) { return Type::float_(); }
        return Type::int_();
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

    private function inferFor(For_ $node): Type
    {
        if ($node->init !== null) { $this->inferNode($node->init); }
        if ($node->cond !== null) { $this->inferNode($node->cond); }
        $saved = $this->localTypes;
        $this->inferNode($node->body);
        if ($node->step !== null) { $this->inferNode($node->step); }
        $this->localTypes = $this->mergeLocals($saved, $this->localTypes);
        return Type::void();
    }

    private function inferDoWhile(DoWhile_ $node): Type
    {
        $saved = $this->localTypes;
        $this->inferNode($node->body);
        $this->inferNode($node->cond);
        $this->localTypes = $this->mergeLocals($saved, $this->localTypes);
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
    private function inferSpread(Node $node): Type
    {
        $s = $this->asSpread($node);
        $node->type = $this->inferNode($s->operand);
        return $node->type;
    }

    private function asSpread(Node $n): Spread_ { return $n; }

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
                $keyType = $first ? $kt : $keyType->unionWith($kt);
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
                $recordFields[$this->asStringConst($el->key)->value] = $vt;
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
            $name = $this->asLoadLocal($node->array)->name;
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

    /**
     * Merge a new stored value type into an array's running element type.
     * An unknown side defers to the known one (never lose a known type to an
     * empty `[]` or an unknown source — keeps a homogeneous nested array
     * concrete). Two KNOWN-but-different kinds that {@see unionTypes} collapses
     * to unknown become a CELL: the array is genuinely mixed, so each element
     * NaN-boxes its own tag (mirrors {@see inferArrayLit}'s heterogeneous case).
     */
    private function arrayElemMerge(?Type $cur, Type $vt): Type
    {
        if ($cur === null || $cur->kind === Type::KIND_UNKNOWN) { return $vt; }
        if ($vt->kind === Type::KIND_UNKNOWN) { return $cur; }
        $u = $this->unionTypes($cur, $vt);
        if ($u->kind === Type::KIND_UNKNOWN) { return Type::cell(); }
        return $u;
    }

    private function inferNewObj(NewObj $node): Type
    {
        foreach ($node->args as $a) {
            $this->inferNode($a);
        }
        return $node->type;
    }

    private function inferDynProp(Node $node): Type
    {
        $n = $this->asDynProp($node);
        $this->inferNode($n->object);
        $this->inferNode($n->name);
        $n->type = Type::cell();
        return $n->type;
    }

    private function inferStoreDynProp(Node $node): Type
    {
        $n = $this->asStoreDynProp($node);
        $this->inferNode($n->object);
        $this->inferNode($n->name);
        $vt = $this->inferNode($n->value);
        $n->type = $vt;
        return $vt;
    }

    private function asDynProp(Node $n): DynProp_ { return $n; }
    private function asStoreDynProp(Node $n): StoreDynProp_ { return $n; }

    /** Backing kind via a typed param (self-host slot offset). */
    private function edBacking(\Compile\Mir\EnumDef $ed): string
    {
        return $ed->backing;
    }

    private function inferPropertyAccess(PropertyAccess_ $node): Type
    {
        $objType = $this->inferNode($node->object);
        // A property PATH narrowed by an enclosing `$local->prop instanceof C`
        // wins (the branch-local map; see narrowFromCond) — so a `mixed` prop
        // holding an object resolves `$local->prop->field` to a typed offset.
        $pk = $this->propPathKey($node);
        if ($pk !== null && isset($this->localTypes[$pk])) {
            $node->type = $this->localTypes[$pk];
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

    /**
     * Declared type of `$prop` on some subclass of `$base`, or null.
     * Resolves base-typed reads of a subclass-only field.
     */
    private function subclassPropType(string $base, string $prop): ?Type
    {
        $types = [];
        $first = null;
        foreach ($this->classes as $cd) {
            if ($cd->name === $base) { continue; }
            if (!$this->classExtends($cd->name, $base)) { continue; }
            if (isset($cd->propertyTypes[$prop])) {
                $t = $cd->propertyTypes[$prop];
                if ($first === null) { $first = $t; }
                $types[] = $t;
            }
        }
        if ($first === null) { return null; }
        $allObj = true;
        foreach ($types as $t) {
            if ($t->kind !== Type::KIND_OBJ) { $allObj = false; break; }
        }
        if ($allObj) { return Type::union($types); }
        return $first;
    }

    private function unionPropType(Type $u, string $prop): ?Type
    {
        $found = null;
        foreach ($u->atoms as $atom) {
            $cd = $this->classes[$atom->class ?? ''] ?? null;
            if ($cd === null || !isset($cd->propertyTypes[$prop])) { continue; }
            $t = $cd->propertyTypes[$prop];
            if ($found === null) { $found = $t; }
            elseif ($found->kind !== $t->kind) { return null; }
        }
        return $found;
    }

    /** Whether class `$name` transitively extends `$base`. */
    private function classExtends(string $name, string $base): bool
    {
        $cur = $name;
        while ($cur !== '' && isset($this->classes[$cur])) {
            $p = $this->classes[$cur]->parent;
            if ($p === $base) { return true; }
            $cur = $p;
        }
        return false;
    }

    /**
     * {@see Type::unionWith}, but two DISTINCT object classes lift to their
     * nearest common base instead of collapsing to `unknown` — so a
     * heterogeneous array of `ClassStmt|FuncStmt` types as `obj<Stmt>` and a
     * base-typed `$stmt->decl` resolves the subclass field via the existing
     * subclass-prop fallback. The class map is needed for the ancestor walk,
     * so this lives here rather than on the (map-less) Type value object.
     */
    private function unionTypes(Type $a, Type $b): Type
    {
        if ($a->kind === Type::KIND_OBJ && $b->kind === Type::KIND_OBJ
            && $a->class !== null && $b->class !== null && $a->class !== $b->class) {
            $lca = $this->commonAncestor($a->class, $b->class);
            if ($lca !== '') { return Type::obj($lca); }
            // No common base class — a shared interface still types the union
            // enough for virtual dispatch (`[new D, new C]` of two `A`s →
            // obj<A>, so `$arr[0]->m()` resolves through A).
            $iface = $this->commonInterface($a->class, $b->class);
            return $iface === '' ? Type::unknown() : Type::obj($iface);
        }
        // Two arrays join element-/key-wise through THIS hierarchy-aware union so
        // a vec of two object subclasses lifts to vec[common-base] instead of
        // vec[unknown] (Type::unionWith can't reach the class hierarchy). Without
        // this, `for(){ if(){ $a[]=new Circle; } else { $a[]=new Square; } }`
        // erases the element on the loop back-edge merge → `$s->area()` reads a
        // raw bit pattern. An unknown element defers to the known side (never
        // lose a known type to an empty `[]`/loop-entry unknown). Restrict to an
        // OBJECT element pair — joining scalar elements stays with Type::unionWith
        // (a numeric-cell merge etc. has its own discipline we must not perturb).
        if ($a->kind === Type::KIND_ARRAY && $b->kind === Type::KIND_ARRAY
            && $a->element !== null && $b->element !== null
            && $a->element->kind === Type::KIND_OBJ
            && $b->element->kind === Type::KIND_OBJ) {
            $elem = $this->unionTypes($a->element, $b->element);
            $bothVecKeys = $a->key === null && $b->key === null;
            if ($bothVecKeys) { return Type::vec($elem); }
            $key = $a->key ?? Type::string_();
            return Type::assoc($key, $elem);
        }
        // Two arrays where either side carries a CELL (dynamic int-or-string)
        // key keep the cell-keyed assoc shape through the merge — e.g. a loop
        // back-edge join of `assoc[cell,unknown]` (loop entry) and
        // `assoc[cell,V]` (after `$o[$k]=…`). Collapsing to a vec (unionWith)
        // would make a downstream foreach read string keys as raw ints.
        if ($a->kind === Type::KIND_ARRAY && $b->kind === Type::KIND_ARRAY
            && (($a->key !== null && $a->key->kind === Type::KIND_CELL)
                || ($b->key !== null && $b->key->kind === Type::KIND_CELL))) {
            $ae = $a->element ?? Type::unknown();
            $be = $b->element ?? Type::unknown();
            $elem = $ae->kind === Type::KIND_UNKNOWN ? $be
                : ($be->kind === Type::KIND_UNKNOWN ? $ae : $ae->unionWith($be));
            return Type::assoc(Type::cell(), $elem);
        }
        return $a->unionWith($b);
    }

    /** A supertype of `$a` (interface or ancestor, transitively) that `$b` also
     *  implements, or '' — used to unify two unrelated object types. */
    private function commonInterface(string $a, string $b): string
    {
        $seen = [];
        $stack = [$a];
        while ($stack !== []) {
            $c = \array_pop($stack);
            if ($c === '' || isset($seen[$c])) { continue; }
            $seen[$c] = true;
            // `$c` (including `$a` itself, when `$b` is a subtype of `$a`) is a
            // common supertype if `$b` also conforms to it.
            if ($this->classImplementsT($b, $c)) { return $c; }
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { continue; }
            if ($cd->parent !== '') { $stack[] = $cd->parent; }
            foreach ($cd->interfaces as $i) { $stack[] = $i; }
        }
        return '';
    }

    /** Nearest common ancestor of two class names (inclusive), '' if disjoint. */
    private function commonAncestor(string $a, string $b): string
    {
        $anc = [$a => true];
        $cur = $a;
        while (isset($this->classes[$cur]) && $this->classes[$cur]->parent !== '') {
            $cur = $this->classes[$cur]->parent;
            $anc[$cur] = true;
        }
        $cur = $b;
        if (isset($anc[$cur])) { return $cur; }
        while (isset($this->classes[$cur]) && $this->classes[$cur]->parent !== '') {
            $cur = $this->classes[$cur]->parent;
            if (isset($anc[$cur])) { return $cur; }
        }
        return '';
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
            if ($pt !== null && $pt->isAssoc()) {
                $node->value->type = $pt;
                $vt = $pt;
            }
        }
        $node->type = $vt;
        return $vt;
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

    private function inferMethodCall(MethodCall_ $node): Type
    {
        $objType = $this->inferNode($node->object);
        foreach ($node->args as $a) {
            $this->inferNode($a);
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
            // Interface-typed receiver (e.g. `\Throwable $e`): the
            // interface has no ClassDef, so fall back to any class that
            // declares the method for its return signature.
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

    /** Return type of `$method` across a union's atoms — the agreed type when
     *  every atom resolves it to the same kind, else null (unresolved). */
    private function unionMethodReturn(Type $u, string $method): ?Type
    {
        $found = null;
        foreach ($u->atoms as $atom) {
            $cls = $this->resolveMethodClass($atom->class ?? '', $method);
            if ($cls === '') { return null; }
            $sig = $this->sigs[$cls . '__' . $method] ?? null;
            if ($sig === null) {
                $sig = $this->concreteOverrideSig($cls, $method);
            }
            if ($sig === null) { return null; }
            if ($found === null) { $found = $sig; }
            elseif ($found->kind !== $sig->kind) { return null; }
        }
        return $found;
    }

    /** Return type of `$method` when every class declaring it agrees on the
     *  kind — usable for a cell receiver whose runtime class is unknown. Null
     *  when the method is absent or implementers disagree. */
    private function cellMethodReturn(string $method): ?Type
    {
        $found = null;
        foreach ($this->classes as $cd) {
            if (!isset($cd->methodNames[$method])) { continue; }
            $sig = $this->sigs[$cd->name . '__' . $method] ?? null;
            if ($sig === null) { continue; }
            if ($found === null) { $found = $sig; }
            elseif ($found->kind !== $sig->kind) { return null; }
        }
        return $found;
    }

    /** Return type of iterator method `$m` on `$class`, resolving an interface
     *  iterClass via any implementer; `$dflt` when unresolved. */
    private function iterMethodReturn(string $class, string $m, Type $dflt): Type
    {
        $c = $this->resolveMethodClass($class, $m);
        if ($c === '') {
            foreach ($this->classes as $cd) {
                if (isset($cd->methodNames[$m])) { $c = $cd->name; break; }
            }
        }
        if ($c !== '' && isset($this->sigs[$c . '__' . $m])) { return $this->sigs[$c . '__' . $m]; }
        return $dflt;
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

    /**
     * @param array<string, Type> $a
     * @param array<string, Type> $b
     * @return array<string, Type>
     */
    private function mergeLocals(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $name => $type) {
            if (isset($b[$name])) {
                // A name planMergeShadow promoted at this if/else carries a
                // NaN-boxed cell past the merge (its branches got a self-boxing
                // store) — type post-merge reads cell so they dispatch by tag.
                if (isset($this->cellMergeLocals[$name])) {
                    // int|float merge → a numeric cell (arith-able past the if).
                    $out[$name] = $this->unifyToCell($type, $b[$name]);
                } else {
                    $out[$name] = $this->unionTypes($type, $b[$name]);
                }
            } elseif (\strpos($name, "->") === false) {
                // One-sided REAL local survives; a one-sided property-path
                // narrowing ("local->prop") is transient — drop it so it does
                // not flow past the branch (else a later reassign of that prop
                // is read at the stale narrowed type → a cell mis-unboxed).
                $out[$name] = $type;
            }
        }
        foreach ($b as $name => $type) {
            if (!isset($a[$name]) && \strpos($name, "->") === false) {
                $out[$name] = $type;
            }
        }
        return $out;
    }
}
