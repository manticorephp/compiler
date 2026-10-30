<?php

namespace Semantic;

use Analyze\AstWalk;
use Analyze\Index;
use Analyze\Unit;
use Analyze\Units;
use Parser\Ast\ArrayAccess;
use Parser\Ast\ArrayLit;
use Parser\Ast\ArrowFn;
use Parser\Ast\Assign;
use Parser\Ast\BinaryOp;
use Parser\Ast\Block;
use Parser\Ast\BoolLiteral;
use Parser\Ast\BreakStmt;
use Parser\Ast\CallExpr;
use Parser\Ast\Cast;
use Parser\Ast\Closure;
use Parser\Ast\CloneExpr;
use Parser\Ast\CompoundAssign;
use Parser\Ast\ContinueStmt;
use Parser\Ast\DoWhileStmt;
use Parser\Ast\DynProp;
use Parser\Ast\DynamicStaticAccess;
use Parser\Ast\DynamicStaticCall;
use Parser\Ast\DynamicStaticMethodCall;
use Parser\Ast\EchoStmt;
use Parser\Ast\Expr;
use Parser\Ast\ExpressionStmt;
use Parser\Ast\ForStmt;
use Parser\Ast\ForeachStmt;
use Parser\Ast\GlobalStmt;
use Parser\Ast\Identifier;
use Parser\Ast\IfStmt;
use Parser\Ast\IncDec;
use Parser\Ast\InstanceofExpr;
use Parser\Ast\Invoke;
use Parser\Ast\MatchExpr;
use Parser\Ast\MethodCallExpr;
use Parser\Ast\NamedArg;
use Parser\Ast\NamespaceStmt;
use Parser\Ast\NewDynExpr;
use Parser\Ast\NewExpr;
use Parser\Ast\NullCoalesce;
use Parser\Ast\NullLiteral;
use Parser\Ast\Program;
use Parser\Ast\PropertyAccess;
use Parser\Ast\RangedSpan;
use Parser\Ast\LabelStmt;
use Parser\Ast\RefAssign;
use Parser\Ast\ReturnStmt;
use Parser\Ast\Spread;
use Parser\Ast\StaticCall;
use Parser\Ast\StaticLocalStmt;
use Parser\Ast\Stmt;
use Parser\Ast\SwitchStmt;
use Parser\Ast\Ternary;
use Parser\Ast\ThrowStmt;
use Parser\Ast\TryCatchStmt;
use Parser\Ast\UnaryOp;
use Parser\Ast\Variable;
use Parser\Ast\WhileStmt;
use Parser\Ast\YieldExpr;

/**
 * Flow-sensitive environment for the editor: the {@see TypeEnv} in effect at one expression.
 *
 * One forward walk of the enclosing unit (function, method or main; a closure or arrow fn is entered
 * from the walk of its enclosing unit, so `use` / by-value captures see the env at the closure
 * expression) that stops at the target. Cost: one identity search over the file to pick the unit, then
 * O(unit) with early exit; nothing is cached, so nothing can go stale on a file update.
 *
 * This RE-IMPLEMENTS the condition recognition of {@see \Analyze\FlowWalk} over {@see SourceType},
 * because its `Ty` cannot hold unions or nullable class types. Unifying the two is planned for P4.
 * It recognises more: `||`, else / elseif remainders, ternary and `match(true)` arms, property paths,
 * any always-exiting branch (a dead env joins away), loop-condition negation after the loop, foreach
 * element binding and catch types.
 *
 * Model. Absent from `vars` = unknown. Assignments are strong updates in order. A join is the union of
 * the branch envs; a name known on one side only becomes unknown. A property path (`$a->b->c`, key
 * `a->b->c`) is narrowable; it is dropped by an assignment to it or to a prefix of it, by a method call
 * on its root variable, by `self::` / `parent::` / `static::` calls (root `this`) and by calling a
 * closure value. Loops: the entry env is widened by up to two silent passes over the body (one when
 * nested three or deeper), so a variable reassigned in a loop is the union of its types; names still
 * changing after that are made unknown and the pass is re-run until nothing changes (a growing type
 * such as `$x = [$x]` is unknown), and past a budget of silent passes a loop head is all-unknown.
 * Aliasing is conservative: an object copied to another name or passed to any call loses its property
 * paths. A closure given to Closure::bind / bindTo / call is entered with no class. try: a catch starts from the
 * union of the env before the try and after every statement inside it.
 *
 * Known limits (documented, not hidden): reference aliasing beyond the rules above, `$GLOBALS`, inline
 * `@var` docblocks are not modelled; a name that is ever reference-aliased (`use (&$x)`, `&$x`, a by-ref foreach) is never typed, and `$$n`, `include`, a label
 * make the scope unknown; a plain
 * function missing from the index is checked against a small by-ref builtin table only; `switch(true)`
 * arms are not narrowed; a positive `instanceof` against an interface keeps the union's classes unchanged.
 */
final class Flow
{
    private ExprTyper $typer;
    private ?TypeEnv $found = null;
    private int $silent = 0;
    private int $loopDepth = 0;

    /** @var TypeEnv[][] break envs per enclosing loop / switch */
    private array $brk = [];
    /** @var TypeEnv[][] continue envs per enclosing loop / switch */
    private array $cnt = [];
    /** @var bool[] */
    private array $sw = [];
    /** @var TypeEnv[][] envs seen inside each active try body */
    private array $tryAcc = [];
    /** @var array<int, bool> spl_object_id of closures passed to / used as receiver of bind, bindTo, call */
    private array $rebound = [];
    /** @var array<string, bool> names that are or may be reference-aliased (by-ref capture, `&$x`): never given a type */
    private array $aliased = [];
    /** silent loop passes left; when spent a loop head falls back to an all-unknown env (sound, imprecise) */
    private int $passBudget = 400;

    private function __construct(private Index $idx, string $file, private Expr $target)
    {
        $this->typer = new ExprTyper($idx, $file);
    }

    /**
     * The env in effect at $target (the state just before it is evaluated). When $target belongs to no
     * walked unit (a parameter default, a class constant) the env of the enclosing unit's entry is
     * returned. An unreachable point answers an env with `dead` set.
     */
    public static function envAt(Program $p, Index $idx, string $file, Expr $target): TypeEnv
    {
        $units = new Units();
        $units->collect($p->statements, $p->useAliases);
        $chosen = null;
        $main = null;
        foreach ($units->units as $u) {
            if ($u->label === '{closure}') { continue; }
            if ($u->label === '{main}') { $main = $u; continue; }
            if (self::unitHas($u, $target)) { $chosen = $u; break; }
        }
        if ($chosen === null) { $chosen = $main; }
        if ($chosen === null) { return new TypeEnv(); }
        return self::envAtUnit($chosen, $idx, $file, $target);
    }

