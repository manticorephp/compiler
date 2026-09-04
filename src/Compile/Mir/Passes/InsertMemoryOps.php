<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\Block;
use Compile\Mir\CondOwn;
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

    /** @var array<string, string> census only: blocked local → which gate blocked it. */
    private array $blockReason = [];

    /** @var array<string, string> census only: blocked local → the value's type kind. */
    private array $blockKind = [];

    /** @var array<string, bool> FFI function names (foreign, non-rc return) */
    private array $ffiFns = [];

    /** @var array<string, \Compile\Mir\ClassDef> class name → layout */
    private array $classes = [];
    /** @var array<string, mixed> enum name → def (enum values are non-rc). */
    private array $enums = [];

    public function run(Module $module): Module
    {
        $this->classes = $module->classes;
        $this->enums = $module->enums;
        // FFI functions return FOREIGN values (raw libc buffers/pointers
        // from calloc/malloc/fopen/...) that do NOT follow the +1 owned
        // return convention and carry no rc header — never rc-track them.
        $this->ffiFns = [];
        foreach ($module->functions as $fn) {
            if ($fn->ffiSymbol !== null) { $this->ffiFns[$fn->name] = true; }
        }
        foreach ($module->functions as $fn) {
            $this->lowerFunction($fn);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function lowerFunction(FunctionDef $fn): void
    {
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
        $this->blockReason = [];
        $this->blockKind = [];
        // Per-name, whole-function: a loop variable co-owns only if EVERY foreach
        // binding it does ({@see foreachOwnVetoes}). Computed BEFORE the walk,
        // because the arm that registers a name runs before the later loop that
        // would veto it.
        $this->feOwnVeto = self::foreachOwnVetoes($fn->body, $this->enums);

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
        foreach ($fn->params as $p) {
            $this->blocked[$p->name] = true;
            $this->rcObjBlocked[$p->name] = true;
            $this->noteBlock($p->name, 'param', $p->type);
        }

        $this->scanStores($fn->body);

        // ONE exception to the blanket param block above: a BY-VALUE string
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

        // A `$x = null;` / `$x = '';` seed neither owns nor borrows, so it must
        // not disqualify the local: the slot then holds 0, an immortal literal
        // (both self-guarded by every release helper) or a genuine +1. Without
        // this the seed of every accumulator disqualified it — `$out = '';`
        // ahead of `$out = $c ? $s : ($out . ',' . $s);` left the arm retain the
        // ownership contract pays for ({@see isOwnedCond}) with nothing to
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
            $t = $this->rcObjType[$name] ?? null;
            if ($t !== null && $t->kind === Type::KIND_STRING) { continue; }
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

        $this->censusFunction(\count($releases), \count($rcReleases));
        $fn->body->stmts = $stmts;
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
    public static function elemReadCoOwns(?Type $t, array $enums): bool
    {
        if ($t === null) { return false; }
        if ($t->isVec() || $t->isAssoc()) { return true; }
        // A STRING element is co-owned on exactly the same terms, and leaving it
        // out was the last hole: `$s = $m['k']; $m['k'] = '';` and its `foreach`
        // twin handed back FREED bytes the moment the element SLOT started
        // dropping ({@see \Compile\Debug::$rcElemSlotDrop}). Both rc helpers
        // self-guard — `__mir_rc_retain_str` / `__mir_rc_release_str` no-op on
        // null and on an IMMORTAL literal (negative rc) — so a slot holding a
        // constant costs nothing and a heap string is counted like any other.
        if ($t->kind === Type::KIND_STRING) { return true; }
        if ($t->kind === Type::KIND_OBJ) {
            $c = $t->class ?? '';
            return !($c !== '' && isset($enums[$c]));
        }
        return false;
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
    public static function foreachValueCoOwns(\Compile\Mir\Foreach_ $fe, array $enums): bool
    {
        if (!\Compile\Debug::$rcForeachValueOwns) { return false; }
        if ($fe->byRef) { return false; }
        $at = $fe->array->type;
        if (!$at->isVec() && !$at->isAssoc()) { return false; }
        return self::elemReadCoOwns($at->element, $enums);
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
    public static function foreachOwnVetoes(Node $body, array $enums): array
    {
        $veto = [];
        self::collectForeachVetoes($body, $enums, $veto);
        return $veto;
    }

    /** @param array<string, bool> $veto */
    private static function collectForeachVetoes(Node $n, array $enums, array &$veto): void
    {
        if ($n->kind === Node::KIND_FOREACH) {
            $fe = $n;
            if (!self::foreachValueCoOwns($fe, $enums)) { $veto[$fe->valueVar] = true; }
        }
        foreach (Walk::children($n) as $c) { self::collectForeachVetoes($c, $enums, $veto); }
    }

    private function isOwnedObj(Node $value): bool
    {
        // A conditional (ternary / `?:` / `??` / match) the contract covers is an
        // owned producer: the emitter gives EVERY arm a +1 of the result type
        // ({@see EmitLlvmControl::armRetainPostBox}), so the destination local
        // owns it and must release it — that release is what stops the next
        // iteration of `$out = $c ? $s : ($out . ',' . $s);` from handing out a
        // freed block. Tested FIRST: its result may be a UNION (`$c ? new B :
        // new C`), which the kind gate below rejects, and it carries no
        // allocation of its own for the allocKind gate further down.
        //
        // ⚠ This answer must match {@see EmitLlvm::condOwnsResult} exactly. If
        // only the emitter says owned, the value leaks; if only this pass does,
        // the release has no matching retain and the value is double-freed.
        if ($this->isOwnedCond($value)) { return true; }
        // A CLOSURE LITERAL builds a fresh capturing env with rc=1 and a drop fn
        // ({@see EmitLlvmCalls::emitClosure}); the local owns it and releases it
        // at scope exit / before an overwrite, which is what frees both the env
        // and the +1 it took on every captured value. Only the literal counts:
        // a closure ARRIVING from anywhere else (a param, an element read, a
        // call return through an erased channel) stays borrowed, so nothing
        // over-releases a `Closure` this frame did not build.
        if ($value->kind === Node::KIND_CLOSURE) { return true; }
        $tk = $value->type->kind;
        // A CELL counts: `f(): Foo|false` boxes a FRESH object into a cell, and
        // the +1 return convention transfers it to us exactly as for a plain
        // obj. Excluding it meant a cell local was NEVER released — the object
        // leaked and its __destruct never ran (`$r = fopen(...)` is precisely
        // this shape). The producer gate below keeps it symmetric: only a call /
        // new / clone is owned; a LoadLocal alias or an array read stays
        // borrowed, so a boxed value read out of a container is not over-
        // released. The drop itself (__mir_cell_drop) is tag-guarded, so a cell
        // holding an int/float/null is a no-op.
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY
            && $tk !== Type::KIND_STRING && $tk !== Type::KIND_CELL) { return false; }
        // #[Struct] classes have no class_id/rc header (offset 0 is a
        // property) — they must never be rc-managed.
        if ($tk === Type::KIND_OBJ) {
            $cls = $value->type->class ?? '';
            if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) {
                return false;
            }
            // Enum values are ORDINALS (an immortal per-case singleton when
            // boxed) — never rc-managed, whatever produced them. A `from()` /
            // a method returning the enum yields an obj<Enum> STATIC/METHOD call
            // that would otherwise be tracked as a +1 owned heap object and
            // rc_release the ordinal-as-pointer (SIGSEGV).
            if ($cls !== '' && isset($this->enums[$cls])) { return false; }
            // Closures have no rc header (struct is [fn_ptr, captures...]).
            // Both the synthesized `__closure_N` and a `\Closure`-typed slot
            // (class "Closure") hold such a header-less struct.
            if ($cls === 'Closure' || \str_starts_with($cls, '__closure_')) { return false; }
            // Ffi\Ptr is an opaque foreign pointer (FILE*/DIR*/raw addr) with
            // no rc header — rc-releasing it frees libc memory and aborts.
            if ($cls === 'Ffi\\Ptr') { return false; }
            // A Generator frame now carries a string-style rc header
            // (rc@-8, free base = ptr-16) — track it as owned so its frame is
            // freed on the last reference (EmitLlvm routes the release through
            // the str rc path). Its producer is a call/invoke (the creator).
        }
        $k = $value->kind;
        // A call transfers a +1 owned ref (the return convention) for
        // any flavor (incl. string builtins: substr / strtolower / …).
        // EXCEPT an FFI call: it returns a foreign libc buffer/pointer
        // with no rc header — rc-releasing it frees raw memory → abort.
        if ($k === Node::KIND_CALL) {
            // __mir_fiber_current() hands back a BORROWED alias of the
            // @__mir_current_fiber global (owned by the user's own `$f`), not a
            // +1 ref — releasing it at scope exit would free the live fiber
            // mid-run (use-after-free ⇒ a garbage resumer ⇒ jump into hyperspace).
            $fn = \ltrim($value->function, '\\');
            if ($fn === '__mir_fiber_current') { return false; }
            return !isset($this->ffiFns[$value->function]);
        }
        if ($k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) {
            return true;
        }
        // An ELEMENT READ co-owns what it hands out — the emitter retains it in
        // {@see EmitLlvmLocals::emitStoreLocal}, so the local must release it.
        // The two are one change: see {@see \Compile\Debug::$rcElemReadOwns}.
        // Without it `$keep = $m['a']; unset($m);` hands back freed memory.
        // ⚠ The two halves must decide on the SAME predicate, or they disagree
        // on a name and leave a retain with no release — the extra `dtor elem`
        // php never runs. {@see EmitLlvmLocals::elemReadCoOwn} is the other half.
        if (\Compile\Debug::$rcElemReadOwns && $k === Node::KIND_ARRAY_ACCESS
            && self::elemReadCoOwns($value->type, $this->enums)) {
            return true;
        }
        // A PROPERTY read of an ARRAY is owned BY RETAIN rather than by
        // allocation — the one producer this pass could not see, because it gates
        // on `effects->alloc`. {@see EmitLlvmLocals::emitStoreLocal}'s snapshot
        // path already takes a +1 on it (`$saved = $this->map`) so that a later
        // mutation of either side copy-on-writes instead of clobbering the
        // other's buffer; the retain cannot simply be dropped, because a borrow
        // that left rc alone would let a mutation through the local see rc == 1
        // and write THROUGH into the property. The local genuinely owns — and
        // nothing ever released it, neither on a rebind nor at scope exit.
        //
        // That is ROOT 1 of the compiler's own monotone climb: InferTypes::
        // mergeLocals' per-block local-type maps (402 MB of __mir_array_set_str,
        // 69.7% of that allocator) were still resident at a snapshot taken with
        // the process blocked in clang, with nothing on any stack holding them.
        //
        // The slot's REPRESENTATION decides the flavor ({@see slotStoredType}),
        // which is what makes this claim safe: a nullable array property reads
        // back a NaN-boxed cell, and it is released as a cell.
        if ($k === Node::KIND_PROPERTY_ACCESS
            && ($value->type->isVec() || $value->type->isAssoc())) {
            return true;
        }
        // A fresh RcHeap allocation: `new` (obj) / array-literal (vec) /
        // concat (string). Arena values are excluded — freed by the arena
        // scope; rc-releasing them would be wrong (their header is -1 so
        // release no-ops, but don't track them as owned regardless).
        if ($value->allocKind !== AllocationKind::RC_HEAP) { return false; }
        if ($tk === Type::KIND_OBJ) { return $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE; }
        if ($tk === Type::KIND_STRING) { return $k === Node::KIND_CONCAT; }
        // An array-typed `+` is the union operator — __mir_array_union returns a
        // FRESH +1 array, so it is owned exactly like a literal.
        return $k === Node::KIND_ARRAY_LIT
            || ($tk === Type::KIND_ARRAY && $k === Node::KIND_ADD);
    }

    /**
     * Is `$new` the same array shape as `$old` but with a CONCRETE element
     * where `$old` had `unknown`? That is the one upgrade this pass accepts
     * after the first store: it deepens the release, it cannot redirect it.
     */
    private function refinesElement(Type $old, Type $new): bool
    {
        if (!\Compile\Debug::$rcElemType) { return false; }
        if ($old->kind !== $new->kind) { return false; }
        if (!($old->isVec() && $new->isVec()) && !($old->isAssoc() && $new->isAssoc())) {
            return false;
        }
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
    private function slotStoredType(StoreLocal $sl): Type
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

    /** Whether a SLOT of this type is rc-managed at all. A concrete scalar slot
     *  is not: the flavor ladder's `obj` default would rc-release an int. */
    private function slotTypeIsRc(Type $t): bool
    {
        $k = $t->kind;
        return $k === Type::KIND_OBJ || $k === Type::KIND_ARRAY
            || $k === Type::KIND_STRING || $k === Type::KIND_CELL;
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

    /** {@see CondOwn} — the shared half of the contract, plus this pass's own
     *  rc-eligibility guard on the result type. */
    private function isOwnedCond(Node $value): bool
    {
        if (!CondOwn::isConditional($value)) { return false; }
        if (!$this->condResultIsRc($value->type)) { return false; }
        return CondOwn::armsCoverable($value);
    }

    private function condResultIsRc(Type $t): bool
    {
        if (CondOwn::shapeIsRc($t)) { return true; }
        $k = $t->kind;
        if ($k === Type::KIND_OBJ) { return $this->objClassIsRc($t->class ?? ''); }
        if ($k !== Type::KIND_UNION) { return false; }
        $atoms = $t->atoms;
        if (\count($atoms) === 0) { return false; }
        foreach ($atoms as $a) {
            if ($a->kind !== Type::KIND_OBJ) { return false; }
            if (!$this->objClassIsRc($a->class ?? '')) { return false; }
        }
        return true;
    }

    /** ⚠ Character-for-character the obj guards of
     *  {@see EmitLlvm::discardReleaseFlavor} and the union loop of
     *  {@see EmitLlvm::condFlavor} — the two passes must answer identically. */
    private function objClassIsRc(string $cls): bool
    {
        if ($cls === 'Ffi\\Ptr' || $cls === 'Closure') { return false; }
        if (\str_starts_with($cls, '__closure_')) { return false; }
        if ($cls !== '' && isset($this->enums[$cls])) { return false; }
        if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return false; }
        return true;
    }

    /**
     * Walk the tree: flag any Arena allocation (drives the frame arena
     * scope) and collect NoRefcount owned locals (drive per-local
     * releases).
     */
    private function scanStores(Node $n): void
    {
        $e = $n->effects;
        if ($e !== null && $e->alloc && $n->allocKind === AllocationKind::ARENA) {
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
            $feOwns = self::foreachValueCoOwns($fe, $this->enums)
                && !isset($this->feOwnVeto[$fe->valueVar]);
            if ($feOwns) {
                $et = $fe->array->type->element;
                if ($et !== null && !isset($this->rcObjType[$fe->valueVar])) {
                    $this->rcObjOrder[] = $fe->valueVar;
                    $this->rcObjType[$fe->valueVar] = $et;
                    $this->rcObjSlotBoxed[$fe->valueVar] = $et->kind === Type::KIND_CELL;
                    $this->rcObjPlainOwner[$fe->valueVar] = true;
                }
            } else {
                $this->rcObjBlocked[$fe->valueVar] = true;
                $this->noteBlock($fe->valueVar, "foreach", null);
            }
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
            $slotType = $this->slotStoredType($sl);
            $boxedSlot = $slotType->kind === Type::KIND_CELL;
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
            $ownedByRetain = $value->kind === Node::KIND_PROPERTY_ACCESS
                || (\Compile\Debug::$rcElemReadOwns && $value->kind === Node::KIND_ARRAY_ACCESS);
            if ($this->isOwnedObj($value) && !($ownedByRetain && $boxedSlot)) {
                // Two stores that disagree about the slot's REPRESENTATION leave
                // no single release flavor that is right for both — the scope-exit
                // release reads the slot, not the producer. Block: a leak, never a
                // free of a tag.
                if (isset($this->rcObjSlotBoxed[$name])
                    && $this->rcObjSlotBoxed[$name] !== $boxedSlot) {
                    $this->rcObjBlocked[$name] = true;
                    $this->noteBlock($name, "repr", $slotType);
                }
                $this->rcObjSlotBoxed[$name] = $boxedSlot;
                if (!isset($this->rcObjType[$name])) {
                    $this->rcObjOrder[] = $name;
                    $this->rcObjType[$name] = $slotType;
                } elseif ($this->refinesElement($this->rcObjType[$name], $slotType)) {
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
            } elseif ($this->isRcNeutralStore($value)) {
                // Decided after the walk — a neutral store only survives when
                // EVERY owned store to the name is a conditional.
                $this->rcObjNeutral[$name] = true;
            } else {
                $this->rcObjBlocked[$name] = true;
                $this->noteBlock($name, "notowned", $value->type);
            }
            // Aliasing a vec (`$b = $a`) leaves two locals sharing one
            // buffer (no obj-style alias retain for vecs — they COW-copy
            // on mutation). Block the source so we never rc-release a
            // shared vec twice.
            if ($value->kind === Node::KIND_LOAD_LOCAL
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
        $e = $value->effects;
        if ($e === null || !$e->alloc) { return null; }
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
