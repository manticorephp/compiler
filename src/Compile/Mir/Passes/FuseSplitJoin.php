<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\FunctionDef;
use Compile\Mir\LoadLocal;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Pass;
use Compile\Mir\StoreLocal;
use Compile\Mir\Walk;

/**
 * Fuse the split-join round-trip `implode($sep, explode($delim, $subj))` into a
 * single native `__mir_str_replace_one($delim, $sep, $subj)` — one pass, zero
 * intermediate array / segment allocations (explode is entirely alloc-bound: a
 * per-segment string malloc + the result vec + freeing it all). Semantically
 * exact: joining every split piece with `$sep` is replacing every `$delim` with
 * `$sep` (str_replace replaces all, non-overlapping, left-to-right).
 *
 * Two shapes, both require `$delim` a NON-EMPTY string literal (empty `explode`
 * is a PHP error, not a str_replace no-op) and `explode` with exactly 2 args (a
 * `$limit` keeps a partial tail — not a full replace):
 *  - direct:   `implode($sep, explode($lit, $subj))` — `$delim` literal has no
 *    side effect, so swapping the `$sep`/`$delim` eval order is unobservable.
 *  - via-temp: `$x = explode($lit, $subj);` immediately followed by a statement
 *    using `implode($sep, $x)`, where `$x` occurs EXACTLY twice in the whole
 *    function (its def + that one read) and `$subj` is side-effect-free (so
 *    moving its evaluation to the join site is safe). The explode store is
 *    removed; `$subj`/`$delim` nodes are reused in the fused call.
 *
 * Runs after InferTypes/Monomorphize (needs settled types + the explode arg
 * count) and before InferEffects, so the analysis sees the fused form.
 */
final class FuseSplitJoin implements Pass
{
    public const NAME = 'fuse-split-join';