    /** As {@see envAt} when the unit holding $target is already known (UnitLookup): walks that unit only. */
    public static function envAtUnit(Unit $chosen, Index $idx, string $file, Expr $target): TypeEnv
    {
        $env = TypeEnv::forUnit($chosen, $idx);
        $f = new Flow($idx, $file, $target);
        $f->runUnit($chosen, $env);
        return $f->found ?? $env;
    }

    private static function unitHas(Unit $u, Expr $target): bool
    {
        $w = new AstWalk();
        if ($u->exprBody !== null) { $w->exprTree($u->exprBody); } else { $w->stmts($u->stmts); }
        foreach ($w->exprs as $x) { if ($x === $target) { return true; } }
        return false;
    }

    private function runUnit(Unit $u, TypeEnv $env): TypeEnv
    {
        if ($u->exprBody !== null) { return $this->expr($u->exprBody, $env); }
        return $this->stmts($u->stmts, $env);
    }

    // ── statements ────────────────────────────────────────────────────

    /**
     * @param Stmt[] $ss
     */
    private function stmts(array $ss, TypeEnv $env): TypeEnv
    {
        foreach ($ss as $s) {
            if ($this->found !== null) { return $env; }
            $env = $this->stmt($s, $env);
            $n = \count($this->tryAcc);
            if ($n > 0) { $this->tryAcc[$n - 1][] = $env; }
        }
        return $env;
    }

    private function block(?Block $b, TypeEnv $env): TypeEnv
    {
        return $b === null ? $env : $this->stmts($b->statements, $env);
    }

    private function stmt(Stmt $s, TypeEnv $env): TypeEnv
    {
        if ($s instanceof ExpressionStmt) { return $this->expr($s->expr, $env); }
        if ($s instanceof EchoStmt) {
            foreach ($s->exprs as $e) { $env = $this->expr($e, $env); }
            return $env;
        }
        if ($s instanceof ReturnStmt) { return self::dead($s->value === null ? $env : $this->expr($s->value, $env)); }
        if ($s instanceof ThrowStmt) { return self::dead($this->expr($s->expr, $env)); }
        if ($s instanceof IfStmt) { return $this->ifStmt($s, $env); }
        if ($s instanceof WhileStmt) { return $this->whileStmt($s, $env); }
        if ($s instanceof DoWhileStmt) { return $this->doWhileStmt($s, $env); }
        if ($s instanceof ForStmt) { return $this->forStmt($s, $env); }
        if ($s instanceof ForeachStmt) { return $this->foreachStmt($s, $env); }
        if ($s instanceof SwitchStmt) { return $this->switchStmt($s, $env); }
        if ($s instanceof TryCatchStmt) { return $this->tryStmt($s, $env); }
        if ($s instanceof BreakStmt) { return $this->jump($env, $s->level, false); }
        if ($s instanceof ContinueStmt) { return $this->jump($env, $s->level, true); }
        if ($s instanceof NamespaceStmt) { return $this->block($s->body, $env); }
        if ($s instanceof LabelStmt) {
            // a goto may arrive from anywhere: nothing is known here
            $o = self::onlyThis($env);
            $o->dead = false;
            return $o;
        }
        if ($s instanceof StaticLocalStmt) {
            $out = $env->copy();
            foreach ($s->decls as $d) { $out->forget($d->name); }
            return $out;
        }
        if ($s instanceof GlobalStmt) {
            $out = $env->copy();
            foreach ($s->names as $n) { $out->forget($n); }
            return $out;
        }
        return $env;
    }

    private function ifStmt(IfStmt $s, TypeEnv $env): TypeEnv
    {
        [$t, $f] = $this->cond($s->condition, $env);
        $out = $this->block($s->then, $t);
        foreach ($s->elseifs as $arm) {
            [$t2, $f2] = $this->cond($arm->condition, $f);
            $out = self::join($out, $this->block($arm->body, $t2));
            $f = $f2;
        }
        return self::join($out, $s->else !== null ? $this->block($s->else, $f) : $f);
    }

    private function whileStmt(WhileStmt $s, TypeEnv $env): TypeEnv
    {
        return $this->loop($env, function (TypeEnv $h) use ($s): array {
            [$t, $f] = $this->cond($s->condition, $h);
            return [$this->block($s->body, $t), $f];
        });
    }

    private function doWhileStmt(DoWhileStmt $s, TypeEnv $env): TypeEnv
    {
        return $this->loop($env, function (TypeEnv $h) use ($s): array {
            $end = $this->block($s->body, $h);
            $n = \count($this->cnt) - 1;
            foreach ($this->cnt[$n] as $c) { $end = self::join($end, $c); }
            [$t, $f] = $this->cond($s->condition, $end);
            return [$t, $f];
        });
    }

    private function forStmt(ForStmt $s, TypeEnv $env): TypeEnv
    {
        foreach ($s->init as $e) { $env = $this->expr($e, $env); }
        return $this->loop($env, function (TypeEnv $h) use ($s): array {
            if ($s->condition !== null) {
                [$t, $f] = $this->cond($s->condition, $h);
            } else {
                $t = $h;
                $f = self::dead($h);
            }
            $end = $this->block($s->body, $t);
            $n = \count($this->cnt) - 1;
            foreach ($this->cnt[$n] as $c) { $end = self::join($end, $c); }
            foreach ($s->update as $e) { $end = $this->expr($e, $end); }
            return [$end, $f];
        });
    }

    private function foreachStmt(ForeachStmt $s, TypeEnv $env): TypeEnv
    {
        $env = $this->expr($s->expr, $env);
        $elems = self::elementTypes($this->typer->typeOf($s->expr, $env));
        return $this->loop($env, function (TypeEnv $h) use ($s, $elems): array {
            $b = $h;
            if ($s->valueByRef) {
                $b = $this->alias($s->expr, $b);
                $b = $this->alias($s->value, $b);
            }
            if ($s->key !== null) { $b = $this->store($s->key, $elems[0], $b); }
            $b = $this->store($s->value, $s->valueByRef ? null : $elems[1], $b);
            return [$this->block($s->body, $b), $h];
        });
    }

