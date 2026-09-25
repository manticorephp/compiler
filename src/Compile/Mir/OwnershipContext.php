<?php

namespace Compile\Mir;

/**
 * What {@see Ownership} reads to answer "is this value +1". Built once per
 * module from the module alone ({@see fromModule}), so the pass and the
 * emitter hand the classifier the same facts.
 *
 * `$fn` is the function being classified; the pass and the emitter set it as
 * they enter a body. Nothing the classifier answers today depends on it.
 */
final class OwnershipContext
{
    public ?Module $module = null;

    public ?FunctionDef $fn = null;

    /** @var array<string, ClassDef> class name → layout */
    public array $classes = [];

    /** @var array<string, EnumDef> enum name → case table (values are non-rc) */
    public array $enums = [];

    /** @var array<string, bool[]> fn name → per-param by-ref mask */
    public array $refMasks = [];

    /** @var array<string, bool> fn names with a body in this module — the
     *  evidence a user body (not a codegen builtin) answers a call */
    public array $definedFns = [];

    /** @var array<string, bool> FFI fn names (foreign, non-rc return) */
    public array $externFns = [];

    /** @var array<string, bool> fn name → returns by reference */
    public array $returnsByRef = [];

    /** @var string[] builtins whose result is a BORROW ({@see AliasOwn::borrowingBuiltins}) */
    public array $borrowingBuiltins = [];

    public static function fromModule(Module $module): self
    {
        $c = new self();
        $c->module = $module;
        $c->classes = $module->classes;
        $c->enums = $module->enums;
        foreach ($module->functions as $fn) {
            $mask = [];
            foreach ($fn->params as $p) { $mask[] = $p->byRef; }
            $c->refMasks[$fn->name] = $mask;
            $c->definedFns[$fn->name] = true;
            $c->returnsByRef[$fn->name] = $fn->returnsByRef;
            if ($fn->ffiSymbol !== null) { $c->externFns[$fn->name] = true; }
        }
        $c->borrowingBuiltins = AliasOwn::borrowingBuiltins();
        return $c;
    }
}
