<?php

namespace Compile\Mir\Flow;

use Compile\Mir\Block;
use Compile\Mir\Break_;
use Compile\Mir\Continue_;
use Compile\Mir\DoWhile_;
use Compile\Mir\For_;
use Compile\Mir\Foreach_;
use Compile\Mir\Goto_;
use Compile\Mir\If_;
use Compile\Mir\Label_;
use Compile\Mir\Match_;
use Compile\Mir\Node;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Return_;
use Compile\Mir\Switch_;
use Compile\Mir\Ternary;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\Walk;
use Compile\Mir\While_;

/**
 * Structured forward dataflow over one tree-shaped MIR function body.
 *
 * The tree IS the CFG: a statement's successor is the next statement, a loop
 * iterates its body to a fixpoint of the head state, and the non-local jumps
 * (break / continue / goto / return / throw) carry their state to the target
 * that joins them. A state is `array<string, int>`; the DEAD state (one
 * reserved key no local can be named) means "unreachable" and never reaches
 * the {@see Lattice}.
 *
 * ⚠ Not `null`: natively `array<string, int>|null` is a union, i.e. a CELL, and
 * a typed array returned through one is rebuilt with NaN-boxed elements that the
 * lattice's typed reads then take as raw ints (a state value 1 read back as
 * -4222124650659839).
 *
 * - `stateBefore()` answers, per `spl_object_id`, the state at entry of every
 *   node the walk evaluated: statements AND expression nodes (when recording).
 * - A catch arm enters with the join over the try entry and every point inside
 *   the try body (entry and throw point of each node).
 * - A `finally` enters with the join over the try exit, every catch exit, every
 *   exceptional path, and every return / break / continue / goto leaving the
 *   try. Those jumps then continue to their targets with the finally's exit.
 * - A `goto` re-runs the whole body until every label's incoming state is
 *   stable; a goto whose label is never reached is a LogicException.
 * - Code after a jump is dead unless a Label_ inside it (at any depth of a
 *   Block / If_ / TryCatch_ nest — php forbids a goto into a loop or switch)
 *   revives it.
 *
 * Edges passed to {@see Lattice::onEdge} — `$at` / `$pred`:
 *   if-then / if-else   If_ / last stmt of the arm (the arm Block when empty,
 *                       the condition when there is no else); Ternary and
 *                       NullCoalesce_ / the operand evaluated last
 *   loop-back           the loop / last body stmt (the step for a for with a
 *                       step, the condition for a do-while); out = fall-through
 *                       only, every `continue` is its own edge
 *   loop-entry          the loop / itself: the state before the loop (after a
 *                       for's init, a foreach's subject) into the converged head
 *   loop-body           the loop / last body stmt: a for WITH a step and a
 *                       do-while only — the body's fall-through into the step /
 *                       the condition, which their loop-back edge starts after
 *   loop-exit           the loop / its condition (a foreach: its subject)
 *   break / continue    the Break_ / Continue_ / itself — after a finally it
 *                       left: the finally's last stmt
 *   goto                the Goto_ / itself (or the finally's last stmt)
 *   switch-arm          Switch_ / the case value that matched (default and
 *                       no-match: the last case value, else the subject)
 *   fallthrough         Switch_ / last stmt of the previous arm; or Label_ /
 *                       the statement before it (the enclosing node if first)
 *   match-arm           the arm body / the arm body
 *   catch               TryCatch_ / the arm's FIRST stmt (the TryCatch_ when
 *                       empty): the predecessors are every throw point of the
 *                       try body, so the node names the arm instead
 *   finally             TryCatch_ / last stmt of the try body or of a catch
 *                       arm, the jump node for a jump leaving the try, null
 *                       for the exceptional path
 *   return              the Return_ / itself (or the finally's last stmt); the
 *                       implicit return at the end of the body: the body Block
 *                       / its last stmt, null when the body is empty
 *   throw               the Throw_ / itself
 */
final class Forward
{
    private const CAP = 64;
    private const DEAD = '#dead';

    /** @var array<int, array<string, int>> */
    private array $before = [];

    /** @var array<int, array<string, int>> */
    private array $frBreak = [];
    /** @var array<int, bool> */
    private array $frHasBreak = [];
    /** @var array<int, array<string, int>> */
    private array $frCont = [];
    /** @var array<int, bool> */
    private array $frHasCont = [];
    /** @var array<int, bool> */
    private array $frSwitch = [];
    /** @var array<int, array<int, string>> */
    private array $frBreakKeys = [];
    /** @var array<int, array<int, string>> */
    private array $frContKeys = [];

