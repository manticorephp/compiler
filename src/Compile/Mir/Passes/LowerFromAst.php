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

    /** @var array<string, string> unambiguous short class name → FQN */
    private array $shortClassFqn = [];

    /** @var array<string, bool> short names declared by 2+ classes (unresolvable) */
    private array $shortClassAmbiguous = [];

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
                $module->addClass($cd);
            }
        }

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
            $mainStmts[] = $this->lowerStmt($stmt);
        }
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
     * Built-in Throwable / Exception hierarchy, parsed as PHP so it
     * lowers through the normal class path. Clean, minimal — a string
     * `message` + `getMessage()` (no is_string/tagged-value deps).
     * @return \Parser\Ast\Stmt[]
     */
    /**
     * A Throwable class body (Exception / Error). Carries the message/code/
     * previous plus the thrown-location (`line`/`file`) and the captured call
     * stack (`traceNames`/`traceLines`, filled at `new` by EmitLlvm when the
     * program queries a trace). The trace getters read those; the trace-usage
     * gate matches the arrow-call form of these getters, so their bare
     * `function get...(` definitions here do not trip a self-build.
     */
    private function throwableClassSrc(string $name, string $iface): string
    {
        // getTrace: PHP-shaped assoc frames only when the backtrace prelude is
        // injected (a trace user); otherwise the bare name vec (the builder is
        // absent, so it must not be referenced — keeps the self-build clean).
        $getTrace = $this->includeBacktrace
            ? "  public function getTrace(): array { return __mir_bt_frames(\$this->traceNames, \$this->traceLines, \$this->file); }\n"
            : "  public function getTrace(): array { return \$this->traceNames; }\n";
        return "class " . $name . " implements " . $iface . " {\n"
            . "  public string \$message;\n"
            . "  public int \$code;\n"
            . "  public ?Throwable \$previous;\n"
            . "  public int \$line = 0;\n"
            . "  public string \$file = \"\";\n"
            . "  /** @var string[] */ public array \$traceNames = [];\n"
            . "  /** @var int[] */ public array \$traceLines = [];\n"
            . "  public function __construct(string \$message = \"\", int \$code = 0, ?Throwable \$previous = null) {\n"
            . "    \$this->message = \$message; \$this->code = \$code; \$this->previous = \$previous;\n"
            . "  }\n"
            . "  public function getMessage(): string { return \$this->message; }\n"
            . "  public function getCode(): int { return \$this->code; }\n"
            . "  public function getPrevious(): ?Throwable { return \$this->previous; }\n"
            . "  public function getLine(): int { return \$this->line; }\n"
            . "  public function getFile(): string { return \$this->file; }\n"
            . $getTrace
            . "  public function getTraceAsString(): string {\n"
            . "    \$s = \"\"; \$n = \\count(\$this->traceNames); \$i = 0;\n"
            . "    while (\$i < \$n) {\n"
            . "      \$s = \$s . \"#\" . \$i . \" \" . \$this->file . \"(\" . \$this->traceLines[\$i] . \"): \" . \$this->traceNames[\$i] . \"()\\n\";\n"
            . "      \$i = \$i + 1;\n"
            . "    }\n"
            . "    return \$s . \"#\" . \$n . \" {main}\";\n"
            . "  }\n"
            . "}\n";
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

    private function preludeStatements(): array
    {
        $src = "<?php\n"
            . "interface Throwable {}\n"
            . $this->throwableClassSrc("Exception", "Throwable")
            . $this->throwableClassSrc("Error", "Throwable")
            . "class RuntimeException extends Exception {}\n"
            . "class LogicException extends Exception {}\n"
            . "class InvalidArgumentException extends LogicException {}\n"
            . "class OutOfRangeException extends LogicException {}\n"
            . "class TypeError extends Error {}\n"
            . "class ValueError extends Error {}\n";
        if ($this->includeBacktrace) {
            $src = $src . $this->backtraceFramesSrc();
        }
        if ($this->includeVarDump) {
            $src = $src . $this->varDumpPreludeSrc();
        }
        if ($this->includeArrayClasses) {
            $src = $src . $this->arrayClassesPreludeSrc();
        }
        if ($this->includeArrayFns && $this->arrayFnsSrc !== '') {
            $src = $src . $this->arrayFnsSrc;
        }
        if ($this->includeCli && $this->cliSrc !== '') {
            $src = $src . $this->cliSrc;
        }
        if ($this->includePrintR && $this->printRSrc !== '') {
            $src = $src . $this->printRSrc;
        }
        $program = \Parser\Parser::parseSource($src);
        return $program->statements;
    }


    /**
     * PHP source for the built-in SPL ArrayIterator / ArrayObject. Backed by a
     * `mixed` (cell) array so any value type round-trips; keys are rebuilt with
     * a foreach (NOT array_keys, which a prelude-only call wouldn't link). All
     * key/value params are `mixed` so the call sites NaN-box them — the cell
     * array store/get/isset/unset/foreach paths then handle them. Included only
     * when the program references either name (avoids the cell runtime in every
     * binary). Practical subset; matches PHP for the common surface.
     *
     * The readable source of truth is `prelude/spl_arrays.php` (read by Main
     * into {@see $arrayClassesSrc}); the inline copy below is the byte-identical
     * bootstrap/distribution fallback used when that file can't be read (the
     * Zend cold-seed `read_file` stub throws; a no-src distribution). Keep both
     * in sync.
     */
    private function arrayClassesPreludeSrc(): string
    {
        if ($this->arrayClassesSrc !== '') {
            return $this->arrayClassesSrc;
        }
        return "class ArrayIterator implements Iterator, ArrayAccess, Countable {\n"
            . "  private mixed \$__s; private mixed \$__k; private int \$__i = 0;\n"
            . "  public function __construct(mixed \$array = []) { \$this->__s = \$array; \$this->__rebuildKeys(); }\n"
            . "  private function __rebuildKeys(): void { \$ks = []; foreach (\$this->__s as \$k => \$v) { \$ks[] = \$k; } \$this->__k = \$ks; }\n"
            . "  public function rewind(): void { \$this->__rebuildKeys(); \$this->__i = 0; }\n"
            . "  public function valid(): bool { return \$this->__i < count(\$this->__k); }\n"
            . "  public function current(): mixed { return \$this->__s[\$this->__k[\$this->__i]]; }\n"
            . "  public function key(): mixed { return \$this->__k[\$this->__i]; }\n"
            . "  public function next(): void { \$this->__i = \$this->__i + 1; }\n"
            . "  public function offsetExists(mixed \$o): bool { return isset(\$this->__s[\$o]); }\n"
            . "  public function offsetGet(mixed \$o): mixed { return \$this->__s[\$o]; }\n"
            . "  public function offsetSet(mixed \$o, mixed \$v): void { if (\$o === null) { \$this->__s[] = \$v; } else { \$this->__s[\$o] = \$v; } }\n"
            . "  public function offsetUnset(mixed \$o): void { unset(\$this->__s[\$o]); }\n"
            . "  public function count(): int { return count(\$this->__s); }\n"
            . "  public function append(mixed \$v): void { \$this->__s[] = \$v; }\n"
            . "  public function getArrayCopy(): mixed { return \$this->__s; }\n"
            . "}\n"
            . "class ArrayObject implements IteratorAggregate, ArrayAccess, Countable {\n"
            . "  private mixed \$__s;\n"
            . "  public function __construct(mixed \$array = []) { \$this->__s = \$array; }\n"
            . "  public function offsetExists(mixed \$o): bool { return isset(\$this->__s[\$o]); }\n"
            . "  public function offsetGet(mixed \$o): mixed { return \$this->__s[\$o]; }\n"
            . "  public function offsetSet(mixed \$o, mixed \$v): void { if (\$o === null) { \$this->__s[] = \$v; } else { \$this->__s[\$o] = \$v; } }\n"
            . "  public function offsetUnset(mixed \$o): void { unset(\$this->__s[\$o]); }\n"
            . "  public function count(): int { return count(\$this->__s); }\n"
            . "  public function append(mixed \$v): void { \$this->__s[] = \$v; }\n"
            . "  public function getArrayCopy(): mixed { return \$this->__s; }\n"
            . "  public function getIterator(): ArrayIterator { return new ArrayIterator(\$this->__s); }\n"
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

    /**
     * PHP source for `__mir_dump_object` — a class-aware var_dump for typed
     * objects, generated from the complete class table. Each known class gets
     * an `instanceof` branch (most-derived first, so a subclass is matched
     * before its base) that prints `object(Class)#1 (N) { ["prop"]=> ... }` over
     * its declared properties via the recursive `__mir_var_dump`. A dynamic
     * (stdClass / bag) object falls through to a bag walk. Clarity over strict
     * PHP parity: public-style keys (no visibility annotation), a fixed `#1` id.
     */
    private function dumpObjectSrc(): string
    {
        $names = [];
        $depths = [];
        foreach ($this->classTable as $cname => $cd) {
            if ($cname === 'stdClass') { continue; }
            if ($cd->isStruct) { continue; }
            $names[] = $cname;
            $depths[] = $this->classDepth($cname);
        }
        // Selection sort by depth DESC (a subclass before its base).
        $n = \count($names);
        $i = 0;
        while ($i < $n) {
            $max = $i;
            $j = $i + 1;
            while ($j < $n) {
                if ($depths[$j] > $depths[$max]) { $max = $j; }
                $j = $j + 1;
            }
            if ($max !== $i) {
                $tn = $names[$i]; $names[$i] = $names[$max]; $names[$max] = $tn;
                $td = $depths[$i]; $depths[$i] = $depths[$max]; $depths[$max] = $td;
            }
            $i = $i + 1;
        }
        $body = "function __mir_dump_object(mixed \$v, int \$indent): void {\n"
            . "  \$pad = ''; \$jj = 0; while (\$jj < \$indent) { \$pad = \$pad . '  '; \$jj = \$jj + 1; }\n"
            // An enum-case singleton renders `enum(Enum::Case)` — detected via its
            // class descriptor before the instanceof walk (enums aren't classes here).
            . "  \$en = __mir_enum_name(\$v); if (\$en !== '') { echo 'enum(', \$en, \")\\n\"; return; }\n";
        $ci = 0;
        while ($ci < $n) {
            $cname = $names[$ci];
            $cd = $this->classTable[$cname];
            $props = $cd->propertyNames;
            $pc = (string)\count($props);
            $body = $body . "  if (\$v instanceof \\" . $cname . ") {\n"
                . "    echo 'object(" . $cname . ")#1 (" . $pc . ") {' . \"\\n\";\n";
            foreach ($props as $p) {
                $body = $body . "    echo \$pad, '  [\"" . $p . "\"]=>', \"\\n\", \$pad, '  '; __mir_var_dump(\$v->" . $p . ", \$indent + 1);\n";
            }
            $body = $body . "    echo \$pad, \"}\\n\"; return;\n  }\n";
            $ci = $ci + 1;
        }
        $body = $body
            . "  \$arr = (array)\$v;\n"
            . "  echo 'object(stdClass)#1 (', (string)count(\$arr), \") {\\n\";\n"
            . "  foreach (\$arr as \$k => \$val) {\n"
            . "    echo \$pad, '  [\"', \$k, \"\\\"]=>\\n\", \$pad, '  ';\n"
            . "    __mir_var_dump(\$val, \$indent + 1);\n"
            . "  }\n"
            . "  echo \$pad, \"}\\n\";\n}\n";
        return $body;
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
     * Name of a class decl read through a ClassDecl-typed param. The
     * pre-pass holds the decl as a statically-unknown `$stmt->decl`;
     * reading `->name` off that erases to the wrong field offset under
     * self-host. Threading it through this typed param fixes the offset.
     */
    private function classDeclName(\Parser\Ast\ClassDecl $decl): string
    {
        return $decl->name;
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

    private function buildClassDef(\Parser\Ast\ClassDecl $decl, int $classId): ClassDef
    {
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        // Set the class context so `self` / `static` / `parent` in a
        // property or promoted-ctor-param type hint resolves to obj<Class>
        // (else lowerTypeHint erases it to unknown → method dispatch on the
        // property can't find a return flavor). Restored before return.
        $savedLowerClass = $this->currentLowerClass;
        $this->currentLowerClass = $decl->name;
        $names = [];
        $types = [];
        // Single inheritance: prepend the parent's properties so the
        // subclass instance shares the parent's field offsets, then
        // append the subclass's own. Parent is assumed already built
        // (declared earlier in source — the common case).
        $parent = '';
        if ($decl->extends !== []) {
            $parent = \ltrim($decl->extends[0], '\\');
        }
        if ($parent !== '' && isset($this->classTable[$parent])) {
            $pcd = $this->classTable[$parent];
            foreach ($pcd->propertyNames as $pn) {
                $names[] = $pn;
                $types[$pn] = $pcd->propertyTypes[$pn] ?? Type::unknown();
            }
        }
        foreach ($decl->methods as $m) {
            if ($m->name === '__construct') {
                foreach ($m->params as $p) {
                    if ($p->promoted !== '') {
                        $names[] = $p->name;
                        $pdoc = $this->docTagType($m->docComment, '@param', $p->name);
                        $peff = $this->effectiveHint($p->typeHint, $pdoc);
                        $types[$p->name] = $this->lowerTypeHint($peff);
                    }
                }
            }
        }
        $spNames = [];
        $spTypes = [];
        foreach ($decl->properties as $prop) {
            $vdoc = $this->docTagType($prop->docComment, '@var', '');
            $veff = $this->effectiveHint($prop->typeHint, $vdoc);
            $pt = $this->lowerTypeHint($veff);
            // Bare `array` with no docblock: recover the element type from a
            // homogeneous list-literal default, else from how the class's methods
            // push into it (usage inference) — both keep reads typed instead of
            // erased. Static props keep the default-only recovery (no $this stores).
            if ($this->isBareArrayHint($veff)) {
                $this->propStoreStrKey = false;
                $elem = $prop->default !== null ? $this->inferBareArrayPropElem($prop->default) : null;
                if ($elem === null && !$prop->isStatic) {
                    $elem = $this->inferPropElemFromStores($decl, $prop->name);
                }
                if ($elem !== null) {
                    $pt = $this->propStoreStrKey ? Type::assoc(Type::string_(), $elem) : Type::vec($elem);
                }
            }
            if ($prop->isStatic) {
                $spNames[] = $prop->name;
                $spTypes[] = $pt;
                $this->staticProps[$decl->name . '::' . $prop->name] = true;
                $this->staticPropTypes[$decl->name . '::' . $prop->name] = $pt;
                continue;
            }
            $names[] = $prop->name;
            $types[$prop->name] = $pt;
        }
        // Mixed-in trait properties extend the class's layout (PHP appends
        // them after the class's own fields). Without this they get no slot,
        // so `$this->traitProp` inside a trait method reads a wrong offset →
        // heap corruption (e.g. a string slot that lands on an obj/vec tag →
        // strcmp-on-RC_TAG_MAGIC abort). The class's own property wins on
        // conflict. Static trait props are not yet mixed in (rare).
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->properties as $tprop) {
                if ($tprop->isStatic) { continue; }
                if (isset($types[$tprop->name])) { continue; }
                $tvdoc = $this->docTagType($tprop->docComment, '@var', '');
                $tveff = $this->effectiveHint($tprop->typeHint, $tvdoc);
                $names[] = $tprop->name;
                $types[$tprop->name] = $this->lowerTypeHint($tveff);
            }
        }
        // PHP 8.4 property hooks: inherit the parent's map, then record each
        // hooked property's get/set accessor symbol. The property keeps its
        // storage slot (allocated above) — a virtual hook just never uses it.
        $propHooks = [];
        if ($parent !== '' && isset($this->classTable[$parent])) {
            foreach ($this->classTable[$parent]->propHooks as $pn => $ph) {
                $propHooks[$pn] = $ph;
            }
        }
        $methodNames = [];
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic || $prop->hooks === []) { continue; }
            // Empty-string = absent hook (never null — a null assoc value trips
            // the self-host missing-key-reads-false hazard downstream).
            $get = '';
            $set = '';
            foreach ($prop->hooks as $hook) {
                $sym = $decl->name . '____hook_' . $prop->name . '_' . $hook->kind;
                if ($hook->kind === 'get') { $get = $sym; $methodNames['__hook_' . $prop->name . '_get'] = true; }
                if ($hook->kind === 'set') { $set = $sym; $methodNames['__hook_' . $prop->name . '_set'] = true; }
            }
            $propHooks[$prop->name] = ['get' => $get, 'set' => $set];
        }
        foreach ($decl->methods as $m) {
            $methodNames[$m->name] = true;
        }
        // Mixed-in trait methods count as the class's own (for dispatch
        // + ctor resolution); the class's own method wins on conflict.
        // `use … { A::m insteadof B; }` excludes the loser; `m as x;` adds an alias.
        $excluded = $this->traitExclusions($decl);
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->methods as $tm) {
                if (isset($excluded[$tn . '::' . $tm->name])) { continue; }
                if (!isset($methodNames[$tm->name])) { $methodNames[$tm->name] = true; }
            }
        }
        foreach ($decl->traitAdaptations as $a) {
            if ($a->kind === 'as' && $a->alias !== '') { $methodNames[$a->alias] = true; }
        }
        // A class with defaulted properties but no user ctor gets a
        // synthesised one (see lowerClassMethods) — flag it so NewObj
        // calls it.
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic) { continue; }
            if ($prop->default !== null) { $methodNames['__construct'] = true; break; }
        }
        // A defaulted trait property also needs the synthesised ctor to run.
        if (!isset($methodNames['__construct'])) {
            foreach ($decl->uses as $traitName) {
                $td = $this->traitTable[\ltrim($traitName, '\\')] ?? null;
                if ($td === null) { continue; }
                foreach ($td->properties as $tprop) {
                    if ($this->traitPropHasDefault($tprop)) { $methodNames['__construct'] = true; break; }
                }
            }
        }
        $ifaces = [];
        foreach ($decl->implements as $i) { $ifaces[] = \ltrim($i, '\\'); }
        // Register a module global cell per static property (initialised
        // to its literal default, or 0). Set the class context so a
        // default like `self::CONST` resolves against this class.
        $prevLowerClass = $this->currentLowerClass;
        $this->currentLowerClass = $decl->name;
        foreach ($decl->properties as $prop) {
            if (!$prop->isStatic) { continue; }
            $def = $prop->default !== null ? $this->lowerExpr($prop->default) : new IntConst(0, Type::int_());
            $this->module->addGlobalCell('@' . $this->sanitizeSym($decl->name . '__sp_' . $prop->name), $def);
        }
        $this->currentLowerClass = $prevLowerClass;
        $isStruct = $this->hasStructAttr($decl->attributes);
        $hasBag = $this->hasDynamicPropsAttr($decl->attributes);
        $this->currentLowerClass = $savedLowerClass;
        return new ClassDef($decl->name, $classId, $names, $types, $methodNames, $parent, $ifaces, $spNames, $spTypes, $isStruct, $hasBag, $propHooks);
    }

    /**
     * Whether a (mixed-in trait) property carries a non-static default.
     * Reads through a typed PropertyDecl param so `->default` resolves the
     * right offset under self-host (T5 pattern — an untyped foreach element
     * mis-reads the nullable-Expr field).
     */
    private function traitPropHasDefault(\Parser\Ast\PropertyDecl $tp): bool
    {
        return !$tp->isStatic && $tp->default !== null;
    }

    /** Default-init store for one inherited (parent-declared) property (typed-param T5). */
    private function inheritedPropDefaultStore(\Parser\Ast\PropertyDecl $prop, string $className, Type $pt): StoreProperty
    {
        return new StoreProperty(
            new LoadLocal('this', Type::obj($className)),
            $prop->name,
            $this->lowerExpr($prop->default),
            $pt,
            $prop->hooks !== [],
        );
    }

    /** Default-init store for one mixed-in trait property (typed-param T5). */
    private function traitPropDefaultStore(\Parser\Ast\PropertyDecl $tp, string $className, Type $pt): StoreProperty
    {
        return new StoreProperty(
            new LoadLocal('this', Type::obj($className)),
            $tp->name,
            $this->lowerExpr($tp->default),
            $pt,
        );
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
     * Lower each instance method into a `Class__method` MIR
     * function with `$this` as the implicit first param. The
     * constructor additionally gets synthetic `$this->p = $p`
     * stores for every promoted parameter, prepended to the body.
     */
    private function lowerClassMethods(\Parser\Ast\ClassDecl $decl, Module $module): void
    {
        $cd = $this->classTable[$decl->name];
        $this->currentLowerClass = $decl->name;
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        // Property-default stores (`public int $c = 7` → `$this->c = 7`),
        // applied at construction before the ctor body. Lowered into a
        // real function body so they pass through InferTypes like any
        // other node.
        $defaultStores = [];
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic) { continue; }
            if ($prop->default === null) { continue; }
            $ptype = $cd->propertyTypes[$prop->name] ?? Type::unknown();
            // A hooked property's default initialises the backing slot DIRECTLY —
            // PHP does not route the default through the set hook.
            $defaultStores[] = new StoreProperty(
                new LoadLocal('this', Type::obj($decl->name)),
                $prop->name,
                $this->lowerExpr($prop->default),
                $ptype,
                $prop->hooks !== [],
            );
        }
        // Inherited property defaults: PHP applies EVERY declared default at
        // instantiation, not just the leaf class's. A subclass ctor (synthesized
        // or explicit) must therefore also initialise the parent chain's
        // defaulted properties — else an inherited `public string $k = 'x'` reads
        // back as 0/null on a subclass instance. Nearer class wins on name
        // conflict (a redeclaration, even without a default, shadows the parent).
        $seen = [];
        foreach ($decl->properties as $prop) { $seen[$prop->name] = true; }
        $pname = $decl->extends !== [] ? \ltrim($decl->extends[0], '\\') : '';
        $guard = 0;
        while ($pname !== '' && isset($this->classDecls[$pname]) && $guard < 256) {
            $pdecl = $this->classDecls[$pname];
            foreach ($pdecl->properties as $pprop) {
                if (isset($seen[$pprop->name])) { continue; }
                $seen[$pprop->name] = true;
                if ($pprop->isStatic) { continue; }
                if ($pprop->default === null) { continue; }
                $pptype = $cd->propertyTypes[$pprop->name] ?? Type::unknown();
                $defaultStores[] = $this->inheritedPropDefaultStore($pprop, $decl->name, $pptype);
            }
            $pname = $pdecl->extends !== [] ? \ltrim($pdecl->extends[0], '\\') : '';
            $guard = $guard + 1;
        }
        // Mixed-in trait property defaults too — the layout merge in
        // buildClassDef gives them a slot, but without these stores a
        // defaulted trait property is never initialized (uninitialized read,
        // e.g. a string slot → SIGSEGV). Field access goes through typed
        // helpers (T5). Class's own property wins.
        $ownPropNames = [];
        foreach ($decl->properties as $prop) { $ownPropNames[$prop->name] = true; }
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->properties as $tprop) {
                if (isset($ownPropNames[$tprop->name])) { continue; }
                if (!$this->traitPropHasDefault($tprop)) { continue; }
                $tptype = $cd->propertyTypes[$tprop->name] ?? Type::unknown();
                $defaultStores[] = $this->traitPropDefaultStore($tprop, $decl->name, $tptype);
            }
        }
        // Augment with mixed-in trait methods (the class's own method
        // wins on conflict — trait_override). Each trait method is
        // lowered as `Class__method` with $this typed as the class, so
        // `$this->prop` resolves against the class layout.
        // Copy into a fresh vec — `$methods = $decl->methods` would ALIAS
        // the ClassDecl's own methods vec (no COW self-host); the trait
        // append below reallocs an empty vec, freeing the buffer that
        // `$this->classDecls[$class]->methods` still points to → UAF.
        $methods = [];
        foreach ($decl->methods as $m) { $methods[] = $m; }
        // Synthesize a method per property hook: `<prop>` get/set hooks become
        // `__hook_<prop>_get` / `__hook_<prop>_set`, lowered like any method so
        // `$this` / `$value` and the body pass through InferTypes. A get arrow
        // returns the expr; a set arrow stores the expr to the backing slot.
        // Both bodies reference `$this-><prop>` DIRECTLY (EmitLlvm's hook
        // dispatch suppresses re-entry while emitting the property's own hook).
        foreach ($decl->properties as $prop) {
            if ($prop->isStatic || $prop->hooks === []) { continue; }
            foreach ($prop->hooks as $hook) {
                $sp = $prop->span;
                if ($hook->kind === 'get') {
                    $body = $hook->blockBody
                        ?? new \Parser\Ast\Block([\Parser\Ast\Stmt::return_($hook->exprBody, $sp)]);
                    $methods[] = new \Parser\Ast\MethodDecl(
                        '__hook_' . $prop->name . '_get',
                        'public', false, false, false, [],
                        $prop->typeHint, $body, [], $sp,
                    );
                } else {
                    $pname = $hook->paramName ?? 'value';
                    $ptypeHint = $hook->paramType ?? $prop->typeHint;
                    $param = new \Parser\Ast\Param($pname, $ptypeHint, null, false, false, '', false, [], $sp);
                    $body = $hook->blockBody
                        ?? new \Parser\Ast\Block([\Parser\Ast\Stmt::expression(
                            \Parser\Ast\Expr::assign(
                                \Parser\Ast\Expr::propertyAccess(
                                    \Parser\Ast\Expr::variable('this', $sp), $prop->name, false, $sp),
                                $hook->exprBody, $sp),
                            $sp)]);
                    $methods[] = new \Parser\Ast\MethodDecl(
                        '__hook_' . $prop->name . '_set',
                        'public', false, false, false, [$param],
                        null, $body, [], $sp,
                    );
                }
            }
        }
        $ownNames = [];
        foreach ($decl->methods as $m) { $ownNames[$m->name] = true; }
        $excluded = $this->traitExclusions($decl);
        foreach ($decl->uses as $traitName) {
            $tn = \ltrim($traitName, '\\');
            $td = $this->traitTable[$tn] ?? null;
            if ($td === null) { continue; }
            foreach ($td->methods as $tm) {
                if (isset($excluded[$tn . '::' . $tm->name])) { continue; }
                if (!isset($ownNames[$tm->name])) { $methods[] = $tm; }
            }
        }
        // `m as alias` / `A::m as alias`: emit a renamed copy of the source
        // trait method (a visibility change without an alias is not enforced).
        foreach ($decl->traitAdaptations as $a) {
            if ($a->kind !== 'as' || $a->alias === '') { continue; }
            $src = $this->findTraitMethod($decl, \ltrim($a->trait, '\\'), $a->method);
            if ($src === null || isset($ownNames[$a->alias])) { continue; }
            $methods[] = new \Parser\Ast\MethodDecl(
                $a->alias, $src->visibility, $src->isStatic, $src->isFinal, $src->isAbstract,
                $src->params, $src->returnType, $src->body, $src->attributes, $src->span,
                $src->returnsByRef, $src->docComment,
            );
        }
        $sawCtor = false;
        foreach ($methods as $m) {
            if ($m->body === null) { continue; }
            if ($m->name === '__construct') { $sawCtor = true; }
            // Normal copy: late-static scope == the lexical class.
            $mfn = $this->lowerMethodFn(
                $decl, $m, $cd, $defaultStores,
                $decl->name, $decl->name . '__' . $m->name,
            );
            $module->addFunction($mfn);
            $sep = $m->isStatic ? '::' : '->';
            $module->methodDisplay[$decl->name . '__' . $m->name] =
                $decl->name . $sep . $m->name;
            // Methods that reference `static` (`static::class`, `new static`,
            // `static::method()`) bind late: a subclass that inherits this
            // body must see itself as `static`. Queue per-descendant copies.
            if ($this->sawStaticUse) {
                $this->lsbPending[] = new LsbPending($decl, $m, $cd, $defaultStores);
            }
        }
        // No user ctor but defaulted properties → synthesise a ctor
        // (`Class____construct($this)`) holding just the default stores.
        if (!$sawCtor && $defaultStores !== []) {
            $module->addFunction(new FunctionDef(
                name: $decl->name . '____construct',
                params: [new Param(
                    name: 'this',
                    type: Type::obj($decl->name),
                    byRef: false,
                    variadic: false,
                )],
                returnType: Type::void(),
                body: new Block($defaultStores, Type::void()),
            ));
        }
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
        $this->constCallables = [];
        $params = [];
        // Static methods have no implicit `$this`.
        if (!$m->isStatic) {
            $params[] = new Param(
                name: 'this',
                type: Type::obj($decl->name),
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
            $params[] = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVar,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
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
        foreach ($m->body->statements as $bodyStmt) {
            $stmts[] = $this->lowerStmt($bodyStmt);
        }
        $isGen = $this->sawYield;
        $this->sawYield = $savedSawYield;
        $mret = $this->lowerTypeHint($this->effectiveHint(
            $m->returnType,
            $this->docTagType($m->docComment, '@return', ''),
        ));
        if ($isGen) {
            $elem = $mret->isGenerator() ? $mret->element : null;
            $mret = Type::generator($elem);
        }
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

    private function lowerTryCatch(\Parser\Ast\TryCatchStmt $stmt): Node
    {
        $tryBody = [];
        foreach ($stmt->try->statements as $s) { $tryBody[] = $this->lowerStmt($s); }
        $catches = [];
        foreach ($stmt->catches as $c) {
            $types = [];
            foreach ($c->types as $t) { $types[] = \ltrim($t, '\\'); }
            $body = [];
            foreach ($c->body->statements as $s) { $body[] = $this->lowerStmt($s); }
            $catches[] = new MirCatch($types, $c->name, $body);
        }
        $finallyBody = [];
        $hasFinally = $stmt->finally !== null;
        if ($hasFinally) {
            foreach ($stmt->finally->statements as $s) { $finallyBody[] = $this->lowerStmt($s); }
        }
        return new TryCatch_($tryBody, $catches, $finallyBody, $hasFinally, Type::void());
    }

    private function lowerFunction(\Parser\Ast\FunctionDecl $decl): FunctionDef
    {
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        $this->constCallables = [];
        $params = [];
        foreach ($decl->params as $p) {
            $isVariadic = (bool)($p->variadic ?? false);
            // `T ...$xs` collects trailing args into a vec[T] the callee
            // sees as a single vec param (caller packs at the call site).
            $pt = $isVariadic
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerParamType($this->effectiveHint(
                    $p->typeHint,
                    $this->docTagType($decl->docComment, '@param', $p->name),
                ));
            $params[] = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVariadic,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
        }
        $this->currentLowerClass = '';
        $this->currentLowerFn = $decl->name;
        // FFI: `#[Symbol('cSym')]` makes this a thin extern forward — the
        // body (a stock-PHP fallback like `$GLOBALS['argc']`) is never
        // lowered; EmitLlvm emits a wrapper that calls the C symbol.
        $ffiSymbol = $this->ffiSymbolOf($decl->attributes);
        if ($ffiSymbol !== null) {
            $fn = new FunctionDef(
                name: $decl->name,
                params: $params,
                returnType: $this->lowerTypeHint($decl->returnType),
                body: new Block([], Type::void()),
                returnsByRef: false,
            );
            $fn->ffiSymbol = $ffiSymbol;
            $ctypes = [];
            foreach ($decl->params as $p) { $ctypes[] = $this->ffiCType($p->typeHint); }
            $fn->ffiParamCTypes = $ctypes;
            $fn->ffiRetCType = $this->ffiCType($decl->returnType);
            return $fn;
        }
        $savedSawYield = $this->sawYield;
        $this->sawYield = false;
        $loweredBody = $this->lowerBlockNode($decl->body);
        $isGen = $this->sawYield;
        $this->sawYield = $savedSawYield;
        $fn = new FunctionDef(
            name: $decl->name,
            params: $params,
            returnType: $this->lowerTypeHint($this->effectiveHint(
                $decl->returnType,
                $this->docTagType($decl->docComment, '@return', ''),
            )),
            body: $loweredBody,
            returnsByRef: (bool)($decl->returnsByRef ?? false),
        );
        $fn->isGenerator = $isGen;
        if ($fn->isGenerator) {
            // A generator CALL returns a Generator (its frame ptr); type it so
            // foreach / InferTypes route through the iterator-protocol path.
            // Keep a declared `: Generator<V>` element as the seed; InferTypes
            // refines it from the yield expressions.
            $declared = $fn->returnType;
            $elem = $declared->isGenerator() ? $declared->element : null;
            $fn->returnType = Type::generator($elem);
        }
        return $fn;
    }

    /**
     * Build a SIGNATURE-ONLY {@see FunctionDef} from a bundled-stdlib decl:
     * params (with defaults, for call-site filling) + return type, but an
     * empty body. EmitLlvm renders it as a `declare`; the body comes from the
     * linked stdlib.o. The body is deliberately never lowered — that avoids
     * both the per-program-merge codegen hazard and any output bloat.
     */
    private function lowerFunctionSignature(\Parser\Ast\FunctionDecl $decl): FunctionDef
    {
        $this->currentDeclNamespace = $this->nsOf($decl->name);
        $this->currentLowerClass = '';
        $this->currentLowerFn = $decl->name;
        $params = [];
        foreach ($decl->params as $p) {
            $isVariadic = (bool)($p->variadic ?? false);
            // EXTERN sig (from the stdlib .sig): an EMPTY type ("") means the
            // stdlib ERASED it to unknown (a genuine `mixed` serializes as
            // "mixed" → cell). Lower it to UNKNOWN via lowerTypeHint, NOT
            // lowerParamType (whose null→cell default makes the CALLER box an
            // array arg to a cell while the raw-walking stdlib callee reads it
            // as a plain array pointer → tag deref SIGSEGV: array_key_exists /
            // array_slice on a concrete assoc). A user function's own untyped
            // param still routes through lowerParamType (mixed) elsewhere.
            $pt = $isVariadic
                ? Type::vec($this->lowerTypeHint($p->typeHint))
                : $this->lowerTypeHint($this->effectiveHint(
                    $p->typeHint,
                    $this->docTagType($decl->docComment, '@param', $p->name),
                ));
            $params[] = new Param(
                name: $p->name,
                type: $pt,
                byRef: (bool)($p->byRef ?? false),
                variadic: $isVariadic,
                default: $p->default !== null ? $this->lowerExpr($p->default) : null,
            );
        }
        return new FunctionDef(
            name: $decl->name,
            params: $params,
            returnType: $this->lowerTypeHint($this->effectiveHint(
                $decl->returnType,
                $this->docTagType($decl->docComment, '@return', ''),
            )),
            body: new Block([], Type::void()),
            returnsByRef: (bool)($decl->returnsByRef ?? false),
        );
    }

    /**
     * True for a function name that {@see Passes\EmitLlvmBuiltins::emitBuiltin}
     * emits inline. Such a name must NOT be registered as a stdlib extern: the
     * builtin intercepts the call (so the extern declare would be dead) and,
     * worse, registering it would change default-arg filling at every call
     * site. Mirrors the emitBuiltin if-chain — keep in sync.
     */
    private function isCodegenBuiltin(string $name): bool
    {
        $n = \strtolower($name);
        $pos = \strrpos($n, '\\');
        if ($pos !== false) { $n = \substr($n, $pos + 1); }
        return $n === 'strlen' || $n === 'count' || $n === 'sizeof'
            || $n === 'ord' || $n === 'chr' || $n === 'abs' || $n === 'pow'
            || $n === 'intdiv'
            || $n === 'intval' || $n === 'floatval'
            || $n === 'is_null' || $n === 'is_int' || $n === 'is_integer'
            || $n === 'is_long' || $n === 'is_string' || $n === 'is_float'
            || $n === 'is_double' || $n === 'is_bool' || $n === 'is_array'
            || $n === 'is_object' || $n === 'is_callable'
            || $n === 'gettype' || $n === 'get_debug_type'
            || $n === 'min' || $n === 'max' || $n === 'dechex'
            || $n === 'substr' || $n === 'str_repeat'
            || $n === 'str_from_buffer' || $n === 'cstr_to_str'
            || $n === '__mir_stdin' || $n === '__mir_stdout' || $n === '__mir_stderr'
            || $n === '__mir_argc' || $n === '__mir_argv_at' || $n === '__mir_to_cell'
            || $n === 'strtolower' || $n === 'strtoupper' || $n === 'strpos'
            || $n === 'implode' || $n === 'join'
            || $n === 'sprintf' || $n === 'printf'
            || $n === 'exit' || $n === 'die' || $n === 'error_log'
            || $n === 'gc_collect_cycles' || $n === 'spl_object_id'
            || $n === 'get_class' || $n === 'array_pop' || $n === 'array_shift'
            || $n === 'array_unshift' || $n === 'addslashes' || $n === 'getenv'
            || $n === 'get_object_vars' || $n === 'var_export'
            || $n === 'class_exists' || $n === 'enum_exists'
            || $n === 'interface_exists' || $n === 'trait_exists'
            || $n === 'method_exists' || $n === 'property_exists'
            || $n === 'is_a' || $n === 'is_subclass_of'
            || $n === 'get_parent_class' || $n === 'get_class_methods'
            || $n === '__mir_float_repr';
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

    private function lowerBlockNode(\Parser\Ast\Block $block): Block
    {
        $stmts = [];
        foreach ($block->statements as $stmt) {
            $stmts[] = $this->lowerStmt($stmt);
        }
        return new Block($stmts, Type::void());
    }

    private function lowerStmt(\Parser\Ast\Stmt $stmt): Node
    {
        // Callable const-propagation is straight-line only: forget every
        // tracked variable across a control-flow boundary (a branch / loop may
        // rebind it on a path this linear scan can't see).
        $k = $stmt->kind;
        $cf = $k === 'If' || $k === 'While' || $k === 'For' || $k === 'DoWhile'
            || $k === 'Switch' || $k === 'Foreach' || $k === 'TryCatch';
        if ($cf) { $this->constCallables = []; }
        $node = $this->lowerStmtInner($stmt);
        if ($cf) { $this->constCallables = []; }
        if ($node->line === 0) { $node->line = $stmt->span->line; }
        return $node;
    }

    private function lowerStmtInner(\Parser\Ast\Stmt $stmt): Node
    {
        if ($stmt->kind === 'Expression') {
            $lowered = $this->lowerExpr($stmt->expr);
            // Inline `/** @var T $x */` on a local binding seeds the slot type
            // (a per-declaration annotation InferTypes honors — element types a
            // bare `array` local can't carry, e.g. `@var array<string, Type>`).
            if ($stmt->docComment !== null && $lowered instanceof StoreLocal) {
                $vt = $this->docTagType($stmt->docComment, '@var', $lowered->name);
                if ($vt === null) { $vt = $this->docTagType($stmt->docComment, '@var', ''); }
                if ($vt !== null) { $lowered->declaredType = $this->lowerTypeHint($vt); }
            }
            return $lowered;
        }
        if ($stmt->kind === 'Echo') {
            $items = [];
            foreach ($stmt->exprs as $e) {
                $items[] = $this->lowerExpr($e);
            }
            return new Echo_($items, Type::void());
        }
        if ($stmt->kind === 'Return') {
            $value = $stmt->value === null ? null : $this->lowerExpr($stmt->value);
            return new Return_($value, Type::void());
        }
        if ($stmt->kind === 'If')       { return $this->lowerIf($stmt); }
        if ($stmt->kind === 'While')    { return $this->lowerWhile($stmt); }
        if ($stmt->kind === 'For')      { return $this->lowerFor($stmt); }
        if ($stmt->kind === 'DoWhile')  { return $this->lowerDoWhile($stmt); }
        if ($stmt->kind === 'Switch')   { return $this->lowerSwitch($stmt); }
        if ($stmt->kind === 'Foreach')  { return $this->lowerForeach($stmt); }
        if ($stmt->kind === 'Break')    { return new Break_((int)($stmt->level ?? 1)); }
        if ($stmt->kind === 'Continue') { return new Continue_((int)($stmt->level ?? 1)); }
        if ($stmt->kind === 'Goto')     { return new Goto_($stmt->label, Type::void()); }
        if ($stmt->kind === 'Label')    { return new Label_($stmt->name, Type::void()); }
        if ($stmt->kind === 'StaticLocal') { return $this->lowerStaticLocal($stmt); }
        if ($stmt->kind === 'Global') { return $this->lowerGlobal($stmt); }
        if ($stmt->kind === 'Throw') { return new Throw_($this->lowerExpr($stmt->expr), Type::void()); }
        if ($stmt->kind === 'TryCatch') { return $this->lowerTryCatch($stmt); }
        throw new \RuntimeException(
            'MIR.lower: unsupported statement kind ' . $stmt->kind
        );
    }

    /**
     * Desugar `if cond { a } elseif c1 { b1 } elseif c2 { b2 } else { e }`
     * into `if cond { a } else { if c1 { b1 } else { if c2 { b2 } else { e } } }`.
     * Keeps the IR shape uniform and analysis straightforward.
     */
    private function lowerIf(\Parser\Ast\IfStmt $stmt): If_
    {
        $cond  = $this->lowerExpr($stmt->condition);
        $then  = $this->lowerBlockNode($stmt->then);
        $else_ = $stmt->else === null ? null : $this->lowerBlockNode($stmt->else);
        $elseifs = $stmt->elseifs;
        for ($i = \count($elseifs) - 1; $i >= 0; $i = $i - 1) {
            $pair = $elseifs[$i];
            $nested = new If_(
                $this->lowerExpr($pair->condition),
                $this->lowerBlockNode($pair->body),
                $else_,
            );
            $else_ = new Block([$nested], Type::void());
        }
        return new If_($cond, $then, $else_);
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

    private function lowerWhile(\Parser\Ast\WhileStmt $stmt): While_
    {
        $cond = $this->lowerExpr($stmt->condition);
        $body = $this->lowerBlockNode($stmt->body);
        return new While_($cond, $body);
    }

    private function lowerFor(\Parser\Ast\ForStmt $stmt): For_
    {
        $init = $this->lowerForClause($stmt->init);
        $cond = $stmt->condition === null ? null : $this->lowerExpr($stmt->condition);
        $step = $this->lowerForClause($stmt->update);
        $body = $this->lowerBlockNode($stmt->body);
        return new For_($init, $cond, $step, $body);
    }

    /**
     * Lower a for-clause expression list: null when empty, the single node
     * when one, else a Block evaluating each in sequence (side effects only).
     *
     * @param \Parser\Ast\Expr[] $exprs
     */
    private function lowerForClause(array $exprs): ?Node
    {
        if (\count($exprs) === 0) { return null; }
        if (\count($exprs) === 1) { return $this->lowerExpr($exprs[0]); }
        $stmts = [];
        foreach ($exprs as $e) { $stmts[] = $this->lowerExpr($e); }
        return new Block($stmts, Type::void());
    }

    private function lowerDoWhile(\Parser\Ast\DoWhileStmt $stmt): DoWhile_
    {
        $body = $this->lowerBlockNode($stmt->body);
        $cond = $this->lowerExpr($stmt->condition);
        return new DoWhile_($body, $cond);
    }

    private function lowerForeach(\Parser\Ast\ForeachStmt $stmt): Foreach_
    {
        $array = $this->lowerExpr($stmt->expr);
        $valueVar = $stmt->value->name;
        $keyVar = null;
        if ($stmt->key !== null) { $keyVar = $stmt->key->name; }
        $body = $this->lowerBlockNode($stmt->body);
        return new Foreach_($array, $keyVar, $valueVar, $stmt->valueByRef, $body);
    }

    private function lowerSwitch(\Parser\Ast\SwitchStmt $stmt): Switch_
    {
        $subject = $this->lowerExpr($stmt->expr);
        $arms = [];
        foreach ($stmt->cases as $arm) {
            $val = null;
            if ($arm->value !== null) { $val = $this->lowerExpr($arm->value); }
            $body = [];
            foreach ($arm->body as $s) { $body[] = $this->lowerStmt($s); }
            $arms[] = new SwitchArm_($val, $body);
        }
        return new Switch_($subject, $arms);
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

    /**
     * @param string[]            $capNames
     * @param \Parser\Ast\Param[] $declParams
     * @param array<string,bool>  $capByRef  capture name → by-reference?
     */
    private function finishClosure(array $capNames, array $declParams, Block $body, ?string $retHint, array $capByRef = [], bool $isGenerator = false): Node
    {
        // A closure / arrow fn in an instance method auto-binds `$this`
        // (PHP semantics — no `use ($this)` needed). If the body reads it
        // and it isn't already captured, prepend it so the closure fn gets
        // a `this` param; type it to the enclosing class so `$this->prop`
        // resolves inside the closure.
        $thisType = $this->currentLowerClass !== ''
            ? Type::obj($this->currentLowerClass) : Type::unknown();
        $hasThis = false;
        foreach ($capNames as $cn) { if ($cn === 'this') { $hasThis = true; } }
        if (!$hasThis && $this->nodeReadsThis($body)) {
            $prepended = ['this'];
            foreach ($capNames as $cn) { $prepended[] = $cn; }
            $capNames = $prepended;
        }
        $id = $this->closureCounter;
        $this->closureCounter = $this->closureCounter + 1;
        $fnName = '__closure_' . (string)$id;
        $params = [];
        foreach ($capNames as $cn) {
            $ptype = $cn === 'this' ? $thisType : Type::unknown();
            // A by-ref capture is passed (and unpacked) as a slot address —
            // mark the param byRef so the closure body derefs it (refLocals).
            $params[] = new Param(name: $cn, type: $ptype, byRef: $capByRef[$cn] ?? false, variadic: false);
        }
        foreach ($declParams as $p) {
            $params[] = new Param(
                name: $p->name,
                // Untyped closure param → cell (NOT unknown), matching a regular
                // untyped param. The uniform closure ABI passes every arg as a
                // tagged cell (so a dynamic `callable` dispatch works), so an
                // untyped param must carry the tag; an unknown-typed param would
                // read the raw bits and a string arg renders as its pointer.
                type: $this->lowerParamType($p->typeHint),
                byRef: (bool)($p->byRef ?? false),
                variadic: (bool)($p->variadic ?? false),
            );
        }
        $retType = $this->lowerTypeHint($retHint);
        if ($isGenerator) {
            // A generator closure CALL returns a Generator (its frame ptr);
            // type it so foreach / InferTypes route the iterator protocol.
            $elem = $retType->isGenerator() ? $retType->element : null;
            $retType = Type::generator($elem);
        }
        $clFn = new FunctionDef(
            name: $fnName,
            params: $params,
            returnType: $retType,
            body: $body,
        );
        $clFn->isGenerator = $isGenerator;
        $this->module->addFunction($clFn);
        $this->module->closureCaptures[$fnName] = \count($capNames);
        $captures = [];
        $captureByRef = [];
        foreach ($capNames as $cn) {
            $ctype = $cn === 'this' ? $thisType : Type::unknown();
            $captures[] = new LoadLocal($cn, $ctype);
            $captureByRef[] = $capByRef[$cn] ?? false;
        }
        return new Closure_($id, $captures, Type::obj($fnName), $captureByRef);
    }

    /** Whether the lowered node tree reads the local `$this`. */
    private function nodeReadsThis(Node $n): bool
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL && $this->asLoadLocal($n)->name === 'this') {
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

    private function injectCliSuperglobals(array $mainStmts): array
    {
        $readArgv = false; $readArgc = false;
        $setArgv = false; $setArgc = false;
        foreach ($mainStmts as $s) {
            if ($this->nodeReadsLocal($s, 'argv')) { $readArgv = true; }
            if ($this->nodeReadsLocal($s, 'argc')) { $readArgc = true; }
            if ($this->nodeWritesLocal($s, 'argv')) { $setArgv = true; }
            if ($this->nodeWritesLocal($s, 'argc')) { $setArgc = true; }
        }
        $pre = [];
        if ($readArgv && !$setArgv) {
            $pre[] = new StoreLocal(
                'argv',
                new Call('__mc_argv', [], Type::vec(Type::string_())),
                Type::vec(Type::string_()),
            );
        }
        if ($readArgc && !$setArgc) {
            $pre[] = new StoreLocal(
                'argc',
                new Call('__mir_argc', [], Type::int_()),
                Type::int_(),
            );
        }
        if ($pre === []) { return $mainStmts; }
        foreach ($mainStmts as $s) { $pre[] = $s; }
        return $pre;
    }

    /** Whether the node tree reads the named local (LoadLocal). */
    private function nodeReadsLocal(Node $n, string $name): bool
    {
        if ($n->kind === Node::KIND_LOAD_LOCAL && $this->asLoadLocal($n)->name === $name) {
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
        if ($n->kind === Node::KIND_STORE_LOCAL && $this->asStoreLocal($n)->name === $name) {
            return true;
        }
        foreach (Walk::children($n) as $c) {
            if ($this->nodeWritesLocal($c, $name)) { return true; }
        }
        return false;
    }

    private function asStoreLocal(Node $n): StoreLocal { return $n; }

    private function asLoadLocal(Node $n): LoadLocal { return $n; }
    private function asFloatLiteral(\Parser\Ast\Expr $e): \Parser\Ast\FloatLiteral { return $e; }
    /** Pin to IntLiteral so `->value` is INT-typed: a base-`Expr` read borrows a
     * subclass type and (under a union perturbation) can resolve to CELL — a
     * large int then truncates through the 48-bit inline box round-trip. */
    private function asIntLiteral(\Parser\Ast\Expr $e): \Parser\Ast\IntLiteral { return $e; }
    private function asPropAccess(\Parser\Ast\Expr $e): \Parser\Ast\PropertyAccess { return $e; }

    // Pin AST nodes to their concrete type before reading subclass fields — a
    // base-`Stmt`/`Expr` read off a bare array element resolves the wrong offset
    // under self-host (the poly-prop trap). Used by the usage-inference scan.
    private function asExprStmt(\Parser\Ast\Stmt $s): \Parser\Ast\ExpressionStmt { return $s; }
    private function asIfStmt(\Parser\Ast\Stmt $s): \Parser\Ast\IfStmt { return $s; }
    private function asWhileStmt(\Parser\Ast\Stmt $s): \Parser\Ast\WhileStmt { return $s; }
    private function asDoWhileStmt(\Parser\Ast\Stmt $s): \Parser\Ast\DoWhileStmt { return $s; }
    private function asForStmt(\Parser\Ast\Stmt $s): \Parser\Ast\ForStmt { return $s; }
    private function asForeachStmt(\Parser\Ast\Stmt $s): \Parser\Ast\ForeachStmt { return $s; }
    private function asTryCatchStmt(\Parser\Ast\Stmt $s): \Parser\Ast\TryCatchStmt { return $s; }
    private function asSwitchStmt(\Parser\Ast\Stmt $s): \Parser\Ast\SwitchStmt { return $s; }
    private function asElseIfArm(\Parser\Ast\ElseIfArm $a): \Parser\Ast\ElseIfArm { return $a; }
    private function asCatchClause(\Parser\Ast\CatchClause $c): \Parser\Ast\CatchClause { return $c; }
    private function asSwitchArm(\Parser\Ast\SwitchArm $a): \Parser\Ast\SwitchArm { return $a; }
    private function asAssign(\Parser\Ast\Expr $e): \Parser\Ast\Assign { return $e; }
    private function asArrayAccessExpr(\Parser\Ast\Expr $e): \Parser\Ast\ArrayAccess { return $e; }
    private function asNewExpr(\Parser\Ast\Expr $e): \Parser\Ast\NewExpr { return $e; }
    private function asVariableExpr(\Parser\Ast\Expr $e): \Parser\Ast\Variable { return $e; }
    private function asArrayLit(\Parser\Ast\Expr $e): \Parser\Ast\ArrayLit { return $e; }
    private function asBinaryOp(\Parser\Ast\Expr $e): \Parser\Ast\BinaryOp { return $e; }
    private function asCastExpr(\Parser\Ast\Expr $e): \Parser\Ast\Cast { return $e; }

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

    /**
     * Assemble a closure value: leading capture params (`$capNames`, bound to
     * `$capVals`) followed by the call params, with body `return <callNode>`.
     * Used by the method/static/builtin first-class-callable lowering.
     *
     * @param Param[]  $mirParams
     * @param string[] $capNames
     * @param Type[]   $capTypes
     * @param Node[]   $capVals
     */
    private function buildClosureNode(array $mirParams, array $capNames, array $capTypes, array $capVals, Node $callNode, Type $ret): Node
    {
        $id = $this->closureCounter;
        $this->closureCounter = $id + 1;
        $fnName = '__closure_' . (string)$id;
        $params = [];
        $i = 0;
        foreach ($capNames as $cn) {
            $params[] = new Param(name: $cn, type: $capTypes[$i], byRef: false, variadic: false);
            $i = $i + 1;
        }
        foreach ($mirParams as $mp) { $params[] = $mp; }
        $clFn = new FunctionDef(
            name: $fnName,
            params: $params,
            returnType: $ret,
            body: new Block([new Return_($callNode, Type::void())], Type::void()),
        );
        $this->module->addFunction($clFn);
        $this->module->closureCaptures[$fnName] = \count($capNames);
        $byRef = [];
        foreach ($capNames as $cn) { $byRef[] = false; }
        return new Closure_($id, $capVals, Type::obj($fnName), $byRef);
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

    /**
     * Convert a callable LITERAL argument (`"fn"`, `"C::m"`, `[$o,"m"]`,
     * `["C","m"]`) bound to a `callable`-typed parameter into a closure value,
     * so the callee can invoke it uniformly (e.g. `array_map("strtoupper",…)`).
     * Returns null when no conversion applies.
     */
    private function coerceCallableArg(?Type $pt, \Parser\Ast\Expr $arg): ?Node
    {
        if ($pt === null || $pt->kind !== Type::KIND_CLOSURE) { return null; }
        if ($arg->kind === 'StringLiteral') {
            $name = $this->strLitValue($arg);
            $cc = \strpos($name, '::');
            if ($cc !== false && $cc > 0) {
                $cls = \ltrim(\substr($name, 0, $cc), '\\');
                return $this->synthStaticClosure($cls, \substr($name, $cc + 2), $cls);
            }
            return $this->lowerFcc($name);
        }
        if ($arg->kind === 'ArrayLit') {
            $els = $this->arrayLitElements($arg);
            if (\count($els) !== 2) { return null; }
            $recvE = $this->elemValue($els[0]);
            $methE = $this->elemValue($els[1]);
            if ($methE->kind !== 'StringLiteral') { return null; }
            $m = $this->strLitValue($methE);
            if ($recvE->kind === 'StringLiteral') {
                $cls = \ltrim($this->strLitValue($recvE), '\\');
                return $this->synthStaticClosure($cls, $m, $cls);
            }
            return $this->synthMethodClosure($this->lowerExpr($recvE), $m);
        }
        return null;
    }

    private function lowerInvoke(\Parser\Ast\Invoke $expr): Node
    {
        // Literal string / array callable invoked directly: `"fn"(x)`,
        // `"C::m"(x)`, `[$o,"m"](x)`, `["C","m"](x)` → the matching call.
        $calleeAst = $expr->callee;
        $ck = $calleeAst->kind;
        if ($ck === 'StringLiteral') {
            return $this->lowerStringCallable($this->strLitValue($calleeAst), $expr->args);
        }
        if ($ck === 'ArrayLit') {
            $node = $this->lowerArrayCallable($calleeAst, $expr->args);
            if ($node !== null) { return $node; }
        }
        // A local tracked as holding a callable literal (straight-line).
        if ($ck === 'Variable') {
            $vn = $this->varName($calleeAst);
            $info = $this->constCallables[$vn] ?? null;
            if ($info !== null) { return $this->lowerConstCallable($vn, $info, $expr->args); }
        }
        $callee = $this->lowerExpr($calleeAst);
        $args = [];
        foreach ($expr->args as $a) { $args[] = $this->lowerExpr($a); }
        return new Invoke_($callee, $args, Type::unknown());
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

    /**
     * Free `Variable` names referenced in an expression (best-effort
     * recursive walk over the common shapes). Returns a flat list;
     * caller de-dups.
     *
     * @return string[]
     */
    private function collectVars(\Parser\Ast\Expr $e): array
    {
        $k = $e->kind;
        if ($k === 'Variable') { return [$e->name]; }
        if ($k === 'BinaryOp') { return \array_merge($this->collectVars($e->left), $this->collectVars($e->right)); }
        if ($k === 'UnaryOp') { return $this->collectVars($e->operand); }
        if ($k === 'Ternary') {
            $out = $this->collectVars($e->condition);
            if ($e->then !== null) { $out = \array_merge($out, $this->collectVars($e->then)); }
            return \array_merge($out, $this->collectVars($e->else));
        }
        if ($k === 'ArrayAccess') {
            $out = $this->collectVars($e->array);
            if ($e->index !== null) { $out = \array_merge($out, $this->collectVars($e->index)); }
            return $out;
        }
        if ($k === 'PropertyAccess') { return $this->collectVars($e->object); }
        if ($k === 'Cast') { return $this->collectVars($e->operand); }
        if ($k === 'Call') {
            $out = [];
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        if ($k === 'MethodCall') {
            $out = $this->collectVars($e->object);
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        if ($k === 'Invoke') {
            $out = $this->collectVars($e->callee);
            foreach ($e->args as $a) { $out = \array_merge($out, $this->collectVars($a)); }
            return $out;
        }
        return [];
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

    /**
     * PHP predefined constants → a literal node, or null if `$name` is not a
     * known predefined. Covers the broadly-used core / math / flag families
     * (php.net/reserved.constants, math.constants, string.constants); values
     * are baked at compile time. INF/NAN ride a FloatConst (EmitLlvm emits the
     * exact bit pattern). User constants (define()) are handled separately.
     */
    private function predefinedConstant(string $name): ?Node
    {
        // PHP_INT_MAX/MIN are written out (too wide for some literal paths).
        if ($name === 'PHP_INT_MAX') { return new IntConst(9223372036854775807, Type::int_()); }
        if ($name === 'PHP_INT_MIN') { return new IntConst(-9223372036854775807 - 1, Type::int_()); }
        if ($name === 'INF') { return new FloatConst(\INF, Type::float_()); }
        if ($name === 'NAN') { return new FloatConst(\NAN, Type::float_()); }
        // Standard CLI stream resources (libc FILE*). A codegen builtin loads
        // the platform global so fwrite(STDOUT, ...) shares echo's buffer.
        if ($name === 'STDIN')  { return new Call('__mir_stdin',  [], Type::obj('Ffi\\Ptr')); }
        if ($name === 'STDOUT') { return new Call('__mir_stdout', [], Type::obj('Ffi\\Ptr')); }
        if ($name === 'STDERR') { return new Call('__mir_stderr', [], Type::obj('Ffi\\Ptr')); }

        $ints = [
            // string padding
            'STR_PAD_RIGHT' => 1, 'STR_PAD_LEFT' => 0, 'STR_PAD_BOTH' => 2,
            // sort flags
            'SORT_REGULAR' => 0, 'SORT_NUMERIC' => 1, 'SORT_STRING' => 2,
            'SORT_DESC' => 3, 'SORT_ASC' => 4, 'SORT_LOCALE_STRING' => 5,
            'SORT_NATURAL' => 6, 'SORT_FLAG_CASE' => 8,
            // count / array_filter
            'COUNT_NORMAL' => 0, 'COUNT_RECURSIVE' => 1,
            'ARRAY_FILTER_USE_KEY' => 2, 'ARRAY_FILTER_USE_BOTH' => 1,
            // round modes
            'PHP_ROUND_HALF_UP' => 1, 'PHP_ROUND_HALF_DOWN' => 2,
            'PHP_ROUND_HALF_EVEN' => 3, 'PHP_ROUND_HALF_ODD' => 4,
            // error reporting levels
            'E_ERROR' => 1, 'E_WARNING' => 2, 'E_PARSE' => 4, 'E_NOTICE' => 8,
            'E_CORE_ERROR' => 16, 'E_CORE_WARNING' => 32, 'E_COMPILE_ERROR' => 64,
            'E_COMPILE_WARNING' => 128, 'E_USER_ERROR' => 256, 'E_USER_WARNING' => 512,
            'E_USER_NOTICE' => 1024, 'E_STRICT' => 2048, 'E_RECOVERABLE_ERROR' => 4096,
            'E_DEPRECATED' => 8192, 'E_USER_DEPRECATED' => 16384, 'E_ALL' => 30719,
            // php core ints
            'PHP_INT_SIZE' => 8, 'PHP_VERSION_ID' => 80503, 'PHP_MAJOR_VERSION' => 8,
            'PHP_MINOR_VERSION' => 5, 'PHP_RELEASE_VERSION' => 3, 'PHP_FLOAT_DIG' => 15,
            'PHP_ZTS' => 0, 'PHP_DEBUG' => 0, 'PHP_MAXPATHLEN' => 1024,
            // json flags
            'JSON_HEX_TAG' => 1, 'JSON_HEX_AMP' => 2, 'JSON_HEX_APOS' => 4,
            'JSON_HEX_QUOT' => 8, 'JSON_FORCE_OBJECT' => 16, 'JSON_NUMERIC_CHECK' => 32,
            'JSON_UNESCAPED_SLASHES' => 64, 'JSON_PRETTY_PRINT' => 128,
            'JSON_UNESCAPED_UNICODE' => 256, 'JSON_PARTIAL_OUTPUT_ON_ERROR' => 512,
            'JSON_PRESERVE_ZERO_FRACTION' => 1024, 'JSON_INVALID_UTF8_IGNORE' => 1048576,
            'JSON_INVALID_UTF8_SUBSTITUTE' => 2097152, 'JSON_THROW_ON_ERROR' => 4194304,
            'JSON_OBJECT_AS_ARRAY' => 1, 'JSON_BIGINT_AS_STRING' => 2, 'JSON_ERROR_NONE' => 0,
            // preg flags
            'PREG_PATTERN_ORDER' => 1, 'PREG_SET_ORDER' => 2, 'PREG_OFFSET_CAPTURE' => 256,
            'PREG_UNMATCHED_AS_NULL' => 512, 'PREG_SPLIT_NO_EMPTY' => 1,
            'PREG_SPLIT_DELIM_CAPTURE' => 2, 'PREG_SPLIT_OFFSET_CAPTURE' => 4,
            // htmlspecialchars / entities (common subset)
            'ENT_NOQUOTES' => 0, 'ENT_COMPAT' => 2, 'ENT_QUOTES' => 3, 'ENT_HTML5' => 48,
            // filesystem: fseek whence + file_put_contents / flock flags
            'SEEK_SET' => 0, 'SEEK_CUR' => 1, 'SEEK_END' => 2,
            'FILE_USE_INCLUDE_PATH' => 1, 'FILE_APPEND' => 8,
            'FILE_IGNORE_NEW_LINES' => 2, 'FILE_SKIP_EMPTY_LINES' => 4, 'FILE_NO_DEFAULT_CONTEXT' => 16,
            'LOCK_SH' => 1, 'LOCK_EX' => 2, 'LOCK_UN' => 3,
        ];
        if (isset($ints[$name])) { return new IntConst($ints[$name], Type::int_()); }

        $floats = [
            'M_PI' => 3.14159265358979323846, 'M_E' => 2.7182818284590452354,
            'M_SQRT2' => 1.41421356237309504880, 'M_SQRT1_2' => 0.70710678118654752440,
            'M_SQRT3' => 1.7320508075688772935, 'M_2_SQRTPI' => 1.12837916709551257390,
            'M_SQRTPI' => 1.77245385090551602729, 'M_1_PI' => 0.31830988618379067154,
            'M_2_PI' => 0.63661977236758134308, 'M_PI_2' => 1.57079632679489661923,
            'M_PI_4' => 0.78539816339744830962, 'M_LN2' => 0.69314718055994530942,
            'M_LN10' => 2.30258509299404568402, 'M_LOG2E' => 1.4426950408889634074,
            'M_LOG10E' => 0.43429448190325182765, 'M_EULER' => 0.57721566490153286061,
            'PHP_FLOAT_EPSILON' => 2.2204460492503131E-16,
            'PHP_FLOAT_MAX' => 1.7976931348623157E+308,
            'PHP_FLOAT_MIN' => 2.2250738585072014E-308,
        ];
        if (isset($floats[$name])) { return new FloatConst($floats[$name], Type::float_()); }

        $strs = [
            'PHP_EOL' => "\n", 'DIRECTORY_SEPARATOR' => '/', 'PATH_SEPARATOR' => ':',
            'PHP_VERSION' => '8.5.3', 'PHP_SAPI' => 'cli', 'PHP_EXTRA_VERSION' => '',
        ];
        if (isset($strs[$name])) { return new StringConst($strs[$name], Type::string_()); }

        // Host-target OS, detected at compile time via libc uname(2) — the
        // sysname ("Darwin" / "Linux") is both PHP_OS and PHP_OS_FAMILY for the
        // two supported targets, matching the interpreter on the build host.
        if ($name === 'PHP_OS' || $name === 'PHP_OS_FAMILY') {
            $os = \Manticore\host_os();
            $os = \substr($os, 0, 6) === 'Darwin' ? 'Darwin'
                : (\substr($os, 0, 5) === 'Linux' ? 'Linux' : $os);
            return new StringConst($os, Type::string_());
        }

        return null;
    }

    /**
     * Resolve `Class::CONST` to its initializer expression, walking the
     * class's own consts, its trait uses, then its parent chain. Null if
     * unknown.
     */
    private function findClassConst(string $className, string $name): ?\Parser\Ast\Expr
    {
        // Class or trait — a trait can declare consts the using class
        // inherits (`self::CONST` inside a mixed-in trait method).
        $decl = $this->classDecls[$className] ?? ($this->traitTable[$className] ?? null);
        if ($decl !== null) {
            foreach ($decl->consts as $const) {
                if ($const->name === $name) { return $const->value; }
            }
            foreach ($decl->uses as $traitName) {
                $tn = \ltrim($traitName, '\\');
                $found = $this->findClassConst($tn, $name);
                if ($found !== null) { return $found; }
            }
            if ($decl->extends !== []) {
                $found = $this->findClassConst(\ltrim($decl->extends[0], '\\'), $name);
                if ($found !== null) { return $found; }
            }
            // Implemented interfaces (and extended interfaces, via `implements`
            // populated by the parser for interface `extends`) carry consts too.
            foreach ($decl->implements as $ifaceName) {
                $found = $this->findClassConst(\ltrim($ifaceName, '\\'), $name);
                if ($found !== null) { return $found; }
            }
        }
        return null;
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

    /**
     * `global $a, $b;` → bind each name to the shared module cell
     * `@g_<name>`. Modeled as an init-less {@see StaticLocalDecl_} so
     * EmitLlvm's `globalBackedLocals` routing handles reads/writes; the
     * name is also recorded on the module so `__main` shares the cell.
     */
    private function lowerGlobal(\Parser\Ast\GlobalStmt $stmt): Node
    {
        $nodes = [];
        foreach ($stmt->names as $name) {
            $cell = '@g_' . $name;
            $this->module->addGlobalCell($cell, new IntConst(0, Type::int_()));
            $this->module->addGlobalVarName($name);
            $nodes[] = new StaticLocalDecl_($name, $cell, '', null, Type::int_());
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

    private function lowerExpr(\Parser\Ast\Expr $expr): Node
    {
        $node = $this->lowerExprInner($expr);
        // Stamp the source line centrally (0 = not yet set) so a later
        // diagnostic can point at this expression. Nested lowerExpr calls
        // already stamped their own sub-nodes; only fill an unset one.
        if ($node->line === 0) { $node->line = $expr->span->line; }
        return $node;
    }

    private function lowerExprInner(\Parser\Ast\Expr $expr): Node
    {
        if ($expr->kind === 'IntLiteral') {
            return new IntConst($this->asIntLiteral($expr)->value, Type::int_());
        }
        if ($expr->kind === 'FloatLiteral') {
            // Pin to the FloatLiteral subclass so `->value` is FLOAT-typed: a
            // base-`Expr` read of `value` borrows a subclass type and resolves to
            // INT (IntLiteral's `value`). The double's bits then ride an i64
            // carrier TYPED int — harmless on its own (a bitcast round-trips it),
            // but a float-param ctor coercion (`new FloatConst(float)`) would
            // sitofp those bits to garbage. Type-pinned read keeps it float.
            return new FloatConst($this->asFloatLiteral($expr)->value, Type::float_());
        }
        if ($expr->kind === 'StringLiteral') {
            return new StringConst($expr->value, Type::string_());
        }
        if ($expr->kind === 'BoolLiteral') {
            return new BoolConst($expr->value, Type::bool_());
        }
        if ($expr->kind === 'NullLiteral') {
            return new NullConst(Type::null_());
        }
        if ($expr->kind === 'Variable') {
            return new LoadLocal($expr->name, Type::unknown());
        }
        if ($expr->kind === 'Assign') { return $this->lowerAssign($expr); }
        if ($expr->kind === 'RefAssign') { return $this->lowerRefAssign($expr); }
        if ($expr->kind === 'CompoundAssign') { return $this->lowerCompoundAssign($expr); }
        if ($expr->kind === 'IncDec') { return $this->lowerIncDec($expr); }
        if ($expr->kind === 'Ternary') { return $this->lowerTernary($expr); }
        if ($expr->kind === 'Cast') { return $this->lowerCast($expr); }
        if ($expr->kind === 'NullCoalesce') { return $this->lowerNullCoalesce($expr); }
        if ($expr->kind === 'Instanceof') { return $this->lowerInstanceof($expr); }
        if ($expr->kind === 'Match') { return $this->lowerMatch($expr); }
        if ($expr->kind === 'MagicConstant') {
            $mn = $expr->name;
            if ($mn === '__LINE__') { return new IntConst($expr->span->line, Type::int_()); }
            if ($mn === '__CLASS__') { return new StringConst($this->currentLowerClass, Type::string_()); }
            if ($mn === '__FUNCTION__') { return new StringConst($this->currentLowerFn, Type::string_()); }
            if ($mn === '__METHOD__') {
                $m = $this->currentLowerClass !== ''
                    ? $this->currentLowerClass . '::' . $this->currentLowerFn
                    : $this->currentLowerFn;
                return new StringConst($m, Type::string_());
            }
            return new StringConst('', Type::string_());
        }
        if ($expr->kind === 'Closure') { return $this->lowerClosure($expr); }
        if ($expr->kind === 'ArrowFn') { return $this->lowerArrowFn($expr); }
        if ($expr->kind === 'Invoke') { return $this->lowerInvoke($expr); }
        if ($expr->kind === 'Clone')  { return $this->lowerClone($expr); }
        if ($expr->kind === 'StaticAccess') {
            // `$expr` is base-Expr-typed; StaticAccess's `class` / `name`
            // collide with other subclasses' same-named fields at different
            // offsets, so read them through a typed param (T5 pattern) — else
            // a garbage class/name misses the enum table and falls through to
            // the "unsupported expression" throw (uncaught → longjmp crash).
            $saClass = $this->staticAccessClass($expr);
            $saName = $this->staticAccessName($expr);
            // `Class::class` / `self::class` / `parent::class` → the fully
            // qualified name as a compile-time string. `static::class` under
            // inheritance needs the runtime called-class (handled in lowerStatic
            // ClassName below); here it folds to the lexical class, which is
            // correct when the method isn't reached through a subclass.
            if (\strtolower($saName) === 'class') {
                return new StringConst($this->resolveStaticClass($saClass), Type::string_());
            }
            // EnumName::Case → ordinal int carrying the enum type. A non-case
            // name (ordinal -1) is an enum CONSTANT — fall through to the
            // const lookup below.
            $ecls = \ltrim($saClass, '\\');
            if (isset($this->enumTable[$ecls])) {
                $ord = $this->enumTable[$ecls]->ordinalOf($saName);
                if ($ord >= 0) { return new IntConst($ord, Type::obj($ecls)); }
            }
            // Class::$prop → load the static-property global.
            $sp = $this->staticPropRef($saClass, $saName);
            if ($sp !== null) { return $sp; }
            // Class::CONST → inline the constant's initializer. Lower it
            // with the owning class as `self` so a `self::OTHER` inside
            // the initializer (e.g. `COLOR_CLEAR_MASK = ~self::COLOR_MASK`)
            // resolves against the declaring class, not the caller's.
            $cname = $this->resolveStaticClass($saClass);
            $cv = $this->findClassConst($cname, $saName);
            if ($cv !== null) {
                $prevC = $this->currentLowerClass;
                $this->currentLowerClass = $cname;
                $lowered = $this->lowerExpr($cv);
                $this->currentLowerClass = $prevC;
                return $lowered;
            }
        }
        if ($expr->kind === 'DynamicStaticAccess') {
            // `$obj::class` → the operand's class name as a string. Read the
            // subclass `name` / `receiver` through a typed param (T5 offset).
            if ($this->dynStaticName($expr) === 'class') {
                return new ClassName_($this->lowerExpr($this->dynStaticReceiver($expr)), Type::string_());
            }
        }
        if ($expr->kind === 'BinaryOp') {
            return $this->lowerBinary($expr);
        }
        if ($expr->kind === 'UnaryOp') {
            return $this->lowerUnary($expr);
        }
        if ($expr->kind === 'Call') {
            $fn = \strtolower($expr->function);
            // `call_user_func($cb, ...$rest)` → invoke $cb with the rest args,
            // reusing the Invoke path (literal / FCC / const-callable dispatch).
            if ($fn === 'call_user_func' && \count($expr->args) >= 1) {
                $rest = [];
                $ci = 1;
                while ($ci < \count($expr->args)) { $rest[] = $expr->args[$ci]; $ci = $ci + 1; }
                return $this->lowerInvoke(new \Parser\Ast\Invoke($expr->args[0], $rest, $expr->span));
            }
            // `call_user_func_array($cb, [$a, $b])` — spread a LITERAL arg array
            // as positional args (a runtime array needs argument unpacking; not
            // yet supported and left to the normal stdlib path).
            if ($fn === 'call_user_func_array' && \count($expr->args) === 2
                && $expr->args[1]->kind === 'ArrayLit') {
                $spread = [];
                foreach ($this->arrayLitElements($expr->args[1]) as $el) {
                    $spread[] = $this->elemValue($el);
                }
                return $this->lowerInvoke(new \Parser\Ast\Invoke($expr->args[0], $spread, $expr->span));
            }
            if ($fn === 'isset') {
                $ts = [];
                foreach ($expr->args as $a) { $ts[] = $this->lowerExpr($a); }
                return new Isset_($ts, Type::bool_());
            }
            if ($fn === 'unset') {
                $ts = [];
                foreach ($expr->args as $a) { $ts[] = $this->lowerExpr($a); }
                return new Unset_($ts, Type::void());
            }
            // `empty($x)` → falsiness test (carrier == 0). Matches the
            // self-host usage (bool / null / `?? false` flags); the
            // string-"0"/"" subtlety is not exercised by the compiler.
            if ($fn === 'empty' && \count($expr->args) === 1) {
                return new Not_($this->lowerExpr($expr->args[0]));
            }
            // `compact('a', 'b', ...)` with STRING-LITERAL names → an assoc array
            // built from the named locals (`['a' => $a, 'b' => $b]`). PHP resolves
            // the names from the runtime symbol table; AOT has no runtime name→slot
            // map, so only the literal-name form is supported (dynamic / nested-
            // array names fall through to the stdlib). An undefined var is not
            // skipped (yields its null slot) — the common "compact vars you just
            // set" usage matches PHP.
            if ($fn === 'compact' && \count($expr->args) >= 1) {
                $names = [];
                $litOnly = true;
                foreach ($expr->args as $a) {
                    if ($a->kind !== 'StringLiteral') { $litOnly = false; break; }
                    $names[] = $this->stringLitValue($a);
                }
                if ($litOnly) {
                    $elems = [];
                    foreach ($names as $nm) {
                        $key = new StringConst($nm, Type::string_());
                        $val = $this->lowerExpr(\Parser\Ast\Expr::variable($nm, $expr->span));
                        $elems[] = new ArrayElement_($key, $val);
                    }
                    return new ArrayLit($elems, Type::unknown());
                }
            }
            // `define("NAME", v)` — registered in the run() pre-pass; the call
            // itself is a no-op yielding true (define's bool return).
            if ($fn === 'define') {
                return new BoolConst(true, Type::bool_());
            }
            // `defined("NAME")` → compile-time bool against predefined +
            // user constants. A non-literal name conservatively folds false.
            if ($fn === 'defined' && \count($expr->args) === 1) {
                $a0 = $expr->args[0];
                $known = false;
                if ($a0->kind === 'StringLiteral') {
                    $nm = $this->constBareName($this->stringLitValue($a0));
                    $known = $this->predefinedConstant($nm) !== null
                        || isset($this->userConstants[$nm]);
                }
                return new BoolConst($known, Type::bool_());
            }
            // `constant("NAME")` → the resolved constant value. An unknown /
            // non-literal name folds to null (PHP throws; null degrades safely).
            if ($fn === 'constant' && \count($expr->args) === 1) {
                $a0 = $expr->args[0];
                if ($a0->kind === 'StringLiteral') {
                    $nm = $this->constBareName($this->stringLitValue($a0));
                    $pre = $this->predefinedConstant($nm);
                    if ($pre !== null) { return $pre; }
                    if (isset($this->userConstants[$nm])) {
                        return $this->lowerExpr($this->userConstants[$nm]);
                    }
                }
                return new NullConst(Type::null_());
            }
            // `function_exists("Name")` → compile-time 1/0 against the
            // declared functions (incl. FFI externs / use-function
            // aliases). A non-literal arg conservatively folds to false.
            if ($fn === 'function_exists' && \count($expr->args) === 1) {
                $a0 = $expr->args[0];
                $known = 0;
                if ($a0->kind === 'StringLiteral') {
                    $nm = \ltrim($this->stringLitValue($a0), '\\');
                    $pos = \strrpos($nm, '\\');
                    $bare = $pos === false ? $nm : \substr($nm, $pos + 1);
                    if (isset($this->fnDecls[$nm])
                        || isset($this->fnDecls[$bare])
                        || (($this->fnAliasByBare[$bare] ?? '') !== '')) {
                        $known = 1;
                    }
                }
                return new IntConst($known, Type::bool_());
            }
            // `var_dump($a, $b, …)` stays a `var_dump` call — EmitLlvm's biVarDump
            // dumps each arg by its static type (a typed FLOAT goes straight to a
            // shortest-round-trip format instead of through the lossy cell box;
            // everything else recurses through `__mir_var_dump`).
            if ($fn === 'var_dump' && \count($expr->args) >= 1) {
                $vdArgs = [];
                foreach ($expr->args as $a) { $vdArgs[] = $this->lowerExpr($a); }
                return new Call('var_dump', $vdArgs, Type::void());
            }
            // First-class callable: `foo(...)` → a closure wrapping foo.
            if (\count($expr->args) === 1 && $expr->args[0]->kind === 'Ellipsis') {
                return $this->lowerFcc($expr->function);
            }
            $callee = $this->resolveCallName($expr->function);
            $args = $this->lowerCallArgs($callee, $expr->args);
            return new Call($callee, $args, Type::unknown());
        }
        if ($expr->kind === 'Spread')         { return new Spread_($this->lowerExpr($expr->value), Type::unknown()); }
        if ($expr->kind === 'ArrayLit')       { return $this->lowerArrayLit($expr); }
        if ($expr->kind === 'ArrayAccess')    { return $this->lowerArrayAccess($expr); }
        if ($expr->kind === 'New')            { return $this->lowerNewExpr($expr); }
        if ($expr->kind === 'PropertyAccess') {
            // Pin to PropertyAccess before reading `nullsafe`: on the base `Expr`
            // the field offset is the load-bearing subclass's (poly-prop trap) —
            // PropertyAccess holds `nullsafe` at a different slot than MethodCall,
            // so a base read returns garbage and routes EVERY `->prop` through the
            // nullsafe desugar.
            $pa = $this->asPropAccess($expr);
            return $pa->nullsafe ? $this->lowerNullsafeProp($pa) : $this->lowerPropertyAccess($pa);
        }
        if ($expr->kind === 'DynProp') {
            return new DynProp_($this->lowerExpr($this->dynPropObject($expr)), $this->lowerExpr($this->dynPropName($expr)), Type::cell());
        }
        if ($expr->kind === 'MethodCall')     { return $this->lowerMethodCall($expr); }
        if ($expr->kind === 'StaticCall')     { return $this->lowerStaticCall($expr); }
        // A `name: value` arg that reached here wasn't reordered by
        // lowerCallArgs (i.e. a `new` / method / static call arg).
        // Unwrap positionally for now — full reordering against the
        // callee's params on those paths is a TODO.
        if ($expr->kind === 'NamedArg')       { return $this->lowerExpr($this->namedArgValue($expr)); }
        if ($expr->kind === 'Identifier')     { return $this->lowerIdentifier($expr->name); }
        if ($expr->kind === 'Yield') {
            // Read subclass fields through a YieldExpr-typed param (the kind
            // check above proves the shape) — a base-`Expr` read picks the
            // wrong offset under self-host (T5), faulting on `key`/`value`.
            $yk = $this->yieldKey($expr);
            $yv = $this->yieldValue($expr);
            $this->sawYield = true;
            if ($this->yieldFrom($expr)) {
                // `yield from $src` desugars to `foreach ($src as $k => $v) {
                // yield $k => $v; }` — reuses the foreach+yield machinery
                // (which frame-backs its iterator state across the inner
                // yield) and works uniformly for arrays and sub-generators.
                $src = $yv !== null ? $this->lowerExpr($yv) : new LoadLocal('this', Type::unknown());
                $n = $this->yieldFromCounter;
                $this->yieldFromCounter = $n + 1;
                $kv = '__yf_k' . (string)$n;
                $vv = '__yf_v' . (string)$n;
                $inner = new Yield_(
                    new LoadLocal($kv, Type::unknown()),
                    new LoadLocal($vv, Type::unknown()),
                    false,
                    Type::cell(),
                );
                return new Foreach_($src, $kv, $vv, false, new Block([$inner], Type::void()));
            }
            $key = $yk !== null ? $this->lowerExpr($yk) : null;
            $value = $yv !== null ? $this->lowerExpr($yv) : null;
            return new Yield_($key, $value, false, Type::cell());
        }
        $extra = '';
        if ($expr->kind === 'StaticAccess') { $extra = ' (' . $this->staticAccessClass($expr) . '::' . $this->staticAccessName($expr) . ')'; }
        if ($expr->kind === 'Identifier') { $extra = ' (' . ($expr->name ?? '?') . ')'; }
        throw new \RuntimeException(
            'MIR.lower: unsupported expression kind ' . $expr->kind . $extra
        );
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

    /** Build the store node for an assignment target + already-lowered value. */
    private function storeToTarget(\Parser\Ast\Expr $target, Node $value): Node
    {
        if ($target->kind === 'Variable') {
            return new StoreLocal($target->name, $value, $value->type);
        }
        if ($target->kind === 'ArrayAccess') {
            $arr = $this->lowerExpr($target->array);
            $idx = $target->index === null
                ? new NullConst(Type::null_())
                : $this->lowerExpr($target->index);
            return new StoreElement($arr, $idx, $value, $value->type);
        }
        if ($target->kind === 'PropertyAccess') {
            $obj = $this->lowerExpr($target->object);
            return new StoreProperty($obj, $target->property, $value, $value->type);
        }
        if ($target->kind === 'DynProp') {
            return new StoreDynProp_(
                $this->lowerExpr($this->dynPropObject($target)),
                $this->lowerExpr($this->dynPropName($target)),
                $value,
                $value->type,
            );
        }
        if ($target->kind === 'StaticAccess') {
            $ref = $this->staticPropRef($this->staticAccessClass($target), $this->staticAccessName($target));
            if ($ref !== null) {
                return new StoreStaticProp_($ref->global, $value, $value->type);
            }
        }
        if ($target->kind === 'ArrayLit') {
            return $this->lowerDestructure($target, $value);
        }
        throw new \RuntimeException(
            'MIR.lower: unsupported assign target kind ' . $target->kind
        );
    }

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

    /**
     * Lower AST call args against a known parameter signature, filling
     * omitted trailing params with their default expression (or null),
     * reordering named args, and packing a trailing variadic into a vec.
     * Critical for `new`/method/static calls: the callee reads one slot
     * per param, so an omitted obj-typed default left uninitialized makes
     * the callee retain stack garbage.
     * @param \Parser\Ast\Param[] $params
     * @param \Parser\Ast\Expr[]  $astArgs
     * @return Node[]
     */
    private function defaultFillArgs(array $params, array $astArgs): array
    {
        $hasNamed = false;
        foreach ($astArgs as $a) {
            if ($a->kind === 'NamedArg') { $hasNamed = true; break; }
        }
        // Variadic last param: pack trailing positional args into a vec.
        $np = \count($params);
        if ($np > 0 && $this->paramVariadic($params[$np - 1])) {
            $vidx = $np - 1;
            $out = [];
            $packed = [];
            $i = 0;
            foreach ($astArgs as $a) {
                if ($i < $vidx) { $out[] = $this->lowerExpr($a); }
                else { $packed[] = new ArrayElement_(null, $this->lowerExpr($a)); }
                $i = $i + 1;
            }
            $out[] = new ArrayLit($packed, Type::unknown());
            return $out;
        }
        // Resolve against the signature only when something is missing /
        // reordered; otherwise lower positionally.
        if (!$hasNamed && \count($astArgs) >= \count($params)) {
            $out = [];
            foreach ($astArgs as $a) { $out[] = $this->lowerExpr($a); }
            return $out;
        }
        // Dense parallel slots (sparse int-key isset is unreliable in
        // self-host, so pre-fill both lists to param count first).
        $slotNode = [];
        $slotSet = [];
        foreach ($params as $p) {
            $slotNode[] = new NullConst(Type::null_());
            $slotSet[] = false;
        }
        $pos = 0;
        foreach ($astArgs as $a) {
            if ($a->kind === 'NamedArg') {
                // `$a` is a base-Expr-typed loop var; NamedArg's `name` /
                // `value` sit at subclass offsets, so read them through a
                // typed param (self-host offset, T5 pattern).
                $an = $this->namedArgName($a);
                $av = $this->namedArgValue($a);
                $idx = 0;
                foreach ($params as $p) {
                    if ($this->paramName($p) === $an) {
                        $slotNode[$idx] = $this->lowerExpr($av);
                        $slotSet[$idx] = true;
                        break;
                    }
                    $idx = $idx + 1;
                }
                continue;
            }
            $slotNode[$pos] = $this->lowerExpr($a);
            $slotSet[$pos] = true;
            $pos = $pos + 1;
        }
        $out = [];
        $i = 0;
        foreach ($params as $p) {
            $pd = $this->paramDefault($p);
            if ($slotSet[$i]) {
                $out[] = $slotNode[$i];
            } elseif ($pd !== null) {
                $out[] = $this->lowerExpr($pd);
            } else {
                $out[] = new NullConst(Type::null_());
            }
            $i = $i + 1;
        }
        return $out;
    }

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

    private function lowerNewExpr(\Parser\Ast\NewExpr $expr): NewObj
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

    private function buildBinop(string $op, Node $left, Node $right): Node
    {
        if ($op === '==' || $op === '!=' || $op === '===' || $op === '!=='
            || $op === '<' || $op === '<=' || $op === '>' || $op === '>=') {
            return new Cmp($left, $right, $op);
        }
        if ($op === '.') {
            return new Concat($left, $right);
        }
        // Spaceship: `$a <=> $b` → `($a > $b) - ($a < $b)` ∈ {-1,0,1}.
        if ($op === '<=>') {
            return new Sub(new Cmp($left, $right, '>'), new Cmp($left, $right, '<'), Type::int_());
        }
        $type = ($left->type->kind === Type::KIND_FLOAT
            || $right->type->kind === Type::KIND_FLOAT)
            ? Type::float_()
            : Type::int_();
        if ($op === '+') { return new Add($left, $right, $type); }
        if ($op === '-') { return new Sub($left, $right, $type); }
        if ($op === '*') { return new Mul($left, $right, $type); }
        // `$a ** $b` → pow() builtin (int^int via __mir_ipow, else
        // llvm.pow.f64). InferTypes::builtinReturnType re-types it.
        if ($op === '**') { return new Call('pow', [$left, $right], $type); }
        if ($op === '/') { return new Div($left, $right, Type::float_()); }
        if ($op === '%') { return new Mod($left, $right, Type::int_()); }
        // Integer bitwise ops (incl. their compound forms `<<=` etc.,
        // which route here after stripping the trailing `=`).
        if ($op === '<<') { return new BitOp('shl', $left, $right, Type::int_()); }
        if ($op === '>>') { return new BitOp('shr', $left, $right, Type::int_()); }
        if ($op === '&')  { return new BitOp('and', $left, $right, Type::int_()); }
        if ($op === '|')  { return new BitOp('or',  $left, $right, Type::int_()); }
        if ($op === '^')  { return new BitOp('xor', $left, $right, Type::int_()); }
        throw new \RuntimeException(
            'MIR.lower: unsupported binary op ' . $op
        );
    }

    /**
     * Param type from its effective hint. A FULLY untyped param (no hint, no
     * usable docblock → `$eff === null`) is `mixed` in PHP: a NaN-boxed cell so
     * `is_*` / dynamic dispatch / array access / casts / boolean tests on it
     * work at runtime (an `unknown` would flow as a raw i64 and mis-read the
     * tag). A bare `array` (or any present) hint is NOT this — it lowers via
     * {@see lowerTypeHint} (bare `array` → unknown, element recovered from use).
     */
    private function lowerParamType(?string $eff): Type
    {
        if ($eff === null) { return Type::cell(); }
        return $this->lowerTypeHint($eff);
    }

    /** True if `$hint` is a union (`a|b|…`) whose every arm is an ARRAY shape
     *  (`X[]` or `array<…>`) — e.g. `int[]|float[]`, `string[]|int[]`. */
    private function isArrayUnion(string $hint): bool
    {
        $parts = \explode('|', \str_replace(['(', ')'], '', $hint));
        if (\count($parts) < 2) { return false; }
        foreach ($parts as $p) {
            if (!$this->looksLikeArrayElemType(\trim($p))) { return false; }
        }
        return true;
    }

    /** True if `$hint` is a union (`a|b|…`) whose every arm is `int` or `float`
     *  (`int|float`, `float|int`) — a purely-numeric scalar union. */
    private function isNumericUnion(string $hint): bool
    {
        $parts = \explode('|', \str_replace(['(', ')', '?', '\\'], '', $hint));
        if (\count($parts) < 2) { return false; }
        foreach ($parts as $p) {
            $low = \strtolower(\trim($p));
            if ($low !== 'int' && $low !== 'integer'
                && $low !== 'float' && $low !== 'double') {
                return false;
            }
        }
        return true;
    }

    private function lowerTypeHint(?string $hint): Type
    {
        if ($hint === null) { return Type::unknown(); }
        // `mixed` and union types (`int|string`, DNF `(A&B)|C`) become a tagged
        // cell (NaN-boxed i64): the value carries its runtime type tag. A purely
        // NUMERIC union (`int|float`) is a NUMERIC cell — arithmetic promotes by
        // tag AND a single-kind return/value narrows to the concrete scalar
        // (InferTypes), so e.g. array_sum's float specialization returns a raw
        // double instead of a mantissa-truncating box_float.
        if (\strpos($hint, '|') > 0) {
            // A union whose every arm is an ARRAY shape (`int[]|float[]`,
            // `string[]|int[]`) is still an ARRAY, not a scalar cell — lower it
            // to an erased `vec[unknown]` so call-site element inference /
            // monomorphization can refine the element per call (a plain cell
            // would erase the array-ness and box every element).
            if ($this->isArrayUnion($hint)) { return Type::vec(Type::unknown()); }
            // A purely-NUMERIC scalar union (`int|float`) is a numeric cell:
            // arithmetic promotes by tag and a single-kind return narrows to the
            // concrete scalar (so e.g. array_sum's float specialization returns a
            // raw double, not a mantissa-truncating box_float).
            if ($this->isNumericUnion($hint)) { return Type::numericCell(); }
            return Type::cell();
        }
        // Pure intersection `A&B` (DNF with no top-level union) — the value
        // implements ALL the named types; type it as the FIRST so method
        // dispatch + return-type resolution have a concrete class (the runtime
        // object satisfies the rest, resolved virtually by class_id). Strip any
        // grouping parens the parser preserved.
        $bare = \str_replace(['(', ')'], '', $hint);
        $amp = \strpos($bare, '&');
        if ($amp !== false && $amp > 0) {
            return $this->lowerTypeHint(\substr($bare, 0, $amp));
        }
        $low = strtolower(ltrim($hint, '?\\'));
        if ($low === 'mixed') { return Type::cell(); }
        // `iterable` = array|Traversable — a tagged cell (carries either at
        // runtime). foreach over an `iterable` array works via the cell path;
        // object iteration through an iterable binding needs runtime
        // array-vs-object dispatch (a follow-up).
        if ($low === 'iterable') { return Type::cell(); }
        // A nullable SCALAR (`?int`/`?float`/`?bool`) can't ride a raw i64: null
        // would collide with 0 / 0.0 / false (so `=== null` and var_dump fail).
        // Box it as a NUMERIC cell — null gets the NULL tag, the value its own,
        // arithmetic promotes by tag. The `numeric` flag distinguishes it from a
        // general mixed/array cell (whose property slot stays RAW for the SPL
        // cell-array machinery) so only scalar-nullable props box-null/box-store.
        // (Nullable POINTER types — `?string`/`?array`/`?Obj` — ride raw: a null
        // is ptr 0, distinct from any valid ptr, mapped to NULL by box_ptr/etc.)
        $nullable = \strlen($hint) > 0 && $hint[0] === '?';
        if ($low === 'int' || $low === 'integer') {
            return $nullable ? Type::numericCell() : Type::int_();
        }
        if ($low === 'float' || $low === 'double') {
            return $nullable ? Type::numericCell() : Type::float_();
        }
        if ($low === 'bool' || $low === 'boolean') {
            return $nullable ? Type::numericCell() : Type::bool_();
        }
        if ($low === 'string') { return Type::string_(); }
        if ($low === 'void')   { return Type::void(); }
        if ($low === 'null')   { return Type::null_(); }
        // `\Ffi\Ptr` is a built-in foreign handle (a libc FILE*/DIR*/raw addr):
        // an i64 pointer, excluded from rc, with a runtime null-compare. It must
        // resolve to obj<Ffi\Ptr> even in a module (the stdlib) that does NOT
        // register the Ffi\Ptr class — otherwise it erases to `unknown`, the
        // .sig drops the type, and a cross-module caller boxes the handle (ABI
        // mismatch → the FILE* is read from NaN-boxed bits → crash). PHP has no
        // writable `resource` type, so a `resource` hint is left to the normal
        // (unknown class) path, matching PHP.
        if ($low === 'ffi\\ptr') { return Type::obj('Ffi\\Ptr'); }
        // `\Closure` is a header-less closure struct ([fn_ptr, captures...]),
        // never rc-managed. Typing it KIND_CLOSURE (not obj) keeps every rc
        // path from mis-routing a retain/release through it — the startup
        // `Command::run(\Closure $h)` corruption. (A `\Closure(...): T` doc
        // shape contains '|'/'(' and is handled above as a cell/union.)
        if ($low === 'closure' || $low === 'callable') { return Type::closure(); }
        // `self` / `static` → the enclosing class; `parent` → its base.
        // Without this a `?self $next` property erases to unknown and a
        // `$this->next->method()` dispatch can't resolve the return flavor.
        if ($low === 'self' || $low === 'static') {
            if ($this->currentLowerClass !== '') {
                return Type::obj($this->currentLowerClass);
            }
        }
        if ($low === 'parent') {
            if (isset($this->classTable[$this->currentLowerClass])) {
                $pc = $this->classTable[$this->currentLowerClass]->parent;
                if ($pc !== '') { return Type::obj($pc); }
            }
        }
        // `T[]` suffix → vec[T]. A list shape; assoc-keyed bare-array
        // slots get re-typed by InferTypes::scanAssoc* from string-key use.
        if (\strlen($low) > 2 && \substr($low, \strlen($low) - 2) === '[]') {
            $base = \ltrim($hint, '?\\');
            $elem = \substr($base, 0, \strlen($base) - 2);
            return Type::vec($this->lowerTypeHint($elem));
        }
        // Generic array: `array<V>` → vec[V]; `array<K, V>` → assoc[V].
        if (\strncmp($low, 'array<', 6) === 0) {
            $base = \ltrim($hint, '?\\');
            $lt = \strpos($base, '<');
            $inner = \substr($base, $lt + 1, \strlen($base) - $lt - 2);
            // self-host strpos returns -1 (not false) on miss; guard both.
            $comma = \strpos($inner, ',');
            if ($comma === false || $comma < 0) {
                return Type::vec($this->lowerTypeHint($inner));
            }
            $keyStr = \trim(\substr($inner, 0, $comma));
            $valStr = \trim(\substr($inner, $comma + 1, \strlen($inner) - $comma - 1));
            return Type::assoc($this->lowerTypeHint($keyStr), $this->lowerTypeHint($valStr));
        }
        // `Generator` / `Generator<V>` / `Generator<K, V>` → a Generator whose
        // yielded value is V (the 2nd param when keyed, mirroring PHP's
        // `Generator<TKey, TValue>`). Lets a generator PARAMETER / return hint
        // carry the element type inference can't see across a call boundary.
        if ($low === 'generator') { return Type::generator(null); }
        if (\strncmp($low, 'generator<', 10) === 0) {
            $base = \ltrim($hint, '?\\');
            $lt = \strpos($base, '<');
            $inner = \substr($base, $lt + 1, \strlen($base) - $lt - 2);
            $comma = \strpos($inner, ',');
            if ($comma === false || $comma < 0) {
                return Type::generator($this->lowerTypeHint(\trim($inner)));
            }
            $keyStr = \trim(\substr($inner, 0, $comma));
            $valStr = \trim(\substr($inner, $comma + 1, \strlen($inner) - $comma - 1));
            return Type::generator($this->lowerTypeHint($valStr), $this->lowerTypeHint($keyStr));
        }
        $cls = \ltrim($hint, '?\\');
        // A bare class name → obj<Class> (so method returns / params of
        // a class type carry their class for dispatch + __toString).
        // `Ffi\Ptr` stays obj<Ffi\Ptr> but is treated as an opaque FOREIGN
        // pointer downstream: excluded from rc (InsertMemoryOps) and given
        // a RUNTIME null-compare (EmitLlvm) since fopen/opendir genuinely
        // return NULL at runtime.
        if (isset($this->classTable[$cls]) || isset($this->knownClassNames[$cls])) {
            return Type::obj($cls);
        }
        // Unqualified short name: PHP resolves it in the current namespace
        // first. Prefer the same-namespace class — this disambiguates a
        // short name shared across namespaces (`FunctionDef` in both
        // `Codegen\Llvm` and `Compile\Mir`) that the global heuristic
        // below would otherwise mark ambiguous and erase.
        if (\strpos($cls, '\\') === false && $this->currentDeclNamespace !== '') {
            // Walk the declaring namespace and its ANCESTORS: a pass in
            // `Compile\Mir\Passes` naming `Type` means `Compile\Mir\Type` (a
            // sibling of its own package), resolved by a file `use`. Doc-comment
            // generic inners (`@var array<string, Type>`) carry the raw short
            // name with no `use` context, and the global short→FQN map marks
            // `Type` ambiguous (also `Codegen\Llvm\Type`). The nearest enclosing
            // namespace that declares it is the PHP-correct pick and disambiguates
            // without per-file alias tracking (which the merged module drops).
            $ns = $this->currentDeclNamespace;
            while ($ns !== '') {
                $qualified = $ns . '\\' . $cls;
                if (isset($this->classTable[$qualified]) || isset($this->knownClassNames[$qualified])) {
                    return Type::obj($qualified);
                }
                $p = \strrpos($ns, '\\');
                if ($p === false || $p < 0) { break; }
                $ns = \substr($ns, 0, $p);
            }
        }
        // Unqualified short name of a namespaced class (`Stmt` →
        // `Parser\Ast\Stmt`) — resolve when exactly one class declares it.
        if (\strpos($cls, '\\') === false
            && isset($this->shortClassFqn[$cls])
            && !isset($this->shortClassAmbiguous[$cls])) {
            return Type::obj($this->shortClassFqn[$cls]);
        }
        return Type::unknown();
    }

    /** Whether `$hint` denotes a bare `array` with no element type. */
    private function isBareArrayHint(?string $hint): bool
    {
        if ($hint === null) { return false; }
        $low = \strtolower(\ltrim($hint, '?\\'));
        return $low === 'array';
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

    /**
     * Recover a bare-`array` property's element type from how the class's own
     * methods STORE into it — the usage-inference fallback when neither a `@var`
     * docblock nor a list-literal default carried the element. A property has no
     * call site to refine its element, so an unknown element leaves every read
     * raw (an object field lands on a wrong offset, a string echoes as its
     * pointer). CONSERVATIVE: only push stores `$this->$prop[] = <resolvable>`
     * count; a keyed store, a wholesale non-empty reassign, or an unresolvable
     * value bails to null (stay erased). Every counted store must agree on one
     * concrete element type. Keeps the concrete fast path (no cell boxing).
     */
    private function inferPropElemFromStores(\Parser\Ast\ClassDecl $decl, string $prop): ?Type
    {
        $found = null;
        foreach ($decl->methods as $m) {
            if ($m->body === null) { continue; }
            $paramTypes = [];
            foreach ($m->params as $p) {
                if ($p->typeHint !== null) {
                    $paramTypes[$p->name] = $this->lowerTypeHint($p->typeHint);
                }
            }
            $types = $this->scanStorePropTypes($m->body->statements, $prop, $paramTypes);
            foreach ($types as $t) {
                // An unknown entry marks an unresolvable / keyed / wholesale store.
                if ($t->kind === Type::KIND_UNKNOWN) { return null; }
                if ($found === null) { $found = $t; }
                elseif (!$this->sameElemType($found, $t)) { return null; }
            }
        }
        return $found;
    }

    /**
     * Element types stored to `$this->$prop` across a statement list (recurses
     * into if/loop/try/switch bodies so a conflicting store is never missed). A
     * `Type::unknown()` entry signals "bail" to the caller.
     *
     * @param \Parser\Ast\Stmt[]     $stmts
     * @param array<string, Type>    $paramTypes
     * @return Type[]
     */
    private function scanStorePropTypes(array $stmts, string $prop, array $paramTypes): array
    {
        $out = [];
        foreach ($stmts as $s) {
            $k = $s->kind;
            if ($k === 'Expression') {
                $t = $this->storeElemTypeOf($this->asExprStmt($s)->expr, $prop, $paramTypes);
                if ($t !== null) { $out[] = $t; }
            } elseif ($k === 'If') {
                $if = $this->asIfStmt($s);
                $out = \array_merge($out, $this->scanStorePropTypes($if->then->statements, $prop, $paramTypes));
                foreach ($if->elseifs as $ei) {
                    $out = \array_merge($out, $this->scanStorePropTypes($this->asElseIfArm($ei)->body->statements, $prop, $paramTypes));
                }
                $else = $if->else;
                if ($else !== null) {
                    $out = \array_merge($out, $this->scanStorePropTypes($else->statements, $prop, $paramTypes));
                }
            } elseif ($k === 'While') {
                $out = \array_merge($out, $this->scanStorePropTypes($this->asWhileStmt($s)->body->statements, $prop, $paramTypes));
            } elseif ($k === 'DoWhile') {
                $out = \array_merge($out, $this->scanStorePropTypes($this->asDoWhileStmt($s)->body->statements, $prop, $paramTypes));
            } elseif ($k === 'For') {
                $out = \array_merge($out, $this->scanStorePropTypes($this->asForStmt($s)->body->statements, $prop, $paramTypes));
            } elseif ($k === 'Foreach') {
                $out = \array_merge($out, $this->scanStorePropTypes($this->asForeachStmt($s)->body->statements, $prop, $paramTypes));
            } elseif ($k === 'TryCatch') {
                $tc = $this->asTryCatchStmt($s);
                $out = \array_merge($out, $this->scanStorePropTypes($tc->try->statements, $prop, $paramTypes));
                foreach ($tc->catches as $c) {
                    $out = \array_merge($out, $this->scanStorePropTypes($this->asCatchClause($c)->body->statements, $prop, $paramTypes));
                }
                $fin = $tc->finally;
                if ($fin !== null) {
                    $out = \array_merge($out, $this->scanStorePropTypes($fin->statements, $prop, $paramTypes));
                }
            } elseif ($k === 'Switch') {
                foreach ($this->asSwitchStmt($s)->cases as $case) {
                    $out = \array_merge($out, $this->scanStorePropTypes($this->asSwitchArm($case)->body, $prop, $paramTypes));
                }
            }
        }
        return $out;
    }

    /**
     * The element type of an `$this->$prop[] = X` push store, or null when the
     * expression is not a store to `$prop`. Returns `Type::unknown()` (bail) for
     * a keyed element store, a wholesale non-empty `$this->$prop = X` reassign,
     * or an unresolvable pushed value.
     *
     * @param array<string, Type> $paramTypes
     */
    private function storeElemTypeOf(\Parser\Ast\Expr $e, string $prop, array $paramTypes): ?Type
    {
        if ($e->kind !== 'Assign') { return null; }
        $as = $this->asAssign($e);
        $target = $as->target;
        if ($target->kind === 'ArrayAccess') {
            $aa = $this->asArrayAccessExpr($target);
            if (!$this->isThisProp($aa->array, $prop)) { return null; }
            if ($aa->index !== null) {
                // A string-keyed store implies an ASSOC; keep a resolvable value
                // type so untyped reads aren't erased to raw pointers (the bare
                // `array` assoc-value bug). An int / dynamic key can't be assumed
                // packed → bail (stay erased).
                if (!$this->syntacticKeyIsString($aa->index, $paramTypes)) { return Type::unknown(); }
                $this->propStoreStrKey = true;
                return $this->syntacticValueType($as->value, $paramTypes);
            }
            return $this->syntacticValueType($as->value, $paramTypes);
        }
        if ($this->isThisProp($target, $prop)) {
            // `$this->prop = []` re-init is fine (no element info); any other
            // wholesale assignment could seed a foreign element type → bail.
            $rhs = $as->value;
            if ($rhs->kind === 'ArrayLit') {
                if ($this->asArrayLit($rhs)->elements === []) { return null; }
                // A homogeneous list literal reveals the element type
                // (`$this->items = [5,6,7]` in the ctor → vec[int]), same as an
                // inline default — so a read / by-ref of `$this->items[$i]` stays
                // typed instead of erased to raw pointers.
                $elem = $this->inferBareArrayPropElem($rhs);
                if ($elem !== null) { return $elem; }
                // A string-keyed homogeneous literal (`$this->map = ["x"=>10]`) →
                // assoc[string, V]; flag it so buildClassDef builds an assoc type.
                $ve = $this->inferBareArrayPropAssocElem($rhs);
                if ($ve !== null) { $this->propStoreStrKey = true; return $ve; }
            }
            return Type::unknown();
        }
        return null;
    }

    /** Whether `$e` is `$this->$prop`. */
    private function isThisProp(\Parser\Ast\Expr $e, string $prop): bool
    {
        if ($e->kind !== 'PropertyAccess') { return false; }
        $pa = $this->asPropAccess($e);
        if ($pa->property !== $prop) { return false; }
        $obj = $pa->object;
        return $obj->kind === 'Variable' && $this->asVariableExpr($obj)->name === 'this';
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
            $cls = \ltrim($this->asNewExpr($v)->class, '\\');
            if ($cls === 'self' || $cls === 'static') { $cls = $this->currentLowerClass; }
            return $cls !== '' ? Type::obj($cls) : Type::unknown();
        }
        if ($k === 'Variable') {
            $name = $this->asVariableExpr($v)->name;
            return $paramTypes[$name] ?? Type::unknown();
        }
        if ($k === 'IntLiteral')    { return Type::int_(); }
        if ($k === 'StringLiteral') { return Type::string_(); }
        if ($k === 'FloatLiteral')  { return Type::float_(); }
        if ($k === 'BoolLiteral')   { return Type::bool_(); }
        // A concat / interpolation (BinaryOp `.`) and a `(string)` cast are
        // always string — the common assoc-value shape (`$this->d[$k] = "$a->$b"`).
        if ($k === 'BinaryOp' && $this->asBinaryOp($v)->op === '.') { return Type::string_(); }
        if ($k === 'Cast' && $this->asCastExpr($v)->cast === 'string') { return Type::string_(); }
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
        if ($kk === 'BinaryOp' && $this->asBinaryOp($k)->op === '.') { return true; }
        if ($kk === 'Cast' && $this->asCastExpr($k)->cast === 'string') { return true; }
        if ($kk === 'Variable') {
            $t = $paramTypes[$this->asVariableExpr($k)->name] ?? null;
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

    /**
     * Lower a type hint, recovering a bare `array`'s element type from a
     * docblock annotation token (`X[]` or `array<...>`). PHP's `array`
     * hint erases the element type; the `@param`/`@return`/`@var X[]`
     * docblock is the only carrier of it for object collections, and
     * without it `vec[obj]` reads collapse to the fallback offset. A
     * scalar doc token (no `[]` / `array<`) is ignored.
     */
    private function effectiveHint(?string $hint, ?string $docType): ?string
    {
        if ($this->isBareArrayHint($hint)
            && $docType !== null && $docType !== ''
            && $this->looksLikeArrayElemType($docType)) {
            return $docType;
        }
        return $hint;
    }

    /** Whether `$t` is a container shape (`X[]` or `array<...>`). */
    private function looksLikeArrayElemType(string $t): bool
    {
        $n = \strlen($t);
        if ($n > 2 && \substr($t, $n - 2) === '[]') { return true; }
        if (\strncmp(\strtolower(\ltrim($t, '?\\')), 'array<', 6) === 0) { return true; }
        return false;
    }

    /**
     * Extract the type token for a docblock `@param X[] $name` (when
     * `$varName` is non-empty) or `@return X[]` / `@var X[]` (empty
     * `$varName`). Returns the raw token (`PropertyDecl[]`) or null.
     */
    private function docTagType(?string $doc, string $tag, string $varName): ?string
    {
        if ($doc === null) { return null; }
        $n = \strlen($doc);
        $tlen = \strlen($tag);
        // Single forward scan — self-host `strpos` ignores the offset
        // arg (`@__mir_strpos` is 2-ary), so a positional re-search
        // would loop on the first hit. Walk the buffer once instead,
        // using only bounded `substr`.
        $i = 0;
        while ($i + $tlen <= $n) {
            if (\substr($doc, $i, $tlen) !== $tag) { $i = $i + 1; continue; }
            $j = $i + $tlen;
            // Require a whitespace boundary after the tag (`@param` not
            // `@params`).
            $b = ($j < $n) ? \substr($doc, $j, 1) : '';
            if ($b !== ' ' && $b !== "\t") { $i = $i + 1; continue; }
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c !== ' ' && $c !== "\t") { break; }
                $j = $j + 1;
            }
            $typeStart = $j;
            // Angle-bracket-aware: a generic like `array<string, ClassDef>`
            // carries a space after the comma — don't stop the type token
            // on whitespace while inside `<...>`, or the value type is lost.
            $depth = 0;
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c === '<') { $depth = $depth + 1; }
                elseif ($c === '>') { if ($depth > 0) { $depth = $depth - 1; } }
                elseif ($depth === 0
                    && ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r")) {
                    break;
                }
                $j = $j + 1;
            }
            $type = \substr($doc, $typeStart, $j - $typeStart);
            if ($varName === '') { return $type; }
            while ($j < $n) {
                $c = \substr($doc, $j, 1);
                if ($c !== ' ' && $c !== "\t") { break; }
                $j = $j + 1;
            }
            $want = '$' . $varName;
            $wl = \strlen($want);
            if ($j + $wl <= $n && \substr($doc, $j, $wl) === $want) {
                $after = ($j + $wl < $n) ? \substr($doc, $j + $wl, 1) : ' ';
                if ($after === ' ' || $after === "\t"
                    || $after === "\n" || $after === "\r") {
                    return $type;
                }
            }
            $i = $j;
        }
        return null;
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
