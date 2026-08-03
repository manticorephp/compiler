<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\FunctionDef;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\NodeClone;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * Non-reified monomorphization — specialize functions whose behaviour
 * depends on an erased / polymorphic parameter (a bare `array` with an
 * `unknown` element) into one concrete copy per call-site argument
 * shape.
 *
 * The motivating root: bare-`array` element-type ERASURE. A generic
 * helper (`function head(array $a){return $a[0];}`) used over `int[]` in
 * one place and `string[]` in another cannot pick a single element type
 * via all-agree inference, so its param erases to `unknown` and a
 * non-int element is read with the wrong representation. Specialization
 * gives each call-group its OWN concrete copy (`head$mono$p0_vec_int`
 * over `vec[int]`, `head$mono$p0_vec_str` over `vec[string]`) — no
 * boxing, no cell.
 *
 * Runs AFTER InferTypes (so call-site argument types are known) and
 * re-runs InferTypes when it specializes anything, so each fresh copy's
 * body is typed precisely against its concrete param types.
 *
 * Phase 1 scope (see docs/design/monomorphization.md):
 * - User functions only — no prelude, no closures/invoke, no generators,
 *   no static locals (those share module globals that a clone must not
 *   duplicate).
 * - Specialize only when a candidate has >=2 DISTINCT concrete array
 *   specialization keys across its call sites (the genuinely-erased
 *   case). Single-type helpers keep the existing all-agree path
 *   untouched — no behaviour change, self-host unaffected.
 * - Call sites whose argument shape is not fully concrete stay on the
 *   original function (the future `$cell` fallback, Phase 3).
 */
final class Monomorphize implements Pass
{
    public const NAME = 'monomorphize';

    /** Max distinct specializations per function (code-size backstop). */
    private const SPEC_CAP = 8;

    /** @var array<string, Call[]> call-site nodes grouped by callee name */
    private array $callsByName = [];

    /** @var array<string, bool> concrete closure fn names (`__closure_N`) — a
     *  callable dimension keys only on these (a real closure struct), never on
     *  a named-string / FCC / `__invoke`-object callable. */
    private array $closureNames = [];

    /** The module under specialization (Phase B closure-fn freshening needs it). */
    private ?Module $module = null;

    /** @var array<string, FunctionDef> name → def, for freshening a closure fn. */
    private array $fnByName = [];

    /** Next fresh `__closure_N` id (seeded past every existing id in run()). */
    private int $nextClosureId = 0;

    /** @var FunctionDef[] fresh closure fns minted this round (spliced in runOnce). */
    private array $newClosureFns = [];

    /** Deferred call-site repoints, applied AFTER every clone in the round is
     *  built (see specialize). Parallel arrays — a tuple array would erase the
     *  `Call` static type and native `->function` would resolve by the wrong
     *  offset (Zend-vs-native divergence).
     *  @var Call[] */
    private array $pendingRepointCalls = [];
    /** @var string[] index-parallel to {@see $pendingRepointCalls}. */
    private array $pendingRepointNames = [];

    public function name(): string { return self::NAME; }

