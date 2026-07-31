<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\BitOp;
use Compile\Mir\BitNot_;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayElement_;
use Compile\Mir\ArrayLit;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\ClassDef;
use Compile\Mir\EnumDef;
use Compile\Mir\BoolConst;
use Compile\Mir\Break_;
use Compile\Mir\Call;
use Compile\Mir\Walk;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Continue_;
use Compile\Mir\Goto_;
use Compile\Mir\Label_;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
use Compile\Mir\FloatConst;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\For_;
use Compile\Mir\DoWhile_;
use Compile\Mir\IncDec;
use Compile\Mir\StaticProp_;
use Compile\Mir\StoreStaticProp_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\Isset_;
use Compile\Mir\Unset_;
use Compile\Mir\ClassName_;
use Compile\Mir\RefAlias_;
use Compile\Mir\RefBind_;
use Compile\Mir\RefAddr_;
use Compile\Mir\Throw_;
use Compile\Mir\Yield_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\NewDynObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\NullConst;
use Compile\Mir\Param;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\StringConst;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;
use Parser\Ast\Program;

/**
 * AST → MIR lowering. Phase A scope: literals, locals, arithmetic,
 * `echo`, `return`, plain function definitions, and direct calls.
 * Anything outside this surface raises so the migration tracks
 * coverage loudly instead of silently lowering to garbage.
 */
final class LowerFromAst implements Pass
{
    use LowerPrelude;
    use LowerClasses;
    use LowerStmts;
    use LowerFns;
    use LowerExprs;
    use LowerTypes;
    use LowerReify;
    use LowerTypeDefs;
    use LowerSuperglobals;
    use LowerAttrChecks;

    public const NAME = 'lower-from-ast';

    public function __construct(public readonly Program $program) {}

    public function name(): string { return self::NAME; }

    public function requires(): array { return []; }

    /** @var array<string, ClassDef> built during the class pre-pass */
    private array $classTable = [];

    /** Set by the store scan when a bare `array` property is written with a
     *  string key (→ assoc, not vec). Reset per property in buildClassDef. */
    private bool $propStoreStrKey = false;

    /** @var array<string, bool> every class name (known before defs are built) */
    private array $knownClassNames = [];

    /** `#[TypeDef]` classes ({@see LowerTypeDefs}): class name → its `repr`
     *  (`u8`, `f32`, … — '' when the attribute names none, which is a plain
     *  newtype over whatever its property declares).
     *  @var array<string, string> */
    private array $typeDefReprs = [];

    /** `#[TypeDef]` class name → its CARRIER (`int` / `float` / `string`). This is
     *  the membership test: a class is a TypeDef iff it has an entry here. Filled
     *  BEFORE any ClassDef is built — a class lowered earlier may already name one
     *  in a property or parameter hint.
     *  @var array<string, string> */
    private array $typeDefCarriers = [];

    /** `#[TypeDef]` class name → the name of its single value property.
     *  @var array<string, string> */
    private array $typeDefProps = [];

    /** `#[TypeDef]` classes that declare a `__invoke` NORMALISER — the function
     *  `new C($raw)` lowers to. Without one the class is the bare promoted-property
     *  shape and `new C($x)` is just `$x`.
     *  @var array<string, true> */
    private array $typeDefInvokes = [];

    /** The `#[TypeDef]` class whose method body is being lowered, or ''. Inside
     *  one, `$this` IS the carrier scalar — not an object pointer. */
    private string $currentTypeDefClass = '';

    /** @var array<string, string> unambiguous short class name → FQN */
    private array $shortClassFqn = [];

    /** @var array<string, bool> short names declared by 2+ classes (unresolvable) */
    private array $shortClassAmbiguous = [];

    /** Reified generic classes ({@see LowerReify}): spec class name → the generic
     *  class it specializes (`Box$of$float` → `Box`).
     *  @var array<string, string> */
    private array $reifyOrigin = [];

    /** Spec class name → the type-param substitution its members lower under
     *  (`Box$of$float` → `['T' => float]`).
     *  @var array<string, array<string, Type>> */
    private array $reifySubst = [];

    /** Spec classes whose methods are still to be lowered.
     *  @var array<string, \Parser\Ast\ClassDecl> */
    private array $reifyMethodQueue = [];

    /** Lowered method function name → the class whose body it is. Set in
     *  {@see lowerMethodFn}, the funnel every method (incl. the late-static-bound
     *  copies) passes through.
     *  @var array<string, string> */
    private array $methodOwner = [];

    /**
     * Namespace of the class / function whose signature or property
     * types are currently being lowered (e.g. `Compile\Mir` for
     * `Compile\Mir\Module`). Lets an unqualified type hint / `@var`
     * short name resolve to the same-namespace class first — the PHP
     * resolution rule — so a short name shared across namespaces
     * (`FunctionDef` in both `Codegen\Llvm` and `Compile\Mir`) still
     * resolves rather than collapsing to the erased fallback offset.
     */
    private string $currentDeclNamespace = '';

    /** Enclosing class while lowering a method body (self/static resolution). */
    private string $currentLowerClass = '';

    /** `@template` names declared by the class being lowered, in order.
     *  @var string[] */
    private array $currentTypeParams = [];

    /** Filled by {@see docTemplates}: type-param name → its `of X` bound hint.
     *  @var array<string, string> */
    private array $pendingTypeBounds = [];

    /** Filled by {@see docTemplates}: type-param name → its `= X` default hint.
     *  @var array<string, string> */
    private array $pendingTypeDefaults = [];

    /** Bounds of the class being lowered, as types — so `T` lowers to a typevar
     *  that KNOWS what it is bounded by, and erases into it.
     *  @var array<string, Type> */
    private array $currentTypeBounds = [];

    /** What the class being lowered binds its generic TRAITS to, from
     *  `/** @use Items<string> *\/ use Items;`.
     *
     *  A trait is COPIED into every class that uses it — unlike a generic class,
     *  which has ONE shared body and must therefore keep `T` erased. So the binding
     *  is substituted right at the source: `T` never becomes a typevar at all, it
     *  lowers STRAIGHT to `string`. The merged members come out fully CONCRETE —
     *  no cells, no boxing. This is the one place generics buy speed for free.
     *  @var array<string, Type> */
    private array $currentTypeSubst = [];

    /**
     * Late-static-binding scope while lowering a method body — the *called*
     * class for `static::`. Equals `$currentLowerClass` for the normal copy;
     * for an LSB specialization (`A__M__lsb<B>`) it is the descendant `B`.
     */
    private string $currentStaticClass = '';

    /**
     * Late-static-binding methods queued for per-descendant specialisation
     * (filled while lowering each class, drained by emitLsbSpecializations
     * once the whole class table is known).
     * @var LsbPending[]
     */
    private array $lsbPending = [];

    /** Enclosing function / method name (for __FUNCTION__ / __METHOD__). */
    private string $currentLowerFn = '';

    /** Set true while lowering a method body when `static::`/`new static` is seen. */
    private bool $sawStaticUse = false;

    /**
     * Straight-line callable const-propagation: a local assigned a callable
     * LITERAL (`$g = "strtoupper"` / `["C","m"]` / `[$o,"m"]`) is recorded so a
     * later `$g(...)` lowers to the direct call. Reset per function; cleared at
     * every control-flow boundary (sound — a branch may rebind) and when the
     * variable (or an array-callable's receiver) is reassigned.
     * @var array<string, array<string, mixed>>
     */
    private array $constCallables = [];

    /**
     * Callable-literal bindings that survive control flow — a variable assigned a
     * string / ternary-of-strings function name EXACTLY ONCE in the body, so its
     * invoke resolves to a direct call even across an intervening `if`/`try`
     * (which clears the straight-line {@see $constCallables}). Populated per body
     * by {@see scanStableCallables}.
     *
     * FLAT `var → first candidate name`, never a nested info array: the value is
     * read back inside the compiler's own hot path, and a nested assoc read out
     * of a property erases natively — the lookup then silently missed and the
     * out-param it exists to define stayed dangling (green under Zend, broken
     * self-hosted). Both str_set arms share the by-ref layout, so one name is
     * all this needs.
     * @var array<string, string>
     */
    private array $stableCallables = [];

    /** Declared parameter names of the body being lowered, in order. Backs the
     *  `func_num_args()` / `func_get_arg($k)` fold.
     *  @var string[] */
    private array $currentLowerParams = [];

    /** The str_set's SECOND candidate name, keyed the same way. Two flat maps,
     *  not one map of pairs — see {@see $stableCallables}.
     *  @var array<string, string> */
    private array $stableCallablesAlt = [];

    /** `$var = new C(...)` → C, for a linear body — lets a later `$var->m(a,b)`
     *  pack its variadic against C's exact signature (a same-named variadic
     *  method elsewhere with a different arity otherwise defers the pack). Dropped
     *  on any reassignment; same best-effort lifecycle as {@see $constCallables}.
     *  @var array<string, string> */
    private array $localNewClasses = [];

    /** Set true while lowering a body when a `yield` is seen (generator). */
    private bool $sawYield = false;

    /** Counter for unique `yield from` desugar loop variables. */
    private int $yieldFromCounter = 0;

    /** Counter for the permutation temp of each `array_multisort` desugar. */
    private int $multisortSeq = 0;

    /** @var array<string, EnumDef> enum name → case table (pre-pass) */
    private array $enumTable = [];

    /** @var array<string, \Parser\Ast\ClassDecl> trait name → decl (pre-pass) */
    private array $traitTable = [];

    /** @var array<string, \Parser\Ast\ClassDecl> class name → decl (for constants) */
    private array $classDecls = [];

    /** User constants from top-level `define("NAME", <const-expr>)`.
     *  @var array<string, \Parser\Ast\Expr> bare name → value expression */
    private array $userConstants = [];

    /**
     * Static-property registry keyed by "Class::prop". String-keyed
     * assoc lookups round-trip in self-host; reading back `string[]`
     * array *elements* off ClassDef does not, so resolution lives here
     * as a flat map instead of walking ClassDef.staticPropNames.
     * @var array<string, bool>
     */
    private array $staticProps = [];

    /** @var array<string, Type> `Class::prop` → declared static-prop type */
    private array $staticPropTypes = [];

    /** The Throwable hierarchy, read by Main from `prelude/exceptions.php`.
     *  Unconditional — every program can `throw`. */
    public string $exceptionsSrc = '';
    /** \Resource — unconditional: the .sig carries no classes, so every module needs its own copy. */
    public string $resourceSrc = '';
    /** \Fiber — DEMAND-GATED (empty unless the program mentions Fiber): its
     *  presence flips needsFibers, which emits arch-branched module asm. */
    public string $fiberSrc = '';
    /** Io\Poll — DEMAND-GATED (empty unless the program mentions it). Namespaced
     *  class tree in braced `namespace {}` blocks. */
    public string $ioPollSrc = '';
    /** Error / exception handlers + the shutdown queue — DEMAND-GATED. Non-empty
     *  also means main() gets the atexit trampoline and the uncaught path
     *  consults a user handler; {@see needsErrorHandlers}. */
    public string $errorsSrc = '';
    /** ob_* — DEMAND-GATED. Non-empty also means main() gets the atexit
     *  trampoline that drains any buffer still open at exit. */
    public string $obSrc = '';
    /** pack/unpack — DEMAND-GATED. */
    public string $binarySrc = '';
    /** Nesting depth of the `@` suppression operator around the expression being
     *  lowered — read by the `trigger_error` rewrite ({@see LowerExprs}). */
    private int $silenceDepth = 0;
    /** The file name a diagnostic names, for the `trigger_error` rewrite. */
    private string $lowerSourceFile = '';
    /** Async\ (scheduler / tasks / channels) — DEMAND-GATED. Braced-namespace
     *  tree like io_poll.php; implies fiberSrc + ioPollSrc. */
    public string $asyncSrc = '';
    /** ext/pcntl + posix process control — DEMAND-GATED. Braced-namespace tree;
     *  Async\ implies it (the scheduler dispatches signals every tick). */
    public string $pcntlSrc = '';
    /** True while the class-registration loop is inside the prelude window —
     *  {@see LowerClasses} reads it so a prelude class's static-prop cell is
     *  emitted linkonce_odr (the prelude lands in EVERY module, so external
     *  linkage means stdlib.o and user.o both define it → duplicate symbol). */
    private bool $inPreludeClass = false;
    /** `__mir_bt_frames`: read by Main from `prelude/backtrace.php` when the
     *  program queries a trace, else from the `prelude/backtrace_stub.php`
     *  one-liner. Exactly one of the two, always — `exceptions.php` calls it. */
    public string $backtraceSrc = '';
    public bool $includeVarDump = false;
    /** `__mir_var_dump` prelude source, read by Main from `prelude/var_dump.php`. */
    public string $varDumpSrc = '';
    /** var_export() — DEMAND-GATED on `var_export(`. Pulls in the recursive
     *  walker AND the per-class `__mir_export_object` arms generated below. The
     *  codegen builtin still formats a statically-typed scalar inline and never
     *  reaches either. */
    public bool $includeVarExport = false;
    /** `__mir_var_export` prelude source, read by Main from `prelude/var_export.php`. */
    public string $varExportSrc = '';
    public bool $includePrintR = false;
    /** print_r prelude source, read by Main from `prelude/print_r.php`. */
    public string $printRSrc = '';
    /** serialize() — DEMAND-GATED on `serialize(`. Pulls in the hand-written
     *  walker AND the per-class `__mc_ser_object` arms generated below. */
    public bool $includeSerialize = false;
    /** serialize prelude source, read by Main from `prelude/serialize.php`. */
    public string $serializeSrc = '';
    /** unserialize() — DEMAND-GATED on `unserialize(`, SEPARATELY from
     *  serialize: the per-class rebuild arms cost roughly twice the writer's. */
    public bool $includeUnserialize = false;
    /** unserialize prelude source, read by Main from `prelude/unserialize.php`. */
    public string $unserializeSrc = '';
    /** Inject the built-in SPL ArrayIterator / ArrayObject classes (gated on
     *  the user program referencing them — see Main.php). */
    public bool $includeArrayClasses = false;
    /** SPL array-class prelude source, read by Main from `prelude/spl_arrays.php`. */
    public string $arrayClassesSrc = '';
    /** Inject ReflectionClass / ReflectionException (gated on the program
     *  MENTIONING one — see Main.php). Decides whether the classes exist, NOT
     *  which classes carry metadata: that is ReflectAnalysis's job, because
     *  PreludeDemand cannot see a name hidden in a string literal. */
    public bool $includeReflection = false;
    /** Reflection prelude source, read by Main from `prelude/reflection.php`. */
    public string $reflectionSrc = '';
    /** Inject PHP's reserved attribute classes (Attribute / Deprecated / Override
     *  / …). Gated on a mention AND on reflection, since their only runtime role
     *  is `getAttributes()->newInstance()`; the SEMANTICS are compiler-side and
     *  need none of this. See Main.php. */
    public bool $includeAttributes = false;
    /** Reserved-attribute prelude source, read by Main from `prelude/attributes.php`. */
    public string $attributesSrc = '';
    /** Inject the DateTime class family (gated on the program MENTIONING one —
     *  see Main.php). Gating is possible only because no stdlib signature names
     *  a DateTime* class: the family talks to the stdlib through scalars. */
    public bool $includeDateTime = false;
    /** DateTime prelude source, read by Main from `prelude/datetime.php`. */
    public string $dateTimeSrc = '';
    /** Inject the callback/element array functions (usort/sort/rsort/array_reduce)
     *  as prelude — compiled WITH the user program so call-site element inference
     *  types their array param and the in-module closure ABI matches (they can't
     *  live in the separately-linked stdlib .o; see Main.php gating). */
    public bool $includeArrayFns = false;
    /** Array-functions prelude source, read by Main from `prelude/array_fns.php`.
     *  Empty → not injected (the compiler itself never uses these). */
    public string $arrayFnsSrc = '';
    /** Inject the EXTENDED array functions (`prelude/array_fns_ext.php`) — the
     *  ref.array surface beyond the hot core. A SEPARATE file from array_fns so
     *  the compiler's own build (which calls array_map/sort) never pulls them
     *  in: a prelude is injected whole-file, so one miscompiled helper in
     *  array_fns.php breaks generation 2 of the self-host. */
    public bool $includeArrayFnsExt = false;
    /** Extended array-functions prelude source, from `prelude/array_fns_ext.php`. */
    public string $arrayFnsExtSrc = '';
    /** Inject the CLI prelude (__mc_argv / getopt) — compiled WITH the user
     *  program so the bare-`array` returns narrow at the call site. Gated on a
     *  source reference to $argv / $argc / getopt( (see Main.php). */
    public bool $includeCli = false;
    /** CLI prelude source, read by Main from `prelude/cli.php`. */
    public string $cliSrc = '';

    /**
     * Bundled-stdlib function declarations (parsed from `src/Runtime/**`)
     * offered as signature-only imports: each becomes a `declare`-only
     * {@see FunctionDef} (isExtern) so user code can call it, with the body
     * supplied by the linked prebuilt `stdlib.o`. Set by the compile driver
     * before {@see run}. A decl is skipped when the program defines the name
     * itself or a codegen builtin already handles it.
     * @var \Parser\Ast\FunctionDecl[]
     */
    public array $externDecls = [];

    /** True once at least one stdlib extern was injected → driver links stdlib.o. */
    public bool $externInjected = false;

    /** Name prefix of a hoisted foreach subject — the one owner of the
     *  convention. {@see LowerStmts::hoistForeachSubject} makes them;
     *  {@see EmitLlvmMemory::collectElementSharedLocals} reads them. */
    public const FE_SUBJ_PREFIX = '__fe_subj_';

    private ?Module $module = null;
    private int $closureCounter = 0;
    private int $destrCounter = 0;

    /** @var array<string, \Parser\Ast\FunctionDecl> fn name → decl (defaults / named args) */
    private array $fnDecls = [];

    /** @var Node[] Auto-viv StoreLocals for `#[RefOut]` args, flushed by
     *  {@see lowerStmt} immediately before the statement whose call produced
     *  them (so an undefined out-var is defined + typed ahead of the call). */
    private array $pendingCallInits = [];

    /**
     * Bare fn name → its single namespaced FQN, for unqualified call
     * resolution (PHP `use function`). A call `free()` in namespace `Foo`
     * parses as `Foo\free`; if no `Foo\free` exists but exactly one
     * `*\free` is declared (e.g. the FFI extern `Runtime\Libc\free`),
     * resolve to it. Empty string marks an ambiguous (multiple) bare name.
     * @var array<string, string>
     */
    private array $fnAliasByBare = [];