    /** @var array<int, array<string, int>> */
    private array $thrAcc = [];
    /** @var array<int, bool> */
    private array $thrHas = [];

    /** @var array<int, int> */
    private array $finDepth = [];
    /** @var array<int, array<string, bool>> */
    private array $finLabels = [];
    /** @var array<string, bool> */
    private array $labelScan = [];

    /** @var array<int, string> */
    private array $defKind = [];
    /** @var array<int, Node> */
    private array $defAt = [];
    /** @var array<int, int> */
    private array $defTarget = [];
    /** @var array<int, string> */
    private array $defLabel = [];
    /** @var array<int, array<string, int>> */
    private array $defState = [];
    /** @var array<int, int> */
    private array $defOwner = [];
    private int $defNext = 0;

    /** @var array<string, array<string, int>> */
    private array $gotoIn = [];
    /** @var array<string, array<string, int>> */
    private array $gotoPrev = [];
    /** @var array<string, array<string, bool>> */
    private array $gotoKeys = [];
    /** @var array<string, bool> */
    private array $labelSeen = [];
    private bool $sawGoto = false;

    /** @var array<string, string> */
    private array $edgeKind = [];
    /** @var array<string, Node> */
    private array $edgeAt = [];
    /** @var array<string, Node> */
    private array $edgePred = [];
    /** @var array<string, array<string, int>> */
    private array $edgeOut = [];
    /** @var array<string, array<string, int>> */
    private array $edgeJoined = [];

    /** `$record`: keep {@see stateBefore} — a copy of the state per node, which
     *  only a dump reads; the lattice client gets everything through its callbacks. */
    public function __construct(private Lattice $lattice, private bool $record = false) {}

    public function run(Block $body): void
    {
        $this->before = [];
        $this->frBreak = [];
        $this->frHasBreak = [];
        $this->frCont = [];
        $this->frHasCont = [];
        $this->frSwitch = [];
        $this->frBreakKeys = [];
        $this->frContKeys = [];
        $this->thrAcc = [];
        $this->thrHas = [];
        $this->finDepth = [];
        $this->finLabels = [];
        $this->labelScan = [];
        $this->defKind = [];
        $this->defAt = [];
        $this->defTarget = [];
        $this->defLabel = [];
        $this->defState = [];
        $this->defOwner = [];
        $this->defNext = 0;
        $this->gotoPrev = [];
        $this->gotoKeys = [];
        $this->edgeKind = [];
        $this->edgeAt = [];
        $this->edgePred = [];
        $this->edgeOut = [];
        $this->edgeJoined = [];
        $entry = $this->lattice->entry();
        for ($pass = 0; ; $pass++) {
            if ($pass >= self::CAP) {
                throw new \LogicException('Forward: goto did not converge');
            }
            $this->gotoIn = [];
            $this->labelSeen = [];
            $this->sawGoto = false;
            $out = $this->block($body, $entry);
            if (!self::isDead($out)) {
                $this->edge('return', $body, $this->tailOf($body->stmts, null), 0, $out, $out);
            }
            if (!$this->sawGoto || $this->sameGotos()) { break; }
            $this->gotoPrev = $this->gotoIn;
        }
        foreach ($this->gotoIn as $name => $unused) {
            if (!isset($this->labelSeen[$name])) {
                throw new \LogicException('Forward: goto ' . $name . ' never reached its label');
            }
        }
        foreach ($this->edgeKind as $key => $kind) {
            if (!isset($this->edgeJoined[$key])) {
                throw new \LogicException('Forward: ' . $kind . ' edge never reached its target');
            }
            $pred = isset($this->edgePred[$key]) ? $this->edgePred[$key] : null;
            $this->lattice->onEdge($kind, $this->edgeAt[$key], $pred, $this->edgeOut[$key], $this->edgeJoined[$key]);
        }
    }

    /** @return array<int, array<string, int>> */
    public function stateBefore(): array
    {
        return $this->before;
    }

    private function sameGotos(): bool
    {
        if (\count($this->gotoIn) !== \count($this->gotoPrev)) { return false; }
        foreach ($this->gotoIn as $name => $st) {
            if (!isset($this->gotoPrev[$name])) { return false; }
            if (!$this->lattice->equal($st, $this->gotoPrev[$name])) { return false; }
        }
        return true;
    }

    // ── states ────────────────────────────────────────────────────

