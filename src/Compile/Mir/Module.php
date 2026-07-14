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

    /** `#[TypeDef]` value types — class name → its descriptor. Deliberately NOT
     *  in {@see $classes}: a TypeDef has no runtime form (no class id, no header,
     *  no drop fn), so nothing that walks the class list may see it. It lives
     *  here only so InferTypes can resolve a method's return type and EmitLlvm
     *  can route `$c->value` / `$byte->method()` on an erased receiver.
     *  @var array<string, ClassDef> */
    public array $typeDefs = [];

    /** @var array<string, true> declared interface names (no ClassDef — used by
     *  the compile-time `interface_exists` fold). */
    public array $interfaceNames = [];

    /** @var array<string, true> declared trait names (compile-time
     *  `trait_exists` fold). */
    public array $traitNames = [];

    /** @var array<string, int> closure fn name → number of captured values */
    public array $closureCaptures = [];

    /** @var array<string, bool> closure fn name → capture slot 0 is `$this`
     *  (struct slot 1) — where Closure::bind/->bindTo/->call inject the object. */
    public array $closureHasThis = [];

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

    /** The program asks for a stack trace (an exception trace query or a
     *  backtrace call): emit the runtime call-stack and instrument every user
     *  call with push/pop. Off by default so a program that never asks pays zero
     *  per-call cost. Gated in Main on the source text (kept free of the literal
     *  needles here so a self-build does not trip its own gate). */
    public bool $needsBacktrace = false;

    /** Source file path, for exception file() / trace frames. */
    public string $sourceFile = '';

    /** Method FunctionDef name ("Class__method") → backtrace frame display
     *  ("Class->method" / "Class::method"). Built at lowering (stable string
     *  ops); EmitLlvm stamps the correct name at a method's entry, because the
     *  call-site receiver-class read drifts under the self-host.
     *  @var array<string, string> — the @var pins the string value type; a
     *  bare `array` erases it (values read back as raw pointer ints). */
    public array $methodDisplay = [];

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
