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

        $fn->body->stmts = $stmts;
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
            $this->rcObjBlocked[$fe->valueVar] = true;
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
            if ($this->isOwnedObj($value)) {
                if (!isset($this->rcObjType[$name])) {
                    $this->rcObjOrder[] = $name;
                    $this->rcObjType[$name] = $value->type;
                }
                if (!CondOwn::isConditional($value)) { $this->rcObjPlainOwner[$name] = true; }
            } elseif ($this->isRcNeutralStore($value)) {
                // Decided after the walk — a neutral store only survives when
                // EVERY owned store to the name is a conditional.
                $this->rcObjNeutral[$name] = true;
            } else {
                $this->rcObjBlocked[$name] = true;
            }
            // Aliasing a vec (`$b = $a`) leaves two locals sharing one
            // buffer (no obj-style alias retain for vecs — they COW-copy
            // on mutation). Block the source so we never rc-release a
            // shared vec twice.
            if ($value->kind === Node::KIND_LOAD_LOCAL
                && $value->type->kind === Type::KIND_ARRAY) {
                $this->rcObjBlocked[$value->name] = true;
            }
            $flavor = $this->allocFlavor($value);
            if ($flavor === null) {
                // Non-owning store (borrow / scalar / RcHeap escape):
                // the frame can't free this local at scope exit.
                $this->blocked[$name] = true;
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