    /**
     * Widen the entry env with up to two silent passes (one when nested three deep), then make it a
     * fixpoint by giving up (unknown) every name that is still changing, then run the recording pass.
     * $iter takes the loop-head env and returns [env at the end of the body, env leaving the loop normally].
     * When the pass budget is spent the head is all-unknown.
     */
    private function loop(TypeEnv $entry, callable $iter): TypeEnv
    {
        $h = $entry;
        $this->loopDepth = $this->loopDepth + 1;
        if ($this->passBudget <= 0) {
            $h = self::onlyThis($entry);
        } else {
            $passes = $this->loopDepth <= 2 ? 2 : 1;
            $stable = false;
            for ($i = 0; $i < $passes && $this->found === null; $i++) {
                $h2 = self::join($h, $this->silentBack($iter, $h));
                if (self::same($h, $h2)) { $stable = true; break; }
                $h = $h2;
            }
            // forgetting one name can change a name copied from it, so re-run until nothing changes
            for ($k = 0; !$stable && $k < 4 && $this->found === null; $k++) {
                $h2 = self::join($h, $this->silentBack($iter, $h));
                $drop = [];
                foreach ($h->vars as $name => $t) {
                    $u = $h2->vars[$name] ?? null;
                    if ($u === null || ($t !== $u && $t->toString() !== $u->toString())) { $drop[] = (string)$name; }
                }
                if (\count($drop) === 0) { $stable = true; break; }
                $h = $h->copy();
                foreach ($drop as $name) { $h->forget($name); }
            }
            if (!$stable) { $h = self::onlyThis($h); }
        }
        $r = $this->iterate($iter, $h);
        $this->loopDepth = $this->loopDepth - 1;
        return $r[1];
    }

    /** One silent pass over a loop body; returns its back-edge env. */
    private function silentBack(callable $iter, TypeEnv $h): TypeEnv
    {
        $this->passBudget = $this->passBudget - 1;
        $this->silent = $this->silent + 1;
        $r = $this->iterate($iter, $h);
        $this->silent = $this->silent - 1;
        return $r[0];
    }

    private static function onlyThis(TypeEnv $env): TypeEnv
    {
        $o = $env->copy();
        $keep = $o->vars['this'] ?? null;
        $o->vars = $keep === null ? [] : ['this' => $keep];
        return $o;
    }

    /**
     * @return TypeEnv[] [back edge, exit]
     */
    private function iterate(callable $iter, TypeEnv $h): array
    {
        $this->brk[] = [];
        $this->cnt[] = [];
        $this->sw[] = false;
        $r = $iter($h);
        $n = \count($this->brk) - 1;
        $back = $r[0];
        foreach ($this->cnt[$n] as $c) { $back = self::join($back, $c); }
        $exit = $r[1];
        foreach ($this->brk[$n] as $b) { $exit = self::join($exit, $b); }
        \array_pop($this->brk);
        \array_pop($this->cnt);
        \array_pop($this->sw);
        return [$back, $exit];
    }

    private function jump(TypeEnv $env, int $level, bool $isContinue): TypeEnv
    {
        $idx = \count($this->brk) - \max(1, $level);
        if ($idx >= 0) {
            if ($isContinue && !$this->sw[$idx]) { $this->cnt[$idx][] = $env; } else { $this->brk[$idx][] = $env; }
        }
        return self::dead($env);
    }

    private function switchStmt(SwitchStmt $s, TypeEnv $env): TypeEnv
    {
        $env = $this->expr($s->expr, $env);
        $this->brk[] = [];
        $this->cnt[] = [];
        $this->sw[] = true;
        $prev = self::dead($env);
        $hasDefault = false;
        foreach ($s->cases as $arm) {
            $in = $env;
            if ($arm->value !== null) { $in = $this->expr($arm->value, $env); } else { $hasDefault = true; }
            $prev = $this->stmts($arm->body, self::join($in, $prev));
        }
        $n = \count($this->brk) - 1;
        $out = $prev;
        foreach ($this->brk[$n] as $b) { $out = self::join($out, $b); }
        \array_pop($this->brk);
        \array_pop($this->cnt);
        \array_pop($this->sw);
        return $hasDefault ? $out : self::join($out, $env);
    }

    private function tryStmt(TryCatchStmt $s, TypeEnv $env): TypeEnv
    {
        $this->tryAcc[] = [$env];
        $tryEnd = $this->block($s->try, $env);
        $acc = \array_pop($this->tryAcc);
        $catchIn = $tryEnd;
        foreach ($acc as $a) {
            $catchIn = self::join($catchIn, $a);
            $n = \count($this->tryAcc);
            if ($n > 0) { $this->tryAcc[$n - 1][] = $a; }
        }
        $result = $tryEnd;
        foreach ($s->catches as $c) {
            $ce = $catchIn->copy();
            $ce->dead = false;
            if ($c->name !== null) {
                $ce->forget($c->name);
                $parts = [];
                foreach ($c->types as $t) { $parts[] = new SourceType(SourceType::CLASS_, \ltrim($t, '\\')); }
                if (\count($parts) > 0) { $ce->vars[$c->name] = ExprTyper::union($parts); }
            }
            $result = self::join($result, $this->block($c->body, $ce));
        }
        if ($s->finally === null) { return $result; }
        // finally also runs on the paths that left through return / throw: walk it ONCE against the wide
        // env (sound for the recorded target and for the post-state, a little imprecise for the latter)
        $post = $this->block($s->finally, self::join($result, $catchIn));
        return $result->dead ? self::dead($post) : $post;
    }

    // ── env algebra ───────────────────────────────────────────────────

    private static function dead(TypeEnv $env): TypeEnv
    {
        if ($env->dead) { return $env; }
        $o = $env->copy();
        $o->dead = true;
        return $o;
    }

    public static function join(TypeEnv $a, TypeEnv $b): TypeEnv
    {
        if ($a->dead) { return $b; }
        if ($b->dead || $a === $b) { return $a; }
        $out = $a->copy();
        $out->vars = [];
        foreach ($a->vars as $k => $t) {
            $u = $b->vars[$k] ?? null;
            if ($u === null) { continue; }
            $out->vars[$k] = $t === $u ? $t : ExprTyper::union([$t, $u]);
        }
        return $out;
    }

    private static function same(TypeEnv $a, TypeEnv $b): bool
    {
        if ($a->dead !== $b->dead || \count($a->vars) !== \count($b->vars)) { return false; }
        foreach ($a->vars as $k => $t) {
            $u = $b->vars[$k] ?? null;
            if ($u === null || ($t !== $u && $t->toString() !== $u->toString())) { return false; }
        }
        return true;
    }

    // ── expressions (effects, in evaluation order) ───────────────────

