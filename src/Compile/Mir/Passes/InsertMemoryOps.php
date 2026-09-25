<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\Block;
use Compile\Mir\CondOwn;
use Compile\Mir\Effects;
use Compile\Mir\FunctionDef;
use Compile\Mir\LoadLocal;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\StoreLocal;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * MemoryOps lowering (contract step #5) — turns the allocation-kind
 * verdict into explicit {@see MemoryOp_} nodes in the IR stream, so
 * EmitLlvm *consumes* a memory plan instead of inventing retain /
 * release from its feature handlers (the AST backend's mistake).
 *
 * Reads the final {@see \Compile\Mir\AllocationKind} (after the
 * memory-mode overlay) and lays out the reclaim plan per function:
 *
 *  - Arena allocations → one whole-frame arena scope: `mem_arena_enter`
 *    at body entry, `mem_arena_leave` at exit (bulk free, O(1), no
 *    per-object RC). This is the HYBRID path for confined allocations.
 *  - NoRefcount allocations (rc mode) → per-local `mem_release` at
 *    scope exit. A local is freed iff EVERY StoreLocal to it assigns a
 *    NoRefcount heap alloc; any borrow / scalar / RcHeap store
 *    disqualifies it (never free a value the frame doesn't own).
 *  - RcHeap → deferred to #5b (retain on share + release on exit).
 *
 * Arena scope is per-function (chosen granularity): loop-confined
 * allocations live until the frame's arena leaves — a bounded in-frame
 * leak, never a UAF.
 *
 * EmitLlvm currently treats MemoryOp_ as a no-op consumer (no RC /
 * arena runtime in the MIR backend yet); wiring the emission is #5b.
 */
final class InsertMemoryOps implements Pass
{
    public const NAME = 'insert-memory-ops';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferAllocKind::NAME]; }

    /** @var array<string, string> owned local name → heap flavor */
    private array $ownedFlavor = [];

    /** @var array<string, bool> locals disqualified by a non-owning store */
    private array $blocked = [];

    /** @var string[] owned locals in first-seen order (stable dump) */
    private array $ownedOrder = [];

    /** @var array<string, Type> owned local name → value type for the release target */
    private array $ownedType = [];

    /** Set when the function has at least one Arena allocation. */
    private bool $hasArena = false;

    /** @var array<string, bool> locals re-bound to a non-(rc-obj-alloc)
     *  value — releasing them would double-free, so they're excluded. */
    private array $rcObjBlocked = [];
    /** @var array<string, bool> loop-var names at least one non-co-owning foreach binds */
    private array $feOwnVeto = [];
    /** BISECT: whether the function being lowered is in the feOnly subset. */
    private bool $feFnEnabled = true;

    /** @var string[] owned RcHeap obj locals, first-seen order. */
    private array $rcObjOrder = [];

    /** @var array<string, Type> owned RcHeap obj local → its obj type. */
    private array $rcObjType = [];

    /** @var array<string, bool> locals whose only non-owning store is a
     *  string LITERAL or `null` — neither owns nor borrows. */
    private array $rcObjNeutral = [];

    /** @var array<string, bool> locals given an owned value by something OTHER
     *  than a conditional. */
    private array $rcObjPlainOwner = [];

    /** @var array<string, bool> owned rc local → whether the SLOT holds a
     *  NaN-boxed cell ({@see slotStoredType}). A name whose stores disagree
     *  about this has no single correct release flavor and is blocked. */
    private array $rcObjSlotBoxed = [];

    /** @var array<string, Type> owned rc local → the slot type of its first
     *  owned RAW store (the raw half of a {@see $rcObjMixed} name). */
    private array $rcObjRawType = [];

    /** @var array<string, bool> owned rc local → it took an owned store into a
     *  CELL slot. */
    private array $rcObjCellSeen = [];

    /** @var array<string, bool> owned rc locals whose slot is a raw string /
     *  object on some paths and a cell on others — the flow-sensitive cell
     *  promotion at an if/else merge ({@see InferTypes::planMergeShadow}). No
     *  one static flavor releases both, so the release reads a per-slot
     *  "holds a cell" flag the emitter keeps beside the slot
     *  ({@see EmitLlvmMemory::mixedReleaseIr}). */
    private array $rcObjMixed = [];

    /** @var array<string, bool> names a foreach binds (its own ownership path). */
    private array $rcObjForeachVar = [];

    /** @var array<string, bool> names bound to other storage or aliased by a
     *  reference ({@see collectRefNames}) — a write through the alias lands in
     *  the slot without the store that keeps a MIXED slot's flag. */
    private array $rcObjRefName = [];

    /** @var array<string, bool[]> fn name → per-param by-ref mask */
    private array $refMasks = [];

    /** @var array<string, bool> fn name → its variadic tail is by-ref */
    private array $refVariadic = [];

    /** @var array<string, int> closure fn name → capture count (the call's
     *  leading params) */
    private array $closureCaptureCount = [];

    /** @var array<string, bool> names that took a raw SCALAR store. */
    private array $rcObjRawScalar = [];

    /** @var array<string, bool> owned array names EVERY owned store of which is
     *  a `__mir_array_copy` — so the name's buffer is this frame's own and
     *  releasing it cannot free anything another owner still holds. */
    private array $rcObjCopyOnly = [];

    /** @var array<string, bool> array locals this function ELEMENT-STORES into.
     *  A strict SUBSET of the emitter's `mutatedVecLocals` ({@see
     *  EmitLlvmMemory}, which also counts unset / by-ref / mutating builtins),
     *  so a name in here is one the emitter certainly copies on alias. */
    private array $elemMutatedLocals = [];

    /** @var array<string, bool> names that took an ERASED array-PROPERTY read.
     *  Its retain is repr-deep (buffer only), so the element refinement below
     *  must not deepen this name's release past what that retain took. */
    private array $rcObjErasedProp = [];

    /** @var array<string, string> census only: blocked local → which gate blocked it. */
    /** Array locals mutated in the function — the copy-on-assign input.
     *  @var array<string, bool> */
    private array $mutatedVecs = [];

    private array $blockReason = [];

    /** @var array<string, string> census only: blocked local → the value's type kind. */
    private array $blockKind = [];

    /** The ownership classifier ({@see \Compile\Mir\Ownership}), built per module. */
    private ?\Compile\Mir\Ownership $own = null;

    /** @var array<string, \Compile\Mir\ClassDef> class name → layout */
    private array $classes = [];
    /** @var array<string, mixed> enum name → def (enum values are non-rc). */
    private array $enums = [];
    /** @var array<string, bool> closure fn names — their prologue copies no param */
    private array $closureFns = [];

    public function run(Module $module): Module
    {
        $this->classes = $module->classes;
        $this->enums = $module->enums;
        $this->closureFns = [];
        foreach ($module->closureCaptures as $name => $unused) { $this->closureFns[$name] = true; }
        // FFI functions return FOREIGN values (raw libc buffers/pointers
        // from calloc/malloc/fopen/...) that do NOT follow the +1 owned
        // return convention and carry no rc header — never rc-track them
        // (the context's externFns).
        $this->own = new \Compile\Mir\Ownership(\Compile\Mir\OwnershipContext::fromModule($module));
        $this->refMasks = [];
        $this->refVariadic = [];
        foreach ($module->functions as $fn) {
            $mask = [];
            $tail = false;
            foreach ($fn->params as $p) {
                $mask[] = $p->byRef;
                $tail = $p->variadic && $p->byRef;
            }
            $this->refMasks[$fn->name] = $mask;
            $this->refVariadic[$fn->name] = $tail;
        }
        $this->closureCaptureCount = $module->closureCaptures;
        foreach ($module->functions as $fn) {
            $this->lowerFunction($fn);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function lowerFunction(FunctionDef $fn): void
    {
        $this->traceFn = $fn->name;
        $this->own->ctx->fn = $fn;
        $this->ownedFlavor = [];
        $this->blocked = [];
        $this->ownedOrder = [];
        $this->ownedType = [];
        $this->hasArena = false;
        $this->rcObjBlocked = [];
        $this->rcObjOrder = [];
        $this->rcObjType = [];
        $this->rcObjNeutral = [];
        $this->rcObjPlainOwner = [];
        $this->rcObjSlotBoxed = [];
        $this->rcObjRawType = [];
        $this->rcObjCellSeen = [];
        $this->rcObjMixed = [];
        $this->rcObjForeachVar = [];
        $this->rcObjRawScalar = [];
        $this->rcObjRefName = [];
        $this->collectRefNames($fn->body);
        $this->rcObjErasedProp = [];
        $this->rcObjCopyOnly = [];
        $this->blockReason = [];
        $this->blockKind = [];
        $this->mutatedVecs = \Compile\Mir\VecCopyOnAssign::mutatedLocals($fn->body);
        // Per-name, whole-function: a loop variable co-owns only if EVERY foreach
        // binding it does ({@see foreachOwnVetoes}). Computed BEFORE the walk,
        // because the arm that registers a name runs before the later loop that
        // would veto it.
        $this->feFnEnabled = \Compile\Debug::$feOnly === ''
            || \str_contains($fn->name, \Compile\Debug::$feOnly);
        $this->feOwnVeto = self::foreachOwnVetoes($fn->body, $this->enums, $this->classes);
        $this->elemMutatedLocals = [];
        $this->collectElemMutated($fn->body);

        // A PARAMETER slot is never a scope-exit release candidate. Unlike an
        // ordinary local it is NOT null-inited — it arrives holding the CALLER's
        // value — so the "release on null is a no-op" safety net that makes a
        // conditionally-assigned local safe does not apply. A param reassigned
        // on only SOME path (`if (!is_array($r)) { $r = [$r]; }`) then had its
        // slot released at scope exit, freeing the caller's still-live array:
        // a use-after-free / double-free, and a hard SIGSEGV for
        // array_splice's `mixed $replacement = []`.
        //
        // The conservative direction is a leak, not a free: a param reassigned
        // to a fresh array on every path keeps that array alive to the end of
        // the process instead of being freed at scope exit.
        // The walk runs FIRST so the blocks it records can be told apart from
        // the blanket param block below ({@see $storeBlocked}); it never reads
        // either map.
        $this->scanStores($fn->body);
        $this->settleMixedSlots($fn);
        $storeBlocked = $this->rcObjBlocked;
        foreach ($fn->params as $p) {
            $this->blocked[$p->name] = true;
            $this->rcObjBlocked[$p->name] = true;
            $this->noteBlock($p->name, 'param', $p->type);
        }

        // The FIRST exception: a BY-VALUE object or string param the body
        // REASSIGNS from an owned producer (`$o = $o ?? new Options()`,
        // `if ($o === null) { $o = new O(); }`, `$s = trim($s)`). The blanket
        // block took both the release-before-overwrite and the scope-exit
        // release away, so whatever the frame stored there was never freed —
        // and a conditional's borrowed arm (`$o` itself) is retained to +1 by
        // the ownership contract, so even the CALLER's object leaked one count
        // per call. Registering the param gives it the entry retain + scope-exit
        // release {@see EmitLlvmMemory::initRcObjSlots} pairs for exactly this
        // shape: the entry +1 makes the caller's value the frame's to release,
        // on overwrite or at exit, and a param never reassigned on the path
        // taken is retained and released once. Only when every store to the
        // name is owned and agrees with the param's own slot flavor — any
        // borrowed or mismatched store keeps the block (a leak, never a free).
        foreach ($fn->params as $p) {
            if ($p->byRef || $p->variadic) { continue; }
            $pk = $p->type->kind;
            if ($pk !== Type::KIND_OBJ && $pk !== Type::KIND_STRING) { continue; }
            if ($pk === Type::KIND_OBJ && $this->isClosureType($p->type)) { continue; }
            $st = $this->rcObjType[$p->name] ?? null;
            if ($st === null || isset($storeBlocked[$p->name])) { continue; }
            if ($this->rcSlotFlavor($st) !== $this->rcSlotFlavor($p->type)) { continue; }
            if ($this->rcObjSlotBoxed[$p->name] ?? false) { continue; }
            unset($this->rcObjBlocked[$p->name]);
        }

        // The SECOND exception to the blanket param block: a BY-VALUE string
        // param the body self-appends to (`$out .= …`). The append takes
        // __mir_str_append's in-place fast path whenever rc == 1 — and rc IS 1
        // there, because that single reference is the CALLER'S. The callee then
        // wrote into the caller's buffer (`$a = 'hello'; app($a); // $a is now
        // 'helloWORLD'` — PHP value semantics silently violated) and, once the
        // append outgrew the capacity, the grow path freed the buffer the caller
        // still pointed at, so the caller's own release hit a dead pointer.
        //
        // Registering the param here gives it the entry retain + scope-exit
        // release that {@see EmitLlvmMemory::initRcObjSlots} already implements
        // for reassigned params: rc becomes 2, so the first append COPIES (and
        // releases our entry reference), leaving the frame a private buffer that
        // every later append may mutate in place. Correctness with the amortized
        // append intact.
        $selfAppended = [];
        $this->collectSelfAppendedStrings($fn->body, $selfAppended);
        foreach ($fn->params as $p) {
            if ($p->byRef || $p->variadic) { continue; }
            if (!isset($selfAppended[$p->name])) { continue; }
            unset($this->rcObjBlocked[$p->name]);
            if (!isset($this->rcObjType[$p->name])) {
                $this->rcObjOrder[] = $p->name;
                $this->rcObjType[$p->name] = Type::string_();
            }
        }

        // The THIRD exception, and the same discipline: a BY-VALUE `mixed`
        // param the body MUTATES AS AN ARRAY (`$v[$k] = …`, `$v[] = …`,
        // `unset($v[$k])`, `$v[$k] = &$x`). An `array`-hinted param is copied on
        // entry for this ({@see EmitLlvmModule}'s copy_deep); a `mixed` one was
        // not, and its slot held the caller's buffer at rc 1 — the one reference
        // being the CALLER'S — so the copy-on-write behind the first store saw a
        // sole owner and wrote into the caller's array. Registering it takes the
        // entry retain + scope-exit drop (both tag-dispatched, so a scalar or a
        // string arriving in the same param costs a no-op): rc becomes 2, the
        // first store COPIES, and the frame owns a private buffer from then on.
        // Witness: symfony/polyfill-deepclone's `$values[$k] = &$value`, which
        // must rebind the CALLEE's element and leave the caller's `'p' => &$a`
        // exactly as it was.
        // The FOURTH: an `array` param the prologue COPIES because the body
        // stores into it. The slot holds the frame's private +1, not the
        // caller's value, so it is an owned local like any other — released at
        // scope exit, handed on by a `return`, released before a reassignment.
        $isClosure = isset($this->closureFns[$fn->name]);
        foreach ($fn->params as $p) {
            if ($p->type->kind === Type::KIND_CELL) { continue; }
            if (!\Compile\Mir\VecCopyOnAssign::paramCopiedOnEntry($fn, $p, $isClosure)) { continue; }
            unset($this->rcObjBlocked[$p->name]);
            if (!isset($this->rcObjType[$p->name])) {
                $this->rcObjOrder[] = $p->name;
                $this->rcObjType[$p->name] = $p->type;
            }
        }

        $mutatedAsArray = \Compile\Mir\VecCopyOnAssign::mutatedLocalsAnyType($fn->body);
        foreach ($fn->params as $p) {
            if ($p->byRef || $p->variadic) { continue; }
            if ($p->type->kind !== Type::KIND_CELL) { continue; }
            if (!isset($mutatedAsArray[$p->name])) { continue; }
            unset($this->rcObjBlocked[$p->name]);
            if (!isset($this->rcObjType[$p->name])) {
                $this->rcObjOrder[] = $p->name;
                $this->rcObjType[$p->name] = Type::cell();
            }
        }

        // A `$x = null;` / `$x = '';` seed neither owns nor borrows, so it must
        // not disqualify the local: the slot then holds 0, an immortal literal
        // (both self-guarded by every release helper) or a genuine +1. Without
        // this the seed of every accumulator disqualified it — `$out = '';`
        // ahead of `$out = $c ? $s : ($out . ',' . $s);` left the arm retain the
        // ownership contract pays for ({@see \Compile\Mir\Ownership::condOwnedStored}) with nothing to
        // balance it, i.e. one leaked string per iteration (measured).
        //
        // Deliberately narrow: only when EVERY owned store to the name is a
        // conditional, i.e. exactly the +1 population this contract created. A
        // name that also takes a plain owned producer keeps the old blanket
        // block — unblocking those too made `$conds = null; … $conds = [];`
        // (LowerFromAst::lowerMatch) free a buffer a live MatchArm_ still held,
        // a SIGBUS in the self-build. Whatever escape that shape relies on is
        // NOT this epic's, so it is left exactly as it was.
        foreach ($this->rcObjNeutral as $name => $ignored) {
            if (!isset($this->rcObjPlainOwner[$name])) { continue; }
            // …EXCEPT a STRING, which is what that blanket block costs the most.
            // `$out = ''; for (…) { $out = $out . $s[$i]; }` — every scanner and
            // every decoder in the stdlib — leaked the ENTIRE accumulated buffer
            // at each re-seed, because the literal store blocked the name and
            // with it the release-before-overwrite (64 B per call in urldecode,
            // measured). The SIGBUS that motivated the block was an ARRAY
            // (`$conds = null; … $conds = [];`, whose buffer a live MatchArm_
            // still held); a string has no by-value container aliasing, and
            // every borrowing consumer of a string local — an alias store, an
            // element / property store, a call argument — takes its own +1
            // through {@see EmitLlvmMemory::rcRetainByType}, so the release has
            // a matching retain. A borrowed store still lands in the `else`
            // branch below and blocks the name outright, so only
            // owned-producer-plus-literal names reach here.
            //
            // …and EXCEPT an OBJECT, for the same reason and at a far larger
            // scale. `$x = new Foo(); … $x = null;` is how a compiler phase
            // hands back a graph it is done with, and the blanket block turned
            // that idiom into a PERMANENT leak: the name lost the
            // release-before-overwrite AND its scope-exit release, so the value
            // was never freed at all. `lower_module` ends with exactly that —
            // `$program = null; $lower = null;` over the whole AST, under a
            // comment promising the tree is released there — and it freed
            // nothing: on a self-compile every `Parser\Ast\*` class showed
            // alloc=N free=0, ~47% of the live objects at exit
            // (`docs/status/T6-MEMORY-HANDOFF-2026-09-04.md`).
            //
            // An object is safe here for the same reason a string is, and more
            // directly: it carries its own rc header, and every borrowing
            // consumer — an alias store, an element / property store, a call
            // argument — takes a real +1 through
            // {@see EmitLlvmMemory::rcRetainByType}. The SIGBUS this block was
            // written for is an ARRAY hazard (a by-value container whose BUFFER
            // is shared without an rc of its own); arrays keep the block.
            $t = $this->rcObjType[$name] ?? null;
            if ($t !== null && $t->kind === Type::KIND_STRING) { continue; }
            if ($t !== null && $t->kind === Type::KIND_OBJ) { continue; }
            // …and a CELL: its drop dispatches on the tag the slot carries, so a
            // neutral store (a boxed scalar, null, an immortal literal) leaves
            // it nothing to over-release.
            if ($t !== null && $t->kind === Type::KIND_CELL) { continue; }
            // …and an ARRAY every owned store of which is a COPY. The SIGBUS this
            // block was written for is a SHARED buffer (`$conds = null; … $conds
            // = [];`, whose buffer a live MatchArm_ still held); a copy is the
            // one array shape with no sharing at all — `__mir_array_copy` gives
            // the local its own rc=1 buffer, adopted at the element flavor. So
            // the release has nothing to over-release, and without it the copy is
            // simply lost: `$args = null; … $args = $mc->args;` in
            // `InferScans::collectDocListKeyArgs` made FOUR copies per call
            // against two releases, 374 843 live blocks at the peak.
            if ($t !== null && $t->kind === Type::KIND_ARRAY
                && ($this->rcObjCopyOnly[$name] ?? false)) { continue; }
            $this->rcObjBlocked[$name] = true;
            $this->noteBlock($name, "neutral", $t);
        }

        // Per-local releases for rc-mode confined allocations.
        $releases = [];
        foreach ($this->ownedOrder as $name) {
            if (isset($this->blocked[$name])) { continue; }
            $flavor = $this->ownedFlavor[$name];
            $type = $this->ownedType[$name];
            $target = new LoadLocal($name, $type);
            $releases[] = new MemoryOp_('release', $flavor, $target, Type::void());
        }

        // RcHeap object releases. A local is dropped at scope exit iff it
        // is assigned an RcHeap obj allocation somewhere and never re-bound
        // to a non-alloc value (which would risk a double-free). EmitLlvm
        // null-inits these slots and releases them before every `return`
        // (except the returned one — transfer) plus on fall-through, so a
        // conditionally-assigned local is safe (release on null = no-op).
        $rcReleases = [];
        foreach ($this->rcObjOrder as $name) {
            if (isset($this->rcObjBlocked[$name])) { continue; }
            $type = $this->rcObjType[$name];
            // A slot the emitter fills with a RAW scalar has nothing to release,
            // and the flavor ladder below has no arm for it — its `obj` default
            // would rc-release an int. {@see slotStoredType}'s second arm.
            if (!$this->slotTypeIsRc($type)) { continue; }
            // CELL before the 'obj' default: a cell is NaN-boxed, so releasing
            // it as an obj would inttoptr the tag bits and fault.
            $flavor = $type->kind === Type::KIND_STRING ? 'str'
                : ($type->kind === Type::KIND_CELL ? 'cell'
                : ($type->isVec() ? 'vec'
                : ($type->isAssoc() ? 'assoc'
                : ($this->isClosureType($type) ? 'closure' : 'obj'))));
            if (isset($this->rcObjMixed[$name])) { $flavor = 'mix' . $flavor; }
            $target = new LoadLocal($name, $type);
            $rcReleases[] = new MemoryOp_('rc_release', $flavor, $target, Type::void());
        }

        if (!$this->hasArena && \count($releases) === 0 && \count($rcReleases) === 0) {
            return;
        }

        $stmts = $fn->body->stmts;

        // Whole-frame arena scope wraps the body; enter first.
        if ($this->hasArena) {
            $enter = new MemoryOp_('arena_enter', '', null, Type::void());
            $prefixed = [$enter];
            foreach ($stmts as $s) { $prefixed[] = $s; }
            $stmts = $prefixed;
        }

        // Scope-exit cleanup. Return paths exit before reaching this, so
        // it only fires on fall-through (the transfer-safe path).
        foreach ($rcReleases as $r) { $stmts[] = $r; }
        foreach ($releases as $r) { $stmts[] = $r; }
        if ($this->hasArena) {
            $stmts[] = new MemoryOp_('arena_leave', '', null, Type::void());
        }

        // The rc track, which is the one an ARRAY local actually rides.
        // `ownedFlavor` below is the ARENA track, and reading only that said
        // `stmts` was "blocked" when the real question is whether its rc
        // release names the ELEMENT type — a `vec` release hands back the
        // buffer and strands every element in it.
        foreach ($this->rcObjOrder as $onm) {
            $ot = $this->rcObjType[$onm] ?? null;
            $this->ownTrace('RCOBJ ' . $onm
                . ' type=' . ($ot === null ? '?' : $ot->toString())
                . ' flavor=' . ($ot === null ? '?' : $this->rcSlotFlavor($ot))
                . (isset($this->rcObjMixed[$onm]) ? ' MIXED' : '')
                . (isset($this->rcObjBlocked[$onm]) ? ' BLOCKED' : ' released'));
        }
        foreach ($this->ownedFlavor as $onm => $ofl) {
            $this->ownTrace('OWNED ' . $onm . ' flavor=' . $ofl
                . (isset($this->blocked[$onm]) ? ' (BLOCKED)' : ''));
        }
        $this->ownTrace('releases=' . (string)\count($releases)
            . ' rcReleases=' . (string)\count($rcReleases));
        $this->censusFunction(\count($releases), \count($rcReleases));
        $fn->body->stmts = $stmts;
    }

    /** The function {@see lowerFunction} is working on, for {@see ownTrace}. */
    private string $traceFn = '';

    /**
     * `MANTICORE_OWN_TRACE=<substr>` — say, for one function, which local got
     * a release and which did not.
     *
     * The counterpart to `CLASSSLOT` ({@see EmitLlvm::classDropFlavor}), which
     * answers the same question for a class SLOT. Every refusal here is a
     * deliberate leak, and {@see censusFunction} only ever counted them by
     * gate — so a specific array that never came back had no way to name the
     * gate that kept it. `leaks` names the ALLOCATING function; this names the
     * decision, and the two together are a file:line.
     */
    private function ownTrace(string $line): void
    {
        $want = \getenv('MANTICORE_OWN_TRACE');
        if ($want === false || $want === '') { return; }
        if (!\str_contains($this->traceFn, $want)) { return; }
        \error_log('OWN ' . $this->traceFn . ': ' . $line);
    }

    /**
     * Census hook — active only under `MANTICORE_STATS=1`, and it changes no
     * plan. This pass refuses to schedule a release at five distinct gates and
     * every refusal is a deliberate LEAK ("Block: a leak, never a free of a
     * tag"). Which gate, and on what type kind, is the whole question: without
     * it the retained memory has no attributable owner. First reason wins — a
     * name is blocked once and the first gate is the one that decided it.
     */
    private function noteBlock(string $name, string $reason, ?Type $t): void
    {
        $this->ownTrace('BLOCK ' . $name . ' <- ' . $reason
            . ' type=' . ($t === null ? 'none' : $t->toString()));
        if (!\Compile\Stats::$on) { return; }
        if (isset($this->blockReason[$name])) { return; }
        $this->blockReason[$name] = $reason;
        $this->blockKind[$name] = $t === null ? 'none' : $t->kind;
    }

    /** Locals that got a release vs locals that did not, by gate and by kind. */
    private function censusFunction(int $releases, int $rcReleases): void
    {
        if (!\Compile\Stats::$on) { return; }
        \Compile\Stats::bump('own.released.flavor', $releases);
        \Compile\Stats::bump('own.released.rcobj', $rcReleases);
        foreach ($this->blockReason as $name => $reason) {
            \Compile\Stats::bump('own.blocked.' . $reason, 1);
            \Compile\Stats::bump('own.blocked.kind.' . $this->blockKind[$name], 1);
        }
    }

    /**
     * Names self-appended as strings anywhere in `$n`: `$s = $s . …`, the shape
     * {@see EmitLlvmLocals::emitStoreLocal} turns into an in-place
     * `__mir_str_append`. Only the LEFTMOST leaf counts — that is the one the
     * append mutates.
     *
     * @param array<string, bool> $out
     */
    private function collectSelfAppendedStrings(Node $n, array &$out): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $v = $n->value;
            if ($v->kind === Node::KIND_CONCAT && $v->type->kind === Type::KIND_STRING) {
                $leaf = $v;
                while ($leaf->kind === Node::KIND_CONCAT) { $leaf = $leaf->left; }
                if ($leaf->kind === Node::KIND_LOAD_LOCAL
                    && $leaf->type->kind === Type::KIND_STRING
                    && $leaf->name === $n->name) {
                    $out[$n->name] = true;
                }
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectSelfAppendedStrings($c, $out); }
    }

    /**
     * Whether `$value` yields an owned (rc=1) object: a `new X()`
     * allocation, or an obj-returning call (the +1 return convention
     * transfers ownership to us). Borrowed producers — a LoadLocal alias,
     * property / array read — are excluded: releasing them would
     * over-release the real owner's count.
     */
    /**
     * The ONE condition behind element-read co-ownership, shared by both halves
     * of it: this pass, which makes the destination local release, and
     * {@see EmitLlvmLocals::elemReadCoOwn}, which takes the matching +1. A type
     * either gets both or neither — one half alone is a leak or a double free.
     *
     * @param array<string, mixed> $enums enum name → def; enum values are non-rc
     */
    /**
     * Does `$b = $a` on an array make the destination a CO-OWNER?
     *
     * A local array alias is the one rc shape with no answer of its own: the
     * emitter neither copies it (that needs a proven mutation) nor retains it,
     * so the two names SHARE one buffer and the pass has to block the source or
     * it would be released twice. Blocking is a leak of everything the source
     * ever owned — one `$argl = $args;` in `InferScans::collectDocListKeyArgs`
     * cost 38.2 MB of live blocks, all of it the vec-property COPIES `$args`
     * took one line earlier.
     *
     * So co-own instead: the emitter takes a +1 and the pass stops blocking.
     * ⚠ ONE predicate for both halves, and deliberately narrow — the blanket
     * version of this retain is the one `EmitLlvmLocals` records as having
     * written rc into a live heap string (the enum backing "int"→"jnt"). Both
     * sides must name the SAME element, and the element must be one whose
     * retain/release pair is fully exercised: a STRING or a plain OBJECT.
     * A cell / unknown / erased element is exactly the raw-word case that
     * corrupted, and it is refused here.
     */
    public static function arrayAliasCoOwns(?Type $value, ?Type $slot,
                                            array $enums, array $classes = []): bool
    {
        if ($value === null || $slot === null) { return false; }
        if (!$value->isArray() || !$slot->isArray()) { return false; }
        $ve = $value->element;
        $se = $slot->element;
        if ($ve === null || $se === null) { return false; }
        // Two CLOSURE elements are one representation whatever they are
        // spelled (`closure` / `obj<__closure_N>`); the alias's repr-walk pair
        // ({@see \Compile\MemoryAbi::ARRAY_REPR_CLO}) co-owns and gives back.
        if (self::isClosureElem($ve) && self::isClosureElem($se)) { return true; }
        if ($ve->kind !== $se->kind) { return false; }
        if ($ve->kind !== Type::KIND_STRING && $ve->kind !== Type::KIND_OBJ) { return false; }
        if ($ve->kind === Type::KIND_OBJ && ($ve->class ?? '') !== ($se->class ?? '')) { return false; }
        return self::elemReadCoOwns($ve, $enums, $classes);
    }

    private static function isClosureElem(Type $t): bool
    {
        if ($t->kind === Type::KIND_CLOSURE) { return true; }
        $c = $t->class ?? '';
        return $t->kind === Type::KIND_OBJ && ($c === 'Closure' || \str_starts_with($c, '__closure_'));
    }

    /** {@see \Compile\Mir\Ownership::elemReadCoOwns} */
    public static function elemReadCoOwns(?Type $t, array $enums, array $classes = []): bool
    {
        return \Compile\Mir\Ownership::elemReadCoOwns($t, $enums, $classes);
    }

    /**
     * The ONE condition behind foreach-value co-ownership, shared by both
     * halves: this pass, which stops BLOCKING the loop variable so scope exit
     * releases it, and {@see EmitLlvmControl}'s unified-array loop, which takes
     * the matching +1 and drops the previous iteration's. One half alone is a
     * leak or a double free ({@see \Compile\Debug::$rcForeachValueOwns}).
     *
     * A PROVEN vec/assoc base only. That is not caution, it is the agreement
     * itself: `emitForeach` routes a generator, a Traversable and an erased
     * carrier elsewhere — the last of those CLASSIFIES AT RUNTIME — and this
     * pass cannot know which arm will run. `byRef` is out because `&$v` binds
     * the slot, it does not copy it.
     *
     * @param array<string, mixed> $enums enum name → def; enum values are non-rc
     */
    public static function foreachValueCoOwns(\Compile\Mir\Foreach_ $fe, array $enums, array $classes = []): bool
    {
        return self::foreachValueSlotType($fe, $enums, $classes) !== null;
    }

    /**
     * The type a co-owning loop binds its value at — the flavor both halves
     * retain and release by — or null when the loop does not co-own.
     *
     * Besides a proven vec/assoc, the two ITERATOR loops `emitForeach` routes
     * by the subject's static type co-own too, because their step already
     * hands out a +1 and a borrowed loop variable stranded it on every
     * iteration:
     *  - a GENERATOR subject: its frame owns `current`@16 and the loop takes
     *    its own +1 of it ({@see EmitLlvmGenerator::emitYield}); bound at the
     *    element type the loop unboxes to, a tagged cell when that is erased.
     *  - an Iterator-protocol subject whose `current()` answers a CELL: a
     *    Generator iterator (`getIterator(): \Generator`) and an interface one
     *    that classifies at run time — every arm of that step is +1.
     *    A user Iterator CLASS answers its declared raw type, which this pass
     *    does not see; it stays borrowed.
     *
     * @param array<string, mixed> $enums
     */
    public static function foreachValueSlotType(\Compile\Mir\Foreach_ $fe, array $enums, array $classes = []): ?Type
    {
        if (!\Compile\Debug::$rcForeachValueOwns) { return null; }
        if ($fe->byRef) { return null; }
        $at = $fe->array->type;
        if ($at->isVec() || $at->isAssoc()) {
            return self::elemReadCoOwns($at->element, $enums, $classes) ? $at->element : null;
        }
        if ($at->kind !== Type::KIND_OBJ) { return null; }
        if (($at->class ?? '') === 'Generator') {
            $el = $at->element;
            if ($el === null || $el->kind === Type::KIND_CELL || $el->kind === Type::KIND_UNKNOWN) {
                return Type::cell();
            }
            return self::elemReadCoOwns($el, $enums, $classes) ? $el : null;
        }
        $ic = $fe->iterClass;
        if ($ic === 'Generator' || ($ic !== '' && !isset($classes[$ic]))) { return Type::cell(); }
        return null;
    }

    /**
     * Loop-variable NAMES this function must not co-own, because at least one
     * `foreach` binding the name does NOT co-own.
     *
     * ★★★ The pass decides per NAME and the emitter per SITE, and that is the
     * whole reason this exists. `InferScans::scanByRefCaptureNode` binds `$c`
     * TWICE — once over `$n->captures`, once over `Walk::children($n)`. Let one
     * loop co-own and the other store a borrow into the same slot, and the
     * scope-exit release the first loop earned is paid by the second loop's
     * BORROWED node: the child is freed while the tree still holds it, and the
     * next walk faults inside `Walk::children`. That is a gen-2 compiler that
     * cannot compile hello world.
     *
     * So a name is co-owned only when EVERY foreach that binds it agrees —
     * the same "every store or none" rule {@see EmitLlvmMemory::collectOwnElemLocals}
     * applies to element-owning locals. Both halves call this.
     *
     * @param array<string, mixed> $enums
     * @return array<string, bool> name → vetoed
     */
    public static function foreachOwnVetoes(Node $body, array $enums, array $classes = []): array
    {
        $veto = [];
        self::collectForeachVetoes($body, $enums, $classes, $veto);
        return $veto;
    }

    /** @param array<string, bool> $veto */
    private static function collectForeachVetoes(Node $n, array $enums, array $classes, array &$veto): void
    {
        if ($n->kind === Node::KIND_FOREACH) {
            $fe = $n;
            if (!self::foreachValueCoOwns($fe, $enums, $classes)) { $veto[$fe->valueVar] = true; }
        }
        foreach (Walk::children($n) as $c) { self::collectForeachVetoes($c, $enums, $classes, $veto); }
    }

    /** The store side of {@see \Compile\Mir\Ownership}: does the local this value
     *  is stored into own it? */
    private function isOwnedObj(Node $value): bool { return $this->own->classify($value) > 0; }

    /**
     * Is `$new` the same array shape as `$old` but with a CONCRETE element
     * where `$old` had `unknown`? That is the one upgrade this pass accepts
     * after the first store: it deepens the release, it cannot redirect it.
     */
    private function refinesElement(Type $old, Type $new): bool
    {
        if (!\Compile\Debug::$rcElemType) { return false; }
        // ★★★ vec → assoc IS still a deepening, not a redirect. `$l = [];` types
        // the local `vec[unknown]` and pins the release flavor there
        // first-write-wins; the very next store, `$l = f();`, is
        // `assoc[string,string]` — a different SHAPE, so this refused, and the
        // slot kept the plain repr-driven release for the rest of the function.
        // The buffer it then dropped carried only a shape HINT and no ownership
        // repr, so every key and every value of every array the loop built
        // leaked: 780 B per iteration, 149 MB where php is flat at 28
        // (`tools/prof/propleak.php arrlocal`). `$x = []` before a loop is
        // ubiquitous, which is why this one predicate is worth the note.
        //
        // Safe on the pass's own terms: a vec and an assoc are ONE buffer type
        // (packed vs hashed is a runtime flag) and both release through the same
        // `__mir_array_release*` family, so the flavor deepens rather than moves.
        // The old element must still be UNKNOWN — the old release therefore drops
        // nothing but the buffer — so the upgrade can only ADD drops, and on the
        // empty literal that pinned the name those drops walk zero elements.
        if (!$old->isArray() || !$new->isArray()) { return false; }
        $oe = $old->element;
        $ne = $new->element;
        if ($oe === null || $ne === null) { return false; }
        if ($oe->kind !== Type::KIND_UNKNOWN) { return false; }
        return $ne->kind === Type::KIND_OBJ || $ne->kind === Type::KIND_STRING;
    }

    /**
     * The type of what the SLOT actually receives — which is NOT always the
     * value's type, and the release reads the SLOT.
     *
     * {@see EmitLlvmLocals::emitStoreLocal} has two arms where the store NODE's
     * type and its VALUE's type deliberately disagree, and in both the emitter
     * CONVERTS on the way into the slot:
     *   - store typed CELL, value concrete — the merge box-back InferTypes plants
     *     at an if/else join; the slot receives a NaN-BOXED word;
     *   - store typed a concrete scalar/string, value a CELL — the by-ref
     *     representation plant; the slot receives the RAW payload.
     * Everywhere else `inferStoreLocal` types the store = its value, so this
     * answers exactly what it always did.
     *
     * Reading the value's type through those two arms is what emitted
     * `__mir_array_release_buf` on a boxed array cell: `/** @var int[]|null $c *\/
     * $c = mkList();` inside a loop — a plain owned CALL, a cell-typed slot —
     * SIGSEGV'd at scope exit on `0xfff7…`, i.e. the tag, not a heap pointer.
     * One owner for the slot's representation, or the release frees a tag.
     */
    public static function slotStoredType(StoreLocal $sl): Type
    {
        $st = $sl->type->kind;
        $vt = $sl->value->type->kind;
        if ($st === Type::KIND_CELL && $vt !== Type::KIND_CELL) { return $sl->type; }
        if ($vt === Type::KIND_CELL
            && ($st === Type::KIND_INT || $st === Type::KIND_FLOAT
                || $st === Type::KIND_BOOL || $st === Type::KIND_STRING)) {
            return $sl->type;
        }
        return $sl->value->type;
    }

    /**
     * Does the emitter answer this store with `__mir_array_copy`? The two sites
     * that do ({@see EmitLlvmLocals::emitStoreLocal}): a mutated local-to-local
     * array alias, and a VEC read of an instance or static property. Both hand
     * the destination a fresh rc=1 buffer adopted at the element flavor.
     */
    private function storeMakesArrayCopy(StoreLocal $sl): bool
    {
        if ($this->copiedArrayAlias($sl)) { return true; }
        $v = $sl->value;
        return ($v->kind === Node::KIND_PROPERTY_ACCESS || $v->kind === Node::KIND_STATIC_PROP)
            && $v->type->isVec();
    }

    /**
     * `$b = $a` where the emitter is CERTAIN to copy: an array-typed local read
     * whose source or destination this function element-stores into.
     *
     * ⚠ The emitter's `mutatedVecLocals` is WIDER (unset, by-ref, array_pop &c),
     * and that asymmetry is the safe one: every name this answers true for is in
     * the emitter's set too, so the copy it promises really is emitted. Answering
     * true where no copy happened would schedule a release on a SHARED buffer.
     */
    private function copiedArrayAlias(StoreLocal $sl): bool
    {
        $v = $sl->value;
        if ($v->kind !== Node::KIND_LOAD_LOCAL) { return false; }
        if (!$v->type->isArray()) { return false; }
        return isset($this->elemMutatedLocals[$v->name])
            || isset($this->elemMutatedLocals[$sl->name]);
    }

    /** Names element-stored into directly — the narrow half of the emitter's
     *  mutation scan ({@see copiedArrayAlias} for why narrow is the safe side). */
    private function collectElemMutated(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $arr = $this->storeElementBase($n);
            if ($arr->kind === Node::KIND_LOAD_LOCAL && $arr->type->isArray()) {
                $this->elemMutatedLocals[$arr->name] = true;
            }
        }
        foreach (Walk::children($n) as $c) { $this->collectElemMutated($c); }
    }

    /** Read through a StoreElement-typed param so `->array` resolves the right
     *  field offset under the self-host. */
    private function storeElementBase(\Compile\Mir\StoreElement $n): Node
    {
        return $n->array;
    }

    /** {@see \Compile\Mir\Ownership::erasedArrayPropRead} */
    private function erasedArrayPropRead(Node $value): bool { return $this->own->erasedArrayPropRead($value); }

    /**
     * The rc release FLAVOR a slot of this type takes. Two stores to one name
     * that disagree here have no single release that is right for both: the
     * helpers differ in where the refcount sits and where the allocation begins
     * ({@see \Compile\MemoryAbi}), so picking either one corrupts the other's
     * value. Cell is its own flavor — `__mir_cell_drop` is tag-dispatched.
     */
    private function rcSlotFlavor(Type $t): string
    {
        $k = $t->kind;
        if ($k === Type::KIND_CELL) { return 'cell'; }
        if ($k === Type::KIND_STRING) { return 'str'; }
        if ($k === Type::KIND_ARRAY) {
            // An array of ARRAYS carries its INNER element's flavor in the
            // release helper it picks ({@see \Compile\Mir\Passes\
            // EmitLlvmMemory::nestedArrFlavor}), so two stores that disagree
            // about it pick DIFFERENT helpers for one slot — and first-write-
            // wins hands the loser's buffer to the winner's walk. `$g =
            // [row($i), ['s']]` then `$g = [[$i], [$i+1]]` released a vec of
            // INTS through `__mir_array_release_ownel_arrstr`, which read each
            // int as a string pointer and dereferenced `1 - 8`. Name the
            // difference so the existing flavor gate blocks it: a leak, never a
            // free of a tag. Shallower slots are unaffected — `arr` compares
            // equal to `arr` exactly as before.
            $el = $t->element;
            if ($el === null || $el->kind !== Type::KIND_ARRAY) { return 'arr'; }
            // Walk the WHOLE nesting chain: the helper is picked per level
            // ({@see \Compile\Mir\Passes\EmitLlvmMemory::nestedArrFlavor}),
            // so a disagreement at ANY level picks a different one.
            $name = 'arr';
            $cur = $el;
            while ($cur !== null && $cur->kind === Type::KIND_ARRAY) {
                $name = $name . ':arr';
                $cur = $cur->element;
            }
            return $name . ':' . ($cur === null ? '?' : (string)$cur->kind);
        }
        if ($k === Type::KIND_OBJ) { return 'obj'; }
        return '';
    }

    /** Whether a SLOT of this type is rc-managed at all. A concrete scalar slot
     *  is not: the flavor ladder's `obj` default would rc-release an int. */
    private function slotTypeIsRc(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_OBJ || $k === Type::KIND_ARRAY
            || $k === Type::KIND_STRING || $k === Type::KIND_CELL
            || $k === Type::KIND_CLOSURE;
    }

    /** A store that neither owns nor borrows: a string LITERAL (immortal, `rc <
     *  0`, and `__mir_rc_release_str` self-guards it) or `null` (the slot holds
     *  0, and every release helper null-guards). */
    private function isRcNeutralStore(Node $value): bool
    {
        if ($value->kind === Node::KIND_STRING_CONST) { return true; }
        return $value->kind === Node::KIND_NULL_CONST
            || $value->type->kind === Type::KIND_NULL;
    }

    private function isNonRcScalar(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_INT || $k === Type::KIND_FLOAT
            || $k === Type::KIND_BOOL || $k === Type::KIND_NULL;
    }

    /**
     * Decide every name that took owned stores into BOTH a raw slot and a cell
     * slot. That is InferTypes' flow-sensitive promotion: the slot is raw up to
     * an if/else merge and a cell after it, where a self-boxing `$x = box($x)`
     * converts it in place. It used to be blocked outright — a leak of the whole
     * value on every call (`$d = ''; $d .= $p; if ($z) { $q = $n ? null : f($d);
     * …; $d = $q; } return $d;`, ~2.5x the string per call).
     *
     * A raw STRING or OBJECT is boxed by tagging the same pointer, so the box-back
     * moves the one reference and the slot owns exactly one value throughout; an
     * ARRAY box-back may rebuild the buffer as a cell array that co-owns every
     * element, and the emitter then gives the raw predecessor back on the spot.
     * The release only has to know which representation the slot holds when it
     * runs: the emitter keeps a flag beside the slot, written by every store
     * ({@see slotStoredType}) — "a raw rc pointer" or not (a cell, a raw scalar)
     * — so the answer is exact at every return, overwrite and scope exit. A
     * closure / struct / enum / Generator has no tag a cell drop can trust, a
     * param arrives holding the caller's value, a foreach binding has its own
     * ownership path and a generator's locals live in its frame: all of those
     * stay blocked (a leak, never a free of a tag).
     */
    private function settleMixedSlots(FunctionDef $fn): void
    {
        $params = [];
        foreach ($fn->params as $p) { $params[$p->name] = true; }
        foreach ($this->rcObjRawType as $name => $rawT) {
            if (!isset($this->rcObjCellSeen[$name])) { continue; }
            if (isset($this->rcObjBlocked[$name])) { continue; }
            if (!$fn->isGenerator && !isset($params[$name])
                && !isset($this->rcObjForeachVar[$name]) && !isset($this->rcObjRefName[$name])
                && $this->mixableRaw($rawT)) {
                $this->rcObjMixed[$name] = true;
                $this->rcObjType[$name] = $rawT;
                continue;
            }
            $this->rcObjBlocked[$name] = true;
            $this->noteBlock($name, "repr", $rawT);
        }
        foreach ($this->rcObjRawScalar as $name => $ignored) {
            if (isset($this->rcObjMixed[$name])) { continue; }
            $this->rcObjBlocked[$name] = true;
            $this->noteBlock($name, "notowned", $this->rcObjType[$name] ?? null);
        }
        // Any other name whose slot type disagrees with a cell store it took
        // (registered by a path that recorded no raw type) keeps the block.
        foreach ($this->rcObjCellSeen as $name => $ignored) {
            if (isset($this->rcObjMixed[$name])) { continue; }
            $t = $this->rcObjType[$name] ?? null;
            if ($t === null || $t->kind === Type::KIND_CELL) { continue; }
            $this->rcObjBlocked[$name] = true;
            $this->noteBlock($name, "repr", $t);
        }
    }

    /**
     * Every local a reference can reach, or that lives outside the frame: a
     * `static` / `global` binding, either side of `$r = &$d`, a `$r = &f()`
     * target, a by-ref closure capture, a by-ref foreach (its variable and the
     * array it walks), the root of a `&` address or reference cell, and the
     * root of every argument a by-ref parameter receives. A write through any
     * of those reaches the slot without its StoreLocal, so a MIXED slot's flag
     * would not see it ({@see settleMixedSlots}). An unresolved callee pins
     * every local argument (a false pin is a leak, a miss a double free).
     */
    private function collectRefNames(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $this->rcObjRefName[$this->asStaticLocalDecl($n)->name] = true;
        } elseif ($k === Node::KIND_REF_ALIAS) {
            $ra = $this->asRefAlias($n);
            $this->rcObjRefName[$ra->target] = true;
            $this->rcObjRefName[$ra->source] = true;
        } elseif ($k === Node::KIND_REF_BIND) {
            $this->rcObjRefName[$this->asRefBind($n)->target] = true;
        } elseif ($k === Node::KIND_REF_ADDR) {
            $rd = $this->asRefAddr($n);
            $this->rcObjRefName[$rd->target] = true;
            $this->refRoot($rd->lvalue);
        } elseif ($k === Node::KIND_REF_CELL) {
            $this->refRoot($this->asRefCell($n)->refSource);
        } elseif ($k === Node::KIND_CLOSURE) {
            $cl = $this->asClosure($n);
            $i = 0;
            foreach ($cl->captures as $cap) {
                if ($cl->captureByRef[$i] ?? false) { $this->refRoot($cap); }
                $i = $i + 1;
            }
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeachNode($n);
            if ($fe->byRef) {
                $this->rcObjRefName[$fe->valueVar] = true;
                $this->refRoot($fe->array);
            }
        } else {
            $this->refArgRoots($n);
        }
        foreach (Walk::children($n) as $c) { $this->collectRefNames($c); }
    }

    /** Mark the local at the bottom of an lvalue chain. */
    private function refRoot(Node $n): void
    {
        $cur = $n;
        while (true) {
            $k = $cur->kind;
            if ($k === Node::KIND_LOAD_LOCAL) {
                $this->rcObjRefName[$this->asLoadLocal($cur)->name] = true;
                return;
            }
            if ($k === Node::KIND_PROPERTY_ACCESS) { $cur = $this->asPropertyAccessNode($cur)->object; }
            elseif ($k === Node::KIND_ARRAY_ACCESS) { $cur = $this->asArrayAccessNode($cur)->array; }
            else { return; }
        }
    }

    /** The by-ref argument roots of a call-shaped node. */
    private function refArgRoots(Node $n): void
    {
        $k = $n->kind;
        $fn = '';
        $offset = 0;
        $args = [];
        $builtin = false;
        if ($k === Node::KIND_CALL) {
            $c = $this->asCallNode($n);
            $fn = \ltrim($c->function, '\\');
            $args = $c->args;
            $builtin = true;
        } elseif ($k === Node::KIND_STATIC_CALL) {
            $sc = $this->asStaticCallNode($n);
            $fn = $this->resolveMethodFn($sc->class, $sc->method);
            $args = $sc->args;
        } elseif ($k === Node::KIND_NEW_OBJ) {
            $no = $this->asNewObjNode($n);
            $fn = $this->resolveMethodFn($no->class, '__construct');
            $args = $no->args;
            $offset = 1;
        } elseif ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->asMethodCallNode($n);
            $recv = $mc->object->type->class ?? '';
            $fn = $recv === '' ? '' : $this->resolveMethodFn($recv, $mc->method);
            $args = $mc->args;
            $offset = 1;
        } elseif ($k === Node::KIND_INVOKE) {
            $iv = $this->asInvokeNode($n);
            $fn = $iv->callee->type->class ?? '';
            $args = $iv->args;
            $offset = $this->closureCaptureCount[$fn] ?? 0;
        } else {
            return;
        }
        if (!isset($this->refMasks[$fn])) {
            if ($builtin) {
                if (\count($args) > 0 && ($fn === 'current' || $fn === 'pos' || $fn === 'key'
                    || $fn === 'next' || $fn === 'prev' || $fn === 'reset' || $fn === 'end'
                    || $fn === 'array_pop' || $fn === 'array_shift' || $fn === 'array_unshift')) {
                    $this->refRoot($args[0]);
                }
                return;
            }
            foreach ($args as $a) { $this->refRoot($a); }
            return;
        }
        $mask = $this->refMasks[$fn];
        $cnt = \count($mask);
        $tail = $this->refVariadic[$fn] ?? false;
        $i = 0;
        foreach ($args as $a) {
            $p = $i + $offset;
            $byRef = $p < $cnt ? $mask[$p] : false;
            if (!$byRef && $tail && $p >= $cnt - 1) { $byRef = true; }
            if ($byRef) { $this->refRoot($a); }
            $i = $i + 1;
        }
    }

    private function resolveMethodFn(string $class, string $method): string
    {
        $cur = $class;
        $guard = 0;
        while ($cur !== '' && $guard < 64) {
            $cand = $cur . '__' . $method;
            if (isset($this->refMasks[$cand])) { return $cand; }
            $cur = isset($this->classes[$cur]) ? $this->classes[$cur]->parent : '';
            $guard = $guard + 1;
        }
        return '';
    }

    private function asStaticLocalDecl(Node $n): \Compile\Mir\StaticLocalDecl_ { return $n; }
    private function asRefAlias(Node $n): \Compile\Mir\RefAlias_ { return $n; }
    private function asRefBind(Node $n): \Compile\Mir\RefBind_ { return $n; }
    private function asRefAddr(Node $n): \Compile\Mir\RefAddr_ { return $n; }
    private function asRefCell(Node $n): \Compile\Mir\RefCell_ { return $n; }
    private function asClosure(Node $n): \Compile\Mir\Closure_ { return $n; }
    private function asForeachNode(Node $n): \Compile\Mir\Foreach_ { return $n; }
    private function asLoadLocal(Node $n): LoadLocal { return $n; }
    private function asPropertyAccessNode(Node $n): \Compile\Mir\PropertyAccess_ { return $n; }
    private function asArrayAccessNode(Node $n): \Compile\Mir\ArrayAccess_ { return $n; }
    private function asCallNode(Node $n): \Compile\Mir\Call { return $n; }
    private function asStaticCallNode(Node $n): \Compile\Mir\StaticCall_ { return $n; }
    private function asNewObjNode(Node $n): \Compile\Mir\NewObj { return $n; }
    private function asMethodCallNode(Node $n): \Compile\Mir\MethodCall_ { return $n; }
    private function asInvokeNode(Node $n): \Compile\Mir\Invoke_ { return $n; }

    /** A raw slot type a cell can hold and a tagged drop can release. An array
     *  box-back may REBUILD the buffer; the emitter then releases the raw
     *  predecessor itself ({@see EmitLlvmLocals::emitStoreLocal}). */
    private function mixableRaw(Type $t): bool
    {
        if ($t->kind === Type::KIND_STRING) { return true; }
        if ($t->kind === Type::KIND_ARRAY) { return true; }
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        $cls = $t->class ?? '';
        if ($cls === '' || $cls === 'Generator') { return false; }
        return $this->objClassIsRc($cls);
    }

    /** {@see \Compile\Mir\Ownership::objClassIsRc} */
    private function objClassIsRc(string $cls): bool { return $this->own->objClassIsRc($cls); }

    /**
     * Walk the tree: flag any Arena allocation (drives the frame arena
     * scope) and collect NoRefcount owned locals (drive per-local
     * releases).
     */
    private function scanStores(Node $n): void
    {
        if (($n->effects & Effects::ALLOC) !== 0 && $n->allocKind === AllocationKind::ARENA) {
            $this->hasArena = true;
        }

        // A `foreach (... as $k => $v)` binds BORROWED container elements
        // into the key / value slots with no retain (emitForeach stores the
        // raw element). When the same local name is *also* assigned an owned
        // value elsewhere (`foreach (Walk::children($n) as $c)` next to a
        // `$c = $this->asCmp($n)`), it would be marked an owned RcHeap local
        // and released at scope exit — freeing a still-referenced element
        // (the `fact` arg-node UAF: a vec[Node] child dropped while the
        // parent still owns it). Disqualify foreach loop vars from release.
        if ($n->kind === Node::KIND_FOREACH) {
            $fe = $n;
            // …UNLESS the loop now takes a +1 of its own. The block above exists
            // precisely BECAUSE the store was a raw borrow; with the retain the
            // reason is gone and the release is the other half of it
            // ({@see foreachValueCoOwns}). Registered at the ELEMENT's type —
            // depth follows the DESTINATION slot, never the container.
            $feOwns = $this->feFnEnabled
                && self::foreachValueCoOwns($fe, $this->enums, $this->classes)
                && !isset($this->feOwnVeto[$fe->valueVar]);
            if ($feOwns) {
                $et = self::foreachValueSlotType($fe, $this->enums, $this->classes);
                // ★★★ The loop variable is a STORE like any other, so it owes the
                // same FLAVOR agreement {@see rcSlotFlavor} enforces on
                // KIND_STORE_LOCAL. Registering `rcObjType` directly here walked
                // straight past that check: a name bound by a foreach over
                // `Node[]` and stored as a string elsewhere kept ONE release,
                // first-write-wins, and an object released through
                // `__mir_rc_release_str` reads rc at ptr-8 and frees from ptr-16
                // — a WILD WRITE through the wrong header offset, which is why
                // no rc<=0 verify ever fired and why the faulting word was never
                // a plausible heap pointer.
                if ($et !== null && isset($this->rcObjType[$fe->valueVar])) {
                    $prev = $this->rcSlotFlavor($this->rcObjType[$fe->valueVar]);
                    $now = $this->rcSlotFlavor($et);
                    if ($prev !== '' && $now !== '' && $prev !== $now) {
                        $this->rcObjBlocked[$fe->valueVar] = true;
                        $this->noteBlock($fe->valueVar, "flavor", $et);
                    }
                }
                if ($et !== null && !isset($this->rcObjType[$fe->valueVar])) {
                    $this->rcObjOrder[] = $fe->valueVar;
                    $this->rcObjType[$fe->valueVar] = $et;
                    $this->rcObjSlotBoxed[$fe->valueVar] = $et->kind === Type::KIND_CELL;
                    $this->rcObjPlainOwner[$fe->valueVar] = true;
                }
                // The binding is a store of the element's repr: it takes part in
                // the raw-vs-cell decision {@see settleMixedSlots} makes.
                if ($et !== null && $et->kind === Type::KIND_CELL) {
                    $this->rcObjCellSeen[$fe->valueVar] = true;
                } elseif ($et !== null && !isset($this->rcObjRawType[$fe->valueVar])) {
                    $this->rcObjRawType[$fe->valueVar] = $et;
                }
            } else {
                $this->rcObjBlocked[$fe->valueVar] = true;
                $this->noteBlock($fe->valueVar, "foreach", null);
            }
            $this->rcObjForeachVar[$fe->valueVar] = true;
            // `blocked` is the ARENA/alloc-flavor set, not the rc one: the loop
            // var is never an allocation of this frame either way.
            $this->blocked[$fe->valueVar] = true;
            if ($fe->keyVar !== null) {
                $this->rcObjBlocked[$fe->keyVar] = true;
                $this->blocked[$fe->keyVar] = true;
            }
        }

        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $n;
            $name = $sl->name;
            $value = $sl->value;
            // Track RcHeap obj ownership. Any store of a non-owned-obj
            // value to this name blocks it (a scope-exit release could
            // double-free / over-release a borrow); an owned-obj store
            // (a `new` or an obj-returning call — both yield rc=1)
            // registers it.
            $slotType = self::slotStoredType($sl);
            $boxedSlot = $slotType->kind === Type::KIND_CELL;
            // An ERASED array-property read carries KIND_UNKNOWN, which has no rc
            // flavor at all — so even once it is owned (below) the release ladder
            // would find nothing to emit. Name the array explicitly, and name it
            // as the EMITTER's retain names it: `Type::vec(Type::unknown())`, the
            // fallback {@see EmitLlvmLocals::emitStoreLocal} hands
            // {@see EmitLlvmMemory::rcRetainByType} for exactly this store. Both
            // sides then land on the repr-driven `__mir_array_(retain|release)`.
            // The KIND_UNKNOWN test is what keeps the cell-slot box-back arm out:
            // there `slotStoredType` answers the SLOT's cell type, not this.
            if ($slotType->kind === Type::KIND_UNKNOWN
                && $this->erasedArrayPropRead($value)) {
                $slotType = Type::vec(Type::unknown());
                $this->rcObjErasedProp[$name] = true;
            }
            // A property read owns BY RETAIN, and the arm that boxes a concrete
            // value into a cell slot ({@see EmitLlvmLocals::emitStoreLocal}, the
            // merge box-back) returns BEFORE the alias retain the general store
            // path emits. Claiming ownership there plants a release with no
            // matching retain — this pass's own invariant, and the crash it
            // predicts: `$conds = $arm->conds;` in InferTypes::inferMatch went
            // through the box-back, and the gen-2 compiler wrote through a freed
            // buffer at `str x8, [x0]` with x0 == 0. An allocation-owned producer
            // (a call's +1) keeps its reference through the same arm, so only the
            // retain-owned one is dropped here.
            // An ELEMENT read owns by retain exactly as a property read does
            // ({@see \Compile\Debug::$rcElemReadOwns}), so it inherits the same
            // exclusion: the box-back arm returns before the retain, and
            // claiming ownership there is a release with no matching retain.
            // …and a STATIC vec read, owned by the COPY the general path makes
            // ({@see isOwnedObj}): the box-back arm returns before that copy
            // too, boxing the static's own buffer by pointer.
            // The merge box-back `$x = box($x)` converts the slot IN PLACE: the
            // one reference it held moves into the cell (the emitter gives back
            // a rebuilt array's raw predecessor itself). It neither owns nor
            // borrows — only the slot's representation changes, which is what
            // {@see settleMixedSlots} decides on.
            if ($boxedSlot && $value->kind === Node::KIND_LOAD_LOCAL
                && $value->name === $name && $value->type->kind !== Type::KIND_CELL) {
                $this->rcObjCellSeen[$name] = true;
                $this->blocked[$name] = true;
                return;
            }
            $ownedByRetain = $value->kind === Node::KIND_PROPERTY_ACCESS
                || (\Compile\Debug::$rcElemReadOwns && $value->kind === Node::KIND_ARRAY_ACCESS)
                || ($value->kind === Node::KIND_STATIC_PROP && $value->type->isVec());
            // `$b = $a` between array locals is a COPY when either side is
            // mutated ({@see \Compile\Mir\VecCopyOnAssign}) — the emitter hands
            // the destination a fresh rc=1 buffer and adopts its elements. That
            // is an owned producer, and it leaves the SOURCE untouched. Reading
            // the alias answer for both is what left `$q = $r;` in a loop with
            // no release for either name.
            $ownedCopy = !$boxedSlot
                && \Compile\Mir\VecCopyOnAssign::copies($value, $name, $this->mutatedVecs);
            // A co-owned alias reaches the same place by the other road: no
            // copy fires, the emitter takes a +1 instead, so the destination
            // owns either way and the SOURCE is safe to release either way —
            // which is why one flag stands for both below.
            if (!$boxedSlot && $value->kind === Node::KIND_LOAD_LOCAL
                && self::arrayAliasCoOwns($value->type, $sl->type, $this->enums, $this->classes)) {
                $ownedCopy = true;
            }
            if (($this->isOwnedObj($value) || $ownedCopy) && !($ownedByRetain && $boxedSlot)) {
                // Two stores that disagree about the slot's REPRESENTATION leave
                // no single release flavor that is right for both — the scope-exit
                // release reads the slot, not the producer. Block: a leak, never a
                // free of a tag.
                // …unless the raw side is one string / object flavor: that pair
                // is decided after the walk ({@see settleMixedSlots}).
                if ($boxedSlot) {
                    $this->rcObjCellSeen[$name] = true;
                } elseif (!isset($this->rcObjRawType[$name])) {
                    $this->rcObjRawType[$name] = $slotType;
                } elseif ($this->rcSlotFlavor($this->rcObjRawType[$name]) !== $this->rcSlotFlavor($slotType)) {
                    $this->rcObjBlocked[$name] = true;
                    $this->noteBlock($name, "flavor", $slotType);
                }
                $this->rcObjSlotBoxed[$name] = $boxedSlot;
                // …and two stores that disagree about the slot's rc FLAVOR are
                // the same defect one level down. The check above only separates
                // BOXED from RAW; a name stored first as a string and later as an
                // object passes it and then takes ONE release for both, chosen
                // first-write-wins — the string one. `__mir_rc_release_str` reads
                // rc at ptr-8 and frees from ptr-16, so an OBJECT released through
                // it decrements a word that is not its refcount and hands the
                // allocator an address that is not its base.
                //
                // Dormant until element reads began to co-own: before that the
                // store took neither a retain nor a release, so the mismatch cost
                // nothing. `EmitLlvm::emitCmp` holds the live case — `$cd` is an
                // SSA register NAME (string) at the tagged-float arm and a
                // `ClassDef` read out of `$this->classes` in the object-`==`
                // unroll. The gen-2 compiler then freed ClassDefs the class table
                // still pointed at: `compare_object_props` died with
                // "free(): invalid pointer" on Linux, and macOS's allocator
                // tolerated the same corruption silently.
                $prevFlavor = isset($this->rcObjType[$name])
                    ? $this->rcSlotFlavor($this->rcObjType[$name]) : '';
                $nowFlavor = $this->rcSlotFlavor($slotType);
                if ($prevFlavor !== '' && $nowFlavor !== '' && $prevFlavor !== $nowFlavor
                    && $prevFlavor !== 'cell' && $nowFlavor !== 'cell') {
                    $this->rcObjBlocked[$name] = true;
                    $this->noteBlock($name, "flavor", $slotType);
                }
                if (!isset($this->rcObjType[$name])) {
                    $this->rcObjOrder[] = $name;
                    $this->rcObjType[$name] = $slotType;
                } elseif (!isset($this->rcObjErasedProp[$name])
                        && $this->refinesElement($this->rcObjType[$name], $slotType)) {
                    // FIRST-WRITE-WINS was wrong for the element type. `$a = []`
                    // is `vec[unknown]`, so the release flavor froze as a plain
                    // `vec` — buffer only — while inference later refined the
                    // local to `vec[obj<T>]`. Every element then leaked: the
                    // emitter called __mir_array_release where
                    // __mir_array_release_obj was needed. That is
                    // `$filtered = []; $filtered[] = $tok; $this->tokens =
                    // $filtered;` in Parser::__construct, i.e. 9,236,608 leaked
                    // Lexer\\Token on the Doctrine tier.
                    //
                    // Only ever UNKNOWN -> concrete, and only the element: the
                    // slot's own kind and boxedness are unchanged, so this adds
                    // depth to a release that already ran, never a different one.
                    $this->rcObjType[$name] = $slotType;
                }
                if (!CondOwn::isConditional($value)) { $this->rcObjPlainOwner[$name] = true; }
                // Track whether EVERY owned store to this name hands it a COPY.
                $isCopy = $ownedCopy || $this->storeMakesArrayCopy($sl);
                if (!isset($this->rcObjCopyOnly[$name])) { $this->rcObjCopyOnly[$name] = $isCopy; }
                elseif (!$isCopy) { $this->rcObjCopyOnly[$name] = false; }
            } elseif ($this->isRcNeutralStore($value)
                || ($boxedSlot && $this->isNonRcScalar($value->type))) {
                // A scalar boxed into a CELL slot is a value, not a reference:
                // `$x = 0;` ahead of a loop that re-kinds `$x` to a string
                // blocked the name, and every string the loop stored leaked.
                // Decided after the walk — a neutral store only survives when
                // EVERY owned store to the name is a conditional.
                $this->rcObjNeutral[$name] = true;
            } elseif ($this->isNonRcScalar($value->type) && $this->isNonRcScalar($slotType)) {
                // A RAW scalar owns nothing either, but only a MIXED slot can
                // tell it from a raw pointer at release time (its flag says "not
                // a raw rc value"); anywhere else it keeps the block.
                $this->rcObjRawScalar[$name] = true;
            } else {
                $this->rcObjBlocked[$name] = true;
                $this->noteBlock($name, "notowned", $value->type);
            }
            // Aliasing a vec (`$b = $a`) leaves two locals sharing one
            // buffer (no obj-style alias retain for vecs — they COW-copy
            // on mutation). Block the source so we never rc-release a
            // shared vec twice.
            //
            // …unless the store COPIED or co-owned, which is the one case where
            // the premise is false: the destination has its own buffer (or its
            // own +1), so there is no shared vec and nothing to release twice.
            // Blocking the source there is a pure leak of a buffer this frame
            // built — `$next = []; …; $args = $next;` never freed `$next`.
            if (!$ownedCopy && $value->kind === Node::KIND_LOAD_LOCAL
                && $value->type->kind === Type::KIND_ARRAY) {
                $this->rcObjBlocked[$value->name] = true;
                $this->noteBlock($value->name, "vecalias", $value->type);
            }
            $flavor = $this->allocFlavor($value);
            if ($flavor === null) {
                // Non-owning store (borrow / scalar / RcHeap escape):
                // the frame can't free this local at scope exit.
                $this->blocked[$name] = true;
                $this->noteBlock($name, "noflavor", $value->type);
            } else {
                if (!isset($this->ownedFlavor[$name])) {
                    $this->ownedOrder[] = $name;
                    $this->ownedFlavor[$name] = $flavor;
                    $this->ownedType[$name] = $value->type;
                }
            }
            $this->scanStores($value);
            return;
        }
        // A LOAD carries the refined type. `$a = []` is the only STORE to the
        // name — the appends are store_element — so the concrete element type
        // exists nowhere but on the loads inference later retyped. Reading it
        // here is what turns `__mir_array_release` into
        // `__mir_array_release_obj` for the slot.
        if ($n->kind === Node::KIND_LOAD_LOCAL && isset($this->rcObjType[$n->name])
            && $this->refinesElement($this->rcObjType[$n->name], $n->type)) {
            $this->rcObjType[$n->name] = $n->type;
        }
        foreach (Walk::children($n) as $c) { $this->scanStores($c); }
    }

    /**
     * Heap flavor of `$value` iff it is a NoRefcount allocation — the
     * per-local release case (rc mode). Null otherwise (not an alloc,
     * arena, escapes, or non-heap type).
     */
    private function allocFlavor(Node $value): ?string
    {
        if (($value->effects & Effects::ALLOC) === 0) { return null; }
        if ($value->allocKind !== AllocationKind::NO_REFCOUNT) { return null; }
        return $this->flavorOfType($value->type);
    }

    private function flavorOfType(Type $t): ?string
    {
        $k = $t->kind;
        if ($k === Type::KIND_STRING)  { return 'string'; }
        if ($t->isVec())               { return 'vec'; }
        if ($t->isAssoc())             { return 'assoc'; }
        if ($this->isClosureType($t))  { return 'closure'; }
        if ($k === Type::KIND_OBJ)     { return 'obj'; }
        if ($k === Type::KIND_CLOSURE) { return 'closure'; }
        if ($k === Type::KIND_CELL)    { return 'cell'; }
        return null;
    }

    /** KIND_CLOSURE, or the `obj<__closure_N>` / `obj<Closure>` handle — one
     *  question, asked identically by the release flavor and the emitter. */
    private function isClosureType(Type $t): bool
    {
        if ($t->kind === Type::KIND_CLOSURE) { return true; }
        if ($t->kind !== Type::KIND_OBJ) { return false; }
        $cls = $t->class ?? '';
        return $cls === 'Closure' || \str_starts_with($cls, '__closure_');
    }
}
