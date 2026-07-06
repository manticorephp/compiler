<?php

namespace Compile\Mir\Passes;

use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\Cast;
use Compile\Mir\Closure_;
use Compile\Mir\DoWhile_;
use Compile\Mir\Echo_;
use Compile\Mir\For_;
use Compile\Mir\Foreach_;
use Compile\Mir\FunctionDef;
use Compile\Mir\If_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Invoke_;
use Compile\Mir\Isset_;
use Compile\Mir\LoadLocal;
use Compile\Mir\Match_;
use Compile\Mir\MethodCall_;
use Compile\Mir\Module;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\NodeClone;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\Switch_;
use Compile\Mir\Ternary;
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\Type;
use Compile\Mir\Unset_;
use Compile\Mir\Walk;
use Compile\Mir\While_;

/**
 * Closure de-boxing — two transforms that eliminate the uniform boxed-cell
 * closure call ABI where it is statically avoidable:
 *
 *  1. Inline a captureless single-expression arrow closure at a known
 *     direct-invoke site. `$f = fn($x) => $x * $x; ... $f($v)` becomes
 *     `$v * $v` spliced in place — no call, no box, no env, native ops.
 *
 *  2. Fuse array_map / array_filter / array_reduce over a CONCRETELY-typed
 *     array with a captureless single-expr closure into a dedicated loop
 *     function typed to that array — the closure body is inlined into the
 *     loop, so there is no per-element closure dispatch and no intermediate
 *     boxing. A non-concrete array (a chained fusion's outer call, a
 *     dynamically-typed source) is left as the prelude call, which is correct.
 *
 * Runs AFTER {@see InferTypes} (callee / array-arg types are known) and BEFORE
 * {@see Monomorphize}; the caller re-runs InferTypes so the spliced / fused
 * expressions type from their (now concrete) operands.
 *
 * Eligibility is deliberately narrow (the prelude / {@see emitInvoke} fallback
 * stays correct for everything skipped): captureless closure, body == a single
 * `return <expr>`, arity match, and (for invoke inlining) only side-effect-free
 * / cheap-to-duplicate args. Generators are excluded by the single-return gate.
 */
final class InlineClosures implements Pass
{
    public const NAME = 'inline-closures';

