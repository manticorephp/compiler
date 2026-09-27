<?php

namespace Compile\Mir;

/**
 * What {@see Ownership} reads to answer "is this value +1". Built once per
 * module from the module alone ({@see fromModule}), so the pass and the
 * emitter hand the classifier the same facts.
 */
final class OwnershipContext
{
    /** @var array<string, ClassDef> class name → layout */
    public array $classes = [];

    /** @var array<string, EnumDef> enum name → case table (values are non-rc) */
    public array $enums = [];

    /** @var array<string, bool> RAW fn names with a FunctionDef in this module
     *  (FFI declarations included) — the evidence a body, not a codegen
     *  builtin, answers a call. Not EmitLlvm's `$definedFns`, which is mangled. */
    public array $moduleFns = [];

    /** @var array<string, bool> FFI fn names (foreign, non-rc return) */
    public array $externFns = [];

    /** @var array<string, bool> fn name → returns by reference */
    public array $returnsByRef = [];

    /** @var array<string, bool[]> fn name → per-param by-ref mask: a callee
     *  whose signature is KNOWN (a body in this module) */
    public array $paramByRef = [];

    /** @var array<string, bool> fn names (this module's and imported) that
     *  declare a bare `array` return: +1 on every path ({@see Ownership::erasedArrayReturn}) */
    public array $erasedArrayFns = [];

    /** @var string[] builtins whose result is a BORROW ({@see AliasOwn::borrowingBuiltins}) */
    public array $borrowingBuiltins = [];

    public static function fromModule(Module $module): self
    {
        $c = new self();
        $c->classes = $module->classes;
        $c->enums = $module->enums;
        foreach ($module->functions as $fn) {
            $c->moduleFns[$fn->name] = true;
            $c->returnsByRef[$fn->name] = $fn->returnsByRef;
            $mask = [];
            foreach ($fn->params as $p) { $mask[] = $p->byRef; }
            $c->paramByRef[$fn->name] = $mask;
            if (Ownership::erasedArrayReturn($fn)) { $c->erasedArrayFns[$fn->name] = true; }
            if ($fn->ffiSymbol !== null) { $c->externFns[$fn->name] = true; }
        }
        $c->borrowingBuiltins = AliasOwn::borrowingBuiltins();
        return $c;
    }
}