    /**
     * The unreachable state — never handed to the lattice.
     *
     * @return array<string, int>
     */
    private static function deadState(): array
    {
        return [self::DEAD => 1];
    }

    /** @param array<string, int> $s */
    private static function isDead(array $s): bool
    {
        return isset($s[self::DEAD]);
    }

    /**
     * @param array<string, int> $a
     * @param array<string, int> $b
     * @return array<string, int>
     */
    private function joinOpt(array $a, array $b): array
    {
        if (self::isDead($a)) { return $b; }
        if (self::isDead($b)) { return $a; }
        return $this->lattice->join($a, $b);
    }

    /** @param array<string, int> $s */
    private function note(Node $n, array $s): void
    {
        if ($this->record) { $this->before[\spl_object_id($n)] = $s; }
        $this->mayThrow($s);
    }

    /** @param array<string, int> $s */
    private function mayThrow(array $s): void
    {
        $n = \count($this->thrAcc);
        for ($i = 0; $i < $n; $i++) {
            $this->thrAcc[$i] = $this->thrHas[$i] ? $this->lattice->join($this->thrAcc[$i], $s) : $s;
            $this->thrHas[$i] = true;
        }
    }

    /**
     * `$joined` dead: the target resolves it later.
     *
     * @param array<string, int> $out
     * @param array<string, int> $joined
     */
    private function edge(string $kind, Node $at, ?Node $pred, int $idx, array $out, array $joined): string
    {
        $key = $kind . '#' . (string)\spl_object_id($at) . '#' . (string)$idx;
        $this->edgeKind[$key] = $kind;
        $this->edgeAt[$key] = $at;
        if ($pred !== null) {
            $this->edgePred[$key] = $pred;
        } else {
            unset($this->edgePred[$key]);
        }
        $this->edgeOut[$key] = $out;
        if (!self::isDead($joined)) { $this->edgeJoined[$key] = $joined; }
        return $key;
    }

    /**
     * @param array<int, string> $keys
     * @param array<string, int> $joined
     */
    private function resolve(array $keys, array $joined): void
    {
        foreach ($keys as $k) { $this->edgeJoined[$k] = $joined; }
    }

    /** @param Node[] $stmts */
    private function tailOf(array $stmts, ?Node $empty): ?Node
    {
        $n = \count($stmts);
        return $n === 0 ? $empty : $stmts[$n - 1];
    }

    // ── statements ────────────────────────────────────────────────

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function block(Block $b, array $s): array
    {
        if (!self::isDead($s)) { $this->note($b, $s); }
        return $this->seq($b->stmts, $s, $b);
    }

