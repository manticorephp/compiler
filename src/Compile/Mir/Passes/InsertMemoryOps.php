<?php

namespace Compile\Mir\Passes;

use Compile\Mir\AllocationKind;
use Compile\Mir\Block;
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

        $this->scanStores($fn->body);

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
            $flavor = $type->kind === Type::KIND_STRING ? 'str'
                : ($type->isVec() ? 'vec'
                : ($type->isAssoc() ? 'assoc' : 'obj'));
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
     * Whether `$value` yields an owned (rc=1) object: a `new X()`
     * allocation, or an obj-returning call (the +1 return convention
     * transfers ownership to us). Borrowed producers — a LoadLocal alias,
     * property / array read — are excluded: releasing them would
     * over-release the real owner's count.
     */
    private function isOwnedObj(Node $value): bool
    {
        $tk = $value->type->kind;
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY
            && $tk !== Type::KIND_STRING) { return false; }
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
            return !isset($this->ffiFns[$this->asCall($value)->function]);
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
        return $k === Node::KIND_ARRAY_LIT;
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
            $fe = $this->asForeach($n);
            $this->rcObjBlocked[$fe->valueVar] = true;
            $this->blocked[$fe->valueVar] = true;
            if ($fe->keyVar !== null) {
                $this->rcObjBlocked[$fe->keyVar] = true;
                $this->blocked[$fe->keyVar] = true;
            }
        }

        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
            $name = $sl->name;
            $value = $sl->value;
            // Track RcHeap obj ownership. Any store of a non-owned-obj
            // value to this name blocks it (a scope-exit release could
            // double-free / over-release a borrow); an owned-obj store
            // (a `new` or an obj-returning call — both yield rc=1)
            // registers it.
            if (!$this->isOwnedObj($value)) {
                $this->rcObjBlocked[$name] = true;
            } elseif (!isset($this->rcObjType[$name])) {
                $this->rcObjOrder[] = $name;
                $this->rcObjType[$name] = $value->type;
            }
            // Aliasing a vec (`$b = $a`) leaves two locals sharing one
            // buffer (no obj-style alias retain for vecs — they COW-copy
            // on mutation). Block the source so we never rc-release a
            // shared vec twice.
            if ($value->kind === Node::KIND_LOAD_LOCAL
                && $value->type->kind === Type::KIND_ARRAY) {
                $this->rcObjBlocked[$this->asLoadLocal($value)->name] = true;
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
        if ($k === Type::KIND_OBJ)     { return 'obj'; }
        if ($k === Type::KIND_CLOSURE) { return 'obj'; }
        if ($k === Type::KIND_CELL)    { return 'cell'; }
        return null;
    }

    private function asStoreLocal(Node $n): \Compile\Mir\StoreLocal { return $n; }
    private function asLoadLocal(Node $n): \Compile\Mir\LoadLocal { return $n; }
    private function asCall(Node $n): \Compile\Mir\Call { return $n; }
    private function asForeach(Node $n): \Compile\Mir\Foreach_ { return $n; }
}