    private function expr(Expr $e, TypeEnv $env): TypeEnv
    {
        if ($this->found !== null) { return $env; }
        if ($e === $this->target && $this->silent === 0) { $this->found = $env; return $env; }
        if ($e instanceof Variable) { return $e->name === '' ? self::onlyThis($env) : $env; } // nameless: `$$n`, any variable may be written
        if ($e instanceof Assign) { return $this->assign($e, $env); }
        if ($e instanceof BinaryOp) {
            $op = $e->op;
            if ($op === '&&' || $op === 'and' || $op === '||' || $op === 'or') {
                [$t, $f] = $this->cond($e, $env);
                return self::join($t, $f);
            }
            return $this->expr($e->right, $this->expr($e->left, $env));
        }
        if ($e instanceof CompoundAssign) {
            $env = $this->expr($e->value, $this->expr($e->target, $env));
            return $this->store($e->target, $this->typer->typeOf($e, $env), $env);
        }
        if ($e instanceof IncDec) {
            $env = $this->expr($e->operand, $env);
            $o = $this->typer->typeOf($e->operand, $env);
            $keep = $o !== null && $o->kind === SourceType::SCALAR && ($o->name === 'int' || $o->name === 'float');
            return $this->store($e->operand, $keep ? $o : null, $env);
        }
        if ($e instanceof RefAssign) {
            $env = $this->expr($e->source, $env);
            $out = $this->alias($e->target, $env);
            return $this->alias($e->source, $out);
        }
        if ($e instanceof Ternary) {
            [$t, $f] = $this->cond($e->condition, $env);
            $a = $e->then === null ? $t : $this->expr($e->then, $t);
            return self::join($a, $this->expr($e->else, $f));
        }
        if ($e instanceof NullCoalesce) {
            $l = $this->expr($e->left, $env);
            return self::join($l, $this->expr($e->right, $l));
        }
        if ($e instanceof MatchExpr) { return $this->matchExpr($e, $env); }
        if ($e instanceof UnaryOp) {
            $o = $this->expr($e->operand, $env);
            return $e->op === 'throw' ? self::dead($o) : $o;
        }
        if ($e instanceof Closure || $e instanceof ArrowFn) { return $this->closureExpr($e, $env); }
        if ($e instanceof Identifier) {
            $l = \strtolower($e->name);
            return $l === 'exit' || $l === 'die' ? self::dead($env) : $env;
        }
        if ($e instanceof NewExpr) { return $this->newExpr($e, $env); }
        if ($e instanceof CallExpr || $e instanceof MethodCallExpr || $e instanceof StaticCall || $e instanceof Invoke) {
            return $this->callLike($e, $env);
        }
        if ($e instanceof ArrayLit) {
            foreach ($e->elements as $el) { if ($el->byRef) { $env = $this->alias($el->value, $env); } }
        }
        foreach (self::kids($e) as $k) { $env = $this->expr($k, $env); }
        return $env;
    }

    /** The root variable of $t is reference-aliased from now on: forgotten, and never typed again. */
    private function alias(Expr $t, TypeEnv $env): TypeEnv
    {
        $root = self::rootVar($t);
        if ($root === null) { return $this->store($t, null, $env); }
        $this->aliased[$root] = true;
        $out = $env->copy();
        $out->forget($root);
        return $out;
    }

    /**
     * Direct sub-expressions in evaluation order, for the node kinds with no flow meaning of their own.
     *
     * @return Expr[]
     */
    private static function kids(Expr $e): array
    {
        /** @var Expr[] $o */
        $o = [];
        if ($e instanceof Cast || $e instanceof InstanceofExpr) { $o[] = $e->operand; }
        elseif ($e instanceof ArrayLit) {
            foreach ($e->elements as $el) {
                if ($el->key !== null) { $o[] = $el->key; }
                $o[] = $el->value;
            }
        } elseif ($e instanceof ArrayAccess) {
            $o[] = $e->array;
            if ($e->index !== null) { $o[] = $e->index; }
        } elseif ($e instanceof PropertyAccess || $e instanceof CloneExpr) {
            $o[] = $e->object;
            if ($e instanceof CloneExpr && $e->withProps !== null) { $o[] = $e->withProps; }
        } elseif ($e instanceof DynProp) {
            $o[] = $e->object;
            $o[] = $e->name;
        } elseif ($e instanceof NewExpr) {
            foreach ($e->args as $a) { $o[] = $a; }
        } elseif ($e instanceof NewDynExpr) {
            $o[] = $e->classExpr;
            foreach ($e->args as $a) { $o[] = $a; }
        } elseif ($e instanceof NamedArg || $e instanceof Spread) {
            $o[] = $e->value;
        } elseif ($e instanceof YieldExpr) {
            if ($e->key !== null) { $o[] = $e->key; }
            if ($e->value !== null) { $o[] = $e->value; }
        } elseif ($e instanceof DynamicStaticAccess) {
            $o[] = $e->receiver;
        } elseif ($e instanceof DynamicStaticCall) {
            $o[] = $e->receiver;
            foreach ($e->args as $a) { $o[] = $a; }
        } elseif ($e instanceof DynamicStaticMethodCall) {
            $o[] = $e->nameExpr;
            foreach ($e->args as $a) { $o[] = $a; }
        }
        return $o;
    }

    private function assign(Assign $e, TypeEnv $env): TypeEnv
    {
        $t = $e->target;
        $env = $this->lvalue($t, $env);
        $env = $this->expr($e->value, $env);
        $out = $this->store($t, $this->typer->typeOf($e->value, $env), $env);
        // `$b = $a`: both names now reach one object, so no property path of it can be trusted
        return $this->escape($e->value, $out);
    }

    /** Evaluate what an assignment target itself reads (receiver, index); the target is not read. */
    private function lvalue(Expr $t, TypeEnv $env): TypeEnv
    {
        if ($this->found !== null) { return $env; }
        if ($t === $this->target && $this->silent === 0) { $this->found = $env; return $env; }
        if ($t instanceof Variable) { return $env; }
        if ($t instanceof PropertyAccess) { return $this->expr($t->object, $env); }
        if ($t instanceof ArrayAccess) {
            $env = $this->lvalue($t->array, $env);
            return $t->index === null ? $env : $this->expr($t->index, $env);
        }
        if ($t instanceof ArrayLit) {
            foreach ($t->elements as $el) {
                if ($el->key !== null) { $env = $this->expr($el->key, $env); }
                $env = $this->lvalue($el->value, $env);
            }
            return $env;
        }
        return $this->expr($t, $env);
    }

