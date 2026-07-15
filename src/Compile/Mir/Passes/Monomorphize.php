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
            if (!$this->runOnce($module)) { break; }
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
        $hasCallableDim = $this->hasCallableDim($fn, $dims);
        if (!$hasCallableDim && \count($calls) < 2) { return []; }

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
        // array dim: keep >=2 distinct keys (the genuinely-erased case).
        $minKeys = $hasCallableDim ? 1 : 2;
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
            $clone = $this->cloneWith($fn, $specName, $repCall, $dims);
            if ($clone === null) { return []; }
            $keyToName[$key] = $specName;
            $clones[] = $clone;
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
            if ($p->variadic) { continue; }
            // A dimension is either an erased-array param receiving a concrete
            // array, or a bare `callable` param receiving a concrete closure at
            // >=1 site. Retyping the callable param to the closure's obj type
            // makes its internal invoke KNOWN (the milestone cellify then fires).
            if ($this->isErasedArrayParam($p->type)) {
                foreach ($calls as $call) {
                    if ($idx < \count($call->args)
                        && $this->isConcreteArray($call->args[$idx]->type)) {
                        $dims[] = $idx;
                        break;
                    }
                }
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
            if (!$this->isConcreteArray($t) && !$this->isConcreteClosure($t)) { return ''; }
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
            $np->arrayHinted = $p->arrayHinted;
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
        if ($t->isVec()) {
            $e = $t->element;
            return $e === null || $e->kind === Type::KIND_UNKNOWN;
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
        if ($k === Node::KIND_CLOSURE
            || $k === Node::KIND_STATIC_LOCAL_DECL || $k === Node::KIND_YIELD) {
            return true;
        }
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
            $newParams[] = new Param($p->name, $p->type, $p->byRef, $p->variadic, $p->default);
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