    /**
     * `$owner` stands as the predecessor of a label that opens the list.
     *
     * @param Node[] $stmts
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function seq(array $stmts, array $s, Node $owner): array
    {
        $prev = $owner;
        foreach ($stmts as $st) {
            $s = $this->stmt($st, $s, $prev);
            $prev = $st;
        }
        return $s;
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function stmt(Node $n, array $s, Node $prev): array
    {
        if ($n instanceof Label_) { return $this->label($n, $s, $prev); }
        if (self::isDead($s)) { return $this->dead($n); }
        if ($n instanceof Block) { return $this->block($n, $s); }
        if ($n instanceof If_) {
            $this->note($n, $s);
            return $this->ifStmt($n, $s);
        }
        if ($n instanceof While_) {
            $this->note($n, $s);
            return $this->whileLoop($n, $s);
        }
        if ($n instanceof For_) {
            $this->note($n, $s);
            return $this->forLoop($n, $s);
        }
        if ($n instanceof DoWhile_) {
            $this->note($n, $s);
            return $this->doWhileLoop($n, $s);
        }
        if ($n instanceof Foreach_) {
            $this->note($n, $s);
            return $this->foreachLoop($n, $s);
        }
        if ($n instanceof Switch_) {
            $this->note($n, $s);
            return $this->switchStmt($n, $s);
        }
        if ($n instanceof TryCatch_) {
            $this->note($n, $s);
            return $this->tryCatch($n, $s);
        }
        if ($n instanceof Break_) {
            $this->note($n, $s);
            $this->jump('break', $n, $n, $this->frameAt($n->level, 'break'), '', $s);
            return self::deadState();
        }
        if ($n instanceof Continue_) {
            $this->note($n, $s);
            $this->jump('continue', $n, $n, $this->frameAt($n->level, 'continue'), '', $s);
            return self::deadState();
        }
        if ($n instanceof Goto_) {
            $this->note($n, $s);
            $this->sawGoto = true;
            $this->jump('goto', $n, $n, -1, $n->label, $s);
            return self::deadState();
        }
        if ($n instanceof Return_) {
            $out = $this->expr($n, $s);
            if (!self::isDead($out)) { $this->jump('return', $n, $n, -1, '', $out); }
            return self::deadState();
        }
        return $this->expr($n, $s);
    }

    /**
     * An unreachable statement: only a label inside it can revive the path.
     *
     * @return array<string, int>
     */
    private function dead(Node $n): array
    {
        if ($n instanceof Block) { return $this->seq($n->stmts, self::deadState(), $n); }
        if ($n instanceof If_) {
            $t = $this->seq($n->then->stmts, self::deadState(), $n->then);
            $e = $n->else === null ? self::deadState() : $this->seq($n->else->stmts, self::deadState(), $n->else);
            return $this->joinOpt($t, $e);
        }
        if ($n instanceof TryCatch_) {
            $this->labelScan = [];
            $this->labelsIn($n);
            if (\count($this->labelScan) === 0) { return self::deadState(); }
            return $this->tryCatch($n, self::deadState());
        }
        return self::deadState();
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function label(Label_ $n, array $s, Node $prev): array
    {
        $name = $n->name;
        $in = $s;
        if (isset($this->gotoPrev[$name])) { $in = $this->joinOpt($in, $this->gotoPrev[$name]); }
        if (isset($this->gotoIn[$name])) { $in = $this->joinOpt($in, $this->gotoIn[$name]); }
        if (self::isDead($in)) { return $in; }
        $this->labelSeen[$name] = true;
        if (!self::isDead($s)) { $this->edge('fallthrough', $n, $prev, 0, $s, $in); }
        if (isset($this->gotoKeys[$name])) {
            foreach ($this->gotoKeys[$name] as $k => $unused) { $this->edgeJoined[$k] = $in; }
        }
        $this->note($n, $in);
        return $in;
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function ifStmt(If_ $n, array $s): array
    {
        $c = $this->expr($n->cond, $s);
        if (self::isDead($c)) { return $c; }
        $t = $this->block($n->then, $c);
        $e = $n->else === null ? $c : $this->block($n->else, $c);
        $out = $this->joinOpt($t, $e);
        if (!self::isDead($t)) {
            $this->edge('if-then', $n, $this->tailOf($n->then->stmts, $n->then), 0, $t, $out);
        }
        if (!self::isDead($e)) {
            $ePred = $n->else === null ? $n->cond : $this->tailOf($n->else->stmts, $n->else);
            $this->edge('if-else', $n, $ePred, 0, $e, $out);
        }
        return $out;
    }

    // ── loops ─────────────────────────────────────────────────────

    private function pushFrame(bool $isSwitch): int
    {
        $i = \count($this->frSwitch);
        $this->frBreak[$i] = [];
        $this->frHasBreak[$i] = false;
        $this->frCont[$i] = [];
        $this->frHasCont[$i] = false;
        $this->frSwitch[$i] = $isSwitch;
        $this->frBreakKeys[$i] = [];
        $this->frContKeys[$i] = [];
        return $i;
    }

    private function popFrame(): void
    {
        \array_pop($this->frBreak);
        \array_pop($this->frHasBreak);
        \array_pop($this->frCont);
        \array_pop($this->frHasCont);
        \array_pop($this->frSwitch);
        \array_pop($this->frBreakKeys);
        \array_pop($this->frContKeys);
    }

    /** @return array<string, int> */
    private function breaks(int $f): array
    {
        return $this->frHasBreak[$f] ? $this->frBreak[$f] : self::deadState();
    }

    /** @return array<string, int> */
    private function continues(int $f): array
    {
        return $this->frHasCont[$f] ? $this->frCont[$f] : self::deadState();
    }

    /** Frame a `break N` / `continue N` targets. */
    private function frameAt(int $level, string $kind): int
    {
        $n = \count($this->frSwitch);
        if ($level < 1 || $level > $n) {
            throw new \LogicException('Forward: ' . $kind . ' ' . (string)$level
                . ' with ' . (string)$n . ' enclosing loop(s)');
        }
        return $n - $level;
    }

    private function tooMany(int $i): void
    {
        if ($i >= self::CAP) {
            throw new \LogicException('Forward: loop did not converge');
        }
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function whileLoop(While_ $n, array $s): array
    {
        $head = $s;
        $f = -1;
        $c = self::deadState();
        $b = self::deadState();
        for ($i = 0; ; $i++) {
            $this->tooMany($i);
            $f = $this->pushFrame(false);
            $c = $this->expr($n->cond, $head);
            $b = self::isDead($c) ? $c : $this->block($n->body, $c);
            $back = $this->joinOpt($b, $this->continues($f));
            $next = self::isDead($back) ? $s : $this->lattice->join($s, $back);
            if ($this->lattice->equal($next, $head)) { break; }
            $this->popFrame();
            $head = $next;
        }
        $exit = $this->joinOpt($c, $this->breaks($f));
        $this->edge('loop-entry', $n, $n, 0, $s, $head);
        $this->closeLoop($n, $f, $head, $head,
            $this->tailOf($n->body->stmts, $n->body), $b, $n->cond, $c, $exit);
        return $exit;
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function forLoop(For_ $n, array $s): array
    {
        $s0 = $n->init === null ? $s : $this->expr($n->init, $s);
        if (self::isDead($s0)) { return $s0; }
        $head = $s0;
        $f = -1;
        $c = self::deadState();
        $b = self::deadState();
        $stepIn = self::deadState();
        $back = self::deadState();
        for ($i = 0; ; $i++) {
            $this->tooMany($i);
            $f = $this->pushFrame(false);
            $c = $n->cond === null ? $head : $this->expr($n->cond, $head);
            $b = self::isDead($c) ? $c : $this->block($n->body, $c);
            $stepIn = $this->joinOpt($b, $this->continues($f));
            $back = self::isDead($stepIn) || $n->step === null ? $stepIn : $this->expr($n->step, $stepIn);
            $next = self::isDead($back) ? $s0 : $this->lattice->join($s0, $back);
            if ($this->lattice->equal($next, $head)) { break; }
            $this->popFrame();
            $head = $next;
        }
        $condExit = $n->cond === null ? self::deadState() : $c;
        $exit = $this->joinOpt($condExit, $this->breaks($f));
        $this->edge('loop-entry', $n, $n, 0, $s0, $head);
        $step = $n->step;
        if ($step !== null) {
            if (!self::isDead($b) && !self::isDead($stepIn)) {
                $this->edge('loop-body', $n, $this->tailOf($n->body->stmts, $n->body), 0, $b, $stepIn);
            }
            $this->closeLoop($n, $f, $head, $stepIn, $step, $back, $n->cond, $condExit, $exit);
        } else {
            $this->closeLoop($n, $f, $head, $stepIn,
                $this->tailOf($n->body->stmts, $n->body), $b, $n->cond, $condExit, $exit);
        }
        return $exit;
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function doWhileLoop(DoWhile_ $n, array $s): array
    {
        $head = $s;
        $f = -1;
        $b = self::deadState();
        $condIn = self::deadState();
        $c = self::deadState();
        for ($i = 0; ; $i++) {
            $this->tooMany($i);
            $f = $this->pushFrame(false);
            $b = $this->block($n->body, $head);
            $condIn = $this->joinOpt($b, $this->continues($f));
            $c = self::isDead($condIn) ? $condIn : $this->expr($n->cond, $condIn);
            $next = self::isDead($c) ? $s : $this->lattice->join($s, $c);
            if ($this->lattice->equal($next, $head)) { break; }
            $this->popFrame();
            $head = $next;
        }
        $exit = $this->joinOpt($c, $this->breaks($f));
        $this->edge('loop-entry', $n, $n, 0, $s, $head);
        if (!self::isDead($b) && !self::isDead($condIn)) {
            $this->edge('loop-body', $n, $this->tailOf($n->body->stmts, $n->body), 0, $b, $condIn);
        }
        $this->closeLoop($n, $f, $head, $condIn, $n->cond, $c, $n->cond, $c, $exit);
        return $exit;
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function foreachLoop(Foreach_ $n, array $s): array
    {
        $arr = $this->expr($n->array, $s);
        if (self::isDead($arr)) { return $arr; }
        $head = $arr;
        $f = -1;
        $b = self::deadState();
        for ($i = 0; ; $i++) {
            $this->tooMany($i);
            $f = $this->pushFrame(false);
            $this->mayThrow($head);
            $bound = $this->lattice->transfer($n, $head);
            $b = $this->block($n->body, $bound);
            $back = $this->joinOpt($b, $this->continues($f));
            $next = self::isDead($back) ? $arr : $this->lattice->join($arr, $back);
            if ($this->lattice->equal($next, $head)) { break; }
            $this->popFrame();
            $head = $next;
        }
        $exit = $this->joinOpt($head, $this->breaks($f));
        $this->edge('loop-entry', $n, $n, 0, $arr, $head);
        $this->closeLoop($n, $f, $head, $head,
            $this->tailOf($n->body->stmts, $n->body), $b, $n->array, $head, $exit);
        return $exit;
    }

    /**
     * Edges of a converged loop, then its frame is dropped. `$contTo` is where a
     * `continue` lands: the head, the for step or the do-while condition.
     *
     * @param array<string, int> $head
     * @param array<string, int> $contTo
     * @param array<string, int> $back
     * @param array<string, int> $condExit
     * @param array<string, int> $exit
     */
    private function closeLoop(Node $n, int $f, array $head, array $contTo,
        ?Node $backPred, array $back, ?Node $exitPred, array $condExit, array $exit): void
    {
        if (!self::isDead($contTo)) { $this->resolve($this->frContKeys[$f], $contTo); }
        if (!self::isDead($exit)) { $this->resolve($this->frBreakKeys[$f], $exit); }
        if (!self::isDead($back)) { $this->edge('loop-back', $n, $backPred, 0, $back, $head); }
        if (!self::isDead($condExit) && !self::isDead($exit)) {
            $this->edge('loop-exit', $n, $exitPred, 0, $condExit, $exit);
        }
        $this->popFrame();
    }

    // ── switch ────────────────────────────────────────────────────

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function switchStmt(Switch_ $n, array $s): array
    {
        $d = $this->expr($n->subject, $s);
        if (self::isDead($d)) { return $d; }
        /** @var array<int, array<string, int>> $dispatch */
        $dispatch = [];
        /** @var array<int, Node> $dispPred */
        $dispPred = [];
        $lastVal = $n->subject;
        $default = -1;
        foreach ($n->arms as $i => $arm) {
            $v = $arm->value;
            if ($v === null) { $default = $i; continue; }
            if (!self::isDead($d)) { $d = $this->expr($v, $d); }
            if (!self::isDead($d)) {
                $dispatch[$i] = $d;
                $dispPred[$i] = $v;
                $lastVal = $v;
            }
        }
        if ($default >= 0 && !self::isDead($d)) {
            $dispatch[$default] = $d;
            $dispPred[$default] = $lastVal;
        }
        $f = $this->pushFrame(true);
        $prev = self::deadState();
        $prevPred = $n->subject;
        $count = \count($n->arms);
        foreach ($n->arms as $i => $arm) {
            $in = $prev;
            if (isset($dispatch[$i])) { $in = $this->joinOpt($in, $dispatch[$i]); }
            if (!self::isDead($in)) {
                if (isset($dispatch[$i])) {
                    $this->edge('switch-arm', $n, $dispPred[$i], $i, $dispatch[$i], $in);
                }
                if (!self::isDead($prev)) { $this->edge('fallthrough', $n, $prevPred, $i, $prev, $in); }
            }
            $prev = $this->seq($arm->body, $in, $n);
            $prevPred = $this->tailOf($arm->body, $prevPred);
        }
        $noMatch = $default < 0 ? $d : self::deadState();
        $exit = $this->joinOpt($this->joinOpt($prev, $noMatch), $this->joinOpt($this->breaks($f), $this->continues($f)));
        if (!self::isDead($exit)) {
            if (!self::isDead($prev)) { $this->edge('fallthrough', $n, $prevPred, $count, $prev, $exit); }
            if (!self::isDead($noMatch)) { $this->edge('switch-arm', $n, $lastVal, $count, $noMatch, $exit); }
            $this->resolve($this->frBreakKeys[$f], $exit);
            $this->resolve($this->frContKeys[$f], $exit);
        }
        $this->popFrame();
        return $exit;
    }

    // ── try / catch / finally ─────────────────────────────────────

    /**
     * `$s` dead: the try is dead code entered only through a label inside it.
     *
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function tryCatch(TryCatch_ $n, array $s): array
    {
        $fin = $n->hasFinally;
        $ff = -1;
        if ($fin) {
            $ff = \count($this->finDepth);
            $this->finDepth[$ff] = \count($this->frSwitch);
            $this->labelScan = [];
            $this->labelsIn($n);
            $this->finLabels[$ff] = $this->labelScan;
        }
        $live = !self::isDead($s);
        $this->thrAcc[] = $live ? $s : [];
        $this->thrHas[] = $live;
        $tExit = $this->seq($n->tryBody, $s, $n);
        $catchIn = \array_pop($this->thrAcc);
        $catchHas = \array_pop($this->thrHas);
        if ($fin) {
            $this->thrAcc[] = $catchIn;
            $this->thrHas[] = $catchHas;
        }
        $norm = $tExit;
        /** @var array<int, string> $fKeys */
        $fKeys = [];
        if ($fin && !self::isDead($tExit)) {
            $fKeys[] = $this->edge('finally', $n, $this->tailOf($n->tryBody, $n), 0, $tExit, self::deadState());
        }
        foreach ($n->catches as $i => $c) {
            $cin = self::deadState();
            if ($catchHas) {
                $head = \count($c->body) === 0 ? $n : $c->body[0];
                $this->edge('catch', $n, $head, $i, $catchIn, $catchIn);
                $cin = $this->lattice->catchEntry($c, $catchIn);
            }
            $ce = $this->seq($c->body, $cin, $n);
            $norm = $this->joinOpt($norm, $ce);
            if ($fin && !self::isDead($ce)) {
                $fKeys[] = $this->edge('finally', $n, $this->tailOf($c->body, $n), $i + 1, $ce, self::deadState());
            }
        }
        if (!$fin) { return $norm; }

        $thrown = \array_pop($this->thrAcc);
        $thrownHas = \array_pop($this->thrHas);
        \array_pop($this->finDepth);
        \array_pop($this->finLabels);
        $finIn = $norm;
        if ($thrownHas) {
            $fKeys[] = $this->edge('finally', $n, null, -1, $thrown, self::deadState());
            $finIn = $this->joinOpt($finIn, $thrown);
        }
        /** @var array<int, int> $mine */
        $mine = [];
        foreach ($this->defOwner as $d => $owner) {
            if ($owner !== $ff) { continue; }
            $mine[] = $d;
            $at = $this->defAt[$d];
            $finIn = $this->joinOpt($finIn, $this->defState[$d]);
            $fKeys[] = $this->edge('finally', $n, $at, -2 - \spl_object_id($at), $this->defState[$d], self::deadState());
        }
        if (!self::isDead($finIn)) { $this->resolve($fKeys, $finIn); }
        $fOut = $this->seq($n->finallyBody, $finIn, $n);
        $fTail = $this->tailOf($n->finallyBody, $n);
        foreach ($mine as $d) {
            $kind = $this->defKind[$d];
            $at = $this->defAt[$d];
            $target = $this->defTarget[$d];
            $label = $this->defLabel[$d];
            unset($this->defKind[$d], $this->defAt[$d], $this->defTarget[$d],
                $this->defLabel[$d], $this->defState[$d], $this->defOwner[$d]);
            if (!self::isDead($fOut)) { $this->jump($kind, $at, $fTail, $target, $label, $fOut); }
        }
        if (self::isDead($fOut)) { return $fOut; }
        $this->mayThrow($fOut);
        return self::isDead($norm) ? $norm : $fOut;
    }

    private function labelsIn(Node $n): void
    {
        if ($n instanceof Label_) { $this->labelScan[$n->name] = true; }
        foreach (Walk::children($n) as $c) { $this->labelsIn($c); }
    }

    // ── jumps ─────────────────────────────────────────────────────

    /**
     * Route a jump to its target, through every `finally` it leaves on the way.
     *
     * @param array<string, int> $s
     */
    private function jump(string $kind, Node $at, ?Node $pred, int $target, string $label, array $s): void
    {
        if ($kind === 'throw') {
            $this->edge('throw', $at, $pred, 0, $s, $s);
            return;
        }
        $f = \count($this->finDepth) - 1;
        if ($f >= 0) {
            $leaves = $kind === 'return'
                || ($kind === 'goto' && !isset($this->finLabels[$f][$label]))
                || ($kind !== 'goto' && $target < $this->finDepth[$f]);
            if ($leaves) {
                $d = $this->defNext;
                $this->defNext = $d + 1;
                $this->defKind[$d] = $kind;
                $this->defAt[$d] = $at;
                $this->defTarget[$d] = $target;
                $this->defLabel[$d] = $label;
                $this->defState[$d] = $s;
                $this->defOwner[$d] = $f;
                return;
            }
        }
        if ($kind === 'return') {
            $this->edge('return', $at, $pred, 0, $s, $s);
            return;
        }
        if ($kind === 'goto') {
            $key = $this->edge('goto', $at, $pred, 0, $s, self::deadState());
            $this->gotoIn[$label] = isset($this->gotoIn[$label])
                ? $this->lattice->join($this->gotoIn[$label], $s) : $s;
            if (!isset($this->gotoKeys[$label])) { $this->gotoKeys[$label] = []; }
            $this->gotoKeys[$label][$key] = true;
            return;
        }
        $key = $this->edge($kind, $at, $pred, 0, $s, self::deadState());
        if ($kind === 'continue' && !$this->frSwitch[$target]) {
            $this->frCont[$target] = $this->frHasCont[$target]
                ? $this->lattice->join($this->frCont[$target], $s) : $s;
            $this->frHasCont[$target] = true;
            $this->frContKeys[$target][] = $key;
            return;
        }
        $this->frBreak[$target] = $this->frHasBreak[$target]
            ? $this->lattice->join($this->frBreak[$target], $s) : $s;
        $this->frHasBreak[$target] = true;
        $this->frBreakKeys[$target][] = $key;
    }

    // ── expressions ───────────────────────────────────────────────

    /**
     * Evaluate `$n` in php's order; dead once a `throw` ends the path.
     *
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function expr(Node $n, array $s): array
    {
        $this->note($n, $s);
        if ($n instanceof Ternary) { return $this->ternary($n, $s); }
        if ($n instanceof NullCoalesce_) { return $this->coalesce($n, $s); }
        if ($n instanceof Match_) { return $this->matchExpr($n, $s); }
        foreach (Walk::children($n) as $c) {
            $r = $this->expr($c, $s);
            if (self::isDead($r)) { return $r; }
            $s = $r;
        }
        $this->mayThrow($s);
        $out = $this->lattice->transfer($n, $s);
        if ($n instanceof Throw_) {
            $this->jump('throw', $n, $n, -1, '', $out);
            return self::deadState();
        }
        return $out;
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function ternary(Ternary $n, array $s): array
    {
        $c = $this->expr($n->cond, $s);
        if (self::isDead($c)) { return $c; }
        $then = $n->then;
        $t = $then === null ? $c : $this->expr($then, $c);
        $e = $this->expr($n->else_, $c);
        return $this->branchJoin($n, $then === null ? $n->cond : $then, $t, $n->else_, $e);
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function coalesce(NullCoalesce_ $n, array $s): array
    {
        $l = $this->expr($n->left, $s);
        if (self::isDead($l)) { return $l; }
        $r = $this->expr($n->right, $l);
        return $this->branchJoin($n, $n->left, $l, $n->right, $r);
    }

    /**
     * @param array<string, int> $t
     * @param array<string, int> $e
     * @return array<string, int>
     */
    private function branchJoin(Node $n, Node $tPred, array $t, Node $ePred, array $e): array
    {
        $out = $this->joinOpt($t, $e);
        if (self::isDead($out)) { return $out; }
        if (!self::isDead($t)) { $this->edge('if-then', $n, $tPred, 0, $t, $out); }
        if (!self::isDead($e)) { $this->edge('if-else', $n, $ePred, 0, $e, $out); }
        return $this->lattice->transfer($n, $out);
    }

    /**
     * @param array<string, int> $s
     * @return array<string, int>
     */
    private function matchExpr(Match_ $n, array $s): array
    {
        $d = $this->expr($n->subject, $s);
        if (self::isDead($d)) { return $d; }
        /** @var array<int, array<string, int>> $armIn */
        $armIn = [];
        $default = -1;
        foreach ($n->arms as $i => $arm) {
            $conds = $arm->conds;
            if ($conds === null) { $default = $i; continue; }
            foreach ($conds as $c) {
                if (self::isDead($d)) { break; }
                $d = $this->expr($c, $d);
                if (!self::isDead($d)) { $armIn[$i] = isset($armIn[$i]) ? $this->lattice->join($armIn[$i], $d) : $d; }
            }
        }
        if (!self::isDead($d)) {
            if ($default >= 0) {
                $armIn[$default] = $d;
            } else {
                $this->mayThrow($d);
            }
        }
        /** @var array<int, array<string, int>> $armOut */
        $armOut = [];
        $out = self::deadState();
        foreach ($n->arms as $i => $arm) {
            if (!isset($armIn[$i])) { continue; }
            $r = $this->expr($arm->body, $armIn[$i]);
            if (self::isDead($r)) { continue; }
            $armOut[$i] = $r;
            $out = $this->joinOpt($out, $r);
        }
        if (self::isDead($out)) { return $out; }
        foreach ($n->arms as $i => $arm) {
            if (isset($armOut[$i])) { $this->edge('match-arm', $arm->body, $arm->body, 0, $armOut[$i], $out); }
        }
        return $this->lattice->transfer($n, $out);
    }
}