    private ?Node $fnBody = null;

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $this->fnBody = $fn->body;
            $this->visit($fn->body);
        }
        $this->fnBody = null;
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function visit(Node $n): void
    {
        if ($n->kind === Node::KIND_BLOCK) {
            $this->fuseBlockTemps($this->asBlock($n));
        }
        // Direct-nested: implode($sep, explode($lit, $subj)).
        if ($this->isImplode2($n)) {
            $call = $this->asCall($n);
            $arg1 = $call->args[1];
            if ($this->isFusableExplode($arg1)) {
                $ex = $this->asCall($arg1);
                $this->rewriteToReplace($call, $ex->args[0], $call->args[0], $ex->args[1]);
            }
        }
        foreach (Walk::children($n) as $c) { $this->visit($c); }
    }

    /** Fuse the `$x = explode(...); … implode($sep,$x) …` adjacent-statement shape. */
    private function fuseBlockTemps(Block $b): void
    {
        $drop = [];
        $stmts = $b->stmts;
        $n = \count($stmts);
        for ($i = 0; $i < $n - 1; $i = $i + 1) {
            $s = $stmts[$i];
            if ($s->kind !== Node::KIND_STORE_LOCAL) { continue; }
            $sl = $this->asStoreLocal($s);
            if (!$this->isFusableExplode($sl->value)) { continue; }
            $x = $sl->name;
            $ex = $this->asCall($sl->value);
            $subj = $ex->args[1];
            if (!$this->sideEffectFree($subj)) { continue; }
            // The next statement must use implode($sep, $x); `$x` occurs exactly
            // twice in the whole function (this def + that read) → no other use.
            $imp = $this->findImplodeOfLocal($stmts[$i + 1], $x);
            if ($imp === null) { continue; }
            if ($this->fnBody === null) { continue; }
            if ($this->countNameOccurrences($x, $this->fnBody) !== 2) { continue; }
            $this->rewriteToReplace($imp, $ex->args[0], $this->asCall($imp)->args[0], $subj);
            $drop[$i] = true;
        }
        if (\count($drop) === 0) { return; }
        $kept = [];
        for ($i = 0; $i < $n; $i = $i + 1) {
            if (isset($drop[$i])) { continue; }
            $kept[] = $stmts[$i];
        }
        $b->stmts = $kept;
    }

    /** Turn `$call` (an implode) into `__mir_str_replace_one($delim,$sep,$subj)` in place. */
    private function rewriteToReplace(Node $call, Node $delim, Node $sep, Node $subj): void
    {
        $c = $this->asCall($call);
        $c->function = '__mir_str_replace_one';
        $c->args = [$delim, $sep, $subj];
    }

    /** implode/join with exactly 2 args (sep, array). */
    private function isImplode2(Node $n): bool
    {
        if ($n->kind !== Node::KIND_CALL) { return false; }
        $c = $this->asCall($n);
        return ($c->function === 'implode' || $c->function === 'join')
            && \count($c->args) === 2;
    }

    /**
     * A no-limit explode with a non-empty string-literal delimiter. LowerFromAst
     * materializes the default limit as an explicit 3rd arg = PHP_INT_MAX, so
     * accept 2 args OR 3 args whose limit is that sentinel (a real limit keeps a
     * partial tail — not a full replace — so it must NOT fuse).
     */
    private function isFusableExplode(Node $n): bool
    {
        if ($n->kind !== Node::KIND_CALL) { return false; }
        $c = $this->asCall($n);
        if ($c->function !== 'explode') { return false; }
        $argc = \count($c->args);
        if ($argc === 3) {
            $lim = $c->args[2];
            if ($lim->kind !== Node::KIND_INT_CONST
                || $this->asIntConst($lim)->value !== \PHP_INT_MAX) {
                return false;
            }
        } elseif ($argc !== 2) {
            return false;
        }
        $d = $c->args[0];
        return $d->kind === Node::KIND_STRING_CONST && $this->asStringConst($d)->value !== '';
    }

    /** Find an `implode($sep, LoadLocal($x))` node within `$n`, else null. */
    private function findImplodeOfLocal(Node $n, string $x): ?Node
    {
        if ($this->isImplode2($n)) {
            $a1 = $this->asCall($n)->args[1];
            if ($a1->kind === Node::KIND_LOAD_LOCAL && $this->asLoadLocal($a1)->name === $x) {
                return $n;
            }
        }
        foreach (Walk::children($n) as $c) {
            $r = $this->findImplodeOfLocal($c, $x);
            if ($r !== null) { return $r; }
        }
        return null;
    }

    /** Count every occurrence of local `$name` (reads, def, and aliasing uses). */
    private function countNameOccurrences(string $name, Node $n): int
    {
        $c = 0;
        $k = $n->kind;
        if ($k === Node::KIND_LOAD_LOCAL) {
            if ($this->asLoadLocal($n)->name === $name) { $c = $c + 1; }
        } elseif ($k === Node::KIND_STORE_LOCAL) {
            if ($this->asStoreLocal($n)->name === $name) { $c = $c + 1; }
        } elseif ($k === Node::KIND_INCDEC) {
            if ($this->asIncDec($n)->name === $name) { $c = $c + 1; }
        } elseif ($k === Node::KIND_REF_ALIAS) {
            $ra = $this->asRefAlias($n);
            if ($ra->target === $name || $ra->source === $name) { $c = $c + 1; }
        } elseif ($k === Node::KIND_REF_BIND) {
            if ($this->asRefBind($n)->target === $name) { $c = $c + 1; }
        } elseif ($k === Node::KIND_STATIC_LOCAL_DECL) {
            if ($this->asStaticLocalDecl($n)->name === $name) { $c = $c + 1; }
        } elseif ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            if ($fe->valueVar === $name) { $c = $c + 1; }
            if ($fe->keyVar === $name) { $c = $c + 1; }
        }
        foreach (Walk::children($n) as $ch) {
            $c = $c + $this->countNameOccurrences($name, $ch);
        }
        return $c;
    }

    /** No side-effecting node in the subtree — safe to move its evaluation. */
    private function sideEffectFree(Node $n): bool
    {
        $k = $n->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_METHOD_CALL
            || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_CLONE
            || $k === Node::KIND_STORE_LOCAL || $k === Node::KIND_STORE_PROPERTY
            || $k === Node::KIND_STORE_ELEMENT || $k === Node::KIND_STORE_STATIC_PROP
            || $k === Node::KIND_STORE_DYN_PROP || $k === Node::KIND_INCDEC
            || $k === Node::KIND_YIELD || $k === Node::KIND_THROW) {
            return false;
        }
        foreach (Walk::children($n) as $c) {
            if (!$this->sideEffectFree($c)) { return false; }
        }
        return true;
    }

    private function asBlock(Node $n): Block { return $n; }
    private function asCall(Node $n): Call { return $n; }
    private function asStoreLocal(Node $n): StoreLocal { return $n; }
    private function asLoadLocal(Node $n): LoadLocal { return $n; }
    private function asStringConst(Node $n): \Compile\Mir\StringConst { return $n; }
    private function asIntConst(Node $n): \Compile\Mir\IntConst { return $n; }
    private function asIncDec(Node $n): \Compile\Mir\IncDec { return $n; }
    private function asRefAlias(Node $n): \Compile\Mir\RefAlias_ { return $n; }
    private function asRefBind(Node $n): \Compile\Mir\RefBind_ { return $n; }
    private function asStaticLocalDecl(Node $n): \Compile\Mir\StaticLocalDecl_ { return $n; }
    private function asForeach(Node $n): \Compile\Mir\Foreach_ { return $n; }
}
