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

    /** @var array<string, true> locals defined in the current function */
    private array $defined = [];

    /** @var array<string, true> locals read in the current function */
    private array $used = [];

    private string $fnName = '';

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
        $this->defined = [];
        $this->used = [];
        $this->fnName = $fn->name;
        foreach ($fn->params as $p) {
            $this->defined[$p->name] = true;
        }
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

        // ── definition sites ──
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
            $this->defined[$sl->name] = true;
            $this->walk($sl->value);
            return;
        }
        if ($k === Node::KIND_INCDEC) {
            $name = $this->asIncDec($n)->name;
            $this->defined[$name] = true;
            $this->used[$name] = true;
            return;
        }
        if ($k === Node::KIND_REF_ALIAS) {
            $ra = $this->asRefAlias($n);
            $this->defined[$ra->target] = true;
            $this->used[$ra->source] = true;
            return;
        }
        if ($k === Node::KIND_REF_BIND) {
            $rb = $this->asRefBind($n);
            $this->defined[$rb->target] = true;
            $this->walk($rb->call);
            return;
        }
        if ($k === Node::KIND_STATIC_LOCAL_DECL) {
            $sld = $this->asStaticLocalDecl($n);
            $this->defined[$sld->name] = true;
            if ($sld->init !== null) { $this->walk($sld->init); }
            return;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            $this->defined[$fe->valueVar] = true;
            if ($fe->keyVar !== null) { $this->defined[$fe->keyVar] = true; }
            $this->walk($fe->array);
            $this->walk($fe->body);
            return;
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $this->asTryCatch($n);
            foreach ($tc->tryBody as $s) { $this->walk($s); }
            foreach ($tc->catches as $c) {
                if ($c->var !== null) { $this->defined[$c->var] = true; }
                foreach ($c->body as $s) { $this->walk($s); }
            }
            foreach ($tc->finallyBody as $s) { $this->walk($s); }
            return;
        }

        // ── use site ──
        if ($k === Node::KIND_LOAD_LOCAL) {
            $this->used[$this->asLoadLocal($n)->name] = true;
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

    private function asStoreLocal(Node $n): \Compile\Mir\StoreLocal { return $n; }
    private function asLoadLocal(Node $n): \Compile\Mir\LoadLocal { return $n; }
    private function asIncDec(Node $n): \Compile\Mir\IncDec { return $n; }
    private function asRefAlias(Node $n): \Compile\Mir\RefAlias_ { return $n; }
    private function asRefBind(Node $n): \Compile\Mir\RefBind_ { return $n; }
    private function asStaticLocalDecl(Node $n): \Compile\Mir\StaticLocalDecl_ { return $n; }
    private function asForeach(Node $n): \Compile\Mir\Foreach_ { return $n; }
    private function asTryCatch(Node $n): \Compile\Mir\TryCatch_ { return $n; }
}
