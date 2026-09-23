<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Call;
use Compile\Mir\FunctionDef;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * `#[Overload('f')]` — retarget a call to `f` onto a typed overload whose
 * parameters the call's arguments statically FIT ({@see \Manticore\Attr\Overload}).
 *
 * The canonical `f` keeps php's union signature and dispatches at runtime, so it
 * is always correct; an overload only makes the answer PRECISE — `preg_replace`
 * over three strings returns `string`, which the canonical
 * `array|string|null` cannot say. A call is retargeted only when every argument
 * is concretely typed and matches its parameter exactly; anything erased,
 * spread or named stays on the canonical function.
 *
 * Runs after argument types are inferred and before the InferTypes run that
 * follows it, which re-derives the call's type from the new callee.
 */
final class ResolveOverloads implements Pass
{
    public const NAME = 'resolve-overloads';

    /** @var array<string, FunctionDef[]> canonical name → its overloads, declaration order */
    private array $overloads = [];

    public function name(): string { return self::NAME; }

    public function requires(): array { return [InferTypes::NAME]; }

    public function run(Module $module): Module
    {
        $this->overloads = [];
        foreach ($module->functions as $fn) {
            if ($fn->overloadOf === '') { continue; }
            $this->overloads[$fn->overloadOf][] = $fn;
        }
        if ($this->overloads !== []) {
            foreach ($module->functions as $fn) {
                // The canonical body and the overloads themselves call each
                // other by name on purpose; leave their calls as written.
                if ($fn->overloadOf !== '' || isset($this->overloads[$fn->name])) { continue; }
                $this->visit($fn->body);
            }
        }
        $this->overloads = [];
        $module->markPassApplied(self::NAME);
        return $module;
    }

    private function visit(Node $n): void
    {
        if ($n->kind === Node::KIND_CALL) { $this->resolve($n); }
        foreach (Walk::children($n) as $c) { $this->visit($c); }
    }

    private function resolve(Call $call): void
    {
        $cands = $this->overloads[$call->function] ?? null;
        if ($cands === null) { return; }
        // The MOST SPECIFIC fitting overload: the one that pins the most
        // parameters to a concrete type. A list-taking helper whose params are
        // `array|string` fits a three-string call too, and must not shadow the
        // all-string body. Ties keep declaration order.
        $best = null;
        $bestScore = -1;
        foreach ($cands as $ov) {
            if (!$this->fits($call->args, $ov->params)) { continue; }
            $score = $this->specificity($ov->params);
            if ($score > $bestScore) { $best = $ov; $bestScore = $score; }
        }
        if ($best === null) { return; }
        $call->function = $best->name;
        $call->type = $best->returnType;
    }

    /** @param Param[] $params */
    private function specificity(array $params): int
    {
        $n = 0;
        foreach ($params as $p) {
            $k = $p->type->kind;
            if ($k !== Type::KIND_CELL && $k !== Type::KIND_UNKNOWN) { $n = $n + 1; }
        }
        return $n;
    }

    /**
     * @param Node[]  $args
     * @param Param[] $params
     */
    private function fits(array $args, array $params): bool
    {
        $argc = \count($args);
        if ($argc > \count($params)) { return false; }
        foreach ($params as $i => $p) {
            if ($p->variadic) { return false; }
            if ($i >= $argc) {
                if ($p->default === null) { return false; }
                continue;
            }
            $a = $args[$i];
            if ($a->kind === Node::KIND_SPREAD) { return false; }
            if (!$this->argFits($a->type, $p->type)) { return false; }
        }
        return true;
    }

    /** `$given` is concrete and is exactly what a `$want` parameter holds. */
    private function argFits(Type $given, Type $want): bool
    {
        $wk = $want->kind;
        if ($wk === Type::KIND_CELL || $wk === Type::KIND_UNKNOWN) { return true; }
        $gk = $given->kind;
        if ($gk === Type::KIND_CELL || $gk === Type::KIND_UNKNOWN || $gk === Type::KIND_UNION
            || $gk === Type::KIND_TYPEVAR) {
            return false;
        }
        if ($wk !== $gk) { return false; }
        if ($wk === Type::KIND_OBJ) { return ($given->class ?? '') === ($want->class ?? ''); }
        if ($wk !== Type::KIND_ARRAY) { return true; }
        // An array parameter whose elements the overload does not constrain
        // takes any array; a typed one wants the same element kind.
        $we = $want->element;
        if ($we === null || $we->kind === Type::KIND_CELL || $we->kind === Type::KIND_UNKNOWN) { return true; }
        $ge = $given->element;
        return $ge !== null && $ge->kind === $we->kind;
    }
}