    /** @var array<string,FunctionDef> closure fn name → its FunctionDef */
    private array $closures = [];
    /** @var array<string,int> closure fn name → capture count */
    private array $captureCount = [];
    private ?Module $module = null;
    private int $fuseCounter = 0;

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        $this->module = $module;
        $this->captureCount = $module->closureCaptures;
        // Snapshot the function list: synthesized fused loop fns are appended
        // during the walk and must not be re-traversed (their bodies hold no
        // further closures / array fns to rewrite).
        $fns = [];
        foreach ($module->functions as $rawFn) {
            $fn = $this->fnDef($rawFn);
            $fns[] = $fn;
            if (isset($this->captureCount[$fn->name])) {
                $this->closures[$fn->name] = $fn;
            }
        }
        foreach ($fns as $rawFn) {
            $fn = $this->fnDef($rawFn);
            // Never rewrite inside a closure body itself — keep the original
            // available as an inline source and avoid recursive surprises.
            if (isset($this->captureCount[$fn->name])) { continue; }
            $fn->body = $this->rewriteBlock($fn->body);
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function rewriteBlock(Block $b): Block
    {
        $out = [];
        foreach ($b->stmts as $s) { $out[] = $this->rewrite($s); }
        $b->stmts = $out;
        return $b;
    }

    /** Recurse every node, rebuilding children; splice at an eligible invoke. */
    private function rewrite(Node $n): Node
    {
        $k = $n->kind;
        if ($k === Node::KIND_INVOKE) {
            $iv = $this->asInvoke($n);
            $iv->callee = $this->rewrite($iv->callee);
            $args = [];
            foreach ($iv->args as $a) { $args[] = $this->rewrite($a); }
            $iv->args = $args;
            $spliced = $this->tryInline($iv);
            return $spliced ?? $iv;
        }

        // ── Leaves (no sub-nodes that can hold an invoke) ──
        if ($k === Node::KIND_INT_CONST || $k === Node::KIND_FLOAT_CONST
            || $k === Node::KIND_STRING_CONST || $k === Node::KIND_BOOL_CONST
            || $k === Node::KIND_NULL_CONST || $k === Node::KIND_LOAD_LOCAL
            || $k === Node::KIND_STATIC_PROP || $k === Node::KIND_BREAK
            || $k === Node::KIND_CONTINUE || $k === Node::KIND_INCDEC
            || $k === Node::KIND_REF_ALIAS || $k === Node::KIND_REF_BIND
            || $k === Node::KIND_REF_ADDR
            || $k === Node::KIND_CLASS_NAME || $k === Node::KIND_CLOSURE
            || $k === Node::KIND_YIELD || $k === Node::KIND_SPREAD) {
            return $n;
        }

        // ── Binary (left,right) ──
        if ($k === Node::KIND_ADD) { $x = $this->asAdd($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        if ($k === Node::KIND_SUB) { $x = $this->asSub($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        if ($k === Node::KIND_MUL) { $x = $this->asMul($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        if ($k === Node::KIND_DIV) { $x = $this->asDiv($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        if ($k === Node::KIND_MOD) { $x = $this->asMod($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        if ($k === Node::KIND_CMP) { $x = $this->asCmp($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        if ($k === Node::KIND_CONCAT) { $x = $this->asConcat($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        if ($k === Node::KIND_BITOP) { $x = $this->asBitOp($n); $x->left = $this->rewrite($x->left); $x->right = $this->rewrite($x->right); return $n; }
        // ── Unary (operand) ──
        if ($k === Node::KIND_NEG)    { $x = $this->asNeg($n);    $x->operand = $this->rewrite($x->operand); return $n; }
        if ($k === Node::KIND_NOT)    { $x = $this->asNot($n);    $x->operand = $this->rewrite($x->operand); return $n; }
        if ($k === Node::KIND_BITNOT) { $x = $this->asBitNot($n); $x->operand = $this->rewrite($x->operand); return $n; }
        if ($k === Node::KIND_CAST) {
            $c = $this->asCast($n);
            $c->operand = $this->rewrite($c->operand);
            return $n;
        }
        if ($k === Node::KIND_INSTANCEOF) {
            $io = $this->asInstanceof($n);
            $io->operand = $this->rewrite($io->operand);
            return $n;
        }

        // ── Statements / expressions with children ──
        if ($k === Node::KIND_RETURN) {
            $r = $this->asReturn($n);
            if ($r->value !== null) { $r->value = $this->rewrite($r->value); }
            return $n;
        }
        if ($k === Node::KIND_STORE_LOCAL) {
            $sl = $this->asStoreLocal($n);
            $sl->value = $this->rewrite($sl->value);
            return $n;
        }
        if ($k === Node::KIND_ECHO) {
            $e = $this->asEcho($n);
            $out = [];
            foreach ($e->exprs as $x) { $out[] = $this->rewrite($x); }
            $e->exprs = $out;
            return $n;
        }
        if ($k === Node::KIND_CALL) {
            $call = $this->asCall($n);
            $args = [];
            foreach ($call->args as $a) { $args[] = $this->rewrite($a); }
            $call->args = $args;
            $fused = $this->tryFuseArrayFn($call);
            return $fused ?? $n;
        }
        if ($k === Node::KIND_NULLCOALESCE) {
            $nc = $this->asNullCoalesce($n);
            $nc->left = $this->rewrite($nc->left);
            $nc->right = $this->rewrite($nc->right);
            return $n;
        }
        if ($k === Node::KIND_TERNARY) {
            $t = $this->asTernary($n);
            $t->cond = $this->rewrite($t->cond);
            if ($t->then !== null) { $t->then = $this->rewrite($t->then); }
            $t->else_ = $this->rewrite($t->else_);
            return $n;
        }
        if ($k === Node::KIND_THROW) {
            $th = $this->asThrow($n);
            $th->value = $this->rewrite($th->value);
            return $n;
        }
        if ($k === Node::KIND_ISSET) {
            $is = $this->asIsset($n);
            $out = [];
            foreach ($is->targets as $t) { $out[] = $this->rewrite($t); }
            $is->targets = $out;
            return $n;
        }
        if ($k === Node::KIND_UNSET) {
            $u = $this->asUnset($n);
            $out = [];
            foreach ($u->targets as $t) { $out[] = $this->rewrite($t); }
            $u->targets = $out;
            return $n;
        }
        if ($k === Node::KIND_STORE_STATIC_PROP) {
            $sp = $this->asStoreStaticProp($n);
            $sp->value = $this->rewrite($sp->value);
            return $n;
        }

        // ── Containers / arrays ──
        if ($k === Node::KIND_ARRAY_LIT) {
            foreach ($this->asArrayLit($n)->elements as $el) {
                if ($el->key !== null) { $el->key = $this->rewrite($el->key); }
                $el->value = $this->rewrite($el->value);
            }
            return $n;
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $aa = $this->asArrayAccess($n);
            $aa->array = $this->rewrite($aa->array);
            $aa->index = $this->rewrite($aa->index);
            return $n;
        }
        if ($k === Node::KIND_STORE_ELEMENT) {
            $se = $this->asStoreElement($n);
            $se->array = $this->rewrite($se->array);
            $se->index = $this->rewrite($se->index);
            $se->value = $this->rewrite($se->value);
            return $n;
        }

        // ── Objects ──
        if ($k === Node::KIND_NEW_OBJ) {
            $args = [];
            foreach ($this->asNewObj($n)->args as $a) { $args[] = $this->rewrite($a); }
            $this->asNewObj($n)->args = $args;
            return $n;
        }
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->asPropertyAccess($n);
            $pa->object = $this->rewrite($pa->object);
            return $n;
        }
        if ($k === Node::KIND_STORE_PROPERTY) {
            $sp = $this->asStoreProperty($n);
            $sp->object = $this->rewrite($sp->object);
            $sp->value = $this->rewrite($sp->value);
            return $n;
        }
        if ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->asMethodCall($n);
            $mc->object = $this->rewrite($mc->object);
            $args = [];
            foreach ($mc->args as $a) { $args[] = $this->rewrite($a); }
            $mc->args = $args;
            return $n;
        }
        if ($k === Node::KIND_STATIC_CALL) {
            $sc = $this->asStaticCall($n);
            $args = [];
            foreach ($sc->args as $a) { $args[] = $this->rewrite($a); }
            $sc->args = $args;
            return $n;
        }

        // ── Control flow ──
        if ($k === Node::KIND_BLOCK) { return $this->rewriteBlock($this->asBlock($n)); }
        if ($k === Node::KIND_IF) {
            $if = $this->asIf($n);
            $if->cond = $this->rewrite($if->cond);
            $this->rewriteBlock($if->then);
            if ($if->else !== null) { $this->rewriteBlock($if->else); }
            return $n;
        }
        if ($k === Node::KIND_WHILE) {
            $w = $this->asWhile($n);
            $w->cond = $this->rewrite($w->cond);
            $this->rewriteBlock($w->body);
            return $n;
        }
        if ($k === Node::KIND_DOWHILE) {
            $dw = $this->asDoWhile($n);
            $this->rewriteBlock($dw->body);
            $dw->cond = $this->rewrite($dw->cond);
            return $n;
        }
        if ($k === Node::KIND_FOR) {
            $f = $this->asFor($n);
            if ($f->init !== null) { $f->init = $this->rewrite($f->init); }
            if ($f->cond !== null) { $f->cond = $this->rewrite($f->cond); }
            if ($f->step !== null) { $f->step = $this->rewrite($f->step); }
            $this->rewriteBlock($f->body);
            return $n;
        }
        if ($k === Node::KIND_FOREACH) {
            $fe = $this->asForeach($n);
            $fe->array = $this->rewrite($fe->array);
            $this->rewriteBlock($fe->body);
            return $n;
        }
        if ($k === Node::KIND_SWITCH) {
            $sw = $this->asSwitch($n);
            $sw->subject = $this->rewrite($sw->subject);
            foreach ($sw->arms as $arm) {
                if ($arm->value !== null) { $arm->value = $this->rewrite($arm->value); }
                $body = [];
                foreach ($arm->body as $s) { $body[] = $this->rewrite($s); }
                $arm->body = $body;
            }
            return $n;
        }
        if ($k === Node::KIND_MATCH) {
            $m = $this->asMatch($n);
            $m->subject = $this->rewrite($m->subject);
            foreach ($m->arms as $arm) {
                if ($arm->conds !== null) {
                    $cs = [];
                    foreach ($arm->conds as $c) { $cs[] = $this->rewrite($c); }
                    $arm->conds = $cs;
                }
                $arm->body = $this->rewrite($arm->body);
            }
            return $n;
        }
        if ($k === Node::KIND_TRY_CATCH) {
            $tc = $this->asTryCatch($n);
            $tb = [];
            foreach ($tc->tryBody as $s) { $tb[] = $this->rewrite($s); }
            $tc->tryBody = $tb;
            foreach ($tc->catches as $c) {
                $cb = [];
                foreach ($c->body as $s) { $cb[] = $this->rewrite($s); }
                $c->body = $cb;
            }
            $fb = [];
            foreach ($tc->finallyBody as $s) { $fb[] = $this->rewrite($s); }
            $tc->finallyBody = $fb;
            return $n;
        }

        // Unknown / leaf-like: leave untouched (the invoke fallback is safe).
        return $n;
    }

    /**
     * Splice an eligible captureless single-expr arrow closure at the invoke,
     * or return null to keep the normal invoke (always correct).
     */
    private function tryInline(Invoke_ $iv): ?Node
    {
        $cls = $iv->callee->type->class ?? '';
        if ($cls === '' || !isset($this->closures[$cls])) { return null; }
        if (($this->captureCount[$cls] ?? -1) !== 0) { return null; }
        $fn = $this->fnDef($this->closures[$cls]);
        if ($fn->isGenerator) { return null; }
        // Body must be exactly `{ return <expr>; }`.
        $stmts = $fn->body->stmts;
        if (\count($stmts) !== 1) { return null; }
        $ret = $this->node($stmts[0]);
        if ($ret->kind !== Node::KIND_RETURN) { return null; }
        $retX = $this->asReturn($ret);
        if ($retX->value === null) { return null; }
        // Arity match (captureless → params are exactly the user params).
        if (\count($fn->params) !== \count($iv->args)) { return null; }
        // Map param name → arg node; reject non-substitutable params/args.
        $subst = [];
        $useCounts = $this->countParamUses($retX->value, $fn->params);
        $i = 0;
        foreach ($fn->params as $rawP) {
            $p = $this->param($rawP);
            if ($p->byRef || $p->variadic) { return null; }
            $arg = $this->node($iv->args[$i]);
            $i++;
            if (!$this->argSafe($arg, $useCounts[$p->name] ?? 0)) { return null; }
            $subst[$p->name] = $arg;
        }
        // Clone the body expr and substitute each param load with its arg.
        $body = NodeClone::node($retX->value);
        return $this->substitute($body, $subst);
    }

    /**
     * An arg is safe to inline when it is side-effect-free AND either used at
     * most once (no duplicated work) or cheap to duplicate (a load / const /
     * property read). Anything else → skip (keep the call).
     *
     * @param array<string,Node> ignored — signature kept simple
     */
    private function argSafe(Node $arg, int $uses): bool
    {
        $cheap = $this->isCheap($arg);
        if ($uses <= 1) {
            // Used once: any pure expression is fine (no duplication). Be
            // conservative — only allow clearly side-effect-free shapes.
            return $cheap || $this->isPure($arg);
        }
        return $cheap;
    }

    /** A leaf that is trivially free to re-evaluate. */
    private function isCheap(Node $a): bool
    {
        $k = $a->kind;
        return $k === Node::KIND_LOAD_LOCAL
            || $k === Node::KIND_INT_CONST || $k === Node::KIND_FLOAT_CONST
            || $k === Node::KIND_STRING_CONST || $k === Node::KIND_BOOL_CONST
            || $k === Node::KIND_NULL_CONST
            || ($k === Node::KIND_PROPERTY_ACCESS
                && $this->isCheap($this->asPropertyAccess($a)->object));
    }

    /** Side-effect-free expression (no call/invoke/incdec/store/new/yield). */
    private function isPure(Node $a): bool
    {
        $k = $a->kind;
        if ($k === Node::KIND_CALL || $k === Node::KIND_INVOKE
            || $k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_INCDEC
            || $k === Node::KIND_YIELD || $k === Node::KIND_STORE_LOCAL
            || $k === Node::KIND_STORE_ELEMENT || $k === Node::KIND_STORE_PROPERTY
            || $k === Node::KIND_CLOSURE || $k === Node::KIND_CLONE
            || $k === Node::KIND_THROW || $k === Node::KIND_SPREAD) {
            return false;
        }
        foreach (Walk::children($a) as $c) {
            if (!$this->isPure($c)) { return false; }
        }
        return true;
    }

    /**
     * Count how many times each param name is read in the body expr.
     *
     * @param \Compile\Mir\Param[] $params
     * @return array<string,int>
     */
    private function countParamUses(Node $body, array $params): array
    {
        $names = [];
        foreach ($params as $rawP) { $names[$this->param($rawP)->name] = true; }
        $counts = [];
        $this->countLoads($body, $names, $counts);
        return $counts;
    }

    /**
     * @param array<string,bool> $names
     * @param array<string,int>  $counts
     */
    private function countLoads(Node $n, array $names, array &$counts): void
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL) {
            $name = $this->asLoadLocal($n)->name;
            if (isset($names[$name])) { $counts[$name] = ($counts[$name] ?? 0) + 1; }
            return;
        }
        foreach (Walk::children($n) as $c) { $this->countLoads($c, $names, $counts); }
    }

    /**
     * Replace every `LoadLocal($name)` in the (already-cloned) tree with a
     * fresh clone of the substituted arg. Returns the (possibly replaced) node.
     *
     * @param array<string,Node> $subst
     */
    private function substitute(Node $n, array $subst): Node
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL) {
            $name = $this->asLoadLocal($n)->name;
            if (isset($subst[$name])) { return NodeClone::node($subst[$name]); }
            return $n;
        }
        $this->substituteChildren($n, $subst);
        return $n;
    }

    /** Rewrite each child field of $n in place via {@see substitute}. */
    private function substituteChildren(Node $n, array $subst): void
    {
        $k = $n->kind;
        if ($k === Node::KIND_ADD) { $x = $this->asAdd($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_SUB) { $x = $this->asSub($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_MUL) { $x = $this->asMul($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_DIV) { $x = $this->asDiv($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_MOD) { $x = $this->asMod($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_CMP) { $x = $this->asCmp($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_CONCAT) { $x = $this->asConcat($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_BITOP) { $x = $this->asBitOp($n); $x->left = $this->substitute($x->left, $subst); $x->right = $this->substitute($x->right, $subst); return; }
        if ($k === Node::KIND_NEG)    { $x = $this->asNeg($n);    $x->operand = $this->substitute($x->operand, $subst); return; }
        if ($k === Node::KIND_NOT)    { $x = $this->asNot($n);    $x->operand = $this->substitute($x->operand, $subst); return; }
        if ($k === Node::KIND_BITNOT) { $x = $this->asBitNot($n); $x->operand = $this->substitute($x->operand, $subst); return; }
        if ($k === Node::KIND_CAST) { $c = $this->asCast($n); $c->operand = $this->substitute($c->operand, $subst); return; }
        if ($k === Node::KIND_INSTANCEOF) { $io = $this->asInstanceof($n); $io->operand = $this->substitute($io->operand, $subst); return; }
        if ($k === Node::KIND_NULLCOALESCE) {
            $nc = $this->asNullCoalesce($n);
            $nc->left = $this->substitute($nc->left, $subst);
            $nc->right = $this->substitute($nc->right, $subst);
            return;
        }
        if ($k === Node::KIND_TERNARY) {
            $t = $this->asTernary($n);
            $t->cond = $this->substitute($t->cond, $subst);
            if ($t->then !== null) { $t->then = $this->substitute($t->then, $subst); }
            $t->else_ = $this->substitute($t->else_, $subst);
            return;
        }
        if ($k === Node::KIND_CALL) {
            $args = [];
            foreach ($this->asCall($n)->args as $a) { $args[] = $this->substitute($a, $subst); }
            $this->asCall($n)->args = $args;
            return;
        }
        if ($k === Node::KIND_INVOKE) {
            $iv = $this->asInvoke($n);
            $iv->callee = $this->substitute($iv->callee, $subst);
            $args = [];
            foreach ($iv->args as $a) { $args[] = $this->substitute($a, $subst); }
            $iv->args = $args;
            return;
        }
        if ($k === Node::KIND_ARRAY_LIT) {
            foreach ($this->asArrayLit($n)->elements as $el) {
                if ($el->key !== null) { $el->key = $this->substitute($el->key, $subst); }
                $el->value = $this->substitute($el->value, $subst);
            }
            return;
        }
        if ($k === Node::KIND_ARRAY_ACCESS) {
            $aa = $this->asArrayAccess($n);
            $aa->array = $this->substitute($aa->array, $subst);
            $aa->index = $this->substitute($aa->index, $subst);
            return;
        }
        if ($k === Node::KIND_PROPERTY_ACCESS) {
            $pa = $this->asPropertyAccess($n);
            $pa->object = $this->substitute($pa->object, $subst);
            return;
        }
        if ($k === Node::KIND_METHOD_CALL) {
            $mc = $this->asMethodCall($n);
            $mc->object = $this->substitute($mc->object, $subst);
            $args = [];
            foreach ($mc->args as $a) { $args[] = $this->substitute($a, $subst); }
            $mc->args = $args;
            return;
        }
        if ($k === Node::KIND_STATIC_CALL) {
            $sc = $this->asStaticCall($n);
            $args = [];
            foreach ($sc->args as $a) { $args[] = $this->substitute($a, $subst); }
            $sc->args = $args;
            return;
        }
        // Leaves and any unexpected shape: nothing to substitute. (The
        // eligible bodies are pure single expressions; this never recurses
        // into statement nodes.)
    }

    // ── array_map / array_filter / array_reduce fusion ──────────────────
    //
    // Fuse one of these calls whose callback is a captureless single-expr
    // closure AND whose array argument has a CONCRETE element type into a
    // dedicated loop function, returning a Call to it (the result stays an
    // expression). The synth fn's array param is typed to the call-site array,
    // so the re-run of InferTypes types its inlined body NATIVELY — no closure
    // dispatch, no boxed cell ABI, no Monomorphize dependency. A non-concrete
    // array (a chained fusion's outer call, a dynamically-typed source) is
    // left as the prelude call, which stays correct for every shape.

    private function tryFuseArrayFn(Call $call): ?Node
    {
        $fn = $call->function;
        $args = $call->args;
        if ($fn === 'array_map' && \count($args) === 2) {
            return $this->fuseMap($this->node($args[0]), $this->node($args[1]));
        }
        if ($fn === 'array_filter' && \count($args) === 2) {
            return $this->fuseFilter($this->node($args[0]), $this->node($args[1]));
        }
        if ($fn === 'array_reduce' && \count($args) === 3) {
            return $this->fuseReduce($this->node($args[0]), $this->node($args[1]), $this->node($args[2]));
        }
        if ($fn === 'in_array' && (\count($args) === 2 || \count($args) === 3)) {
            return $this->lowerArrayQuery($args, true);
        }
        if ($fn === 'array_search' && (\count($args) === 2 || \count($args) === 3)) {
            return $this->lowerArrayQuery($args, false);
        }
        return null;
    }

    /**
     * `in_array(n,h[,strict])` / `array_search(n,h[,strict])` over a CONCRETELY-
     * typed haystack → a synthesized per-call loop fn (monomorphized to the
     * call's needle/element types, so the element compare is native — no .o
     * erasure that faults on an int haystack). in_array returns bool; array_search
     * returns the matching key or false. A non-concrete haystack returns null →
     * the stdlib fallback (string-only) handles it.
     *
     * @param Node[] $args
     */
    private function lowerArrayQuery(array $args, bool $isInArray): ?Node
    {
        $needle = $this->node($args[0]);
        $haystack = $this->node($args[1]);
        if (!$this->isConcreteArray($haystack->type)) { return null; }
        // strict (===) when the 3rd arg is a literal `true`; else loose (==).
        $strict = \count($args) >= 3
            && $args[2]->kind === Node::KIND_BOOL_CONST
            && $this->asBool($this->node($args[2]))->value;
        $op = $strict ? '===' : '==';
        $u = Type::unknown();
        // `if ($v <op> $needle) return <hit>;` inside `foreach ($h as $k => $v)`.
        $cmp = new \Compile\Mir\Cmp(new LoadLocal('__mc_v', $u), new LoadLocal('__mc_n', $needle->type), $op);
        $hit = $isInArray
            ? new \Compile\Mir\BoolConst(true, Type::bool_())
            : new LoadLocal('__mc_k', $u);
        $if = new If_($cmp, new Block([new Return_($hit, Type::void())], Type::void()), null);
        $keyVar = $isInArray ? null : '__mc_k';
        $loop = new Foreach_(new LoadLocal('__mc_a', $haystack->type), $keyVar, '__mc_v', false, new Block([$if], Type::void()));
        $miss = new \Compile\Mir\BoolConst(false, Type::bool_());
        $body = new Block([$loop, new Return_($miss, Type::void())], Type::void());
        // array_search returns key|false (mixed) → a tagged cell so emitReturn
        // boxes each return and a caller dispatches by tag (`=== false`, echo,
        // use-as-key). in_array is a plain bool.
        $ret = $isInArray ? Type::bool_() : Type::cell();
        $tag = $isInArray ? 'inarray' : 'arrsearch';
        return $this->emitFusedFn(
            $tag,
            [new Param('__mc_n', $needle->type, false, false), new Param('__mc_a', $haystack->type, false, false)],
            $ret,
            $body,
            [$needle, $haystack],
        );
    }

    private function asBool(Node $n): \Compile\Mir\BoolConst { return $n; }

    /** `array_map(fn($v)=>E, $a)` → `[$o=[]; foreach($a as $k=>$v){ $o[$k]=E; } return $o]`. */
    private function fuseMap(Node $cbArg, Node $arrArg): ?Node
    {
        if (!$this->isConcreteArray($arrArg->type)) { return null; }
        $clos = $this->eligibleClosure($cbArg, 1);
        if ($clos === null) { return null; }
        $vvar = $this->param($clos->params[0])->name;
        $bodyExpr = $this->closureBodyExpr($clos);
        $u = Type::unknown();
        $store = new StoreElement(new LoadLocal('__mc_o', $u), new LoadLocal('__mc_k', $u), $bodyExpr, $u);
        $loop = new Foreach_(new LoadLocal('__mc_a', $arrArg->type), '__mc_k', $vvar, false, new Block([$store], Type::void()));
        $body = new Block([
            new StoreLocal('__mc_o', new ArrayLit([], $u), $u),
            $loop,
            new Return_(new LoadLocal('__mc_o', $u), Type::void()),
        ], Type::void());
        return $this->emitFusedFn('map', [new Param('__mc_a', $arrArg->type, false, false)], $u, $body, [$arrArg]);
    }

    /** `array_filter($a, fn($v)=>P)` → `[$o=[]; foreach($a as $k=>$v){ if(P) $o[$k]=$v; } return $o]`. */
    private function fuseFilter(Node $arrArg, Node $cbArg): ?Node
    {
        if (!$this->isConcreteArray($arrArg->type)) { return null; }
        $clos = $this->eligibleClosure($cbArg, 1);
        if ($clos === null) { return null; }
        $vvar = $this->param($clos->params[0])->name;
        $pred = $this->closureBodyExpr($clos);
        $u = Type::unknown();
        $store = new StoreElement(new LoadLocal('__mc_o', $u), new LoadLocal('__mc_k', $u), new LoadLocal($vvar, $u), $u);
        $if = new If_($pred, new Block([$store], Type::void()), null);
        $loop = new Foreach_(new LoadLocal('__mc_a', $arrArg->type), '__mc_k', $vvar, false, new Block([$if], Type::void()));
        $body = new Block([
            new StoreLocal('__mc_o', new ArrayLit([], $u), $u),
            $loop,
            new Return_(new LoadLocal('__mc_o', $u), Type::void()),
        ], Type::void());
        return $this->emitFusedFn('filter', [new Param('__mc_a', $arrArg->type, false, false)], $arrArg->type, $body, [$arrArg]);
    }

    /** `array_reduce($a, fn($c,$v)=>E, $i)` → `[$c=$i; foreach($a as $v){ $c=E; } return $c]`. */
    private function fuseReduce(Node $arrArg, Node $cbArg, Node $initArg): ?Node
    {
        if (!$this->isConcreteArray($arrArg->type)) { return null; }
        $clos = $this->eligibleClosure($cbArg, 2);
        if ($clos === null) { return null; }
        $cvar = $this->param($clos->params[0])->name;
        $vvar = $this->param($clos->params[1])->name;
        if ($cvar === $vvar) { return null; }
        $bodyExpr = $this->closureBodyExpr($clos);
        $u = Type::unknown();
        $it = $initArg->type;
        $loop = new Foreach_(
            new LoadLocal('__mc_a', $arrArg->type),
            null,
            $vvar,
            false,
            new Block([new StoreLocal($cvar, $bodyExpr, $u)], Type::void()),
        );
        $body = new Block([
            new StoreLocal($cvar, new LoadLocal('__mc_i', $it), $it),
            $loop,
            new Return_(new LoadLocal($cvar, $u), Type::void()),
        ], Type::void());
        return $this->emitFusedFn(
            'reduce',
            [new Param('__mc_a', $arrArg->type, false, false), new Param('__mc_i', $it, false, false)],
            $u,
            $body,
            [$arrArg, $initArg],
        );
    }

    /**
     * The captureless single-expression closure FunctionDef behind a closure
     * VALUE arg with exactly `$arity` simple user params, or null.
     */
    private function eligibleClosure(Node $closureArg, int $arity): ?FunctionDef
    {
        if ($closureArg->kind !== Node::KIND_CLOSURE) { return null; }
        $cls = $closureArg->type->class ?? '';
        if ($cls === '' || !isset($this->closures[$cls])) { return null; }
        if (($this->captureCount[$cls] ?? -1) !== 0) { return null; }
        $fn = $this->fnDef($this->closures[$cls]);
        if ($fn->isGenerator) { return null; }
        if (\count($fn->params) !== $arity) { return null; }
        foreach ($fn->params as $rawP) {
            $p = $this->param($rawP);
            if ($p->byRef || $p->variadic) { return null; }
            if (\strncmp($p->name, '__mc_', 5) === 0) { return null; }
        }
        $stmts = $fn->body->stmts;
        if (\count($stmts) !== 1) { return null; }
        $ret = $this->node($stmts[0]);
        if ($ret->kind !== Node::KIND_RETURN) { return null; }
        if ($this->asReturn($ret)->value === null) { return null; }
        return $fn;
    }

    /** Cloned single-return body expression of an eligible closure fn. */
    private function closureBodyExpr(FunctionDef $fn): Node
    {
        $ret = $this->asReturn($this->node($fn->body->stmts[0]));
        return NodeClone::node($this->node($ret->value));
    }

    /**
     * Register a synthesized fused loop function and return a Call to it.
     *
     * @param Param[] $params
     * @param Node[]  $args
     */
    private function emitFusedFn(string $tag, array $params, Type $ret, Block $body, array $args): Node
    {
        $name = '__mc_fuse_' . $tag . '_' . (string)$this->fuseCounter;
        $this->fuseCounter = $this->fuseCounter + 1;
        $this->module->addFunction(new FunctionDef($name, $params, $ret, $body));
        return new Call($name, $args, $ret);
    }

    /** A concretely-shaped array: element (and key, if assoc) is definite. */
    private function isConcreteArray(Type $t): bool
    {
        if (!$t->isArray()) { return false; }
        $e = $t->element;
        if ($e === null || !$this->isConcreteElem($e)) { return false; }
        if ($t->isAssoc()) {
            $key = $t->key;
            if ($key === null || !$this->isConcreteElem($key)) { return false; }
        }
        return true;
    }

    private function isConcreteElem(Type $t): bool
    {
        $k = $t->kind;
        if ($k === Type::KIND_UNKNOWN || $k === Type::KIND_CELL || $k === Type::KIND_VOID) {
            return false;
        }
        if ($k === Type::KIND_ARRAY) { return $this->isConcreteArray($t); }
        return true;
    }

    private function fnDef(FunctionDef $f): FunctionDef { return $f; }
    private function param(\Compile\Mir\Param $p): \Compile\Mir\Param { return $p; }
    private function node(Node $n): Node { return $n; }
    private function asInvoke(Node $n): Invoke_ { return $n; }
    private function asReturn(Node $n): Return_ { return $n; }
    private function asLoadLocal(Node $n): LoadLocal { return $n; }
    private function asStoreLocal(Node $n): StoreLocal { return $n; }
    private function asEcho(Node $n): Echo_ { return $n; }
    private function asCall(Node $n): \Compile\Mir\Call { return $n; }
    private function asNullCoalesce(Node $n): NullCoalesce_ { return $n; }
    private function asTernary(Node $n): Ternary { return $n; }
    private function asThrow(Node $n): Throw_ { return $n; }
    private function asIsset(Node $n): Isset_ { return $n; }
    private function asUnset(Node $n): Unset_ { return $n; }
    private function asStoreStaticProp(Node $n): StoreStaticProp_ { return $n; }
    private function asArrayLit(Node $n): ArrayLit { return $n; }
    private function asArrayAccess(Node $n): ArrayAccess_ { return $n; }
    private function asStoreElement(Node $n): StoreElement { return $n; }
    private function asNewObj(Node $n): NewObj { return $n; }
    private function asPropertyAccess(Node $n): PropertyAccess_ { return $n; }
    private function asStoreProperty(Node $n): StoreProperty { return $n; }
    private function asMethodCall(Node $n): MethodCall_ { return $n; }
    private function asStaticCall(Node $n): StaticCall_ { return $n; }
    private function asBlock(Node $n): Block { return $n; }
    private function asIf(Node $n): If_ { return $n; }
    private function asWhile(Node $n): While_ { return $n; }
    private function asDoWhile(Node $n): DoWhile_ { return $n; }
    private function asFor(Node $n): For_ { return $n; }
    private function asForeach(Node $n): Foreach_ { return $n; }
    private function asSwitch(Node $n): Switch_ { return $n; }
    private function asMatch(Node $n): Match_ { return $n; }
    private function asTryCatch(Node $n): TryCatch_ { return $n; }
    private function asCast(Node $n): Cast { return $n; }
    private function asInstanceof(Node $n): Instanceof_ { return $n; }
    private function asAdd(Node $n): \Compile\Mir\Add { return $n; }
    private function asSub(Node $n): \Compile\Mir\Sub { return $n; }
    private function asMul(Node $n): \Compile\Mir\Mul { return $n; }
    private function asDiv(Node $n): \Compile\Mir\Div { return $n; }
    private function asMod(Node $n): \Compile\Mir\Mod { return $n; }
    private function asCmp(Node $n): \Compile\Mir\Cmp { return $n; }
    private function asConcat(Node $n): \Compile\Mir\Concat { return $n; }
    private function asBitOp(Node $n): \Compile\Mir\BitOp { return $n; }
    private function asNeg(Node $n): \Compile\Mir\Neg { return $n; }
    private function asNot(Node $n): \Compile\Mir\Not_ { return $n; }
    private function asBitNot(Node $n): \Compile\Mir\BitNot_ { return $n; }
}
