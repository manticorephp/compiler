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

    /** Resolved source path → the global slot holding that file's top-level
     *  `return` value, i.e. what `require`/`include` of it evaluates to.
     *  Only files that actually return are listed; everything else answers
     *  php's `int(1)`.
     *  @var array<string, string> */
    public array $includeSlots = [];

    /** Every function name `function_exists()` should answer true for, for a
     *  program that asks with a NON-literal argument. Filtered through the same
     *  predicate the literal fold uses, so the two forms cannot disagree.
     *  Empty unless the program actually asks dynamically.
     *  @var string[] */
    public array $knownFnNames = [];

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
     * Whether each cell belongs to the PRELUDE, parallel to $globalNames.
     *
     * The prelude is compiled into EVERY module, so a prelude class's static
     * prop is DEFINED by both stdlib.o and the user's .o — at default (external)
     * linkage that is `ld: duplicate symbols`, and every user program linking
     * the stdlib fails. `linkonce_odr` coalesces the two definitions to one
     * address, which is also the only way the counter stays SINGLE: two copies
     * would hand out the same ids from independent sequences.
     *
     * Mirrors {@see FunctionDef::$isPrelude}, which already does this for the
     * bodies. Precedent for the linkage on a mutable global: `@__manticore_argc`
     * / `@__manticore_argv` ({@see EmitLlvmModule::emitPreamble}).
     * @var bool[]
     */
    public array $globalIsPrelude = [];

    /**
     * Whether each cell is DEFINED ELSEWHERE — a static property of a class
     * imported from a library `.sig` — parallel to $globalNames.
     *
     * Deliberately NOT the prelude's `linkonce_odr` treatment. PHP gives a
     * class ONE static slot, and two coalescable definitions carrying different
     * initialisers leave which one survives to the linker; on a slot the
     * library's own constructor writes, that is a counter that silently forgets
     * increments. The library `.o` owns the definition, so this module emits a
     * declaration and links to it.
     * @var bool[]
     */
    public array $globalIsExtern = [];

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

    /** Some slot in this module is a REFERENCE CELL — a `&` whose result had to
     *  become a storable VALUE ({@see docs/design/reference-cells.md}), not just
     *  an address. A module-level fact so the inline cell-tag paths can consult
     *  it BEFORE emit starts; a demand flag set during emit ({@see
     *  RuntimeFeatures::$needsCellKey}) is answered too late for that. */
    public bool $hasRefCells = false;

    /** The error/shutdown prelude is compiled in, so main() registers the
     *  atexit trampoline that drains register_shutdown_function's queue and the
     *  uncaught path offers the Throwable to a set_exception_handler() first.
     *  Off for every program that never touches them — no hook, no cost. */
    public bool $needsErrorHandlers = false;

    /** prelude/ob.php is compiled in, so main() registers the atexit trampoline
     *  that flushes any buffer still open at exit (php's own behaviour). */
    public bool $needsOb = false;

    /** This module generated `__mir_obj_to_str` — a dispatcher with one
     *  `instanceof` arm per class declaring `__toString`, written from the
     *  finished class table.
     *
     *  Needed because `@__manticore_tagged_to_str` is ONE external body living
     *  in the central core (stdlib.o), so it cannot know a user module's
     *  classes and has no object arm at all: `(string)$cell` on an object cell
     *  used to render the tagged word itself. The tag-8 branch is therefore
     *  emitted at the CALL SITE, where the module knows whether the dispatcher
     *  exists — never inside the shared body, which would specialize a symbol
     *  the whole program links once. */
    public bool $hasObjToStr = false;

    /** Source file path, for exception file() / trace frames. */
    public string $sourceFile = '';

    /** Classes needing reflection metadata, or ALL when
     *  {@see $reflectAll}. Filled by {@see Passes\ReflectAnalysis}; read by the
     *  emitter to decide which classes get an rmeta block, tables and a registry
     *  ctor. A class outside the set keeps `ptr null` in its descriptor.
     *  @var array<string, bool> */
    public array $reflectNames = [];

    /** Something reflected on a name the analysis could not resolve, so every
     *  class needs metadata. Also the state before the pass runs, which keeps
     *  any path that skips it correct-but-fat rather than silently wrong. */
    public bool $reflectAll = true;

    /** Dynamic method calls need compiler-owned class metadata and uniform
     *  method trampolines, even when the program never mentions Reflection. */
    public bool $needsDynamicMethodMeta = false;

    /** Method FunctionDef name ("Class__method") → backtrace frame display
     *  ("Class->method" / "Class::method"). Built at lowering (stable string
     *  ops); EmitLlvm stamps the correct name at a method's entry, because the
     *  call-site receiver-class read drifts under the self-host.
     *  @var array<string, string> — the @var pins the string value type; a
     *  bare `array` erases it (values read back as raw pointer ints). */
    public array $methodDisplay = [];

    /** @var array<string, \Compile\Mir\MethodMeta> free functions a
     *  `new ReflectionFunction('f')` names literally → their declared shape (Ф5).
     *  Each gets an `@__mc_fnmeta_<f>` metadata row + an invoke trampoline + a
     *  registry entry. A MethodMeta (not a class member) reuses the method row /
     *  param-table emission machinery unchanged. Declared LAST — a new field
     *  mid-struct shifts later offsets, a self-host layout hazard. */
    public array $reflFnMeta = [];

    /** Free function name → the `#[\Deprecated]` diagnostic body php prints
     *  ("Function f() is deprecated since 1.5, use g() instead"). Emitted at
     *  every CALL site, where the file and line are compile-time constants.
     *  @var array<string, string> */
    public array $deprecatedFns = [];
    /** "DeclaringClass::method" → the same, for methods and static methods.
     *  Keyed by the DECLARING class so an inherited call resolves.
     *  @var array<string, string> */
    public array $deprecatedMethods = [];
    /** Free function name → the `#[\NoDiscard]` warning body.
     *  @var array<string, string> */
    public array $noDiscardFns = [];
    /** "DeclaringClass::method" → the `#[\NoDiscard]` warning body.
     *  @var array<string, string> */
    public array $noDiscardMethods = [];

    /** "<declClass>|<kind>|<member>|<k>" → the \Error message
     *  ReflectionAttribute::newInstance() must throw for that attribute use.
     *  php validates a USERLAND attribute's target / repeatability only when the
     *  instance is asked for, so the verdict is computed at lowering (where the
     *  attribute class's own `#[Attribute(flags)]` is still readable) and baked
     *  into the metadata row.
     *  @var array<string, string> */
    public array $attrSiteErrors = [];

    /**
     * The return type each function was DECLARED with, by name — before any
     * pass adopted a narrower one into {@see FunctionDef::$returnType} (which
     * is rewritten in place). A monomorphic clone needs it: the generic's
     * adopted return describes the GENERIC body, and inheriting it makes the
     * specialization's signature disagree with its own returns — `array_sum`'s
     * `int|float` narrowed to `int` for the erased body, and the vec[float]
     * clone then summed doubles behind an `-> int` signature.
     * @var array<string, Type>
     */
    public array $declaredReturnTypes = [];

    /** Register a global cell once (idempotent by name). $isPrelude →
     *  linkonce_odr; $isExtern → a declaration, defined in a dependency's `.o`. */
    public function addGlobalCell(string $name, Node $default,
                                  bool $isPrelude = false, bool $isExtern = false): void
    {
        foreach ($this->globalNames as $existing) {
            if ($existing === $name) { return; }
        }
        $this->globalNames[] = $name;
        $this->globalDefaults[] = $default;
        $this->globalIsPrelude[] = $isPrelude;
        $this->globalIsExtern[] = $isExtern;
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
        $this->claimClassId($class->classId, $class->name);
        $this->classes[$class->name] = $class;
    }

    public function addEnum(EnumDef $enum): void
    {
        $this->claimClassId($enum->classId, $enum->name);
        $this->enums[$enum->name] = $enum;
    }

    /** class_id → the FQN that owns it, for the collision check below.
     *  @var array<int, string> */
    private array $classIdOwner = [];

    /**
     * FQN → the AST declaration of a type this module may EXPORT, populated
     * only when building a library ({@see \Manticore\Sig::emitModule} reads it).
     *
     * Lowering keeps a {@see ClassDef} for the layout and an {@see EnumDef} for
     * the cases, but an INTERFACE gets neither — it lives on as a name in
     * {@see $interfaceNames} and nothing else — and no MIR structure anywhere
     * records a type's CONSTANTS, which are inlined at each use site. The
     * declaration is the only thing that still knows both.
     *
     * Prelude types are never recorded: they are compiled into every module
     * already, so exporting one would hand a dependent a second definition of
     * a class it also holds.
     *
     * @var array<string, \Parser\Ast\ClassDecl>
     */
    public array $typeDecls = [];

    /**
     * `"<FQN>::<CONST>"` → the const-folded MIR value of a class constant, and
     * plain `"<NAME>"` → that of a global one, for library export.
     *
     * FLAT and separate rather than one nested map per class: an assoc of
     * assocs erases to KIND_UNKNOWN under the self-host, so every read would
     * come back raw. Populated at lowering, where `self::OTHER` still resolves.
     *
     * @var array<string, Node>
     */
    public array $classConstValues = [];

    /** @var array<string, Node> */
    public array $globalConstValues = [];

    /**
     * A class id is a content hash of the fully-qualified name
     * ({@see Passes\LowerFromAst::stableClassId}) — which is what makes it the
     * same in every compiled object, and therefore what makes a `.o` boundary
     * safe. Two DIFFERENT names hashing alike is the one way that guarantee
     * fails, and every consequence of it is silent: the wrong `drop` body, an
     * `instanceof` that answers yes, a virtual-dispatch arm entered with the
     * wrong layout.
     *
     * Classes and enums share one id space — `instanceof` matches across both —
     * so one map covers them.
     */
    private function claimClassId(int $id, string $name): void
    {
        $owner = $this->classIdOwner[$id] ?? '';
        if ($owner !== '' && $owner !== $name) {
            throw new \RuntimeException(
                'manticore: class-id collision — ' . $owner . ' and ' . $name
                . ' both hash to ' . (string)$id
                . "\n  rename one of them (the id is a hash of the fully-qualified name).");
        }
        $this->classIdOwner[$id] = $name;
    }

    /**
     * True when this module is a LIBRARY target — its classes go into a `.sig`
     * and an importing module may do anything with them.
     *
     * The one question a whole-module analysis has to ask before it may act on
     * "nobody borrows this": whether "nobody" can be answered at all. A library's
     * answer is always "unknown", so any such analysis must decline here.
     * {@see Passes\EmitLlvm::$propRawBorrow} is the first caller.
     */
    public bool $isLibraryModule = false;

    public function markPassApplied(string $name): void
    {
        $this->passesApplied[$name] = true;
    }

    public function hasPassApplied(string $name): bool
    {
        return isset($this->passesApplied[$name]);
    }
}