    /** @return string[] */
    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        // Worklist fixpoint: a round specializes every polymorphic fn whose
        // call sites are now concrete, re-types, then repeats — so a clone
        // whose body calls ANOTHER polymorphic helper (e.g. `process$vec_int`
        // calling `array_map`) lets that helper specialize on the next round.
        // Specializations have concrete params, so they are never candidates
        // (isCandidate skips `$mono$`) → the worklist converges. The round cap
        // is a runaway backstop.
        // Seed the fresh-closure id past every existing `__closure_N` so freshened
        // copies never collide (across ALL rounds — a persistent counter).
        $this->nextClosureId = 0;
        foreach ($module->closureCaptures as $cn => $_) {
            $id = (int)\substr($cn, \strlen('__closure_'));
            if ($id >= $this->nextClosureId) { $this->nextClosureId = $id + 1; }
        }
        $maxRounds = \count($module->functions) + 8;
        $round = 0;
        while ($round < $maxRounds) {
            $round = $round + 1;
            $roundT = \Compile\Stats::now();
            $before = \count($module->functions);
            $more = $this->runOnce($module);
            \Compile\Stats::step('  mono round ' . (string)$round, $roundT,
                \count($module->functions), -1);
            \Compile\Stats::bump('mono.rounds', 1);
            \Compile\Stats::bump('mono.fns_added', \count($module->functions) - $before);
            if (!$more) { break; }
        }
        if ($round >= $maxRounds) {
            \Compile\Stats::line('mono: HIT THE ROUND CAP (' . (string)$maxRounds . ')');
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /** One specialization round. Returns true if anything was specialized
     *  (and the module re-typed), false at the fixpoint. */
    private function runOnce(Module $module): bool
    {
        $this->callsByName = [];
        $this->closureNames = $module->closureCaptures;
        $this->module = $module;
        $this->newClosureFns = [];
        $this->pendingRepointCalls = [];
        $this->pendingRepointNames = [];
        $this->fnByName = [];
        foreach ($module->functions as $fn) {
            $this->fnByName[$fn->name] = $fn;
            $this->collectCalls($fn->body);
        }

        // originalName → [ specializedFunctionDef, ... ]
        $clonesByOrig = [];
        $changed = false;

        foreach ($module->functions as $fn) {
            if (!$this->isCandidate($fn)) { continue; }
            $clones = $this->specialize($fn);
            if (\count($clones) === 0) { continue; }
            $clonesByOrig[$fn->name] = $clones;
            $changed = true;
        }

        if (!$changed) { return false; }

        // All clones built (from un-repointed originals) — now apply every
        // deferred call-site repoint at once.
        foreach ($this->pendingRepointCalls as $i => $call) {
            $call->function = $this->pendingRepointNames[$i];
        }

        // Splice each specialization in right after its original, so a
        // specialized callee precedes call sites defined later and the
        // scalar-return adoption in InferTypes sees its sig in order.
        $rebuilt = [];
        foreach ($module->functions as $fn) {
            $rebuilt[] = $fn;
            if (isset($clonesByOrig[$fn->name])) {
                foreach ($clonesByOrig[$fn->name] as $clone) { $rebuilt[] = $clone; }
            }
        }
        // Fresh closure fns minted while cloning bodies (Phase B). Appended last;
        // InferTypes re-types every fn regardless of order and seeds each closure
        // body from its (now unique, concretely-typed) capture site.
        foreach ($this->newClosureFns as $clFn) { $rebuilt[] = $clFn; }
        $module->functions = $rebuilt;

        // Re-type: specialized bodies now have concrete params; rewritten
        // call sites resolve to the specialized sigs (and nested polymorphic
        // calls inside the clones become concrete for the next round).
        $infer = new InferTypes();
        $infer->run($module);
        return true;
    }

    /**
     * Build (and register, by mutating call sites in place) the set of
     * specialized copies of `$fn`. Returns the new FunctionDefs, or [] if
     * the function is not a profitable / supported specialization target.
     *
     * @return FunctionDef[]
     */
    private function specialize(FunctionDef $fn): array
    {
        $calls = $this->callsByName[$fn->name] ?? [];
        if (\count($calls) === 0) { return []; }

        $dims = $this->dimensions($fn, $calls);
        if (\count($dims) === 0) { return []; }

        // A callable dimension turns a DYNAMIC invoke into a KNOWN one — a real
        // win from a SINGLE concrete-closure site. A pure array-dim
        // specialization keeps the conservative >=2-call-sites threshold (a
        // single-type helper stays on the untouched all-agree path — no bloat).
        //
        // EXCEPT for a prelude fn: "stays on the untouched all-agree path" is
        // only true for a symbol this module owns. A prelude body is emitted
        // linkonce_odr into every module and coalesced to ONE copy, so a
        // single-site specialization still mutates a SHARED symbol, and another
        // module's differently-specialized copy can win at link time. Give every
        // concrete site its own `$mono$` symbol instead.
        //
        // A CONFLICTED-CONCRETE dim is the third early-specialize case: a body
        // heuristic concretized the param (e.g. `'x'.$a[0]` guesses vec[string]),
        // but a call site passes a DIFFERENT concrete element repr (vec[int]).
        // The single body reads that site's raw int slot as a string ptr →
        // SIGSEGV. The original type is KNOWN-WRONG for the conflicting site, so
        // any specialization strictly improves — treat it like the callable dim
        // (>=1 concrete key, even from a single call site).
        // A vec[cell] param dimension is the cell-floor case (InferScans resolved
        // it because a heterogeneous vec[cell] site cannot be specialized): the
        // original body reads/unboxes cells and serves the cell site, while each
        // CONCRETE site must clone off it (a raw vec[string] arg fed to the
        // cell-reading body reads a bare ptr as a boxed cell → garbage). A cell
        // param always implies >=1 cell site (else it never floored), so its
        // concrete sites genuinely need their own body — specialize from ONE key.
        $hasCallableDim = $this->hasCallableDim($fn, $dims);
        $hasConflictedConcrete = $this->hasConflictedConcreteDim($fn, $dims, $calls);
        $hasCellDim = $this->hasCellElemDim($fn, $dims)
            || $this->hasCellElemArgDim($fn, $dims, $calls);
        if (!$hasCallableDim && !$fn->isPrelude && !$hasConflictedConcrete
            && !$hasCellDim && \count($calls) < 2) { return []; }

        // Per call site: a specialization key over the dimension arg types,
        // or '' when the site is not fully concrete (stays on the original).
        // A representative Call per key carries the concrete arg types into
        // cloning — avoids holding a nested array<int,Type> (a self-host
        // miscompile hazard).
        $callKeys = [];          // index-parallel to $calls: key string or ''
        $keyToCall = [];         // key → representative Call
        foreach ($calls as $ci => $call) {
            $key = $this->callKey($call, $dims);
            $callKeys[$ci] = $key;
            if ($key !== '' && !isset($keyToCall[$key])) {
                $keyToCall[$key] = $call;
            }
        }
        // Callable dim: >=1 concrete key specializes (dynamic -> known). Pure
        // array dim: keep >=2 distinct keys (the genuinely-erased case) — but a
        // prelude fn specializes from ONE key, since its symbol is shared across
        // objects and cannot carry a module-local body (see above). A
        // conflicted-concrete dim also specializes from ONE key: even when every
        // call site agrees on vec[int] while the body guessed vec[string], the
        // lone key still needs a clone to correct the repr the original misreads.
        $minKeys = ($hasCallableDim || $fn->isPrelude || $hasConflictedConcrete
            || $hasCellDim) ? 1 : 2;
        if (\count($keyToCall) < $minKeys) { return []; }

        // Per-fn specialization cap (code-size / compile-time backstop). On
        // overflow, leave the function unspecialized — every call site falls
        // back to the original (the name-addressable dynamic entry, with
        // today's erased/all-agree behaviour). Rare: >SPEC_CAP distinct
        // concrete element types of ONE helper in a single program.
        if (\count($keyToCall) > self::SPEC_CAP) { return []; }

        // Clone one copy per distinct key. NodeClone throws on a node kind
        // it does not yet support (closures/invoke) — bail on the whole
        // function rather than emit a partial specialization.
        $keyToName = [];
        $clones = [];
        foreach ($keyToCall as $key => $repCall) {
            $specName = $fn->name . '$mono$' . $key;
            // Already minted in an earlier ROUND — re-point this site at it, but
            // do NOT clone a second body. A RECURSIVE function keeps a call to
            // itself inside the original, so the next round re-derives the very
            // same key and emitted a duplicate definition: clang rejected
            // `array_walk_recursive$mono$p1_obj___closure_1` with "invalid
            // redefinition".
            if (isset($this->fnByName[$specName])) {
                $keyToName[$key] = $specName;
                continue;
            }
            $clone = $this->cloneWith($fn, $specName, $repCall, $dims);
            if ($clone === null) { return []; }
            $keyToName[$key] = $specName;
            $clones[] = $clone;
            $this->fnByName[$specName] = $clone;
        }

        // DEFER repointing to the end of the round (applied in runOnce). A
        // `$mono$` callee name encodes the closure-ARG identity, so a call must
        // not be repointed before a SIBLING candidate clones a body containing
        // it: freshenClosures gives the clone a fresh closure id, and a call
        // already repointed on the shared original would carry a stale
        // specialization name into the clone (wrong closure). Cloning from
        // un-repointed originals, then repointing once, avoids the hazard.
        foreach ($calls as $ci => $call) {
            $k = $callKeys[$ci];
            if ($k !== '' && isset($keyToName[$k])) {
                $this->pendingRepointCalls[] = $call;
                $this->pendingRepointNames[] = $keyToName[$k];
            }
        }
        return $clones;
    }

    /**
     * Parameter indices that are an erased-array "specialization
     * dimension": an `unknown` / `vec[unknown]` param that receives a
     * concrete array at >=1 call site. By-ref and variadic params are
     * never dimensions.
     *
     * @param Call[] $calls
     * @return int[]
     */
    private function dimensions(FunctionDef $fn, array $calls): array
    {
        $dims = [];
        foreach ($fn->params as $idx => $p) {
            // Variadic stays unspecializable. By-ref IS specializable: a
            // `sort(array &$arr)` called over int[] AND string[] in one program
            // erases its element (all-agree conflict) -> the string case does a
            // pointer compare. cloneWith keeps `byRef` and the call keeps passing
            // the lvalue, so in-place mutation is preserved.
            // A VARIADIC pack is specializable: the caller has already built it
            // as an array_lit whose type is known at the call site
            // (`vec[assoc[string,cell]]`), while the declared param is
            // `vec[unknown]`. Left erased, `foreach ($others as $o)` bound `$o`
            // as unknown and the inner `foreach ($o as $k => $v)` read the
            // buffer with no type — the copy loop in array_replace_recursive /
            // array_merge_recursive returned garbage for every string key.
            // cloneWith substitutes the pack type wholesale, so the pack ABI is
            // unchanged — only its element type gets sharper.
            if ($p->variadic && !$this->isSpecializableVariadicPack($p, $idx, $calls)) { continue; }
            // A dimension is either an erased-array param receiving a concrete
            // array, or a bare `callable` param receiving a concrete closure at
            // >=1 site. Retyping the callable param to the closure's obj type
            // makes its internal invoke KNOWN (the milestone cellify then fires).
            if ($this->isErasedArrayParam($p->type)) {
                foreach ($calls as $call) {
                    if ($idx < \count($call->args)
                        && $this->isSpecializableArray($call->args[$idx]->type)) {
                        $dims[] = $idx;
                        break;
                    }
                }
            } elseif ($this->isConcreteArray($p->type)
                && $this->paramConflictsWithArg($p, $idx, $calls)) {
                // A body heuristic already concretized this param to ONE element
                // repr, but a call site passes a concrete array with a DIFFERENT
                // repr. isErasedArrayParam is false (it looks concrete), so the
                // erased branch above cannot see it — recognise the conflict here
                // so each concrete site gets its own re-inferred body.
                $dims[] = $idx;
            } elseif ($this->isCallableParam($p->type)) {
                foreach ($calls as $call) {
                    if ($idx < \count($call->args)
                        && $this->isConcreteClosure($call->args[$idx]->type)) {
                        $dims[] = $idx;
                        break;
                    }
                }
            }
        }
        return $dims;
    }

    /**
     * A concrete-array param whose declared element repr DISAGREES with a
     * concrete-array argument at >=1 call site. This is the body-heuristic
     * misfire: `f(array $a){ return 'x'.$a[0]; }` guesses `vec[string]` from the
     * concat, yet a `f([7])` site passes `vec[int]` (raw int slots). The single
     * body then `inttoptr`s a bare int as a string pointer → SIGSEGV. Distinct
     * typeToken is the conflict test: an argument whose token equals the param's
     * needs no specialization (the body already reads it right).
     *
     * @param Call[] $calls
     */
    private function paramConflictsWithArg(Param $p, int $idx, array $calls): bool
    {
        $paramTok = $this->typeToken($p->type);
        foreach ($calls as $call) {
            if ($idx >= \count($call->args)) { continue; }
            $at = $call->args[$idx]->type;
            if ($this->isConcreteArray($at) && $this->typeToken($at) !== $paramTok) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when any dimension is a conflicted-concrete array param (added by the
     * middle branch of {@see dimensions}). Such a dim specializes from a SINGLE
     * key — the original body is known-wrong for the conflicting site, so even
     * one clone strictly improves — so it drops `minKeys` to 1 like the callable
     * and prelude cases.
     *
     * @param int[] $dims
     * @param Call[] $calls
     */
    private function hasConflictedConcreteDim(FunctionDef $fn, array $dims, array $calls): bool
    {
        foreach ($dims as $di) {
            $p = $fn->params[$di];
            if (!$this->isErasedArrayParam($p->type)
                && !$this->isCallableParam($p->type)
                && $this->isConcreteArray($p->type)
                && $this->paramConflictsWithArg($p, $di, $calls)) {
                return true;
            }
        }
        return false;
    }

    /**
     * True when any dimension's param is a vec[cell] / assoc[?,cell] array (the
     * cell-floor case). Its concrete call sites specialize from a SINGLE key —
     * the original cell body serves the heterogeneous cell site, and every
     * concrete site clones off it, so {@see specialize} drops minKeys to 1.
     *
     * @param int[] $dims
     */
    private function hasCellElemDim(FunctionDef $fn, array $dims): bool
    {
        foreach ($dims as $di) {
            $t = $fn->params[$di]->type;
            if ($t->isArray() && $t->element !== null
                && $t->element->kind === Type::KIND_CELL) {
                return true;
            }
        }
        return false;
    }

    /**
     * The mirror of {@see hasCellElemDim} on the ARGUMENT side: an erased
     * (bare-`array` → KIND_UNKNOWN) param receiving a CELL-element array at some
     * call site. That also specializes from a SINGLE key.
     *
     * The ≥2-distinct-keys rule exists for the genuinely-erased case, where one
     * call shape means the body's own inference already reads the elements
     * right. A cell-element argument breaks that assumption: the erased body
     * reads element slots RAW while the caller's slots are NaN-boxed, so a
     * lone call site still needs its own clone. Without this,
     * `function f(array $a): array { return $a; }` called once with
     * `['a'=>'x','b'=>7]` returns a raw pointer through an `unknown` result —
     * `is_array()` says false and `var_dump` prints an int.
     *
     * @param int[] $dims
     * @param Call[] $calls
     */
    private function hasCellElemArgDim(FunctionDef $fn, array $dims, array $calls): bool
    {
        foreach ($dims as $di) {
            if (!$this->isErasedArrayParam($fn->params[$di]->type)) { continue; }
            foreach ($calls as $call) {
                if ($di >= \count($call->args)) { continue; }
                $at = $call->args[$di]->type;
                if (!$at->isArray()) { continue; }
                $e = $at->element;
                if ($e !== null && $e->kind === Type::KIND_CELL) { return true; }
                $k = $at->key;
                if ($at->isAssoc() && $k !== null && $k->kind === Type::KIND_CELL) { return true; }
            }
        }
        return false;
    }

    /**
     * Specialization key for a call over `$dims` — a token per dimension
     * built from the concrete argument type at that position. Returns ''
     * when any dimension's argument is not a concrete array (the site is
     * not specializable and stays on the original function).
     *
     * @param int[] $dims
     */
    private function callKey(Call $call, array $dims): string
    {
        $parts = [];
        foreach ($dims as $di) {
            if ($di >= \count($call->args)) { return ''; }
            $t = $call->args[$di]->type;
            // A dim's arg is specializable when it is a concrete array OR a
            // concrete closure (the callable dimension). typeToken renders both
            // (a closure arg is KIND_OBJ<__closure_N> → `obj_...`).
            if (!$this->isSpecializableArray($t) && !$this->isConcreteClosure($t)) { return ''; }
            $parts[] = 'p' . $di . '_' . $this->typeToken($t);
        }
        return \implode('_', $parts);
    }

    /**
     * Clone `$fn` as `$specName`, substituting each dimension param's type
     * with the concrete argument type from the representative call site.
     * Returns null if the body uses a node kind NodeClone cannot copy yet.
     *
     * @param int[] $dims
     */
    private function cloneWith(FunctionDef $fn, string $specName, Call $repCall, array $dims): ?FunctionDef
    {
        $isDim = [];
        foreach ($dims as $di) { $isDim[$di] = true; }
        $newParams = [];
        foreach ($fn->params as $idx => $p) {
            $t = $p->type;
            if (isset($isDim[$idx]) && $idx < \count($repCall->args)) {
                $t = $repCall->args[$idx]->type;
            }
            $np = new Param($p->name, $t, $p->byRef, $p->variadic, $p->default);
            $np->refOut = $p->refOut;
            $np->cellArg = $p->cellArg;
            $np->arrayHinted = $p->arrayHinted;
            $np->docList = $p->docList;
            $newParams[] = $np;
        }
        try {
            $body = NodeClone::block($fn->body);
        } catch (\Throwable $e) {
            return null;
        }
        // Phase B: this clone must own any closure it defines — a SHARED
        // `__closure_N` would be typed by the UNION of every clone's capture
        // site, collapsing the concrete capture types back to bare. Freshen each
        // closure fn per clone so a captured callable keeps its concrete type
        // (the uasort decorate chain). Bail (→ unspecialized, dynamic entry) if a
        // closure can't be safely freshened (generator / static local).
        if (!$this->freshenClosures($body)) { return null; }
        return new FunctionDef(
            $specName,
            $newParams,
            $fn->returnType,
            $body,
            $fn->returnsByRef,
            $fn->isPrelude,
        );
    }

    /** LLVM-symbol-safe token for a type (no brackets / spaces / commas). */
    private function typeToken(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_INT)    { return 'int'; }
        if ($k === Type::KIND_FLOAT)  { return 'flt'; }
        if ($k === Type::KIND_STRING) { return 'str'; }
        if ($k === Type::KIND_BOOL)   { return 'bool'; }
        if ($k === Type::KIND_NULL)   { return 'null'; }
        if ($k === Type::KIND_CELL)   { return 'cell'; }
        if ($k === Type::KIND_OBJ)    { return 'obj_' . $this->sanitize($t->class ?? '?'); }
        if ($k === Type::KIND_ARRAY) {
            $elem = $t->element === null ? 'unk' : $this->typeToken($t->element);
            if ($t->isAssoc()) {
                $key = $t->key === null ? 'unk' : $this->typeToken($t->key);
                return 'assoc_' . $key . '_' . $elem;
            }
            return 'vec_' . $elem;
        }
        return 'unk';
    }

    private function sanitize(string $s): string
    {
        $out = '';
        $n = \strlen($s);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($s, $i, 1);
            $ok = ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z')
                || ($c >= '0' && $c <= '9');
            $out .= $ok ? $c : '_';
        }
        return $out;
    }

    /** True when >=1 of `$dims` is a callable param (a callable dimension). */
    private function hasCallableDim(FunctionDef $fn, array $dims): bool
    {
        foreach ($dims as $di) {
            if ($di < \count($fn->params) && $this->isCallableParam($fn->params[$di]->type)) {
                return true;
            }
        }
        return false;
    }

    /** A bare `callable` / `Closure` param (`KIND_CLOSURE`, with or without a
     *  spelled-out signature). Its invoke is DYNAMIC until specialized to a
     *  concrete closure. By-ref/variadic excluded by the dimensions() guard. */
    private function isCallableParam(Type $t): bool
    {
        return $t->kind === Type::KIND_CLOSURE;
    }

    /** A concrete closure ARGUMENT: an `obj<__closure_N>` naming a real closure
     *  fn (in `closureCaptures`). NOT a named-string / FCC / `__invoke`-object
     *  callable — retyping the param to those would break the dynamic path. */
    private function isConcreteClosure(Type $t): bool
    {
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        $c = $t->class;
        return $c !== null && isset($this->closureNames[$c]);
    }

    /** A param worth specializing: a bare array hint / untyped (`unknown`)
     *  or a vec with an unknown element.
     *
     *  NOTE (Phase B, deferred): broadening this to a CELL-element / bare
     *  `array` param DOES let `uasort(array &$arr, …)` specialize `$arr` and
     *  order correctly — but the decorated pair's values then round-trip as
     *  BOXED cells while the writeback slot stays int-typed, printing raw box
     *  bits. That is the representation-consistency root epic, not a
     *  monomorphization fix. Kept narrow until that lands. */
    private function isErasedArrayParam(Type $t): bool
    {
        if ($t->kind === Type::KIND_UNKNOWN) { return true; }
        if ($t->isArray()) {
            $e = $t->element;
            if ($e === null || $e->kind === Type::KIND_UNKNOWN || $e->kind === Type::KIND_CELL) {
                return true;
            }
        }
        return false;
    }

    /** A concretely-shaped array: an array whose element (and key, if assoc)
     *  is a definite type — not unknown / not a cell. */
    private function isConcreteArray(Type $t): bool
    {
        if (!$t->isArray()) { return false; }
        $e = $t->element;
        if ($e === null || !$this->isConcreteElem($e)) { return false; }
        if ($t->isAssoc()) {
            $key = $t->key;
            if ($key === null || !$this->isConcreteElem($key)) { return false; }
        }
        return true;
    }

    private function isConcreteElem(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_UNKNOWN || $k === Type::KIND_CELL || $k === Type::KIND_VOID) {
            return false;
        }
        // A nested array element must itself be concrete.
        if ($k === Type::KIND_ARRAY) { return $this->isConcreteArray($t); }
        return true;
    }

