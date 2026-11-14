<?php
namespace Compile\Mir;

/**
 * Flow-insensitive value sets of one function's locals, for {@see Reachability}: the set of strings a local
 * (or an expression over locals) can hold, or null when it cannot be proven a complete literal set.
 *
 * A local has a set only when EVERY write to it is a string literal, a ternary / match of such, a
 * concatenation of such, or a read of another such local, and nothing can write it behind the analysis'
 * back: a parameter, `global` / `static`, a reference binding (`=&`, `[&$x]`, `use (&$x)`, `foreach as &$x`),
 * an argument that may be by-reference, `++` / `--`, an element store (`$s[0] = 'x'`), a `foreach` / `catch`
 * target and `extract()` / `get_defined_vars()` all taint it. Any doubt taints.
 */
final class LocalValueSets
{
    private const MAX_SET = 32;

    /** @var array<string, Node[]> local => the value of every StoreLocal into it */
    private array $stores = [];
    /** @var array<string, bool> */
    private array $taint = [];
    /** Something in the body may write any local (`extract`, `get_defined_vars`). */
    private bool $all = false;
    /** @var array<string, array<string, bool>|null> */
    private array $memo = [];
    /** @var array<string, bool> */
    private array $busy = [];

    private function asLoad(Node $n): LoadLocal { return $n; }
    private function asStore(Node $n): StoreLocal { return $n; }
    private function asString(Node $n): StringConst { return $n; }
    private function asRefAlias(Node $n): RefAlias_ { return $n; }
    private function asRefBind(Node $n): RefBind_ { return $n; }
    private function asRefAddr(Node $n): RefAddr_ { return $n; }
    private function asStaticDecl(Node $n): StaticLocalDecl_ { return $n; }
    private function asForeach(Node $n): Foreach_ { return $n; }
    private function asIncDec(Node $n): IncDec { return $n; }
    private function asClosure(Node $n): Closure_ { return $n; }
    private function asCall(Node $n): Call { return $n; }
    private function asMethodCall(Node $n): MethodCall_ { return $n; }
    private function asStaticCall(Node $n): StaticCall_ { return $n; }
    private function asInvoke(Node $n): Invoke_ { return $n; }
    private function asNewObj(Node $n): NewObj { return $n; }
    private function asNewDyn(Node $n): NewDynObj { return $n; }
    private function asStoreElement(Node $n): StoreElement { return $n; }
    private function asTry(Node $n): TryCatch_ { return $n; }
    private function asConcat(Node $n): Concat { return $n; }
    private function asTernary(Node $n): Ternary { return $n; }
    private function asMatch(Node $n): Match_ { return $n; }
    private function asBlock(Node $n): Block { return $n; }
    private function asMatchArm(MatchArm_ $a): MatchArm_ { return $a; }

    /** @param array<string, FunctionDef> $fns every module function by symbol (by-ref parameters of a callee) */
    public static function of(FunctionDef $fd, array $fns, bool $hasUnits): self
    {
        $s = new self();
        // An include unit shares the top-level scope with `__main` and with other units: any of their locals may be rewritten.
        if ($hasUnits && ($fd->name === '__main' || \str_starts_with($fd->name, '__mc_unit_'))) { $s->all = true; }
        foreach ($fd->params as $p) { $s->taint[$p->name] = true; }
        $s->scan($fd->body, $fns);
        return $s;
    }

    /** Every string `$e` can evaluate to, as keys; null when unknown. @return array<string, bool>|null */
    public function valueSet(Node $e): ?array
    {
        $k = $e->kind;
        if ($k === Node::KIND_STRING_CONST) { return [$this->asString($e)->value => true]; }
        if ($k === Node::KIND_LOAD_LOCAL) { return $this->localSet($this->asLoad($e)->name); }
        if ($k === Node::KIND_STORE_LOCAL) { return $this->valueSet($this->asStore($e)->value); }
        if ($k === Node::KIND_BLOCK) {
            $st = $this->asBlock($e)->stmts;
            return \count($st) === 0 ? null : $this->valueSet($st[\count($st) - 1]);
        }
        if ($k === Node::KIND_CONCAT) {
            $c = $this->asConcat($e);
            $l = $this->valueSet($c->left);
            $r = $this->valueSet($c->right);
            if ($l === null || $r === null || \count($l) * \count($r) > self::MAX_SET) { return null; }
            $out = [];
            foreach ($l as $a => $_) {
                foreach ($r as $b => $_2) { $out[(string)$a . (string)$b] = true; }
            }
            return $out;
        }
        if ($k === Node::KIND_TERNARY) {
            $t = $this->asTernary($e);
            $a = $t->then === null ? $this->valueSet($t->cond) : $this->valueSet($t->then);
            $b = $this->valueSet($t->else_);
            return $this->union($a, $b);
        }
        if ($k === Node::KIND_MATCH) {
            $out = [];
            foreach ($this->asMatch($e)->arms as $arm) {
                $out = $this->union($out, $this->valueSet($this->asMatchArm($arm)->body));
                if ($out === null) { return null; }
            }
            return \count($out) === 0 ? null : $out;
        }
        // `$c::$m()` guards its class with `is_object($c) ? get_class($c) : $c`; a literal set is never an object.
        if ($k === Node::KIND_CALL) {
            $c = $this->asCall($e);
            if (\strtolower($c->function) === 'get_class' && \count($c->args) === 1) { return $this->valueSet($c->args[0]); }
        }
        return null;
    }

