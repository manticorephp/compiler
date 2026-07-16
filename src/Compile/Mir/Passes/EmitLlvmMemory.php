<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\Block;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\BoolConst;
use Compile\Mir\MethodCall_;
use Compile\Mir\NewObj;
use Compile\Mir\Clone_;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StaticCall_;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RuntimeFeatures;
use Compile\Mir\StringPool;
use Compile\Mir\SsaBuilder;
use Compile\Mir\GeneratorContext;
use Compile\Mir\ControlFlow;
use Compile\Mir\FunctionEmitFrame;
use Compile\Mir\FunctionSignatures;
use Compile\Mir\ArenaContext;
use Compile\Mir\LocalSlots;
use Compile\Mir\RuntimeLibrary;
use Compile\Mir\EmitVisitor;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\MemoryOp_;
use Compile\Mir\Yield_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\While_;
use Compile\Runtime\BareHost;
use Compile\Runtime\UnifiedArrayRuntime;
use Codegen\Llvm\Module as LlvmModule;

/**
 * Memory operations: rc retain / release and arena scope, consumed from the
 * plan InsertMemoryOps produced. This layer never invents an op of its own.
 *
 * A trait on the one {@see EmitLlvm} host — the split is by concern, so a reader
 * opens the file for the thing they are looking at instead of scrolling one
 * 8k-line class. State stays on the host and its collaborators.
 */
trait EmitLlvmMemory
{
    /**
     * Register the owned RcHeap obj locals (the plan's rc_release ops)
     * for the current function and null-init their slots, so a release on
     * a path where the local was never assigned is a no-op rather than a
     * read of garbage.
     */
    private function initRcObjSlots(Node $body, array $paramNames = []): string
    {
        $this->frame->rcObjLocals = [];
        $this->collectRcObjLocals($body);
        $this->frame->paramNames = $paramNames;
        $this->frame->transferredLocals = [];
        $this->collectTransferredLocals($body);
        $this->frame->elementSharedLocals = [];
        $this->collectElementSharedLocals($body);
        $out = '';
        foreach ($this->frame->rcObjLocals as $name => $mo) {
            // A reassigned obj/str/vec/assoc PARAM holds the caller's
            // incoming (borrowed) value. The first `$p = ...` reassignment
            // emits a release-before-overwrite of that old value, and a
            // no-return path releases it at scope exit — both would
            // over-release the caller's reference (a double-free, e.g.
            // `$fqn = ltrim($fqn)` in parseUseDecl). Retain it once on
            // entry so the frame co-owns the slot; the matching release
            // then cancels cleanly. (Slot already holds the incoming arg.)
            if (isset($paramNames[$name])) {
                // A BY-REF param's slot holds the caller's ADDRESS, not the
                // value. Retaining it rc-bumps whatever sits at (addr-8) — the
                // caller's stack — and the paired scope-exit release then frees
                // it: `emit(string &$o) { $o = $o . $s; }` double-released, a
                // corruption that stayed silent only because the bytes it hit
                // happened to be harmless. The caller owns the value; the
                // callee co-owns nothing.
                if (isset($this->locals->refLocals[$name])) { continue; }
                if (isset($this->locals->slots[$name])) {
                    $out .= $this->rcRetainSlot($this->locals->slots[$name], $this->rcReleaseFlavor($mo));
                }
                continue;
            }
            if (isset($this->locals->slots[$name])) {
                $out .= '  store i64 0, ptr ' . $this->locals->slots[$name] . "\n";
            }
        }
        return $out;
    }

