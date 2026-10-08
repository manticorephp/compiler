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
use Compile\Mir\If_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Invoke_;
use Compile\Mir\Return_;
use Compile\Mir\Spread_;
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
                // An interface / abstract / erased receiver: no single declaration
                // speaks for it (see resolveMulti).
                $multi = $this->resolveMulti($fn, $module, $byName, $byLower, $cls, $fn->fccMethod);
                if ($multi !== null) { $module->functions[$i] = $multi; }
                continue;
            }
            $module->functions[$i] = $this->rebuild($fn, $target);
            if ($module->reflClosureWanted) {
                $mm = $this->methodMetaFor($module, $cls, $fn->fccMethod);
                if ($mm !== null) { $module->reflClosureMeta[$fn->name] = $mm; }
            }
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

    /** The reflection shape of `$cls::$method`, walking up the parents. */
    private function methodMetaFor(Module $module, string $cls, string $method): ?\Compile\Mir\MethodMeta
    {
        $c = $cls;
        $guard = 0;
        while ($c !== '' && $guard < 64) {
            $cd = $module->classes[$c] ?? null;
            if ($cd === null) { return null; }
            $hit = $cd->methodMeta[$method] ?? ($cd->methodMeta[\strtolower($method)] ?? null);
            if ($hit !== null) { return $hit; }
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
     * The callable for a receiver that no single declaration speaks for (an
     * interface, an abstract class, an erased `mixed`): `null` keeps the
     * variadic forwarder, which passes exactly the supplied arguments and lets
     * the actual callee apply its own defaults.
     *
     * A signature is materialised only where every implementer AGREES (same
     * arity, no defaults, same variadic-ness); when only the by-ref modes
     * differ, the callable takes the union of the modes and dispatches on the
     * receiver's actual class, each arm calling that class's own method with
     * its own modes — as do by-ref implementers whose parameter TYPES differ.
     * Implementers disagreeing on arity or defaults: {@see refPrefixForwarder}.
     *
     * @param array<string, FunctionDef> $byName
     * @param array<string, FunctionDef> $byLower
     */
    private function resolveMulti(FunctionDef $fn, Module $module, array $byName, array $byLower, string $cls, string $method): ?FunctionDef
    {
        /** @var FunctionDef[] $cands */
        $cands = [];
        /** @var string[] $candClass */
        $candClass = [];
        foreach ($module->classes as $cn => $cd) {
            if ($cls !== '' && !$this->isA($module, (string)$cn, $cls)) { continue; }
            $key = (string)$cn . '__' . $method;
            $hit = $byName[$key] ?? ($byLower[\strtolower($key)] ?? null);
            if ($hit === null) { continue; }
            $cands[] = $hit;
            $candClass[] = (string)$cn;
        }
        $nc = \count($cands);
        if ($nc === 0) { return null; }
        $first = $cands[0];
        $arity = \count($first->params);
        $anyRef = false;
        $modesDiffer = false;
        $typesDiffer = false;
        foreach ($cands as $c) {
            if (\count($c->params) !== $arity) { return $this->refPrefixForwarder($fn, $module, $cands, $candClass); }
            for ($j = 1; $j < $arity; $j = $j + 1) {
                $p = $c->params[$j];
                $q = $first->params[$j];
                if (($p->default !== null && $nc > 1) || $p->variadic !== $q->variadic) {
                    return $this->refPrefixForwarder($fn, $module, $cands, $candClass);
                }
                if ($p->byRef) { $anyRef = true; }
                if ($p->byRef !== $q->byRef) { $modesDiffer = true; }
                $pt = $p->siteRefinedFrom !== null ? $p->siteRefinedFrom : $p->type;
                $qt = $q->siteRefinedFrom !== null ? $q->siteRefinedFrom : $q->type;
                if ($pt->kind !== $qt->kind) { $typesDiffer = true; }
            }
        }
        // An erased receiver may be a class this module does not declare (a
        // builtin with a same-named method): speak for it only where the
        // variadic forwarder would crash — a reference parameter.
        if ($cls === '' && !$anyRef) { return null; }
        $params = [$fn->params[0]];
        for ($j = 1; $j < $arity; $j = $j + 1) {
            $p = $first->params[$j];
            $t = $p->siteRefinedFrom !== null ? $p->siteRefinedFrom : $p->type;
            if ($typesDiffer) { $t = Type::cell(); }
            $ref = false;
            foreach ($cands as $c) { if ($c->params[$j]->byRef) { $ref = true; } }
            // A lone implementer's defaults ARE the callee's own.
            $np = new Param(name: $p->name, type: $t, byRef: $ref, variadic: $p->variadic,
                default: ($nc === 1 && $p->default !== null) ? NodeClone::node($p->default) : null);
            $np->arrayHinted = $p->arrayHinted;
            $params[] = $np;
        }
        if (!$modesDiffer && !($typesDiffer && $anyRef)) {
            $sig = new FunctionDef(name: $first->name, params: $params, returnType: $first->returnType,
                body: $first->body, isPrelude: $first->isPrelude);
            return $this->rebuild($fn, $sig);
        }
        $order = $this->derivedFirst($module, $candClass);
        $recv = $fn->params[0];
        $loads = [];
        for ($j = 1; $j < $arity; $j = $j + 1) {
            $loads[] = new LoadLocal($params[$j]->name, $params[$j]->type);
        }
        $stmts = [];
        foreach ($order as $k) {
            $cn = $candClass[$k];
            $obj = new LoadLocal($recv->name, Type::obj($cn));
            $args = [];
            foreach ($loads as $l) { $args[] = new LoadLocal($l->name, $l->type); }
            $call = new MethodCall_($obj, $fn->fccMethod, $args, Type::unknown());
            $arm = new Block([new Return_($call, Type::void())], Type::void());
            $stmts[] = new If_(new Instanceof_(new LoadLocal($recv->name, Type::unknown()), $cn), $arm, null);
        }
        return new FunctionDef(
            name: $fn->name,
            params: $params,
            returnType: $fn->returnType,
            body: new Block($stmts, Type::void()),
            isPrelude: $fn->isPrelude,
        );
    }

    /**
     * Candidate indexes, most-derived classes first, so an override wins over
     * its parent's arm.
     *
     * @param string[] $candClass
     * @return int[]
     */
    private function derivedFirst(Module $module, array $candClass): array
    {
        $nc = \count($candClass);
        $order = [];
        for ($k = 0; $k < $nc; $k = $k + 1) { $order[] = $k; }
        for ($a = 0; $a < $nc; $a = $a + 1) {
            for ($b = $a + 1; $b < $nc; $b = $b + 1) {
                if ($this->isA($module, $candClass[$order[$b]], $candClass[$order[$a]])
                    && $candClass[$order[$b]] !== $candClass[$order[$a]]) {
                    $tmp = $order[$a]; $order[$a] = $order[$b]; $order[$b] = $tmp;
                }
            }
        }
        return $order;
    }

    /**
     * Implementers that disagree on arity or defaults, and some of them take
     * a parameter BY REFERENCE. The variadic forwarder would hand the callee
     * a copy in its pack (the write never reached the caller, and a by-ref
     * slot fed from a spread dereferenced the value). Declare the leading
     * positions up to the last by-ref one explicitly — by reference where any
     * implementer is — and forward the rest through the pack, so each callee
     * still applies its own defaults to what was not supplied. Differing modes
     * or types there dispatch on the receiver's class, each arm calling its own
     * method. `null` (keep
     * the variadic forwarder) when no implementer has a by-ref parameter, or
     * when a leading position is optional or variadic somewhere: an omitted
     * argument there cannot be told apart from a supplied one.
     *
     * @param FunctionDef[] $cands
     * @param string[] $candClass
     */
    private function refPrefixForwarder(FunctionDef $fn, Module $module, array $cands, array $candClass): ?FunctionDef
    {
        $lead = 0;
        foreach ($cands as $c) {
            $np = \count($c->params);
            for ($j = 1; $j < $np; $j = $j + 1) {
                if ($c->params[$j]->byRef && $j > $lead) { $lead = $j; }
            }
        }
        if ($lead === 0 || \count($fn->params) !== 2 || !$fn->params[1]->variadic) { return null; }
        $first = $cands[0];
        $needArms = false;
        foreach ($cands as $c) {
            for ($j = 1; $j <= $lead; $j = $j + 1) {
                $p = $c->params[$j] ?? null;
                if ($p === null || $p->variadic || $p->default !== null) { return null; }
                if ($p->byRef !== $first->params[$j]->byRef) { $needArms = true; }
            }
        }
        $params = [$fn->params[0]];
        $loads = [];
        for ($j = 1; $j <= $lead; $j = $j + 1) {
            $p = $first->params[$j];
            $t = $p->siteRefinedFrom !== null ? $p->siteRefinedFrom : $p->type;
            $ref = false;
            foreach ($cands as $c) {
                $q = $c->params[$j];
                if ($q->byRef) { $ref = true; }
                $qt = $q->siteRefinedFrom !== null ? $q->siteRefinedFrom : $q->type;
                if ($qt->kind !== $t->kind) { $t = Type::cell(); $needArms = true; }
            }
            $params[] = new Param(name: $p->name, type: $t, byRef: $ref, variadic: false, default: null);
            $loads[] = new LoadLocal($p->name, $t);
        }
        $rest = $fn->params[1];
        $params[] = $rest;
        $recv = $fn->params[0];
        if (!$needArms) {
            $call = new MethodCall_(new LoadLocal($recv->name, Type::unknown()), $fn->fccMethod, $this->forwardArgs($loads, $rest), Type::unknown());
            $stmts = [new Return_($call, Type::void())];
        } else {
            $stmts = [];
            foreach ($this->derivedFirst($module, $candClass) as $k) {
                $cn = $candClass[$k];
                $call = new MethodCall_(new LoadLocal($recv->name, Type::obj($cn)), $fn->fccMethod, $this->forwardArgs($loads, $rest), Type::unknown());
                $arm = new Block([new Return_($call, Type::void())], Type::void());
                $stmts[] = new If_(new Instanceof_(new LoadLocal($recv->name, Type::unknown()), $cn), $arm, null);
            }
        }
        return new FunctionDef(
            name: $fn->name,
            params: $params,
            returnType: $fn->returnType,
            body: new Block($stmts, Type::void()),
            isPrelude: $fn->isPrelude,
        );
    }

    /**
     * @param LoadLocal[] $loads
     * @return Node[]
     */
    private function forwardArgs(array $loads, Param $rest): array
    {
        $args = [];
        foreach ($loads as $l) { $args[] = new LoadLocal($l->name, $l->type); }
        $args[] = new Spread_(new LoadLocal($rest->name, $rest->type), Type::unknown());
        return $args;
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
