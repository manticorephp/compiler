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
     *
     * @param array<string, bool> $paramNames param name => is-a-param (a SET)
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
        // LAST: its gate reads paramNames, transferredLocals, elementSharedLocals
        // and rcObjLocals, and asks rcReleaseFlavor with the flag map still empty.
        $this->frame->ownElemLocals = [];
        $this->collectOwnElemLocals($body);
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
            $this->maybeTransfer($n->value, $fallback, $this->storeElemBoxesValue($n));
        } elseif ($k === Node::KIND_STORE_PROPERTY) {
            // Same destination type the emitter's retain uses — one owner, or
            // the two drift into a leak / double free ({@see
            // EmitLlvmObjects::propStoreRetainType}).
            $this->maybeTransfer($n->value, $this->propStoreRetainType($n));
        } elseif ($k === Node::KIND_ARRAY_LIT) {
            $fallback = $n->type->element ?? null;
            $boxed = $this->litBoxesValues($n);
            foreach ($n->elements as $el) { $this->maybeTransfer($el->value, $fallback, $boxed); }
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
    /** The emitted symbol of `$class`'s constructor, or '' when there is none to
     *  speak for (no class, no declared `__construct`). '' keeps the veto. */
    private function ctorSymbolFor(string $class): string
    {
        if ($class === '' || !isset($this->classes[$class])) { return ''; }
        $decl = $this->resolveMethodClass($class, '__construct');
        if ($decl === '') { return ''; }
        return $this->lsbTarget($decl, '__construct', $class);
    }

    private function collectElementSharedLocals(Node $n): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_NEW_OBJ) {
            // The constructor is resolvable from the class, so its parameters'
            // retain discipline is knowable — which is what lets
            // {@see EmitLlvm::shareCallArgs} keep the caller's element release
            // instead of vetoing it blind. `new Parser($toks)` is the shape.
            $this->shareCallArgs($n->args, $this->ctorSymbolFor($n->class ?? ''));
        } elseif ($n->type->kind === Type::KIND_OBJ) {
            if ($k === Node::KIND_CALL) {
                $this->shareCallArgs($n->args, $n->function);
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
     * Pre-pass for PAIRWISE-SYMMETRIC element drop: find the locals whose value
     * was acquired BY RETAIN and whose retain and release name the SAME flavor,
     * so their release can undo exactly what their retain did — on every
     * release, not only at rc → 0 ({@see UnifiedArrayRuntime::emitRelease}'s
     * `_ownel_` variants).
     *
     * The retain co-owns the elements on EVERY retain while the release walks
     * them out of the `rc → 0` block, so a pair around a buffer that does not
     * die leaks +1 per element per pair — the propleak `snap` row, ~24 small
     * strings an iteration. Making that symmetric for ALL release sites is
     * REFUTED (AOT 1006/1011): one buffer is retained as `_buf` at one site and
     * released as `_str` at another, and every mismatched pair then becomes an
     * over-release on a LIVE buffer. Element ownership is a property of the
     * REFERENCE, so the drop is only legal where BOTH ends are the same site:
     *
     *   1. EVERY store to the name is the retaining property snapshot
     *      (`$saved = $this->map` — {@see EmitLlvm::storeLocalRetainsProp}, the
     *      one read shape in the tree that takes a reference). A single
     *      producer store (call / literal / `new`) vetoes the name: its +1 has
     *      no element refs behind it, so the counts stop matching.
     *   2. The retain's flavor ({@see arrayRetainFlavor}, the emitter's own) and
     *      the release's ({@see rcReleaseFlavor}) are EQUAL and element-bearing.
     *      Anything else is vetoed, never "fixed".
     *   3. A PARAM is excluded (its slot arrives holding the caller's value, and
     *      {@see initRcObjSlots} balances that with an entry retain of its own),
     *      as are a transferred local (release suppressed) and an element-shared
     *      one (deliberately buffer-only — the parser `$args` UAF).
     *
     * ⚠ A conservative gate fails SILENTLY — `MANTICORE_OWNEL_TRACE=1` prints
     * each accepted / rejected name, so a gate that matches 3 sites cannot pass
     * for one that matches 46.
     */
    private function collectOwnElemLocals(Node $body): void
    {
        /** @var array<string, string> $cand */
        $cand = [];
        /** @var array<string, bool> $veto */
        $veto = [];
        $this->scanOwnElemStores($body, $cand, $veto);
        $dbg = \getenv('MANTICORE_OWNEL_TRACE') !== false;
        foreach ($cand as $name => $flavor) {
            $why = '';
            if (isset($veto[$name])) { $why = 'mixed store'; }
            elseif (isset($this->frame->paramNames[$name])) { $why = 'param'; }
            elseif (isset($this->frame->transferredLocals[$name])) { $why = 'transferred'; }
            elseif (isset($this->frame->elementSharedLocals[$name])) { $why = 'element-shared'; }
            elseif (!isset($this->frame->rcObjLocals[$name])) { $why = 'no release'; }
            elseif ($this->rcReleaseFlavor($this->frame->rcObjLocals[$name]) !== $flavor) {
                $why = 'flavor ' . $this->rcReleaseFlavor($this->frame->rcObjLocals[$name]) . ' != ' . $flavor;
            }
            if ($why !== '') {
                if ($dbg) { \error_log('OWNEL? $' . $name . ' no: ' . $why); }
                continue;
            }
            if ($dbg) { \error_log('OWNEL? $' . $name . ' YES ' . $flavor); }
            $this->frame->ownElemLocals[$name] = true;
        }
    }

    /**
     * @param array<string, string> $cand name => the pair's retain flavor
     * @param array<string, bool>   $veto names disqualified by any other store
     */
    private function scanOwnElemStores(Node $n, array &$cand, array &$veto): void
    {
        if ($n->kind === Node::KIND_STORE_LOCAL) {
            $name = $n->name;
            $v = $n->value;
            // `null` neither owns nor borrows — every release helper null-guards,
            // so it decides nothing either way.
            if ($v->kind !== Node::KIND_NULL_CONST && $v->type->kind !== Type::KIND_NULL) {
                $f = $this->ownElemPairFlavor($n, $v);
                if ($f === null || (isset($cand[$name]) && $cand[$name] !== $f)) {
                    $veto[$name] = true;
                } else {
                    $cand[$name] = $f;
                }
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->scanOwnElemStores($c, $cand, $veto); }
    }

    /**
     * The flavor of the element refs this store gives the local, or null when
     * the store cannot be spoken for.
     *
     * TWO shapes give a local its own element refs, and both must drop them:
     *   - the retaining PROPERTY SNAPSHOT (`$s = $this->map`), whose retain took
     *     a +1 on every element ({@see EmitLlvmLocals::emitStoreLocal});
     *   - an OWNED PRODUCER (a literal, a call's +1 return, a union), which
     *     carries the BUILDER's one ref per element. `$m = build(); $h->set($m);`
     *     is the shape: the property store retains (+1 per element) and the
     *     local's own release must give back the ref it holds, or every element
     *     leaks exactly once per iteration — `noread` in the probe, no snapshot
     *     anywhere. InsertMemoryOps only plants a release for an OWNED value
     *     (a borrowed alias blocks the name outright), so reaching here with a
     *     release at all is the ownership proof.
     *
     * Element-bearing flavors only: `vec` / `assoc` (repr mode) read ownership
     * off the BUFFER's own repr bits, which no per-reference pairing can speak
     * for, and `*buf` has nothing to drop.
     */
    private function ownElemPairFlavor(Node $store, Node $value): ?string
    {
        $fallback = null;
        if ($value->kind === Node::KIND_PROPERTY_ACCESS) {
            if (!$this->storeLocalRetainsProp($store, $value)) { return null; }
            // ⚠ Character-for-character {@see EmitLlvmLocals::emitStoreLocal}'s
            // `$fallback`: an array-HINTED slot whose type erased to unknown
            // carries no kind for the retain to dispatch on, so the emitter names
            // the array explicitly there — and the pair's flavor follows from it.
            if (!$value->type->isArray()) { $fallback = Type::vec(Type::unknown()); }
        } elseif (!$value->type->isArray()) {
            return null;
        }
        // `$a = []` is `vec[unknown]`, and it is usually the ONLY store_local to
        // the name — the appends that give the local its real element type are
        // store_element. Judging the pair by the LITERAL's type therefore answered
        // "not an own-element flavor" and vetoed the local, so its release gave
        // back no element reference at all while the property store's retain had
        // taken one per element. Every element then leaked exactly once per
        // iteration: precisely the `$m = build(); $h->set($m);` shape this
        // method's own docblock describes, and 9,236,608 leaked Lexer\Token on
        // the Doctrine tier. InsertMemoryOps has already refined the local's
        // release type from the LOADS — use it rather than the literal's.
        if (\Compile\Debug::$rcElemType && $store->kind === Node::KIND_STORE_LOCAL) {
            $mo = $this->frame->rcObjLocals[$store->name] ?? null;
            $known = $mo === null ? null : $mo->target->type;
            if ($known !== null && $known->isArray() && $value->type->isArray()
                && ($value->type->element === null
                    || $value->type->element->kind === Type::KIND_UNKNOWN)) {
                $fallback = $known;
            }
        }
        $flavor = $this->arrayRetainFlavor($value, $fallback);
        return $this->isOwnElemFlavor($flavor) ? $flavor : null;
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
        // A builtin whose FIRST parameter php declares by reference mutates its
        // argument exactly like an element store, and the CoW inside the builtin
        // cannot save it: a read-only alias is never retained, so the buffer's
        // rc stays 1 and the copy never triggers.
        //
        // This arm knew only the four cursor moves (`next`/`prev`/`reset`/`end`,
        // which write the header's internal pointer). Every OTHER by-ref
        // builtin was a silent wrong answer — 7 of 9 probed shapes, e.g.
        // `$b = $a; array_pop($a);` left `$b` short an element and
        // `array_unshift($a, 9)` left `$b` reading an EMPTY array off the
        // reallocated base. The list is the shape contract: a name belongs here
        // when php declares its first parameter `&$array`.
        // `current`/`key`/`array_key_first` only read.
        if ($n->kind === Node::KIND_CALL && \count($n->args) > 0) {
            // Shape first, NAME second: a kind compare is a word compare while
            // the name test is a walk of string equalities, and this pre-scan
            // visits every node of every function.
            $a0 = $n->args[0];
            $base = $a0;
            while ($base->kind === Node::KIND_ARRAY_ACCESS) {
                // `array_pop($x[0])` mutates the ROOT local too — the same walk
                // the nested element store below does.
                $base = $base->array;
            }
            if ($base->kind === Node::KIND_LOAD_LOCAL && $base->type->isArray()
                && $this->mutatesArg0($n->function)) {
                $this->frame->mutatedVecLocals[$base->name] = true;
            }
        }
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
        // `unset($a[$k])` REMOVES an entry — a mutation of `$a` exactly like a
        // store, and it was the one lvalue shape this scan did not see. So
        // `$b = $a; unset($a['x']);` never took the copy and the unset removed
        // the entry from `$b` as well (php: `$b` keeps it). Same root-walk as
        // the store arm: `unset($x[0][1])` mutates `$x`.
        if ($n->kind === Node::KIND_UNSET) {
            foreach ($n->targets as $t) {
                if ($t->kind !== Node::KIND_ARRAY_ACCESS) { continue; }
                $base = $t;
                while ($base->kind === Node::KIND_ARRAY_ACCESS) { $base = $base->array; }
                if ($base->kind === Node::KIND_LOAD_LOCAL && $base->type->isArray()) {
                    $this->frame->mutatedVecLocals[$base->name] = true;
                }
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
        // Separate arm — `lvalue` is at a different offset on RefCell_ than on
        // RefAddr_, so the two cannot share one field read.
        // No REF_CELL arm: a reference cell's source is a plain LOCAL today, and
        // {@see markVecElemBase} does nothing for anything but an element. The
        // arm belongs with the element-source instalment that gives it work to
        // do — writing it early bought nothing and cost a field read on a
        // Node-typed receiver, which is how it faulted.
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
     * Whether php declares $fn's FIRST parameter by reference over an array,
     * i.e. whether the call mutates the argument in place
     * ({@see collectMutatedVecs}). Independent of HOW the name is implemented
     * here — a codegen builtin (`array_pop`), a prelude body (`sort`) and a
     * desugar (`array_multisort`) all mutate the caller's array, and the
     * copy-on-assign decision is about php's contract, not ours.
     *
     * The four cursor moves are in the list because php's internal pointer
     * lives IN the array value: `next($a)` writes the header.
     * `array_multisort` is here for its first array too, even though the
     * desugar packs the rest.
     */
    private function mutatesArg0(string $fn): bool
    {
        $bare = $fn;
        // Monomorphize has already run, so a prelude body arrives as
        // `sort$mono$p0_vec_int` — matching the raw callee name found the four
        // codegen builtins and MISSED every php-bodied one (`sort`, `usort`,
        // `array_push`), which is why the first cut still printed a mutated
        // alias for them.
        $m = \strpos($bare, '$mono$');
        if ($m !== false) { $bare = \substr($bare, 0, $m); }
        $p = \strrpos($bare, '\\');
        if ($p !== false) { $bare = \substr($bare, $p + 1); }
        foreach ([
            'array_multisort', 'array_pop', 'array_push', 'array_shift', 'array_splice',
            'array_unshift', 'array_walk', 'array_walk_recursive', 'arsort', 'asort',
            'each', 'end', 'krsort', 'ksort', 'natcasesort', 'natsort', 'next', 'prev',
            'reset', 'rsort', 'shuffle', 'sort', 'uasort', 'uksort', 'usort',
        ] as $k) {
            if ($k === $bare) { return true; }
        }
        return false;
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
        // It used to `abort()` in silence, which is the one thing an assertion
        // must not do: the whole cold-seed build died with no output at all and
        // the site could only be recovered from an lldb backtrace. Say what
        // was released, with the rc it had and the first header word — slot 0
        // is the class descriptor for an object (so `hdr0` names the class) and
        // the length for a vec, which is enough to tell the two apart.
        // ⚠ Read the rc the way the ABI says, not as a raw word. The live count
        // is the SIGNED LOW 56 BITS; the top byte carries the collector's color
        // and buffered bits, so a perfectly alive object registered as a cycle
        // candidate holds e.g. 0x8100000000000005 — rc 5, and NEGATIVE as an
        // i64. This check compared the raw word and therefore fired on it: the
        // whole `MANTICORE_DEBUG_VERIFY=1` cold seed aborted inside the parser
        // on a live Token. Same field, same spelling as the zero-test below —
        // that is the lesson `__mir_rc_release` already carries in its comment.
        $out  = "  %vsh = shl i64 %rc, 8\n";
        $out .= "  %vsig = ashr i64 %vsh, 8\n";
        $out .= "  %vbad = icmp slt i64 %vsig, 1\n";
        $out .= "  br i1 %vbad, label %vcorrupt, label %vok\n";
        $out .= "vcorrupt:\n";
        // Drain our own stdout first: abort() discards the stdio buffer, and
        // without this the output leading up to the over-release is lost.
        if ($this->rt->needsOutBuf) { $out .= "  call void @__mir_out_flush()\n"; }
        $out .= "  %vh0 = load i64, ptr %p\n";
        $out .= "  call i32 (i32, ptr, ...) @dprintf(i32 2, ptr @.vfy.rcrel, ptr %p, i64 %vsig, i64 %vh0)\n";
        $out .= "  call void @abort()\n";
        $out .= "  unreachable\n";
        $out .= "vok:\n";
        return $out;
    }

    /** The format string {@see rcVerifyAlive} prints through. Emitted once,
     *  beside the release helper that carries the check. */
    private function rcVerifyAliveFormat(): string
    {
        if (!\Compile\Debug::$verify) { return ''; }
        $raw = '[VERIFY] rc_release: rc < 1 (double release / UAF) p=%p rc=%lld hdr0=%lld';
        return '@.vfy.rcrel = private unnamed_addr constant ['
            . (string)(\strlen($raw) + 2) . ' x i8] c"' . $raw . '\0A\00", align 1' . "\n";
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
        // This reference OWNS its element refs (it took them in its own retain)
        // ⇒ it drops them on every release, not only at rc → 0. Only the
        // element-bearing flavors below can carry the suffix, and the gate that
        // sets the flag already proved retain and release name the same one
        // ({@see collectOwnElemLocals}).
        $ownEl = $t !== null && $t->kind === Node::KIND_LOAD_LOCAL
            && isset($this->frame->ownElemLocals[$t->name]);
        $f = $this->rcReleaseFlavorPlain($mo, $shared);
        return $ownEl && $this->isOwnElemFlavor($f) ? $f . 'own' : $f;
    }

    /** {@see rcReleaseFlavor}'s answer before the ownership suffix. */
    private function rcReleaseFlavorPlain(\Compile\Mir\MemoryOp_ $mo, bool $shared): string
    {
        $t = $mo->target;
        if ($mo->flavor === 'vec') {
            // A shared buffer (passed by value to a callee that co-owns and
            // drops the element refs) releases BUFFER-ONLY — ignore the repr
            // bits, or the elements are double-dropped (the parser $args UAF).
            if ($t === null || $shared) { return 'vecbuf'; }
            $el = $t->type->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'veccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'vecobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'vecstr'; }
            if ($el !== null && $this->isNonRcScalarKind($el->kind)) { return 'vecbuf'; }
            return 'vec';
        }
        if ($mo->flavor === 'assoc') {
            if ($t === null || $shared) { return 'assocbuf'; }
            $el = $t->type->element;
            if ($el !== null && $el->kind === Type::KIND_CELL) { return 'assoccell'; }
            if ($el !== null && $el->kind === Type::KIND_OBJ && !$this->isEnumClass($el->class ?? '')) { return 'assocobj'; }
            if ($el !== null && $el->kind === Type::KIND_STRING) { return 'assocstr'; }
            if ($el !== null && $this->isNonRcScalarKind($el->kind)) { return 'assocbuf'; }
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

    /**
     * Co-own an rc value in `$i64reg` that is NOT NaN-boxed — the half
     * `__mir_cell_retain` cannot do.
     *
     * That helper answers on the cell tag and returns immediately for an
     * untagged word, which is correct for a scalar and wrong for the very
     * common case of a container riding an ERASED slot: a bare `array` hint
     * lowers to unknown, so the value is a raw pointer with no tag at all and
     * the retain silently did nothing. A closure capturing such a param never
     * co-owned it, so an argument with no other owner — an array LITERAL passed
     * straight into the call — was freed at the caller's scope exit and the
     * closure body then read freed memory (symfony's `TableRows` closure over
     * `buildTableRows`' locals; `count()` on it answered garbage).
     *
     * Only a CONTAINER can be identified from a raw pointer: the allocator
     * stamps a magic at `ptr-8`, and nothing does for a raw string or int
     * ({@see biIsType} says the same), so those keep today's behaviour. The
     * pointer is bounded both ends first ({@see plausiblePtrIr}).
     *
     * Emit this NEXT TO `__mir_cell_retain`, not instead of it — the two are
     * disjoint (this one does nothing when the word IS tagged), so there is no
     * double retain.
     */
    private function rawContainerRetainIr(string $v): string
    {
        $this->rt->needsRc = true;
        $rawL = $this->ssa->allocLabel('rcap.raw');
        $probeL = $this->ssa->allocLabel('rcap.probe');
        $arrL = $this->ssa->allocLabel('rcap.arr');
        $objChkL = $this->ssa->allocLabel('rcap.objchk');
        $objL = $this->ssa->allocLabel('rcap.obj');
        $endL = $this->ssa->allocLabel('rcap.end');
        $isBox = $this->ssa->allocReg();
        $out  = '  ' . $isBox . ' = icmp ugt i64 ' . $v . ", -4503599627370496\n";
        $out .= '  br i1 ' . $isBox . ', label %' . $endL . ', label %' . $rawL . "\n";
        $out .= $rawL . ":\n";
        $out .= $this->plausiblePtrIr($v);
        $out .= '  br i1 ' . $this->plausiblePtrReg . ', label %' . $probeL
              . ', label %' . $endL . "\n";
        $out .= $probeL . ":\n";
        $rp = $this->ssa->allocReg();
        $out .= '  ' . $rp . ' = inttoptr i64 ' . $v . " to ptr\n";
        $tp = $this->ssa->allocReg();
        $out .= '  ' . $tp . ' = getelementptr inbounds i8, ptr ' . $rp . ", i64 -8\n";
        $tw = $this->ssa->allocReg();
        $out .= '  ' . $tw . ' = load i64, ptr ' . $tp . "\n";
        $isArr = $this->magicMatchIr($tw, [\Compile\MemoryAbi::ARRAY_TAG_MAGIC,
            \Compile\MemoryAbi::ARRAY_TAG_ARENA, \Compile\MemoryAbi::ASSOC_TAG_MAGIC]);
        $out .= $this->magicMatchOut;
        $out .= '  br i1 ' . $isArr . ', label %' . $arrL . ', label %' . $objChkL . "\n";
        $out .= $arrL . ":\n";
        $out .= '  call void @__mir_array_retain(ptr ' . $rp . ")\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $objChkL . ":\n";
        $isObj = $this->ssa->allocReg();
        $out .= '  ' . $isObj . ' = icmp eq i64 ' . $tw . ', '
              . (string)\Compile\MemoryAbi::RC_TAG_MAGIC . "\n";
        $out .= '  br i1 ' . $isObj . ', label %' . $objL . ', label %' . $endL . "\n";
        $out .= $objL . ":\n";
        $out .= '  call void @__mir_rc_retain(ptr ' . $rp . ")\n";
        $out .= '  br label %' . $endL . "\n";
        $out .= $endL . ":\n";
        return $out;
    }

    /**
     * A by-reference return read in VALUE context. `$copy = f()` is an
     * ASSIGNMENT, so php hands back a COPY — only `$r = &f()` aliases. The
     * deref yields a BORROWED word naming storage someone else owns, so the
     * new holder has to take a reference: without it the container's rc stays
     * 1, the first `$copy[] = …` COWs nothing and appends IN PLACE, and the
     * reallocation leaves the aliased property pointing at freed memory —
     * `implode($h->items)` printed nothing where php prints the original.
     *
     * The two calls are disjoint by construction ({@see rawContainerRetainIr}):
     * cell_retain answers on the NaN tag and returns at once for an untagged
     * word; the raw probe only fires on an untagged pointer carrying a
     * container magic at `ptr-8`. A scalar matches neither and costs nothing.
     * A raw STRING matches neither either — nothing stamps a magic for one —
     * which keeps today's behaviour there rather than guessing.
     */
    private function byRefValueCopyRetainIr(string $v): string
    {
        $this->rt->needsRc = true;
        $this->rt->needsStrRc = true;
        return '  call void @__mir_cell_retain(i64 ' . $v . ")\n"
             . $this->rawContainerRetainIr($v);
    }

    /** Emit a retain of the rc value carried in the i64 register `$i64reg` —
     *  the exact mirror of {@see rcReleaseReg}. */
    private function rcRetainReg(string $i64reg, string $flavor): string
    {
        // Mirror of the cell branch in rcReleaseReg: raw i64, tag-dispatched,
        // never inttoptr'd.
        if ($flavor === 'cell') {
            $this->rt->needsRc = true;
            $this->rt->needsStrRc = true;
            return '  call void @__mir_cell_retain(i64 ' . $i64reg . ")\n";
        }
        $fn = '@__mir_array_retain';
        if ($flavor === 'str') { $this->rt->needsStrRc = true; $fn = '@__mir_rc_retain_str'; }
        elseif ($flavor === 'obj') { $this->rt->needsRc = true; $fn = '@__mir_rc_retain'; }
        elseif ($flavor === 'vecbuf' || $flavor === 'assocbuf') { $fn = '@__mir_array_retain_buf'; }
        elseif ($flavor === 'vecobj' || $flavor === 'assocobj') { $this->rt->needsRc = true; $fn = '@__mir_array_retain_obj'; }
        elseif ($flavor === 'vecstr' || $flavor === 'assocstr') { $this->rt->needsStrRc = true; $fn = '@__mir_array_retain_str'; }
        elseif ($flavor === 'veccell' || $flavor === 'assoccell') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; $fn = '@__mir_array_retain_cell'; }
        // The `own` suffix is a RELEASE-side distinction — retain already co-owns
        // the elements on every call, which is the asymmetry the suffix repairs.
        // Mapped rather than left to the default, so an `own` flavor arriving
        // here can never silently degrade into the repr-mode retain.
        elseif ($flavor === 'vecobjown' || $flavor === 'assocobjown') { $this->rt->needsRc = true; $fn = '@__mir_array_retain_obj'; }
        elseif ($flavor === 'vecstrown' || $flavor === 'assocstrown') { $this->rt->needsStrRc = true; $fn = '@__mir_array_retain_str'; }
        elseif ($flavor === 'veccellown' || $flavor === 'assoccellown') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; $fn = '@__mir_array_retain_cell'; }
        $pv = $this->ssa->allocReg();
        $out  = '  ' . $pv . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
        $out .= '  call void ' . $fn . '(ptr ' . $pv . ")\n";
        return $out;
    }

    /**
     * The DEPTH an array retain co-owns to — one owner for the question, so the
     * pairwise-symmetric gate ({@see ownElemPairFlavor}) reads exactly what
     * {@see rcRetainByType} emits instead of a copy that can drift.
     *
     * Depth is decided by the DESTINATION's type, never the value's: a
     * bare-`array` property (`Isset_::$targets`) erases its element, so
     * retaining by the value's type takes the buffer alone while the caller —
     * who sees the declared `Node[]` — drops every element. The fallback IS what
     * the other side assumes.
     */
    private function arrayRetainFlavor(Node $valueNode, ?Type $fallback): string
    {
        $at = $valueNode->type->kind === Type::KIND_ARRAY ? $valueNode->type : null;
        // An UNKNOWN element is as uninformative as a missing one, and it is the
        // common case: `$a = []` types as `vec[unknown]`, whose `element` is a
        // real Type — so the `=== null` test never fired and the fallback never
        // won. The flavor then stayed a plain buffer drop while the destination
        // had long since been refined to `vec[obj<T>]`, and every element leaked.
        $uninformative = $at === null || $at->element === null
            || (\Compile\Debug::$rcElemType && $at->element->kind === Type::KIND_UNKNOWN);
        if ($fallback !== null && $fallback->kind === Type::KIND_ARRAY && $uninformative) {
            $at = $fallback;
        }
        $flavor = $at !== null ? $this->discardReleaseFlavor($at) : 'vec';
        return $flavor === '' ? 'vec' : $flavor;
    }

    /**
     * Co-own the KEYS and ELEMENTS of a buffer this frame already owns outright
     * — a fresh `__mir_array_copy` result — without touching its rc.
     * {@see UnifiedArrayRuntime::emitRetainVariant}'s `$bumpRc = false` twin of
     * the flavor's retain, so a copy gives back exactly what its release drops.
     */
    private function arrayAdoptIr(string $i64reg, string $flavor): string
    {
        $sym = '@__mir_array_adopt';
        if ($flavor === 'vecobj' || $flavor === 'assocobj') { $this->rt->needsRc = true; $sym .= '_obj'; }
        elseif ($flavor === 'vecstr' || $flavor === 'assocstr') { $this->rt->needsStrRc = true; $sym .= '_str'; }
        elseif ($flavor === 'veccell' || $flavor === 'assoccell') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; $sym .= '_cell'; }
        elseif ($flavor === 'vecbuf' || $flavor === 'assocbuf') { $sym .= '_buf'; }
        else { $this->rt->needsRc = true; $this->rt->needsStrRc = true; }
        $p = $this->ssa->allocReg();
        return '  ' . $p . ' = inttoptr i64 ' . $i64reg . " to ptr\n"
            . '  call void ' . $sym . '(ptr ' . $p . ")\n";
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
        // A CELL carries its type in the tag, not in the static type, so it
        // cannot be inttoptr'd: the payload may be an int/float/null with no
        // pointer at all. __mir_cell_drop takes the RAW i64 and dispatches on
        // the tag (str / obj / nested array, scalars a no-op), self-guarding on
        // payload > 0xFFFF and RC_TAG_MAGIC. Same helper the cell-array element
        // walker already uses per element.
        if ($flavor === 'cell') {
            $this->rt->needsRc = true;
            $this->rt->needsStrRc = true;
            return '  call void @__mir_cell_drop(i64 ' . $i64reg . ")\n";
        }
        $fn = '@__mir_array_release';
        if ($flavor === 'str') { $this->rt->needsStrRc = true; $fn = '@__mir_rc_release_str'; }
        elseif ($flavor === 'obj') { $this->rt->needsRc = true; $fn = '@__mir_rc_release'; }
        // A capturing closure env: rc@-8 behind its own magic, and its generated
        // drop releases the captures ({@see EmitLlvmRuntime::closureRcRuntime}).
        // Self-guarded, so a `Closure` slot holding anything else is untouched.
        elseif ($flavor === 'closure') { $this->rt->needsClosureRc = true; $fn = '@__mir_closure_release'; }
        elseif ($flavor === 'vecbuf' || $flavor === 'assocbuf') { $fn = '@__mir_array_release_buf'; }
        elseif ($flavor === 'vecobj' || $flavor === 'assocobj') { $this->rt->needsRc = true; $fn = \Compile\Debug::$rcSymElem ? '@__mir_array_release_ownel_obj' : '@__mir_array_release_obj'; }
        elseif ($flavor === 'vecstr' || $flavor === 'assocstr') { $this->rt->needsStrRc = true; $fn = \Compile\Debug::$rcSymElem ? '@__mir_array_release_ownel_str' : '@__mir_array_release_str'; }
        elseif ($flavor === 'veccell' || $flavor === 'assoccell') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; $fn = \Compile\Debug::$rcSymElem ? '@__mir_array_release_ownel_cell' : '@__mir_array_release_cell'; }
        // PAIRWISE-SYMMETRIC: this reference took the element refs in its own
        // retain, so its release gives them back — every time, not only at
        // rc → 0 ({@see collectOwnElemLocals}).
        elseif ($flavor === 'vecobjown' || $flavor === 'assocobjown') { $this->rt->needsRc = true; $fn = '@__mir_array_release_ownel_obj'; }
        elseif ($flavor === 'vecstrown' || $flavor === 'assocstrown') { $this->rt->needsStrRc = true; $fn = '@__mir_array_release_ownel_str'; }
        elseif ($flavor === 'veccellown' || $flavor === 'assoccellown') { $this->rt->needsRc = true; $this->rt->needsStrRc = true; $fn = '@__mir_array_release_ownel_cell'; }
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
        // A CAPTURING closure env now has a lifetime header of its own, so an
        // alias / element / property store must co-own it — otherwise the
        // producing local's scope-exit release frees an env the new owner still
        // points at. Self-guarded on the magic, so a `Closure` value from any
        // other producer is left alone exactly as before. An owned producer
        // (the literal itself) is filtered by the borrow gate further down.
        if ($tk === Type::KIND_CLOSURE || ($tk === Type::KIND_OBJ && $this->isClosureClass($cls))) {
            // An OWNED producer transfers its +1 — the literal itself, and a
            // call/invoke returning a closure under the +1 return convention.
            // Only a borrow (an alias, an element / property read, a param)
            // needs a co-owner.
            $k0 = $valueNode->kind;
            if ($k0 === Node::KIND_CLOSURE || $k0 === Node::KIND_CALL
                || $k0 === Node::KIND_METHOD_CALL || $k0 === Node::KIND_STATIC_CALL
                || $k0 === Node::KIND_INVOKE) { return ''; }
            $this->rt->needsClosureRc = true;
            $cp = $this->ssa->allocReg();
            $co  = '  ' . $cp . ' = inttoptr i64 ' . $i64reg . " to ptr\n";
            $co .= '  call void @__mir_closure_retain(ptr ' . $cp . ")\n";
            return $co;
        }
        if ($tk !== Type::KIND_OBJ && $tk !== Type::KIND_ARRAY
            && $tk !== Type::KIND_STRING) { return ''; }
        // #[Struct] classes have no rc header — never rc-manage them.
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
        // A normalized conditional already carries a +1 from whichever arm ran
        // ({@see EmitLlvm::condOwnsResult}); retaining it here would over-count
        // — that double retain is what made the half-fixed string ternary leak
        // into every property / array-element / cell store.
        if ($this->condOwnsResult($valueNode)) { return ''; }
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
            return $out . $this->rcRetainReg($i64reg, $this->arrayRetainFlavor($valueNode, $fallback));
        } else {
            $this->rt->needsRc = true;
            $out .= '  call void @__mir_rc_retain(ptr ' . $p . ")\n";
        }
        return $out;
    }
}