    /**
     * B2 escape pre-pass: find owned rcObj locals whose value flows into a
     * BORROWING container store and record them in {@see $transferredLocals}.
     * A "borrowing" store is one where {@see containerStoreRetains} is false —
     * the value's type is erased and the container offers no usable element /
     * property fallback, so the store writes a borrowed reference WITHOUT a
     * retain. Releasing such a local at scope exit over-releases (the
     * container still references it) — the enum/arena heisenbug. Suppressing
     * the release moves ownership to the container instead (leak-safe).
     */
    private function collectTransferredLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_STORE_ELEMENT) {
            // The DESTINATION's element type decides whether the store retains —
            // for a vec exactly as for an assoc, and ONLY for a raw-repr value
            // ({@see storeRetainFallback}): a CELL value is NaN-boxed, and its
            // co-ownership is boxToCell's business, not the element type's.
            $fallback = $this->storeRetainFallback($n);
            $this->maybeTransfer($n->value, $fallback);
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            $pcls = $n->object->type->class ?? '';
            $propType = ($pcls !== '' && isset($this->classes[$pcls]))
                ? ($this->classes[$pcls]->propertyTypes[$n->property] ?? null)
                : null;
            $this->maybeTransfer($n->value, $propType);
        } elseif ($k === Node::KIND_ARRAY_LIT) {
            $fallback = $n->type->element ?? null;
            foreach ($n->elements as $el) { $this->maybeTransfer($el->value, $fallback); }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectTransferredLocals($c); }
    }

    /**
     * Escape pre-pass for element-drop suppression: find owned vec/assoc
     * locals whose buffer is passed BY VALUE to a FACTORY — a call that
     * returns an object (or a `new`) — so the constructed node stores and
     * co-owns the buffer plus its retained element refs (the +1 each
     * `array_append` adds). The local's scope-exit release must then drop the
     * buffer only — see {@see $elementSharedLocals}. This is the parser
     * `$args = parseArgList(); return Expr::call(..., $args, ...)` shape.
     *
     * Gated on an OBJECT result on purpose: a scalar/array-returning callee
     * (`count`, `implode`, `array_map`) READS the buffer without keeping it,
     * so a sole-owned confined vec passed there must keep its element-drop
     * (else its elements leak). A false positive here only leaks (the safe
     * direction); element-drop on a genuinely co-owned buffer would UAF.
     */
    private function collectElementSharedLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_NEW_OBJ) {
            $this->shareCallArgs($n->args);
        } elseif ($n->type->kind === Type::KIND_OBJ) {
            if ($k === Node::KIND_CALL) {
                $this->shareCallArgs($n->args);
            } elseif ($k === Node::KIND_METHOD_CALL) {
                $this->shareCallArgs($n->args);
            } elseif ($k === Node::KIND_STATIC_CALL) {
                $this->shareCallArgs($n->args);
            } elseif ($k === Node::KIND_INVOKE) {
                $this->shareCallArgs($n->args);
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->collectElementSharedLocals($c); }
    }

    /**
     * Walks the function body looking for `StoreLocal` nodes and
     * returns the alloca chunk for the entry block. Subsequent
     * stores / loads address through `$this->locals->slots[$name]`.
     *
     * Self-host pre-scan doesn't propagate `string &$body` writes
     * through nested method calls; returning the chunk and concat-
     * at-call-site is the workaround that holds.
     */
    /**
     * Pre-scan: mark every vec local that is mutated (append or element
     * store) in the function. Drives copy-on-assign value semantics — a
     * `$b = $a` between vecs only needs an independent copy when one of
     * them is later mutated; pure read-only aliases share safely.
     */
    private function collectMutatedVecs(Node $n): void
    {
        if ($n->kind === Node::KIND_STORE_ELEMENT) {
            $arr = $n->array;
            if ($arr->kind === Node::KIND_LOAD_LOCAL
                && $arr->type->isArray()) {
                $this->frame->mutatedVecLocals[$arr->name] = true;
            }
            // A NESTED element store (`$x[0][] = …` / `$x[0][0][] = …`) mutates
            // the root local `$x` too — its base is an `$x[0]…` element, not `$x`
            // directly. Walk down the element chain to the root local and mark it
            // so a by-value copy-on-entry separates the outer buffer (the deep
            // copy owns the inner levels).
            $base = $arr;
            while ($base->kind === Node::KIND_ARRAY_ACCESS) {
                $base = $base->array;
            }
            if ($base->kind === Node::KIND_LOAD_LOCAL && $base->type->isArray()) {
                $this->frame->mutatedVecLocals[$base->name] = true;
            }
        }
        // Taking an element's ADDRESS by reference (a `$a[$k]` bound via RefAddr_
        // or passed as a call argument that may be by-ref) can mutate the vec —
        // mark it so a prior `$b = $a` copy-on-assigns instead of sharing the
        // buffer the reference will write through. Over-approximate (any call
        // arg): a needless copy is safe, a shared write is not.
        if ($n->kind === Node::KIND_REF_ADDR) {
            $this->markVecElemBase($n->lvalue);
        }
        if ($n->kind === Node::KIND_CALL) {
            foreach ($n->args as $a) { $this->markVecElemBase($a); }
        }
        if ($n->kind === Node::KIND_METHOD_CALL) {
            foreach ($n->args as $a) { $this->markVecElemBase($a); }
        }
        if ($n->kind === Node::KIND_STATIC_CALL) {
            foreach ($n->args as $a) { $this->markVecElemBase($a); }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->collectMutatedVecs($c);
        }
    }

    /**
     * A2 verify check, emitted inside an obj/vec release helper right after
     * `%rc = load`: abort if `%rc < 1` (releasing an already-dead value =
     * double-free / use-after-free). Returns '' (byte-identical IR) unless
     * MANTICORE_DEBUG_VERIFY is set. Labels are function-scoped, so the fixed
     * names are safe across the distinct release helpers.
     */
    private function rcVerifyAlive(): string
    {
        if (!\Compile\Debug::$verify) { return ''; }
        $out  = "  %vbad = icmp slt i64 %rc, 1\n";
        $out .= "  br i1 %vbad, label %vcorrupt, label %vok\n";
        $out .= "vcorrupt:\n";
        $out .= "  call void @abort()\n";
        $out .= "  unreachable\n";
        $out .= "vok:\n";
        return $out;
    }

    /**
     * Refine a vec rc_release flavor: a `vec[obj]` becomes `vecobj` so
     * its obj elements are released before the buffer is freed. All other
     * flavors pass through unchanged.
     */
    private function rcReleaseFlavor(\Compile\Mir\MemoryOp_ $mo): string
    {
        $t = $mo->target;
        // A shared buffer (passed by value as a call arg) is co-owned by the
        // callee along with its retained element refs — drop the buffer only,
        // never the elements (element-drop would double-free the shared refs:
        // the parser `$args` UAF). See {@see $elementSharedLocals}.
        $shared = $t !== null && $t->kind === Node::KIND_LOAD_LOCAL
            && isset($this->frame->elementSharedLocals[$t->name]);
        if ($mo->flavor === 'vec') {
            if ($t === null || $shared) { return 'vec'; }
            $el = $t->type->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'veccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'vecobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'vecstr'; }
            return 'vec';
        }
        if ($mo->flavor === 'assoc') {
            if ($t === null || $shared) { return 'assoc'; }
            $el = $t->type->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'assoccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'assocobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'assocstr'; }
            return 'assoc';
        }
        return $mo->flavor;
    }

    /**
     * Emit a retain of the rc value held in `$slot`, by the same flavor
     * vocabulary as {@see rcReleaseSlot}, and — critically — the same DEPTH:
     * a vecobj/vecstr/veccell retain co-owns the element refs its paired
     * release drops. Retain used to bump only the container header while
     * release dropped the elements too, so any second owner of a `Node[]`
     * freed the tree's children on its release without ever retaining one.
     */
    private function rcRetainSlot(string $slot, string $flavor): string
    {
        $iv = $this->ssa->allocReg();
        $out = '  ' . $iv . ' = load i64, ptr ' . $slot . "\n";
        return $out . $this->rcRetainReg($iv, $flavor);
    }

    /** Emit a retain of the rc value carried in the i64 register `$i64reg` —
     *  the exact mirror of {@see rcReleaseReg}. */
    private function rcRetainReg(string $i64reg, string $flavor): string
    {
        $fn = '@__mir_array_retain';
        if ($flavor === 'str') { $this->rt->needsStrRc = true; $fn = '@__mir_rc_retain_str'; }
        elseif ($flavor === 'obj') { $this->rt->needsRc = true; $fn = '@__mir_rc_retain'; }
        elseif ($flavor === 'vecobj' || $flavor === 'assocobj') { $this->rt->needsRc = true; $fn = '@__mir_array_retain_obj'; }
        elseif ($flavor === 'vecstr' || $flavor === 'assocstr') { $this->rt->needsStrRc = true; $fn = '@__mir_array_retain_str'; }
        elseif ($flavor === 'veccell' || $flavor === 'assoccell') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; $fn = '@__mir_array_retain_cell'; }
        $pv = $this->ssa->allocReg();
        $out  = '  ' . $pv . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
        $out .= '  call void ' . $fn . '(ptr ' . $pv . ")\n";
        return $out;
    }

    /** Emit a release of the rc value held in `$slot` (obj / vec / vecobj / str). */
    private function rcReleaseSlot(string $slot, string $flavor): string
    {
        $iv = $this->ssa->allocReg();
        $out = '  ' . $iv . ' = load i64, ptr ' . $slot . "\n";
        return $out . $this->rcReleaseReg($iv, $flavor);
    }

    /** Emit a release of the rc value carried in the i64 register `$i64reg`. */
    private function rcReleaseReg(string $i64reg, string $flavor): string
    {
        // Every vec/assoc flavor releases through the one __mir_array_release*
        // (mode-driven; drops hashed string keys, and the _obj/_str variants
        // drop element values). str/obj scalars keep their own helpers.
        $fn = '@__mir_array_release';
        if ($flavor === 'str') { $this->rt->needsStrRc = true; $fn = '@__mir_rc_release_str'; }
        elseif ($flavor === 'obj') { $this->rt->needsRc = true; $fn = '@__mir_rc_release'; }
        elseif ($flavor === 'vecobj' || $flavor === 'assocobj') { $fn = '@__mir_array_release_obj'; }
        elseif ($flavor === 'vecstr' || $flavor === 'assocstr') { $fn = '@__mir_array_release_str'; }
        elseif ($flavor === 'veccell' || $flavor === 'assoccell') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; $fn = '@__mir_array_release_cell'; }
        $pv = $this->ssa->allocReg();
        $out  = '  ' . $pv . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
        $out .= '  call void ' . $fn . '(ptr ' . $pv . ")\n";
        return $out;
    }

    /**
     * Retain (rc++) a just-emitted vec / obj value that is being given a
     * second owner (heap store, container element, obj alias, capture).
     * `$i64reg` is the value in the i64 carrier. No-op for non-rc types.
     * Keeps escaping (RcHeap) values alive until every owner releases —
     * the soundness counterpart to the scope-exit rc_release.
     */
    /**
     * Emit a co-owner retain for a raw i64 value of a known static type — no
     * value node (used by `clone`'s slot copy). Skips non-rc kinds and the
     * header-less foreign/struct/closure objects.
     */
    private function rcRetainRawByType(string $i64reg, ?Type $t): string
    {
        if ($t === null) { return ''; }
        $tk = $t->kind;
        if ($tk === Type::KIND_OBJ) {
            $cls = $t->class ?? '';
            if ($cls === 'Ffi\\Ptr' || $cls === 'Generator' || $this->isClosureClass($cls)) { return ''; }
            if ($cls !== '' && isset($this->classes[$cls]) && $this->classes[$cls]->isStruct) { return ''; }
            if ($this->isEnumClass($cls)) { return ''; }
            $this->rt->needsRc = true;
            $p = $this->ssa->allocReg();
            $o  = '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
            $o .= '  call void @__mir_rc_retain(ptr ' . $p . ")\n";
            return $o;
        }
        if ($tk === Type::KIND_STRING) {
            $this->rt->needsStrRc = true;
            $p = $this->ssa->allocReg();
            $o  = '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
            $o .= '  call void @__mir_rc_retain_str(ptr ' . $p . ")\n";
            return $o;
        }
        if ($tk === Type::KIND_ARRAY) {
            $p = $this->ssa->allocReg();
            $o  = '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
            $o .= '  call void @__mir_array_retain(ptr ' . $p . ")\n";
            return $o;
        }
        return '';
    }

    private function rcRetainByType(Node $valueNode, string $i64reg, ?Type $fallback = null, int $cat = 6): string
    {
        // By-handle rc for obj / vec / string / assoc (buffer rc).
        $tk = $valueNode->type->kind;
        $cls = $valueNode->type->class ?? '';
        // A KIND_UNION value is a bare object pointer (an all-object union — its
        // arms are concrete classes); rc-manage it exactly like KIND_OBJ so a
        // borrowed union read stored into an obj slot/array gets a co-owner
        // retain to balance the obj release. Without this the array's
        // release_obj over-frees the borrowed arm → double-free.
        if ($tk === Type::KIND_UNION) { $tk = Type::KIND_OBJ; $cls = ''; }
        // Value type erased to unknown but the destination (e.g. a property)
        // is a known rc-managed kind → co-own per the destination's type.
        if (($tk === Type::KIND_UNKNOWN || $tk === Type::KIND_CELL) && $fallback !== null) {
            $fk = $fallback->kind;
            if ($fk === Type::KIND_OBJ || $fk === Type::KIND_ARRAY
                || $fk === Type::KIND_STRING) {
                $tk = $fk;
                $cls = $fallback->class ?? '';
            }
        }
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY
            && $tk !== Type::KIND_STRING) { return ''; }
        // #[Struct] classes and closures have no rc header (a closure
        // struct is [fn_ptr, captures...] — offset 8 is a capture, not an
        // rc word) — never rc-manage them.
        if ($tk === Type::KIND_OBJ) {
            $scls = $cls;
            // A raw foreign address has no rc header — retaining one writes into
            // the allocator's metadata. Same guard as rcRetainRawByType.
            if ($scls === 'Ffi\\Ptr') { return ''; }
            if ($scls !== '' && isset($this->classes[$scls]) && $this->classes[$scls]->isStruct) {
                return '';
            }
            if ($this->isClosureClass($scls)) { return ''; }
            if ($this->isEnumClass($scls)) { return ''; }
            // A Generator frame uses a string-style rc header (rc@-8) — retain
            // it via the str path (treat as KIND_STRING below). The owned vs
            // borrowed logic still applies: a gen() call / $g() invoke is a
            // fresh owned +1 (skipped), only an alias gets a co-owner retain.
            if ($scls === 'Generator') { $tk = Type::KIND_STRING; }
        }
        // An owned producer (`new` / array-literal / concat / call return)
        // carries a fresh +1 that transfers to the new owner — retaining it
        // would over-count. Only borrowed values (alias / property / array
        // read) and owned locals need a retain to add a co-owner.
        $k = $valueNode->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE) {
            return '';
        }
        if ($tk === Type::KIND_OBJ && ($k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE)) { return ''; }
        // An array literal / spread is a fresh +1 that transfers; only
        // borrowed arrays (alias / read) need a co-owner retain.
        if ($tk === Type::KIND_ARRAY && ($k === Node::KIND_ARRAY_LIT || $k === Node::KIND_SPREAD)) { return ''; }
        // String owned producer: a concat is a fresh +1; a literal is
        // immortal (retain is a sentinel no-op — skip it).
        if ($tk === Type::KIND_STRING
            && ($k === Node::KIND_CONCAT || $k === Node::KIND_STRING_CONST)) { return ''; }
        $p = $this->ssa->allocReg();
        $out  = $this->profBump(7 + $cat);
        $out .= '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
        if ($tk === Type::KIND_STRING) {
            $this->rt->needsStrRc = true;
            $out .= '  call void @__mir_rc_retain_str(ptr ' . $p . ")\n";
        } elseif ($tk === Type::KIND_ARRAY) {
            // Retain to the same DEPTH the paired release drops: a co-owner of
            // a vec<obj> must co-own the elements, or its release frees refs it
            // never took (the `Node[]` borrow-return that ate the AST).
            //
            // Depth is decided by the DESTINATION's type, never the value's: a
            // bare-`array` property (`Isset_::$targets`) erases its element, so
            // retaining by the value's type takes the buffer alone while the
            // caller — who sees the declared `Node[]` — drops every element.
            // The fallback IS what the other side assumes.
            $at = $valueNode->type->kind === Type::KIND_ARRAY ? $valueNode->type : null;
            if ($fallback !== null && $fallback->kind === Type::KIND_ARRAY
                && ($at === null || $at->element === null)) {
                $at = $fallback;
            }
            $flavor = $at !== null ? $this->discardReleaseFlavor($at) : 'vec';
            if ($flavor === '') { $flavor = 'vec'; }
            return $out . $this->rcRetainReg($i64reg, $flavor);
        } else {
            $this->rt->needsRc = true;
            $out .= '  call void @__mir_rc_retain(ptr ' . $p . ")\n";
        }
        return $out;
    }
}