    /** Record that target $t now holds $ty (null = unknown). Returns a new env. */
    private function store(Expr $t, ?SourceType $ty, TypeEnv $env): TypeEnv
    {
        $out = $env->copy();
        if ($t instanceof Variable) {
            if ($t->name === 'this') { return $env; }
            // a nameless Variable is `$$n`: any variable may be written
            if ($t->name === '') { return self::onlyThis($env); }
            $out->forget($t->name);
            if ($ty !== null && $ty->kind !== SourceType::NEVER && !isset($this->aliased[$t->name])) { $out->vars[$t->name] = $ty; }
            return $out;
        }
        if ($t instanceof PropertyAccess) {
            $key = TypeEnv::pathKey($t);
            if ($key === null) { return $env; }
            $out->forget($key);
            // only an object-ish value is kept: a typed property coerces a scalar to its declared type
            if ($ty !== null && self::objectish($ty)) { $out->vars[$key] = $ty; }
            return $out;
        }
        if ($t instanceof ArrayAccess) {
            $root = $t->array;
            while ($root instanceof ArrayAccess) { $root = $root->array; }
            if ($root instanceof Variable) {
                $cur = $out->vars[$root->name] ?? null;
                if ($cur !== null && ($cur->kind === SourceType::GENERIC || $cur->kind === SourceType::ITERABLE)) {
                    $out->vars[$root->name] = ExprTyper::scalar('array');
                }
            } elseif ($root instanceof PropertyAccess) {
                $key = TypeEnv::pathKey($root);
                if ($key !== null) { $out->forget($key); }
            }
            return $out;
        }
        if ($t instanceof ArrayLit) {
            foreach ($t->elements as $el) { $out = $this->store($el->value, null, $out); }
            return $out;
        }
        return $env;
    }

    private static function objectish(SourceType $t): bool
    {
        foreach (self::members($t) as $m) {
            $isNull = $m->kind === SourceType::SCALAR && $m->name === 'null';
            if ($m->kind !== SourceType::CLASS_ && !$isNull) { return false; }
        }
        return true;
    }

    private function callLike(Expr $e, TypeEnv $env): TypeEnv
    {
        $this->markRebound($e);
        if ($e instanceof MethodCallExpr) { $env = $this->expr($e->object, $env); }
        if ($e instanceof Invoke) { $env = $this->expr($e->callee, $env); }
        foreach ($e->args as $a) { $env = $this->expr($a, $env); }
        $out = $env;
        if ($e instanceof MethodCallExpr) {
            $root = self::rootVar($e->object);
            if ($root !== null) { $out = $out->copy(); $out->forgetBelow($root); }
        } elseif ($e instanceof StaticCall) {
            $low = \strtolower($e->class);
            if ($low === 'self' || $low === 'parent' || $low === 'static') { $out = $out->copy(); $out->forgetBelow('this'); }
        } elseif ($e instanceof Invoke) {
            $out = $out->copy();
            foreach (\array_keys($out->vars) as $k) { if (\strpos((string)$k, '->') !== false) { unset($out->vars[$k]); } }
        }
        if ($e instanceof CallExpr) { $out = $this->callEffects($e, $out); }
        $out = $this->refArgs($e, $out);
        foreach ($e->args as $a) { $out = $this->escape($a, $out); }
        $ty = $this->typer->typeOf($e, $out);
        if ($ty !== null && $ty->kind === SourceType::NEVER) { return self::dead($out); }
        return $out;
    }

    /** `new` evaluates its arguments like a call and may take them by reference. */
    private function newExpr(NewExpr $e, TypeEnv $env): TypeEnv
    {
        foreach ($e->args as $a) { $env = $this->expr($a, $env); }
        $out = $this->refArgs($e, $env);
        foreach ($e->args as $a) { $out = $this->escape($a, $out); }
        return $out;
    }

    /**
     * An object passed to anything (or copied to another name) may be changed through the other name:
     * drop every property path below it. Aliasing is otherwise not tracked.
     */
    private function escape(Expr $a, TypeEnv $env): TypeEnv
    {
        if ($a instanceof NamedArg || $a instanceof Spread) { $a = $a->value; }
        $key = TypeEnv::pathKey($a);
        if ($key === null) { return $env; }
        $out = $env->copy();
        $out->forgetBelow($key);
        return $out;
    }

    /**
     * A closure handed to Closure::bind / fromCallable, or used as the receiver of bindTo / call, runs with
     * another `$this` and scope than its lexical one: it is entered with no class at all.
     */
    private function markRebound(Expr $e): void
    {
        $c = null;
        if ($e instanceof StaticCall) {
            $cls = self::bareFn($e->class); // the parser prefixes the namespace: `N\Closure`
            $m = \strtolower($e->method);
            if ($cls === 'closure' && ($m === 'bind' || $m === 'fromcallable') && \count($e->args) > 0) { $c = $e->args[0]; }
        } elseif ($e instanceof MethodCallExpr) {
            $m = \strtolower($e->method);
            if ($m === 'bindto' || $m === 'call' || $m === 'bind') { $c = $e->object; }
        }
        if ($c instanceof Closure || $c instanceof ArrowFn) { $this->rebound[\spl_object_id($c)] = true; }
    }

    /**
     * Arguments a callee takes by reference become unknown (a property path or array element loses its
     * narrowing too). A method / static / constructor callee that cannot be resolved, and any call with
     * named or spread arguments, invalidates every variable / path argument; an unresolved plain function
     * is looked up in a small builtin table.
     */
    private function refArgs(Expr $e, TypeEnv $env): TypeEnv
    {
        $params = null;
        $all = false;
        if ($e instanceof CallExpr) {
            $fn = $this->idx->resolveFunction($e->function);
            if ($fn === null) { return $this->builtinRefArgs($e, $env); }
            $params = $fn->params;
        } elseif ($e instanceof MethodCallExpr) {
            $classes = $this->receiverClasses($this->typer->typeOf($e->object, $env), $env);
            $params = $classes === null ? null : $this->methodParams($classes, $e->method);
            $all = $params === null;
        } elseif ($e instanceof StaticCall) {
            $cls = $this->classOf($e->class, $env);
            $params = $cls === null ? null : $this->methodParams([$cls], $e->method);
            $all = $params === null;
        } elseif ($e instanceof NewExpr) {
            $cls = $this->classOf($e->class, $env);
            if ($cls === null || $this->idx->findClass($cls) === null) {
                $all = true;
            } else {
                $params = $this->methodParams([$cls], '__construct');
                if ($params === null) { return $env; }
            }
        } else {
            return $env;
        }
        foreach ($e->args as $a) { if ($a instanceof NamedArg || $a instanceof Spread) { $all = true; } }
        $out = $env;
        foreach ($e->args as $i => $a) {
            $byRef = $all;
            if (!$byRef && $params !== null) {
                $last = \count($params) - 1;
                $p = $params[$i] ?? ($last >= 0 && $params[$last]->variadic ? $params[$last] : null);
                $byRef = $p !== null && $p->byRef;
            }
            if (!$byRef) { continue; }
            if ($a instanceof NamedArg || $a instanceof Spread) { $a = $a->value; }
            if (TypeEnv::pathKey($a) !== null || $a instanceof ArrayAccess) { $out = $this->store($a, null, $out); }
        }
        return $out;
    }

