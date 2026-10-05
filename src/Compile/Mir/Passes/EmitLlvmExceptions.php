<?php

namespace Compile\Mir\Passes;

use Compile\Mir\MirCatch;
use Compile\Mir\Node;
use Compile\Mir\Type;
use Compile\Runtime\UnwindRuntime;

/**
 * Exception emitters extracted from {@see EmitLlvm}: throw / try-catch /
 * rethrow, MirCatch field accessors, and catch-class matching (class-id
 * chains, descendant sets).
 *
 * Zero-cost unwinding ({@see UnwindRuntime}): a throw is `@__mc_throw`, and
 * every call inside a try region that may unwind is an `invoke` whose unwind
 * edge is the region's landing pad ({@see ehInvokeRegion}). A try costs
 * nothing until something throws.
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

    /** The `invoke` result prefix (`%r = ` or '') of the line {@see ehCallRest} just accepted. */
    private string $ehLhs = '';
    /** The Throwable the last {@see ehPad} took off the unwinder. */
    private string $ehPadObj = '';

    /** ` personality …` for the `define` of a function that holds a landing pad. */
    private function personalityClause(): string
    {
        return $this->locals->hasTry ? UnwindRuntime::PERSONALITY : '';
    }

    /** A PHP catch pad at `$label`; the Throwable lands in {@see $ehPadObj}. */
    private function ehPad(string $label): string
    {
        $lp = $this->ssa->allocReg();
        $ex = $this->ssa->allocReg();
        $this->ehPadObj = $this->ssa->allocReg();
        return UnwindRuntime::catchPad($label, $lp, $ex, $this->ehPadObj);
    }

    /**
     * Route every call in `$text` that may unwind to the landing pad `$pad`:
     * `call` becomes `invoke … to label %k unwind label %pad` and the rest of
     * the block continues at the fresh `k:`.
     *
     * `$text` is one try region's IR, starting at a label. A nested try already
     * rewrote its own body to ITS pad, so what is still a `call` here — the
     * inner catch dispatch, catch and finally bodies — is exactly what this
     * region protects. Splitting a block moves its terminator to the last
     * continuation, so every `phi` in the region that names the split block as
     * a predecessor is retargeted to it ({@see ehPhiRetarget}); nothing outside
     * the region can name a block inside it, since a try is a statement.
     */
    private function ehInvokeRegion(string $text, string $pad): string
    {
        $lines = \explode("\n", $text);
        $n = \count($lines);
        $cur = '';
        /** @var array<string, string> */
        $last = [];
        $split = false;
        for ($i = 0; $i < $n; $i = $i + 1) {
            $l = $lines[$i];
            $len = \strlen($l);
            if ($len === 0) { continue; }
            if ($l[0] !== ' ') {
                if ($l[$len - 1] === ':' && $l[0] !== ';') { $cur = \substr($l, 0, $len - 1); }
                continue;
            }
            $rest = $this->ehCallRest($l);
            if ($rest === '') { continue; }
            $k = $this->ssa->allocLabel('eh.c');
            $lines[$i] = '  ' . $this->ehLhs . 'invoke ' . $rest . ' to label %' . $k
                . ' unwind label %' . $pad . "\n" . $k . ':';
            if ($cur !== '') { $last[$cur] = $k; }
            $split = true;
        }
        if (!$split) { return $text; }
        if ($last !== []) {
            for ($i = 0; $i < $n; $i = $i + 1) {
                if (\strpos($lines[$i], ' = phi ') !== false) {
                    $lines[$i] = $this->ehPhiRetarget($lines[$i], $last);
                }
            }
        }
        return \implode("\n", $lines);
    }

    /**
     * The `call` operand text (`<ty> <callee>(<args>) …`) of an instruction line
     * that may unwind, or '' to leave the line alone; the result prefix goes
     * to {@see $ehLhs}.
     *
     * Kept as `call`: intrinsics, inline asm, a named callee outside the PHP
     * namespaces (libc, FFI C symbols — no PHP throw starts there), and the
     * runtime helpers {@see ehNounwind} lists. Everything else — a PHP
     * function, a runtime helper that can reach user code (a release that runs
     * a destructor, a reflective call), an indirect call — is an invoke.
     */
    private function ehCallRest(string $l): string
    {
        $body = \substr($l, 2);
        $lhs = '';
        if ($body !== '' && $body[0] === '%') {
            $eq = \strpos($body, ' = ');
            if ($eq === false) { return ''; }
            $lhs = \substr($body, 0, $eq + 3);
            $body = \substr($body, $eq + 3);
        }
        if (\str_starts_with($body, 'tail call ')) { $body = \substr($body, 5); }
        if (!\str_starts_with($body, 'call ')) { return ''; }
        $rest = \substr($body, 5);
        $at = \strpos($rest, '@');
        $pc = \strpos($rest, '%');
        $ai = $at === false ? -1 : $at;
        $pi = $pc === false ? -1 : $pc;
        $p = $ai;
        if ($p < 0 || ($pi >= 0 && $pi < $p)) { $p = $pi; }
        if ($p < 0) { return ''; }
        $asm = \strpos($rest, ' asm ');
        if ($asm !== false && $asm < $p) { return ''; }
        if ($rest[$p] === '@') {
            $q = \strpos($rest, '(', $p);
            if ($q === false) { return ''; }
            $callee = \substr($rest, $p, $q - $p);
            if (!\str_starts_with($callee, '@manticore_') && !\str_starts_with($callee, '@__')) { return ''; }
            if ($this->ehNounwind($callee)) { return ''; }
        }
        $this->ehLhs = $lhs;
        return $rest;
    }

    /** Runtime helpers that never run user code and never throw. */
    private function ehNounwind(string $callee): bool
    {
        return $callee === '@__mc_eh_catch'
            || $callee === '@__mir_elem_untag'
            || \str_starts_with($callee, '@__mir_arena_')
            || \str_starts_with($callee, '@__mir_rc_retain')
            || \str_starts_with($callee, '@__manticore_box_');
    }

    /**
     * `$line` (a `phi`) with every incoming block that {@see ehInvokeRegion}
     * split renamed to its last continuation.
     * @param array<string, string> $last
     */
    private function ehPhiRetarget(string $line, array $last): string
    {
        // An incoming block is the `%name` after the last comma of a pair, right
        // before its `]` — both `[ v, %b ]` and `[v, %b]` are emitted. A value
        // never sits there, so no bracket matching is needed.
        $out = '';
        $p = 0;
        $len = \strlen($line);
        while ($p < $len) {
            $cm = \strpos($line, ', %', $p);
            if ($cm === false) { break; }
            $s = $cm + 3;
            $e = $s;
            while ($e < $len && $line[$e] !== ']' && $line[$e] !== ' ' && $line[$e] !== ',') { $e = $e + 1; }
            $f = $e;
            while ($f < $len && $line[$f] === ' ') { $f = $f + 1; }
            $lab = \substr($line, $s, $e - $s);
            $out .= \substr($line, $p, $s - $p);
            if ($f < $len && $line[$f] === ']' && isset($last[$lab])) {
                $out .= $last[$lab];
            } else {
                $out .= $lab;
            }
            $p = $e;
        }
        return $out . \substr($line, $p);
    }

    private function emitThrow(\Compile\Mir\Throw_ $n): string
    {
        $this->rt->needsExceptions = true;
        $out = $this->emitNode($n->value);
        // `throw $e` where $e came off an ERASED channel — a `mixed` array
        // element, an untyped property, a `\Throwable` handed through a cell —
        // holds a NaN-BOXED word, not an object address. A bare inttoptr stored
        // the tag bits as the pointer, and the catch arm dereferenced
        // 0xfff8_0000_xxxx_xxxx on the first ->getMessage(). The 48-bit payload
        // mask is the identity on an already-raw pointer, so this costs nothing
        // for every other carrier. Same fix, same reason, as
        // {@see EmitLlvmArrays::arrayBaseToPtr}.
        $tk = $n->value->type->kind;
        $out .= ($tk === Type::KIND_CELL || $tk === Type::KIND_UNKNOWN)
            ? $this->cellToPtr()
            : $this->coerceToPtr();
        // The exception object owns what it carries, and the catch that binds it
        // takes that +1 ({@see emitCaughtValue}): a fresh object hands its own, an
        // owned local leaving the function moves its reference
        // ({@see \Compile\Mir\Throw_::$ownMove}), anything else is retained.
        $v = $n->value;
        $moved = $n->ownMove && $v->kind === Node::KIND_LOAD_LOCAL;
        if (!$moved && $this->own->classifyTemp($v, $this->lastCallWasBuiltin) <= 0) {
            $tp = $this->lastValue;
            $ti = $this->ssa->allocReg();
            $out .= '  ' . $ti . ' = ptrtoint ptr ' . $tp . " to i64\n";
            $out .= $this->rcRetainReg($ti, 'obj');
            $this->lastValue = $tp;
            $this->lastValueType = 'ptr';
        }
        $out .= $this->emitRethrowAt($this->lastValue);
        $out .= $this->emitDeadLabel();
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /**
     * `@__mir_thrown`'s +1, taken out of the slot by the catch that matched
     * it — the slot no longer owns it ({@see \Compile\Mir\CaughtValue_}).
     */
    private function emitCaughtValue(): string
    {
        $r = $this->ssa->allocReg();
        $out = '  ' . $r . " = load i64, ptr @__mir_thrown\n  store i64 0, ptr @__mir_thrown\n";
        $this->lastValue = $r;
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Restore the backtrace depth saved at try entry (a caught throw skipped
     *  the per-call bt_pop()s). No-op when traces are off. */
    private function btRestore(string $btSlot): string
    {
        if ($btSlot === '') { return ''; }
        $b = $this->ssa->allocReg();
        return '  ' . $b . ' = load i64, ptr ' . $btSlot . "\n"
             . '  store i64 ' . $b . ", ptr @__mir_bt_depth\n";
    }

    /**
     * Open a try region's arena landing: a throw skips the `arena_leave` of
     * every frame it unwinds and the exit restore of every resetting loop it
     * leaves, and whatever those would have reclaimed piles up once per throw
     * when no enclosing loop resets. The landing ({@see arenaTryLandingIr})
     * pops the skipped frames' marks back to the depth sampled at try entry,
     * and rewinds to the landing mark a resetting loop in the region armed
     * ({@see EmitLlvm::arenaArmTryMark}). The reg to hold that depth. In a
     * generator it is the depth its resume body was entered with
     * ({@see \Compile\Mir\GeneratorContext::$entryArenaSp}), not one sampled at
     * the try: a yield inside it hands the mark stack to the consumer between
     * try entry and landing, and a generator pushes no mark of its own.
     */
    private function arenaTryEnter(): string
    {
        $this->rt->needsArena = true;
        $this->arena->tryMarkCur[] = $this->ssa->allocReg();
        $this->arena->tryMarkUsed[] = $this->ssa->allocReg();
        $this->arena->tryMarkArmed[] = 0;
        $this->arena->tryMarkOpen[] = 0;
        return $this->gen->inGenerator ? $this->gen->entryArenaSp : $this->ssa->allocReg();
    }

    /** The landing's arena reclaim, for the innermost open region. */
    private function arenaTryLandingIr(string $spReg): string
    {
        if ($spReg === '') { return ''; }
        $out = '  call void @__mir_arena_unwind(i64 ' . $spReg . ")\n";
        $k = \count($this->arena->tryMarkCur) - 1;
        if ($this->arena->tryMarkArmed[$k] === 0) { return $out; }
        $mp = $this->arena->tryMarkUsed[$k];
        $u = $this->ssa->allocReg();
        $on = $this->ssa->allocReg();
        $c = $this->ssa->allocReg();
        $rl = $this->ssa->allocLabel('try_arena');
        $jl = $this->ssa->allocLabel('try_arena_done');
        $out .= '  ' . $u . ' = load i64, ptr ' . $mp . "\n";
        $out .= '  ' . $on . ' = icmp sge i64 ' . $u . ", 0\n";
        $out .= '  br i1 ' . $on . ', label %' . $rl . ', label %' . $jl . "\n";
        $out .= $rl . ":\n";
        $out .= '  ' . $c . ' = load ptr, ptr ' . $this->arena->tryMarkCur[$k] . "\n";
        $out .= '  call void @__mir_arena_restore(ptr ' . $c . ', i64 ' . $u . ")\n";
        $out .= '  store i64 -1, ptr ' . $mp . "\n";
        $out .= '  br label %' . $jl . "\n";
        $out .= $jl . ":\n";
        return $out;
    }

    /** Close the innermost region's arena landing; the mark's allocas and
     *  initial disarm when a loop armed it, for the try's entry. */
    private function arenaTryLeave(): string
    {
        $k = \count($this->arena->tryMarkCur) - 1;
        $cur = $this->arena->tryMarkCur[$k];
        $used = $this->arena->tryMarkUsed[$k];
        $armed = $this->arena->tryMarkArmed[$k];
        \array_pop($this->arena->tryMarkCur);
        \array_pop($this->arena->tryMarkUsed);
        \array_pop($this->arena->tryMarkArmed);
        \array_pop($this->arena->tryMarkOpen);
        if ($armed === 0) { return ''; }
        return '  ' . $cur . " = alloca ptr\n"
            . '  ' . $used . " = alloca i64\n"
            . '  store i64 -1, ptr ' . $used . "\n";
    }

    private function emitTryCatch(\Compile\Mir\TryCatch_ $n): string
    {
        $this->rt->needsExceptions = true;
        $hasFinally = $n->hasFinally;
        $hasCatch = \count($n->catches) > 0;
        $endLbl = $this->ssa->allocLabel('try_end');
        $finLbl = $hasFinally ? $this->ssa->allocLabel('try_fin') : '';
        $joinLbl = $hasFinally ? $finLbl : $endLbl;
        $tryLbl = $this->ssa->allocLabel('try_body');
        $catchLbl = $hasCatch ? $this->ssa->allocLabel('try_catch') : '';
        $finPadLbl = $hasFinally ? $this->ssa->allocLabel('try_finpad') : '';

        $out = '';
        $markInit = '';
        $spReg = $this->arenaTryEnter();
        if ($spReg !== '' && !$this->gen->inGenerator) { $out .= '  ' . $spReg . " = load i64, ptr @__mir_arena_sp\n"; }
        // Save the backtrace depth at try entry; a caught throw unwinds past
        // the per-call bt_pop()s, so the landing pad restores it (else the stack
        // keeps the unwound frames and later traces grow).
        $btSlot = '';
        if ($this->rt->needsBacktrace) {
            // In a generator the snapshot lives in a FRAME CELL: this alloca sits
            // in whatever block the `try` occupies, the restore reads it from the
            // landing pad, and the resume switch re-enters past the former — so
            // the alloca dominates neither use.
            // `Instruction does not dominate all uses` out of symfony/cache's
            // doDeleteYieldTags, whose try sits inside an `if` inside a loop.
            if ($this->gen->inGenerator && $n->genBtSlot >= 0
                && isset($this->locals->slots["@try.bt." . (string)$n->genBtSlot])) {
                $btSlot = $this->locals->slots["@try.bt." . (string)$n->genBtSlot];
            } else {
                $btSlot = $this->ssa->allocReg();
                $out .= '  ' . $btSlot . " = alloca i64\n";
            }
            $bd = $this->ssa->allocReg();
            $out .= '  ' . $bd . " = load i64, ptr @__mir_bt_depth\n";
            $out .= '  store i64 ' . $bd . ', ptr ' . $btSlot . "\n";
        }
        $pendFlag = '';
        $pendVal = '';
        if ($hasFinally) {
            $pendFlag = $this->ssa->allocReg();
            $pendVal = $this->ssa->allocReg();
            if ($this->gen->inGenerator && $n->genPendSlot >= 0) {
                // Frame cells (a yield in the try bypasses an alloca via the
                // resume switch). Use the entry-block GEPs precomputed in
                // {@see $this->locals->slots} so the pointer dominates every use across
                // the resume re-entry; an inline GEP in gen.start would not.
                $pendFlag = $this->locals->slots["@try.pf." . (string)$n->genPendSlot];
                $pendVal = $this->locals->slots["@try.pv." . (string)$n->genPendSlot];
            } else {
                $out .= '  ' . $pendFlag . " = alloca i64\n";
                $out .= '  ' . $pendVal . " = alloca ptr\n";
            }
            $out .= '  store i64 0, ptr ' . $pendFlag . "\n";
            $out .= '  store ptr null, ptr ' . $pendVal . "\n";
            // A `return` inside the try / catch bodies must run this finally
            // before exiting the function — make the finally body visible to
            // emitReturn. Popped before the finally's own emission (the finally
            // is not self-protected).
            $this->cf->pushFinally($n->finallyBody);
        }
        $out .= '  br label %' . $tryLbl . "\n";

        // The try body. Its calls unwind to the catch pad, or — catchless — to
        // the finally pad.
        $region = $tryLbl . ":\n";
        foreach ($n->tryBody as $s) { $region .= $this->emitNode($s); $region .= $this->emitDiscardedCallRelease($s); }
        $region .= '  br label %' . $joinLbl . "\n";

        if ($hasCatch) {
            $region = $this->ehInvokeRegion($region, $catchLbl);
            $region .= $this->ehPad($catchLbl);
            $thrown = $this->ehPadObj;
            $region .= $this->btRestore($btSlot);
            $region .= $this->arenaTryLandingIr($spReg);
            if (!$hasFinally) { $markInit = $this->arenaTryLeave(); }
            $region .= $this->emitLoadClassId($thrown);
            $cid = $this->classIdReg;
            foreach ($n->catches as $c) {
                $matchLbl = $this->ssa->allocLabel('catch_match');
                $nextLbl = $this->ssa->allocLabel('catch_next');
                $cTypes = $this->catchTypes($c);
                if ($this->catchAcceptsAll($cTypes)) {
                    $region .= '  br label %' . $matchLbl . "\n";
                } else {
                    $region .= $this->classIdInChain($cid, $this->catchClassIds($cTypes));
                    $region .= '  br i1 ' . $this->ccScratch . ', label %' . $matchLbl
                          . ', label %' . $nextLbl . "\n";
                }
                $region .= $matchLbl . ":\n";
                foreach ($this->catchBody($c) as $s) { $region .= $this->emitNode($s); $region .= $this->emitDiscardedCallRelease($s); }
                $region .= '  br label %' . $joinLbl . "\n";
                $region .= $nextLbl . ":\n";
            }
            // No catch matched — raise it again from here: the finally pad below
            // (when there is one) or the next frame out.
            $region .= $this->emitRethrowAt($thrown);
        }

        if (!$hasFinally) {
            $out .= $region;
        } else {
            // Finally. Pop first — the finally body is not protected by itself, and
            // a `return` inside it must not re-inline this same finally.
            $this->cf->popFinally();
            // An exception out of the try body (catchless) or out of a catch body
            // runs the finally, then goes on up.
            $out .= $this->ehInvokeRegion($region, $finPadLbl);
            $out .= $this->ehPad($finPadLbl);
            $out .= $this->btRestore($btSlot);
            $out .= $this->arenaTryLandingIr($spReg);
            $markInit = $this->arenaTryLeave();
            $out .= '  store ptr ' . $this->ehPadObj . ', ptr ' . $pendVal . "\n";
            $out .= '  store i64 1, ptr ' . $pendFlag . "\n";
            $out .= '  br label %' . $finLbl . "\n";

            $out .= $finLbl . ":\n";
            foreach ($n->finallyBody as $s) { $out .= $this->emitNode($s); $out .= $this->emitDiscardedCallRelease($s); }
            $rethrowLbl = $this->ssa->allocLabel('try_rethrow');
            $pf = $this->ssa->allocReg();
            $out .= '  ' . $pf . ' = load i64, ptr ' . $pendFlag . "\n";
            $pc = $this->ssa->allocReg();
            $out .= '  ' . $pc . ' = icmp ne i64 ' . $pf . ", 0\n";
            $out .= '  br i1 ' . $pc . ', label %' . $rethrowLbl . ', label %' . $endLbl . "\n";
            $out .= $rethrowLbl . ":\n";
            $sv = $this->ssa->allocReg();
            $out .= '  ' . $sv . ' = load ptr, ptr ' . $pendVal . "\n";
            $out .= $this->emitRethrowAt($sv);
        }

        $out .= $endLbl . ":\n";
        $out = $markInit . $out;
        $this->lastValue = '0';
        $this->lastValueType = 'i64';
        return $out;
    }

    /** Raise the Throwable `$objReg` (a `ptr`, owning its +1). Ends the block. */
    private function emitRethrowAt(string $objReg): string
    {
        return '  call void @__mc_throw(ptr ' . $objReg . ")\n  unreachable\n";
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
            $eq = $this->ssa->allocReg();
            $out .= '  ' . $eq . ' = icmp eq i64 ' . $cidReg . ', ' . (string)$id . "\n";
            if ($first) { $acc = $eq; $first = false; continue; }
            $or = $this->ssa->allocReg();
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
            // A reified specialization is caught by its ORIGIN's name, which the
            // plain parent walk cannot see once the spec's parent is itself a
            // specialization (see classIsA).
            if ($cd->originClass !== '' && $this->classIsA($cd->name, $class)) {
                $ids[] = $cd->classId;
                continue;
            }
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
