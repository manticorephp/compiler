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

    /** `#[TypeDef]` classes ({@see LowerTypeDefs}): class name → its `repr`.
     *  Filled BEFORE any ClassDef is built — a class lowered earlier may already
     *  name one in a property or parameter hint.
     *  @var array<string, string> */
    private array $typeDefReprs = [];

    /** `#[TypeDef]` class name → the name of its single value property.
     *  @var array<string, string> */
    private array $typeDefProps = [];

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

    /** Set true while lowering a body when a `yield` is seen (generator). */
    private bool $sawYield = false;

    /** Counter for unique `yield from` desugar loop variables. */
    private int $yieldFromCounter = 0;

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

    public bool $includeVarDump = false;
    public bool $includePrintR = false;
    /** print_r prelude source, read by Main from `prelude/print_r.php`. */
    public string $printRSrc = '';
    /** Inject the built-in SPL ArrayIterator / ArrayObject classes (gated on
     *  the user program referencing them — see Main.php). */
    public bool $includeArrayClasses = false;
    /** SPL array-class prelude source, read by Main from `prelude/spl_arrays.php`.
     *  Empty → {@see arrayClassesPreludeSrc} uses its embedded fallback. */
    public string $arrayClassesSrc = '';
    /** Inject the callback/element array functions (usort/sort/rsort/array_reduce)
     *  as prelude — compiled WITH the user program so call-site element inference
     *  types their array param and the in-module closure ABI matches (they can't
     *  live in the separately-linked stdlib .o; see Main.php gating). */
    public bool $includeArrayFns = false;
    /** Array-functions prelude source, read by Main from `prelude/array_fns.php`.
     *  Empty → not injected (the compiler itself never uses these). */
    public string $arrayFnsSrc = '';
    /** Inject the CLI prelude (__mc_argv / getopt) — compiled WITH the user
     *  program so the bare-`array` returns narrow at the call site. Gated on a
     *  source reference to $argv / $argc / getopt( (see Main.php). */
    public bool $includeCli = false;
    /** CLI prelude source, read by Main from `prelude/cli.php`. */
    public string $cliSrc = '';
    /** Inject the backtrace-frame builder (`__mir_bt_frames`) and make
     *  Throwable::getTrace return PHP-shaped assoc frames. Gated on a source
     *  reference to a trace getter / debug_backtrace (see Main.php) so the
     *  compiler self-build (which uses none) never compiles the nested-assoc
     *  builder. */
    public bool $includeBacktrace = false;

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

    private ?Module $module = null;
    private int $closureCounter = 0;
    private int $destrCounter = 0;

    /** @var array<string, \Parser\Ast\FunctionDecl> fn name → decl (defaults / named args) */
    private array $fnDecls = [];

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
        // Built-in Exception hierarchy (parsed prelude) is lowered like
        // any user class, so `throw` / `catch` / `getMessage` resolve
        // through the normal class machinery.
        $stmts = [];
        $preludeStmts = $this->preludeStatements();
        $preludeCount = \count($preludeStmts);
        foreach ($preludeStmts as $ps) { $stmts[] = $ps; }
        // Flatten braced-namespace blocks (`namespace Ffi { ... }`) into
        // their inner statements — the parser already qualified the inner
        // decl names (`Ffi\call`), so they lower like any unbraced-ns file.
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
        foreach ($stmts as $stmt) {
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
        $mainBody = new Block($mainStmts, Type::void());
        $module->addFunction(new FunctionDef(
            name: '__main',
            params: [],
            returnType: Type::int_(),
            body: $mainBody,
        ));
        $module->markPassApplied(self::NAME);
        return $module;
    }

    /**
     * PHP source for `__mir_bt_frames` — turns the captured name vec into
     * PHP-shaped backtrace frames (innermost first). The captured name is the
     * combined display ("Class->method" / "Class::m" / "fn"); split it back into
     * function/class/type so getTrace() and debug_backtrace() match PHP's frame
     * assoc. Frames carry file + line + function[/class/type]; `args` is still
     * omitted. `line` is a real int mixed with the substr-derived strings (a
     * cell assoc) — this used to miscompile; fixed by retaining string payloads
     * boxed into a cell array (see EmitLlvm::retainCellPayload).
     */
    private function backtraceFramesSrc(): string
    {
        return "/** @param string[] \$names @param int[] \$lines\n"
            . "  * @return array<int,array<string,mixed>> */\n"
            . "function __mir_bt_frames(array \$names, array \$lines, string \$file): array {\n"
            . "  \$out = []; \$n = \\count(\$names); \$i = 0;\n"
            . "  while (\$i < \$n) {\n"
            . "    \$name = \$names[\$i]; \$ln = \$lines[\$i]; \$type = \"\";\n"
            . "    \$p = \\strpos(\$name, \"::\");\n"
            . "    if (\$p === false) { \$p = \\strpos(\$name, \"->\"); if (\$p !== false) { \$type = \"->\"; } }\n"
            . "    else { \$type = \"::\"; }\n"
            . "    if (\$type !== \"\") {\n"
            . "      \$cls = \\substr(\$name, 0, \$p); \$fn = \\substr(\$name, \$p + 2);\n"
            . "      \$out[] = [\"file\" => \$file, \"line\" => \$ln, \"function\" => \$fn, \"class\" => \$cls, \"type\" => \$type];\n"
            . "    } else {\n"
            . "      \$out[] = [\"file\" => \$file, \"line\" => \$ln, \"function\" => \$name];\n"
            . "    }\n"
            . "    \$i = \$i + 1;\n"
            . "  }\n"
            . "  return \$out;\n"
            . "}\n";
    }

    /**
     * PHP source for `__mir_var_dump`, the recursive backend of the
     * `var_dump` builtin. Walks a tagged cell via the is_type, cast,
     * and foreach primitives. Included only when the program uses
     * var_dump (avoids pulling the tagged/assoc runtime into every
     * binary).
     */
    private function varDumpPreludeSrc(): string
    {
        return "function __mir_var_dump(mixed \$v, int \$indent): void {\n"
            . "  \$pad = ''; \$j = 0;\n"
            . "  while (\$j < \$indent) { \$pad = \$pad . '  '; \$j = \$j + 1; }\n"
            . "  if (is_int(\$v)) { echo 'int(', (string)\$v, \")\\n\"; return; }\n"
            . "  if (is_float(\$v)) { echo 'float(', __mir_float_repr(\$v), \")\\n\"; return; }\n"
            . "  if (is_bool(\$v)) { \$b = (string)\$v; if (\$b === '1') { echo \"bool(true)\\n\"; } else { echo \"bool(false)\\n\"; } return; }\n"
            . "  if (is_null(\$v)) { echo \"NULL\\n\"; return; }\n"
            . "  if (is_string(\$v)) { \$sv = (string)\$v; echo 'string(', (string)strlen(\$sv), ') \"', \$sv, \"\\\"\\n\"; return; }\n"
            . "  if (is_object(\$v)) { __mir_dump_object(\$v, \$indent); return; }\n"
            . "  echo 'array(', (string)count(\$v), \") {\\n\";\n"
            . "  foreach (\$v as \$k => \$val) {\n"
            . "    if (is_int(\$k)) { echo \$pad, '  [', (string)\$k, \"]=>\\n\", \$pad, '  '; }\n"
            . "    else { echo \$pad, '  [\"', \$k, \"\\\"]=>\\n\", \$pad, '  '; }\n"
            . "    __mir_var_dump(\$val, \$indent + 1);\n"
            . "  }\n"
            . "  echo \$pad, \"}\\n\";\n"
            . "}\n";
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
            $name = \ltrim($attr->name, '\\');
            if ($name === 'AllowDynamicProperties'
                || $name === 'Manticore\\Attr\\AllowDynamicProperties'
                || $name === 'Attr\\AllowDynamicProperties') {
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
            $name = \ltrim($attr->name, '\\');
            if ($name === 'Struct'
                || $name === 'Manticore\\Attr\\Struct'
                || $name === 'Attr\\Struct') {
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
        // Inside a `#[TypeDef]` body `$this` IS the carrier scalar: there is no
        // object to point at. The CONSTRUCTOR loses `$this` entirely — it is not
        // an initializer of something already allocated, it is the function that
        // COMPUTES the value, so it takes the ctor params and returns the carrier.
        $isTypeDef = $this->isTypeDef($decl->name);
        $this->currentTypeDefClass = $isTypeDef ? \ltrim($decl->name, '\\') : '';
        $tdCtor = $isTypeDef && $m->name === '__construct';
        $params = [];
        // Static methods have no implicit `$this`.
        if (!$m->isStatic && !$tdCtor) {
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
        $pi = 0;
        foreach ($m->params as $p) {
            $isVar = (bool)($p->variadic ?? false);
            // `T ...$xs` collects trailing args into a vec[T] the callee sees as
            // one vec param (caller packs at the call site) — same as the plain
            // function path. Without the Type::vec wrapper the callee reads the
            // param as a single T, so `$xs` is garbage (a raw arg, not the vec).
            $pt = $isVar
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerParamType($this->effectiveHint(
                    $p->typeHint,
                    $this->docTagType($m->docComment, '@param', $p->name),
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
            $params[] = $mp;
            $pi = $pi + 1;
        }
        $stmts = [];
        if ($tdCtor) {
            // A promoted `public readonly int $v` IS the whole constructor: the
            // value is the parameter. An explicit body instead RETURNS it — its
            // `$this->value = <expr>;` was rewritten to `return <expr>;` while lowering
            // (see lowerStmt / StoreProperty on a TypeDef receiver).
            $tdCarrier = $this->typeDefCarrier($decl->name);
            foreach ($m->params as $p) {
                if ($p->promoted === '') { continue; }
                $stmts[] = new Return_(
                    new LoadLocal($p->name, $cd->propertyTypes[$p->name] ?? $tdCarrier),
                    $tdCarrier,
                );
            }
        } elseif ($m->name === '__construct') {
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
        foreach ($m->body->statements as $bodyStmt) {
            $stmts[] = $this->lowerStmt($bodyStmt);
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
        // The constructor of a `#[TypeDef]` returns the value it computed.
        if ($tdCtor) { $mret = $this->typeDefCarrier($decl->name); }
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
    private function ffiSymbolOf(array $attributes): ?string
    {
        foreach ($attributes as $attr) {
            $name = \ltrim($attr->name, '\\');
            if ($name !== 'Symbol' && $name !== 'Ffi\\Symbol') { continue; }
            if ($attr->args === []) { continue; }
            $arg = $attr->args[0];
            // Read `->value` through a StringLiteral-typed param: `$arg` is a
            // base-`Expr` here, and the subclass `value` field sits past the
            // base fields, so a base-typed read picks the wrong offset under
            // self-host (T5) → a garbage symbol name. The kind check above
            // proves it is a StringLiteral.
            if ($arg->kind === 'StringLiteral') { return $this->strLitValue($arg); }
        }
        return null;
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
        $call = new Call($fnName, $callArgs, $ret);
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
        $declParams = $cls !== '' ? $this->resolveMethodParams($cls, $method) : null;
        [$mir, $loads] = $this->fccParamsAndArgs($declParams);
        $body = new MethodCall_(new LoadLocal("__frecv", $recv->type), $method, $loads, Type::unknown());
        return $this->buildClosureNode($mir, ['__frecv'], [$recv->type], [$recv], $body, Type::unknown());
    }

    /** A string callable `"fn"` / `"C::m"` applied to `$astArgs`. */
    private function lowerStringCallable(string $name, array $astArgs): Node
    {
        $args = [];
        foreach ($astArgs as $a) { $args[] = $this->lowerExpr($a); }
        $cc = \strpos($name, '::');
        if ($cc !== false && $cc > 0) {
            $cls = \ltrim(\substr($name, 0, $cc), '\\');
            return new StaticCall_($cls, \substr($name, $cc + 2), $args, Type::unknown(), $cls);
        }
        return new Call($this->resolveCallName($name), $args, Type::unknown());
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
    private function lowerIdentifier(string $rawName): Node
    {
        // An unqualified constant resolves in the current namespace
        // first, then the global one — so `Compile\PHP_INT_MAX` is really
        // the global `PHP_INT_MAX`. Match on the trailing segment.
        $name = $this->constBareName($rawName);
        $pre = $this->predefinedConstant($name);
        if ($pre !== null) { return $pre; }
        if (isset($this->userConstants[$name])) {
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
     */
    private function lowerStaticLocal(\Parser\Ast\StaticLocalStmt $stmt): Node
    {
        $base = $this->currentLowerClass !== ''
            ? '@' . $this->currentLowerClass . '__' . $this->currentLowerFn
            : '@' . $this->currentLowerFn;
        $nodes = [];
        foreach ($stmt->decls as $d) {
            $cell = $base . '__sl_' . $d->name;
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
        if ($info !== null) { $this->constCallables[$name] = $info; }
    }

    /** Classify a callable-literal assignment value, or null. */
    private function callableLiteralInfo(\Parser\Ast\Expr $value): ?array
    {
        if ($value->kind === 'StringLiteral') {
            return ['kind' => 'str', 'name' => $this->strLitValue($value)];
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
     * Lower a call's arguments into positional MIR order for `$fnName`,
     * reordering named args and filling omitted trailing params from
     * their defaults. Unknown callees (builtins) fall back to plain
     * positional lowering.
     * @param \Parser\Ast\Expr[] $astArgs
     * @return Node[]
     */
    private function lowerCallArgs(string $fnName, array $astArgs): array
    {
        if (!isset($this->fnDecls[$fnName])) {
            $out = [];
            foreach ($astArgs as $a) { $out[] = $this->lowerExpr($a); }
            return $out;
        }
        $params = $this->fnDecls[$fnName]->params;
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
    private function methodDeclName(\Parser\Ast\MethodDecl $m): string { return $m->name; }
    /** @return \Parser\Ast\Param[] */
    private function methodDeclParams(\Parser\Ast\MethodDecl $m): array { return $m->params; }

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

    private function lowerTernary(\Parser\Ast\Ternary $expr): Node
    {
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

    private function lowerArrayLit(\Parser\Ast\ArrayLit $expr): ArrayLit
    {
        $elems = [];
        foreach ($expr->elements as $el) {
            $k = $el->key === null ? null : $this->lowerExpr($el->key);
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

    private function lowerArrayAccess(\Parser\Ast\ArrayAccess $expr): ArrayAccess_
    {
        $arr = $this->lowerExpr($expr->array);
        $idx = $expr->index === null
            ? new NullConst(Type::null_())
            : $this->lowerExpr($expr->index);
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
        $arms = [];
        foreach ($this->classTable as $name => $cd) {
            // A `#[TypeDef]` is not a candidate for a DYNAMIC `new $cls(…)`: the
            // class has no runtime form to select, and obj<U8> would be a pointer
            // to nothing. A TypeDef is constructed only where its name is written.
            if ($this->isTypeDef($name)) { continue; }
            $params = $this->resolveMethodParams($name, '__construct');
            $need = $params === null ? 0 : \count($params);
            if ($need !== $argc) { continue; }
            $arms[] = Type::obj($name);
        }
        $t = Type::unknown();
        if (\count($arms) === 1) { $t = $arms[0]; }
        elseif (\count($arms) > 1) { $t = Type::union($arms); }
        return new NewDynObj($this->lowerExpr($expr->classExpr), $args, $t);
    }

    private function lowerNewExpr(\Parser\Ast\NewExpr $expr): Node
    {
        // `new self` / `new static` / `new parent` → concrete class.
        $cls = $this->resolveStaticClass($expr->class);
        $params = $this->resolveMethodParams($cls, '__construct');
        if ($params !== null) {
            $args = $this->defaultFillArgs($params, $expr->args);
        } else {
            $args = [];
            foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
        }
        // `new U8(x)` allocates NOTHING: a TypeDef's constructor is the function
        // that COMPUTES its scalar, so the `new` IS that call. The class has no
        // runtime form for a NewObj to point at.
        if ($this->isTypeDef($cls)) {
            return new Call(
                \ltrim($cls, '\\') . '____construct',
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
        if ($expr->object->kind === 'Variable'
            && $expr->object->name === 'this'
            && $this->currentLowerClass !== '') {
            $params = $this->resolveMethodParams($this->currentLowerClass, $expr->method);
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
            $args = $this->defaultFillArgs($params, $expr->args);
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
            foreach ($this->defaultFillArgs($params, $expr->args) as $f) { $args[] = $f; }
        } else {
            foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
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
            $left  = $this->lowerExpr($e->left);
            $right = $this->lowerExpr($e->right);
            // Both arms bool so the Ternary stays bool through InferTypes
            // (a bool/int arm mismatch widens it to unknown → echoes "0").
            return new Ternary($left, $this->truthy($right), new BoolConst(false, Type::bool_()), Type::bool_());
        }
        if ($op === '||' || $op === 'or') {
            $left  = $this->lowerExpr($e->left);
            $right = $this->lowerExpr($e->right);
            return new Ternary($left, new BoolConst(true, Type::bool_()), $this->truthy($right), Type::bool_());
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