    /**
     * Parameters of $method over all $classes (a position is by-ref if any class says so); null when a
     * class or the method is unknown. A class without the method yields null, `__construct` included, so
     * the caller must check the class exists first.
     *
     * @param string[] $classes
     * @return \Parser\Ast\Param[]|null
     */
    private function methodParams(array $classes, string $method): ?array
    {
        $best = null;
        foreach ($classes as $c) {
            $m = $this->idx->findMethod($c, \strtolower($method), 0);
            if ($m === null) { return null; }
            if ($best === null) { $best = $m->params; continue; }
            foreach ($m->params as $i => $p) {
                if (!isset($best[$i]) || ($p->byRef && !$best[$i]->byRef)) { $best[$i] = $p; }
            }
        }
        return $best;
    }

    /**
     * @return string[]|null
     */
    private function receiverClasses(?SourceType $t, TypeEnv $env): ?array
    {
        if ($t === null) { return null; }
        $out = [];
        foreach (self::members($t) as $m) {
            if ($m->kind === SourceType::SCALAR && $m->name === 'null') { continue; }
            if ($m->kind === SourceType::CLASS_) { $out[] = \ltrim($m->name, '\\'); continue; }
            if (($m->kind === SourceType::SELF || $m->kind === SourceType::STATIC) && $env->thisClass !== null) { $out[] = $env->thisClass; continue; }
            return null;
        }
        return \count($out) === 0 ? null : $out;
    }

    /** @var array<string, int[]> by-reference argument positions of common builtins */
    private const BUILTIN_REF = [
        'preg_match' => [2], 'preg_match_all' => [2], 'preg_replace_callback' => [4], 'preg_replace' => [4],
        'str_replace' => [3], 'str_ireplace' => [3], 'similar_text' => [2], 'exec' => [1, 2], 'settype' => [0],
        'sort' => [0], 'rsort' => [0], 'usort' => [0], 'uasort' => [0], 'uksort' => [0], 'asort' => [0], 'arsort' => [0],
        'ksort' => [0], 'krsort' => [0], 'natsort' => [0], 'natcasesort' => [0], 'shuffle' => [0], 'array_multisort' => [0],
        'array_push' => [0], 'array_pop' => [0], 'array_shift' => [0], 'array_unshift' => [0], 'array_splice' => [0],
        'array_walk' => [0], 'end' => [0], 'reset' => [0], 'next' => [0], 'prev' => [0],
        'stream_select' => [0, 1, 2], 'sscanf' => [2, 3, 4], 'fscanf' => [2, 3, 4],
    ];

    private function builtinRefArgs(CallExpr $e, TypeEnv $env): TypeEnv
    {
        $pos = self::BUILTIN_REF[self::bareFn($e->function)] ?? null;
        if ($pos === null) { return $env; }
        $out = $env;
        foreach ($e->args as $i => $a) {
            if (\in_array($i, $pos, true) && (TypeEnv::pathKey($a) !== null || $a instanceof ArrayAccess)) { $out = $this->store($a, null, $out); }
        }
        return $out;
    }

    /** `unset`, `exit`, and calls that clobber the scope. */
    private function callEffects(CallExpr $e, TypeEnv $env): TypeEnv
    {
        $l = self::bareFn($e->function);
        if ($l === 'exit' || $l === 'die') { return self::dead($env); }
        if ($l === 'unset') {
            $out = $env->copy();
            foreach ($e->args as $a) {
                // unset($arr['k']) leaves the array's type alone
                if ($a instanceof Variable || $a instanceof PropertyAccess) {
                    $k = TypeEnv::pathKey($a);
                    if ($k !== null && $k !== 'this') { $out->forget($k); }
                }
            }
            return $out;
        }
        if ($l === 'extract' || $l === 'parse_str' || $l === 'eval' || $l === '__mc_require_value') { return self::onlyThis($env); }
        return $env;
    }

    /** Variable name at the base of `$a->b->c()` / `$a->b()->c`, or null. */
    private static function rootVar(Expr $e): ?string
    {
        while (true) {
            if ($e instanceof Variable) { return $e->name; }
            if ($e instanceof PropertyAccess || $e instanceof MethodCallExpr) { $e = $e->object; }
            elseif ($e instanceof ArrayAccess) { $e = $e->array; }
            else { return null; }
        }
    }

    private function matchExpr(MatchExpr $e, TypeEnv $env): TypeEnv
    {
        $env = $this->expr($e->subject, $env);
        $isTrue = $e->subject instanceof BoolLiteral && $e->subject->value;
        $cur = $env;
        $out = self::dead($env);
        foreach ($e->arms as $arm) {
            if ($arm->conds === null) {
                $in = $cur;
            } else {
                $in = self::dead($env);
                $next = $cur;
                foreach ($arm->conds as $c) {
                    if ($isTrue) {
                        [$t, $f] = $this->cond($c, $next);
                        $in = self::join($in, $t);
                        $next = $f;
                    } else {
                        $next = $this->expr($c, $next);
                        $in = self::join($in, $next);
                    }
                }
                if ($isTrue) { $cur = $next; }
            }
            $out = self::join($out, $this->expr($arm->body, $in));
        }
        return $out;
    }

    /**
     * @param Closure|ArrowFn $e
     */
    private function closureExpr(Expr $e, TypeEnv $env): TypeEnv
    {
        $out = $env;
        if ($e instanceof Closure) {
            foreach ($e->uses as $u) {
                if ($u->byRef) {
                    $this->aliased[$u->name] = true;
                    $out = $out === $env ? $env->copy() : $out;
                    $out->forget($u->name);
                }
            }
        }
        if ($this->silent === 0 && $this->found === null && self::contains($e, $this->target)) {
            $this->enterClosure($e, $env);
        }
        return $out;
    }

