<?php

namespace Compile\Mir;

/**
 * How much of the module inference left erased, as one `MANTICORE_STATS` line:
 * the yardstick for a change to inference. Fewer `cell` / `unknown` nodes and
 * fewer arrays with an erased element mean fewer boxes in the emitted code.
 */
final class PrecisionCensus
{
    private int $nodes = 0;
    private int $cell = 0;
    private int $unknown = 0;
    private int $arrays = 0;
    private int $arraysErased = 0;

    public static function line(Module $module): string
    {
        $c = new self();
        $params = 0;
        $paramsErased = 0;
        $returns = 0;
        $returnsErased = 0;
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $c->walk($fn->body);
            foreach ($fn->params as $p) {
                $params = $params + 1;
                if (self::erased($p->type)) { $paramsErased = $paramsErased + 1; }
            }
            $returns = $returns + 1;
            if (self::erased($fn->returnType)) { $returnsErased = $returnsErased + 1; }
        }
        $props = 0;
        $propsErased = 0;
        foreach ($module->classes as $cd) {
            foreach ($cd->propertyTypes as $t) {
                $props = $props + 1;
                if (self::erased($t)) { $propsErased = $propsErased + 1; }
            }
        }
        return 'precision: nodes=' . (string)$c->nodes
            . ' cell=' . (string)$c->cell
            . ' unknown=' . (string)$c->unknown
            . ' arrays=' . (string)$c->arrays
            . ' arraysErased=' . (string)$c->arraysErased
            . ' params=' . (string)$params . '/' . (string)$paramsErased
            . ' returns=' . (string)$returns . '/' . (string)$returnsErased
            . ' props=' . (string)$props . '/' . (string)$propsErased;
    }

    /** A `cell`, `unknown`, or an array whose element is either. */
    private static function erased(Type $t): bool
    {
        if ($t->kind === Type::KIND_CELL || $t->kind === Type::KIND_UNKNOWN) { return true; }
        return $t->kind === Type::KIND_ARRAY && self::erasedElement($t);
    }

    private static function erasedElement(Type $t): bool
    {
        $e = $t->element;
        return $e === null || $e->kind === Type::KIND_CELL || $e->kind === Type::KIND_UNKNOWN;
    }

    private function walk(Node $n): void
    {
        $this->nodes = $this->nodes + 1;
        $t = $n->type;
        if ($t->kind === Type::KIND_CELL) {
            $this->cell = $this->cell + 1;
        } elseif ($t->kind === Type::KIND_UNKNOWN) {
            $this->unknown = $this->unknown + 1;
        } elseif ($t->kind === Type::KIND_ARRAY) {
            $this->arrays = $this->arrays + 1;
            if (self::erasedElement($t)) { $this->arraysErased = $this->arraysErased + 1; }
        }
        foreach (Walk::children($n) as $c) { $this->walk($c); }
    }
}
