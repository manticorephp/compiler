<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Block;
use Compile\Mir\Concat;
use Compile\Mir\CondOwn;
use Compile\Mir\Continue_;
use Compile\Mir\DoWhile_;
use Compile\Mir\Flow\Forward;
use Compile\Mir\Flow\OwnLattice;
use Compile\Mir\For_;
use Compile\Mir\Foreach_;
use Compile\Mir\FunctionDef;
use Compile\Mir\If_;
use Compile\Mir\IncDec;
use Compile\Mir\LoadLocal;
use Compile\Mir\MemoryOp_;
use Compile\Mir\MirCatch;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Ownership;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
use Compile\Mir\StoreLocal;
use Compile\Mir\Switch_;
use Compile\Mir\TryCatch_;
use Compile\Mir\Type;
use Compile\Mir\Unset_;
use Compile\Mir\VecCopyOnAssign;
use Compile\Mir\Walk;

/**
 * Ownership of rc LOCALS per program point (`MANTICORE_OWNFLOW=1`).
 *
 * A {@see Forward} walk over {@see OwnLattice}: every managed local is Empty,
 * Borrow(k), Scalar, MixDead or Own(k) at each point — k a release class (the
 * flavor plus the slot type a drop releases by) — and a store takes the state
 * {@see Ownership::classifyStored} answers for its value, read against the
 * SLOT's representation. The plan is written down as ops the emitter executes:
 *
 *  - `drop` of the OLD value on a store reached by Own ({@see StoreLocal::$ownOld},
 *    run after the new value is computed), before an `unset`, at every return
 *    ({@see Return_::$ownDrops}, after the value and any finally) and on the
 *    fall-through end; an owned returned local MOVES ({@see Return_::$ownMove});
 *  - `own_retain` where Borrow(k) meets Own(k) at a join, on the borrowed edge;
 *    where no edge has a statement position (a catch entry, a loop exit, a
 *    switch dispatch, an expression arm) the name is FORCED instead: every
 *    borrowed store / binding / param of it takes its +1 on the spot;
 *  - `own_retain` before a self-append of a borrowed string (the append
 *    consumes the old reference) and after an array alias of another local (a
 *    borrow of a buffer its owner may drop first);
 *  - where two representations meet at a join (a loop re-binding a name at
 *    another element kind), the name is MixDead past it — nothing may read it —
 *    and each owned side is dropped on its edge; a read of it, or an edge with
 *    no place for the drop, is reported through {@see Verify};
 *  - the foreach binding's co-ownership and its drop of the value / key the
 *    slot still holds ({@see Foreach_::$ownCoOwn}, `$ownDropValue`, `$ownDropKey`);
 *  - one `own_local` (`own_local_b` when some source borrows) registration per
 *    name — the release class the emitter's remaining per-name questions
 *    (foreach retain depth, MIXED flag slot, an array base's release type,
 *    element-owning releases) read.
 *
 * PHP locals are function-scoped, so a break / continue / goto edge needs only
 * compensation; the previous value is dropped at the next overwrite or exit.
 * Names reachable through a reference (`&`, `static`, a by-ref capture or
 * foreach) are left to their storage; a MIXED slot keeps InsertMemoryOps'
 * verdict and releases through its representation flag.
 */