    /**
     * An array ARGUMENT worth specializing on: its key/element REPRESENTATION is
     * definite. Wider than {@see isConcreteArray} by exactly one case — a CELL
     * element (`assoc[string,cell]`, what any mixed-value literal like
     * `['a'=>'x','b'=>7]` types as) is a definite repr: every slot is a uniformly
     * NaN-boxed i64. Only UNKNOWN is indefinite.
     *
     * Without this a mixed-value array passed to a bare-`array` (KIND_UNKNOWN)
     * param specialized NOTHING, so the raw array pointer rode an `unknown`
     * param and an `unknown` return with no tag — and every tag-dispatching
     * consumer downstream misread it (`is_array()` false, `var_dump` printing
     * the pointer as int/denormal-float, a string element read as garbage).
     * That is the erasure the prelude array functions kept hitting: it reproduces
     * on a two-line `function f(array $a): array { return $a; }`, not just on the
     * exotic ones. A homogeneous array was always fine — it IS concrete, so it
     * specialized and stayed typed.
     */
    private function isSpecializableArray(Type $t): bool
    {
        if (!$t->isArray()) { return false; }
        $e = $t->element;
        if ($e === null || !$this->isDefiniteElem($e)) { return false; }
        if ($t->isAssoc()) {
            $key = $t->key;
            if ($key === null || !$this->isDefiniteElem($key)) { return false; }
        }
        return true;
    }