    /**
     * @param Closure|ArrowFn $e
     */
    private function enterClosure(Expr $e, TypeEnv $env): void
    {
        $owner = isset($this->rebound[\spl_object_id($e)]) ? '' : ($env->thisClass ?? '');
        $bound = $e->isStatic || $env->isStatic || $owner === '' ? '' : $owner;
        $stmts = $e instanceof Closure ? $e->body->statements : [];
        $body = $e instanceof ArrowFn ? $e->body : null;
        $unit = new Unit('{closure}', $e->params, $bound, $stmts, $body, $e->returnType, [], $owner);
        $inner = TypeEnv::forUnit($unit, $this->idx);
        if ($e instanceof Closure) {
            foreach ($e->uses as $u) {
                $t = $env->vars[$u->name] ?? null;
                if (!$u->byRef && $t !== null) { $inner->vars[$u->name] = $t; }
            }
        } else {
            $params = [];
            foreach ($e->params as $p) { $params[$p->name] = true; }
            foreach ($env->vars as $k => $t) {
                $name = (string)$k;
                if ($name === 'this' || isset($params[$name]) || isset($inner->vars[$name]) || \strpos($name, '->') !== false) { continue; }
                $inner->vars[$name] = $t;
            }
        }
        $saved = [$this->brk, $this->cnt, $this->sw, $this->tryAcc, $this->loopDepth];
        $this->brk = [];
        $this->cnt = [];
        $this->sw = [];
        $this->tryAcc = [];
        $this->loopDepth = 0;
        $this->runUnit($unit, $inner);
        $this->brk = $saved[0];
        $this->cnt = $saved[1];
        $this->sw = $saved[2];
        $this->tryAcc = $saved[3];
        $this->loopDepth = $saved[4];
    }

    private static function contains(Expr $outer, Expr $t): bool
    {
        $a = $outer->span;
        $b = $t->span;
        if ($a instanceof RangedSpan && $b instanceof RangedSpan && $a->endLine() !== 0 && $b->endLine() !== 0) {
            if ($b->startLine() < $a->startLine() || ($b->startLine() === $a->startLine() && $b->startColumn() < $a->startColumn())) { return false; }
            return $b->endLine() < $a->endLine() || ($b->endLine() === $a->endLine() && $b->endColumn() <= $a->endColumn());
        }
        $w = new AstWalk();
        $w->exprTree($outer);
        foreach ($w->exprs as $x) { if ($x === $t) { return true; } }
        return false;
    }

    // ── conditions ────────────────────────────────────────────────────

    /**
     * The env when $c is true and when it is false, after evaluating $c.
     *
     * @return TypeEnv[] [whenTrue, whenFalse]
     */
    private function cond(Expr $c, TypeEnv $env): array
    {
        if ($this->found !== null) { return [$env, $env]; }
        if ($c === $this->target && $this->silent === 0) { $this->found = $env; return [$env, $env]; }
        if ($c instanceof UnaryOp && $c->op === '!') {
            [$t, $f] = $this->cond($c->operand, $env);
            return [$f, $t];
        }
        if ($c instanceof BinaryOp) {
            $op = $c->op;
            if ($op === '&&' || $op === 'and') {
                [$ta, $fa] = $this->cond($c->left, $env);
                [$tb, $fb] = $this->cond($c->right, $ta);
                return [$tb, self::join($fa, $fb)];
            }
            if ($op === '||' || $op === 'or') {
                [$ta, $fa] = $this->cond($c->left, $env);
                [$tb, $fb] = $this->cond($c->right, $fa);
                return [self::join($ta, $tb), $fb];
            }
        }
        $after = $this->expr($c, $env);
        if ($this->found !== null) { return [$after, $after]; }
        return $this->leaf($c, $after);
    }

    /**
     * @return TypeEnv[]
     */
    private function leaf(Expr $c, TypeEnv $env): array
    {
        if ($c instanceof InstanceofExpr) {
            $cls = $this->classOf($c->class, $env);
            $subj = self::subject($c->operand);
            if ($cls === null || $subj === null) { return [$env, $env]; }
            return [$this->narrow($env, $subj, 'inst', $cls, true), $this->narrow($env, $subj, 'inst', $cls, false)];
        }
        if ($c instanceof BinaryOp && ($c->op === '===' || $c->op === '!==' || $c->op === '==' || $c->op === '!=')) {
            $subj = null;
            if ($c->right instanceof NullLiteral) { $subj = self::subject($c->left); }
            elseif ($c->left instanceof NullLiteral) { $subj = self::subject($c->right); }
            if ($subj === null) { return [$env, $env]; }
            $isEq = $c->op === '===' || $c->op === '==';
            $strict = $c->op === '===' || $c->op === '!==';
            $isNull = $this->narrow($env, $subj, $strict ? 'null' : 'falsy', '', true);
            $notNull = $this->narrow($env, $subj, 'nonnull', '', true);
            return $isEq ? [$isNull, $notNull] : [$notNull, $isNull];
        }
        if ($c instanceof CallExpr) {
            $fn = self::bareFn($c->function);
            if ($fn === 'isset') {
                $t = $env;
                foreach ($c->args as $a) {
                    $s = self::subject($a);
                    if ($s !== null) { $t = $this->narrow($t, $s, 'nonnull', '', true); }
                }
                return [$t, $env];
            }
            if (\count($c->args) === 1) {
                $s = self::subject($c->args[0]);
                if ($s === null) { return [$env, $env]; }
                if ($fn === 'empty') { return [$env, $this->narrow($env, $s, 'truthy', '', true)]; }
                if ($fn === 'is_null') { return [$this->narrow($env, $s, 'null', '', true), $this->narrow($env, $s, 'nonnull', '', true)]; }
                $kind = self::predicateKind($fn);
                if ($kind !== '') { return [$this->narrow($env, $s, 'is', $kind, true), $this->narrow($env, $s, 'is', $kind, false)]; }
            }
            return [$env, $env];
        }
        $subj = self::subject($c);
        if ($subj !== null) {
            return [$this->narrow($env, $subj, 'truthy', '', true), $this->narrow($env, $subj, 'falsy', '', true)];
        }
        return [$env, $env];
    }

    /** The variable / property path a condition operand narrows (an assignment narrows its target). */
    private static function subject(Expr $e): ?Expr
    {
        if ($e instanceof Assign) { $e = $e->target; }
        return TypeEnv::pathKey($e) === null ? null : $e;
    }

    /** Lower-case function name without its namespace (a namespaced call to a global builtin is written with the prefix). */
    private static function bareFn(string $name): string
    {
        $bs = \strrpos($name, '\\');
        return \strtolower($bs === false ? $name : \substr($name, $bs + 1));
    }

