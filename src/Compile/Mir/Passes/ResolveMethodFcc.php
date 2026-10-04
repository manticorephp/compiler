<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Block;
use Compile\Mir\FunctionDef;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\NodeClone;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\Return_;
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
        if (!$pending) { return $module; }
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
            if ($cls === '') { continue; }
            $target = $this->findMethod($module, $byName, $byLower, $cls, $fn->fccMethod);
            if ($target === null) { continue; }
            $module->functions[$i] = $this->rebuild($fn, $target);
        }
        return $module;
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
