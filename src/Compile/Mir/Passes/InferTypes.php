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
    use InferNodes;
    use InferScans;
    use InferCalls;
    use InferNarrow;

    public const NAME = 'infer-types';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [LowerFromAst::NAME]; }

    /** @var array<string, Type> */
    private array $localTypes = [];
    /** @var array<string, Type> current fn's param name → declared type. A
     *  concrete-element array param stays authoritative across a store: a
     *  cell-array value assigned to it de-cellifies (see inferStoreLocal). */
    private array $currentParamTypes = [];
    /** @var array<string,string> kind-alias local → object local: a `$k = $obj->kind`
     *  binding, so a later `$k === Node::KIND_X` narrows $obj (the `$k = $n->kind`
     *  dispatch idiom). Reset per function; a re-store to $k drops its entry. */
    private array $kindAliasOf = [];
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
    /** @var array<string,bool> string locals that are `++`'d — the slot rides a
     *  CELL (numeric string → int/float, else Perl-incremented string). */
    private array $incStrLocals = [];
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
    /** fn name => [local name => true]: locals a post-inference store scan proved
     *  hold CELL elements, seeded on the next pass. {@see scanLocalElemFromStores} */
    private array $forcedCellElemLocals = [];
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
    /** @var array<string,bool> locals whose kind CHANGES across a loop back-edge
     *  (`$x = 0; while (…) { $x = getenv("H"); }`) — promoted to a cell for the
     *  WHOLE function. The if/else shadow-store trick can't work here: a read at
     *  the top of iteration 2 already sees the slot the body wrote, so the slot
     *  must be self-describing from its FIRST store, not merely after the loop.
     *  Discovered during inference (a call's kind isn't knowable to a pre-scan),
     *  so a promotion re-runs the function — see inferFunction. */
    private array $cellLoopLocals = [];
    /** @var array<string,bool> locals a loop widens int→float (`$f = 1;` then
     *  `$f = 2.5` in the body). Same story, cheaper answer: the slot is a FLOAT,
     *  not a cell, so the pre-loop int store rides a sitofp ({@see floatLocals},
     *  which the syntactic scan only fills for a self-referential `$s += 1.5`). */
    private array $floatLoopLocals = [];
    /** @var array<string,Type> locals seeded `null` before a loop that the body
     *  assigns a POINTER value (`$acc = null; foreach (…) { $acc = $acc === null
     *  ? $x : $acc->merge($x); }` — the accumulator idiom). At loop entry the
     *  name is KIND_NULL, so the body's own `$acc === null` folds to a constant
     *  TRUE and the merge arm is never taken: the accumulator silently keeps the
     *  LAST item. Unlike the cell case the answer is not a cell — {@see
     *  Type::unionWith} already rules `null ∪ obj` = obj (PHP `?C`), and a null
     *  rides that slot raw as ptr 0 — so pin the name to the BODY type for the
     *  whole function and re-infer. Value is that type, not a bool. */
    private array $nullLoopLocals = [];
    /** Set when a loop promoted a NEW name this round (re-infer needed). */
    private bool $loopPromoGrew = false;
    /** @var array<string,bool> "fn|param" already boxed at entry — a promoted
     *  PARAM arrives raw, so the box-back store is prepended once, not per round. */
    private array $cellLoopBoxedParams = [];

    /** Set when scanCtorPropContainers retypes a property (triggers re-infer). */
    private bool $ctorPropChanged = false;

    /** @var array<string, Type> global var name → unified type across every
     *  scope (`__main` + all functions that `global $g`). A global-backed
     *  StaticLocalDecl_ is hard-lowered `int`; a function that only READS the
     *  global (no local store) would keep that int and mis-render/leak a
     *  string/obj. {@see scanGlobalTypes} joins all stores; the decl seeds
     *  from here so cross-scope reads carry the real type. */
    private array $globalVarTypes = [];

    /** @var array<string,bool> every `global $x` name in the module. In `__main`
     *  these are global-backed WITHOUT a decl node ({@see EmitLlvmModule::
     *  emitFunction}), so a top-level store to one must not undo the unified
     *  {@see $globalVarTypes}. Meaningless outside `__main` — a same-named local
     *  in another scope is an ordinary local — hence the {@see $inMainBody} gate. */
    private array $mainGlobalNames = [];

    /** Whether the function being inferred is `__main`. */
    private bool $inMainBody = false;

    /** @var array<string,bool> functions whose return type was UNDECLARED (unknown
     *  before inference) — their return is re-narrowed from scratch, so the global
     *  re-infer may reset it to unknown to re-adopt a now-string global return. */
    private array $undeclaredReturnFns = [];

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

    /** `#[TypeDef]` value types — kept apart from {@see $classes} because they
     *  have no runtime object form. Only `$byte->value` and `$byte->method()` consult them.
     *  @var array<string, \Compile\Mir\ClassDef> */
    private array $typeDefs = [];

    public function run(Module $module): Module
    {
        $this->sigs = [];
        $this->classes = $module->classes;
        $this->enums = $module->enums;
        $this->typeDefs = $module->typeDefs;
        $this->fnByName = [];
        $this->closureNodeByName = [];
        $this->sawClosures = false;
        $this->undeclaredReturnFns = [];
        foreach ($module->functions as $fn) {
            $this->sigs[$fn->name] = $fn->returnType;
            $this->fnByName[$fn->name] = $fn;
            if ($fn->returnType->kind === Type::KIND_UNKNOWN) {
                $this->undeclaredReturnFns[$fn->name] = true;
            }
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
        // Property-type inference from a whole-array assignment: `$this->p =
        // [1,"x"]` (a heterogeneous literal → vec[cell]) lifts that shape onto
        // the DECLARED property type so a later `$this->p[$i]` / foreach reads a
        // tagged cell instead of a raw i64 (the property analogue of a typed
        // local literal). Runs before the element scan so the shape is set first.
        if ($this->scanPropTypeFromArrayAssign($module)) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
        }
        // Property-element inference from stores: a bare-`array` property that
        // only ever receives element stores of ONE concrete scalar/object type
        // (`$this->lines[] = $m` where `$m` settled to string via the call-site
        // pass above) is that vec[T]. Runs AFTER the call-site pass so a value
        // sourced from a now-typed param is seen concrete, not unknown. Without
        // it the property element stays erased and a read-back bitcasts each raw
        // i64 to a garbage double.
        if ($this->scanPropElemFromStores($module)) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
        }
        // The same, for a STATIC property. Its stores mostly sit outside the
        // declaring class, so neither the lowering-time AST scan nor the
        // instance scan above can reach them.
        if ($this->scanStaticPropElemFromStores($module)) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
        }
        // By-ref param type inference: an UNTYPED (`cell`) `&$p` holds the
        // caller's RAW slot value (a string/array pointer), but a cell type
        // makes string/array ops misread it as a NaN-boxed value. Refine it to
        // the concrete type every call site passes (a TYPED `&$p` already works).
        if ($this->scanCallSiteRefParams($module)) {
            foreach ($module->functions as $fn) {
                $this->inferFunction($fn);
            }
        }
        // Global var type unification: a `global $g` read in a scope that never
        // stores to it keeps the hard-lowered `int` type and mis-renders/leaks a
        // string/obj global. Join all stores and re-infer so pure-read scopes
        // pick up the real type.
        if ($this->scanGlobalTypes($module)) {
            // A pure-read return (`global $g; return $g;`) was locked to `int`
            // in pass 1 (the return narrowing only ADOPTS a scalar when the type
            // is still unknown). Reset every UNDECLARED return to unknown so the
            // re-infer re-adopts the now-string global return; declared returns
            // keep their hint.
            foreach ($module->functions as $fn) {
                if (isset($this->undeclaredReturnFns[$fn->name])) {
                    $fn->returnType = Type::unknown();
                    $this->sigs[$fn->name] = Type::unknown();
                }
            }
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
        // Element erasure on a LOCAL: `$out = []` whose only clue is a store of an
        // already-CELL value (`$out[$k] = $v`). The pre-inference scan can't type a
        // variable, so the local kept vec[unknown] and its reads came back raw.
        // Bounded — a seed only widens unknown → cell, so it converges at once.
        $guard = 0;
        while ($guard < 4 && $this->scanLocalElemFromStores($module)) {
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
        // array/string/obj is now typed, not read as a raw int. Nested closures
        // (curried arrow fns) propagate one capture level per pass — the inner
        // capture is typed only after the enclosing closure's param is seeded —
        // so iterate until the closure return sigs converge (N-level currying
        // needs N passes). Early-break keeps the common shallow case at ~2 passes;
        // the cap bounds a pathological chain (and self-build compile time).
        if ($this->sawClosures) {
            for ($iter = 0; $iter < 8; $iter = $iter + 1) {
                // Convergence is driven by CAPTURE-node types, not return kinds:
                // an inner curried capture flips unknown→cell one level per pass
                // while the enclosing closure's return kind can already look
                // stable — breaking on the return kind stops a pass too early and
                // leaves the deepest capture read raw (boxed value, raw read).
                $before = '';
                foreach ($this->closureNodeByName as $cn) {
                    foreach ($cn->captures as $c) { $before .= $c->type->kind . ','; }
                }
                foreach ($module->functions as $fn) {
                    $this->inferFunction($fn);
                }
                $after = '';
                foreach ($this->closureNodeByName as $cn) {
                    foreach ($cn->captures as $c) { $after .= $c->type->kind . ','; }
                }
                if ($before === $after) { break; }
            }
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /** @var ?Type running union of return-value types in the current fn body */
    private ?Type $fnReturnUnion = null;

    /** @var ?Type accumulated union of the current generator's yield values */
    private ?Type $genValueType = null;
    /** @var ?Type accumulated union of the current generator's explicit keys */
    private ?Type $genKeyType = null;

    /** @var array<string,bool> "Class::prop" → receives a mixed-value elem store */
    private array $cellElemPropsFound = [];

    private function findCellElemStores(Node $n, string $cls): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_PROPERTY_ACCESS
                && $se->index->kind !== Node::KIND_NULL_CONST) {
                if ($se->array->object->kind === Node::KIND_LOAD_LOCAL
                    && $se->array->object->name === 'this') {
                    // A definitely-mixed stored value (a `mixed` param / cell):
                    // the slot holds NaN-boxed cells.
                    $vk = $se->value->type->kind;
                    if ($vk === Type::KIND_CELL) {
                        $this->cellElemPropsFound[$cls . '::' . $se->array->property] = true;
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->findCellElemStores($c, $cls); }
    }

    /**
     * @param array<string, Type> $observed
     * @param array<string, bool> $unusable
     */
    private function collectPropArrayAssigns(Node $n, string $cls, array &$observed, array &$unusable): void
    {
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            if ($n->object->kind === Node::KIND_LOAD_LOCAL
                && $n->object->name === 'this') {
                $key = $cls . '::' . $n->property;
                $vt = $n->value->type;
                // Only a CONCRETE array shape carries type info; a bare/unknown-
                // element array or a non-array assignment can't seed the slot.
                $ok = $vt->isArray() && $vt->element !== null
                    && $vt->element->kind !== Type::KIND_UNKNOWN;
                if (!$ok) {
                    $unusable[$key] = true;
                } elseif (!isset($observed[$key])) {
                    $observed[$key] = $vt;
                } elseif (!$this->sameElemShape($observed[$key], $vt)) {
                    $unusable[$key] = true;
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectPropArrayAssigns($c, $cls, $observed, $unusable); }
    }

    /**
     * Whether `$t` carries NO element evidence — an `unknown`, or an array whose
     * element is absent/unknown. The shared test for "this slot still needs an
     * element scan": a concrete element must never be overwritten by one.
     */
    private function isErasedArrayType(Type $t): bool
    {
        return $t->kind === Type::KIND_UNKNOWN
            || ($t->isArray()
                && ($t->element === null || $t->element->kind === Type::KIND_UNKNOWN));
    }

    /** Whether `$t` is a concrete element a store can box into a cell slot. */
    private function isBoxablePropElem(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_STRING || $k === Type::KIND_INT
            || $k === Type::KIND_FLOAT || $k === Type::KIND_BOOL
            || ($k === Type::KIND_OBJ && $t->class !== null);
    }

    /**
     * @param array<string, Type> $observed
     * @param array<string, bool> $unusable
     */
    private function collectPropElemStores(Node $n, string $cls, array &$observed, array &$unusable): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_PROPERTY_ACCESS) {
                $owner = $this->propElemStoreOwner($se->array->object, $cls);
                if ($owner !== '') {
                    $key = $owner . '::' . $se->array->property;
                    $vt = $se->value->type;
                    if (!$this->isBoxablePropElem($vt)) {
                        $unusable[$key] = true;
                    } elseif (!isset($observed[$key])) {
                        $observed[$key] = $vt;
                    } elseif ($observed[$key]->kind !== Type::KIND_CELL
                        && !$this->sameElemShape($observed[$key], $vt)) {
                        $observed[$key] = Type::cell();
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectPropElemStores($c, $cls, $observed, $unusable); }
    }

    /**
     * Element stores into a static property (`B::$xs[] = v`), keyed by the cell
     * symbol. The counterpart of {@see collectPropElemStores} for statics —
     * see {@see scanStaticPropElemFromStores}.
     *
     * @param array<string, Type> $observed
     * @param array<string, bool> $unusable
     */
    private function collectStaticPropElemStores(Node $n, array &$observed, array &$unusable): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $se = $n;
            if ($se->array->kind === Node::KIND_STATIC_PROP) {
                $key = $se->array->global;
                $vt = $se->value->type;
                if (!$this->isBoxablePropElem($vt)) {
                    $unusable[$key] = true;
                } elseif (!isset($observed[$key])) {
                    $observed[$key] = $vt;
                } elseif ($observed[$key]->kind !== Type::KIND_CELL
                    && !$this->sameElemShape($observed[$key], $vt)) {
                    $observed[$key] = Type::cell();
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectStaticPropElemStores($c, $observed, $unusable); }
    }

    /**
     * Class whose property an element store targets: `$this->p[] = v` inside a
     * method of C → C; `$o->p[] = v` through a receiver typed `obj<D>` → D (any
     * function, including a free one / top-level main).
     *
     * Without the typed-receiver arm the scan only ever saw `$this->` stores, so
     * a property filled from OUTSIDE its class (`$b->xs[] = "a"`) kept an ERASED
     * element — and the read then guessed a repr, printing string elements as
     * garbage floats (`implode` → `2.1e-314`).
     */
    private function propElemStoreOwner(Node $obj, string $cls): string
    {
        if ($obj->kind === Node::KIND_LOAD_LOCAL && $obj->name === 'this') { return $cls; }
        $t = $obj->type;
        if ($t->kind === Type::KIND_OBJ && $t->class !== null) { return $t->class; }
        return '';
    }

    private function findPropReturns(Node $n, string $cls, Type $rt): void
    {
        if ($n->kind === Node::KIND_RETURN) {
            $rv = $n->value;
            if ($rv !== null && $rv->kind === Node::KIND_ARRAY_ACCESS) {
                $aa = $rv;
                if ($aa->array->kind === Node::KIND_PROPERTY_ACCESS
                    && $aa->index->kind !== Node::KIND_NULL_CONST) {
                    if ($aa->array->object->kind === Node::KIND_LOAD_LOCAL
                        && $aa->array->object->name === 'this') {
                        $this->propReturnsFound[$cls . '::' . $aa->array->property] = $rt;
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->findPropReturns($c, $cls, $rt); }
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

    /** @param array<string,int> $cand */
    private function collectCellSinkParams(Node $n, array $cand): void
    {
        if ($n->kind === Node::KIND_STORE_PROPERTY) {
            if ($n->object->kind === Node::KIND_LOAD_LOCAL
                && $n->object->name === 'this'
                && $n->value->kind === Node::KIND_LOAD_LOCAL
                && isset($this->cellPropNames[$n->property])) {
                $vn = $n->value->name;
                if (isset($cand[$vn])) { $this->cellSinkParams[$vn] = true; }
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectCellSinkParams($c, $cand); }
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
            $sl = $n;
            $v = $sl->value;
            if ($v->kind === Node::KIND_ARRAY_ACCESS) {
                $aa = $v;
                if ($aa->array->kind === Node::KIND_LOAD_LOCAL
                    && $aa->index->kind !== Node::KIND_NULL_CONST) {
                    $arrName = $aa->array->name;
                    if (isset($cand[$arrName])) {
                        $this->elemLocalOf[$sl->name] = $arrName;
                    }
                }
            }
        } elseif ($n->kind === Node::KIND_FOREACH) {
            // `foreach ($param as $v)` — the value var carries an element.
            $fe = $n;
            if ($fe->array->kind === Node::KIND_LOAD_LOCAL) {
                $arrName = $fe->array->name;
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
            $lp = $this->paramOfElemRef($n->left, $cand);
            $rp = $this->paramOfElemRef($n->right, $cand);
            if ($lp !== '' && $this->isStringOperand($n->right)) { $this->strParamsFound[$lp] = true; }
            if ($rp !== '' && $this->isStringOperand($n->left))  { $this->strParamsFound[$rp] = true; }
        } elseif ($k === Node::KIND_CONCAT) {
            $lp = $this->paramOfElemRef($n->left, $cand);
            $rp = $this->paramOfElemRef($n->right, $cand);
            if ($lp !== '') { $this->strParamsFound[$lp] = true; }
            if ($rp !== '') { $this->strParamsFound[$rp] = true; }
        } elseif ($k === Node::KIND_ARRAY_ACCESS) {
            // `$x[...]` where $x is an element-local → $x is a string (char
            // subscript), so its param is vec[string].
            if ($n->array->kind === Node::KIND_LOAD_LOCAL) {
                $nm = $n->array->name;
                if (isset($this->elemLocalOf[$nm])) { $this->strParamsFound[$this->elemLocalOf[$nm]] = true; }
            }
        } elseif ($k === Node::KIND_CAST) {
            // `(string)$elem` — the element is used in a string context.
            if ($n->target === 'string') {
                $p = $this->paramOfElemRef($n->operand, $cand);
                if ($p !== '') { $this->strParamsFound[$p] = true; }
            }
        } elseif ($k === Node::KIND_CALL) {
            if ($this->isStringArgBuiltin($n->function)) {
                foreach ($n->args as $a) {
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
            $nm = $node->name;
            return $this->elemLocalOf[$nm] ?? '';
        }
        if ($node->kind === Node::KIND_ARRAY_ACCESS) {
            $aa = $node;
            if ($aa->array->kind === Node::KIND_LOAD_LOCAL
                && $aa->index->kind !== Node::KIND_NULL_CONST) {
                $nm = $aa->array->name;
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
            || $bare === 'strcspn'
            || $bare === 'str_contains' || $bare === 'str_starts_with'
            || $bare === 'str_ends_with' || $bare === 'trim' || $bare === 'ltrim'
            || $bare === 'rtrim' || $bare === 'strtolower' || $bare === 'strtoupper';
    }

    /**
     * @param array<string,bool> $cand
     * @param array<string,Type> $observed
     * @param array<string,bool> $conflict
     * @param array<string,Type> $assocKey
     * @param array<string,string> $shape
     */
    private function collectCallArgElems(Node $n, array $cand, array &$observed, array &$conflict, array &$assocKey, array &$shape, array &$sawCell): void
    {
        // Resolve the target function name + a param-index base for each call
        // flavor. A free/static call's arg `i` maps to param `i`; an INSTANCE
        // method's args are offset by 1 (param 0 is `this`). A method whose
        // receiver class is erased, or an inherited method (name resolves to the
        // parent fn, not `$cls__$method`), simply won't match a candidate — a
        // conservative no-op.
        $fnName = '';
        $args = null;
        $base = 0;
        if ($n->kind === Node::KIND_CALL) {
            $fnName = $n->function;
            $args = $n->args;
        } elseif ($n->kind === Node::KIND_METHOD_CALL) {
            $mc = $n;
            $cls = $mc->object->type->class ?? '';
            if ($cls !== '') { $fnName = $cls . '__' . $mc->method; $args = $mc->args; $base = 1; }
        } elseif ($n->kind === Node::KIND_STATIC_CALL) {
            $sc = $n;
            if ($sc->class !== '') { $fnName = $sc->class . '__' . $sc->method; $args = $sc->args; }
        }
        if ($args !== null) {
            /** @var \Compile\Mir\Node[] $argl */
            $argl = $args;
            $i = 0;
            foreach ($argl as $a) {
                $key = $fnName . '#' . (string)($base + $i);
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
                $isAssoc = $a->type->isAssoc();
                // An ASSOC arg refines the param to assoc[K,V] — but ONLY for a
                // NUMERIC value (int/float/bool). A string/cell value stays out:
                // a bare-`array` param that also holds a callable name / mixed is
                // widely used in the compiler's own call machinery, and retyping
                // it assoc there mis-reads the name (a self-host FCC regression).
                if ($isAssoc) {
                    $ve = $a->type->element;
                    $vk = $ve === null ? '' : $ve->kind;
                    if ($vk !== Type::KIND_INT && $vk !== Type::KIND_FLOAT
                        && $vk !== Type::KIND_BOOL) { $conflict[$key] = true; continue; }
                } elseif (!$a->type->isVec()) {
                    $conflict[$key] = true; continue;
                }
                // All call sites must agree on the shape (all vec, or all assoc
                // with the same key type).
                $sh = $isAssoc ? 'a' : 'v';
                if (isset($shape[$key]) && $shape[$key] !== $sh) {
                    unset($observed[$key]); $conflict[$key] = true; continue;
                }
                if ($isAssoc) {
                    $kt = $a->type->key ?? Type::string_();
                    if (isset($assocKey[$key]) && $assocKey[$key]->kind !== $kt->kind) {
                        unset($observed[$key]); $conflict[$key] = true; continue;
                    }
                    $assocKey[$key] = $kt;
                }
                $shape[$key] = $sh;
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
                // A heterogeneous vec[cell] arg (element boxed by nature) records
                // that this param has a CELL floor — even if a differing concrete
                // observation later marks $conflict and unsets $observed. cell is
                // the join of every element repr, so the refiner resolves such a
                // param to vec[cell] (the cell site reads cells; Monomorphize
                // clones each concrete site off it) rather than keeping a body
                // guess that misreads the boxed slots as raw pointers (m8).
                if ($ek === Type::KIND_CELL) { $sawCell[$key] = true; }
                if (!isset($observed[$key])) { $observed[$key] = $elem; }
                elseif (!$this->sameElemShape($observed[$key], $elem)) { unset($observed[$key]); $conflict[$key] = true; }
            }
        }
        foreach (Walk::children($n) as $ch) {
            $this->collectCallArgElems($ch, $cand, $observed, $conflict, $assocKey, $shape, $sawCell);
        }
    }

    /** Record the names bound by a global-backed StaticLocalDecl_ (cell `@g_*`)
     *  in this function body.
     *  @param array<string,bool> $active */
    private function collectGlobalBacked(Node $n, array &$active): void
    {
        if ($n->kind === Node::KIND_STATIC_LOCAL_DECL) {
            if (\str_starts_with($n->cell, '@g_')) { $active[$n->name] = true; }
        }
        foreach (Walk::children($n) as $ch) {
            $this->collectGlobalBacked($ch, $active);
        }
    }

    /** Join the value type of every `StoreLocal` into an active global name,
     *  and the ELEMENT type of every element store (`$g[] = v` / `$g[$k] = v`)
     *  into `$elems`, with its key shape in `$strKey`.
     *  @param array<string,bool> $active
     *  @param array<string,Type> $observed
     *  @param array<string,Type> $elems
     *  @param array<string,bool> $elemBad
     *  @param array<string,bool> $strKey */
    private function collectGlobalStoreTypes(Node $n, array $active, array &$observed, array &$elems, array &$elemBad, array &$strKey): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $s = $n;
            if (isset($active[$s->name])) {
                $t = $s->value->type;
                $tk = $t->kind;
                if ($tk !== Type::KIND_UNKNOWN) {
                    $observed[$s->name] = isset($observed[$s->name])
                        ? $this->unionTypes($observed[$s->name], $t)
                        : $t;
                }
            }
        } elseif ($n->kind === Node::KIND_STORE_ELEMENT) {
            // `global $g; $g[] = v` is a STORE_ELEMENT, not a StoreLocal, so the
            // join above never saw it: an array global filled only by element
            // stores kept an ERASED element and the read guessed a repr (implode
            // over a string global printed `2.1e-314`). Collect the element here.
            $se = $n;
            if ($se->array->kind === Node::KIND_LOAD_LOCAL && isset($active[$se->array->name])) {
                $name = $se->array->name;
                $vt = $se->value->type;
                // The KEY decides vec-vs-assoc. A string key makes an
                // assoc[string,T]; typing it a vec would read each string key as
                // an int index and render it as its pointer (`4343328072=v`).
                // A key that is neither a plain append nor a string/int is the
                // generic dynamic case — leave the whole global to the existing
                // machinery rather than guess a container shape.
                $isAppend = $se->index->kind === Node::KIND_NULL_CONST;
                if (!$isAppend) {
                    if ($this->isStringKey($se->index)) {
                        $strKey[$name] = true;
                    } elseif ($se->index->type->kind !== Type::KIND_INT) {
                        $elemBad[$name] = true;
                    }
                }
                if (!$this->isBoxablePropElem($vt)) {
                    $elemBad[$name] = true;
                } elseif (!isset($elems[$name])) {
                    $elems[$name] = $vt;
                } elseif ($elems[$name]->kind !== Type::KIND_CELL
                    && !$this->sameElemShape($elems[$name], $vt)) {
                    $elems[$name] = Type::cell();
                }
            }
        }
        foreach (Walk::children($n) as $ch) {
            $this->collectGlobalStoreTypes($ch, $active, $observed, $elems, $elemBad, $strKey);
        }
    }

    /**
     * Observe the type each call site passes to a candidate by-ref param.
     * Only a plain lvalue (LoadLocal) is a valid by-ref arg; a cell/unknown
     * arg carries no info (skip, don't conflict). Differing concrete types
     * conflict → the param stays a cell.
     *
     * @param array<string,bool> $cand
     * @param array<string,Type> $observed
     * @param array<string,bool> $conflict
     */
    private function collectRefArgTypes(Node $n, array $cand, array &$observed, array &$conflict): void
    {
        if ($n->kind === Node::KIND_CALL) {
            $c = $n;
            $i = 0;
            foreach ($c->args as $a) {
                $key = $c->function . '#' . (string)$i;
                $i = $i + 1;
                if (!isset($cand[$key]) || isset($conflict[$key])) { continue; }
                $tk = $a->type->kind;
                if ($tk === Type::KIND_CELL || $tk === Type::KIND_UNKNOWN) { continue; }
                if (!isset($observed[$key])) { $observed[$key] = $a->type; }
                elseif (!$this->sameElemShape($observed[$key], $a->type)) {
                    unset($observed[$key]);
                    $conflict[$key] = true;
                }
            }
        }
        foreach (Walk::children($n) as $ch) {
            $this->collectRefArgTypes($ch, $cand, $observed, $conflict);
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
            $se = $n;
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

    private function valueReadsLocal(Node $v, string $name): bool
    {
        if ($v->kind === Node::KIND_LOAD_LOCAL && $v->name === $name) {
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
        if ($k === Node::KIND_CAST) { return $v->target === 'float'; }
        if ($k === Node::KIND_CALL) { return $this->isFloatReturningBuiltin($v->function); }
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
            $t = $this->localTypes[$idx->name] ?? null;
            if ($t !== null && $t->kind === Type::KIND_STRING) { return true; }
        }
        // Any other expression whose inferred type resolved to string —
        // a property read (`$decl->name`), method call, ternary, etc.
        // Set only after the first inference pass; the pre-inference
        // scan sees unknown here and falls through (no false positive).
        return $idx->type->kind === Type::KIND_STRING;
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

    /** A statement/block that always exits its enclosing flow (return/throw). */
    private function blockDiverges(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_RETURN || $k === Node::KIND_THROW) { return true; }
        if ($k === Node::KIND_BLOCK) {
            $stmts = $n->stmts;
            $c = \count($stmts);
            if ($c === 0) { return false; }
            return $this->blockDiverges($stmts[$c - 1]);
        }
        return false;
    }

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
            $tOk = $this->isScalarOrCell($tT) || ($tT->kind === Type::KIND_NULL && $this->nullBoxesWith($oT));
            $oOk = $this->isScalarOrCell($oT) || ($oT->kind === Type::KIND_NULL && $this->nullBoxesWith($tT));
            if (!$tOk || !$oOk) { continue; }
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
     *  read of $name — the (store cell + value concrete) combo EmitLlvm boxes.
     *  `$slot` overrides the destination type: a float slot coerces the same way
     *  (store float + int value → sitofp), which is what a loop-widened numeric
     *  PARAM needs at entry. */
    private function boxBackStore(string $name, Type $concrete, ?Type $slot = null): StoreLocal
    {
        $dest = $slot ?? Type::cell();
        return new StoreLocal($name, new LoadLocal($name, $concrete), $dest);
    }

    /** A scalar value kind (or an already-boxed cell) — boxable into a slot. */
    private function isScalarOrCell(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL
            || $k === Type::KIND_CELL;
    }

    /** Kinds a `null` merge arm may box INTO a shared cell slot: only the
     *  NON-pointer scalars (int/float/bool), plus an already-tagged cell. Their
     *  null cannot ride the slot raw — `0`/`0.0`/`false` collide with a real
     *  value — so `$x = null; if ($c) $x = 5;` must become a tagged cell (the null
     *  side via `box_null`) for `=== null` and reads to stay correct.
     *  STRING/ARRAY/OBJ/CLOSURE are EXCLUDED: those are pointer kinds whose null
     *  IS ptr 0. `null|obj` is already kept as `obj` by {@see \Compile\Mir\Type::
     *  unionWith}; `null|string` staying a raw nullable string is the RIGHT model
     *  but a `null∪string ⇒ string` unionWith rule UNMASKS latent
     *  "declared `string[]` holds a tagged cell" arrays (a self-host `array_retain_
     *  str` crash on generic traits) — that is the `unknown→cell` string sub-epic,
     *  deferred here. See memory `repr-consistency-null-scalar-erasure`. */
    private function nullBoxesWith(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_CELL;
    }

    /** A type whose null rides the slot RAW as ptr 0 ({@see LowerTypes::
     *  lowerTypeHint}'s nullable-POINTER note), so a `null`-seeded slot can take
     *  it and still answer `=== null` at runtime instead of folding statically.
     *
     *  INT/FLOAT/BOOL are excluded deliberately: their null is NOT ptr 0 — it
     *  would collide with `0`/`0.0`/`false` — so a numeric accumulator needs the
     *  tagged-cell promotion below, not this. UNKNOWN is included because it is
     *  the erased-pointer case this fires on in practice: the body's own
     *  `$acc->m()` types unknown precisely BECAUSE the entry says null, so
     *  keying on a concrete body type would never converge. */
    private function isPointerKind(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_OBJ || $k === Type::KIND_UNION
            || $k === Type::KIND_UNKNOWN || $k === Type::KIND_ARRAY
            || $k === Type::KIND_STRING || $k === Type::KIND_CLOSURE;
    }

    private function markKeyLocal(Node $idx): void
    {
        if ($idx->kind === Node::KIND_LOAD_LOCAL) {
            $this->keyUsedLocals[$idx->name] = true;
        }
    }

    /** The literal `true` (the `||` short-circuit then arm). */
    private function isLiteralTrue(?Node $n): bool
    {
        if ($n !== null && $n->kind === Node::KIND_BOOL_CONST) {
            return $n->value === true;
        }
        return false;
    }

    /** The literal `false` (the `&&` short-circuit else arm). */
    private function isLiteralFalse(Node $n): bool
    {
        if ($n->kind === Node::KIND_BOOL_CONST) {
            return $n->value === false;
        }
        return false;
    }

    /** Unwrap the `!!B` truthiness wrapper (`Not_(Not_(B))`) → B, or null. */
    private function unwrapNotNot(Node $n): ?Node
    {
        if ($n->kind === Node::KIND_NOT) {
            $inner = $n->operand;
            if ($inner->kind === Node::KIND_NOT) {
                return $inner->operand;
            }
        }
        return null;
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

    /** Pair a concrete branch type with a NULL sibling: a tagged cell so null is
     *  representable and renders as NULL. A numeric scalar → numeric cell (arith
     *  still promotes); a string/object/ARRAY → a plain cell. null / unknown /
     *  an already-cell type are returned unchanged.
     *
     *  An ARRAY arm lifts to a cell here (a TERNARY / local context) so
     *  `is_null`/`gettype`/`=== null` dispatch on the runtime tag — a raw vec
     *  has no null representation a static consumer would test (`ternary_null_
     *  arm_objarray` asserts exactly this). This is the OPPOSITE of a `?array`
     *  RETURN, which stays raw ({@see InferNodes::inferReturn}): a return flows
     *  to callers reading a bare-`array` param raw, and cellifying it faults
     *  (`fccParamsAndArgs`). The two are genuinely different consumers. */
    private function nullableOf(Type $t): Type
    {
        $k = $t->kind;
        if ($k === Type::KIND_INT || $k === Type::KIND_FLOAT || $k === Type::KIND_BOOL) {
            return Type::numericCell();
        }
        if ($k === Type::KIND_STRING || $k === Type::KIND_OBJ || $k === Type::KIND_ARRAY) {
            return Type::cell();
        }
        return $t;
    }

    /** A plain-ternary null arm whose SIBLING is a scalar (int/float/string/
     *  bool) or an ARRAY must lift to a nullable cell so null renders as NULL
     *  (not the sibling's zero: `$c ? 5 : null` else int(0); `$c ? [1] : null`
     *  else array(0); is_null/gettype mistold). A cell dispatches on the runtime
     *  tag, so those consumers read null correctly for free.
     *
     *  OBJECT siblings are deliberately NOT lifted: a null object is already a
     *  0 pointer (var_dump/`===`/instanceof read it right), and boxing to a cell
     *  loses the static class the inline `clone` lowering needs (the descriptor
     *  header carries no size/prop layout for a generic runtime clone). The
     *  remaining obj-null gaps (is_null/gettype short-circuiting on the static
     *  obj type) are fixed at those builtins instead. */
    private function scalarNullArm(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_STRING || $k === Type::KIND_BOOL
            || $k === Type::KIND_ARRAY;
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
            $bt = $body[$name];
            $w = $this->widenNumeric($st, $bt);
            if ($w !== null) {
                $out[$name] = $w;
                // int slot, float body: the merged type is float, but the PRE-LOOP
                // `$f = 1` store already emitted a raw i64 — a float read then
                // bitcasts it to a denormal. Record the name so the re-infer types
                // the whole slot float and that store rides a sitofp.
                if ($w->kind === Type::KIND_FLOAT && $st->kind === Type::KIND_INT
                    && !isset($this->floatLoopLocals[$name])
                    && !isset($this->assocLocals[$name])) {
                    $this->floatLoopLocals[$name] = true;
                    $this->loopPromoGrew = true;
                }
                continue;
            }
            // A NON-numeric kind change across the back-edge (`$x = 0;` then
            // `$x = getenv(…)` in the body) has no raw i64 repr that both sides
            // agree on: unionWith collapses it to `unknown`, which reads back as
            // a raw bit pattern (a float printed as its bits, a string as its
            // pointer). Promote the NAME to a cell — it carries its own tag —
            // and re-run the function so EVERY store to it boxes.
            if ($st->kind === $bt->kind) { continue; }
            // A `null` slot the body assigns a POINTER: keep the body's type for
            // the whole function ({@see $nullLoopLocals}). Must precede the
            // scalar gate below — an obj/array/string body type is not a scalar,
            // so it would `continue` and leave the body reading the entry NULL.
            if ($st->kind === Type::KIND_NULL && $this->isPointerKind($bt)) {
                $out[$name] = $bt;
                if (!isset($this->nullLoopLocals[$name])) {
                    $this->nullLoopLocals[$name] = $bt;
                    $this->loopPromoGrew = true;
                }
                continue;
            }
            // A `null` slot the body assigns a NON-pointer scalar (int/float/bool):
            // its null cannot ride raw (0/0.0/false collide), and the scalar gate
            // below rejects the null entry — leaving the body to read the stale
            // KIND_NULL and fold `=== null` to a constant. Promote to a cell (the
            // numeric analogue of the pointer case above, and of the merge shadow's
            // {@see nullBoxesWith}); the `cellLoopLocals` store pin boxes the
            // `$x = null;` seed via `box_null`. A numeric body types directly, so
            // no entry/body chicken-and-egg — key on the body kind here.
            if ($st->kind === Type::KIND_NULL && $this->nullBoxesWith($bt)) {
                if (isset($this->keyUsedLocals[$name])) { continue; }
                $out[$name] = Type::cell();
                if (!isset($this->cellLoopLocals[$name])) {
                    $this->cellLoopLocals[$name] = true;
                    $this->loopPromoGrew = true;
                }
                continue;
            }
            if (!$this->isScalarOrCell($st) || !$this->isScalarOrCell($bt)) { continue; }
            if (isset($this->keyUsedLocals[$name])) { continue; }
            $out[$name] = Type::cell();
            if (!isset($this->cellLoopLocals[$name])) {
                $this->cellLoopLocals[$name] = true;
                $this->loopPromoGrew = true;
            }
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
        $wide = ($ak === Type::KIND_FLOAT || $bk === Type::KIND_FLOAT)
            ? Type::float_()
            : Type::int_();
        // A loop-carried `#[TypeDef]` keeps its tag when BOTH sides are the same
        // one. Widening to the BARE carrier would erase what the value is, and the
        // `$x->value` after the loop would then emit as an object property read at
        // offset 16 — a load through the scalar itself. A merge with a plain int
        // (or a different TypeDef) genuinely IS a plain number: tag dropped.
        $td = $a->typeDefClass();
        if ($td !== null && $td === $b->typeDefClass()) {
            return Type::typeDef($td, $wide);
        }
        return $wide;
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
        // A homogeneous-null element cannot represent "present but null"
        // distinctly from "absent" in a raw slot (isset/`??` misread it) —
        // box it as a cell so the store emits box_null (mirrors inferArrayLit).
        if ($cur === null || $cur->kind === Type::KIND_UNKNOWN) {
            return $vt->kind === Type::KIND_NULL ? Type::cell() : $vt;
        }
        if ($vt->kind === Type::KIND_UNKNOWN) { return $cur; }
        $u = $this->unionTypes($cur, $vt);
        if ($u->kind === Type::KIND_UNKNOWN || $u->kind === Type::KIND_NULL) { return Type::cell(); }
        return $u;
    }

    /** Backing kind via a typed param (self-host slot offset). */
    private function edBacking(\Compile\Mir\EnumDef $ed): string
    {
        return $ed->backing;
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
        /** @var Type $found */
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

    /** Return type of `$method` across a union's atoms — the agreed type when
     *  every atom that HAS the method resolves it to the same kind, else null
     *  (unresolved). An atom lacking the method is an unreachable arm: calling
     *  `$x->m()` when the runtime `$x` is that class fatals in PHP, so the only
     *  non-fatal runtime types are the ones that declare it. This lets a broad
     *  `new $cls()` union (every ctor-arity match) still type `->m()` from the
     *  members that actually implement it, instead of erasing to unknown. */
    private function unionMethodReturn(Type $u, string $method): ?Type
    {
        /** @var Type $found */
        $found = null;
        foreach ($u->atoms as $atom) {
            $cls = $this->resolveMethodClass($atom->class ?? '', $method);
            if ($cls === '') { continue; }
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
        /** @var Type $found */
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