    /**
     * A variadic pack worth specializing: the declared pack element is erased
     * (`vec[unknown]`) while EVERY call site passes a pack with a definite
     * element repr. Requiring every site to agree keeps the single clone sound —
     * a site that disagrees would otherwise be repointed to a body typed for
     * someone else's pack.
     *
     * @param Call[] $calls
     */
    private function isSpecializableVariadicPack(Param $p, int $idx, array $calls): bool
    {
        if (!$this->isErasedArrayParam($p->type)) { return false; }
        // ONE definite site is enough, exactly like the erased-array branch of
        // {@see dimensions}. Demanding that EVERY site agree is self-defeating
        // for a RECURSIVE function: the erased original keeps a self-call whose
        // pack is still `vec[unknown]`, so that single site vetoed the dimension
        // for every real caller — the pack stayed erased and the body walked its
        // string keys as positional indices. Soundness comes from
        // {@see callKey}, not from this predicate: a site whose pack is not
        // specializable produces an empty key and stays on the original body.
        foreach ($calls as $call) {
            if ($idx < \count($call->args)
                && $this->isSpecializableArray($call->args[$idx]->type)) {
                return true;
            }
        }
        return false;
    }

    /** {@see isConcreteElem}, but a CELL is a definite repr. */
    private function isDefiniteElem(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_UNKNOWN || $k === Type::KIND_VOID) { return false; }
        if ($k === Type::KIND_ARRAY) { return $this->isSpecializableArray($t); }
        return true;
    }

    private function isCandidate(FunctionDef $fn): bool
    {
        if ($fn->name === '__main') { return false; }
        if ($fn->isExtern || $fn->isGenerator) { return false; }
        if ($fn->ffiSymbol !== null) { return false; }
        // Already a specialization (`f$mono$…`) — its params are concrete, so
        // it is never a candidate; this also keeps the worklist terminating.
        if (\str_contains($fn->name, '$mono$')) { return false; }
        // A leading `this` param marks a lowered method — skip (free functions
        // only; object layout / dispatch is out of scope).
        if (\count($fn->params) > 0 && $fn->params[0]->name === 'this') { return false; }
        if ($this->bodyHasUnsupported($fn->body)) { return false; }
        return true;
    }

    /** A body that DEFINES a closure (captures the enclosing scope) or a static
     *  local (a module global a clone must not duplicate), or yields, can't be
     *  cloned safely. A dynamic INVOKE of a passed-in callable IS fine — the
     *  callee is a parameter, not duplicated (the array_map / array_filter /
     *  usort callback-taker shape, which the callable dimension specializes).
     *
     *  Phase B (deferred) would relax the closure case and let
     *  {@see freshenClosures} give each clone its own `__closure_N'`; it is kept
     *  rejecting for now because the transitive case it unlocks (uasort's
     *  decorate) needs the representation-consistency root fix to be correct —
     *  see {@see isErasedArrayParam}. The freshening machinery stays in place,
     *  dormant, so it never fires while this rejects closure bodies. */
    private function bodyHasUnsupported(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_STATIC_LOCAL_DECL || $k === Node::KIND_YIELD) {
            return true;
        }
        if ($k === Node::KIND_CLOSURE) { return false; }
        foreach (Walk::children($n) as $c) {
            if ($this->bodyHasUnsupported($c)) { return true; }
        }
        return false;
    }

    /**
     * Freshen every closure DEFINED in a cloned body `$n`: mint a fresh
     * `__closure_N'` FunctionDef (a deep copy) per Closure_ literal and repoint
     * the literal to it, so this clone OWNS its closures. A shared closure fn
     * would be typed by the UNION of every clone's capture site, collapsing a
     * captured callable's concrete type back to bare and re-opening the dynamic
     * misbox. Mutates Closure_ nodes in place; registers fresh fns in
     * {@see $newClosureFns}. Returns false when a closure cannot be freshened
     * safely (unknown fn / generator / static-local body) — the caller then
     * leaves the whole function unspecialized (the dynamic entry stays correct).
     */
    private function freshenClosures(Node $n): bool
    {
        if ($n instanceof Closure_) {
            if (!$this->freshenOneClosure($n)) { return false; }
        }
        foreach (Walk::children($n) as $c) {
            if (!$this->freshenClosures($c)) { return false; }
        }
        return true;
    }

    private function freshenOneClosure(Closure_ $node): bool
    {
        $oldName = '__closure_' . (string)$node->id;
        $orig = $this->fnByName[$oldName] ?? null;
        if ($orig === null || $orig->isGenerator) { return false; }
        try {
            $clBody = NodeClone::block($orig->body);
        } catch (\Throwable $e) {
            return false;
        }
        if ($this->bodyHasStaticLocal($clBody)) { return false; }
        // Nested closures inside this one get their own fresh ids too.
        if (!$this->freshenClosures($clBody)) { return false; }

        $newId = $this->nextClosureId;
        $this->nextClosureId = $this->nextClosureId + 1;
        $newName = '__closure_' . (string)$newId;
        $newParams = [];
        foreach ($orig->params as $p) {
            $np = new Param($p->name, $p->type, $p->byRef, $p->variadic, $p->default);
            // `arrayHinted` is not part of the constructor, so it has to be
            // carried across explicitly — exactly as cloneWith does. A freshened
            // closure that lost it stopped having its cell/erased arguments
            // untagged at the call site, since the whole array-hint mask is keyed
            // off this flag.
            $np->arrayHinted = $p->arrayHinted;
            $np->docList = $p->docList;
            $newParams[] = $np;
        }
        $clFn = new FunctionDef($newName, $newParams, $orig->returnType, $clBody, $orig->returnsByRef, $orig->isPrelude);

        $m = $this->module;
        $m->closureCaptures[$newName] = $m->closureCaptures[$oldName] ?? \count($node->captures);
        $m->closureHasThis[$newName] = $m->closureHasThis[$oldName] ?? false;
        $this->fnByName[$newName] = $clFn;
        $this->closureNames[$newName] = true;
        $this->newClosureFns[] = $clFn;

        // Repoint the literal (id drives the fn name at emit; type->class drives
        // the KNOWN-invoke resolution).
        $node->id = $newId;
        $node->type = Type::obj($newName);
        return true;
    }

    /** A STATIC_LOCAL_DECL anywhere in `$n` EXCEPT inside a nested closure (whose
     *  body is a separate FunctionDef, not a child here). */
    private function bodyHasStaticLocal(Node $n): bool
    {
        if ($n->kind === Node::KIND_STATIC_LOCAL_DECL) { return true; }
        if ($n->kind === Node::KIND_CLOSURE) { return false; }
        foreach (Walk::children($n) as $c) {
            if ($this->bodyHasStaticLocal($c)) { return true; }
        }
        return false;
    }

    private function collectCalls(Node $n): void
    {
        if ($n->kind === Node::KIND_CALL) {
            if (!isset($this->callsByName[$n->function])) {
                $this->callsByName[$n->function] = [];
            }
            $this->callsByName[$n->function][] = $n;
        }
        foreach (Walk::children($n) as $c) { $this->collectCalls($c); }
    }
}
