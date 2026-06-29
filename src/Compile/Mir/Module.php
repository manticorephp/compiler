<?php

namespace Compile\Mir;

/**
 * Whole-program MIR unit. Holds every function (top-level `main`,
 * user-declared functions, future class methods) and a record of
 * which passes have already run.
 *
 * Pass tracking lets later passes assert preconditions and lets
 * `dump-mir --after=<pass>` show IR state at any pipeline point.
 */
final class Module
{
    /** @var FunctionDef[] */
    public array $functions = [];

    /** @var array<string, ClassDef> class name → layout descriptor */
    public array $classes = [];

    /** @var array<string, EnumDef> enum name → case table */
    public array $enums = [];

    /** @var array<string, true> declared interface names (no ClassDef — used by
     *  the compile-time `interface_exists` fold). */
    public array $interfaceNames = [];

    /** @var array<string, true> declared trait names (compile-time
     *  `trait_exists` fold). */
    public array $traitNames = [];

    /** @var array<string, int> closure fn name → number of captured values */
    public array $closureCaptures = [];

    /**
     * Module-level i64 global cells (static props, static locals,
     * `global` vars). Parallel arrays (name + literal default node) —
     * not a map of objects, which the self-host backend mishandles.
     * @var string[]
     */
    public array $globalNames = [];
    /** @var Node[] */
    public array $globalDefaults = [];

    /**
     * Names declared `global $x` anywhere — top-level (`__main`) reads
     * of these route to the shared `@g_<name>` cell too.
     * @var string[]
     */
    public array $globalVarNames = [];

    /** Register a global cell once (idempotent by name). */
    public function addGlobalCell(string $name, Node $default): void
    {
        foreach ($this->globalNames as $existing) {
            if ($existing === $name) { return; }
        }
        $this->globalNames[] = $name;
        $this->globalDefaults[] = $default;
    }

    /** Record a `global $name` declaration (idempotent). */
    public function addGlobalVarName(string $name): void
    {
        foreach ($this->globalVarNames as $existing) {
            if ($existing === $name) { return; }
        }
        $this->globalVarNames[] = $name;
    }

    /** @var array<string, true> Names of passes that have run. */
    public array $passesApplied = [];

    public function addFunction(FunctionDef $fn): void
    {
        $this->functions[] = $fn;
    }

    public function addClass(ClassDef $class): void
    {
        $this->classes[$class->name] = $class;
    }

    public function addEnum(EnumDef $enum): void
    {
        $this->enums[$enum->name] = $enum;
    }

    public function markPassApplied(string $name): void
    {
        $this->passesApplied[$name] = true;
    }

    public function hasPassApplied(string $name): bool
    {
        return isset($this->passesApplied[$name]);
    }
}
