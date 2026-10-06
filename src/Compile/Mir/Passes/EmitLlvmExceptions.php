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
    /** The named callee (`@sym`) of the line {@see ehCallRest} just accepted; '' when indirect. */
    private string $ehCallee = '';
    /** The Throwable the last {@see ehPad} took off the unwinder. */
    private string $ehPadObj = '';

    /** ` personality …` for the `define` of a function that holds a landing pad. */
    private function personalityClause(): string
    {
        return $this->locals->hasTry || $this->ehPersonality ? UnwindRuntime::PERSONALITY : '';
    }

    // ── cleanup pads: an unwind out of the frame drops its owned locals ──
    //
    // While a function body is emitted, every may-unwind `call` line a call
    // node, a `throw` or a try's re-raise produces is MARKED (` ;!eK`) with the
    // set of locals Own at that point ({@see \Compile\Mir\Call::$ownLive}): K > 0
    // names a cleanup pad, 0 none. Marking is post-order, so a nested call has
    // marked its own lines before its parent sees the text; the non-call
    // operands of a call mark theirs 0, since their state is not the call's.
    // A try region rewrites its lines to its own pad first ({@see
    // ehInvokeRegion}: the locals stay alive for the catch); what is still a
    // marked `call` at the end of a top-level statement becomes `invoke … unwind
    // label %ehlp.K` ({@see ehLower}). One pad per distinct drop set per
    // function: `landingpad cleanup`, the drops, `resume`.

    /** Marking is on for the function being emitted. */
    private bool $ehOn = false;
    /** The function's define carries a personality for its cleanup pads. */
    private bool $ehPersonality = false;
    /** The SSA the function body is emitted in; an outlined helper's nodes are not marked. */
    private ?\Compile\Mir\SsaBuilder $ehSsa = null;
    /** The node being emitted is an operand of a call node. */
    private bool $ehInCall = false;
    /** Call nodes open around the node being emitted. */
    private int $ehCallDepth = 0;
    /** @var array<string, int> drop-set key → pad number */
    private array $ehPadKeys = [];
    /** @var array<int, string> pad number → label */
    private array $ehPadLabels = [];
    /** @var array<int, string> pad number → its IR */
    private array $ehPadBodies = [];
    /** @var array<int, bool> pads some invoke unwinds to */
    private array $ehPadUsed = [];
    /** The function being emitted, for its leaf-statement drop sets. */
    private ?\Compile\Mir\FunctionDef $ehFn = null;
    /** @var array<int, int> leaf statement `spl_object_id` → its index in {@see \Compile\Mir\FunctionDef::$ownStmtNodes} */
    private array $ehStmtIdx = [];

    private function ehBegin(\Compile\Mir\FunctionDef $fn): void
    {
        $this->ehPadKeys = [];
        $this->ehPadLabels = [];
        $this->ehPadBodies = [];
        $this->ehPadUsed = [];
        $this->ehInCall = false;
        $this->ehCallDepth = 0;
        $this->ehSsa = $this->ssa;
        $this->ehOn = false;
        $this->ehPersonality = false;
        $this->ehFn = null;
        $this->ehStmtIdx = [];
        if ($this->nothrow === null || $fn->isGenerator || $this->gen->inGenerator) { return; }
        $this->ehFn = $fn;
        foreach ($fn->ownStmtNodes as $si => $st) { $this->ehStmtIdx[\spl_object_id($st)] = $si; }
        $this->ehPersonality = $fn->ownStmtNodes !== [] || $this->ehNeedsPads($fn->body);
        $this->ehOn = $this->ehPersonality;
    }

    /** The used pads' IR, for the end of the function; marking stops. */
    private function ehEnd(): string
    {
        $out = '';
        foreach ($this->ehPadBodies as $k => $body) {
            if (isset($this->ehPadUsed[$k])) { $out .= $body; }
        }
        $this->ehOn = false;
        $fn = $this->ehFn;
        if ($fn !== null) {
            // The statements are emitted: let them go with the rest of the body.
            $fn->ownStmtNodes = [];
            $fn->ownStmtStart = [];
            $fn->ownStmtEnd = [];
            $fn->ownStmtDrops = [];
        }
        $this->ehFn = null;
        $this->ehStmtIdx = [];
        $this->ehPadKeys = [];
        $this->ehPadLabels = [];
        $this->ehPadBodies = [];
        $this->ehPadUsed = [];
        return $out;
    }

    /** Some point of `$n` would unwind out of the frame with an owned local. */
    private function ehNeedsPads(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_INVOKE) {
            if ($this->callNeedsPad($n)) { return true; }
        } elseif ($k === Node::KIND_THROW) {
            if ($this->ehThrow($n)->ownLive !== []) { return true; }
        } elseif ($k === Node::KIND_TRY_CATCH) {
            $t = $this->ehTry($n);
            if ($t->ownCatch !== [] || $t->ownFinally !== []) { return true; }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            if ($this->ehNeedsPads($c)) { return true; }
        }
        return false;
    }

    private function ehThrow(Node $n): \Compile\Mir\Throw_ { return $n; }
    private function ehTry(Node $n): \Compile\Mir\TryCatch_ { return $n; }

    /** {@see emitNode} while marking: emit, then mark this node's own lines. */
    private function emitNodeEh(Node $n): string
    {
        $k = $n->kind;
        $isCall = $k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_INVOKE;
        $parent = $this->ehInCall;
        $this->ehInCall = $isCall;
        if ($isCall) { $this->ehCallDepth = $this->ehCallDepth + 1; }
        if ($this->irCensus) {
            $out = $this->emitNodeCensus($n);
        } else {
            $out = $n->accept($this);
            if ($this->cellGuard) { $this->markCellCalleeResult($n); }
        }
        if ($isCall) { $this->ehCallDepth = $this->ehCallDepth - 1; }
        $this->ehInCall = $parent;
        if ($this->ssa !== $this->ehSsa) { return $out; }
        if ($isCall) {
            if (!$this->callNeedsPad($n)) { return $this->ehMark($out, []); }
            return $this->ehMark($out, \Compile\Mir\NothrowSummary::ownLive($n));
        }
        // A throw with nothing to drop leaves its raise to the enclosing call: an
        // emitter-synthesised one (intdiv's DivisionByZeroError) has no flow state.
        if ($k === Node::KIND_THROW) {
            $tl = $this->ehThrow($n)->ownLive;
            return $tl === [] ? $out : $this->ehMark($out, $tl);
        }
        if ($parent) { return $this->ehMark($out, []); }
        if ($this->ehStmtIdx !== []) {
            $si = $this->ehStmtIdx[\spl_object_id($n)] ?? -1;
            if ($si >= 0 && $this->ehFn !== null && $this->ehFn->ownStmtNodes[$si] === $n) {
                // A return's own drops run after its value: with a destructor
                // that may throw, one of them may raise mid-drop.
                if ($k === Node::KIND_RETURN && $this->nothrow !== null && $this->nothrow->releasesRaise()) {
                    return $out;
                }
                return $this->ehMark($out, $this->ehStmtLive($si), true);
            }
        }
        return $out;
    }

    /**
     * Leaf statement `$si`'s drop set ({@see \Compile\Mir\FunctionDef::$ownStmtNodes}).
     * @return array<string, \Compile\Mir\MemoryOp_>
     */
    private function ehStmtLive(int $si): array
    {
        $out = [];
        $fn = $this->ehFn;
        if ($fn === null) { return $out; }
        $end = $fn->ownStmtEnd[$si];
        for ($i = $fn->ownStmtStart[$si]; $i < $end; $i = $i + 1) {
            $op = $fn->ownStmtDrops[$i];
            $t = $op->target;
            if ($t === null || $t->kind !== Node::KIND_LOAD_LOCAL) { continue; }
            $out[$this->asLoadLocalNode($t)->name] = $op;
        }
        return $out;
    }
    /**
     * `$text` with every unmarked may-unwind `call` line marked for the drop
     * set `$live` (pad 0 when it drops nothing). Nothing to do when the set
     * is empty and no enclosing call node would mark the lines instead.
     *
     * `$raisesOnly`: only the emitter's own raises (a statement's set covers
     * the throws it synthesises, not every helper of it).
     *
     * @param array<string, \Compile\Mir\MemoryOp_> $live
     */
    private function ehMark(string $text, array $live, bool $raisesOnly = false): string
    {
        if (!$this->ehOn) { return $text; }
        if ($live === [] && $this->ehCallDepth === 0) { return $text; }
        if (\strpos($text, 'call ') === false) { return $text; }
        $pad = $live === [] ? 0 : $this->ehPadFor($live);
        if ($pad === 0 && $this->ehCallDepth === 0) { return $text; }
        $tag = ' ;!e' . (string)$pad;
        $lines = \explode("\n", $text);
        $n = \count($lines);
        $changed = false;
        for ($i = 0; $i < $n; $i = $i + 1) {
            $l = $lines[$i];
            if (\strlen($l) < 8 || $l[0] !== ' ') { continue; }
            if (\strpos($l, 'call ') === false || \strpos($l, ' ;!e') !== false) { continue; }
            if (!$this->ehLineMayThrow($l)) { continue; }
            if ($raisesOnly && !$this->ehRaiseCallee($this->ehCallee)) { continue; }
            $lines[$i] = $l . $tag;
            $changed = true;
        }
        return $changed ? \implode("\n", $lines) : $text;
    }

    /** A raise the emitter synthesises: `@__mc_throw`, or a php-bodied `__mir_*_error` thrower. */
    private function ehRaiseCallee(string $c): bool
    {
        return $c === '@__mc_throw'
            || (\str_starts_with($c, '@manticore___mir_') && \str_ends_with($c, '_error'));
    }

    /** @var array<string, bool> `@manticore_*` symbols of functions that cannot unwind */
    private array $ehNothrowSyms = [];
    private bool $ehNothrowSymsBuilt = false;

    /**
     * May the `call` line `$l` unwind, for a cleanup pad? A judged nothrow
     * function cannot; a runtime helper can unless it is a retain, an
     * allocation or a plain container read, or a release while no destructor
     * of the module may throw ({@see \Compile\Mir\NothrowSummary::releasesRaise}).
     * Anything else — a php function, a dispatch helper that runs user code,
     * a conversion that may call `__toString`, an indirect call — may.
     */
    private function ehLineMayThrow(string $l): bool
    {
        if ($this->ehCallRest($l) === '') { return false; }
        $c = $this->ehCallee;
        if ($c === '') { return true; }
        if (\str_starts_with($c, '@manticore_')) {
            if (!$this->ehNothrowSymsBuilt) {
                $this->ehNothrowSymsBuilt = true;
                if ($this->nothrow !== null) {
                    foreach ($this->nothrow->nothrowNames() as $fn) {
                        $this->ehNothrowSyms['@manticore_' . $this->mangle($fn)] = true;
                    }
                }
            }
            return !isset($this->ehNothrowSyms[$c]);
        }
        if (\str_starts_with($c, '@__mir_array_retain') || \str_starts_with($c, '@__mir_alloc')
            || $c === '@__mir_cell_retain' || $c === '@__mir_str_alloc' || $c === '@__mir_str_from_cstr'
            || $c === '@__mir_array_alloc' || $c === '@__mir_array_value_at' || $c === '@__mir_array_live_len'
            || $c === '@__mir_array_key_cell_at' || $c === '@__mir_strlen' || $c === '@__mir_dtor_reg') {
            return false;
        }
        if ($this->nothrow !== null && !$this->nothrow->releasesRaise()) {
            if ($c === '@__mir_cell_drop' || \str_starts_with($c, '@__mir_rc_release')
                || \str_starts_with($c, '@__mir_array_release') || $c === '@__mir_closure_release') {
                return false;
            }
        }
        return true;
    }

    /**
     * A try's re-raise marked for `$live`, outside any node's marking.
     * @param array<string, \Compile\Mir\MemoryOp_> $live
     */
    private function ehMarkRaise(string $text, array $live): string
    {
        if (!$this->ehOn || $live === [] || $this->ssa !== $this->ehSsa) { return $text; }
        return $this->ehMark($text, $live);
    }

    /**
     * The cleanup pad dropping `$live` (registered on first use), or 0 when no
     * name of it has a slot this frame releases.
     *
     * @param array<string, \Compile\Mir\MemoryOp_> $live
     */
    private function ehPadFor(array $live): int
    {
        $key = '';
        foreach ($live as $name => $op) {
            if ($this->ownOpSlot($op) === '') { continue; }
            $key .= $name . '=' . $this->rcReleaseFlavor($op) . ';';
        }
        if ($key === '') { return 0; }
        if (isset($this->ehPadKeys[$key])) { return $this->ehPadKeys[$key]; }
        $pad = \count($this->ehPadKeys) + 1;
        $this->ehPadKeys[$key] = $pad;
        $lbl = $this->ssa->allocLabel('ehlp');
        $this->ehPadLabels[$pad] = $lbl;
        $lp = $this->ssa->allocReg();
        $body = $lbl . ":\n  " . $lp . " = landingpad { ptr, i32 } cleanup\n";
        foreach ($live as $name => $op) {
            $slot = $this->ownOpSlot($op);
            if ($slot === '') { continue; }
            $body .= $this->ownDropIr($slot, $op);
        }
        $body .= '  resume { ptr, i32 } ' . $lp . "\n";
        $this->ehPadBodies[$pad] = $body;
        return $pad;
    }

    /**
     * One top-level statement's IR with its marks resolved: a `call` marked
     * for a pad becomes `invoke … unwind label %pad` (the block continues at a
     * fresh label, and a `phi` naming the split block is retargeted, as in
     * {@see ehInvokeRegion}); every mark is removed.
     */
    private function ehLower(string $text): string
    {
        if (!$this->ehOn || \strpos($text, ' ;!e') === false) { return $text; }
        $lines = \explode("\n", $text);
        $n = \count($lines);
        $cur = '';
        /** @var array<string, string> */
        $last = [];
        for ($i = 0; $i < $n; $i = $i + 1) {
            $l = $lines[$i];
            $len = \strlen($l);
            if ($len === 0) { continue; }
            if ($l[0] !== ' ') {
                if ($l[$len - 1] === ':' && $l[0] !== ';') { $cur = \substr($l, 0, $len - 1); }
                continue;
            }
            $m = \strpos($l, ' ;!e');
            if ($m === false) { continue; }
            $pad = (int)\substr($l, $m + 4);
            $l = \substr($l, 0, $m);
            $lines[$i] = $l;
            if ($pad === 0) { continue; }
            $rest = $this->ehCallRest($l);
            if ($rest === '') { continue; }
            $k = $this->ssa->allocLabel('eh.c');
            $lines[$i] = '  ' . $this->ehLhs . 'invoke ' . $rest . ' to label %' . $k
                . ' unwind label %' . $this->ehPadLabels[$pad] . "\n" . $k . ':';
            $this->ehPadUsed[$pad] = true;
            if ($cur !== '') { $last[$cur] = $k; }
        }
        if ($last !== []) {
            for ($i = 0; $i < $n; $i = $i + 1) {
                if (\strpos($lines[$i], ' = phi ') !== false) {
                    $lines[$i] = $this->ehPhiRetarget($lines[$i], $last);
                }
            }
        }
        return \implode("\n", $lines);
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
        $callee = '';
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
        $this->ehCallee = $rest[$p] === '@' ? $callee : '';
        $mk = \strpos($rest, ' ;!e');
        if ($mk !== false) { return \substr($rest, 0, $mk); }
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
            $region .= $this->ehMarkRaise($this->emitRethrowAt($thrown), $n->ownCatch);
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
            $out .= $this->ehMarkRaise($this->emitRethrowAt($sv), $n->ownFinally);
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