    /**
     * @param array<string, bool>|null $a
     * @param array<string, bool>|null $b
     * @return array<string, bool>|null
     */
    private function union(?array $a, ?array $b): ?array
    {
        if ($a === null || $b === null) { return null; }
        $out = $a;
        foreach ($b as $s => $_) { $out[(string)$s] = true; }
        return \count($out) > self::MAX_SET ? null : $out;
    }

    /** @return array<string, bool>|null */
    private function localSet(string $name): ?array
    {
        if ($this->all || isset($this->taint[$name]) || !isset($this->stores[$name])) { return null; }
        if (\array_key_exists($name, $this->memo)) { return $this->memo[$name]; }
        if (isset($this->busy[$name])) { return null; }
        $this->busy[$name] = true;
        /** @var array<string, bool>|null $out */
        $out = [];
        foreach ($this->stores[$name] as $v) {
            $out = $this->union($out, $this->valueSet($v));
            if ($out === null) { break; }
        }
        unset($this->busy[$name]);
        $this->memo[$name] = $out;
        return $out;
    }

    /** Taint every local read anywhere under `$n`. */
    private function taintReads(Node $n): void
    {
        /** @var Node[] $stack */
        $stack = [$n];
        while (\count($stack) > 0) {
            $x = \array_pop($stack);
            if ($x->kind === Node::KIND_LOAD_LOCAL) { $this->taint[$this->asLoad($x)->name] = true; }
            foreach (Walk::children($x) as $c) { $stack[] = $c; }
        }
    }

    /** A call argument that is a bare local may be bound by reference. @param Node[] $args */
    private function taintBareArgs(array $args): void
    {
        foreach ($args as $a) {
            if ($a->kind === Node::KIND_LOAD_LOCAL) { $this->taint[$this->asLoad($a)->name] = true; }
        }
    }

    /** @param array<string, FunctionDef> $fns */
    private function scan(Node $root, array $fns): void
    {
        /** @var Node[] $stack */
        $stack = [$root];
        while (\count($stack) > 0) {
            $n = \array_pop($stack);
            $k = $n->kind;
            if ($k === Node::KIND_STORE_LOCAL) {
                $s = $this->asStore($n);
                $this->stores[$s->name][] = $s->value;
                // An include / require inside this body binds this function's locals to the included file.
                if (\str_starts_with($s->name, '__mc_inc_')) { $this->all = true; }
            } elseif ($k === Node::KIND_REF_ALIAS) {
                $a = $this->asRefAlias($n);
                $this->taint[$a->target] = true;
                $this->taint[$a->source] = true;
            } elseif ($k === Node::KIND_REF_BIND) {
                $this->taint[$this->asRefBind($n)->target] = true;
            } elseif ($k === Node::KIND_REF_ADDR) {
                $a = $this->asRefAddr($n);
                $this->taint[$a->target] = true;
                $this->taintReads($a->lvalue);
            } elseif ($k === Node::KIND_REF_CELL) {
                $this->taintReads($n);
            } elseif ($k === Node::KIND_STATIC_LOCAL_DECL) {
                $this->taint[$this->asStaticDecl($n)->name] = true;
            } elseif ($k === Node::KIND_FOREACH) {
                $f = $this->asForeach($n);
                $this->taint[$f->valueVar] = true;
                if ($f->keyVar !== null) { $this->taint[$f->keyVar] = true; }
                if ($f->byRef) { $this->taintReads($f->array); }
            } elseif ($k === Node::KIND_INCDEC) {
                $this->taint[$this->asIncDec($n)->name] = true;
            } elseif ($k === Node::KIND_CLOSURE) {
                $cl = $this->asClosure($n);
                foreach ($cl->captures as $i => $cap) {
                    if ($cl->captureByRef[$i] ?? true) { $this->taintReads($cap); }
                }
            } elseif ($k === Node::KIND_STORE_ELEMENT) {
                $this->taintReads($this->asStoreElement($n)->array);
            } elseif ($k === Node::KIND_TRY_CATCH) {
                foreach ($this->asTry($n)->catches as $c) {
                    if ($c->var !== null) { $this->taint[$c->var] = true; }
                }
            } elseif ($k === Node::KIND_CALL) {
                $c = $this->asCall($n);
                $fn = $c->function;
                if (\str_starts_with($fn, '__mc_require') || \str_starts_with($fn, '__mc_include') || $fn === 'extract' || $fn === 'get_defined_vars' || \str_starts_with($fn, '__mc_extract')) { $this->all = true; }
                $def = $fns[$fn] ?? null;
                if ($def === null) {
                    $this->taintBareArgs($c->args);
                } else {
                    $np = \count($def->params);
                    foreach ($c->args as $i => $a) {
                        if ($a->kind !== Node::KIND_LOAD_LOCAL) { continue; }
                        $p = $i < $np ? $def->params[$i] : ($np > 0 ? $def->params[$np - 1] : null);
                        if ($p === null || $p->byRef || $p->refPack || $p->variadic) { $this->taint[$this->asLoad($a)->name] = true; }
                    }
                }
            } elseif ($k === Node::KIND_METHOD_CALL) {
                $this->taintBareArgs($this->asMethodCall($n)->args);
            } elseif ($k === Node::KIND_STATIC_CALL) {
                $this->taintBareArgs($this->asStaticCall($n)->args);
            } elseif ($k === Node::KIND_INVOKE) {
                $this->taintBareArgs($this->asInvoke($n)->args);
            } elseif ($k === Node::KIND_NEW_OBJ) {
                $this->taintBareArgs($this->asNewObj($n)->args);
            } elseif ($k === Node::KIND_NEW_DYN_OBJ) {
                $this->taintBareArgs($this->asNewDyn($n)->args);
            }
            foreach (Walk::children($n) as $c) { $stack[] = $c; }
        }
    }
}