final class OwnershipFlow implements Pass
{
    public const NAME = 'ownership-flow';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [SpillFreshBases::NAME]; }

    private ?Ownership $own = null;
    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $classes = [];
    /** @var array<string, \Compile\Mir\EnumDef> */
    private array $enums = [];
    /** @var array<string, bool> */
    private array $closureFns = [];
    /** @var string[] */
    private array $errors = [];

    /** @var array<string, array<string, Type>> fn → MIXED name → its raw slot type */
    private array $mixed = [];

    // ── per function ───────────────────────────────────────────────
    private string $fnName = '';
    /** False for a function {@see \Compile\Debug::$ownFlowOnly} leaves out: it
     *  keeps its registrations and every retain the conventions need, and gets
     *  no drop (a leak, never a free). */
    private bool $releases = true;
    private bool $trace = false;
    /** @var array<string, bool> */
    private array $excluded = [];
    /** @var array<string, Type> */
    private array $mixedHere = [];
    /** @var array<string, bool> */
    private array $mutatedVecs = [];
    /** @var array<string, bool> */
    private array $erasedPropNames = [];

    /** @var array<string, int> "name#class" → release class id */
    private array $keyId = [];
    /** @var array<int, string> class id → flavor (the InsertMemoryOps vocabulary) */
    private array $keyFlavor = [];
    /** @var array<int, Type> class id → the slot type it releases by */
    private array $keyType = [];
    /** @var array<int, string> class id → the local it belongs to */
    private array $keyOwner = [];
    /** @var array<int, string> class id → its class string */
    private array $keyClass = [];
    /** @var array<string, int> name → first release class seen */
    private array $firstKey = [];
    /** @var array<string, int> name → first OWNED release class seen */
    private array $firstOwnKey = [];
    /** @var array<string, int> name → release class of a co-owning foreach */
    private array $feKeyOf = [];

    /** @var array<int, bool> statement ids that sit in a statement list */
    private array $inList = [];
    /** @var array<int, MemoryOp_[]> */
    private array $insBefore = [];
    /** @var array<int, MemoryOp_[]> */
    private array $insAfter = [];
    /** @var array<int, MemoryOp_[]> Block id → ops appended */
    private array $insEnd = [];
    /** @var array<int, MemoryOp_[]> Block id → ops prepended */
    private array $insStart = [];
    /** @var array<int, MemoryOp_[]> If_ id → ops for its (new) else */
    private array $insElse = [];
    /** @var array<int, MemoryOp_[]> MirCatch id → ops prepended to its body */
    private array $insCatch = [];
    /** @var array<string, bool> dedupe of edge fixes */
    private array $placed = [];
    /** @var array<int, bool> ops this function inserted for compensation */
    private array $inserted = [];

    /** @var array<int, StoreLocal> */
    private array $storeById = [];
    /** @var array<int, Unset_> */
    private array $unsetById = [];
    /** @var array<int, Foreach_> */
    private array $feById = [];
    /** @var array<int, MirCatch> */
    private array $catchById = [];
    /** @var array<string, int> managed name → the release class it registers */
    private array $regKey = [];
    /** @var array<int, bool> array local-to-local alias stores that take a +1 */
    private array $aliasRetain = [];

    /** @param array<string, array<string, Type>> $mixed {@see InsertMemoryOps::$mixedVerdict} */
    public function __construct(array $mixed = [])
    {
        $this->mixed = $mixed;
    }

    public function run(Module $module): Module
    {
        $this->classes = $module->classes;
        $this->enums = $module->enums;
        $this->closureFns = [];
        foreach ($module->closureCaptures as $name => $unused) { $this->closureFns[$name] = true; }
        $this->own = new Ownership(\Compile\Mir\OwnershipContext::fromModule($module));
        $this->errors = [];
        foreach ($module->functions as $fn) {
            $this->lowerFunction($fn);
        }
        foreach ($this->errors as $e) { $module->ownFlowErrors[] = $e; }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /** {@see \Compile\Debug::$ownFlowOnly} */
    private static function bisectAdmits(string $fn): bool
    {
        $spec = \Compile\Debug::$ownFlowOnly;
        if ($spec === '') { return true; }
        $neg = \str_starts_with($spec, '!');
        if ($neg) { $spec = \substr($spec, 1); }
        $hit = false;
        foreach (\explode(',', $spec) as $part) {
            if ($part !== '' && \str_contains($fn, $part)) { $hit = true; }
        }
        return $neg ? !$hit : $hit;
    }

    private function lowerFunction(FunctionDef $fn): void
    {
        $this->fnName = $fn->name;
        $this->releases = self::bisectAdmits($fn->name);
        $want = \getenv('MANTICORE_OWN_TRACE');
        $this->trace = $want !== false && $want !== '' && \str_contains($fn->name, $want);
        $this->excluded = [];
        $this->mixedHere = $this->mixed[$fn->name] ?? [];
        $this->mutatedVecs = VecCopyOnAssign::mutatedLocals($fn->body);
        $this->erasedPropNames = [];
        $this->keyId = [];
        $this->keyFlavor = [];
        $this->keyType = [];
        $this->keyOwner = [];
        $this->keyClass = [];
        $this->firstKey = [];
        $this->firstOwnKey = [];
        $this->feKeyOf = [];
        $this->storeById = [];
        $this->unsetById = [];
        $this->feById = [];
        $this->catchById = [];
        $this->regKey = [];
        $this->aliasRetain = [];
        $this->inserted = [];
        $this->insName = [];
        $this->insKey = [];
        $this->insDrop = [];
        $this->skipLoad = [];
        $this->madeElse = [];

        foreach ($fn->params as $p) {
            if ($p->byRef) { $this->excluded[$p->name] = true; }
        }
        $this->collectExcluded($fn->body);

        $lat = new OwnLattice();
        $this->scan($fn->body, $lat);
        $this->refineFromLoads($fn->body);

        // Param entry: a by-value param arrives BORROWED; a prologue-copied
        // array is the frame's own; a `mixed` param written through as an
        // array must take its own share before the first write, or the
        // copy-on-write behind it sees the caller's rc 1 and writes through.
        $isClosure = isset($this->closureFns[$fn->name]);
        $mutatedAny = VecCopyOnAssign::mutatedLocalsAnyType($fn->body);
        /** @var array<string, int> $entry */
        $entry = [];
        /** @var array<string, int> $entryRetain name → class */
        $entryRetain = [];
        foreach ($fn->params as $p) {
            if ($p->byRef || isset($this->excluded[$p->name])) { continue; }
            $ks = $this->keyString($p->type);
            if ($ks === '') { continue; }
            $k = $this->intern($p->name, $ks, $p->type);
            if ($p->type->kind !== Type::KIND_CELL && VecCopyOnAssign::paramCopiedOnEntry($fn, $p, $isClosure)) {
                $entry[$p->name] = $k;
                $this->noteOwnKey($p->name, $k);
                continue;
            }
            if (!$p->variadic && $p->type->kind === Type::KIND_CELL && isset($mutatedAny[$p->name])) {
                $entry[$p->name] = $k;
                $entryRetain[$p->name] = $k;
                continue;
            }
            $entry[$p->name] = OwnLattice::borrow($k);
        }
        foreach ($this->firstKey as $name => $k0) {
            $k = $k0;
            if (isset($this->feKeyOf[$name])) {
                $k = $this->feKeyOf[$name];
            } elseif (isset($this->firstOwnKey[$name])) {
                $k = $this->firstOwnKey[$name];
            }
            $this->regKey[$name] = $k;
        }
        if (\count($this->regKey) === 0) { return; }
        // The emitter retains a co-owning binding at the NAME's registered
        // class; a loop whose element class is another one binds a borrow.
        foreach ($lat->feValName as $id => $name) {
            $st = $lat->feValState[$id];
            if ($st > 0 && ($this->regKey[$name] ?? 0) !== $st) {
                $lat->feValState[$id] = OwnLattice::borrow($st);
            }
        }

        $body = $fn->body;
        $this->inList = [];
        $this->indexLists($body);

        /** @var array<string, bool> $force */
        $force = [];
        $final = null;
        /** @var array<string, string> $stuck name → the edge no mismatch drop fits */
        $stuck = [];
        /** @var array<string, int> $deadRead read past a mismatch — seen before the drops */
        $deadRead = [];
        /** @var array<string, string> $mismatch */
        $mismatch = [];
        for ($round = 0; $round < 256; $round++) {
            $this->resetPlan();
            $stuck = [];
            $l1 = $this->runFlow($body, $lat, $entry, $force);
            if ($l1 === null) { return; }
            $deadRead = $l1->deadRead;
            $mismatch = $l1->mismatch;
            $newForce = false;
            $n = \count($l1->fixKind);
            for ($i = 0; $i < $n; $i++) {
                $name = $l1->fixName[$i];
                $op = $l1->fixOp[$i];
                if ($op === 'own_retain' && isset($force[$name])) { continue; }
                if ($this->placeFix($op, $l1->fixKind[$i], $l1->fixAt[$i], $l1->fixPred[$i],
                        $l1->fixNoPred[$i], $name, $l1->fixKey[$i])) {
                    continue;
                }
                if ($op === 'own_retain') {
                    $force[$name] = true;
                    $newForce = true;
                } else {
                    $stuck[$name] = $l1->fixKind[$i];
                }
            }
            if ($newForce) { continue; }
            if (\count($l1->conflicts) === 0 && $n === 0) {
                $final = $l1;
                break;
            }
            $this->applyInsertions($body);
            $l2 = $this->runFlow($body, $lat, $entry, $force);
            if ($l2 === null) { return; }
            $bad = false;
            foreach ($l2->conflicts as $name => $unused) {
                $force[$name] = true;
                $bad = true;
            }
            foreach ($l2->doubleRetain as $name => $unused) {
                $force[$name] = true;
                $bad = true;
            }
            if (!$bad) {
                $final = $l2;
                break;
            }
            $this->removeInserted($body);
            $this->inserted = [];
        }
        if ($final === null) {
            $this->errors[] = 'ownflow: ' . $fn->name . ': compensation did not converge';
            return;
        }
        foreach ($stuck as $name => $kind) {
            $what = $mismatch[$name] ?? '?';
            $this->errors[] = 'ownflow: flavor mismatch at a join in ' . $fn->name . ': $' . $name
                . ' (' . $what . ' — ' . $this->describe($what) . '), and its ' . $kind
                . ' edge has no place for the drop';
        }
        foreach ($deadRead as $name => $line) {
            $what = $mismatch[$name] ?? '?';
            $this->errors[] = 'ownflow: $' . $name . ' read at line ' . (string)$line . ' in ' . $fn->name
                . ' past a representation mismatch (' . $this->describe($what) . ')';
        }
        if (\Compile\Stats::$on) { \Compile\Stats::bump('own.flow.mismatch', \count($mismatch)); }
        foreach ($mismatch as $name => $what) {
            $this->say(0, $name, OwnLattice::MIXDEAD, 'mismatch ' . $this->describe($what));
        }
        $this->resetPlan();
        if (!$this->releases) {
            // Bisect: keep every retain (a self-append or a copy-on-write
            // downstream relies on the +1), lose every drop.
            $keep = [];
            foreach ($this->inserted as $id => $unused) {
                if (!$this->insDrop[$id]) { $keep[$id] = true; }
            }
            foreach ($keep as $id => $unused) { unset($this->inserted[$id]); }
            $this->removeInserted($body);
            $this->inserted = $keep;
        }
        $this->finish($fn, $final, $force, $entry, $entryRetain);
    }

    private function describe(string $what): string
    {
        $out = [];
        foreach (\explode(' vs ', $what) as $part) {
            if (\str_starts_with($part, 'Own:')) {
                $k = (int)\substr($part, 4);
                $out[] = $this->keyClass[$k] ?? '?';
            } else {
                $out[] = \strtolower($part);
            }
        }
        return \implode(' vs ', $out);
    }

    /**
     * @param array<string, int> $entry
     * @param array<string, bool> $force
     */
    private function runFlow(Block $body, OwnLattice $proto, array $entry, array $force): ?OwnLattice
    {
        $l = $this->cloneLattice($proto);
        $l->force = $force;
        foreach ($entry as $n => $s) {
            $l->entryState[$n] = (OwnLattice::isBorrow($s) && isset($force[$n]))
                ? OwnLattice::borrowKey($s) : $s;
        }
        foreach ($this->inserted as $id => $unused) {
            $l->opName[$id] = $this->insName[$id];
            $l->opKey[$id] = $this->insKey[$id];
            $l->opDrop[$id] = $this->insDrop[$id];
        }
        $fw = new Forward($l);
        try {
            $fw->run($body);
        } catch (\LogicException $e) {
            $this->errors[] = 'ownflow: ' . $this->fnName . ': ' . $e->getMessage();
            return null;
        }
        return $l;
    }

    /** A fresh lattice carrying the precomputed per-node effects of `$p`. */
    private function cloneLattice(OwnLattice $p): OwnLattice
    {
        $l = new OwnLattice();
        $l->storeName = $p->storeName;
        $l->storeState = $p->storeState;
        $l->storeKey = $p->storeKey;
        $l->storeMode = $p->storeMode;
        $l->unsetNames = $p->unsetNames;
        $l->feValName = $p->feValName;
        $l->feValState = $p->feValState;
        $l->feValKey = $p->feValKey;
        $l->feKeyName = $p->feKeyName;
        $l->feKeyState = $p->feKeyState;
        $l->feKeyKey = $p->feKeyKey;
        $l->catchName = $p->catchName;
        $l->catchKey = $p->catchKey;
        $l->loadName = $p->loadName;
        foreach ($this->keyClass as $k => $cls) {
            if ($cls === 'cell' || $cls === 'mix') { $l->cellish[$k] = true; }
        }
        return $l;
    }

    // ── release classes ────────────────────────────────────────────

    /** The release class of a slot of this type, or '' when it is not rc. */
    private function keyString(Type $t): string
    {
        if ($t->kind === Type::KIND_UNKNOWN) { return ''; }
        $c = $this->own->flavorOf($t);
        if ($c <= 0) { return ''; }
        if ($t->kind === Type::KIND_ARRAY) {
            $el = $t->element;
            if ($el === null || $el->kind !== Type::KIND_ARRAY) { return 'arr'; }
            $name = 'arr';
            $cur = $el;
            while ($cur !== null && $cur->kind === Type::KIND_ARRAY) {
                $name = $name . ':arr';
                $cur = $cur->element;
            }
            return $name . ':' . ($cur === null ? '?' : $cur->kind);
        }
        return Ownership::flavorName($c);
    }

    private function flavorFor(string $ks, Type $t): string
    {
        if (\str_starts_with($ks, 'arr')) { return $t->isAssoc() ? 'assoc' : 'vec'; }
        return $ks;
    }

    private function intern(string $name, string $ks, Type $t): int
    {
        if (isset($this->mixedHere[$name])) {
            $raw = $this->mixedHere[$name];
            $ks = 'mix';
            $t = $raw;
        }
        $key = $name . '#' . $ks;
        if (isset($this->keyId[$key])) {
            $k = $this->keyId[$key];
            if ($ks !== 'mix' && !isset($this->erasedPropNames[$name])
                && $this->refinesElement($this->keyType[$k], $t)) {
                $this->keyType[$k] = $t;
                $this->keyFlavor[$k] = $this->flavorFor($ks, $t);
            }
            return $k;
        }
        $k = \count($this->keyFlavor) + 1;
        $this->keyId[$key] = $k;
        if ($ks === 'mix') {
            $rawKs = $this->keyString($t);
            $this->keyFlavor[$k] = 'mix' . $this->flavorFor($rawKs, $t);
        } else {
            $this->keyFlavor[$k] = $this->flavorFor($ks, $t);
        }
        $this->keyType[$k] = $t;
        $this->keyOwner[$k] = $name;
        $this->keyClass[$k] = $ks;
        if (!isset($this->firstKey[$name])) { $this->firstKey[$name] = $k; }
        return $k;
    }

    private function noteOwnKey(string $name, int $k): void
    {
        if (!isset($this->firstOwnKey[$name])) { $this->firstOwnKey[$name] = $k; }
    }

    /** `vec[unknown]` deepened to a concrete obj / string element — the one
     *  upgrade {@see InsertMemoryOps::refinesElement} accepts. */
    private function refinesElement(Type $old, Type $new): bool
    {
        if (!\Compile\Debug::$rcElemType) { return false; }
        if (!$old->isArray() || !$new->isArray()) { return false; }
        $oe = $old->element;
        $ne = $new->element;
        if ($oe === null || $ne === null) { return false; }
        if ($oe->kind !== Type::KIND_UNKNOWN) { return false; }
        return $ne->kind === Type::KIND_OBJ || $ne->kind === Type::KIND_STRING;
    }

    // ── the scan: per-node effects ─────────────────────────────────

    /**
     * Names this pass leaves alone: bound to other storage or reachable
     * through a reference — `static`, either side of `$r = &$x`, a `&f()` /
     * `&$a[k]` binding, a reference cell's root, a by-ref capture, a by-ref
     * foreach variable, an `++`/`--` target. A write through the alias reaches
     * the slot without a StoreLocal the flow could see.
     */
    private function collectExcluded(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $this->excluded[self::asStaticLocalDecl($n)->name] = true;
        } elseif ($k === Node::KIND_REF_ALIAS) {
            $ra = self::asRefAlias($n);
            $this->excluded[$ra->target] = true;
            $this->excluded[$ra->source] = true;
        } elseif ($k === Node::KIND_REF_BIND) {
            $this->excluded[self::asRefBind($n)->target] = true;
        } elseif ($k === Node::KIND_REF_ADDR) {
            $this->excluded[self::asRefAddr($n)->target] = true;
        } elseif ($k === Node::KIND_REF_CELL) {
            $src = self::asRefCell($n)->refSource;
            if ($src->kind === Node::KIND_LOAD_LOCAL) { $this->excluded[self::asLoadLocal($src)->name] = true; }
        } elseif ($k === Node::KIND_CLOSURE) {
            $cl = self::asClosure($n);
            $i = 0;
            foreach ($cl->captures as $cap) {
                if (($cl->captureByRef[$i] ?? false) && $cap->kind === Node::KIND_LOAD_LOCAL) {
                    $this->excluded[self::asLoadLocal($cap)->name] = true;
                }
                $i = $i + 1;
            }
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            if ($fe->byRef) { $this->excluded[$fe->valueVar] = true; }
        } elseif ($k === Node::KIND_INCDEC) {
            $this->excluded[self::asIncDec($n)->name] = true;
        }
        foreach (Walk::children($n) as $c) { $this->collectExcluded($c); }
    }

    private function scan(Node $n, OwnLattice $lat): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = self::asStoreLocal($n);
            $this->storeById[\spl_object_id($sl)] = $sl;
            $this->scanStore($sl, $lat);
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = self::asForeach($n);
            $this->feById[\spl_object_id($fe)] = $fe;
            $this->scanForeach($fe, $lat);
        } elseif ($k === Node::KIND_LOAD_LOCAL) {
            $ll = self::asLoadLocal($n);
            $lid = \spl_object_id($ll);
            if (!isset($this->skipLoad[$lid]) && !isset($this->excluded[$ll->name])
                && $this->keyString($ll->type) !== '') {
                $lat->loadName[$lid] = $ll->name;
            }
        } elseif ($k === Node::KIND_MEMORY_OP) {
            $mt = self::asMemoryOp($n)->target;
            if ($mt !== null) { $this->skipLoad[\spl_object_id($mt)] = true; }
        } elseif ($k === Node::KIND_UNSET) {
            $this->unsetById[\spl_object_id($n)] = self::asUnset($n);
            foreach (self::asUnset($n)->targets as $t) { $this->skipLoad[\spl_object_id($t)] = true; }
            $names = [];
            foreach (self::asUnset($n)->targets as $t) {
                if ($t->kind !== Node::KIND_LOAD_LOCAL) { continue; }
                $nm = self::asLoadLocal($t)->name;
                if (!isset($this->excluded[$nm])) { $names[] = $nm; }
            }
            if (\count($names) > 0) { $lat->unsetNames[\spl_object_id($n)] = $names; }
        } elseif ($k === Node::KIND_TRY_CATCH) {
            foreach (self::asTryCatch($n)->catches as $c) {
                $v = $c->var;
                if ($v === null || isset($this->excluded[$v])) { continue; }
                $cls = \count($c->types) > 0 ? $c->types[0] : 'Throwable';
                $t = Type::obj($cls);
                $ks = $this->keyString($t);
                if ($ks === '') { continue; }
                $cid = \spl_object_id($c);
                $this->catchById[$cid] = $c;
                $lat->catchName[$cid] = $v;
                $lat->catchKey[$cid] = $this->intern($v, $ks, $t);
            }
        }
        foreach (Walk::children($n) as $c) { $this->scan($c, $lat); }
    }

    private function scanStore(StoreLocal $sl, OwnLattice $lat): void
    {
        $name = $sl->name;
        if (isset($this->excluded[$name])) { return; }
        $id = \spl_object_id($sl);
        $v = $sl->value;
        $slotT = InsertMemoryOps::slotStoredType($sl);
        $boxed = $slotT->kind === Type::KIND_CELL;
        if ($slotT->kind === Type::KIND_UNKNOWN && $this->own->erasedArrayPropRead($v)) {
            $slotT = Type::vec(Type::unknown());
            $this->erasedPropNames[$name] = true;
        }
        $ks = $this->keyString($slotT);
        $isMixed = isset($this->mixedHere[$name]);
        if ($ks === '') {
            $nullish = $v->kind === Node::KIND_NULL_CONST || $v->type->kind === Type::KIND_NULL
                || $slotT->kind === Type::KIND_NULL || $slotT->kind === Type::KIND_VOID;
            // A MIXED slot's release reads its representation flag, which
            // this store sets to "not a raw rc pointer": nothing to avoid.
            $st = ($nullish || $isMixed) ? OwnLattice::EMPTY : OwnLattice::SCALAR;
            $lat->storeName[$id] = $name;
            $lat->storeState[$id] = $st;
            $lat->storeKey[$id] = 0;
            $lat->storeMode[$id] = OwnLattice::PLAIN;
            return;
        }
        $key = $this->intern($name, $ks, $slotT);
        $lat->storeName[$id] = $name;
        $lat->storeKey[$id] = $key;
        $lat->storeState[$id] = OwnLattice::EMPTY;
        if ($boxed && $v->kind === Node::KIND_LOAD_LOCAL && self::asLoadLocal($v)->name === $name
            && $v->type->kind !== Type::KIND_CELL) {
            $lat->storeMode[$id] = OwnLattice::SELF_MOVE;
            // The merge box-back InferTypes plants to reconcile a slot's
            // representation reads the name to re-tag it, not to use it.
            $this->skipLoad[\spl_object_id($v)] = true;
            return;
        }
        if ($v->kind === Node::KIND_LOAD_LOCAL && self::asLoadLocal($v)->name === $name) {
            $lat->storeMode[$id] = OwnLattice::SELF_COPY;
            $this->skipLoad[\spl_object_id($v)] = true;
            return;
        }
        if (!$boxed && $this->isSelfAppend($sl)) {
            $lat->storeMode[$id] = OwnLattice::SELF_APPEND;
            $this->noteOwnKey($name, $key);
            return;
        }
        $lat->storeMode[$id] = OwnLattice::PLAIN;
        $c = $this->own->classifyStored($v);
        // TWIN-DRIFT (empty-class union conditional): the store side says
        // owned, but the emitter's arm retain follows the TEMP side — own only
        // what both sides agree was retained.
        if ($c > 0 && CondOwn::isConditional($v) && !$this->own->condOwnedTemp($v)) {
            $c = Ownership::BORROW;
        }
        $ownedByRetain = $v->kind === Node::KIND_PROPERTY_ACCESS
            || (\Compile\Debug::$rcElemReadOwns && $v->kind === Node::KIND_ARRAY_ACCESS)
            || ($v->kind === Node::KIND_STATIC_PROP && $v->type->isVec());
        $ownedCopy = !$boxed && VecCopyOnAssign::copies($v, $name, $this->mutatedVecs);
        if (!$boxed && $v->kind === Node::KIND_LOAD_LOCAL
            && InsertMemoryOps::arrayAliasCoOwns($v->type, $sl->type, $this->enums, $this->classes)) {
            $ownedCopy = true;
        }
        // The merge box-back arm of the emitter returns before the retain a
        // property / element / static-vec read owns by: such a store borrows.
        if (($c > 0 || $ownedCopy) && !($ownedByRetain && $boxed)) {
            $lat->storeState[$id] = $key;
            $this->noteOwnKey($name, $key);
            return;
        }
        // `$b = $a` between array locals that neither copies nor co-owns: a
        // borrow of ANOTHER LOCAL's buffer dies the moment that local drops
        // it (overwrite, unset, exit). php's array is a value, so the
        // destination takes its own count — retained after the store at the
        // depth its drop gives back.
        if ($c === Ownership::BORROW && !$boxed && $v->kind === Node::KIND_LOAD_LOCAL
            && self::asLoadLocal($v)->name !== $name && \str_starts_with($ks, 'arr')) {
            $lat->storeState[$id] = $key;
            $this->aliasRetain[$id] = true;
            $this->noteOwnKey($name, $key);
            return;
        }
        $lat->storeState[$id] = OwnLattice::stateOf($c === Ownership::NONE ? Ownership::NONE : Ownership::BORROW, $key);
    }

    /** `$s = $s . …` on a string slot — the emitter's in-place append, which
     *  CONSUMES the old reference. */
    private function isSelfAppend(StoreLocal $sl): bool
    {
        $v = $sl->value;
        if ($v->kind !== Node::KIND_CONCAT || $v->type->kind !== Type::KIND_STRING) { return false; }
        $leaf = $v;
        while ($leaf->kind === Node::KIND_CONCAT) { $leaf = self::asConcat($leaf)->left; }
        return $leaf->kind === Node::KIND_LOAD_LOCAL
            && $leaf->type->kind === Type::KIND_STRING
            && self::asLoadLocal($leaf)->name === $sl->name;
    }

    private function scanForeach(Foreach_ $fe, OwnLattice $lat): void
    {
        $id = \spl_object_id($fe);
        $val = $fe->valueVar;
        if (!$fe->byRef && !isset($this->excluded[$val])) {
            $feOn = \Compile\Debug::$feOnly === '' || \str_contains($this->fnName, \Compile\Debug::$feOnly);
            $et = $feOn ? InsertMemoryOps::foreachValueSlotType($fe, $this->enums, $this->classes) : null;
            if ($et !== null && $this->keyString($et) !== '') {
                $k = $this->intern($val, $this->keyString($et), $et);
                $lat->feValName[$id] = $val;
                $lat->feValState[$id] = $k;
                $lat->feValKey[$id] = $k;
                if (!isset($this->feKeyOf[$val])) { $this->feKeyOf[$val] = $k; }
                $this->noteOwnKey($val, $k);
            } else {
                $vt = $fe->array->type->element;
                $this->bindBorrow($lat, $id, $val, $vt === null ? Type::unknown() : $vt, true);
            }
        }
        $key = $fe->keyVar;
        if ($key !== null && !isset($this->excluded[$key])) {
            $at = $fe->array->type;
            $kt = $at->isArray() ? $at->key : null;
            if ($kt === null || $kt->kind === Type::KIND_UNKNOWN) { $kt = Type::cell(); }
            $this->bindBorrow($lat, $id, $key, $kt, false);
        }
    }

    private function bindBorrow(OwnLattice $lat, int $id, string $name, Type $t, bool $value): void
    {
        $ks = $this->keyString($t);
        $st = OwnLattice::EMPTY;
        $k = 0;
        if ($ks === '') {
            $sk = $t->kind;
            if ($sk === Type::KIND_NULL || $sk === Type::KIND_VOID || isset($this->mixedHere[$name])) {
                $st = OwnLattice::EMPTY;
            } else {
                $st = OwnLattice::SCALAR;
            }
        } else {
            $k = $this->intern($name, $ks, $t);
            $st = OwnLattice::borrow($k);
        }
        if ($value) {
            $lat->feValName[$id] = $name;
            $lat->feValState[$id] = $st;
            $lat->feValKey[$id] = $k;
        } else {
            $lat->feKeyName[$id] = $name;
            $lat->feKeyState[$id] = $st;
            $lat->feKeyKey[$id] = $k;
        }
    }

    /** A LOAD carries the refined element type `$a = []` never had. */
    private function refineFromLoads(Node $n): void
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL && $n->type->isArray()) {
            $name = self::asLoadLocal($n)->name;
            if (!isset($this->erasedPropNames[$name]) && !isset($this->mixedHere[$name])) {
                $ks = $this->keyString($n->type);
                if ($ks !== '' && isset($this->keyId[$name . '#' . $ks])) {
                    $k = $this->keyId[$name . '#' . $ks];
                    if ($this->refinesElement($this->keyType[$k], $n->type)) {
                        $this->keyType[$k] = $n->type;
                        $this->keyFlavor[$k] = $this->flavorFor($ks, $n->type);
                    }
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->refineFromLoads($c); }
    }

    // ── placement ──────────────────────────────────────────────────

    /** @var array<int, string> inserted op id → name */
    private array $insName = [];
    /** @var array<int, int> inserted op id → class */
    private array $insKey = [];
    /** @var array<int, bool> inserted op id → it is a `drop` */
    private array $insDrop = [];
    /** @var array<int, bool> LoadLocal ids that are not reads: Unset_ targets, op targets, box-back sources */
    private array $skipLoad = [];

    private function resetPlan(): void
    {
        $this->insBefore = [];
        $this->insAfter = [];
        $this->insEnd = [];
        $this->insStart = [];
        $this->insElse = [];
        $this->insCatch = [];
        $this->placed = [];
    }

    private function indexLists(Node $n): void
    {
        if ($n->kind === Node::KIND_BLOCK) {
            foreach (self::asBlock($n)->stmts as $s) { $this->inList[\spl_object_id($s)] = true; }
        } elseif ($n->kind === Node::KIND_TRY_CATCH) {
            $tc = self::asTryCatch($n);
            foreach ($tc->tryBody as $s) { $this->inList[\spl_object_id($s)] = true; }
            foreach ($tc->catches as $c) {
                foreach ($c->body as $s) { $this->inList[\spl_object_id($s)] = true; }
            }
            foreach ($tc->finallyBody as $s) { $this->inList[\spl_object_id($s)] = true; }
        } elseif ($n->kind === Node::KIND_SWITCH) {
            foreach (self::asSwitch($n)->arms as $arm) {
                foreach ($arm->body as $s) { $this->inList[\spl_object_id($s)] = true; }
            }
        }
        foreach (Walk::children($n) as $c) { $this->indexLists($c); }
    }

    private function compOp(string $op, string $name, int $k): MemoryOp_
    {
        $mo = new MemoryOp_($op, $this->keyFlavor[$k], new LoadLocal($name, $this->keyType[$k]), Type::void());
        $id = \spl_object_id($mo);
        $this->insName[$id] = $name;
        $this->insKey[$id] = $k;
        $this->insDrop[$id] = $op === 'drop';
        return $mo;
    }

    private function dropOp(string $name, int $k): MemoryOp_
    {
        return new MemoryOp_('drop', $this->keyFlavor[$k], new LoadLocal($name, $this->keyType[$k]), Type::void());
    }

    /**
     * Put a compensation retain of `$name` on the edge `$kind` / `$at` /
     * `$pred`. False when the edge has no statement position of its own —
     * the caller then forces the name.
     */
    private function placeFix(string $op, string $kind, Node $at, Node $pred, bool $noPred, string $name, int $k): bool
    {
        $pid = \spl_object_id($pred);
        $aid = \spl_object_id($at);
        if ($kind === 'if-then' || $kind === 'if-else') {
            if ($at->kind !== Node::KIND_IF) { return false; }
            $if = self::asIf($at);
            if ($kind === 'if-else' && $if->else === null) {
                return $this->plan($op, 'else', $aid, $name, $k);
            }
            if ($pred->kind === Node::KIND_BLOCK) { return $this->plan($op, 'end', $pid, $name, $k); }
            return isset($this->inList[$pid]) && $this->plan($op, 'after', $pid, $name, $k);
        }
        if ($kind === 'loop-back' || $kind === 'loop-body') {
            if ($noPred) { return false; }
            if ($pred->kind === Node::KIND_BLOCK) { return $this->plan($op, 'end', $pid, $name, $k); }
            if (isset($this->inList[$pid])) { return $this->plan($op, 'after', $pid, $name, $k); }
            // The back edge starts after a for's step / a do-while's condition,
            // an expression. When that expression leaves the name alone, the
            // same state leaves the body's end and every `continue` that lands
            // there — put the op on each of those instead.
            if ($kind !== 'loop-back' || $this->storesName($pred, $name)) { return false; }
            $body = null;
            if ($at->kind === Node::KIND_FOR) { $body = self::asFor($at)->body; }
            if ($at->kind === Node::KIND_DOWHILE) { $body = self::asDoWhile($at)->body; }
            if ($body === null) { return false; }
            $this->plan($op, 'end', \spl_object_id($body), $name, $k);
            /** @var Node[] $conts */
            $conts = [];
            $this->continuesOf($body, 0, $conts);
            foreach ($conts as $c) {
                $cid = \spl_object_id($c);
                if (!isset($this->inList[$cid])) { return false; }
                $this->plan($op, 'before', $cid, $name, $k);
            }
            return true;
        }
        if ($kind === 'loop-entry') {
            if (!isset($this->inList[$aid])) { return false; }
            if ($at->kind === Node::KIND_FOR) {
                $init = self::asFor($at)->init;
                if ($init !== null && $this->storesName($init, $name)) { return false; }
            } elseif ($at->kind === Node::KIND_FOREACH) {
                if ($this->storesName(self::asForeach($at)->array, $name)) { return false; }
            }
            return $this->plan($op, 'before', $aid, $name, $k);
        }
        if ($kind === 'break' || $kind === 'continue' || $kind === 'goto') {
            if ($pid !== $aid || !isset($this->inList[$aid])) { return false; }
            return $this->plan($op, 'before', $aid, $name, $k);
        }
        if ($kind === 'fallthrough') {
            if ($at->kind !== Node::KIND_LABEL || !isset($this->inList[$aid])) { return false; }
            return $this->plan($op, 'before', $aid, $name, $k);
        }
        if ($kind === 'finally') {
            if ($noPred || $pid === $aid || !isset($this->inList[$pid])) { return false; }
            $pk = $pred->kind;
            if ($pk === Node::KIND_BREAK || $pk === Node::KIND_CONTINUE
                || $pk === Node::KIND_GOTO || $pk === Node::KIND_RETURN) {
                return $this->plan($op, 'before', $pid, $name, $k);
            }
            return $this->plan($op, 'after', $pid, $name, $k);
        }
        return false;
    }

    private function plan(string $op0, string $where, int $id, string $name, int $k): bool
    {
        $dk = $op0 . '#' . $where . '#' . (string)$id . '#' . $name;
        if (isset($this->placed[$dk])) { return true; }
        $this->placed[$dk] = true;
        $op = $this->compOp($op0, $name, $k);
        $this->inserted[\spl_object_id($op)] = true;
        if ($where === 'before') {
            $this->insBefore[$id][] = $op;
        } elseif ($where === 'after') {
            $this->insAfter[$id][] = $op;
        } elseif ($where === 'end') {
            $this->insEnd[$id][] = $op;
        } else {
            $this->insElse[$id][] = $op;
        }
        return true;
    }

    /**
     * The `continue`s under `$n` that land on the loop `$n` is the body of:
     * `$depth` loops / switches in between, so its level is `$depth + 1`.
     *
     * @param Node[] $out
     */
    private function continuesOf(Node $n, int $depth, array &$out): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_CONTINUE) {
            if (self::asContinue($n)->level === $depth + 1) { $out[] = $n; }
            return;
        }
        $inner = $k === Node::KIND_WHILE || $k === Node::KIND_FOR || $k === Node::KIND_DOWHILE
            || $k === Node::KIND_FOREACH || $k === Node::KIND_SWITCH;
        foreach (Walk::children($n) as $c) { $this->continuesOf($c, $inner ? $depth + 1 : $depth, $out); }
    }

    private function storesName(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_STORE_LOCAL && self::asStoreLocal($n)->name === $name) { return true; }
        foreach (Walk::children($n) as $c) {
            if ($this->storesName($c, $name)) { return true; }
        }
        return false;
    }

    // ── rewriting statement lists ──────────────────────────────────

    private function applyInsertions(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_BLOCK) {
            $b = self::asBlock($n);
            $id = \spl_object_id($b);
            $list = $this->spliceList($b->stmts);
            if (isset($this->insStart[$id])) { $list = \array_merge($this->insStart[$id], $list); }
            if (isset($this->insEnd[$id])) {
                foreach ($this->insEnd[$id] as $op) { $list[] = $op; }
            }
            $b->stmts = $list;
        } elseif ($k === Node::KIND_TRY_CATCH) {
            $tc = self::asTryCatch($n);
            $tc->tryBody = $this->spliceList($tc->tryBody);
            foreach ($tc->catches as $c) {
                $body = $this->spliceList($c->body);
                $cid = \spl_object_id($c);
                if (isset($this->insCatch[$cid])) { $body = \array_merge($this->insCatch[$cid], $body); }
                $c->body = $body;
            }
            $tc->finallyBody = $this->spliceList($tc->finallyBody);
        } elseif ($k === Node::KIND_SWITCH) {
            foreach (self::asSwitch($n)->arms as $arm) { $arm->body = $this->spliceList($arm->body); }
        } elseif ($k === Node::KIND_IF) {
            $if = self::asIf($n);
            $id = \spl_object_id($if);
            if (isset($this->insElse[$id])) {
                $else = $if->else;
                if ($else === null) {
                    $if->else = new Block($this->insElse[$id], Type::void());
                    $this->madeElse[$id] = true;
                } else {
                    foreach ($this->insElse[$id] as $op) { $else->stmts[] = $op; }
                }
            }
        }
        foreach (Walk::children($n) as $c) {
            if ($c->kind === Node::KIND_MEMORY_OP) { continue; }
            $this->applyInsertions($c);
        }
    }

    /**
     * @param Node[] $stmts
     * @return Node[]
     */
    private function spliceList(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $s) {
            $id = \spl_object_id($s);
            if (isset($this->insBefore[$id])) {
                foreach ($this->insBefore[$id] as $op) { $out[] = $op; }
            }
            $out[] = $s;
            if (isset($this->insAfter[$id])) {
                foreach ($this->insAfter[$id] as $op) { $out[] = $op; }
            }
        }
        return $out;
    }

    private function removeInserted(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_BLOCK) {
            $b = self::asBlock($n);
            $b->stmts = $this->filterList($b->stmts);
        } elseif ($k === Node::KIND_TRY_CATCH) {
            $tc = self::asTryCatch($n);
            $tc->tryBody = $this->filterList($tc->tryBody);
            foreach ($tc->catches as $c) { $c->body = $this->filterList($c->body); }
            $tc->finallyBody = $this->filterList($tc->finallyBody);
        } elseif ($k === Node::KIND_SWITCH) {
            foreach (self::asSwitch($n)->arms as $arm) { $arm->body = $this->filterList($arm->body); }
        }
        foreach (Walk::children($n) as $c) { $this->removeInserted($c); }
        if ($k === Node::KIND_IF) {
            $if = self::asIf($n);
            $else = $if->else;
            if ($else !== null && \count($else->stmts) === 0 && isset($this->madeElse[\spl_object_id($if)])) {
                $if->else = null;
            }
        }
    }

    /** @var array<int, bool> If_ ids whose else this pass created */
    private array $madeElse = [];

    /**
     * @param Node[] $stmts
     * @return Node[]
     */
    private function filterList(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $s) {
            if (isset($this->inserted[\spl_object_id($s)])) { continue; }
            $out[] = $s;
        }
        return $out;
    }

    // ── the final plan ─────────────────────────────────────────────

    /**
     * @param array<string, bool> $force
     * @param array<string, int> $entry
     * @param array<string, int> $entryRetain
     */
    private function finish(FunctionDef $fn, OwnLattice $l, array $force, array $entry, array $entryRetain): void
    {
        $body = $fn->body;
        $bodyId = \spl_object_id($body);
        $rel = $this->releases;

        // Stores: drop what an overwrite takes off an OWNED slot; a borrowed
        // accumulator takes its own +1 before the in-place append consumes it;
        // a forced name's borrowed store takes one after.
        foreach ($l->storeIn as $id => $x) {
            $sl = $this->storeById[$id] ?? null;
            if ($sl === null) { continue; }
            $name = $sl->name;
            $mode = $l->storeMode[$id];
            $k = $l->storeKey[$id];
            if ($mode === OwnLattice::SELF_APPEND) {
                if (OwnLattice::isBorrow($x)) { $sl->ownOld = $this->plainRetain($name, $k); }
                $this->say($sl->line, $name, $x, 'self-append');
                continue;
            }
            if ($mode === OwnLattice::SELF_MOVE) { continue; }
            if ($mode === OwnLattice::SELF_COPY) {
                if ($x > 0 && $rel && isset($l->cellish[$x])) { $sl->ownOld = $this->dropOp($name, $x); }
                $this->say($sl->line, $name, $x, 'self-copy');
                continue;
            }
            if ($x > 0 && $rel) { $sl->ownOld = $this->dropOp($name, $x); }
            if ((OwnLattice::isBorrow($l->storeState[$id]) && isset($force[$name]))
                || isset($this->aliasRetain[$id])) {
                $sl->ownNew = $this->plainRetain($name, $k);
            }
            $this->say($sl->line, $name, $x, 'store');
        }

        foreach ($l->unsetIn as $id => $in) {
            $u = $this->unsetById[$id] ?? null;
            if ($u === null) { continue; }
            foreach ($l->unsetNames[$id] as $name) {
                $x = $in[$name] ?? OwnLattice::EMPTY;
                if ($x > 0 && $rel) {
                    $this->insBefore[$id][] = $this->dropOp($name, $x);
                    $this->say($u->line, $name, $x, 'unset');
                }
            }
        }

        foreach ($this->feById as $id => $fe) {
            $fe->ownCoOwn = isset($l->feValName[$id]) && $l->feValState[$id] > 0
                && ($this->regKey[$fe->valueVar] ?? 0) === $l->feValState[$id];
            if (isset($l->feValIn[$id])) {
                $x = $l->feValIn[$id];
                if ($x > 0 && $rel) { $fe->ownDropValue = $this->dropOp($fe->valueVar, $x); }
                $this->say($fe->line, $fe->valueVar, $x, 'foreach value');
                if (OwnLattice::isBorrow($l->feValState[$id]) && isset($force[$fe->valueVar])) {
                    $this->insStart[\spl_object_id($fe->body)][] = $this->plainRetain($fe->valueVar, $l->feValKey[$id]);
                }
            }
            $kv = $fe->keyVar;
            if ($kv !== null && isset($l->feKeyIn[$id])) {
                $x = $l->feKeyIn[$id];
                if ($x > 0 && $rel) { $fe->ownDropKey = $this->dropOp($kv, $x); }
                $this->say($fe->line, $kv, $x, 'foreach key');
                if (OwnLattice::isBorrow($l->feKeyState[$id]) && isset($force[$kv])) {
                    $this->insStart[\spl_object_id($fe->body)][] = $this->plainRetain($kv, $l->feKeyKey[$id]);
                }
            }
        }

        foreach ($l->catchName as $cid => $name) {
            if (!isset($force[$name])) { continue; }
            $c = $this->catchById[$cid] ?? null;
            if ($c === null) { continue; }
            $this->insCatch[$cid][] = $this->plainRetain($name, $l->catchKey[$cid]);
        }

        /** @var MemoryOp_[] $tail */
        $tail = [];
        $n = \count($l->retAt);
        for ($i = 0; $i < $n; $i++) {
            $at = $l->retAt[$i];
            $out = $l->retOut[$i];
            if (\spl_object_id($at) === $bodyId) {
                foreach ($out as $name => $x) {
                    if ($x > 0 && $rel) {
                        $tail[] = $this->dropOp($name, $x);
                        $this->say(0, $name, $x, 'fall-through');
                    }
                }
                continue;
            }
            if ($at->kind !== Node::KIND_RETURN) { continue; }
            $r = self::asReturn($at);
            $drops = [];
            foreach ($out as $name => $x) {
                if ($x > 0 && $rel) {
                    $drops[] = $this->dropOp($name, $x);
                    $this->say($r->line, $name, $x, 'return');
                }
            }
            $r->ownDrops = $drops;
            // Only an OWNED returned local moves; any other state — a borrow,
            // a raw word, a mixed slot — takes the +1 a borrowed return owes.
            $v = $r->value;
            if ($v !== null && $v->kind === Node::KIND_LOAD_LOCAL) {
                $rn = self::asLoadLocal($v)->name;
                $rs = $out[$rn] ?? OwnLattice::EMPTY;
                $r->ownMove = $rs > 0;
                $this->say($r->line, $rn, $rs, $rs > 0 ? 'return moves' : 'return retains');
            }
        }

        /** @var MemoryOp_[] $head */
        $head = [];
        foreach ($entryRetain as $name => $k) { $head[] = $this->plainRetain($name, $k); }
        foreach ($entry as $name => $s) {
            if (OwnLattice::isBorrow($s) && isset($force[$name])) {
                $head[] = $this->plainRetain($name, OwnLattice::borrowKey($s));
            }
        }

        $this->applyInsertions($body);

        // Fall-through drops before the arena track's trailing releases and
        // `arena_leave`: after the bulk free an arena header is freed memory.
        $stmts = $body->stmts;
        $cut = \count($stmts);
        while ($cut > 0) {
            $s = $stmts[$cut - 1];
            if ($s->kind !== Node::KIND_MEMORY_OP) { break; }
            $op = self::asMemoryOp($s)->op;
            if ($op !== 'arena_leave' && $op !== 'release') { break; }
            $cut = $cut - 1;
        }
        $list = $head;
        for ($i = 0; $i < $cut; $i++) { $list[] = $stmts[$i]; }
        foreach ($tail as $op) { $list[] = $op; }
        $cnt = \count($stmts);
        for ($i = $cut; $i < $cnt; $i++) { $list[] = $stmts[$i]; }
        // `own_local_b`: a name some source of which borrows — never proven to
        // hold element refs of its own ({@see EmitLlvmMemory::collectOwnElemLocals}).
        /** @var array<string, bool> $borrowed */
        $borrowed = [];
        foreach ($entry as $name => $s) {
            if (OwnLattice::isBorrow($s)) { $borrowed[$name] = true; }
        }
        foreach ($l->storeName as $id => $name) {
            if (OwnLattice::isBorrow($l->storeState[$id])) { $borrowed[$name] = true; }
        }
        foreach ($l->feValName as $id => $name) {
            if ($l->feValState[$id] <= 0) { $borrowed[$name] = true; }
        }
        foreach ($l->feKeyName as $id => $name) { $borrowed[$name] = true; }
        foreach ($l->catchName as $cid => $name) { $borrowed[$name] = true; }
        foreach ($this->regKey as $name => $k) {
            $list[] = new MemoryOp_(isset($borrowed[$name]) ? 'own_local_b' : 'own_local', $this->keyFlavor[$k],
                new LoadLocal($name, $this->keyType[$k]), Type::void());
        }
        $body->stmts = $list;
        if (\Compile\Stats::$on) {
            \Compile\Stats::bump('own.flow.managed', \count($this->regKey));
            \Compile\Stats::bump('own.flow.forced', \count($force));
            \Compile\Stats::bump('own.flow.compensations', \count($this->inserted));
        }
    }

    private function plainRetain(string $name, int $k): MemoryOp_
    {
        return new MemoryOp_('own_retain', $this->keyFlavor[$k], new LoadLocal($name, $this->keyType[$k]), Type::void());
    }

    private function say(int $line, string $name, int $x, string $what): void
    {
        if (!$this->trace) { return; }
        \error_log('OWNFLOW ' . $this->fnName . ': STATE ' . (string)$line . ' ' . $name . '='
            . OwnLattice::show($x) . ($x > 0 ? '(' . ($this->keyClass[$x] ?? '?') . ')' : '') . ' ' . $what);
    }

    // ── typed reads (a base-Node field read resolves by offset natively) ──

    private static function asStoreLocal(Node $n): StoreLocal { return $n; }
    private static function asLoadLocal(Node $n): LoadLocal { return $n; }
    private static function asForeach(Node $n): Foreach_ { return $n; }
    private static function asUnset(Node $n): Unset_ { return $n; }
    private static function asTryCatch(Node $n): TryCatch_ { return $n; }
    private static function asSwitch(Node $n): Switch_ { return $n; }
    private static function asBlock(Node $n): Block { return $n; }
    private static function asIf(Node $n): If_ { return $n; }
    private static function asFor(Node $n): For_ { return $n; }
    private static function asDoWhile(Node $n): DoWhile_ { return $n; }
    private static function asContinue(Node $n): Continue_ { return $n; }
    private static function asReturn(Node $n): Return_ { return $n; }
    private static function asConcat(Node $n): Concat { return $n; }
    private static function asMemoryOp(Node $n): MemoryOp_ { return $n; }
    private static function asIncDec(Node $n): IncDec { return $n; }
    private static function asStaticLocalDecl(Node $n): \Compile\Mir\StaticLocalDecl_ { return $n; }
    private static function asRefAlias(Node $n): \Compile\Mir\RefAlias_ { return $n; }
    private static function asRefBind(Node $n): \Compile\Mir\RefBind_ { return $n; }
    private static function asRefAddr(Node $n): \Compile\Mir\RefAddr_ { return $n; }
    private static function asRefCell(Node $n): \Compile\Mir\RefCell_ { return $n; }
    private static function asClosure(Node $n): \Compile\Mir\Closure_ { return $n; }
}