    public function run(Module $module): Module
    {
        $this->module = $module;
        $this->lowerSourceFile = $module->sourceFile;
        // Built-in Exception hierarchy (parsed prelude) is lowered like
        // any user class, so `throw` / `catch` / `getMessage` resolve
        // through the normal class machinery.
        $stmts = [];
        $preludeStmts = $this->preludeStatements();
        // Flatten braced-namespace blocks (`namespace Io\Poll { ... }`) into
        // their inner statements — the parser already qualified the inner decl
        // names (`Io\Poll\Backend`), so they register/lower like any unbraced-ns
        // file. The PRELUDE needs this too (io_poll.php's namespaced class tree),
        // not just the user program below — else the inner enums/classes hide
        // inside an unregistered Namespace wrapper.
        foreach ($preludeStmts as $ps) {
            if ($ps->kind === 'Namespace' && $ps->body !== null) {
                foreach ($ps->body->statements as $inner) { $stmts[] = $inner; }
            } else {
                $stmts[] = $ps;
            }
        }
        // Count AFTER flattening — $preludeCount indexes into $stmts to mark a
        // class's static-prop linkage as prelude (linkonce_odr).
        $preludeCount = \count($stmts);
        foreach ($this->program->statements as $us) {
            if ($us->kind === 'Namespace' && $us->body !== null) {
                foreach ($us->body->statements as $inner) { $stmts[] = $inner; }
            } else {
                $stmts[] = $us;
            }
        }
        // Pre-pass: register every class layout first so method
        // bodies and `new` sites can resolve property offsets and
        // sibling classes regardless of source order.
        // Register every class name first so a class can reference
        // itself / a later-declared sibling in a property type hint
        // (e.g. `?Node $next`) before its full def exists.
        foreach ($stmts as $stmt) {
            if ($stmt->kind === 'Class') {
                $cdecl = $stmt->decl;
                if (($cdecl->kind ?? 'class') === 'class') {
                    // `$stmt->decl` is statically unknown here, so reading
                    // `$cdecl->name` directly resolves to the wrong field
                    // offset under self-host (it aliases `kind` → "class").
                    // Route through a ClassDecl-typed param so the offset
                    // resolves like buildClassDef's own `$decl` reads do.
                    $cname = $this->classDeclName($cdecl);
                    $this->knownClassNames[$cname] = true;
                    // Decl table up front so a static-prop default or a
                    // const initializer can resolve `Other::CONST` (incl.
                    // forward references) while buildClassDef runs.
                    $this->classDecls[$cname] = $cdecl;
                    // Map the trailing short name → FQN so a namespaced
                    // type hint (`Stmt` for `Parser\Ast\Stmt`) resolves to
                    // obj<FQN> in lowerTypeHint — needed so AST-node arrays
                    // (`Stmt[]`) carry an obj element type and rc their
                    // elements. Only unambiguous short names resolve;
                    // collisions (`Type` in two namespaces) stay erased.
                    $fqn = $cname;
                    $bs = \strrpos($fqn, '\\');
                    if ($bs !== false && $bs >= 0) {
                        $short = \substr($fqn, $bs + 1, \strlen($fqn) - $bs - 1);
                        if (isset($this->shortClassFqn[$short])) {
                            $this->shortClassAmbiguous[$short] = true;
                        } else {
                            $this->shortClassFqn[$short] = $fqn;
                        }
                    }
                } elseif (($cdecl->kind ?? 'class') === 'trait') {
                    // Register traits in the SAME up-front pre-pass as
                    // classes so `buildClassDef` can merge a used trait's
                    // methods (and their return types) regardless of source
                    // / file order. Without this, a class processed before
                    // its trait's decl merges nothing → `$this->traitMethod()`
                    // return type defaults to int → string results render via
                    // int_to_str (the cross-file EmitLlvm-split corruption).
                    $this->traitTable[$this->declName($cdecl)] = $cdecl;
                    $module->traitNames[\ltrim($this->declName($cdecl), '\\')] = true;
                } elseif (($cdecl->kind ?? 'class') === 'interface') {
                    // Register interface decls so (1) an implementing class
                    // inherits their consts (findClassConst's `implements` walk),
                    // and (2) an interface NAME resolves to obj<Iface> in a type
                    // hint — else a `function f(): Iface` return / `Iface $x`
                    // param erases to `unknown` and a method call on it can't
                    // resolve the method's return type (a string result renders
                    // as a raw ptr). Dispatch goes through the interface-typed
                    // receiver path (the iface has no ClassDef, so class_id
                    // selects the impl at runtime).
                    $iname = $this->classDeclName($cdecl);
                    $this->classDecls[$iname] = $cdecl;
                    $this->knownClassNames[$iname] = true;
                    $module->interfaceNames[\ltrim($iname, '\\')] = true;
                    $ibs = \strrpos($iname, '\\');
                    if ($ibs !== false && $ibs >= 0) {
                        $ishort = \substr($iname, $ibs + 1, \strlen($iname) - $ibs - 1);
                        if (isset($this->shortClassFqn[$ishort])) {
                            $this->shortClassAmbiguous[$ishort] = true;
                        } else {
                            $this->shortClassFqn[$ishort] = $iname;
                        }
                    }
                } elseif (($cdecl->kind ?? 'class') === 'enum') {
                    // Register the enum decl so `self::CONST` / `Enum::CONST`
                    // resolve its constants (findClassConst walks `->consts`).
                    $ename = $this->classDeclName($cdecl);
                    $this->classDecls[$ename] = $cdecl;
                    $this->knownClassNames[$ename] = true;
                }
            }
        }
        // `#[TypeDef]` classes, BEFORE any ClassDef is built: a class registered
        // earlier may already name one in a property or parameter hint, and
        // `lowerTypeHint` must resolve it to the carrier scalar from the first use.
        $this->registerTypeDefs($stmts);
        // PHP's reserved attributes: #[Override] against the (now complete) decl
        // table, plus target / repeat validation. Before any ClassDef is built,
        // so a fatal aborts ahead of the expensive work — and interfaces are
        // visible only here, since they never get a ClassDef.
        $this->checkAttributes($stmts, $preludeCount);
        // The `Ffi\*` attributes are ours, not Zend's, so unlike the reserved
        // set they are checked across the WHOLE statement list — prelude
        // included. prelude/resource.php and prelude/io_poll.php carry them, and
        // a broken binding there should fail at once rather than only when some
        // user program happens to pull that prelude tier in.
        $this->checkFfiAttrs($stmts);
        // Same [0, $preludeCount) window the method loop below uses for
        // FunctionDef::$isPrelude — here it decides the LINKAGE of a class's
        // static-prop cells, which are registered inside buildClassDef.
        // Build classes PARENT-FIRST, not in source order. buildClassDef copies
        // the parent's slots to give the subclass the same field offsets, and it
        // can only do that if the parent is already in classTable — source order
        // used to be assumed. A composer app breaks that assumption immediately:
        // `src/App\GreetCommand` sorts before `vendor/symfony/…/Command`, so the
        // three probe commands were built with ZERO inherited slots and
        // allocated 16 bytes (header only) while every inherited Command method
        // wrote at +24…+136 — an out-of-bounds write on every command object,
        // which corrupted malloc's own metadata and crashed far from the cause.
        foreach ($this->classBuildOrder($stmts) as $clsIdx) {
            $stmt = $stmts[$clsIdx];
            $this->inPreludeClass = $clsIdx < $preludeCount;
            if ($stmt->kind === 'Class') {
                $decl = $stmt->decl;
                $dkind = $decl->kind ?? 'class';
                if ($dkind === 'enum') {
                    $ed = $this->buildEnumDef($decl);
                    $this->enumTable[$ed->name] = $ed;
                    $module->addEnum($ed);
                    // Register a minimal ClassDef so enum instance methods lower
                    // and emit (a case is an ordinal; the method takes $this =
                    // ordinal, and `->name`/`->value` resolve via the enum
                    // globals). NOT added to module->classes: enum cases are
                    // value ordinals, not heap objects — the call site dispatches
                    // directly to `Enum__method`.
                    $mnames = [];
                    foreach ($decl->methods as $m) { $mnames[$m->name] = true; }
                    if ($mnames !== []) {
                        $ecd = new ClassDef(
                            name: $ed->name,
                            classId: $this->stableClassId(\ltrim($ed->name, '\\')),
                            propertyNames: [],
                            propertyTypes: [],
                            methodNames: $mnames,
                            interfaces: $decl->implements,
                        );
                        $this->classTable[$ed->name] = $ecd;
                        // Registered so InferTypes resolves `$case->method()`
                        // return types + dispatch. Enum cases stay ordinals — the
                        // isEnumClass() guards keep rc/new/property on the enum
                        // path; only method signatures are consulted here.
                        $module->addClass($ecd);
                    }
                    continue;
                }
                if ($dkind === 'trait') {
                    // `$decl` is untyped here ($stmt->decl); an inline
                    // `$decl->name` resolves the wrong offset and reads the
                    // `kind` slot ("trait") instead of the trait name. Read
                    // through a typed param so the trait registers under its
                    // real name (else `use Trait` never resolves). T5 pattern.
                    $this->traitTable[$this->declName($decl)] = $decl;
                    continue;
                }
                if ($dkind !== 'class') { continue; }
                $cd = $this->buildClassDef($decl, $this->stableClassId(\ltrim($this->declName($decl), '\\')));
                $cd->isPreludeClass = $this->inPreludeClass;
                $this->classTable[$cd->name] = $cd;
                // A `#[TypeDef]` is a VALUE, not an object: it keeps a ClassDef so
                // its methods and its one property still resolve, but it goes to
                // the module's TypeDef table, never the class list — no class
                // descriptor, no drop fn, no instanceof arm, no var_dump case. At
                // runtime nothing is left of it but the scalar.
                if ($this->isTypeDef($cd->name)) {
                    $cd->typeDefRepr = $this->typeDefReprs[\ltrim($cd->name, '\\')];
                    $cd->typeDefProp = $this->typeDefProp($cd->name);
                    $module->typeDefs[$cd->name] = $cd;
                    continue;
                }
                $module->addClass($cd);
            }
        }

        // Reify every `Box<float>` the program's docblocks bind. Runs HERE: the
        // origin classes (and their parents) now exist, and no body has been
        // lowered yet — so a spec class is already in the class table when a body
        // first mentions it, and `new Box()` can be pointed at it.
        $this->reifyPreScan($module);

        // Built-in `stdClass`: a bag-only object (dynamic properties),
        // used by `(object)` casts and `json_decode`.
        if (!isset($this->classTable['stdClass'])) {
            $std = new ClassDef(
                name: 'stdClass',
                classId: $this->stableClassId('stdClass'),
                propertyNames: [],
                propertyTypes: [],
                methodNames: [],
                hasBag: true,
            );
            $this->classTable['stdClass'] = $std;
            $this->knownClassNames['stdClass'] = true;
            $module->addClass($std);
        }

        // Synthesize `__mir_dump_object` from the now-complete class table: a
        // class-aware var_dump for typed objects (the prelude's is_object branch
        // calls it). Generated HERE, not in the prelude (which is parsed before
        // any user class registers). Reuses instanceof narrowing + typed prop
        // access — clarity over strict PHP parity (public-style keys, `#1` id).
        if ($this->includeVarDump) {
            $dumpProg = \Parser\Parser::parseSource("<?php\n" . $this->dumpObjectSrc());
            foreach ($dumpProg->statements as $dstmt) {
                if ($dstmt->kind !== 'Function') { continue; }
                $this->fnDecls[$dstmt->decl->name] = $dstmt->decl;
                $dfn = $this->lowerFunction($dstmt->decl);
                $dfn->isPrelude = true;
                $module->addFunction($dfn);
            }
        }

        // var_export()'s object arm — same point and pattern as
        // __mir_dump_object. It prints a `\C::__set_state(array(…))` literal; php
        // does NOT call that method from var_export, and neither does this.
        if ($this->includeVarExport) {
            $exProg = \Parser\Parser::parseSource("<?php\n" . $this->exportObjectSrc());
            foreach ($exProg->statements as $estmt) {
                if ($estmt->kind !== 'Function') { continue; }
                $this->fnDecls[$estmt->decl->name] = $estmt->decl;
                $efn = $this->lowerFunction($estmt->decl);
                $efn->isPrelude = true;
                $module->addFunction($efn);
            }
        }

        // serialize()'s object arm — same point and pattern as __mir_dump_object,
        // and for the same reason: one `instanceof` arm per class, written from
        // the now-complete class table.
        if ($this->includeSerialize) {
            $serProg = \Parser\Parser::parseSource("<?php\n" . $this->serObjectSrc());
            foreach ($serProg->statements as $sstmt) {
                if ($sstmt->kind !== 'Function') { continue; }
                $this->fnDecls[$sstmt->decl->name] = $sstmt->decl;
                $sfn = $this->lowerFunction($sstmt->decl);
                $sfn->isPrelude = true;
                $module->addFunction($sfn);
            }
        }

        // unserialize()'s object arms — the reader's half of the above: an
        // allocator that skips __construct, a per-class slot filler, and the
        // enum spec -> case-singleton map.
        if ($this->includeUnserialize) {
            $unProg = \Parser\Parser::parseSource("<?php\n" . $this->unserSrc());
            foreach ($unProg->statements as $ustmt) {
                if ($ustmt->kind !== 'Function') { continue; }
                $this->fnDecls[$ustmt->decl->name] = $ustmt->decl;
                $ufn = $this->lowerFunction($ustmt->decl);
                $ufn->isPrelude = true;
                $module->addFunction($ufn);
            }
        }

        // Reflection Ф2: an invoke trampoline per (user class, method) + ctor,
        // synthesized from the now-complete class table (same point + pattern as
        // __mir_dump_object). Gated on the program reflecting at all; a
        // non-reflecting program synthesizes none. Over-approximates the
        // reflectable set here (no ClassDef graph closure yet) — the emit gate
        // ({@see EmitLlvmRuntime::reflectWants}) prunes a non-reflectable class's
        // trampoline before clang, and dead-strip drops the rest.
        if ($this->includeReflection) {
            $trampSrc = '';
            foreach ($module->classes as $cd) {
                $trampSrc .= \Compile\Mir\Passes\TrampolineSynth::sourceFor($cd);
                $trampSrc .= \Compile\Mir\Passes\ReflectSynth::sourceFor($cd);
            }
            if ($trampSrc !== '') {
                $trampProg = \Parser\Parser::parseSource("<?php\n" . $trampSrc);
                foreach ($trampProg->statements as $tstmt) {
                    if ($tstmt->kind !== 'Function') { continue; }
                    $this->fnDecls[$tstmt->decl->name] = $tstmt->decl;
                    $module->addFunction($this->lowerFunction($tstmt->decl));
                }
            }
            // Ф4: attribute-argument + newInstance factories, built as AST
            // directly (reusing the attribute's own arg Expr subtrees) so array /
            // enum-case / const args lower for free. rmeta references them by the
            // ReflectSynth naming.
            foreach ($this->synthAttrFactories($module) as $decl) {
                $this->fnDecls[$decl->name] = $decl;
                $module->addFunction($this->lowerFunction($decl));
            }
            // Ф5: a class-constants factory per class with any constant, built as
            // AST that references each constant by `\C::NAME` — reusing the
            // existing class-const resolution (self:: / inherited all resolve).
            foreach ($this->synthConstFactories($module) as $decl) {
                $this->fnDecls[$decl->name] = $decl;
                $module->addFunction($this->lowerFunction($decl));
            }
            foreach ($this->synthIfaceFactories($module) as $decl) {
                $this->fnDecls[$decl->name] = $decl;
                $module->addFunction($this->lowerFunction($decl));
            }
        }

        // Pre-pass: capture every function's params so call sites can
        // fill defaults + reorder named args regardless of source order.
        foreach ($stmts as $stmt) {
            if ($stmt->kind === 'Function') {
                $fqn = $stmt->decl->name;
                $this->fnDecls[$fqn] = $stmt->decl;
                $pos = \strrpos($fqn, '\\');
                if ($pos !== false) {
                    $bare = \substr($fqn, $pos + 1);
                    $this->fnAliasByBare[$bare] = isset($this->fnAliasByBare[$bare])
                        ? '' : $fqn;
                }
            }
        }

        // Pre-pass: register every top-level `define("NAME", <const-expr>)` so a
        // later bareword reference / `constant()` / `defined()` resolves at
        // compile time, regardless of source order. Conditional / non-top-level
        // / non-literal-name defines are not registered (no runtime registry).
        foreach ($stmts as $stmt) {
            if ($stmt->kind !== 'Expression') { continue; }
            $de = $stmt->expr;
            if ($de->kind !== 'Call') { continue; }
            if (\strtolower($de->function) !== 'define') { continue; }
            $dargs = $de->args;
            if (\count($dargs) < 2) { continue; }
            if ($dargs[0]->kind !== 'StringLiteral') { continue; }
            $this->userConstants[$this->constBareName($this->stringLitValue($dargs[0]))] = $dargs[1];
        }

        // Inject bundled-stdlib signatures as declare-only externs. Skipped
        // when the program defines the name itself (the compiler's own source
        // ships the stdlib) or a codegen builtin handles it inline — in both
        // cases the user object is self-contained and stdlib.o is not linked.
        foreach ($this->externDecls as $extDecl) {
            $name = $extDecl->name;
            if (isset($this->fnDecls[$name])) { continue; }
            if ($this->isCodegenBuiltin($name)) { continue; }
            $this->fnDecls[$name] = $extDecl;
            // Register the bare-name alias for a namespaced import, exactly as
            // the in-source pre-pass does, so an unqualified `strncmp()` in the
            // consumer resolves to the imported `Runtime\Libc\strncmp` instead
            // of mis-mangling to an undefined global `@manticore_strncmp`.
            $pos = \strrpos($name, '\\');
            if ($pos !== false && $pos >= 0) {
                $bare = \substr($name, $pos + 1);
                $this->fnAliasByBare[$bare] = isset($this->fnAliasByBare[$bare])
                    ? '' : $name;
            }
            $fn = $this->lowerFunctionSignature($extDecl);
            $fn->isExtern = true;
            $module->addFunction($fn);
            $this->externInjected = true;
        }

        // Fold polyfill-style declaration guards: expand a top-level `if
        // (compile-time predicate) { … }` into its live branch, so a declaration
        // it guards — `if (!function_exists('X')) { function X() {…} }` — hoists to
        // the top level when live, or is dropped when X already exists. Runs after
        // the stdlib externs are in `$fnDecls` (so function_exists sees native
        // functions) and only over the user portion (the prelude window stays put).
        if ($preludeCount < \count($stmts)) {
            $head = \array_slice($stmts, 0, $preludeCount);
            $tail = $this->flattenConstantIfs(\array_slice($stmts, $preludeCount));
            $stmts = \array_merge($head, $tail);
        }

        $mainStmts = [];
        $stmtIdx = 0;
        foreach ($stmts as $stmt) {
            // Statements [0, $preludeCount) are the built-in Throwable /
            // Exception hierarchy; flag the functions they emit so
            // `dump-mir` can hide them by default (golden snapshots stay
            // focused on user code, not boilerplate).
            $isPrelude = $stmtIdx < $preludeCount;
            $stmtIdx = $stmtIdx + 1;
            // FunctionStmt wraps FunctionDecl in `$decl`; the kind
            // discriminant is 'Function' (mirrors how ClassStmt
            // discriminates as 'Class').
            if ($stmt->kind === 'Function') {
                $fn = $this->lowerFunction($stmt->decl);
                if ($isPrelude) { $fn->isPrelude = true; }
                $module->addFunction($fn);
                continue;
            }
            if ($stmt->kind === 'Class') {
                $dk = $stmt->decl->kind ?? 'class';
                if ($dk === 'class' || $dk === 'enum') {
                    $before = \count($module->functions);
                    $this->lowerClassMethods($stmt->decl, $module);
                    if ($isPrelude) {
                        $after = \count($module->functions);
                        for ($k = $before; $k < $after; $k = $k + 1) {
                            $module->functions[$k]->isPrelude = true;
                        }
                    }
                }
                continue;
            }
            if ($stmt->kind === 'Interface'
                || $stmt->kind === 'Trait'
                || $stmt->kind === 'Use'
                || $stmt->kind === 'UseDecl'
                || $stmt->kind === 'Namespace') {
                continue;
            }
            // A top-level statement has no class scope — reset it (a preceding
            // class lowering left `currentLowerClass` set, which would make a
            // top-level closure reading `$this` capture a non-existent local
            // instead of a late-bound placeholder).
            $this->currentLowerClass = '';
        $this->currentTypeParams = [];
            $mainStmts[] = $this->lowerStmt($stmt);
        }
        // Bodies for the reified classes. Last: a body can itself bind a new
        // specialization (`@var Box<int>` inside a method), and the drain loops.
        $this->lowerReifiedMethods($module);
        // Now that every body exists, a PROPERTY holding a bound container can be
        // typed as the specialization — decided from the stores the module really
        // contains. {@see LowerReify::reifyProperties}
        $this->reifyProperties($module);
        // The class table is now complete, so descendant sets are known —
        // materialise the late-static-binding specialisations.
        $this->emitLsbSpecializations($module);
        $mainStmts = $this->injectCliSuperglobals($mainStmts);
        $mainStmts = $this->injectGlobalDecls($mainStmts);
        $mainStmts = $this->injectShutdownDrain($mainStmts);
        $mainBody = new Block($mainStmts, Type::void());
        $module->addFunction(new FunctionDef(
            name: '__main',
            params: [],
            returnType: Type::int_(),
            body: $mainBody,
        ));
        // Last: the superglobal binding scans EVERY function body (including
        // __main and the closures), so it needs the complete function list.
        $this->injectSuperglobals($module);
        // Ф5 ReflectionFunction: NOW that every body is lowered, scan for
        // `new ReflectionFunction('f')` targets and synthesize an invoke
        // trampoline per invokable one (the metadata row + registry are emitted
        // from Module::$reflFnMeta). Deferred to here because the scan reads the
        // lowered MIR bodies, which the early class-synthesis block predates.
        if ($this->includeReflection) {
            $this->collectReflFnNames($module);
            $fnTrampSrc = '';
            foreach ($module->reflFnMeta as $fn => $mm) {
                $variadic = false; $byRef = false;
                foreach ($mm->params as $p) {
                    if ($p->variadic) { $variadic = true; }
                    if ($p->byRef) { $byRef = true; }
                }
                if ($variadic || $byRef) { continue; }
                $void = \strtolower($mm->returnType) === 'void';
                $fnTrampSrc .= \Compile\Mir\Passes\TrampolineSynth::functionTramp(
                    $fn, $mm->requiredParams(), \count($mm->params), $void);
            }
            if ($fnTrampSrc !== '') {
                $prog = \Parser\Parser::parseSource("<?php\n" . $fnTrampSrc);
                foreach ($prog->statements as $s) {
                    if ($s->kind !== 'Function') { continue; }
                    $this->fnDecls[$s->decl->name] = $s->decl;
                    $module->addFunction($this->lowerFunction($s->decl));
                }
            }
        }
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /** Depth of a class in its inheritance chain (0 = no parent). */
    private function classDepth(string $name): int
    {
        $d = 0;
        $cur = $name;
        $guard = 0;
        while (isset($this->classTable[$cur]) && $guard < 256) {
            $p = $this->classTable[$cur]->parent;
            if ($p === '') { break; }
            $d = $d + 1;
            $cur = $p;
            $guard = $guard + 1;
        }
        return $d;
    }

    /**
     * Populate {@see Module::$reflFnNames} — every free function a
     * `new ReflectionFunction('literal')` names. A dynamic name cannot be
     * resolved statically (its function is simply not registered, and the ctor
     * throws at runtime), the same trade the class registry makes.
     */
    private function collectReflFnNames(Module $module): void
    {
        foreach ($module->functions as $fn) {
            if ($fn->body === null) { continue; }
            $this->scanReflFn($fn->body, $module);
        }
    }

    private function scanReflFn(\Compile\Mir\Node $n, Module $module): void
    {
        if ($n instanceof \Compile\Mir\NewObj
            && \ltrim($n->class, '\\') === 'ReflectionFunction'
            && \count($n->args) >= 1
            && $n->args[0] instanceof \Compile\Mir\StringConst) {
            $fn = \ltrim($n->args[0]->value, '\\');
            if (!isset($module->reflFnMeta[$fn])) {
                $decl = $this->fnDecls[$fn] ?? null;
                if ($decl !== null) {
                    $module->reflFnMeta[$fn] = $this->fnMethodMeta($fn, $decl);
                }
            }
        }
        foreach (\Compile\Mir\Walk::children($n) as $c) {
            $this->scanReflFn($c, $module);
        }
    }

    /** A free function's declared shape as a {@see MethodMeta}, so it reuses the
     *  reflection method-row + param-table emission unchanged. */
    private function fnMethodMeta(string $fn, \Parser\Ast\FunctionDecl $decl): \Compile\Mir\MethodMeta
    {
        $params = [];
        foreach ($decl->params as $p) {
            $params[] = new \Compile\Mir\ParamMeta(
                $p->name,
                $p->typeHint === null ? '' : $p->typeHint,
                $p->default !== null,
                $p->byRef,
                $p->variadic,
                '', []);
        }
        return new \Compile\Mir\MethodMeta(
            $fn, 'public', false, false, false,
            $decl->returnType === null ? '' : $decl->returnType,
            // Attributes were dropped here, so ReflectionFunction::isDeprecated()
            // could never see a #[\Deprecated] on a free function.
            $params, $this->attrNames($this->fnDeclAttrs($decl)), '');
    }

    /**
     * Ф4 — the attribute factories for every reflectable class: per attribute
     * occurrence (class / method / property level), a nullary `__mc_attr_args_*`
     * returning its arguments as an array and a `__mc_attr_new_*` returning a
     * fresh attribute instance. Built as AST directly, reusing the attribute's
     * own arg Expr subtrees, so array / enum-case / const arguments lower
     * through the normal path.
     *
     * Only for an attribute whose name resolves to a class we know — this skips
     * the compiler's own marker attributes (`#[Struct]`, `#[CellArg]`, …), which
     * are not instantiable classes and whose `new` would fail to compile.
     *
     * @return \Parser\Ast\FunctionDecl[]
     */
    private function synthAttrFactories(Module $module): array
    {
        $out = [];
        foreach ($module->classes as $cd) {
            if ($cd->isStruct || $cd->isPreludeClass) { continue; }
            $decl = $this->classDecls[$cd->name] ?? null;
            if ($decl === null) { continue; }
            $this->attrFactoriesFor($cd->name, 'c', '', $decl->attributes, $out);
            foreach ($decl->methods as $m) {
                $this->attrFactoriesFor($cd->name, 'm', $m->name, $m->attributes, $out);
            }
            foreach ($decl->properties as $prop) {
                $this->attrFactoriesFor($cd->name, 'p', $prop->name, $prop->attributes, $out);
            }
        }
        return $out;
    }

    /**
     * Append the args + new factory for each known-class attribute in `$attrs`,
     * keyed by the site (declaring class / kind / member / index).
     *
     * @param \Parser\Ast\AttributeNode[] $attrs
     * @param \Parser\Ast\FunctionDecl[]  $out  appended to, by reference
     */
    private function attrFactoriesFor(string $class, string $kind, string $member, array $attrs, array &$out): void
    {
        $k = -1;
        foreach ($attrs as $attr) {
            $k = $k + 1;
            if (!isset($this->classDecls[\ltrim($attr->name, '\\')])) { continue; }
            $span = $attr->span;
            // args factory: return [0 => <arg0>, 'name' => <namedArgVal>, …]
            $elems = [];
            $pos = 0;
            foreach ($attr->args as $a) {
                if ($a instanceof \Parser\Ast\NamedArg) {
                    $elems[] = new \Parser\Ast\ArrayElement(\Parser\Ast\Expr::string($a->name, $span), $a->value);
                } else {
                    $elems[] = new \Parser\Ast\ArrayElement(\Parser\Ast\Expr::int($pos, $span), $a);
                    $pos = $pos + 1;
                }
            }
            $argsBody = new \Parser\Ast\Block([
                \Parser\Ast\Stmt::return_(\Parser\Ast\Expr::arrayLit($elems, $span), $span),
            ]);
            $out[] = new \Parser\Ast\FunctionDecl(
                \Compile\Mir\Passes\ReflectSynth::attrFn($class, $kind, $member, $k, false),
                [], 'array', $argsBody, $span);
            // new factory: return new <AttrClass>(<original args, named preserved>);
            // Declared `mixed`, NOT `object`: the only caller is the indirect
            // `__mc_refl_call0`, which is typed CELL. An `object` return handed
            // it a RAW pointer with no cell tag, so the instance came back
            // untagged — is_object() was false, instanceof false, get_class ''
            // and var_dump printed the pointer as a float. `mixed` makes the
            // return box (tag 8) and the cell honest.
            $newBody = new \Parser\Ast\Block([
                \Parser\Ast\Stmt::return_(\Parser\Ast\Expr::new_($attr->name, $attr->args, $span), $span),
            ]);
            $out[] = new \Parser\Ast\FunctionDecl(
                \Compile\Mir\Passes\ReflectSynth::attrFn($class, $kind, $member, $k, true),
                [], 'mixed', $newBody, $span);
        }
    }

    /**
     * Ф5 — one `__mc_consts_<C>(): array` per class that has any constant,
     * returning `['NAME' => \C::NAME, …]`. Referencing each constant by its
     * qualified name reuses the existing const resolution (a `self::` or
     * inherited value resolves in the owning class's scope), so no value
     * expression is re-lowered out of context.
     *
     * @return \Parser\Ast\FunctionDecl[]
     */
    private function synthConstFactories(Module $module): array
    {
        $out = [];
        foreach ($module->classes as $cd) {
            if ($cd->isStruct || $cd->isPreludeClass) { continue; }
            if (!isset($this->classDecls[$cd->name])) { continue; }
            /** @var array<string, \Parser\Ast\Span> $names name → its span */
            $names = [];
            $seen = [];
            $visited = [];
            $this->collectConstNames($cd->name, $names, $seen, $visited);
            if ($names === []) { continue; }
            $fqn = '\\' . \ltrim($cd->name, '\\');
            $elems = [];
            foreach ($names as $cn => $span) {
                $elems[] = new \Parser\Ast\ArrayElement(
                    \Parser\Ast\Expr::string($cn, $span),
                    \Parser\Ast\Expr::staticAccess($fqn, $cn, $span));
            }
            $sp = new \Parser\Ast\Span(0, 0);
            $body = new \Parser\Ast\Block([
                \Parser\Ast\Stmt::return_(\Parser\Ast\Expr::arrayLit($elems, $sp), $sp),
            ]);
            $out[] = new \Parser\Ast\FunctionDecl(
                \Compile\Mir\Passes\ReflectSynth::constsFn($cd->name), [], 'array', $body, $sp);
        }
        return $out;
    }

    /**
     * Gather a class's constant names (own, then parents, interfaces and traits),
     * first declaration winning — the same reach as {@see LowerClasses::findClassConst}.
     *
     * @param array<string, \Parser\Ast\Span> $names   name → span, appended
     * @param array<string, bool>             $seen    names already taken
     * @param array<string, bool>             $visited classes already walked
     */
    private function collectConstNames(string $class, array &$names, array &$seen, array &$visited): void
    {
        $c = \ltrim($class, '\\');
        if (isset($visited[$c])) { return; }
        $visited[$c] = true;
        $decl = $this->classDecls[$c] ?? null;
        if ($decl === null) { return; }
        foreach ($decl->consts as $const) {
            if (isset($seen[$const->name])) { continue; }
            $seen[$const->name] = true;
            $names[$const->name] = $const->span;
        }
        foreach ($decl->uses as $t)       { $this->collectConstNames($t, $names, $seen, $visited); }
        foreach ($decl->extends as $p)    { $this->collectConstNames($p, $names, $seen, $visited); }
        foreach ($decl->implements as $i) { $this->collectConstNames($i, $names, $seen, $visited); }
    }

    /**
     * Ф5 — one `__mc_ifaces_<C>(): array` per class implementing any interface,
     * returning its transitive interface-name list as a `string[]`.
     *
     * @return \Parser\Ast\FunctionDecl[]
     */
    private function synthIfaceFactories(Module $module): array
    {
        $out = [];
        foreach ($module->classes as $cd) {
            if ($cd->isStruct || $cd->isPreludeClass) { continue; }
            if (!isset($this->classDecls[$cd->name])) { continue; }
            $names = [];
            $visited = [];
            $this->collectInterfaceNames($cd->name, $names, $visited);
            if ($names === []) { continue; }
            $sp = new \Parser\Ast\Span(0, 0);
            $elems = [];
            foreach ($names as $iname => $_) {
                $elems[] = new \Parser\Ast\ArrayElement(null, \Parser\Ast\Expr::string($iname, $sp));
            }
            $body = new \Parser\Ast\Block([
                \Parser\Ast\Stmt::return_(\Parser\Ast\Expr::arrayLit($elems, $sp), $sp),
            ]);
            $out[] = new \Parser\Ast\FunctionDecl(
                \Compile\Mir\Passes\ReflectSynth::ifacesFn($cd->name), [], 'array', $body, $sp);
        }
        return $out;
    }

    /**
     * A class's transitive interface names. A class contributes its `implements`
     * (+ each interface's parents); an interface contributes its `extends`; a
     * parent class contributes its own interfaces.
     *
     * @param array<string, bool> $names   interface name → true, appended
     * @param array<string, bool> $visited classes already walked
     */
    private function collectInterfaceNames(string $class, array &$names, array &$visited): void
    {
        $c = \ltrim($class, '\\');
        if (isset($visited[$c])) { return; }
        $visited[$c] = true;
        $decl = $this->classDecls[$c] ?? null;
        if ($decl === null) { return; }
        if (($decl->kind ?? 'class') === 'interface') {
            foreach ($decl->extends as $e) {
                $names[\ltrim($e, '\\')] = true;
                $this->collectInterfaceNames($e, $names, $visited);
            }
            return;
        }
        foreach ($decl->implements as $i) {
            $names[\ltrim($i, '\\')] = true;
            $this->collectInterfaceNames($i, $names, $visited);
        }
        foreach ($decl->extends as $e) {
            $this->collectInterfaceNames($e, $names, $visited);
        }
    }

    /**
     * Build the layout descriptor: promoted ctor params first (in
     * order), then explicit property declarations. Each gets the
     * next 8-byte slot. Property types come from the param / prop
     * type hint.
     */
    private function buildEnumDef(\Parser\Ast\ClassDecl $decl): EnumDef
    {
        $backing = $decl->enumBackingType;
        $backing = $backing === null ? '' : \strtolower(\ltrim($backing, '?\\'));
        $names = [];
        $ints = [];
        // DECLARED, not cast: `$case->value->value` is a POLYMORPHIC AST field
        // (int for one node subclass, string for another) and infers int here,
        // which disagrees with EnumDef::$strValues. A `(string)` cast would be
        // compiled against that WRONG static type and really convert a string
        // POINTER as an int; the annotation fixes the type without emitting a
        // conversion.
        /** @var string[] $strs */
        $strs = [];
        foreach ($decl->cases as $case) {
            $names[] = $case->name;
            if ($case->value !== null) {
                if ($backing === 'int') { $ints[] = (int)$case->value->value; }
                elseif ($backing === 'string') { $strs[] = $case->value->value; }
            }
        }
        return new EnumDef($decl->name, $names, $backing, $ints, $strs,
            $this->stableClassId(\ltrim($decl->name, '\\')));
    }

    /** Namespace portion of an FQN (`Compile\Mir` for `Compile\Mir\Module`); '' if unqualified. */
    private function nsOf(string $fqn): string
    {
        $pos = \strrpos($fqn, '\\');
        if ($pos === false || $pos < 0) { return ''; }
        return \substr($fqn, 0, $pos);
    }

    /**
     * `insteadof` losers as a flat set keyed `<trait>::<method>` (a flat map,
     * NOT a nested array). `as` aliases are read off the TraitAdaptation objects
     * at the call sites. Relies on ClassDecl::$traitAdaptations being typed
     * `TraitAdaptation[]` so the element field reads land on the right offsets.
     *
     * @return array<string,bool>
     */
    private function traitExclusions(\Parser\Ast\ClassDecl $decl): array
    {
        $excluded = [];
        foreach ($decl->traitAdaptations as $a) {
            if ($a->kind !== 'insteadof') { continue; }
            foreach ($a->exclude as $ex) {
                $excluded[\ltrim($ex, '\\') . '::' . $a->method] = true;
            }
        }
        return $excluded;
    }

    /**
     * Find a trait method for an `as` alias: `$trait` names the source trait
     * (or '' to search every used trait). Returns the MethodDecl or null.
     */
    private function findTraitMethod(\Parser\Ast\ClassDecl $decl, string $trait, string $method): ?\Parser\Ast\MethodDecl
    {
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            if ($trait !== '' && $tn !== $trait) { continue; }
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->methods as $tm) {
                if ($tm->name === $method) { return $tm; }
            }
        }
        return null;
    }

    /**
     * Whether any attribute is `#[AllowDynamicProperties]` — the class
     * carries a dynamic-property bag (assoc[string,cell]) after its
     * declared fields. Accepts namespaced variants.
     * @param \Parser\Ast\AttributeNode[] $attributes
     */
    private function hasDynamicPropsAttr(array $attributes): bool
    {
        foreach ($attributes as $attr) {
            if ($this->attrIsOneOf($attr, ['AllowDynamicProperties',
                'Manticore\\Attr\\AllowDynamicProperties', 'Attr\\AllowDynamicProperties'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether any attribute is `#[Struct]` (value-type: no class-id /
     * rc header, fields at offset 0). Accepts namespaced variants.
     * @param \Parser\Ast\AttributeNode[] $attributes
     */
    private function hasStructAttr(array $attributes): bool
    {
        foreach ($attributes as $attr) {
            if ($this->attrIsOneOf($attr, ['Struct',
                'Manticore\\Attr\\Struct', 'Attr\\Struct'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Lower one method body into a `FunctionDef`. `$staticClass` is the
     * late-static-binding scope (`static::` resolves to it); `$fnName` is the
     * emitted symbol. Sets `$this->sawStaticUse` as a side effect so the
     * caller can detect an LSB body. Reused for the normal copy and each
     * per-descendant LSB specialisation.
     *
     * @param StoreProperty[] $defaultStores
     */
    private function lowerMethodFn(
        \Parser\Ast\ClassDecl $decl,
        \Parser\Ast\MethodDecl $m,
        ClassDef $cd,
        array $defaultStores,
        string $staticClass,
        string $fnName,
    ): FunctionDef {
        $this->currentLowerClass = $decl->name;
        $this->currentStaticClass = $staticClass;
        $this->currentLowerFn = $m->name;
        // Which class a lowered method body belongs to. `$this` is untyped until
        // InferTypes runs, so a pass that needs to attribute a `$this->p = …`
        // store while still lowering (LowerReify) asks HERE instead of guessing
        // from the receiver — or from the function name, which cannot be parsed
        // back: a specialization's name has `__` in it too (`Box__of__float__add`
        // starts with `Box__`).
        $this->methodOwner[$fnName] = $decl->name;
        $this->constCallables = [];
        $this->setCurrentLowerParams($m->params);
        $this->scanStableCallables($m->body->statements);
        $this->localNewClasses = [];
        // Inside a `#[TypeDef]` body `$this` IS the carrier: there is no object to
        // point at. `__invoke` — the normaliser — takes no `$this` at all: it is a
        // pure carrier→carrier function, and `new C($x)` calls it directly. (Zend
        // reaches it as `$this($x)` from the constructor, which never runs here.)
        $isTypeDef = $this->isTypeDef($decl->name);
        $this->currentTypeDefClass = $isTypeDef ? \ltrim($decl->name, '\\') : '';
        $tdInvoke = $isTypeDef && $m->name === '__invoke';
        $params = [];
        // Static methods have no implicit `$this`.
        if (!$m->isStatic && !$tdInvoke) {
            $params[] = new Param(
                name: 'this',
                type: $isTypeDef
                    ? $this->typeDefCarrier($decl->name)
                    : Type::obj($decl->name),
                byRef: false,
                variadic: false,
            );
        }
        // PHP fixes the signature of the name-taking magic methods: the
        // first parameter is always `string $name` (and __call/__callStatic
        // take an `array $args` second). Force it so an UNtyped `$name`
        // doesn't erase to a cell — the call site passes a raw string ptr.
        $magicName = $m->name === '__get' || $m->name === '__set'
            || $m->name === '__isset' || $m->name === '__unset'
            || $m->name === '__call' || $m->name === '__callStatic';
        $magicArgs = $m->name === '__call' || $m->name === '__callStatic';
        $refOutNames = $this->refOutParamNames($m->attributes);
        $pi = 0;
        foreach ($m->params as $p) {
            $isVar = (bool)($p->variadic ?? false);
            // `T ...$xs` collects trailing args into a vec[T] the callee sees as
            // one vec param (caller packs at the call site) — same as the plain
            // function path. Without the Type::vec wrapper the callee reads the
            // param as a single T, so `$xs` is garbage (a raw arg, not the vec).
            $outType = $this->docTagType($m->docComment, '@param-out', $p->name);
            $pt = $isVar
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerParamType($this->effectiveHint(
                    $p->typeHint,
                    $outType ?? $this->docTagType($m->docComment, '@param', $p->name),
                ));
            if ($magicName && $pi === 0) { $pt = Type::string_(); }
            if ($magicArgs && $pi === 1) { $pt = Type::vec(Type::cell()); }
            $mp = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVar,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
            $mp->arrayHinted = $this->isBareArrayHint($p->typeHint) || $pt->isArray();
            $mp->refOut = $outType !== null || isset($refOutNames[$p->name])
                || $this->paramHasRefOutAttr($p);
            $params[] = $mp;
            $pi = $pi + 1;
        }
        $stmts = [];
        if ($m->name === '__construct') {
            // Property defaults run first, then promoted-param stores.
            foreach ($defaultStores as $ds) { $stmts[] = $ds; }
            foreach ($m->params as $p) {
                if ($p->promoted !== '') {
                    $stmts[] = new StoreProperty(
                        new LoadLocal('this', Type::obj($decl->name)),
                        $p->name,
                        new LoadLocal($p->name, $cd->propertyTypes[$p->name] ?? Type::unknown()),
                        $cd->propertyTypes[$p->name] ?? Type::unknown(),
                    );
                }
            }
        }
        $savedSawYield = $this->sawYield;
        $this->sawYield = false;
        $this->sawStaticUse = false;
        $deadTail = false;
        foreach ($m->body->statements as $bodyStmt) {
            // A label heads live code even after a terminator — `goto` reaches it.
            if ($deadTail && $bodyStmt->kind !== 'Label') { continue; }
            $deadTail = false;
            $lowered = $this->lowerStmt($bodyStmt);
            $stmts[] = $lowered;
            // Same dead-code cut as lowerBlockNode: skip after an unconditional
            // terminator so trailing code behind a folded static guard (a
            // `if (!function_exists('pcntl_signal')) return;` reducing to a bare
            // return, then `[\SIGINT, …]`) is never lowered.
            if ($this->nodeAlwaysTerminates($lowered)) { $deadTail = true; }
        }
        $isGen = $this->sawYield;
        $this->sawYield = $savedSawYield;
        $mret = $this->lowerTypeHint($this->effectiveHint(
            $m->returnType,
            $this->docTagType($m->docComment, '@return', ''),
        ));
        // `@return T` on a generic class: the shared body must see the ERASED
        // type (exactly what it saw before generics), so keep the un-erased form
        // aside for call sites to substitute against their receiver's binding.
        if ($mret->hasTypeVar()) {
            $cd->genericReturns[$m->name] = $mret;
            $mret = $mret->eraseTypeVars();
        }
        if ($isGen) {
            $elem = $mret->isGenerator() ? $mret->element : null;
            $mret = Type::generator($elem);
        }
        // The normaliser RETURNS the value type, not the bare carrier: `new Email($s)`
        // lowers to this call, and its result must stay tagged `Email` — else the
        // signature narrows it back to a plain string and `$email->domain()` no
        // longer resolves against the class it came from.
        if ($tdInvoke) { $mret = $this->typeDefCarrier($decl->name); }
        $this->currentTypeDefClass = '';
        $mfn = new FunctionDef(
            name: $fnName,
            params: $params,
            returnType: $mret,
            body: new Block($stmts, Type::void()),
            returnsByRef: (bool)($m->returnsByRef ?? false),
        );
        $mfn->isGenerator = $isGen;
        return $mfn;
    }

    /**
     * Emit per-descendant copies of every late-static-binding method queued
     * during class lowering. For an LSB method `M` owned by class `R`, each
     * strict descendant `S` gets a copy `R__M__lsb<S>` whose `static::`
     * resolves to `S`. Call sites pick the copy matching the called class
     * (see EmitLlvm's lsbTarget); the normal `R__M` copy serves `S == R`.
     */
    private function emitLsbSpecializations(Module $module): void
    {
        foreach ($this->lsbPending as $p) {
            $owner = $p->decl->name;
            foreach ($this->descendantsOf($owner) as $sub) {
                $spec = $this->lowerMethodFn(
                    $p->decl, $p->method, $p->cd, $p->defaultStores,
                    $sub, $owner . '__' . $p->method->name . '__lsb' . $sub,
                );
                $module->addFunction($spec);
                $lsep = $p->method->isStatic ? '::' : '->';
                $module->methodDisplay[$owner . '__' . $p->method->name . '__lsb' . $sub] =
                    $sub . $lsep . $p->method->name;
            }
        }
    }

    /**
     * Strict descendants of `$class` (every class that transitively extends
     * it), from the fully-built class table.
     * @return string[]
     */
    private function descendantsOf(string $class): array
    {
        $out = [];
        foreach ($this->classTable as $name => $cd) {
            if ($name === $class) { continue; }
            $c = $cd->parent;
            while ($c !== '') {
                if ($c === $class) { $out[] = $name; break; }
                $pc = $this->classTable[$c] ?? null;
                $c = $pc !== null ? $pc->parent : '';
            }
        }
        return $out;
    }

    /**
     * The C symbol from a `#[Symbol('name')]` / `#[Ffi\Symbol('name')]`
     * attribute, or null when absent.
     *
     * @param \Parser\Ast\AttributeNode[] $attributes
     */
    /**
     * True when a param carries `#[RefOut]` / `#[Ffi\RefOut]` — a pure-output
     * by-ref param the caller may auto-vivify.
     *
     * @param \Parser\Ast\AttributeNode[] $attributes
     */
    /**
     * Out-param names declared by a function's `#[RefOut('a', 'b')]` attribute
     * (core semantics, `Manticore\Attr\RefOut`). Named at the function so the
     * marker survives both the `.sig` and the self-host parse — a param-position
     * attribute is dropped on both today.
     *
     * @param \Parser\Ast\AttributeNode[] $attributes
     * @return array<string,bool> name → true
     */
    private function refOutParamNames(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $attr) {
            if (!$this->attrIsOneOf($attr, ['RefOut', 'Attr\\RefOut',
                'Manticore\\Attr\\RefOut'])) { continue; }
            foreach ($this->attrArgs($attr) as $arg) {
                if ($arg->kind === 'StringLiteral') { $out[$this->strLitValue($arg)] = true; }
            }
        }
        return $out;
    }

    /** A param-position `#[RefOut]` (no arg — marks THIS param). Read through a
     *  typed `$p` so `->attributes` resolves by name, not a base offset. */
    private function paramHasRefOutAttr(\Parser\Ast\Param $p): bool
    {
        foreach ($p->attributes as $attr) {
            if ($this->attrIsOneOf($attr, ['RefOut', 'Attr\\RefOut',
                'Manticore\\Attr\\RefOut'])) { return true; }
        }
        return false;
    }

    /** Function-level `#[CellArg('a','b')]` → names of element-consuming array
     *  params (the portable form; a param attribute alone doesn't survive .sig). */
    private function cellArgParamNames(array $attributes): array
    {
        $out = [];
        foreach ($attributes as $attr) {
            if (!$this->attrIsOneOf($attr, ['CellArg', 'Attr\\CellArg',
                'Manticore\\Attr\\CellArg'])) { continue; }
            foreach ($this->attrArgs($attr) as $arg) {
                if ($arg->kind === 'StringLiteral') { $out[$this->strLitValue($arg)] = true; }
            }
        }
        return $out;
    }

    /** A param-position `#[CellArg]` (no arg — marks THIS param). */
    private function paramHasCellArgAttr(\Parser\Ast\Param $p): bool
    {
        foreach ($p->attributes as $attr) {
            if ($this->attrIsOneOf($attr, ['CellArg', 'Attr\\CellArg',
                'Manticore\\Attr\\CellArg'])) { return true; }
        }
        return false;
    }

    /** Param->cellArg via a typed param (self-host offset), for the .sig-carried flag. */
    private function paramCellArg(\Parser\Ast\Param $p): bool { return $p->cellArg; }

    private function ffiSymbolOf(array $attributes): ?string
    {
        foreach ($attributes as $attr) {
            if (!$this->attrIsOneOf($attr, ['Symbol', 'Ffi\\Symbol'])) { continue; }
            $args = $this->attrArgs($attr);
            if ($args === []) { continue; }
            $arg = $args[0];
            // Read `->value` through a StringLiteral-typed param: `$arg` is a
            // base-`Expr` here, and the subclass `value` field sits past the
            // base fields, so a base-typed read picks the wrong offset under
            // self-host (T5) → a garbage symbol name. The kind check above
            // proves it is a StringLiteral.
            if ($arg->kind === 'StringLiteral') { return $this->strLitValue($arg); }
        }
        return null;
    }

    /**
     * `#[Ffi\Library('name')]` → the native library this binding needs at link
     * time, or '' when absent. 'c' is carried through and dropped later, at the
     * one place that knows libc is implicit.
     */
    private function ffiLibraryOf(array $attributes): string
    {
        foreach ($attributes as $attr) {
            if (!$this->attrIsOneOf($attr, ['Library', 'Ffi\\Library'])) { continue; }
            $args = $this->attrArgs($attr);
            if ($args === []) { continue; }
            $arg = $args[0];
            if ($arg->kind === 'NamedArg') { $arg = $this->namedArgValue($arg); }
            // Subclass-typed read — a base-`Expr` `->value` picks the wrong
            // offset under self-host (T5); the kind check proves the subclass.
            if ($arg->kind === 'StringLiteral') { return $this->strLitValue($arg); }
        }
        return '';
    }

    /** True when a `#[Ffi\Weak]` attribute is present (extern_weak binding). */
    private function ffiIsWeak(array $attributes): bool
    {
        foreach ($attributes as $attr) {
            if ($this->attrIsOneOf($attr, ['Weak', 'Ffi\\Weak'])) { return true; }
        }
        return false;
    }

    /**
     * `#[Ffi\Variadic($fixed)]` → the NAMED-param count of a C variadic callee,
     * or -1 when the attribute is absent. The wrapper needs it to emit an LLVM
     * variadic call type; without one, the backend applies the fixed-arity ABI
     * and on Darwin arm64 (which passes varargs on the STACK) the callee reads
     * register garbage where it does `va_arg`.
     *
     * Accepts the positional form `#[Ffi\Variadic(2)]` and the named form
     * `#[Ffi\Variadic(fixed: 2)]`. `$arity` is the binding's declared parameter
     * count, so a count past the end is caught here rather than in LLVM.
     */
    private function ffiVariadicFixed(array $attributes, int $arity,
                                      \Parser\Ast\Span $span, string $fnName): int
    {
        foreach ($attributes as $attr) {
            if (!$this->attrIsOneOf($attr, ['Variadic', 'Ffi\\Variadic'])) { continue; }
            $args = $this->attrArgs($attr);
            if ($args === []) {
                $this->attrFail('#[Ffi\\Variadic] on ' . $fnName
                    . '(): requires an integer literal argument', $span);
                return -1;
            }
            // Unwrap `fixed: 2` to its value; both reads go through a
            // subclass-typed accessor (a base-`Expr`-typed `->value` resolves
            // by the WRONG offset under self-host — T5).
            $arg = $args[0];
            if ($arg->kind === 'NamedArg') { $arg = $this->namedArgValue($arg); }
            // `-1` is a UnaryOp over an IntLiteral, not a literal. Fold it so a
            // negative count reports the RANGE error (what the author got wrong)
            // rather than "requires an integer literal".
            $negate = false;
            if ($arg->kind === 'UnaryOp' && $this->unaryOpOf($arg) === '-') {
                $negate = true;
                $arg = $this->unaryOperandOf($arg);
            }
            if ($arg->kind !== 'IntLiteral') {
                $this->attrFail('#[Ffi\\Variadic] on ' . $fnName
                    . '(): requires an integer literal argument', $span);
                return -1;
            }
            $fixed = $this->intLitValue($arg);
            if ($negate) { $fixed = -$fixed; }
            if ($fixed < 0 || $fixed > $arity) {
                $this->attrFail('#[Ffi\\Variadic(' . (string)$fixed . ')] on ' . $fnName
                    . '(): $fixed must be between 0 and the declared arity ('
                    . (string)$arity . ')', $span);
                return -1;
            }
            return $fixed;
        }
        return -1;
    }

    /**
     * The FUNCTION-level `#[Ffi\CType]` token — the C type of the binding's
     * RETURN — or '' when the attribute is absent.
     *
     * This is not cosmetic. A C-compiled callee returning `int` -1 does
     * `mov w0, #-1`, which zeroes x0's upper half, so an `i64` declare reads
     * 4294967295. That is how SSL_read's WANT_READ (-1) became a 4 GB length in
     * __mc_stream_fill and memmove'd off the end of the heap. Hand-written libc
     * syscall stubs happen to sign-extend (they write the full x0), which is why
     * only the C libraries — OpenSSL, PCRE2 — were exposed.
     */
    private function ffiCTypeToken(array $attributes, string $where,
                                   \Parser\Ast\Span $span): string
    {
        foreach ($attributes as $attr) {
            if (!$this->attrIsOneOf($attr, ['CType', 'Ffi\\CType'])) { continue; }
            $args = $this->attrArgs($attr);
            if ($args === []) {
                $this->attrFail('#[Ffi\\CType] on ' . $where
                    . ': requires a string literal argument', $span);
                return '';
            }
            // Read `->value` through a StringLiteral-typed accessor: a
            // base-`Expr`-typed read resolves by the WRONG offset under
            // self-host (T5). The kind check proves the subclass.
            $arg = $args[0];
            if ($arg->kind === 'NamedArg') { $arg = $this->namedArgValue($arg); }
            if ($arg->kind !== 'StringLiteral') {
                $this->attrFail('#[Ffi\\CType] on ' . $where
                    . ': requires a string literal argument', $span);
                return '';
            }
            return $this->strLitValue($arg);
        }
        return '';
    }

    /** A PARAMETER-position `#[Ffi\CType]` token, or '' when absent. */
    private function paramCTypeToken(\Parser\Ast\Param $p, string $where): string
    {
        return $this->ffiCTypeToken($this->paramAttrs($p), $where, $this->paramSpan($p));
    }

    /**
     * Validate a written `#[Ffi\CType]` token against the PHP type hint that
     * carries it, and answer the LLVM type the wrapper should declare. '' when
     * the token is rejected — the caller then falls back to the hint, so a
     * collected (analyze-mode) diagnostic does not also derail lowering.
     *
     * The carrier rules exist because the token and the hint describe the same
     * value from two sides, and a disagreement is always a bug: `#[CType('int')]`
     * on a `\Ffi\Ptr` return would sign-extend an address, which is the SSL_read
     * failure written down as a rule.
     */
    private function ffiResolveCType(string $token, ?string $hint, bool $isReturn,
                                     string $where, \Parser\Ast\Span $span): string
    {
        $llvm = \Compile\Mir\FfiCTypes::llvmType($token);
        if ($llvm === '') {
            $this->attrFail('#[Ffi\\CType(\'' . $token . '\')] on ' . $where
                . ': unknown C type. Known: ' . \Compile\Mir\FfiCTypes::tokens(), $span);
            return '';
        }
        if ($llvm === 'void' && !$isReturn) {
            $this->attrFail('#[Ffi\\CType(\'void\')] on ' . $where
                . ': void is a return type only', $span);
            return '';
        }
        // No hint declares no intent, so there is nothing to contradict.
        if ($hint === null) { return $llvm; }
        $carrier = $this->ffiCType($hint);
        $shown = \ltrim($hint, '\\');
        if ($carrier === 'ptr' && $llvm !== 'ptr') {
            // The SSL_read rule: a pointer carrier must never be narrowed or
            // sign-extended, and a PHP string is not a number either.
            $this->attrFail('#[Ffi\\CType(\'' . $token . '\')] on ' . $where
                . ': the declared type is ' . $shown
                . ', which carries a pointer — a pointer must not be extended or'
                . ' truncated (use \'ptr\', or drop the attribute)', $span);
            return '';
        }
        if ($carrier === 'void' && $llvm !== 'void') {
            $this->attrFail('#[Ffi\\CType(\'' . $token . '\')] on ' . $where
                . ': the declared type is void', $span);
            return '';
        }
        if ($carrier === 'double' && !\Compile\Mir\FfiCTypes::isFloat($token)) {
            $this->attrFail('#[Ffi\\CType(\'' . $token . '\')] on ' . $where
                . ': the declared type is float, which cannot carry a C ' . $token, $span);
            return '';
        }
        if (($carrier === 'i64' || $carrier === 'i1')
            && (\Compile\Mir\FfiCTypes::isFloat($token) || $llvm === 'void')) {
            $this->attrFail('#[Ffi\\CType(\'' . $token . '\')] on ' . $where
                . ': the declared type is ' . $shown . ', which cannot carry a C '
                . $token, $span);
            return '';
        }
        return $llvm;
    }

    /** Subclass-typed read of a StringLiteral's value (correct offset). */
    private function strLitValue(\Parser\Ast\StringLiteral $s): string { return $s->value; }

    /** Subclass-typed reads of a YieldExpr's fields (correct offsets — T5). */
    private function yieldKey(\Parser\Ast\YieldExpr $y): ?\Parser\Ast\Expr { return $y->key; }
    private function yieldValue(\Parser\Ast\YieldExpr $y): ?\Parser\Ast\Expr { return $y->value; }
    private function yieldFrom(\Parser\Ast\YieldExpr $y): bool { return $y->from; }

    /**
     * Resolve a called function name. PHP resolves an unqualified call in
     * a namespace to the namespaced function if one exists, else falls back
     * to the global function / builtin. We keep the name when it's a known
     * user function; otherwise strip to the last segment so a namespaced
     * `ltrim()` reaches the global builtin instead of an undefined
     * `@manticore_Ns_ltrim`.
     */
    private function resolveCallName(string $name): string
    {
        if (isset($this->fnDecls[$name])) { return $name; }
        $pos = \strrpos($name, '\\');
        $bare = $pos === false ? $name : \substr($name, $pos + 1);
        if ($bare !== $name && isset($this->fnDecls[$bare])) { return $bare; }
        // `use function` / global builtin: an unqualified call namespaced
        // at parse time (`Foo\free`) — or a global `\strncmp` — with no
        // matching decl resolves to the lone `*\<bare>` declaration (e.g.
        // FFI extern `Runtime\Libc\free`).
        $alias = $this->fnAliasByBare[$bare] ?? '';
        if ($alias !== '') { return $alias; }
        return $bare;
    }

    /** Sanitize a symbol for an LLVM identifier: `\` (namespace) → `_`. */
    private function sanitizeSym(string $name): string
    {
        $out = '';
        $n = \strlen($name);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $c = \substr($name, $i, 1);
            $out .= $c === '\\' ? '_' : $c;
        }
        return $out;
    }

    /** PHP type hint → LLVM C type for an FFI extern (mirrors AST ffiTypeFor). */
    private function ffiCType(?string $hint): string
    {
        if ($hint === null) { return 'ptr'; }
        $clean = \strtolower(\ltrim($hint, '?\\'));
        if ($clean === 'void')   { return 'void'; }
        if ($clean === 'bool')   { return 'i1'; }
        if ($clean === 'int')    { return 'i64'; }
        if ($clean === 'float' || $clean === 'double') { return 'double'; }
        // string / Ffi\Ptr / class names → opaque pointer.
        return 'ptr';
    }

    private function lowerUnary(\Parser\Ast\UnaryOp $e): Node
    {
        // `@expr` — suppression. Nothing in this runtime emits a diagnostic
        // except an explicit `trigger_error`, so `@` is a marker the operand's
        // lowering reads rather than a runtime state change: no counter, no
        // cost, and no depth to leak when the operand throws. The marker is
        // COUNTED, not set, so nested `@` (or `@` around an expression holding
        // several calls) behaves.
        if ($e->op === '@') {
            $this->silenceDepth = $this->silenceDepth + 1;
            $inner = $this->lowerExpr($e->operand);
            $this->silenceDepth = $this->silenceDepth - 1;
            return $inner;
        }
        $operand = $this->lowerExpr($e->operand);
        $op = $e->op;
        if ($op === '-') {
            $type = $operand->type->kind === Type::KIND_FLOAT
                ? Type::float_() : Type::int_();
            return new Neg($operand, $type);
        }
        if ($op === '+') {
            return $operand;
        }
        if ($op === '!') {
            return new Not_($operand);
        }
        if ($op === '~') {
            return new BitNot_($operand, Type::int_());
        }
        // Throw expression (PHP 8.0): `$x ?? throw new E`, `fn() => throw …`,
        // `cond ? … : throw …`. The parser models it as a unary `throw`. It
        // never yields a value (a `never`-typed node); the enclosing
        // coalesce/ternary takes the sibling arm's type (see infer*).
        if ($op === 'throw') {
            return new Throw_($operand, Type::void());
        }
        // `print $x` — echo with a value: prints the operand, yields int 1.
        // Lowered to the `print` codegen builtin rather than a new node kind,
        // because a Call is already walked, cloned, typed and folded by every
        // pass; a new kind would mean touching all of them for no gain.
        if ($op === 'print') {
            return new Call('print', [$operand], Type::int_());
        }
        throw new \RuntimeException('MIR.lower: unsupported unary op ' . $op);
    }

    private function lowerDoWhile(\Parser\Ast\DoWhileStmt $stmt): DoWhile_
    {
        $body = $this->lowerBlockNode($stmt->body);
        $cond = $this->lowerExpr($stmt->condition);
        return new DoWhile_($body, $cond);
    }

    /**
     * `function (...) use (...) { }` → a top-level `__closure_N` fn
     * whose params are the captured vars (by value) followed by the
     * declared params. The expression value is a Closure_ holding the
     * captured values, packed into a heap struct at emit time.
     */
    private function lowerClosure(\Parser\Ast\Closure $expr): Node
    {
        $capNames = [];
        $capByRef = [];
        foreach ($expr->uses as $u) { $capNames[] = $u->name; $capByRef[$u->name] = $u->byRef; }
        // Isolate the generator flag: a yield inside this closure marks the
        // closure, not the enclosing function.
        $savedSawYield = $this->sawYield;
        $this->sawYield = false;
        $body = $this->lowerBlockNode($expr->body);
        $isGen = $this->sawYield;
        $this->sawYield = $savedSawYield;
        return $this->finishClosure($capNames, $expr->params, $body, $expr->returnType, $capByRef, $isGen);
    }

    private function lowerArrowFn(\Parser\Ast\ArrowFn $expr): Node
    {
        // Arrow fns capture every free variable by value.
        $paramNames = [];
        foreach ($expr->params as $p) { $paramNames[$p->name] = true; }
        $free = [];
        $seen = [];
        foreach ($this->collectVars($expr->body) as $v) {
            if (isset($paramNames[$v])) { continue; }
            if (isset($seen[$v])) { continue; }
            $seen[$v] = true;
            $free[] = $v;
        }
        $body = new Block([new Return_($this->lowerExpr($expr->body), Type::void())], Type::void());
        return $this->finishClosure($free, $expr->params, $body, $expr->returnType);
    }

    /** Whether the lowered node tree reads the local `$this`. */
    private function nodeReadsThis(Node $n): bool
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL && $n->name === 'this') {
            return true;
        }
        foreach (Walk::children($n) as $c) {
            if ($this->nodeReadsThis($c)) { return true; }
        }
        return false;
    }

    /**
     * Materialise the CLI superglobals `$argv` / `$argc` at the top of
     * `__main` when the program reads them (and does not assign them first).
     * `$argv` comes from the `__mc_argv` prelude helper (a string[] built from
     * the captured process argv); `$argc` from the `__mir_argc` builtin. PHP's
     * CLI SAPI always defines both — mirroring that lets `getopt()` and plain
     * `$argv[1]` work without a manual `global`.
     *
     * @param Node[] $mainStmts
     * @return Node[]
     */
    /**
     * A top-level variable IS the global of the same name. For every name a
     * function `global`-imports and that `__main` also touches, prepend a
     * `StaticLocalDecl_` binding it to the shared `@g_<name>` cell — so a
     * top-level `$g = 5` writes that cell (visible inside the function) instead
     * of a frame local that DeadStore would drop as write-only.
     *
     * @param \Compile\Mir\Node[] $mainStmts
     * @return \Compile\Mir\Node[]
     */
    /**
     * Drain the shutdown queue at the END of `__main`'s body — before the
     * scope's releases, and therefore before the destructors of everything
     * still alive.
     *
     * php's order is: registered shutdown functions first, then the remaining
     * objects are destroyed. The queue already runs from an `atexit` hook, but
     * atexit fires AFTER main returns, by which point the trailing
     * `mem_rc_release`s have already run every destructor — so `$kernel`'s
     * __destruct beat Kernel::terminate, and a doctrine or monolog flush
     * registered at shutdown found its subject already torn down.
     *
     * The atexit hook stays: it is what covers `exit()` and the uncaught path.
     * `__mc_run_shutdown` is idempotent (`$shutdownRan`), so on a normal return
     * this call wins and the hook is a no-op.
     *
     * Inserted BEFORE a trailing top-level `return` — appending after it would
     * be dead code, and a top-level return is a real shape here.
     *
     * @param \Compile\Mir\Node[] $mainStmts
     * @return \Compile\Mir\Node[]
     */
    private function injectShutdownDrain(array $mainStmts): array
    {
        if (!$this->module->needsErrorHandlers) { return $mainStmts; }
        $drain = new Call('__mc_run_shutdown', [], Type::void());
        $n = \count($mainStmts);
        $last = $n > 0 ? $mainStmts[$n - 1] : null;
        if ($last !== null && $last->kind === Node::KIND_RETURN) {
            $out = [];
            for ($i = 0; $i < $n - 1; $i = $i + 1) { $out[] = $mainStmts[$i]; }
            $out[] = $drain;
            $out[] = $last;
            return $out;
        }
        $mainStmts[] = $drain;
        return $mainStmts;
    }

    private function injectGlobalDecls(array $mainStmts): array
    {
        $pre = [];
        foreach ($this->module->globalVarNames as $gname) {
            $used = false;
            foreach ($mainStmts as $s) {
                if ($this->nodeReadsLocal($s, $gname) || $this->nodeWritesLocal($s, $gname)) {
                    $used = true;
                    break;
                }
            }
            if ($used) {
                $pre[] = new StaticLocalDecl_($gname, '@g_' . $gname, '', null, Type::int_());
            }
        }
        if ($pre === []) { return $mainStmts; }
        foreach ($mainStmts as $s) { $pre[] = $s; }
        return $pre;
    }

    /** Whether the node tree reads the named local (LoadLocal). */
    private function nodeReadsLocal(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL && $n->name === $name) {
            return true;
        }
        foreach (Walk::children($n) as $c) {
            if ($this->nodeReadsLocal($c, $name)) { return true; }
        }
        return false;
    }

    /** Whether the node tree assigns the named local (StoreLocal). */
    private function nodeWritesLocal(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_STORE_LOCAL && $n->name === $name) {
            return true;
        }
        foreach (Walk::children($n) as $c) {
            if ($this->nodeWritesLocal($c, $name)) { return true; }
        }
        return false;
    }

    // Pin foreach-var helper structs (not Expr/Stmt subclasses) to their concrete
    // type before reading fields — a bare array element read resolves the wrong
    // offset under self-host (the poly-prop trap). Used by the usage-inference scan.

    /**
     * `foo(...)` first-class callable → a 0-capture closure whose body
     * forwards to `foo`. Reuses the closure machinery so `$f(args)`
     * invokes it like any other closure.
     */
    private function lowerFcc(string $fnName): Node
    {
        $decl = $this->fnDecls[$fnName] ?? null;
        // A stdlib extern is registered under its FQN (`Runtime\Libc\strcasecmp`)
        // with only a bare-name ALIAS, so the bare lookup above misses and the
        // unary fallback below built `fn($a) => strcasecmp($a)` for a
        // two-parameter function. The wrapper then took ONE arg while the
        // comparator was invoked with two — array_diff_ukey($a,$b,'strcasecmp')
        // SIGSEGV'd. Resolve through the same alias path the BODY call uses.
        $target = $fnName;
        if ($decl === null) {
            $resolved = $this->resolveCallName($fnName);
            if ($resolved !== $fnName && isset($this->fnDecls[$resolved])) {
                $decl = $this->fnDecls[$resolved];
                $target = $resolved;
            }
        }
        if ($decl === null) {
            // Builtin / unknown target — best-effort unary wrapper closure
            // `fn($a) => name($a)`. Covers scalar builtins (strtoupper/strlen/
            // abs/…); a builtin with a different arity won't match (rare as a
            // stored value — the pipe / direct-call paths handle those).
            [$mir, $loads] = $this->fccParamsAndArgs(null);
            $body = new Call($this->resolveCallName($fnName), $loads, Type::unknown());
            return $this->buildClosureNode($mir, [], [], [], $body, Type::unknown());
        }
        $callArgs = [];
        foreach ($decl->params as $p) {
            $callArgs[] = new LoadLocal($p->name, $this->lowerTypeHint($p->typeHint));
        }
        $ret = $this->lowerTypeHint($decl->returnType);
        $call = new Call($target, $callArgs, $ret);
        $body = new Block([new Return_($call, Type::void())], Type::void());
        return $this->finishClosure([], $decl->params, $body, $decl->returnType);
    }

    /**
     * MIR call-params + their LoadLocal arg nodes for a synthesised callable
     * wrapper. `null` declParams (unknown arity, e.g. a builtin) falls back to
     * a single cell param. Returns `[Param[], Node[]]`.
     */
    private function fccParamsAndArgs(?array $declParams): array
    {
        $mir = [];
        $loads = [];
        if ($declParams !== null) {
            // Rebind to a NON-nullable local before typing it: a `@param T[]` on
            // the nullable `?array` parameter itself coerces a null argument to
            // `[]` under the native self-build (dropping the __fa0 fallback →
            // a param-less closure). Type the local inside the null guard instead.
            /** @var \Parser\Ast\Param[] $dp */
            $dp = $declParams;
            foreach ($dp as $p) {
                $t = $this->lowerParamType($p->typeHint);
                $mir[] = new Param(name: $p->name, type: $t, byRef: (bool)($p->byRef ?? false), variadic: (bool)($p->variadic ?? false));
                $loads[] = new LoadLocal($p->name, $t);
            }
        } else {
            $mir[] = new Param(name: '__fa0', type: Type::cell(), byRef: false, variadic: false);
            $loads[] = new LoadLocal('__fa0', Type::cell());
        }
        return [$mir, $loads];
    }

    /** Closure forwarding to the static method `$class::$method` (LSB scope
     *  `$scope`). Shared by `C::m(...)` and `["C","m"]` callable coercion. */
    private function synthStaticClosure(string $class, string $method, string $scope): Node
    {
        /** @var \Parser\Ast\Param[] $declParams */
        $declParams = $this->resolveMethodParams($class, $method) ?? [];
        $loads = [];
        foreach ($declParams as $p) {
            $loads[] = new LoadLocal($p->name, $this->lowerTypeHint($p->typeHint));
        }
        $call = new StaticCall_($class, $method, $loads, Type::unknown(), $scope);
        $body = new Block([new Return_($call, Type::void())], Type::void());
        return $this->finishClosure([], $declParams, $body, null);
    }

    /** Closure capturing `$recv` and forwarding to `$recv->$method(...)`.
     *  Shared by `$o->m(...)` and `[$o,"m"]` callable coercion. */
    private function synthMethodClosure(Node $recv, string $method): Node
    {
        $cls = $recv->type->class ?? '';
        // An if-guard, NOT `$cls!=='' ? resolveMethodParams(…) : null`: a ternary
        // pairing an array arm with null lifts to a CELL ({@see InferTypes::
        // nullableOf} — correct for `is_null`/`gettype` on a local), but this
        // value goes to `fccParamsAndArgs(?array $p)`, a bare-`array` param that
        // reads RAW — a cell there faults. The if keeps `$declParams` a raw
        // `?array`. (The `?array` return-narrowing this pass now does made the
        // arm concrete, which is what triggers the ternary's cell-lift.)
        $declParams = null;
        if ($cls !== '') { $declParams = $this->resolveMethodParams($cls, $method); }
        [$mir, $loads] = $this->fccParamsAndArgs($declParams);
        $body = new MethodCall_(new LoadLocal("__frecv", $recv->type), $method, $loads, Type::unknown());
        return $this->buildClosureNode($mir, ['__frecv'], [$recv->type], [$recv], $body, Type::unknown());
    }

    /** A string callable `"fn"` / `"C::m"` applied to `$astArgs`. */
    private function lowerStringCallable(string $name, array $astArgs): Node
    {
        $cc = \strpos($name, '::');
        if ($cc !== false && $cc > 0) {
            $args = [];
            foreach ($astArgs as $a) { $args[] = $this->lowerExpr($a); }
            $cls = \ltrim(\substr($name, 0, $cc), '\\');
            return new StaticCall_($cls, \substr($name, $cc + 2), $args, Type::unknown(), $cls);
        }
        // Route through lowerCallArgs (not bare lowerExpr) so the callee's
        // #[RefOut] out-params auto-vivify — `('preg_match')($p, $s, $matches)`
        // must define $matches by ref exactly like a direct `preg_match(...)`.
        $resolved = $this->resolveCallName($name);
        return new Call($resolved, $this->lowerCallArgs($resolved, $astArgs), Type::unknown());
    }

    /**
     * A `str_set` callable (`$fn = cond ? 'preg_match_all' : 'preg_match'`)
     * applied to `$astArgs`: dispatch on `$fn`'s RUNTIME VALUE into the two
     * DIRECT calls rather than emitting a dynamic invoke.
     *
     * A dynamic invoke passes every argument by value, so the callee's
     * `#[RefOut]` out-param (preg_match's `$matches`) was never filled — the
     * caller read an undefined local and printed denormal floats. Only a direct
     * call carries the by-ref ABI, and only one arm ever executes, so the
     * duplicated argument lowering costs nothing at run time.
     */
    private function lowerStrSetCallable(string $var, string $n1, string $n2, array $astArgs): Node
    {
        $c1 = $this->lowerStringCallable($n1, $astArgs);
        if ($n2 === '' || $n2 === $n1) { return $c1; }
        $c2 = $this->lowerStringCallable($n2, $astArgs);
        $cond = new Cmp(
            new LoadLocal($var, Type::string_()),
            new StringConst($n1, Type::string_()),
            '==='
        );
        return new Ternary($cond, $c1, $c2, Type::unknown());
    }

    /** An array callable `[$o,"m"]` / `["C","m"]` applied to `$astArgs`, or
     *  null when the literal isn't a `[receiver, methodName]` shape. */
    private function lowerArrayCallable(\Parser\Ast\ArrayLit $arr, array $astArgs): ?Node
    {
        if (\count($arr->elements) !== 2) { return null; }
        $recvE = $this->elemValue($arr->elements[0]);
        $methE = $this->elemValue($arr->elements[1]);
        if ($methE->kind !== 'StringLiteral') { return null; }
        $m = $this->strLitValue($methE);
        $args = [];
        foreach ($astArgs as $a) { $args[] = $this->lowerExpr($a); }
        if ($recvE->kind === 'StringLiteral') {
            $cls = \ltrim($this->strLitValue($recvE), '\\');
            return new StaticCall_($cls, $m, $args, Type::unknown(), $cls);
        }
        return new MethodCall_($this->lowerExpr($recvE), $m, $args, Type::unknown());
    }

    private function elemValue(\Parser\Ast\ArrayElement $e): \Parser\Ast\Expr { return $e->value; }

    private function lowerClone(\Parser\Ast\CloneExpr $expr): Node
    {
        $obj = $this->lowerExpr($expr->object);
        // PHP 8.5 clone-with: collect string-keyed `['p' => v]` overrides
        // applied to the fresh copy after __clone(). Only literal keys.
        $with = [];
        $wp = $expr->withProps;
        if ($wp !== null && $wp->kind === 'ArrayLit') {
            foreach ($wp->elements as $el) {
                if ($el->key !== null && $el->key->kind === 'StringLiteral') {
                    $with[] = new \Compile\Mir\CloneWith($this->stringLitValue($el->key), $this->lowerExpr($el->value));
                }
            }
        }
        return new \Compile\Mir\Clone_($obj, $with, $obj->type);
    }

    /** Resolve self/static/parent + leading slashes to a concrete class name. */
    private function resolveStaticClass(string $class): string
    {
        $low = \strtolower($class);
        if ($low === 'self') { return $this->currentLowerClass; }
        if ($low === 'static') {
            $this->sawStaticUse = true;
            return $this->currentStaticClass !== ''
                ? $this->currentStaticClass : $this->currentLowerClass;
        }
        if ($low === 'parent') {
            if (isset($this->classTable[$this->currentLowerClass])) {
                return $this->classTable[$this->currentLowerClass]->parent;
            }
            return $this->currentLowerClass;
        }
        return \ltrim($class, '\\');
    }

    /** Declaring class of static prop `$name` (walk parents), or ''. */
    private function staticPropDeclClass(string $class, string $name): string
    {
        $c = $class;
        while ($c !== '') {
            if (isset($this->staticProps[$c . '::' . $name])) { return $c; }
            if (!isset($this->classTable[$c])) { return ''; }
            $c = $this->classTable[$c]->parent;
        }
        return '';
    }

    /** `Class::$prop` read node, or null if not a static property. */
    /**
     * Lower a bare `Identifier` — a named constant. Covers the PHP
     * predefined constants the compiler source uses; `true`/`false`/
     * `null` too (the parser sometimes hands them through as identifiers).
     */
    private function lowerIdentifier(string $rawName, int $line = 0): Node
    {
        // An unqualified constant resolves in the current namespace
        // first, then the global one — so `Compile\PHP_INT_MAX` is really
        // the global `PHP_INT_MAX`. Match on the trailing segment.
        $name = $this->constBareName($rawName);
        $pre = $this->predefinedConstant($name);
        if ($pre !== null) { return $pre; }
        if (isset($this->userConstants[$name])) {
            $this->noteDeprecatedConstUse($name, $line);
            return $this->lowerExpr($this->userConstants[$name]);
        }
        $low = \strtolower($name);
        if ($low === 'true')  { return new BoolConst(true, Type::bool_()); }
        if ($low === 'false') { return new BoolConst(false, Type::bool_()); }
        if ($low === 'null')  { return new NullConst(Type::null_()); }
        throw new \RuntimeException('MIR.lower: unknown constant ' . $name);
    }

    /** The trailing segment of a possibly-namespaced constant name. */
    private function constBareName(string $raw): string
    {
        $bs = \strrpos($raw, '\\');
        return $bs === false ? $raw : \substr($raw, $bs + 1);
    }

    private function staticPropRef(string $rawClass, string $rawName): ?StaticProp_
    {
        $cls = $this->resolveStaticClass($rawClass);
        $pn = $rawName;
        if (\strlen($pn) > 0 && $pn[0] === '$') { $pn = \substr($pn, 1); }
        $dc = $this->staticPropDeclClass($cls, $pn);
        if ($dc === '') { return null; }
        $global = '@' . $this->sanitizeSym($dc . '__sp_' . $pn);
        $pt = $this->staticPropTypes[$dc . '::' . $pn] ?? Type::int_();
        return new StaticProp_($global, $pt);
    }

    /**
     * `static $a = e, $b;` → one {@see StaticLocalDecl_} per binding,
     * each backed by a module global cell `@<fn>__sl_<name>`. A binding
     * with an initialiser also gets a once-init guard cell so the init
     * runs on the first call only.
     *
     * The name is SANITIZED, like a static property's: inside a namespaced
     * function the cell would otherwise be `@Ns\fn__sl_x`, and a backslash in
     * an unquoted LLVM identifier is a parse error ("expected '=' in global
     * variable"). Every static local in this tree happened to sit in a
     * global-namespace file, so the whole class of function was unbuildable
     * without anyone finding out.
     */
    private function lowerStaticLocal(\Parser\Ast\StaticLocalStmt $stmt): Node
    {
        // Sanitize the class+fn base like the static-PROPERTY path (__sp_): a
        // namespaced class (`Symfony\Component\…`) has backslashes, which are
        // illegal in an unquoted LLVM global name (`@…\… = global` → "expected '='").
        $rawBase = $this->currentLowerClass !== ''
            ? $this->currentLowerClass . '__' . $this->currentLowerFn
            : $this->currentLowerFn;
        $nodes = [];
        foreach ($stmt->decls as $d) {
            $cell = '@' . $this->sanitizeSym($rawBase . '__sl_' . $d->name);
            $this->module->addGlobalCell($cell, new IntConst(0, Type::int_()));
            $guard = '';
            $init = null;
            if ($d->default !== null) {
                $guard = $cell . '__init';
                $this->module->addGlobalCell($guard, new IntConst(0, Type::int_()));
                $init = $this->lowerExpr($d->default);
            }
            $nodes[] = new StaticLocalDecl_($d->name, $cell, $guard, $init, Type::int_());
        }
        return new Block($nodes, Type::void());
    }

    private function lowerCast(\Parser\Ast\Cast $expr): Node
    {
        $operand = $this->lowerExpr($expr->operand);
        $c = \strtolower($expr->cast);
        // `(void) f()` evaluates and discards. Lowering it AWAY (rather than
        // minting a Cast node) keeps the call node in statement position, which
        // is what emitDiscardedCallRelease keys on — a Cast wrapper would hide
        // the call from it and LEAK the discarded result.
        if ($c === 'void') { return $operand; }
        $target = 'int';
        $type = Type::int_();
        if ($c === 'float' || $c === 'double') { $target = 'float'; $type = Type::float_(); }
        elseif ($c === 'string') { $target = 'string'; $type = Type::string_(); }
        elseif ($c === 'bool' || $c === 'boolean') { $target = 'bool'; $type = Type::bool_(); }
        elseif ($c === 'object') { $target = 'object'; $type = Type::obj('stdClass'); }
        elseif ($c === 'array')  { $target = 'array'; $type = Type::assoc(Type::string_(), Type::cell()); }
        return new Cast($target, $operand, $type);
    }

    private function lowerMatch(\Parser\Ast\MatchExpr $expr): Match_
    {
        $subject = $this->lowerExpr($expr->subject);
        $arms = [];
        foreach ($expr->arms as $arm) {
            $conds = null;
            if ($arm->conds !== null) {
                $conds = [];
                foreach ($arm->conds as $c) { $conds[] = $this->lowerExpr($c); }
            }
            $body = $this->lowerExpr($arm->body);
            $arms[] = new MatchArm_($conds, $body);
        }
        return new Match_($subject, $arms, Type::unknown());
    }

    /**
     * `$y = &$x` — local reference binding. When both sides are plain
     * locals, `$y` aliases `$x`'s slot (RefAlias_). Other sources
     * (e.g. `&fn()` by-ref return) fall back to a value copy — not
     * true reference semantics, but non-crashing.
     */
    private function lowerRefAssign(\Parser\Ast\RefAssign $expr): Node
    {
        if ($expr->target->kind === 'Variable' && $expr->source->kind === 'Variable') {
            return new RefAlias_($expr->target->name, $expr->source->name, Type::void());
        }
        // `$r = &fn(...)` / `$r = &$obj->m()` / `$r = &Cls::m()` — bind $r as a
        // reference to the by-ref return (the callee yields the raw address;
        // emitRefBind sets rawRefCall so the value-context deref is suppressed).
        if ($expr->target->kind === 'Variable'
            && ($expr->source->kind === 'Call'
                || $expr->source->kind === 'MethodCall'
                || $expr->source->kind === 'StaticCall')) {
            return new RefBind_($expr->target->name, $this->lowerExpr($expr->source), Type::void());
        }
        // `$r = &$obj->prop` / `$r = &$a[$k]` — bind $r to the container slot's
        // ADDRESS so reads/writes of $r alias the property / element.
        if ($expr->target->kind === 'Variable'
            && ($expr->source->kind === 'PropertyAccess'
                || $expr->source->kind === 'ArrayAccess')) {
            $lv = $this->lowerExpr($expr->source);
            return new RefAddr_($expr->target->name, $lv, $lv->type);
        }
        return $this->storeToTarget($expr->target, $this->lowerExpr($expr->source));
    }

    private function lowerAssign(\Parser\Ast\Assign $expr): Node
    {
        if ($expr->target->kind === 'Variable') {
            $this->trackCallableAssign($this->varName($expr->target), $expr->value);
        }
        return $this->storeToTarget($expr->target, $this->lowerExpr($expr->value));
    }

    private function varName(\Parser\Ast\Variable $v): string { return $v->name; }

    /**
     * Update {@see $constCallables} for `$name = $value`: drop any callable
     * previously bound to `$name`, then record `$name` if `$value` is a callable
     * literal. (An array-callable's receiver needs no invalidation — the call is
     * lowered against the array slot snapshot `$name[0]`, not the live recv var.)
     */
    private function trackCallableAssign(string $name, \Parser\Ast\Expr $value): void
    {
        unset($this->constCallables[$name]);
        $info = $this->callableLiteralInfo($value);
        // `str_set` is NOT a straight-line binding — it is handled only via
        // {@see scanStableCallables} + the dynamic-invoke fall-through in
        // lowerInvoke (lowerConstCallable has no str_set arm). Keep it out of the
        // straight-line tracker so it never reaches lowerConstCallable's
        // string/array dispatch (which would misread its shape).
        if ($info !== null && $info['kind'] !== 'str_set') { $this->constCallables[$name] = $info; }
        // Track a `$var = new C(...)` binding for receiver-class-aware variadic
        // packing; any other assignment drops it (a later `$var->m()` then falls
        // back to the by-name union).
        unset($this->localNewClasses[$name]);
        if ($value->kind === 'New') { $this->localNewClasses[$name] = \ltrim($value->class, '\\'); }
    }

    /** Classify a callable-literal assignment value, or null. */
    private function callableLiteralInfo(\Parser\Ast\Expr $value): ?array
    {
        if ($value->kind === 'StringLiteral') {
            return ['kind' => 'str', 'name' => $this->strLitValue($value)];
        }
        // `$fn = cond ? 'preg_match_all' : 'preg_match'` — both arms name known
        // functions. Track the pair so the invoke dispatches on `$fn`'s VALUE to
        // DIRECT calls (a `#[RefOut]` out-arg like preg_match's `$matches` fills
        // by reference — a dynamic `$fn(...)` invoke cannot pass one by ref).
        if ($value->kind === 'Ternary') {
            $tv = $value;
            $thenE = $this->ternaryThenExpr($tv);
            $elseE = $this->ternaryElseExpr($tv);
            if ($thenE !== null && $thenE->kind === 'StringLiteral' && $elseE->kind === 'StringLiteral') {
                $n1 = $this->strLitValue($thenE);
                $n2 = $this->strLitValue($elseE);
                if ($this->functionIsKnown($n1) && $this->functionIsKnown($n2)) {
                    // Two FLAT string slots, never a nested `['names' => [...]]`:
                    // this map's other arms are all `array<string, string>`, and a
                    // single array-valued slot makes the whole return MIXED — the
                    // reader then unboxes a raw string pointer as a cell and the
                    // compiler SIGSEGVs on the unrelated `arr_obj` path.
                    return ['kind' => 'str_set', 'name' => $n1, 'name2' => $n2];
                }
            }
        }
        if ($value->kind === 'ArrayLit') {
            $els = $this->arrayLitElements($value);
            if (\count($els) !== 2) { return null; }
            $recvE = $this->elemValue($els[0]);
            $methE = $this->elemValue($els[1]);
            if ($methE->kind !== 'StringLiteral') { return null; }
            $m = $this->strLitValue($methE);
            if ($recvE->kind === 'StringLiteral') {
                return ['kind' => 'arr_static', 'class' => \ltrim($this->strLitValue($recvE), '\\'), 'method' => $m];
            }
            if ($recvE->kind === 'Variable') {
                // `[$o, "m"]` — the receiver is read back from the array slot at
                // the call site (string-only info; storing the Expr here trips
                // the native object-in-cell-array path).
                return ['kind' => 'arr_obj', 'method' => $m];
            }
        }
        return null;
    }

    /**
     * Record body-stable `str_set` callables (a var assigned a ternary of two
     * function-name literals) from the top-level statements, so a `$fn(...)`
     * invoke resolves to a direct dispatch even across an intervening `if`/`try`
     * that clears the straight-line tracker. Only `str_set` is recorded — it
     * dispatches on `$fn`'s RUNTIME value, so a later reassignment stays correct
     * as long as `$fn` holds one of the two names (the symfony preg pattern);
     * plain-string callables stay straight-line-only, where reassignment matters.
     *
     * @param \Parser\Ast\Stmt[] $stmts
     */
    /**
     * Record the DECLARED parameter names of the body being lowered, in order,
     * so `func_num_args()` / `func_get_arg($k)` resolve against them.
     *
     * @param \Parser\Ast\Param[] $params
     */
    private function setCurrentLowerParams(array $params): void
    {
        $this->currentLowerParams = [];
        foreach ($params as $p) { $this->currentLowerParams[] = $p->name; }
    }

    private function scanStableCallables(array $stmts): void
    {
        $this->stableCallables = [];
        $this->stableCallablesAlt = [];
        foreach ($stmts as $s) {
            if ($s->kind !== 'Expression') { continue; }
            $e = $this->expressionStmtExpr($s);
            if ($e->kind !== 'Assign') { continue; }
            $t = $this->assignTarget($e);
            if ($t->kind !== 'Variable') { continue; }
            $info = $this->callableLiteralInfo($this->assignValue($e));
            if ($info !== null && $info['kind'] === 'str_set') {
                $vn = $this->varName($t);
                $this->stableCallables[$vn] = $info['name'];
                $this->stableCallablesAlt[$vn] = $info['name2'];
            }
        }
    }

    private function expressionStmtExpr(\Parser\Ast\ExpressionStmt $s): \Parser\Ast\Expr { return $s->expr; }
    private function assignTarget(\Parser\Ast\Assign $a): \Parser\Ast\Expr { return $a->target; }
    private function assignValue(\Parser\Ast\Assign $a): \Parser\Ast\Expr { return $a->value; }

    /** Lower a tracked callable variable `$var` invoked as `$var(args)` to the
     *  direct call. */
    private function lowerConstCallable(string $var, array $info, array $astArgs): Node
    {
        if ($info['kind'] === 'str') {
            return $this->lowerStringCallable($info['name'], $astArgs);
        }
        $args = [];
        foreach ($astArgs as $a) { $args[] = $this->lowerExpr($a); }
        if ($info['kind'] === 'arr_static') {
            $cls = $info['class'];
            return new StaticCall_($cls, $info['method'], $args, Type::unknown(), $cls);
        }
        // arr_obj: dispatch on the receiver SNAPSHOT held in the array's slot 0
        // (`$var[0]`) — `[$o,"m"]` binds `$o`'s value at array creation, so this
        // stays correct even if `$o` is later reassigned.
        $recv = new ArrayAccess_(new LoadLocal($var, Type::unknown()), new IntConst(0, Type::int_()), Type::cell());
        return new MethodCall_($recv, $info["method"], $args, Type::unknown());
    }

    private function arrayLitElements(\Parser\Ast\ArrayLit $a): array { return $a->elements; }

    /**
     * Expand top-level compile-time `if` guards: an `if` whose condition (and any
     * preceding elseif) folds statically is replaced by its live branch's
     * statements (recursively), so a declaration it guards hoists to the top
     * level or is dropped. Any non-foldable `if` is left as-is.
     *
     * @param \Parser\Ast\Stmt[] $stmts
     * @return \Parser\Ast\Stmt[]
     */
    private function flattenConstantIfs(array $stmts): array
    {
        $out = [];
        foreach ($stmts as $s) {
            if ($s->kind === 'If') {
                $branch = $this->constIfBranch($s);
                if ($branch !== null) {
                    foreach ($this->flattenConstantIfs($branch) as $b) {
                        $this->registerHoistedDecl($b);
                        $out[] = $b;
                    }
                    continue;
                }
            }
            $out[] = $s;
        }
        return $out;
    }

    /**
     * The live branch's statements of a compile-time `if`, or null when the guard
     * (or a preceding elseif guard) is not statically foldable.
     *
     * @return \Parser\Ast\Stmt[]|null
     */
    private function constIfBranch(\Parser\Ast\IfStmt $s): ?array
    {
        $c = $this->foldGuard($s->condition);
        if ($c === self::GUARD_UNKNOWN) { return null; }
        if ($c === self::GUARD_TRUE) { return $s->then->statements; }
        foreach ($s->elseifs as $arm) {
            $ac = $this->foldGuard($arm->condition);
            if ($ac === self::GUARD_UNKNOWN) { return null; }
            if ($ac === self::GUARD_TRUE) { return $arm->body->statements; }
        }
        return $s->else !== null ? $s->else->statements : [];
    }

    /** Register a hoisted top-level function declaration so calls to it resolve. */
    private function registerHoistedDecl(\Parser\Ast\Stmt $s): void
    {
        if ($s->kind !== 'Function') { return; }
        $fqn = $s->decl->name;
        $this->fnDecls[$fqn] = $s->decl;
        $pos = \strrpos($fqn, '\\');
        if ($pos !== false) {
            $bare = \substr($fqn, $pos + 1);
            $this->fnAliasByBare[$bare] = isset($this->fnAliasByBare[$bare]) ? '' : $fqn;
        }
    }

    /** foldGuard: not statically foldable — the guard stays for runtime. */
    private const GUARD_UNKNOWN = -1;
    private const GUARD_FALSE = 0;
    private const GUARD_TRUE = 1;

    /**
     * Compile-time truth of a declaration guard as a TRI-STATE:
     * GUARD_TRUE / GUARD_FALSE / GUARD_UNKNOWN. Folds `function_exists('X')`
     * (the same test the expression fold uses), `defined`, the `class_exists`
     * family, `extension_loaded`, a constant `===`/`!==`, bool literals, and
     * `!` / `&&` / `||` over any of those.
     *
     * The tri-state is an int ON PURPOSE. This used to return `?bool`, and a
     * nullable scalar's null does NOT read back as null under the self-host
     * (invisible under Zend, where the same code is correct) — so UNKNOWN
     * arrived at the call sites as FALSE and folded away live branches: the
     * compiler built by the compiler dropped `$n > 1` from
     * `function_exists('have') && $n > 1`. Never widen this back to `?bool`.
     */
    private function foldGuard(\Parser\Ast\Expr $e): int
    {
        // Dispatch by kind into TYPED helpers. A subclass field read off a
        // base-`Expr` receiver resolves by the wrong offset under the self-host
        // (Expr declares only kind/span), so every access below happens behind a
        // concrete parameter type.
        $k = $e->kind;
        if ($k === 'BoolLiteral') { return $this->boolLitValue($e) ? self::GUARD_TRUE : self::GUARD_FALSE; }
        if ($k === 'UnaryOp')     { return $this->foldGuardUnary($e); }
        if ($k === 'BinaryOp')    { return $this->foldGuardBinary($e); }
        if ($k === 'Call')        { return $this->foldGuardCall($e); }
        return self::GUARD_UNKNOWN;
    }

    private function boolLitValue(\Parser\Ast\BoolLiteral $b): bool { return $b->value; }

    private function intLitValue(\Parser\Ast\IntLiteral $i): int { return $i->value; }

    private function identifierName(\Parser\Ast\Identifier $i): string { return $i->name; }

    private function guardOf(bool $v): int { return $v ? self::GUARD_TRUE : self::GUARD_FALSE; }

    private function foldGuardUnary(\Parser\Ast\UnaryOp $e): int
    {
        if ($e->op !== '!') { return self::GUARD_UNKNOWN; }
        $v = $this->foldGuard($e->operand);
        if ($v === self::GUARD_UNKNOWN) { return self::GUARD_UNKNOWN; }
        return $v === self::GUARD_TRUE ? self::GUARD_FALSE : self::GUARD_TRUE;
    }

    private function foldGuardBinary(\Parser\Ast\BinaryOp $e): int
    {
        $op = $e->op;
        if ($op === '&&' || $op === 'and') {
            $l = $this->foldGuard($e->left);
            if ($l === self::GUARD_FALSE) { return self::GUARD_FALSE; }
            $r = $this->foldGuard($e->right);
            if ($r === self::GUARD_FALSE) { return self::GUARD_FALSE; }
            return ($l === self::GUARD_UNKNOWN || $r === self::GUARD_UNKNOWN)
                ? self::GUARD_UNKNOWN : self::GUARD_TRUE;
        }
        if ($op === '||' || $op === 'or') {
            $l = $this->foldGuard($e->left);
            if ($l === self::GUARD_TRUE) { return self::GUARD_TRUE; }
            $r = $this->foldGuard($e->right);
            if ($r === self::GUARD_TRUE) { return self::GUARD_TRUE; }
            return ($l === self::GUARD_UNKNOWN || $r === self::GUARD_UNKNOWN)
                ? self::GUARD_UNKNOWN : self::GUARD_FALSE;
        }
        // Constant strict comparison — `'\\' === DIRECTORY_SEPARATOR`,
        // `PHP_OS_FAMILY === 'Windows'`. Both sides compile-time scalars ⇒ a
        // definite bool, so the Windows / platform-specific dead branch (which
        // often calls functions this target lacks) drops before lowering.
        if ($op === '===' || $op === '!==') {
            $lk = $this->constScalarKey($e->left);
            $rk = $this->constScalarKey($e->right);
            if ($lk === null || $rk === null) { return self::GUARD_UNKNOWN; }
            $eq = $lk === $rk;
            return $this->guardOf($op === '===' ? $eq : !$eq);
        }
        return self::GUARD_UNKNOWN;
    }

    private function foldGuardCall(\Parser\Ast\CallExpr $e): int
    {
        if (\count($e->args) !== 1) { return self::GUARD_UNKNOWN; }
        if (true) {
            // An unqualified builtin call inside a namespace resolves to
            // `Ns\class_exists` in the AST (PHP falls back to the global at
            // runtime); match on the trailing segment so the guard folds
            // regardless of the enclosing namespace.
            $qual = \ltrim($e->function, '\\');
            $qpos = \strrpos($qual, '\\');
            $fn = $qpos === false ? $qual : \substr($qual, $qpos + 1);
            $a0 = $e->args[0];
            if ($fn === 'function_exists') {
                // The name is usually a string literal, but symfony writes
                // `function_exists(u::class)` — a `::class` constant resolving to
                // the fully-qualified function name. guardClassArgName handles both.
                $cn = $this->guardClassArgName($a0);
                if ($cn === null) { return self::GUARD_UNKNOWN; }
                return $this->guardOf($this->functionIsKnown($cn));
            }
            // `defined('NAME')` — whole-program AOT knows every constant, so an
            // unknown name is definitively false (mirrors the expression fold in
            // LowerExprs). Lets a `defined('SIGINT')`-guarded branch that names an
            // undefined constant drop before that name reaches lowering.
            if ($fn === 'defined' && $a0->kind === 'StringLiteral') {
                $nm = $this->constBareName($this->stringLitValue($a0));
                return $this->guardOf($this->predefinedConstant($nm) !== null || isset($this->userConstants[$nm]));
            }
            // `class_exists(X)` / interface_ / trait_ / enum_exists guarding an
            // OPTIONAL-dependency branch (`if (class_exists(CliDumper::class)) {
            // … CliDumper::CONST … }`). A name the whole-program build does NOT
            // contain is definitively absent → fold FALSE so the dead branch
            // (which references the missing class) never reaches lowering. A KNOWN
            // name is left unfolded (null) — the branch compiles normally, so no
            // guess about class-vs-interface truthiness is needed.
            if ($fn === 'class_exists' || $fn === 'interface_exists'
                || $fn === 'trait_exists' || $fn === 'enum_exists') {
                $cn = $this->guardClassArgName($a0);
                if ($cn === null) { return self::GUARD_UNKNOWN; }
                $known = isset($this->knownClassNames[$cn]) || isset($this->traitTable[$cn]);
                return $known ? self::GUARD_UNKNOWN : self::GUARD_FALSE;
            }
            // `extension_loaded('X')`. A whole-program build has a FIXED set of
            // built-in extensions — nothing can be dlopen'd later — so the answer
            // is a compile-time constant. It matters because the polyfills gate
            // on it: `if (extension_loaded('mbstring')) { … }` in
            // symfony/polyfill-mbstring must fold FALSE so the polyfill's own
            // implementation is the one that compiles, and pcntl's absence must
            // drop a branch that names functions this build has no definition for.
            if ($fn === 'extension_loaded' && $a0->kind === 'StringLiteral') {
                return $this->guardOf($this->extensionIsBuiltIn(\strtolower($this->stringLitValue($a0))));
            }
        }
        return self::GUARD_UNKNOWN;
    }

    /**
     * The extensions a compiled binary genuinely carries. `pcre` is linked
     * (pcre2), `json` / `ctype` are built in, `openssl` rides the TLS stack.
     * Everything else — mbstring, intl, pcntl, dom — is absent, and a program
     * that asks gets the honest answer rather than a link-time surprise.
     */
    private function extensionIsBuiltIn(string $ext): bool
    {
        return $ext === 'pcre' || $ext === 'json' || $ext === 'ctype'
            || $ext === 'openssl' || $ext === 'core' || $ext === 'standard';
    }

    /** A type-tagged key for a compile-time scalar expression (`s:`/`i:`/`b:`/`n:`),
     *  or null when not a foldable constant. Used to strictly compare two
     *  constants in a guard — same key ⇔ `===` true. */
    private function constScalarKey(\Parser\Ast\Expr $e, int $depth = 0): ?string
    {
        if ($depth > 8) { return null; }   // guard mutually-recursive `define`s
        $k = $e->kind;
        if ($k === 'StringLiteral') { return 's:' . $this->stringLitValue($e); }
        if ($k === 'IntLiteral')    { return 'i:' . (string)$this->intLitValue($e); }
        if ($k === 'BoolLiteral')   { return 'b:' . ($this->boolLitValue($e) ? '1' : '0'); }
        if ($k === 'NullLiteral')   { return 'n:'; }
        if ($k === 'Identifier') {
            $nm = $this->constBareName($this->identifierName($e));
            if (isset($this->userConstants[$nm])) {
                return $this->constScalarKey($this->userConstants[$nm], $depth + 1);
            }
            $pre = $this->predefinedConstant($nm);
            if ($pre !== null) { return $this->nodeScalarKey($pre); }
        }
        // `X::class` folds to its resolved FQN string.
        if ($k === 'StaticAccess' && \strtolower($this->staticAccessName($e)) === 'class') {
            return 's:' . \ltrim($this->staticAccessClass($e), '\\');
        }
        return null;
    }

    /** constScalarKey for an already-lowered constant Node (from predefinedConstant). */
    private function nodeScalarKey(Node $n): ?string
    {
        $k = $n->kind;
        if ($k === Node::KIND_STRING_CONST) { return 's:' . $n->value; }
        if ($k === Node::KIND_INT_CONST)    { return 'i:' . (string)$n->value; }
        if ($k === Node::KIND_BOOL_CONST)   { return 'b:' . ($n->value ? '1' : '0'); }
        if ($k === Node::KIND_NULL_CONST)   { return 'n:'; }
        return null;
    }

    /** The class name a `class_exists(...)`-style guard tests: a string literal
     *  or a `X::class` constant (already namespace-resolved), else null for a
     *  runtime-only name. */
    private function guardClassArgName(\Parser\Ast\Expr $a): ?string
    {
        if ($a->kind === 'StringLiteral') { return \ltrim($this->stringLitValue($a), '\\'); }
        if ($a->kind === 'StaticAccess'
            && \strtolower($this->staticAccessName($a)) === 'class') {
            return \ltrim($this->staticAccessClass($a), '\\');
        }
        return null;
    }

    /**
     * Functions the stdlib DEFINES but that must read as ABSENT.
     *
     * A guard the folder cannot reach still emits its call — `if ($cp) {
     * sapi_windows_cp_set($cp); }` is guarded by a VALUE, not a predicate, so
     * the symbol has to exist or the link fails. But letting `function_exists`
     * see it would send the program down the Windows path it was trying to
     * avoid. So: the body links, and every observer says it is not there — which
     * is exactly true of this target.
     *
     * These four belong to the per-OS symbol table the target-abi work will own;
     * until then the set is small enough to name.
     */
    private const HIDDEN_FNS = [
        'sapi_windows_cp_conv' => true,
        'sapi_windows_cp_get' => true,
        'sapi_windows_cp_set' => true,
        'sapi_windows_vt100_support' => true,
    ];

    /**
     * Names the compiler always resolves even though nothing DECLARES them:
     * a construct the parser turns into a Call, or a call it rewrites to an
     * internal helper (`trigger_error` → `__mc_trigger_error`).
     *
     * Without these `function_exists` answered false for functions that plainly
     * work — `trigger_error` routes through set_error_handler correctly, yet
     * every guard around it took the "not available" branch.
     */
    private const RESOLVED_FNS = [
        'function_exists' => true, 'defined' => true, 'constant' => true,
        'compact' => true, 'call_user_func' => true, 'call_user_func_array' => true,
        'trigger_error' => true,
    ];

    /** Whether a function name is already declared (user or stdlib extern / alias)
     *  — the same predicate the `function_exists` expression fold uses. */
    private function functionIsKnown(string $name): bool
    {
        $nm = \ltrim($name, '\\');
        $pos = \strrpos($nm, '\\');
        $bare = $pos === false ? $nm : \substr($nm, $pos + 1);
        if (isset(self::HIDDEN_FNS[$bare])) { return false; }
        if (isset(self::RESOLVED_FNS[\strtolower($bare)])) { return true; }
        // A CODEGEN BUILTIN is emitted inline, so it is declared nowhere and
        // used to read as absent: `function_exists('strlen')` was false, and so
        // was count/substr/implode/min/max/ord/chr/get_class/… — 28 of the 44
        // names measured against the interpreter. That is the exact shape the
        // polyfill idiom tests (`if (!function_exists('X')) { function X(){} }`),
        // and any `function_exists('X') ? fast : slow` picked the slow arm.
        if ($this->isCodegenBuiltin($bare)) { return true; }
        return isset($this->fnDecls[$nm]) || isset($this->fnDecls[$bare])
            || (($this->fnAliasByBare[$bare] ?? '') !== '');
    }

    /**
     * Lower a call's arguments into positional MIR order for `$fnName`,
     * reordering named args and filling omitted trailing params from
     * their defaults. Unknown callees (builtins) fall back to plain
     * positional lowering.
     * @param \Parser\Ast\Expr[] $astArgs
     * @return Node[]
     */
    /**
     * Queue an auto-viv init for every `#[RefOut]` arg that is a bare variable:
     * an empty array typed as the (out) parameter's element type, stored into
     * the variable before the call. That defines an otherwise-undefined out-var
     * (`preg_match($p, $s, $m)` with no prior `$m`) AND types it so the read-back
     * carries the parameter's element type instead of erasing to `unknown`.
     * Positional-only — a named-arg call skips this (rare for out-params).
     * An out-param is known from the callee's `#[RefOut(...)]` attribute
     * (same-unit decl) OR the param's `refOut` flag (carried across the .sig).
     *
     * @param \Parser\Ast\Expr[] $astArgs
     */
    private function collectRefOutInits(\Parser\Ast\FunctionDecl $decl, array $astArgs): void
    {
        $names = $this->refOutParamNames($decl->attributes);
        $params = $decl->params;
        $i = 0;
        foreach ($astArgs as $a) {
            $p = $params[$i] ?? null;
            $i = $i + 1;
            if ($a->kind === 'NamedArg') { return; }
            if ($p === null) { continue; }
            // Typed reads — a base-typed `$p`/`$a` resolves fields by OFFSET
            // under self-host and picks the wrong slot.
            if (!isset($names[$this->paramName($p)]) && !$this->paramRefOut($p)
                && !$this->paramHasRefOutAttr($p)) { continue; }
            if ($a->kind !== 'Variable') { continue; }
            // Only ARRAY out-params get auto-vivified: the empty-array init both
            // defines the var and types it `vec[cell]` (so captures read back as
            // tagged cells). A SCALAR out-param (`int &$count`) must NOT get an
            // array init — that would clobber a pre-set `$c = 0` with an array and
            // corrupt the heap when the callee writes an int through the ref.
            $hint = $this->paramTypeHint($p);
            if ($hint === null) {
                $pt = Type::vec(Type::cell());          // erased out-array → cell array
            } else {
                $pt = $this->lowerTypeHint($hint);
                if (!$pt->isArray()) {
                    // A SCALAR #[RefOut] (`preg_replace(…, int &$count)`) is fresh-out
                    // by the RefOut rule, so it may not exist at the call site. DEFINE
                    // it with the type's zero — else a later read (`+ $count`) is a
                    // dangling local. No array init here (that would corrupt the heap
                    // when the callee writes a scalar through the ref); the callee
                    // overwrites the zero, matching php's "0 when nothing matched".
                    $k = $pt->kind;
                    $zero = ($k === Type::KIND_INT || $k === Type::KIND_BOOL)
                        ? new IntConst(0, $pt)
                        : (($k === Type::KIND_FLOAT)
                            ? new FloatConst(0.0, $pt)
                            : new NullConst(Type::null_()));
                    $sinit = new StoreLocal($this->variableName($a), $zero, $pt);
                    $sinit->declaredType = $pt;
                    $this->pendingCallInits[] = $sinit;
                    continue;
                }
            }
            // declaredType seeds the SLOT type (the `@var` path) so InferTypes
            // keeps `vec[cell]` — an empty `[]` literal otherwise re-infers to
            // vec[unknown], and the callee-written cells read back as raw ints.
            $init = new StoreLocal($this->variableName($a), new ArrayLit([], $pt), $pt);
            $init->declaredType = $pt;
            $this->pendingCallInits[] = $init;
        }
    }

    /** Param->refOut via a typed param (self-host offset). */
    private function paramRefOut(\Parser\Ast\Param $p): bool { return $p->refOut; }

    /** Variable->name via a typed param (subclass field, self-host offset). */
    private function variableName(\Parser\Ast\Variable $v): string { return $v->name; }

    private function lowerCallArgs(string $fnName, array $astArgs): array
    {
        if (!isset($this->fnDecls[$fnName])) {
            $out = [];
            foreach ($astArgs as $a) { $out[] = $this->lowerExpr($a); }
            return $out;
        }
        $params = $this->fnDecls[$fnName]->params;
        $this->collectRefOutInits($this->fnDecls[$fnName], $astArgs);
        // Fast positional path also coerces a literal callable bound to a
        // `callable` param into a closure (`array_map("strtoupper", …)`), so the
        // callee invokes it like any closure. Named / defaulted / variadic calls
        // fall through to defaultFillArgs (no callable literal in the corpus).
        $hasNamed = false;
        foreach ($astArgs as $a) { if ($a->kind === 'NamedArg') { $hasNamed = true; break; } }
        $np = \count($params);
        $variadic = $np > 0 && $this->paramVariadic($params[$np - 1]);
        if (!$hasNamed && !$variadic && \count($astArgs) >= $np) {
            $out = [];
            $i = 0;
            foreach ($astArgs as $a) {
                $conv = $i < $np ? $this->coerceCallableArg($this->lowerParamType($this->paramTypeHint($params[$i])), $a) : null;
                $out[] = $conv !== null ? $conv : $this->lowerExpr($a);
                $i = $i + 1;
            }
            return $out;
        }
        return $this->defaultFillArgs($params, $astArgs);
    }

    private function paramTypeHint(\Parser\Ast\Param $p): ?string { return $p->typeHint; }

    private function namedArgName(\Parser\Ast\NamedArg $a): string { return $a->name; }
    private function namedArgValue(\Parser\Ast\NamedArg $a): \Parser\Ast\Expr { return $a->value; }
    private function paramName(\Parser\Ast\Param $p): string { return $p->name; }
    private function paramVariadic(\Parser\Ast\Param $p): bool { return (bool)($p->variadic ?? false); }
    private function paramDefault(\Parser\Ast\Param $p): ?\Parser\Ast\Expr { return $p->default; }
    private function staticAccessClass(\Parser\Ast\StaticAccess $e): string { return $e->class; }
    private function staticAccessName(\Parser\Ast\StaticAccess $e): string { return $e->name; }
    private function dynStaticName(\Parser\Ast\DynamicStaticAccess $e): string { return $e->name; }
    private function dynStaticReceiver(\Parser\Ast\DynamicStaticAccess $e): \Parser\Ast\Expr { return $e->receiver; }
    private function declName(\Parser\Ast\ClassDecl $d): string { return $d->name; }

    /**
     * `$cls::CONST` / `$cls::$sp` with a runtime class-name string. Lower to a
     * ternary chain over every class that actually declares the member —
     * `$cls === "A" ? A::MEMBER : ($cls === "B" ? … : null)` — reusing the
     * literal static-access lowering (const inline / static-prop global) for
     * each arm. The receiver is normalised to a class-NAME string first —
     * `is_object($cls) ? get_class($cls) : $cls` — so an object receiver
     * (`$obj::CONST`) resolves through the same name-ternary as a string one.
     * The name expression is re-lowered per condition; a plain `$cls` variable is
     * side-effect-free, so it evaluates identically each time.
     */
    private function lowerDynStaticAccess(\Parser\Ast\DynamicStaticAccess $e): Node
    {
        $recv = $e->receiver;
        $nm = $e->name;
        $span = $e->span;
        $names = [];
        foreach ($this->classTable as $cname => $cd) {
            if ($this->staticPropRef($cname, $nm) !== null
                || $this->findClassConst($this->resolveStaticClass($cname), $nm) !== null) {
                $names[] = $cname;
            }
        }
        $nameExpr = \Parser\Ast\Expr::ternary(
            \Parser\Ast\Expr::call('is_object', [$recv], $span),
            \Parser\Ast\Expr::call('get_class', [$recv], $span),
            $recv,
            $span,
        );
        $chain = \Parser\Ast\Expr::null($span);
        for ($i = \count($names) - 1; $i >= 0; $i = $i - 1) {
            $cname = $names[$i];
            $cond = \Parser\Ast\Expr::binary('===', $nameExpr, new \Parser\Ast\StringLiteral($cname, $span), $span);
            $then = \Parser\Ast\Expr::staticAccess($cname, $nm, $span);
            $chain = \Parser\Ast\Expr::ternary($cond, $then, $chain, $span);
        }
        return $this->lowerExpr($chain);
    }

    /**
     * `$cls::method(args)` with a runtime receiver. Normalise the receiver to a
     * class-NAME string first — `is_object($cls) ? get_class($cls) : $cls` — so an
     * OBJECT receiver (`$obj::method()`, calling a static method on the object's
     * own class) resolves through the SAME name-ternary as a string receiver. Every
     * arm stays a literal static call, so the result keeps a consistent repr (a
     * cell-typed `$obj->method()` arm would push the ternary join to a cell and
     * leave the string arms unboxed → the erased-return miscompile). Args and the
     * name expression are re-lowered per arm but only the matching arm runs.
     */
    private function lowerDynStaticCall(\Parser\Ast\DynamicStaticCall $e): Node
    {
        $recv = $e->receiver;
        $method = $e->method;
        $args = $e->args;
        $span = $e->span;
        $names = [];
        foreach ($this->classTable as $cname => $cd) {
            if ($this->isTypeDef($cname)) { continue; }
            if ($this->resolveMethodParams($cname, $method) !== null) { $names[] = $cname; }
        }
        $nameExpr = \Parser\Ast\Expr::ternary(
            \Parser\Ast\Expr::call('is_object', [$recv], $span),
            \Parser\Ast\Expr::call('get_class', [$recv], $span),
            $recv,
            $span,
        );
        $chain = \Parser\Ast\Expr::null($span);
        for ($i = \count($names) - 1; $i >= 0; $i = $i - 1) {
            $cname = $names[$i];
            $cond = \Parser\Ast\Expr::binary('===', $nameExpr, new \Parser\Ast\StringLiteral($cname, $span), $span);
            $then = \Parser\Ast\Expr::staticCall($cname, $method, $args, $span);
            $chain = \Parser\Ast\Expr::ternary($cond, $then, $chain, $span);
        }
        return $this->lowerExpr($chain);
    }

    /**
     * Stable, cross-object class identity: same FQN → same id in EVERY compiled
     * object. A per-module sequential class_id collides across the user.o /
     * stdlib.o boundary (id N = a different class in each object), which
     * corrupts cross-object drop / method-dispatch / instanceof — the rc=139
     * two-object fault. A content hash of the FQN is identical everywhere, so
     * the boundary is safe (worst case for a class only one object knows = a
     * missing drop case = a leak, never a wrong-layout free).
     *
     * Bounded polynomial hash: `h*131` stays far under PHP_INT_MAX, so the
     * value is IDENTICAL under Zend (which promotes int overflow to float) and
     * the native self-host runtime (which wraps i64) — otherwise the seed and
     * the self-built compiler would assign different ids and the byte-identical
     * fixpoint would break. Positive + non-zero (0 is the "no class" sentinel).
     */
    private function stableClassId(string $fqn): int
    {
        $h = 0;
        $n = \strlen($fqn);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $h = ($h * 131 + \ord(\substr($fqn, $i, 1))) % 1000000000000037;
        }
        if ($h === 0) { $h = 1; }
        return $h;
    }
    /** @return \Parser\Ast\MethodDecl[] */
    /** StringLiteral->value via a typed param (subclass field, self-host offset). */
    private function stringLitValue(\Parser\Ast\StringLiteral $s): string { return $s->value; }
    /** DynProp object/name via typed params (subclass fields, self-host offset). */
    private function dynPropObject(\Parser\Ast\DynProp $d): \Parser\Ast\Expr { return $d->object; }
    private function dynPropName(\Parser\Ast\DynProp $d): \Parser\Ast\Expr { return $d->name; }
    private function classDeclMethods(\Parser\Ast\ClassDecl $d): array { return $d->methods; }
    /** @return string[] */
    private function classDeclExtends(\Parser\Ast\ClassDecl $d): array { return $d->extends; }

    /**
     * Indices of the `Class` statements in `$stmts`, ordered so a class is
     * always built AFTER the class it extends. Bucketed by inheritance depth,
     * source order preserved inside a bucket; non-class statements are dropped
     * (the build loop ignores them anyway).
     *
     * buildClassDef prepends the parent's properties so a subclass shares the
     * parent's field offsets, and it can only do that once the parent is in
     * classTable. Source order happens to satisfy that in a single file and
     * does NOT for a composer app, where `src/` sorts before `vendor/`.
     *
     * @param \Parser\Ast\Stmt[] $stmts
     * @return int[]
     */
    private function classBuildOrder(array $stmts): array
    {
        /** @var int[] $idx */
        $idx = [];
        /** @var int[] $depths */
        $depths = [];
        $maxDepth = 0;
        $i = -1;
        foreach ($stmts as $stmt) {
            $i = $i + 1;
            if ($stmt->kind !== 'Class') { continue; }
            $cdecl = $stmt->decl;
            $d = $this->classDeclDepth($this->classDeclName($cdecl));
            $idx[] = $i;
            $depths[] = $d;
            if ($d > $maxDepth) { $maxDepth = $d; }
        }
        /** @var int[] $out */
        $out = [];
        $lvl = 0;
        while ($lvl <= $maxDepth) {
            $k = 0;
            foreach ($idx as $ix) {
                if ($depths[$k] === $lvl) { $out[] = $ix; }
                $k = $k + 1;
            }
            $lvl = $lvl + 1;
        }
        return $out;
    }

    /**
     * How many `extends` hops separate `$name` from a root. 0 for a class with
     * no parent, or one whose parent is not declared in this module (nothing to
     * wait for). Capped so a cyclic `extends` cannot spin.
     */
    private function classDeclDepth(string $name): int
    {
        $d = 0;
        $cur = \ltrim($name, '\\');
        while ($d < 64) {
            $decl = $this->classDecls[$cur] ?? null;
            if ($decl === null) { return $d; }
            $ext = $this->classDeclExtends($decl);
            if ($ext === []) { return $d; }
            $cur = \ltrim($ext[0], '\\');
            $d = $d + 1;
        }
        return $d;
    }
    private function methodDeclName(\Parser\Ast\MethodDecl $m): string { return $m->name; }
    /** @return \Parser\Ast\Param[] */
    private function methodDeclParams(\Parser\Ast\MethodDecl $m): array { return $m->params; }
    private function methodDeclReturnType(\Parser\Ast\MethodDecl $m): ?string { return $m->returnType; }

    /**
     * `[$a, $b] = $rhs` / `["k" => $v] = $rhs` — stash the RHS in a
     * fresh temp, then assign each target from the temp by index/key.
     */
    private function lowerDestructure(\Parser\Ast\Expr $target, Node $value): Node
    {
        $tmp = '__destr_' . (string)$this->destrCounter;
        $this->destrCounter = $this->destrCounter + 1;
        $stmts = [];
        $stmts[] = new StoreLocal($tmp, $value, $value->type);
        $idx = 0;
        foreach ($target->elements as $el) {
            if ($el->value === null) { $idx = $idx + 1; continue; }
            $key = $el->key !== null
                ? $this->lowerExpr($el->key)
                : new IntConst($idx, Type::int_());
            $access = new ArrayAccess_(
                new LoadLocal($tmp, Type::unknown()),
                $key,
                Type::unknown(),
            );
            $stmts[] = $this->storeToTarget($el->value, $access);
            $idx = $idx + 1;
        }
        return new Block($stmts, Type::void());
    }

    /** `$t op= v` → `$t = ($t op v)`. */
    private function lowerCompoundAssign(\Parser\Ast\CompoundAssign $expr): Node
    {
        // `$GLOBALS += […]` is a WRITE of the whole array (a php 8.1 fatal), so
        // say so — lowering the target as a read below would blame the read.
        if ($this->isGlobalsVar($expr->target)) { $this->rejectGlobalsWrite(); }
        $read = $this->lowerExpr($expr->target);
        $value = $this->lowerExpr($expr->value);
        // `$t ??= v` → `$t = $t ?? v`.
        if ($expr->op === '??=') {
            return $this->storeToTarget($expr->target, new NullCoalesce_($read, $value, Type::unknown()));
        }
        $base = \substr($expr->op, 0, \strlen($expr->op) - 1); // strip '='
        return $this->storeToTarget($expr->target, $this->buildBinop($base, $read, $value));
    }

    /** `$x++` / `++$x` / `$x--` / `--$x` on a plain local. */
    private function lowerIncDec(\Parser\Ast\IncDec $expr): Node
    {
        $operand = $expr->operand;
        $op = $expr->op === '++' ? '+' : '-';
        if ($operand->kind !== 'Variable') {
            // `$this->p++` / `$a[$k]++` — no plain-local slot. Desugar to
            // `target = target ± 1` (a compound assign). In statement context
            // (the common case, e.g. an Iterator's `next()`) this is exact; a
            // postfix used as an expression value yields the NEW value (minor).
            $read = $this->lowerExpr($operand);
            return $this->storeToTarget($operand, $this->buildBinop($op, $read, new IntConst(1, Type::int_())));
        }
        return new IncDec($operand->name, $op, $expr->prefix, Type::int_());
    }

    /** Ternary arms via a typed param (self-host field offsets). */
    private function ternaryThenExpr(\Parser\Ast\Ternary $t): ?\Parser\Ast\Expr { return $t->then; }
    private function ternaryElseExpr(\Parser\Ast\Ternary $t): \Parser\Ast\Expr { return $t->else; }

    private function lowerTernary(\Parser\Ast\Ternary $expr): Node
    {
        // A compile-time condition lowers ONLY the live arm — the dead one must
        // not reach codegen. `$cp = \function_exists('sapi_windows_cp_set')
        // ? sapi_windows_cp_get() : 0;` otherwise emitted a call to a function
        // that exists on no unix target. Same purity argument as lowerBinary.
        $cf = $this->foldGuard($expr->condition);
        if ($cf === self::GUARD_TRUE && $expr->then !== null) { return $this->lowerExpr($expr->then); }
        if ($cf === self::GUARD_FALSE) { return $this->lowerExpr($expr->else); }
        $cond = $this->lowerExpr($expr->condition);
        $else = $this->lowerExpr($expr->else);
        $type = $cond->type;
        $then = null;
        if ($expr->then !== null) {
            $then = $this->lowerExpr($expr->then);
            $type = $then->type;
        }
        return new Ternary($cond, $then, $else, $type);
    }

    /**
     * `$a ?? $b`. Routed through a NullCoalesce-typed param: reading
     * `->left` / `->right` off a base-`Expr` receiver resolves the wrong
     * offset in self-host (BinaryOp's `left` sits one slot later, after its
     * `op`), so a typed receiver is required for correct field offsets.
     */
    private function lowerNullCoalesce(\Parser\Ast\NullCoalesce $e): Node
    {
        // NOTE: `$a->b->c ?? $d` should suppress a null-deref of the `$a->b`
        // intermediate (PHP treats it as null → the default). Lowering the left
        // as a null-safe chain works for user code but REGRESSED the self-host
        // (the compiler's own `??` chains over erased types crash Stage-2 emit),
        // so it stays a bare read for now — use `$a->b?->c ?? $d` explicitly.
        return new NullCoalesce_(
            $this->lowerExpr($e->left),
            $this->lowerExpr($e->right),
            Type::unknown(),
        );
    }

    /**
     * `$x instanceof C`. Typed receiver for correct field offsets in
     * self-host: `operand` sits at a different slot on UnaryOp / Cast
     * (after their leading `op` / `cast`), so a base-`Expr` read picks the
     * wrong offset and faults. See {@see lowerNullCoalesce}.
     */
    private function lowerInstanceof(\Parser\Ast\InstanceofExpr $e): Node
    {
        return new Instanceof_($this->lowerExpr($e->operand), \ltrim($e->class, '\\'));
    }

    /**
     * php normalises a canonical numeric-string ARRAY KEY to an int key
     * (`$a["42"]` IS `$a[42]`, `["0"=>x]` builds an int-keyed array). Fold a
     * matching string LITERAL key to an IntConst here at lowering, so the STATIC
     * key type follows and every downstream dispatch — get/set int-vs-str,
     * foreach's raw-vs-tagged key reader, var_dump — takes the int path for
     * free. Doing it at runtime instead is unsound: the read side picks
     * raw-vs-tagged from the DECLARED key type, not the stored entry kind.
     *
     * The rule is exactly `(string)(int)$s === $s`, which rejects "01", "-0",
     * "+1", " 1", "1 ", "1.0", "1e2", "" and — via the cast's saturation at the
     * int64 boundary — any out-of-range digit run (all of which php keeps as
     * string keys). Only a LITERAL is folded; a dynamic `$a[$k]` key is a
     * separate (deferred) int|string-key-typing problem.
     */
    private function foldNumericKey(?Node $k): ?Node
    {
        if (!($k instanceof StringConst)) { return $k; }
        $s = $k->value;
        if ($s === '' || (string)(int)$s !== $s) { return $k; }
        return new IntConst((int)$s, Type::int_());
    }

    private function lowerArrayLit(\Parser\Ast\ArrayLit $expr): ArrayLit
    {
        $elems = [];
        foreach ($expr->elements as $el) {
            $k = $el->key === null ? null : $this->foldNumericKey($this->lowerExpr($el->key));
            if ($el->value->kind === 'Spread') {
                $inner = $this->lowerExpr($el->value->value);
                $elems[] = new ArrayElement_(null, new Spread_($inner, Type::unknown()));
                continue;
            }
            $v = $this->lowerExpr($el->value);
            $elems[] = new ArrayElement_($k, $v);
        }
        return new ArrayLit($elems, Type::unknown());
    }

    private function lowerArrayAccess(\Parser\Ast\ArrayAccess $expr): Node
    {
        $g = $this->lowerGlobalsRead($expr);
        if ($g !== null) { return $g; }
        $arr = $this->lowerExpr($expr->array);
        $idx = $expr->index === null
            ? new NullConst(Type::null_())
            : $this->foldNumericKey($this->lowerExpr($expr->index));
        return new ArrayAccess_($arr, $idx, Type::unknown());
    }

    /**
     * `new $cls(args)` — the class is a runtime value, so nothing can be resolved
     * here: no ctor params to default-fill, no concrete result type. The emitter
     * matches the name against the module's classes; the RESULT is typed `unknown`,
     * which routes property reads through the runtime class_id dispatch (the same
     * path an unknown-receiver `->prop` already takes). A `class-string<T>` call
     * site refines it back to a concrete type.
     */
    private function lowerNewDynExpr(\Parser\Ast\NewDynExpr $expr): NewDynObj
    {
        $args = [];
        foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
        $argc = \count($args);
        // The result is one of the classes whose constructor takes this many
        // arguments — exactly the set the emitter will compare the name against.
        // Typing it as their UNION (rather than `unknown`) is what makes a method
        // call on the result resolve: the union dispatches on the runtime class_id
        // and its return type comes from the atoms. An `unknown` receiver resolves
        // nothing, so `$obj->speak()` rendered its string result as a raw pointer.
        // Two candidate sets: EXACT arity (total === argc — the historical set) and
        // the RELAXED set that also accepts a defaulted / variadic constructor
        // (required <= argc, argc <= total or variadic). The exact set keeps its
        // precise obj / union type (byte-identical to before — no regression). When
        // relaxation brings in MORE classes (a `new $cls()` that relies on ctor
        // defaults), the candidate set is broad and its members' prop/method reprs
        // may disagree, so the result is a plain CELL: reads/stores route through
        // the already-boxing cell primitives (emitCellPropertyRead /
        // emitCellStoreProperty) and method calls through the class_id dispatch,
        // all keyed on the object's runtime class_id. A wide UNION would instead
        // erase to `unknown` (raw pointer reads) — the broad-union soundness root.
        $exactArms = [];
        $relaxed = [];
        foreach ($this->classTable as $name => $cd) {
            // A `#[TypeDef]` is not a candidate for a DYNAMIC `new $cls(…)`: the
            // class has no runtime form to select, and obj<U8> would be a pointer
            // to nothing. A TypeDef is constructed only where its name is written.
            if ($this->isTypeDef($name)) { continue; }
            $params = $this->resolveMethodParams($name, '__construct');
            $total = $params === null ? 0 : \count($params);
            $required = 0;
            $variadic = false;
            if ($params !== null) {
                foreach ($params as $p) {
                    if ($p->variadic) { $variadic = true; continue; }
                    if ($p->default === null) { $required = $required + 1; }
                }
            }
            if ($total === $argc) { $exactArms[] = Type::obj($name); }
            if ($argc >= $required && ($variadic || $argc <= $total)) { $relaxed[] = $name; }
        }
        if (\count($relaxed) > \count($exactArms)) {
            // Broad (defaulted-ctor) case → boxed object, runtime class_id dispatch.
            $t = \count($relaxed) === 1 ? Type::obj($relaxed[0]) : Type::cell();
            return new NewDynObj($this->lowerExpr($expr->classExpr), $args, $t);
        }
        $t = Type::unknown();
        if (\count($exactArms) === 1) { $t = $exactArms[0]; }
        elseif (\count($exactArms) > 1) { $t = Type::union($exactArms); }
        return new NewDynObj($this->lowerExpr($expr->classExpr), $args, $t);
    }

    private function lowerNewExpr(\Parser\Ast\NewExpr $expr): Node
    {
        // `new self` / `new static` / `new parent` → concrete class.
        $cls = $this->resolveStaticClass($expr->class);
        $params = $this->resolveMethodParams($cls, '__construct');
        if ($params !== null) {
            $args = $this->defaultFillArgs($params, $expr->args, $this->resolveMethodDeclClass($cls, '__construct'));
        } else {
            $args = [];
            foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
        }
        // `new U8(x)` allocates NOTHING — there is no runtime form for a NewObj to
        // point at. A TypeDef's constructor is Zend-only glue; the compiler never
        // lowers it. What `new` means here is decided by the class's shape:
        //
        //   - a NORMALISER (`__invoke(T $raw): T`) → the `new` IS that call. The
        //     validation / sanitisation runs exactly once, here, and the type then
        //     carries the proof: no later use re-checks anything.
        //   - a promoted `public readonly T $value` and nothing else → the value IS
        //     the argument. Not even a call: `new U8(7)` emits the literal 7.
        if ($this->isTypeDef($cls)) {
            if ($expr->args === []) {
                $this->typeDefError($cls, 'constructed with no argument — a value type needs its value');
            }
            return new Call(
                \ltrim($cls, '\\') . '____invoke',
                $args,
                $this->typeDefCarrier($cls),
            );
        }
        return new NewObj($cls, $args, Type::obj($cls));
    }

    /**
     * Resolve a method's declared parameters (walking ancestors), or null
     * when the class/method is unknown. Used to default-fill ctor/method
     * call args so the callee never reads uninitialized param slots.
     * @return \Parser\Ast\Param[]|null
     */
    /**
     * Resolve a variadic method signature by NAME across all classes, for
     * packing a variable-receiver call whose class isn't known until InferTypes.
     * Returns the params of a class declaring `$method` with a trailing variadic
     * — but only when EVERY class declaring `$method` agrees (all variadic, same
     * fixed arity). A single non-variadic same-name method → null (ambiguous
     * packing, defer): never mis-pack a non-variadic call. Common consistent
     * variadic method names pack correctly; rare collisions safely defer.
     * @return \Parser\Ast\Param[]|null
     */
    private function variadicMethodParams(string $method): ?array
    {
        $found = null;
        foreach ($this->classDecls as $cd) {
            foreach ($this->classDeclMethods($cd) as $m) {
                if ($this->methodDeclName($m) !== $method) { continue; }
                $mp = $this->methodDeclParams($m);
                $np = \count($mp);
                if ($np === 0 || !$this->paramVariadic($mp[$np - 1])) { return null; }
                if ($found !== null && \count($found) !== $np) { return null; }
                $found = $mp;
            }
        }
        return $found;
    }

    /**
     * The static class of a method-call receiver EXPRESSION, when lowering can
     * tell — a `new C(...)` receiver, or a `$x->m(...)` chain whose `m` has a
     * consistent class return type across all classes that declare it. '' when
     * unknown (a bare variable — its type waits on InferTypes). Lets a chained
     * `$r->getMethod('x')->invoke(a, b)` pack its variadic against the ACTUAL
     * ReflectionMethod::invoke rather than the by-name union, which breaks when a
     * same-named variadic method (ReflectionFunction::invoke) disagrees on arity.
     */
    private function receiverClassHint(\Parser\Ast\Expr $obj): string
    {
        if ($obj->kind === 'New') { return \ltrim($obj->class, '\\'); }
        if ($obj->kind === 'MethodCall') {
            // A FLUENT method returns `static` / `self` — the receiver of the
            // next link is the same class as its own receiver, so recurse
            // instead of giving up. Without this, the second and later calls of
            // `$this->addOption(…)->addOption(…)` resolved no params, so their
            // OMITTED arguments were never filled from the defaults: symfony's
            // `mixed $default = null` arrived as raw 0, read back as 0.0, and
            // every VALUE_NONE option threw "Cannot set a default value".
            //
            // Resolved against the INNER RECEIVER's class, not by method name:
            // `addArgument` is fluent on Command and `: void` on
            // InputDefinition, so any name-wide answer is no answer. The static
            // class is right even for `static` — all this decides is which
            // parameter list to pad against, and those are inherited.
            $inner = $this->receiverClassHint($obj->object);
            if ($inner !== '') {
                $rt = $this->methodReturnTypeOn($inner, $obj->method);
                if ($rt !== null) {
                    $low = \strtolower(\ltrim($rt, '\\?'));
                    if ($low === 'self' || $low === 'static') { return $inner; }
                    $cls = \ltrim($rt, '\\?');
                    if (isset($this->classDecls[$cls])) { return $cls; }
                    return '';
                }
            }
            return $this->methodReturnClassByName($obj->method);
        }
        if ($obj->kind === 'Variable') {
            $vn = $this->varName($obj);
            // `$this` is the class being lowered — the commonest fluent root.
            if ($vn === 'this') { return $this->currentLowerClass; }
            return $this->localNewClasses[$vn] ?? '';
        }
        return '';
    }

    /** The declared return type of `$method` as resolved from `$class` (walking
     *  the parent chain, like resolveMethodParams), or null when neither the
     *  class nor an ancestor declares it. */
    private function methodReturnTypeOn(string $class, string $method): ?string
    {
        $c = $class;
        while ($c !== '' && isset($this->classDecls[$c])) {
            $cd = $this->classDecls[$c];
            foreach ($this->classDeclMethods($cd) as $m) {
                if ($this->methodDeclName($m) === $method) {
                    return $this->methodDeclReturnType($m);
                }
            }
            $ext = $this->classDeclExtends($cd);
            $c = ($ext !== []) ? $ext[0] : '';
        }
        return null;
    }

    /**
     * The class a method NAME returns, if every class declaring it agrees on a
     * single class return type (leading `?` stripped); '' when they disagree, a
     * declaration has no / a non-class return type, or none declares it.
     */
    private function methodReturnClassByName(string $method): string
    {
        $ret = '';
        foreach ($this->classDecls as $cd) {
            foreach ($this->classDeclMethods($cd) as $m) {
                if ($this->methodDeclName($m) !== $method) { continue; }
                $rt = $this->methodDeclReturnType($m);
                if ($rt === null || $rt === '') { return ''; }
                $rt = \ltrim($rt, '\\');
                if ($rt !== '' && $rt[0] === '?') { $rt = \substr($rt, 1); }
                // A scalar / pseudo return type is not a class receiver.
                $low = \strtolower($rt);
                if ($low === 'int' || $low === 'float' || $low === 'string' || $low === 'bool'
                    || $low === 'array' || $low === 'void' || $low === 'mixed'
                    || $low === 'self' || $low === 'static' || $low === 'never') { return ''; }
                if (!isset($this->classDecls[$rt])) { return ''; }
                if ($ret !== '' && $ret !== $rt) { return ''; }
                $ret = $rt;
            }
        }
        return $ret;
    }

    private function resolveMethodParams(string $class, string $method): ?array
    {
        $c = $class;
        while ($c !== '' && isset($this->classDecls[$c])) {
            // `$cd` comes from an assoc whose docblock value type isn't
            // propagated self-host, so it's untyped — inline `$cd->methods`
            // / `$cd->extends` (and `$m->name` / `$m->params`) read the wrong
            // field offset (garbage). Route through typed accessors. T5.
            $cd = $this->classDecls[$c];
            foreach ($this->classDeclMethods($cd) as $m) {
                if ($this->methodDeclName($m) === $method) { return $this->methodDeclParams($m); }
            }
            $ext = $this->classDeclExtends($cd);
            $c = ($ext !== []) ? $ext[0] : '';
        }
        return null;
    }

    /**
     * Class that DECLARES `$method` (walking ancestors), or '' if unresolved.
     * The `self`/`parent`/`static` scope for that method's param defaults —
     * a default lowered at the call site must bind `self` to this, not the
     * caller (mirrors {@see resolveMethodParams}'s walk).
     */
    private function resolveMethodDeclClass(string $class, string $method): string
    {
        $c = $class;
        while ($c !== '' && isset($this->classDecls[$c])) {
            $cd = $this->classDecls[$c];
            foreach ($this->classDeclMethods($cd) as $m) {
                if ($this->methodDeclName($m) === $method) { return $c; }
            }
            $ext = $this->classDeclExtends($cd);
            $c = ($ext !== []) ? $ext[0] : '';
        }
        return '';
    }

    private function lowerPropertyAccess(\Parser\Ast\PropertyAccess $expr): PropertyAccess_
    {
        $obj = $this->lowerExpr($expr->object);
        $this->checkErasedGenericPropRead($obj, $expr->property);
        return new PropertyAccess_($obj, $expr->property, Type::unknown());
    }

    /**
     * Nullsafe `$obj?->prop` — short-circuits to null when the receiver is null
     * (without the guard the property read derefs null+offset → SEGV). Desugar
     * (evaluating `$obj` ONCE via a temp) to `($t = $obj) === null ? null :
     * $t->prop`, mirroring the nullsafe method-call path. NOTE: the null arm
     * renders as the non-null type's zero (int(0), not NULL) — a nullable-type
     * limitation (the result is really `T|null`); correct NULL rendering needs a
     * real nullable/union type (the type-system-v2 epic). The CRASH is fixed.
     */
    private function lowerNullsafeProp(\Parser\Ast\PropertyAccess $expr): Node
    {
        $obj = $this->lowerExpr($expr->object);
        $tmp = '__ns_' . (string)$this->destrCounter;
        $this->destrCounter = $this->destrCounter + 1;
        $store = new StoreLocal($tmp, $obj, $obj->type);
        $cond = new Cmp($store, new NullConst(Type::null_()), '===');
        $prop = new PropertyAccess_(new LoadLocal($tmp, $obj->type), $expr->property, Type::unknown());
        return new Ternary($cond, new NullConst(Type::null_()), $prop, Type::unknown(), true);
    }

    private function lowerMethodCall(\Parser\Ast\MethodCallExpr $expr): Node
    {
        // First-class callable `$o->m(...)` → a closure capturing `$o`.
        if (\count($expr->args) === 1 && $expr->args[0]->kind === 'Ellipsis') {
            return $this->synthMethodClosure($this->lowerExpr($expr->object), $expr->method);
        }
        $obj = $this->lowerExpr($expr->object);
        // Nullsafe `$obj?->m(args)` short-circuits to null when the receiver
        // is null — without the guard the callee dereferences a null `$this`
        // (reads field at null+offset → SEGV). Desugar, evaluating `$obj`
        // ONCE via a temp, to `($t = $obj) === null ? null : $t->m(args)`.
        // Args lower positionally (receiver class unknown pre-InferTypes);
        // the emit-time pad fills omitted optionals.
        if ($expr->nullsafe) {
            $tmp = '__ns_' . (string)$this->destrCounter;
            $this->destrCounter = $this->destrCounter + 1;
            $store = new StoreLocal($tmp, $obj, $obj->type);
            $cond = new Cmp($store, new NullConst(Type::null_()), '===');
            $args = [];
            foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
            $call = new MethodCall_(
                new LoadLocal($tmp, $obj->type),
                $expr->method,
                $args,
                Type::unknown(),
            );
            return new Ternary($cond, new NullConst(Type::null_()), $call, Type::unknown());
        }
        // Default-fill only when the receiver class is statically known
        // (`$this->m()`); a typed receiver's class isn't resolved until
        // InferTypes, so omitted trailing optionals on `$x->m()` are filled
        // later by the emit-time pad in emitMethodCall (emitDefaultArgPad).
        $params = null;
        $mcClass = '';
        if ($expr->object->kind === 'Variable'
            && $expr->object->name === 'this'
            && $this->currentLowerClass !== '') {
            $params = $this->resolveMethodParams($this->currentLowerClass, $expr->method);
            if ($params !== null) { $mcClass = $this->currentLowerClass; }
        }
        // A statically-knowable receiver (a `new C(...)` or a chained
        // `$x->getMethod(...)->` whose return class is unambiguous) packs against
        // the EXACT method — the only correct choice when a same-named variadic
        // method elsewhere disagrees on arity (ReflectionMethod::invoke vs
        // ReflectionFunction::invoke), where the by-name union below defers.
        if ($params === null) {
            $hint = $this->receiverClassHint($expr->object);
            if ($hint !== '') {
                $params = $this->resolveMethodParams($hint, $expr->method);
                if ($params !== null) { $mcClass = $hint; }
            }
        }
        // A variable-receiver variadic call (`$x->m(a,b,c)`) must STILL pack its
        // trailing args into a vec — but the receiver class isn't resolved until
        // InferTypes. Variadic-ness is a property of the method NAME, so resolve
        // a consistent variadic signature across all classes and pack against it
        // (default-arg padding for non-variadic methods still defers to emit).
        if ($params === null) {
            $params = $this->variadicMethodParams($expr->method);
        }
        if ($params !== null) {
            $selfCls = $mcClass !== '' ? $this->resolveMethodDeclClass($mcClass, $expr->method) : '';
            $args = $this->defaultFillArgs($params, $expr->args, $selfCls);
        } else {
            $args = [];
            foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
        }
        return new MethodCall_($obj, $expr->method, $args, Type::unknown());
    }

    private function lowerStaticCall(\Parser\Ast\StaticCall $expr): Node
    {
        $class = $expr->class;
        $low = \strtolower($class);
        // Dispatch target vs. late-static scope. `static::` dispatches to the
        // called class; `self::`/`parent::` dispatch lexically but FORWARD the
        // called class so a downstream `static::` stays bound to it; an
        // explicit `C::` resets the scope to C (non-forwarding).
        $scope = $this->currentStaticClass !== ''
            ? $this->currentStaticClass : $this->currentLowerClass;
        if ($low === 'static') {
            $class = $scope;
            $this->sawStaticUse = true;
        } elseif ($low === 'self') {
            $class = $this->currentLowerClass;
        } elseif ($low === 'parent') {
            $cd = $this->classTable[$this->currentLowerClass] ?? null;
            $class = $cd !== null ? $cd->parent : $this->currentLowerClass;
        } else {
            $scope = \ltrim($class, '\\');
        }
        // `Closure::fromCallable($c)` → the same closure a first-class callable
        // builds. A string / `C::m` / `[$o,"m"]` literal reuses coerceCallableArg;
        // a value already a closure passes through.
        if (\strtolower(\ltrim($class, '\\')) === 'closure' && $expr->method === 'fromCallable'
            && \count($expr->args) === 1) {
            $conv = $this->coerceCallableArg(Type::closure(), $expr->args[0]);
            if ($conv !== null) { return $conv; }
            return $this->lowerExpr($expr->args[0]);
        }
        // First-class callable `C::m(...)` → a 0-capture closure forwarding to
        // the static method (scope preserved for late static binding).
        if (\count($expr->args) === 1 && $expr->args[0]->kind === 'Ellipsis') {
            return $this->synthStaticClosure($class, $expr->method, $scope);
        }
        // A self/parent/static call to an INSTANCE method (e.g.
        // `parent::__construct(...)`) is dispatched against the current
        // object — the callee has `$this` as param 0, so pass it. A genuine
        // static method has no `$this` param and must not get one.
        $args = [];
        $isSelfish = $low === 'self' || $low === 'parent' || $low === 'static';
        // A forwarding call (`self::`/`parent::`/`static::`) propagates the
        // late-static scope to the callee, so the enclosing method must be
        // specialised per descendant too — else `parent::m()` reaching an LSB
        // ancestor binds `static` to the lexical class, not the called one.
        if ($isSelfish) { $this->sawStaticUse = true; }
        if ($isSelfish && !$this->methodIsStatic($class, $expr->method)) {
            $args[] = new LoadLocal('this', Type::obj($this->currentLowerClass));
        }
        $params = $this->resolveMethodParams($class, $expr->method);
        if ($params !== null) {
            $selfCls = $this->resolveMethodDeclClass($class, $expr->method);
            foreach ($this->defaultFillArgs($params, $expr->args, $selfCls) as $f) { $args[] = $f; }
        } else {
            foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
        }
        // `TaskGroup::run(…)` carries its call site too — see asyncSiteCallee().
        // The class is syntactic here, so this needs no inference; a program that
        // declares its own `TaskGroup` already collides with the prelude's (the
        // demand gate keys on that very name).
        if ($this->asyncSrc !== '' && $expr->method === 'run'
            && ($class === 'Async\\TaskGroup' || $class === 'TaskGroup')) {
            $site = $this->callSite($expr->span);
            if ($site !== '') {
                $sited = [new StringConst($site, Type::string_())];
                foreach ($args as $a) { $sited[] = $a; }
                return new StaticCall_($class, 'runAt', $sited, Type::unknown(), $scope);
            }
        }
        return new StaticCall_($class, $expr->method, $args, Type::unknown(), $scope);
    }

    /** Whether `$method` resolved from `$class` (walking ancestors) is a
     * static method. `__construct` is always instance; an unresolved method
     * defaults to instance so a self/parent call still receives `$this`. */
    private function methodIsStatic(string $class, string $method): bool
    {
        if ($method === '__construct') { return false; }
        $c = $class;
        while ($c !== '') {
            $decl = $this->classDecls[$c] ?? null;
            if ($decl === null) { return false; }
            foreach ($decl->methods as $m) {
                if ($m->name === $method) { return $m->isStatic; }
            }
            $cd = $this->classTable[$c] ?? null;
            $c = $cd !== null ? $cd->parent : '';
        }
        return false;
    }

    private function lowerBinary(\Parser\Ast\BinaryOp $e): Node
    {
        $op = $e->op;
        // Short-circuit logical operators: only the left operand is
        // unconditionally evaluated. Desugar to a Ternary so the right
        // side lives in a conditionally-emitted branch. Result is the
        // i64 0/1 bool manticore echoes as "0"/"1".
        if ($op === '&&' || $op === 'and') {
            // A statically-FALSE operand kills the expression, and the other
            // side must then never be LOWERED — not merely never executed. This
            // is the expression-position twin of the compile-time `if`: without
            // it `\function_exists('sapi_windows_vt100_support') &&
            // sapi_windows_vt100_support($h)` still emitted the call and the
            // link failed on a branch that provably cannot run. foldGuard only
            // answers for PURE predicates, so dropping the arm drops no effect.
            $lf = $this->foldGuard($e->left);
            if ($lf === self::GUARD_FALSE) { return new BoolConst(false, Type::bool_()); }
            $rf = $this->foldGuard($e->right);
            if ($rf === self::GUARD_FALSE && $lf !== self::GUARD_UNKNOWN) {
                return new BoolConst(false, Type::bool_());
            }
            // A folded operand is replaced by its constant rather than dropped
            // outright: the Ternary shape is what keeps the result BOOL through
            // InferTypes (a bare Not_ chain widens and echoes "0").
            $left = $lf === self::GUARD_UNKNOWN
                ? $this->lowerExpr($e->left) : new BoolConst($lf === self::GUARD_TRUE, Type::bool_());
            $right = $rf === self::GUARD_UNKNOWN
                ? $this->truthy($this->lowerExpr($e->right))
                : new BoolConst($rf === self::GUARD_TRUE, Type::bool_());
            return new Ternary($left, $right, new BoolConst(false, Type::bool_()), Type::bool_());
            // Both arms bool so the Ternary stays bool through InferTypes
            // (a bool/int arm mismatch widens it to unknown → echoes "0").
            return new Ternary($left, $this->truthy($right), new BoolConst(false, Type::bool_()), Type::bool_());
        }
        if ($op === '||' || $op === 'or') {
            $lf = $this->foldGuard($e->left);
            if ($lf === self::GUARD_TRUE) { return new BoolConst(true, Type::bool_()); }
            $rf = $this->foldGuard($e->right);
            if ($rf === self::GUARD_TRUE && $lf !== self::GUARD_UNKNOWN) {
                return new BoolConst(true, Type::bool_());
            }
            $left = $lf === self::GUARD_UNKNOWN
                ? $this->lowerExpr($e->left) : new BoolConst($lf === self::GUARD_TRUE, Type::bool_());
            $right = $rf === self::GUARD_UNKNOWN
                ? $this->truthy($this->lowerExpr($e->right))
                : new BoolConst($rf === self::GUARD_TRUE, Type::bool_());
            return new Ternary($left, new BoolConst(true, Type::bool_()), $right, Type::bool_());
        }
        return $this->buildBinop($op, $this->lowerExpr($e->left), $this->lowerExpr($e->right));
    }

    /** Normalise a node to a 0/1 bool (double logical-not). */
    private function truthy(Node $n): Node
    {
        return new Not_(new Not_($n));
    }

    /**
     * Recover the element type of a bare-`array` property from a homogeneous
     * list literal default (`public array $t = ['a','b']` → vec[string]).
     * PHP's `array` hint erases the element type and there's no docblock; the
     * literal is then the only carrier, so a read like `$o->t[0]` / `"$o->t[0]"`
     * knows the element is a string rather than rendering the raw slot. Returns
     * null for an empty, keyed, or heterogeneous default (stays erased).
     */
    private function inferBareArrayPropElem(\Parser\Ast\Expr $default): ?Type
    {
        if ($default->kind !== 'ArrayLit') { return null; }
        $elems = $default->elements;
        if ($elems === []) { return null; }
        $kind = '';
        foreach ($elems as $el) {
            if ($el->key !== null) { return null; }          // assoc, not a list
            $vk = $el->value->kind;
            if ($kind === '') { $kind = $vk; }
            elseif ($kind !== $vk) { return null; }          // heterogeneous
        }
        if ($kind === 'IntLiteral')    { return Type::int_(); }
        if ($kind === 'StringLiteral') { return Type::string_(); }
        if ($kind === 'FloatLiteral')  { return Type::float_(); }
        if ($kind === 'BoolLiteral')   { return Type::bool_(); }
        return null;
    }

    /** Value element type of a fully STRING-keyed homogeneous array literal
     *  (`["x"=>10, "y"=>20]` → int); null if any key is non-literal-string or
     *  the values are heterogeneous / non-scalar. Recovers an assoc bare-array
     *  property's value type from a wholesale store. */
    private function inferBareArrayPropAssocElem(\Parser\Ast\Expr $default): ?Type
    {
        if ($default->kind !== 'ArrayLit') { return null; }
        $elems = $default->elements;
        if ($elems === []) { return null; }
        $kind = '';
        foreach ($elems as $el) {
            if ($el->key === null || $el->key->kind !== 'StringLiteral') { return null; }
            $vk = $el->value->kind;
            if ($kind === '') { $kind = $vk; }
            elseif ($kind !== $vk) { return null; }
        }
        if ($kind === 'IntLiteral')    { return Type::int_(); }
        if ($kind === 'StringLiteral') { return Type::string_(); }
        if ($kind === 'FloatLiteral')  { return Type::float_(); }
        if ($kind === 'BoolLiteral')   { return Type::bool_(); }
        return null;
    }

    /** Whether `$e` is `$this->$prop`. */
    private function isThisProp(\Parser\Ast\Expr $e, string $prop): bool
    {
        if ($e->kind !== 'PropertyAccess') { return false; }
        $pa = $e;
        if ($pa->property !== $prop) { return false; }
        $obj = $pa->object;
        return $obj->kind === 'Variable' && $obj->name === 'this';
    }

    /**
     * A conservative SYNTACTIC type for a stored value at class-build time: a
     * `new C` → obj<C>, a typed param → its hint, a scalar literal → its kind.
     * Anything else (a call, a ternary, an untyped local) → unknown (bail).
     *
     * @param array<string, Type> $paramTypes
     */
    private function syntacticValueType(\Parser\Ast\Expr $v, array $paramTypes): Type
    {
        $k = $v->kind;
        if ($k === 'New') {
            $cls = \ltrim($v->class, '\\');
            if ($cls === 'self' || $cls === 'static') { $cls = $this->currentLowerClass; }
            return $cls !== '' ? Type::obj($cls) : Type::unknown();
        }
        if ($k === 'Variable') {
            $name = $v->name;
            return $paramTypes[$name] ?? Type::unknown();
        }
        if ($k === 'IntLiteral')    { return Type::int_(); }
        if ($k === 'StringLiteral') { return Type::string_(); }
        if ($k === 'FloatLiteral')  { return Type::float_(); }
        if ($k === 'BoolLiteral')   { return Type::bool_(); }
        // A concat / interpolation (BinaryOp `.`) and a `(string)` cast are
        // always string — the common assoc-value shape (`$this->d[$k] = "$a->$b"`).
        if ($k === 'BinaryOp' && $v->op === '.') { return Type::string_(); }
        if ($k === 'Cast' && $v->cast === 'string') { return Type::string_(); }
        return Type::unknown();
    }

    /**
     * Whether `$k` is syntactically a string at class-build time — a string
     * literal, a concat / interpolation (`BinaryOp .`), a `(string)` cast, or a
     * string-typed param. Drives the vec-vs-assoc decision for a bare `array`
     * property's keyed stores.
     *
     * @param array<string, Type> $paramTypes
     */
    private function syntacticKeyIsString(\Parser\Ast\Expr $k, array $paramTypes): bool
    {
        $kk = $k->kind;
        if ($kk === 'StringLiteral') { return true; }
        if ($kk === 'BinaryOp' && $k->op === '.') { return true; }
        if ($kk === 'Cast' && $k->cast === 'string') { return true; }
        if ($kk === 'Variable') {
            $t = $paramTypes[$k->name] ?? null;
            return $t !== null && $t->kind === Type::KIND_STRING;
        }
        return false;
    }

    /** Element-type equality (kind, plus class for objects). */
    private function sameElemType(Type $a, Type $b): bool
    {
        if ($a->kind !== $b->kind) { return false; }
        if ($a->kind === Type::KIND_OBJ) { return ($a->class ?? '') === ($b->class ?? ''); }
        return true;
    }

}

/**
 * A late-static-binding method awaiting per-descendant specialisation. Holds
 * everything {@see LowerFromAst::lowerMethodFn} needs to re-lower the body
 * under a subclass scope.
 */
final class LsbPending
{
    /** @param StoreProperty[] $defaultStores */
    public function __construct(
        public readonly \Parser\Ast\ClassDecl $decl,
        public readonly \Parser\Ast\MethodDecl $method,
        public readonly ClassDef $cd,
        public readonly array $defaultStores,
    ) {}
}
