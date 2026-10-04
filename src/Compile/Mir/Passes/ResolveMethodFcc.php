<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Block;
use Compile\Mir\FunctionDef;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\NullConst;
use Compile\Mir\NodeClone;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\Invoke_;
use Compile\Mir\Return_;
use Compile\Mir\Walk;
use Compile\Mir\Type;

/**
 * Give a first-class callable `$recv->m(...)` the parameters of the method it
 * forwards to, once inference has typed the receiver.
 *
 * Lowering has no local types, so for any receiver but `$this` it cannot name
 * the method, and it builds a one-parameter placeholder
 * ({@see FunctionDef::$fccMethod}): `$c = $r->two(...); $c('a', 'b')` called
 * `two('a')`. After the first inference the captured receiver has a class; the
 * closure is rebuilt here with the method's own parameters (by-ref, variadic
 * and defaults included), so every argument is forwarded and an omitted one
 * takes the method's default — what the lowering does when it can see the
 * class. A receiver that stays erased (an interface, `mixed`) keeps the
 * placeholder: no single declaration speaks for its arity.
 */
final class ResolveMethodFcc implements Pass
{
    public const NAME = 'resolve-method-fcc';

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        $pending = false;
        foreach ($module->functions as $fn) {
            if ($fn->fccMethod !== '') { $pending = true; break; }
        }
        if (!$pending) {
            if ($module->namedInvokes) { $this->bindNamedInvokes($module); }
            return $module;
        }
        /** @var array<string, FunctionDef> $byName */
        $byName = [];
        /** @var array<string, FunctionDef> $byLower */
        $byLower = [];
        foreach ($module->functions as $fn) {
            $byName[$fn->name] = $fn;
            $byLower[\strtolower($fn->name)] = $fn;
        }
        $n = \count($module->functions);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $fn = $module->functions[$i];
            if ($fn->fccMethod === '') { continue; }
            $cls = $this->receiverClass($fn);
            $target = null;
            if ($cls !== '') {
                $target = $this->findMethod($module, $byName, $byLower, $cls, $fn->fccMethod);
            }
            if ($target === null) {
                // An interface / abstract / erased receiver: no one declaration
                // speaks, but every implementer shares the arity and by-ref-ness
                // (php enforces it), so the callable takes the merged signature
                // of the candidates — a variadic spread cannot carry a reference.
                $target = $this->mergedCandidates($module, $byName, $byLower, $cls, $fn->fccMethod);
            }
            if ($target === null) { continue; }
            $module->functions[$i] = $this->rebuild($fn, $target);
        }
        if ($module->namedInvokes) { $this->bindNamedInvokes($module); }
        return $module;
    }

    private function bindNamedInvokes(Module $module): void
    {
        /** @var array<string, FunctionDef> $byName */
        $byName = [];
        foreach ($module->functions as $fn) { $byName[$fn->name] = $fn; }
        foreach ($module->functions as $fn) { $this->bindNamedIn($module, $byName, $fn->body); }
    }

    /** @param array<string, FunctionDef> $byName */
    private function bindNamedIn(Module $module, array $byName, Node $n): void
    {
        if ($n instanceof Invoke_ && \count($n->argNames) > 0) {
            $this->bindNamedInvoke($module, $byName, $n);
        }
        foreach (Walk::children($n) as $c) { $this->bindNamedIn($module, $byName, $c); }
    }

    /**
     * Reorder the arguments of `$closure(c: 9, a: 1)` into parameter order once
     * the callee closure is known: a gap takes the parameter's default. A name
     * the closure does not declare leaves the call as lowered.
     *
     * @param array<string, FunctionDef> $byName
     */
    private function bindNamedInvoke(Module $module, array $byName, Invoke_ $inv): void
    {
        $cn = $inv->callee->type->class ?? '';
        if ($cn === '' || !isset($byName[$cn]) || !isset($module->closureCaptures[$cn])) { return; }
        $params = $byName[$cn]->params;
        $cap = $module->closureCaptures[$cn];
        $np = \count($params) - $cap;
        // Dense parallel slots: a sparse int-key isset is unreliable self-host.
        /** @var Node[] $slots */
        $slots = [];
        /** @var bool[] $set */
        $set = [];
        for ($j = 0; $j < $np; $j = $j + 1) {
            $slots[] = new NullConst(Type::null_());
            $set[] = false;
        }
        $pos = 0;
        $i = 0;
        foreach ($inv->args as $a) {
            $nm = $inv->argNames[$i] ?? '';
            $i = $i + 1;
            if ($nm === '') {
                if ($pos >= $np || $params[$cap + $pos]->variadic) { return; }
                $slots[$pos] = $a;
                $set[$pos] = true;
                $pos = $pos + 1;
                continue;
            }
            $hit = -1;
            for ($j = 0; $j < $np; $j = $j + 1) {
                if ($params[$cap + $j]->name === $nm && !$params[$cap + $j]->variadic) { $hit = $j; break; }
            }
            if ($hit < 0 || $set[$hit]) { return; }
            $slots[$hit] = $a;
            $set[$hit] = true;
        }
        $last = -1;
        for ($j = 0; $j < $np; $j = $j + 1) { if ($set[$j]) { $last = $j; } }
        $out = [];
        for ($j = 0; $j <= $last; $j = $j + 1) {
            if ($set[$j]) { $out[] = $slots[$j]; continue; }
            $d = $params[$cap + $j]->default;
            if ($d === null) { return; }
            $out[] = NodeClone::node($d);
        }
        $inv->args = $out;
        $inv->argNames = [];
    }

    /** The class inference gave the captured receiver, or ''. */
    private function receiverClass(FunctionDef $fn): string
    {
        $stmts = $fn->body->stmts;
        if (\count($stmts) !== 1) { return ''; }
        $ret = $stmts[0];
        if (!($ret instanceof Return_)) { return ''; }
        $mc = $ret->value;
        if (!($mc instanceof MethodCall_)) { return ''; }
        $t = $mc->object->type;
        if ($t->kind !== Type::KIND_OBJ) { return ''; }
        $c = $t->class;
        return $c === null ? '' : $c;
    }

    /**
     * The FunctionDef that implements `$method` for `$cls`, walking up the
     * parents — the one a call on that receiver dispatches to.
     *
     * @param array<string, FunctionDef> $byName
     * @param array<string, FunctionDef> $byLower
     */
    private function findMethod(Module $module, array $byName, array $byLower, string $cls, string $method): ?FunctionDef
    {
        $c = $cls;
        $guard = 0;
        while ($c !== '' && $guard < 64) {
            $key = $c . '__' . $method;
            $hit = $byName[$key] ?? ($byLower[\strtolower($key)] ?? null);
            if ($hit !== null) { return $hit; }
            $cd = $module->classes[$c] ?? null;
            if ($cd === null) { return null; }
            $c = $cd->parent;
            $guard = $guard + 1;
        }
        return null;
    }

    /** Is `$c` the class `$of`, or does it extend / implement it? */
    private function isA(Module $module, string $c, string $of, int $depth = 0): bool
    {
        if ($c === $of) { return true; }
        if ($depth > 16) { return false; }
        $cd = $module->classes[$c] ?? null;
        if ($cd === null) { return false; }
        if ($cd->parent !== '' && $this->isA($module, $cd->parent, $of, $depth + 1)) { return true; }
        foreach ($cd->interfaces as $ifc) {
            if ($this->isA($module, (string)$ifc, $of, $depth + 1)) { return true; }
        }
        return false;
    }

    /**
     * One synthetic signature standing for every implementation of `$method`
     * a receiver of static class `$cls` ('' = erased: every class) can reach:
     * the longest parameter list, a parameter by-ref when any candidate takes
     * it by reference, its type the candidates' common one (else a cell).
     *
     * @param array<string, FunctionDef> $byName
     * @param array<string, FunctionDef> $byLower
     */
    private function mergedCandidates(Module $module, array $byName, array $byLower, string $cls, string $method): ?FunctionDef
    {
        /** @var FunctionDef[] $cands */
        $cands = [];
        foreach ($module->classes as $cn => $cd) {
            if ($cls !== '' && !$this->isA($module, (string)$cn, $cls)) { continue; }
            $hit = $this->findMethod($module, $byName, $byLower, (string)$cn, $method);
            if ($hit === null) { continue; }
            $dup = false;
            foreach ($cands as $c0) { if ($c0 === $hit) { $dup = true; break; } }
            if (!$dup) { $cands[] = $hit; }
        }
        if (\count($cands) === 0) { return null; }
        if ($cls === '') {
            // An erased receiver may be a class this module does not declare
            // (a builtin with a same-named method): the user candidates speak
            // for it only where the variadic forwarder would crash — a
            // reference parameter.
            $anyRef = false;
            foreach ($cands as $c3) {
                foreach ($c3->params as $q3) { if ($q3->byRef) { $anyRef = true; } }
            }
            if (!$anyRef) { return null; }
        }
        $best = $cands[0];
        foreach ($cands as $c1) {
            if (\count($c1->params) > \count($best->params)) { $best = $c1; }
        }
        $params = [$best->params[0]];
        $k = \count($best->params);
        for ($j = 1; $j < $k; $j = $j + 1) {
            $p = $best->params[$j];
            $t = $p->siteRefinedFrom !== null ? $p->siteRefinedFrom : $p->type;
            $ref = $p->byRef;
            $same = true;
            foreach ($cands as $c2) {
                if (!isset($c2->params[$j])) { continue; }
                $q = $c2->params[$j];
                if ($q->byRef) { $ref = true; }
                $qt = $q->siteRefinedFrom !== null ? $q->siteRefinedFrom : $q->type;
                if ($qt->kind !== $t->kind) { $same = false; }
            }
            if (!$same && !$ref) { $t = Type::cell(); }
            $np = new Param(name: $p->name, type: $t, byRef: $ref, variadic: $p->variadic,
                default: $p->default === null ? null : NodeClone::node($p->default));
            $np->arrayHinted = $p->arrayHinted;
            $params[] = $np;
        }
        return new FunctionDef(
            name: $best->name,
            params: $params,
            returnType: $best->returnType,
            body: $best->body,
            isPrelude: $best->isPrelude,
        );
    }
    private function rebuild(FunctionDef $fn, FunctionDef $target): FunctionDef
    {
        $recv = $fn->params[0];
        $params = [$recv];
        $loads = [];
        $tp = $target->params;
        $k = \count($tp);
        // params[0] of a method is `$this`.
        for ($j = 1; $j < $k; $j = $j + 1) {
            $p = $tp[$j];
            $t = $p->siteRefinedFrom !== null ? $p->siteRefinedFrom : $p->type;
            $def = $p->default === null ? null : NodeClone::node($p->default);
            $np = new Param(name: $p->name, type: $t, byRef: $p->byRef, variadic: $p->variadic, default: $def);
            $np->arrayHinted = $p->arrayHinted;
            $params[] = $np;
            $loads[] = new LoadLocal($p->name, $t);
        }
        $obj = new LoadLocal($recv->name, Type::unknown());
        $call = new MethodCall_($obj, $fn->fccMethod, $loads, Type::unknown());
        $out = new FunctionDef(
            name: $fn->name,
            params: $params,
            returnType: $fn->returnType,
            body: new Block([new Return_($call, Type::void())], Type::void()),
            isPrelude: $fn->isPrelude,
        );
        return $out;
    }
}
