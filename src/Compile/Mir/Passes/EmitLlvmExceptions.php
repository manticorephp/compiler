<?php

namespace Compile\Mir\Passes;

use Compile\Mir\MirCatch;
use Compile\Mir\Node;

/**
 * Exception emitters extracted from {@see EmitLlvm}: throw / try-catch /
 * rethrow, the setjmp landing-pad machinery (incl. the `$jmpScratch` trait
 * property), MirCatch field accessors, and catch-class matching (class-id
 * chains, descendant sets). Pure $this-bound; behaviour unchanged. Split out
 * 2026-06-08 (needs the trait-property merge in LowerFromAst).
 */
trait EmitLlvmExceptions
{
    // Typed MirCatch field accessors: `foreach ($tc->catches as $c)` leaves
    // `$c` untyped (the element type isn't propagated self-host), so inline
    // `$c->var` / `$c->body` / `$c->types` resolve the wrong field offset.
    // Routing through `MirCatch $c` fixes the offset (T5 pattern).
    /** @return string[] */
    private function catchTypes(MirCatch $c): array { return $c->types; }
    private function catchVar(MirCatch $c): ?string { return $c->var; }
    /** @return Node[] */
    private function catchBody(MirCatch $c): array { return $c->body; }

    /** `@__mir_jmp_stack + slot*256` as a ptr SSA. Appends IR to $out-by-return. */
    private function jmpBufExpr(string $slotReg): string
    {
        $off = $this->allocSsa();
        $this->jmpScratch = $this->allocSsa();
        $ir = '  ' . $off . ' = mul i64 ' . $slotReg . ", 256\n";
        $ir .= '  ' . $this->jmpScratch . ' = getelementptr inbounds i8, ptr @__mir_jmp_stack, i64 ' . $off . "\n";
        return $ir;
    }
    private string $jmpScratch = '';
    private string $tryDepthScratch = '';

    /** Stash a try depth-snapshot into the generator frame slot (no-op outside
     *  a generator / when no slot). The frame survives a yield suspension. */
    private function tryStoreDepth(int $slot, string $val): string
    {
        if ($slot < 0 || !$this->inGenerator) { return ''; }
        $off = self::GEN_HEADER + 8 * $slot;
        $p = $this->allocSsa();
        return '  ' . $p . ' = getelementptr inbounds i8, ptr %frame, i64 '
             . (string)$off . "\n  store i64 " . $val . ', ptr ' . $p . "\n";
    }

    /** Reload a try depth-snapshot; leaves the value reg in {@see
     *  $tryDepthScratch}. Falls back to the entry SSA outside a generator. */
    private function tryReloadDepth(int $slot, string $fallback): string
    {
        if ($slot < 0 || !$this->inGenerator) { $this->tryDepthScratch = $fallback; return ''; }
        $off = self::GEN_HEADER + 8 * $slot;
        $p = $this->allocSsa();
        $v = $this->allocSsa();
        $this->tryDepthScratch = $v;
        return '  ' . $p . ' = getelementptr inbounds i8, ptr %frame, i64 '
             . (string)$off . "\n  " . $v . ' = load i64, ptr ' . $p . "\n";
    }

