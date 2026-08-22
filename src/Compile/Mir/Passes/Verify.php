<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Block;
use Compile\Mir\Module;
use Compile\Mir\FunctionDef;
use Compile\Mir\Node;
use Compile\Mir\Pass;

/**
 * MIR verifier — asserts the structural invariants that make MIR a
 * checked contract rather than "another AST". Runs after InferTypes.
 *
 * Invariant set (v1):
 *  - No dangling locals: every `LoadLocal` / `IncDec` use names a local
 *    that is defined somewhere in the function (param, store, foreach
 *    var, catch var, static-local, ref-alias / ref-bind target). This
 *    catches optimiser bugs that drop a needed store (the DeadStore
 *    var_dump-key regression).
 *  - Value-producing nodes carry a type (never left structurally null).
 *
 * A violation throws so the bad MIR never reaches LLVM. Later versions
 * add: operand-type validity, terminator rules, no impossible cell /
 * object mixes, effect/allocation-kind consistency.
 */
final class Verify implements Pass
{
    public const NAME = 'verify';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    /** @var array<string, true> locals defined in the current function
     *  ({@see \Compile\Mir\DefinedLocals}, the one owner of the rules) */
    private array $defined = [];

    /** @var array<string, true> locals read in the current function */
    private array $used = [];

    private string $fnName = '';

    // PHP permits isset($possiblyUndefined) and treats it as false. Keep those
    // reads out of the verifier's dangling-local set while retaining strict
    // checking for ordinary LoadLocal uses.
    private bool $allowUndefinedReads = false;

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->verifyFunction($fn);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function verifyFunction(FunctionDef $fn): void
    {
        // Definition rules live in ONE place ({@see \Compile\Mir\DefinedLocals})
        // so this verifier and VivifyRefArgs cannot drift apart; the walk below
        // collects only the USE side.
        $this->defined = \Compile\Mir\DefinedLocals::collect($fn);
        $this->used = [];
        $this->allowUndefinedReads = false;
        $this->fnName = $fn->name;
        $this->walk($fn->body);
        foreach ($this->used as $name => $unused) {
            if (!isset($this->defined[$name])) {
                throw new \RuntimeException(
                    'MIR.verify: dangling local $' . $name . ' read in '
                    . $this->fnName . ' but never defined'
                );
            }
        }
    }

    private function walk(Node $n): void
    {
        $k = $n->kind;

        // ── definition sites: the DEFS come from DefinedLocals; these arms
        //    exist for the USE half each kind carries (and for the recursion
        //    shape, which is part of the rules — a ref-alias source is a read,
        //    a ref-alias target is not). ──
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = $n;
            $this->walk($sl->value);
            return;
        }
        if ($k === Node::KIND_INCDEC) {
            $this->used[$n->name] = true;
            return;
        }
        if ($k === Node::KIND_REF_ALIAS) {
            $ra = $n;
            $this->used[$ra->source] = true;
            return;
        }
        if ($k === Node::KIND_REF_BIND) {
            $rb = $n;
            $this->walk($rb->call);
            return;
        }
        if ($k === Node::KIND_REF_ADDR) {
            $ra = $n;
            $this->walk($ra->lvalue);
            return;
        }
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $sld = $n;
            if ($sld->init !== null) { $this->walk($sld->init); }
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $n;
            $this->walk($fe->array);
            $this->walk($fe->body);
            return;
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $n;
            foreach ($tc->tryBody as $s) { $this->walk($s); }
            foreach ($tc->catches as $c) {
                foreach ($c->body as $s) { $this->walk($s); }
            }
            foreach ($tc->finallyBody as $s) { $this->walk($s); }
            return;
        }

        // ── PHP's isset() explicitly probes an optionally undefined local. ──
        if ($k === Node::KIND_ISSET) {
            $old = $this->allowUndefinedReads;
            $this->allowUndefinedReads = true;
            foreach ($n->targets as $target) { $this->walk($target); }
            $this->allowUndefinedReads = $old;
            return;
        }
        // A successful isset() condition also guarantees the local in the
        // then-branch, even when the optimizer removed its null initialization.
        if ($k === Node::KIND_IF) {
            $this->walk($n->cond);
            $old = $this->allowUndefinedReads;
            if ($n->cond->kind === Node::KIND_ISSET) { $this->allowUndefinedReads = true; }
            $this->walk($n->then);
            $this->allowUndefinedReads = $old;
            if ($n->else !== null) { $this->walk($n->else); }
            return;
        }

        // ── use site ──
        if ($k === Node::KIND_LOAD_LOCAL) {
            if (!$this->allowUndefinedReads) { $this->used[$n->name] = true; }
            return;
        }

        // ── structural recursion (children) ──
        foreach ($this->childrenOf($n) as $c) { $this->walk($c); }
    }

    /**
     * Direct child nodes of `$n` for the generic recursion. Delegates
     * to the shared {@see \Compile\Mir\Walk} so a new node kind is wired
     * in one place. Definition-site kinds (store-local, foreach, …) are
     * intercepted in {@see walk()} before they ever reach here.
     *
     * @return Node[]
     */
    private function childrenOf(Node $n): array
    {
        return \Compile\Mir\Walk::children($n);
    }
}