    private static function predicateKind(string $fn): string
    {
        switch ($fn) {
            case 'is_int': case 'is_integer': case 'is_long': return 'int';
            case 'is_string': return 'string';
            case 'is_float': case 'is_double': return 'float';
            case 'is_bool': return 'bool';
            case 'is_array': return 'array';
            case 'is_object': return 'object';
            default: return '';
        }
    }

    private function classOf(string $written, TypeEnv $env): ?string
    {
        $low = \strtolower($written);
        if ($low === 'self' || $low === 'static') { return $env->thisClass; }
        if ($low === 'parent') { return $env->parentClass; }
        $c = \ltrim($written, '\\');
        return $c === '' ? null : $c;
    }

    /**
     * Copy of $env with the subject's type refined by one recognised test; $env itself when nothing
     * can be said. $mode: inst | null | nonnull | falsy | truthy | is; $pos is the test's outcome
     * (for null / nonnull / falsy / truthy the mode already names the outcome and $pos is true).
     */
    private function narrow(TypeEnv $env, Expr $subj, string $mode, string $arg, bool $pos): TypeEnv
    {
        $key = TypeEnv::pathKey($subj);
        if ($key === null || $key === 'this') { return $env; }
        $cur = $this->typer->typeOf($subj, $env);
        $new = self::refine($cur, $mode, $arg, $pos, $this->idx);
        if ($new === null) { return $env; }
        $out = $env->copy();
        $out->vars[$key] = $new;
        return $out;
    }

    private static function refine(?SourceType $cur, string $mode, string $arg, bool $pos, Index $idx): ?SourceType
    {
        if ($mode === 'null') { return ExprTyper::scalar('null'); }
        if ($mode === 'nonnull' || $mode === 'truthy') {
            if ($cur === null || (self::isNullType($cur))) { return null; }
            return ExprTyper::withoutNull($cur);
        }
        if ($mode === 'falsy') {
            // an object is always truthy: a nullable class type that is falsy is null
            if ($cur === null) { return null; }
            foreach (self::members($cur) as $m) {
                $isNull = $m->kind === SourceType::SCALAR && $m->name === 'null';
                if (!$isNull && $m->kind !== SourceType::CLASS_) { return null; }
            }
            return ExprTyper::scalar('null');
        }
        if ($mode === 'inst') {
            if ($pos) {
                $kept = [];
                // against a class every instance IS that class, so non-subtypes drop out; against an interface
                // (or a name the index does not know) a non-subtype class may still implement it, so the member
                // is kept unchanged (the typer cannot resolve a `Foo&Shape` intersection, so none is built)
                $ci = $idx->findClass($arg);
                $exact = $ci !== null && $ci->kind !== 'interface';
                if ($cur !== null) {
                    foreach (self::members($cur) as $m) {
                        if ($m->kind !== SourceType::CLASS_) { continue; }
                        if (self::isSub($m->name, $arg, $idx) || !$exact) { $kept[] = $m; }
                    }
                }
                return \count($kept) > 0 ? ExprTyper::union($kept) : new SourceType(SourceType::CLASS_, $arg);
            }
            if ($cur === null) { return null; }
            $rest = [];
            $removed = false;
            foreach (self::members($cur) as $m) {
                if ($m->kind === SourceType::CLASS_ && self::isSub($m->name, $arg, $idx)) { $removed = true; } else { $rest[] = $m; }
            }
            return $removed ? ExprTyper::union($rest) : null;
        }
        if ($mode === 'is') {
            if ($pos) {
                $kept = [];
                if ($cur !== null) {
                    foreach (self::members($cur) as $m) { if (self::isKind($m, $arg)) { $kept[] = $m; } }
                }
                if (\count($kept) > 0) { return ExprTyper::union($kept); }
                return $arg === 'object' ? null : ExprTyper::scalar($arg);
            }
            if ($cur === null) { return null; }
            $rest = [];
            $removed = false;
            foreach (self::members($cur) as $m) {
                if (self::isKind($m, $arg)) { $removed = true; } else { $rest[] = $m; }
            }
            return $removed ? ExprTyper::union($rest) : null;
        }
        return null;
    }

    private static function isNullType(SourceType $t): bool
    {
        return $t->kind === SourceType::SCALAR && $t->name === 'null';
    }

    private static function isKind(SourceType $m, string $kind): bool
    {
        if ($kind === 'object') { return $m->kind === SourceType::CLASS_; }
        if ($kind === 'array') {
            return ($m->kind === SourceType::SCALAR && $m->name === 'array')
                || (($m->kind === SourceType::GENERIC || $m->kind === SourceType::ITERABLE) && ($m->name === 'array' || $m->name === 'list'));
        }
        return $m->kind === SourceType::SCALAR && $m->name === $kind;
    }

    /**
     * @return SourceType[] the union members of $t (a nullable contributes its inner type and `null`)
     */
    private static function members(SourceType $t): array
    {
        if ($t->kind === SourceType::NULLABLE) { return [$t->args[0], ExprTyper::scalar('null')]; }
        if ($t->kind === SourceType::UNION) { return $t->args; }
        return [$t];
    }

    private static function isSub(string $a, string $b, Index $idx): bool
    {
        return self::subRec(\strtolower(\ltrim($a, '\\')), \strtolower(\ltrim($b, '\\')), $idx, 0);
    }

    private static function subRec(string $a, string $b, Index $idx, int $depth): bool
    {
        if ($a === $b) { return true; }
        if ($depth > 20) { return false; }
        $ci = $idx->findClass($a);
        if ($ci === null) { return false; }
        foreach ($ci->parents as $p) { if (self::subRec(\strtolower(\ltrim($p, '\\')), $b, $idx, $depth + 1)) { return true; } }
        foreach ($ci->interfaces as $p) { if (self::subRec(\strtolower(\ltrim($p, '\\')), $b, $idx, $depth + 1)) { return true; } }
        return false;
    }

    /**
     * Key and value type of iterating $t; null where unknown.
     *
     * @return (SourceType|null)[]
     */
    private static function elementTypes(?SourceType $t): array
    {
        if ($t === null || ($t->kind !== SourceType::GENERIC && $t->kind !== SourceType::ITERABLE)) { return [null, null]; }
        $n = \count($t->args);
        if ($n === 0) { return [null, null]; }
        if ($n >= 2) { return [$t->args[0], $t->args[$n - 1]]; }
        return [$t->name === 'list' ? ExprTyper::scalar('int') : null, $t->args[0]];
    }
}