    private function emitThrow(Node $n): string
    {
        $this->needsExceptions = true;
        $t = $this->castThrow($n);
        $out = $this->emitNode($t->value);
        $out .= $this->coerceToPtr();
        $out .= '  store ptr ' . $this->lastValue . ", ptr @__mir_thrown\n";
        $depth = $this->allocSsa();
        $out .= '  ' . $depth . " = load i64, ptr @__mir_jmp_depth\n";
        $slot = $this->allocSsa();
        $out .= '  ' . $slot . ' = sub i64 ' . $depth . ", 1\n";
        $out .= $this->jmpBufExpr($slot);
        $out .= '  call void @longjmp(ptr ' . $this->jmpScratch . ", i32 1)\n";
        $out .= "  unreachable\n";
        $out .= $this->emitDeadLabel();
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Restore the backtrace depth saved at try entry (a caught throw skipped
     *  the per-call bt_pop()s). No-op when traces are off. */
    private function btRestore(string $btSlot): string
    {
        if ($btSlot === '') { return ''; }
        $b = $this->allocSsa();
        return '  ' . $b . ' = load i64, ptr ' . $btSlot . "\n"
             . '  store i64 ' . $b . ", ptr @__mir_bt_depth\n";
    }

    private function emitTryCatch(Node $n): string
    {
        $this->needsExceptions = true;
        $tc = $this->castTryCatch($n);
        $hasFinally = $tc->hasFinally;
        $endLbl = $this->allocLabel('try_end');
        $finLbl = $hasFinally ? $this->allocLabel('try_fin') : '';
        $joinLbl = $hasFinally ? $finLbl : $endLbl;

        $out = '';
        // Save the backtrace depth at try entry; a caught throw longjmps past
        // the per-call bt_pop()s, so the catch restores it (else the stack keeps
        // the unwound frames and later traces grow). alloca survives setjmp.
        $btSlot = '';
        if ($this->needsBacktrace) {
            $btSlot = $this->allocSsa();
            $out .= '  ' . $btSlot . " = alloca i64\n";
            $bd = $this->allocSsa();
            $out .= '  ' . $bd . " = load i64, ptr @__mir_bt_depth\n";
            $out .= '  store i64 ' . $bd . ', ptr ' . $btSlot . "\n";
        }
        $pendFlag = '';
        $pendVal = '';
        if ($hasFinally) {
            $pendFlag = $this->allocSsa();
            $pendVal = $this->allocSsa();
            if ($this->inGenerator && $tc->genPendSlot >= 0) {
                // Frame cells (a yield in the try bypasses an alloca via the
                // resume switch). Use the entry-block GEPs precomputed in
                // {@see $this->slots} so the pointer dominates every use across
                // the resume re-entry; an inline GEP in gen.start would not.
                $pendFlag = $this->slots["@try.pf." . (string)$tc->genPendSlot];
                $pendVal = $this->slots["@try.pv." . (string)$tc->genPendSlot];
            } else {
                $out .= '  ' . $pendFlag . " = alloca i64\n";
                $out .= '  ' . $pendVal . " = alloca ptr\n";
            }
            $out .= '  store i64 0, ptr ' . $pendFlag . "\n";
            $out .= '  store ptr null, ptr ' . $pendVal . "\n";
        }

        // Outer buf (finally only) — routes escapes through finally.
        $outerCatchLbl = '';
        if ($hasFinally) {
            $outerCatchLbl = $this->allocLabel('try_outercatch');
            $bodyLbl = $this->allocLabel('try_outerbody');
            $od = $this->allocSsa();
            $out .= '  ' . $od . " = load i64, ptr @__mir_jmp_depth\n";
            $out .= $this->tryStoreDepth($tc->genOuterSlot, $od);
            $out .= $this->jmpBufExpr($od);
            $outerBuf = $this->jmpScratch;
            $nd = $this->allocSsa();
            $out .= '  ' . $nd . ' = add i64 ' . $od . ", 1\n";
            $out .= '  store i64 ' . $nd . ", ptr @__mir_jmp_depth\n";
            $osj = $this->allocSsa();
            $out .= '  ' . $osj . ' = call i32 @setjmp(ptr ' . $outerBuf . ")\n";
            $oc = $this->allocSsa();
            $out .= '  ' . $oc . ' = icmp eq i32 ' . $osj . ", 0\n";
            $out .= '  br i1 ' . $oc . ', label %' . $bodyLbl . ', label %' . $outerCatchLbl . "\n";
            $out .= $bodyLbl . ":\n";
        }

        // Inner buf — try body / catch dispatch.
        $idb = $this->allocSsa();
        $out .= '  ' . $idb . " = load i64, ptr @__mir_jmp_depth\n";
        $out .= $this->tryStoreDepth($tc->genDepthSlot, $idb);
        $out .= $this->jmpBufExpr($idb);
        $innerBuf = $this->jmpScratch;
        $ind = $this->allocSsa();
        $out .= '  ' . $ind . ' = add i64 ' . $idb . ", 1\n";
        $out .= '  store i64 ' . $ind . ", ptr @__mir_jmp_depth\n";
        $sj = $this->allocSsa();
        $out .= '  ' . $sj . ' = call i32 @setjmp(ptr ' . $innerBuf . ")\n";
        $tryLbl = $this->allocLabel('try_body');
        $hasCatch = \count($tc->catches) > 0;
        // No catch but finally present: an inner throw must still record
        // the pending exception so finally re-throws it afterwards.
        $catchlessFin = (!$hasCatch && $hasFinally);
        if ($hasCatch) {
            $catchLbl = $this->allocLabel('try_catch');
        } elseif ($catchlessFin) {
            $catchLbl = $this->allocLabel('try_catchless');
        } else {
            $catchLbl = $joinLbl;
        }
        $cnd = $this->allocSsa();
        $out .= '  ' . $cnd . ' = icmp eq i32 ' . $sj . ", 0\n";
        $out .= '  br i1 ' . $cnd . ', label %' . $tryLbl . ', label %' . $catchLbl . "\n";

        // A `return` inside the try / catch bodies must run this finally before
        // exiting the function — make the finally body visible to emitReturn.
        // Popped before the finally's own emission (the finally is not
        // self-protected).
        if ($hasFinally) { $this->finallyStack[] = $tc->finallyBody; }

        // Try body — pop inner depth on normal exit.
        $out .= $tryLbl . ":\n";
        foreach ($tc->tryBody as $s) { $out .= $this->emitNode($s); $out .= $this->emitDiscardedCallRelease($s); }
        $out .= $this->tryReloadDepth($tc->genDepthSlot, $idb);
        $out .= '  store i64 ' . $this->tryDepthScratch . ", ptr @__mir_jmp_depth\n";
        if ($hasFinally) {
            // Success path: clear pending so finally doesn't rethrow.
            $out .= '  store i64 0, ptr ' . $pendFlag . "\n";
        }
        $out .= '  br label %' . $joinLbl . "\n";

        if ($catchlessFin) {
            $out .= $catchLbl . ":\n";
            $out .= $this->tryReloadDepth($tc->genDepthSlot, $idb);
            $out .= '  store i64 ' . $this->tryDepthScratch . ", ptr @__mir_jmp_depth\n";
            $out .= $this->btRestore($btSlot);
            $clt = $this->allocSsa();
            $out .= '  ' . $clt . " = load ptr, ptr @__mir_thrown\n";
            $out .= '  store ptr ' . $clt . ', ptr ' . $pendVal . "\n";
            $out .= '  store i64 1, ptr ' . $pendFlag . "\n";
            $out .= '  br label %' . $joinLbl . "\n";
        }

        // Catch dispatch.
        if ($hasCatch) {
            $out .= $catchLbl . ":\n";
            $out .= $this->tryReloadDepth($tc->genDepthSlot, $idb);
            $out .= '  store i64 ' . $this->tryDepthScratch . ", ptr @__mir_jmp_depth\n";
            $out .= $this->btRestore($btSlot);
            $thrown = $this->allocSsa();
            $out .= '  ' . $thrown . " = load ptr, ptr @__mir_thrown\n";
            $out .= $this->emitLoadClassId($thrown);
            $cid = $this->classIdReg;
            foreach ($tc->catches as $c) {
                $matchLbl = $this->allocLabel('catch_match');
                $nextLbl = $this->allocLabel('catch_next');
                $cVar = $this->catchVar($c);
                $cTypes = $this->catchTypes($c);
                if ($this->catchAcceptsAll($cTypes)) {
                    $out .= '  br label %' . $matchLbl . "\n";
                } else {
                    $out .= $this->classIdInChain($cid, $this->catchClassIds($cTypes));
                    $out .= '  br i1 ' . $this->ccScratch . ', label %' . $matchLbl
                          . ', label %' . $nextLbl . "\n";
                }
                $out .= $matchLbl . ":\n";
                if ($cVar !== null && isset($this->slots[$cVar])) {
                    $ti = $this->allocSsa();
                    $out .= '  ' . $ti . ' = ptrtoint ptr ' . $thrown . " to i64\n";
                    $out .= '  store i64 ' . $ti . ', ptr ' . $this->slots[$cVar] . "\n";
                }
                foreach ($this->catchBody($c) as $s) { $out .= $this->emitNode($s); $out .= $this->emitDiscardedCallRelease($s); }
                $out .= '  br label %' . $joinLbl . "\n";
                $out .= $nextLbl . ":\n";
            }
            // No catch matched — rethrow through the next outer buf.
            $out .= $this->emitRethrowAt();
        }

        // Finally. Pop first — the finally body is not protected by itself, and
        // a `return` inside it must not re-inline this same finally.
        if ($hasFinally) {
            \array_pop($this->finallyStack);
            $out .= $outerCatchLbl . ":\n";
            // Record the in-flight exception for rethrow after finally.
            $oce = $this->allocSsa();
            $out .= '  ' . $oce . " = load ptr, ptr @__mir_thrown\n";
            $out .= '  store ptr ' . $oce . ', ptr ' . $pendVal . "\n";
            $out .= '  store i64 1, ptr ' . $pendFlag . "\n";
            $out .= '  br label %' . $finLbl . "\n";

            $out .= $finLbl . ":\n";
            // Done with the outer buf either way: depth back to the entry depth
            // ($od). In a generator the snapshot is reloaded from the frame (a
            // yield in the try bypasses the entry SSA via the resume switch).
            $out .= $this->tryReloadDepth($tc->genOuterSlot, $od);
            $out .= '  store i64 ' . $this->tryDepthScratch . ", ptr @__mir_jmp_depth\n";
            foreach ($tc->finallyBody as $s) { $out .= $this->emitNode($s); $out .= $this->emitDiscardedCallRelease($s); }
            $rethrowLbl = $this->allocLabel('try_rethrow');
            $pf = $this->allocSsa();
            $out .= '  ' . $pf . ' = load i64, ptr ' . $pendFlag . "\n";
            $pc = $this->allocSsa();
            $out .= '  ' . $pc . ' = icmp ne i64 ' . $pf . ", 0\n";
            $out .= '  br i1 ' . $pc . ', label %' . $rethrowLbl . ', label %' . $endLbl . "\n";
            $out .= $rethrowLbl . ":\n";
            $sv = $this->allocSsa();
            $out .= '  ' . $sv . ' = load ptr, ptr ' . $pendVal . "\n";
            $out .= '  store ptr ' . $sv . ", ptr @__mir_thrown\n";
            $out .= $this->emitRethrowAt();
        }

        $out .= $endLbl . ":\n";
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** longjmp through the current topmost buf (depth-1). */
    private function emitRethrowAt(): string
    {
        $d = $this->allocSsa();
        $out = '  ' . $d . " = load i64, ptr @__mir_jmp_depth\n";
        $s = $this->allocSsa();
        $out .= '  ' . $s . ' = sub i64 ' . $d . ", 1\n";
        $out .= $this->jmpBufExpr($s);
        $out .= '  call void @longjmp(ptr ' . $this->jmpScratch . ", i32 1)\n";
        $out .= "  unreachable\n";
        // No dead label: a basic-block label always follows this in
        // emitTryCatch, which starts the next block on its own.
        return $out;
    }

    private string $ccScratch = '';

    /**
     * Build an i1 "thrown class-id is one of $ids" test; the result reg
     * is left in {@see $ccScratch} (avoids a tuple return — self-host
     * mis-reads array-element results). Returns the IR.
     * @param int[] $ids
     */
    private function classIdInChain(string $cidReg, array $ids): string
    {
        if (\count($ids) === 0) { $this->ccScratch = '0'; return ''; }
        $acc = '';
        $first = true;
        $out = '';
        foreach ($ids as $id) {
            $eq = $this->allocSsa();
            $out .= '  ' . $eq . ' = icmp eq i64 ' . $cidReg . ', ' . (string)$id . "\n";
            if ($first) { $acc = $eq; $first = false; continue; }
            $or = $this->allocSsa();
            $out .= '  ' . $or . ' = or i1 ' . $acc . ', ' . $eq . "\n";
            $acc = $or;
        }
        $this->ccScratch = $acc;
        return $out;
    }

    /**
     * True iff a catch accepts every throwable — `Throwable` itself or any
     * unknown class. Kept separate from {@see catchClassIds} because a union
     * (`int[]|string`) return is boxed to a tagged cell self-host, and the
     * caller's `count()`/`foreach` then operate on the cell (UAF/garbage).
     * @param string[] $types
     */
    private function catchAcceptsAll(array $types): bool
    {
        foreach ($types as $t) {
            if ($t === 'Throwable' || !isset($this->classes[$t])) { return true; }
        }
        return false;
    }

    /**
     * Accepted class-ids for a catch (assumes !catchAcceptsAll): each named
     * class's id + descendants.
     * @param string[] $types
     * @return int[]
     */
    private function catchClassIds(array $types): array
    {
        $ids = [];
        foreach ($types as $t) {
            foreach ($this->descendantClassIds($t) as $cid) { $ids[] = $cid; }
        }
        return $ids;
    }

    /**
     * Class + every descendant's class_id as an int list. Returning ids
     * (not names) sidesteps the self-host trap where a returned
     * `string[]`'s elements read back as raw i64 pointers.
     * @return int[]
     */
    private function descendantClassIds(string $class): array
    {
        $ids = [];
        $self = $this->classes[$class] ?? null;
        if ($self !== null) { $ids[] = $self->classId; }
        foreach ($this->classes as $cd) {
            if ($cd->name === $class) { continue; }
            $c = $cd->parent;
            while ($c !== '') {
                if ($c === $class) { $ids[] = $cd->classId; break; }
                $pc = $this->classes[$c] ?? null;
                $c = $pc !== null ? $pc->parent : '';
            }
        }
        return $ids;
    }
}
