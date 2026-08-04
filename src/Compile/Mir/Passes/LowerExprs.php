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
 * Expressions → MIR nodes.
 *
 * A trait on the one {@see LowerFromAst} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait LowerExprs
{
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
            return new IntConst($expr->value, Type::int_());
        }
        if ($expr->kind === 'FloatLiteral') {
            // Pin to the FloatLiteral subclass so `->value` is FLOAT-typed: a
            // base-`Expr` read of `value` borrows a subclass type and resolves to
            // INT (IntLiteral's `value`). The double's bits then ride an i64
            // carrier TYPED int — harmless on its own (a bitcast round-trips it),
            // but a float-param ctor coercion (`new FloatConst(float)`) would
            // sitofp those bits to garbage. Type-pinned read keeps it float.
            return new FloatConst($expr->value, Type::float_());
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
            // A BARE `$GLOBALS` — every legal use ($GLOBALS['x']) is intercepted
            // at the ArrayAccess above it, so reaching here means the whole array
            // was read (foreach/count) or handed to a by-ref parameter.
            if ($this->isGlobalsVar($expr)) { $this->rejectGlobalsRead(); }
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
            // const lookup below. Resolve `self`/`static`/`parent` first so a
            // `self::Case` inside an enum method finds its own case table.
            $ecls = \ltrim($this->resolveStaticClass($saClass), '\\');
            if (isset($this->enumTable[$ecls])) {
                $ord = $this->enumTable[$ecls]->ordinalOf($saName);
                if ($ord >= 0) {
                    $this->noteDeprecatedConstUse($ecls . '::' . $saName, $expr->span->line);
                    return new IntConst($ord, Type::obj($ecls));
                }
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
                $this->noteDeprecatedConstUse($cname . '::' . $saName, $expr->span->line);
                $prevC = $this->currentLowerClass;
                $this->currentLowerClass = $cname;
                $lowered = $this->lowerExpr($cv);
                $this->currentLowerClass = $prevC;
                return $lowered;
            }
            // Nothing matched. Falling through to the generic "unsupported
            // expression kind StaticAccess" throw below names the CONSTRUCT,
            // which reads as "the compiler cannot do `::`" and sends you to the
            // wrong file — the actual cause is almost always a class the build
            // never saw (a prelude module whose demand gate did not fire, a
            // missing `use`, a typo). Say which it is.
            $known = isset($this->classDecls[$cname]) || isset($this->traitTable[$cname])
                || isset($this->enumTable[$cname]);
            throw new \RuntimeException(
                $known
                    ? 'MIR.lower: unknown class constant ' . $cname . '::' . $saName
                        . ' at line ' . (string)$expr->span->line
                    : 'MIR.lower: unknown class ' . $cname . ' (in ' . $cname . '::'
                        . $saName . ') at line ' . (string)$expr->span->line
            );
        }
        if ($expr->kind === 'DynamicStaticAccess') {
            // `$obj::class` → the operand's class name as a string. Read the
            // subclass `name` / `receiver` through a typed param (T5 offset).
            if ($this->dynStaticName($expr) === 'class') {
                return new ClassName_($this->lowerExpr($this->dynStaticReceiver($expr)), Type::string_());
            }
            return $this->lowerDynStaticAccess($expr);
        }
        if ($expr->kind === 'DynamicStaticCall') {
            return $this->lowerDynStaticCall($expr);
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
            // `call_user_func_array($cb, [$a, $b])` — a LITERAL array spreads
            // into clean fixed positional args (avoids the runtime element
            // loop). A RUNTIME array is forwarded as a single `...$arr` spread,
            // which lowerInvoke routes to the string/const-callable path (→ a
            // Call that emitCall expands) or the known-closure invoke path.
            // Array/static/method callable + a runtime array remains
            // unsupported (StaticCall_/MethodCall_ don't expand a spread).
            if ($fn === 'call_user_func_array' && \count($expr->args) === 2) {
                if ($expr->args[1]->kind === 'ArrayLit') {
                    $spread = [];
                    foreach ($this->arrayLitElements($expr->args[1]) as $el) {
                        $spread[] = $this->elemValue($el);
                    }
                    return $this->lowerInvoke(new \Parser\Ast\Invoke($expr->args[0], $spread, $expr->span));
                }
                $spreadArg = new \Parser\Ast\Spread($expr->args[1], $expr->span);
                return $this->lowerInvoke(new \Parser\Ast\Invoke($expr->args[0], [$spreadArg], $expr->span));
            }
            if ($fn === 'isset') {
                $ts = [];
                foreach ($expr->args as $a) { $ts[] = $this->lowerExpr($a); }
                return new Isset_($ts, Type::bool_());
            }
            if ($fn === 'unset') {
                // `unset($GLOBALS['x'])` nulls the module cell — Unset_ itself has
                // no idea what a cell is and silently no-ops on one.
                $ts = [];
                $cells = [];
                foreach ($expr->args as $a) {
                    if ($this->isGlobalsAccess($a)) {
                        $cells[] = $this->unsetGlobalsCell($a);
                        continue;
                    }
                    $ts[] = $this->lowerExpr($a);
                }
                if ($cells === []) { return new Unset_($ts, Type::void()); }
                if ($ts !== []) { $cells[] = new Unset_($ts, Type::void()); }
                return \count($cells) === 1 ? $cells[0] : new Block($cells, Type::void());
            }
            // `empty($x)` → falsiness test (carrier == 0). Matches the
            // self-host usage (bool / null / `?? false` flags); the
            // string-"0"/"" subtlety is not exercised by the compiler.
            if ($fn === 'empty' && \count($expr->args) === 1) {
                return new Not_($this->lowerExpr($expr->args[0]));
            }
            // By-ref `sscanf($str, $fmt, $a, $b, …)` — the array-return form
            // (`$r = sscanf($s, $f)`) is a plain stdlib call; the trailing-lvalue
            // form assigns each parsed field into a variable and returns the count.
            // Desugar to: tmp = __mc_sscanf($s, $f); $a = tmp[0]; $b = tmp[1]; …;
            // count(tmp). (php returns the number of assigned values; a full match
            // makes that the field count — the partial-match tail is not modelled.)
            $fnBare = ($bp = \strrpos($fn, '\\')) === false ? $fn : \substr($fn, $bp + 1);
            // `getenv()` with NO argument returns the whole environment as an
            // assoc array — the same value as `$_ENV`. Reading the superglobal (not
            // a bare `__mc_env` call) lets injectSuperglobals seed + keep the
            // builder; a direct call would be tree-shaken (undefined at link). The
            // single-arg `getenv($name)` stays the codegen builtin.
            if ($fnBare === 'getenv' && \count($expr->args) === 0) {
                return new LoadLocal('_ENV', Type::assoc(Type::string_(), Type::string_()));
            }
            if ($fnBare === 'sscanf' && \count($expr->args) > 2) {
                $s = $this->lowerExpr($expr->args[0]);
                $fmt = $this->lowerExpr($expr->args[1]);
                $tmp = '__sscanf_' . (string)$this->destrCounter;
                $this->destrCounter = $this->destrCounter + 1;
                $vecT = Type::vec(Type::cell());
                $stmts = [new StoreLocal($tmp, new Call('__mc_sscanf', [$s, $fmt], $vecT), $vecT)];
                $n = \count($expr->args);
                for ($i = 2; $i < $n; $i = $i + 1) {
                    $val = new ArrayAccess_(new LoadLocal($tmp, $vecT), new IntConst($i - 2, Type::int_()), Type::cell());
                    $stmts[] = $this->storeToTarget($expr->args[$i], $val);
                }
                $stmts[] = new Call('count', [new LoadLocal($tmp, $vecT)], Type::int_());
                return new Block($stmts, Type::int_());
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
            // `array_multisort($a, SORT_DESC, $b, …)` — every array argument is
            // BY REF and the SORT_* flags are interleaved positionally, which
            // needs a by-ref VARIADIC pack; that does not exist (the caller
            // packs trailing args into one array_lit, so the pack is a VALUE and
            // the callee's writes land in a throwaway alloca). Zend special-
            // cases this function in the engine for the same reason, so we
            // desugar it at the call site, where the arguments are still real
            // lvalues: compute the row permutation once, then rebuild each
            // column through a plain assignment. The call itself yields `true`.
            if ($fn === 'array_multisort' && \count($expr->args) >= 1) {
                $ms = $this->lowerMultisort($expr);
                if ($ms !== null) { return $ms; }
            }
            // `__mc_new_uninit('C')` — internal: allocate an instance WITHOUT
            // running __construct, which is what php's unserialize does. Written
            // only by the generated `__mc_unser_alloc`, never in src/, so this
            // costs the bootstrapping compiler nothing. Desugared to a NewObj so
            // it reuses the whole allocation path (per-slot zero-init by repr,
            // the bag, a reified specialization) instead of a second emitter.
            if ($fn === '__mc_new_uninit' && \count($expr->args) === 1) {
                $lit = $this->lowerExpr($expr->args[0]);
                if ($lit->kind === Node::KIND_STRING_CONST) {
                    $cls = $lit->value;
                    $no = new NewObj($cls, [], Type::obj($cls));
                    $no->bare = true;
                    return $no;
                }
            }
            // `count($x, COUNT_RECURSIVE)` — the codegen builtin reads the live
            // length out of the array header and has no notion of a mode, so a
            // non-zero mode is rewritten to the stdlib walker instead. A literal
            // COUNT_NORMAL (0) keeps the fast path.
            if (($fn === 'count' || $fn === 'sizeof') && \count($expr->args) === 2) {
                $mode = $this->lowerExpr($expr->args[1]);
                if ($mode->kind !== Node::KIND_INT_CONST || $mode->value !== 0) {
                    return new Call('__mc_count_recursive', [
                        $this->lowerExpr($expr->args[0]),
                    ], Type::int_());
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
            // `func_num_args()` / `func_get_arg($k)` / `func_get_args()`.
            //
            // The count Zend reports is what the CALLER wrote, and the callee
            // cannot see it directly: an omitted optional arrives already
            // filled from its default ({@see LowerFns::defaultFillArgs}), so by
            // the time the body runs every argument list is exactly arity-many.
            // It travels on a side channel instead — the call site pushes its
            // as-written count and {@see prologueFuncArgs} takes it off into
            // `__mc_argc` before the body's first nested call can overwrite it.
            //
            // The VALUES come from the parameter locals: PHP >= 7 hands back a
            // parameter's CURRENT value, not the one originally passed, so the
            // locals are the correct source and no separate argument buffer is
            // needed. Args past the declared list have no local and ride the
            // overflow channel `__mc_argx`.
            //
            // Matched on the BARE name: a namespaced file qualifies an
            // unqualified call, so `func_get_arg(` inside
            // `namespace Symfony\…;` arrives as `Symfony\…\func_get_arg`.
            $fnBarePos = \strrpos($fn, '\\');
            $fnBare = $fnBarePos === false ? $fn : \substr($fn, $fnBarePos + 1);
            if ($fnBare === 'func_num_args' && \count($expr->args) === 0) {
                $this->sawFuncArgs = true;
                return new LoadLocal($this->argcLocalName(), Type::int_());
            }
            if ($fnBare === 'func_get_args' && \count($expr->args) === 0) {
                $this->sawFuncArgs = true;
                return new Call('__mc_func_get_args',
                    [$this->funcArgsVector(), new LoadLocal($this->argcLocalName(), Type::int_())],
                    Type::vec(Type::cell()));
            }
            if ($fnBare === 'func_get_arg' && \count($expr->args) === 1) {
                $this->sawFuncArgs = true;
                // Any index expression, not just a literal: the idiomatic
                // `for ($i = 0; $i < func_num_args(); $i++) func_get_arg($i)`
                // passes a loop variable, which used to fall through to an
                // undefined `@manticore_func_get_arg` and fail the link.
                return new Call('__mc_func_get_arg',
                    [
                        $this->funcArgsVector(),
                        new LoadLocal($this->argcLocalName(), Type::int_()),
                        $this->lowerExpr($expr->args[0]),
                    ],
                    Type::cell());
            }
            // `trigger_error($msg[, $level])` → `__mc_trigger_error($msg,
            // $level, <file>, <line>, <silenced>)`. A prelude function cannot
            // see its caller's position, and php's diagnostic names the CALL
            // SITE, so the file and line are threaded in from the span here.
            // The last argument is 1 when the call was written `@trigger_error`
            // ({@see lowerUnary}) — the one thing `@` has to mean now that a
            // diagnostic can actually be printed.
            if ($fnBare === 'trigger_error' && \count($expr->args) >= 1
                && \count($expr->args) <= 2) {
                $msg = $this->lowerExpr($expr->args[0]);
                $lvl = \count($expr->args) > 1
                    ? $this->lowerExpr($expr->args[1])
                    : new IntConst(1024, Type::int_());   // E_USER_NOTICE
                return new Call('__mc_trigger_error', [
                    $msg,
                    $lvl,
                    new StringConst($this->lowerSourceFile, Type::string_()),
                    new IntConst($expr->span->line, Type::int_()),
                    new IntConst($this->silenceDepth > 0 ? 1 : 0, Type::int_()),
                ], Type::bool_());
            }
            // `function_exists("Name")` → compile-time 1/0 against the
            // declared functions (incl. FFI externs / use-function
            // aliases). A non-literal arg conservatively folds to false.
            // Shares functionIsKnown with the statement-position guard fold, so
            // the two can never disagree — and so a HIDDEN function (a
            // link-only Windows stub) reads absent in both.
            if ($fn === 'function_exists' && \count($expr->args) === 1) {
                $a0 = $expr->args[0];
                if ($a0->kind === 'StringLiteral') {
                    return new IntConst(
                        $this->functionIsKnown($this->stringLitValue($a0)) ? 1 : 0,
                        Type::bool_());
                }
                // A NON-literal argument used to fold to false, described as
                // conservative. Conservative is the wrong word for an answer
                // that is simply wrong: a loop over a list of names reported
                // strlen, count and floor all missing, and it is what made this
                // project's own SAPI presence probe claim trigger_error was
                // absent. The function set is CLOSED at compile time, so the
                // honest answer is a lookup — the same name-table-and-scan shape
                // the dynamic function-name dispatch and the include resolver
                // already use.
                $this->sawDynFnExists = true;
                return new Call('__mir_fn_exists', [$this->lowerExpr($a0)], Type::bool_());
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
            // `fopen('php://stdout', …)` → the same cached \Resource the STDOUT
            // constant lowers to. It cannot be done in the stdlib's fopen: the
            // FILE* has to come from the `__mir_std*` BUILTIN emitted at the
            // MENTION, because resolving those platform globals needs host_os(),
            // and a stdlib function that mentioned them would make the
            // compiler's own src/ use a stream and kill the Zend cold seed (see
            // LowerPrelude's STDIN/STDOUT/STDERR). Doing it here keeps that
            // invariant and still answers the literal every console app opens
            // with — symfony's ConsoleOutput is fopen('php://stdout', 'w').
            if ($fn === 'fopen' && \count($expr->args) >= 1
                && $expr->args[0]->kind === 'StringLiteral') {
                $stdRes = $this->stdStreamResource($this->stringLitValue($expr->args[0]));
                if ($stdRes !== null) { return $stdRes; }
            }
            // First-class callable: `foo(...)` → a closure wrapping foo.
            if (\count($expr->args) === 1 && $expr->args[0]->kind === 'Ellipsis') {
                return $this->lowerFcc($expr->function);
            }
            $callee = $this->resolveCallName($expr->function);
            $args = $this->lowerCallArgs($callee, $expr->args);
            $sited = $this->asyncSiteCallee($callee);
            if ($sited !== '') {
                $site = $this->callSite($expr->span);
                if ($site !== '') {
                    $withSite = [new StringConst($site, Type::string_())];
                    foreach ($args as $a) { $withSite[] = $a; }
                    return new Call($sited, $withSite, Type::unknown());
                }
            }
            $call = new Call($callee, $args, Type::unknown());
            // Before defaultFillArgs padded it — the callee's func_num_args()
            // needs what the SOURCE wrote, and this is the last point that
            // knows it. The overflow is read here and nowhere later: the next
            // lowering of any call overwrites it.
            $call->srcArgc = \count($expr->args);
            return $call;
        }
        if ($expr->kind === 'Spread')         { return new Spread_($this->lowerExpr($expr->value), Type::unknown()); }
        if ($expr->kind === 'ArrayLit')       { return $this->lowerArrayLit($expr); }
        if ($expr->kind === 'ArrayAccess')    { return $this->lowerArrayAccess($expr); }
        if ($expr->kind === 'New')            { return $this->lowerNewExpr($expr); }
        if ($expr->kind === 'NewDyn')         { return $this->lowerNewDynExpr($expr); }
        if ($expr->kind === 'PropertyAccess') {
            // Pin to PropertyAccess before reading `nullsafe`: on the base `Expr`
            // the field offset is the load-bearing subclass's (poly-prop trap) —
            // PropertyAccess holds `nullsafe` at a different slot than MethodCall,
            // so a base read returns garbage and routes EVERY `->prop` through the
            // nullsafe desugar.
            $pa = $expr;
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
        if ($expr->kind === 'Identifier')     { return $this->lowerIdentifier($expr->name, $expr->span->line); }
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
        // An unresolvable CLASS CONSTANT is php's runtime Error, not a compile
        // error — same rule as a bare undefined constant, and it reaches here
        // for the same real reason: symfony/cache names `PDO::CASE_LOWER` in a
        // PdoAdapter that only runs when ext-pdo is there. php reports the two
        // cases differently, so the message is chosen by whether the CLASS is
        // known at all.
        //
        // Deliberately narrow: only a StaticAccess whose class or constant
        // genuinely is not there converts. Anything else still hits the throw
        // below, because this fallthrough is what catches a construct the
        // compiler simply failed to route, and turning that into a runtime
        // error would hide compiler gaps instead of reporting them.
        if ($expr->kind === 'StaticAccess') {
            $saCls = $this->resolveStaticClass($this->staticAccessClass($expr));
            $saName = $this->staticAccessName($expr);
            $classKnown = isset($this->classTable[$saCls])
                || isset($this->enumTable[$saCls])
                || isset($this->classDecls[$saCls]);
            if ($saName !== '' && \strtolower($saName) !== 'class') {
                return $this->throwErrorExpr($classKnown
                    ? 'Undefined constant ' . $saCls . '::' . $saName
                    : 'Class "' . $saCls . '" not found');
            }
        }
        $extra = '';
        if ($expr->kind === 'StaticAccess') { $extra = ' (' . $this->staticAccessClass($expr) . '::' . $this->staticAccessName($expr) . ')'; }
        if ($expr->kind === 'Identifier') { $extra = ' (' . ($expr->name ?? '?') . ')'; }
        throw new \RuntimeException(
            'MIR.lower: unsupported expression kind ' . $expr->kind . $extra
            . ' at line ' . (string)$expr->span->line
        );
    }

    /**
     * Desugar `array_multisort($a, SORT_DESC, $b, …)`.
     *
     * Every array argument is BY REF and the `SORT_*` settings are interleaved
     * positionally — a by-ref variadic pack, which the compiler does not have
     * (trailing args are packed into one array_lit, i.e. a VALUE, so a callee's
     * writes would land in a throwaway alloca and vanish silently). Zend
     * special-cases the function in the engine for the same reason. Here the
     * arguments are still real lvalues, so the whole thing lowers to
     *
     *     $__mc_msN = __mc_multisort_order([$a, $b], [orders], [flags]);
     *     $a = __mc_multisort_apply($a, $__mc_msN);
     *     $b = __mc_multisort_apply($b, $__mc_msN);
     *
     * queued on {@see LowerFromAst::$pendingCallInits} (flushed immediately
     * before the statement that uses them, the `#[RefOut]` auto-viv path), with
     * the call itself yielding `true` — array_multisort's return value.
     *
     * Classification is syntactic: a plain `$var` starts a new column, anything
     * else is one of that column's two settings. Zend decides by runtime TYPE
     * and accepts the order/flag pair in either sequence, so the settings are
     * told apart by VALUE — SORT_ASC (4) / SORT_DESC (3) are the order, every
     * other constant is the flags. A shape this cannot classify (a non-variable
     * column, a non-constant setting, a setting before any column) returns null
     * and falls through to the normal call path, which reports the function as
     * unresolved rather than compiling something silently wrong.
     */
    private function lowerMultisort(\Parser\Ast\Call $expr): ?Node
    {
        $names = [];
        $orders = [];
        $flags = [];
        $cur = -1;
        foreach ($expr->args as $a) {
            if ($a->kind === 'Variable') {
                $names[] = $this->variableName($a);
                $orders[] = 4;                       // SORT_ASC
                $flags[] = 0;                        // SORT_REGULAR
                $cur = $cur + 1;
                continue;
            }
            if ($cur < 0) { return null; }           // a setting before any column
            $lv = $this->lowerExpr($a);
            if ($lv->kind !== Node::KIND_INT_CONST) { return null; }
            $v = $lv->value;
            if ($v === 3 || $v === 4) { $orders[$cur] = $v; } else { $flags[$cur] = $v; }
        }
        if (\count($names) === 0) { return null; }

        $colEls = [];
        $ordEls = [];
        $flgEls = [];
        $i = 0;
        foreach ($names as $nm) {
            $colEls[] = new ArrayElement_(null, new LoadLocal($nm, Type::unknown()));
            $ordEls[] = new ArrayElement_(null, new IntConst($orders[$i], Type::int_()));
            $flgEls[] = new ArrayElement_(null, new IntConst($flags[$i], Type::int_()));
            $i = $i + 1;
        }
        $permName = '__mc_ms' . (string)$this->multisortSeq;
        $this->multisortSeq = $this->multisortSeq + 1;
        $order = new Call('__mc_multisort_order', [
            new ArrayLit($colEls, Type::unknown()),
            new ArrayLit($ordEls, Type::unknown()),
            new ArrayLit($flgEls, Type::unknown()),
        ], Type::unknown());
        $this->pendingCallInits[] = new StoreLocal($permName, $order, Type::unknown());
        foreach ($names as $nm) {
            // A plain CALL, not an assignment: `__mc_multisort_apply` takes its
            // column BY REF, so each one keeps its own element repr (the store-
            // back of a cell-element return into a `vec[string]` slot made the
            // slot's release read cell bits as string pointers). By-ref-ness is
            // resolved at emit from the callee's signature, and a LoadLocal is
            // addressable, so nothing extra is needed at the call site.
            $this->pendingCallInits[] = new Call('__mc_multisort_apply', [
                new LoadLocal($nm, Type::unknown()),
                new LoadLocal($permName, Type::unknown()),
            ], Type::void());
        }
        return new BoolConst(true, Type::bool_());
    }

    /**
     * The internal twin that carries a call site, for the three `Async\` entry
     * points that CREATE a task — or '' for everything else.
     *
     * A parked task otherwise reports `#3 io-read fd=7`: an fd number, when what
     * a hang needs is a line of code. `Task::named()` exists but nobody annotates
     * before the hang, so the site is folded in at the one stage that still knows
     * which file a call came from (lowering sees statements flattened across the
     * whole build — the same reason `__FILE__` folds at parse time).
     *
     * Only the NAMESPACED functions are rewritten, so a program's own `spawn()`
     * is untouched; `resolveCallName` has already mapped `use function
     * Async\spawn` to its FQN by the time we get here.
     */
    private function asyncSiteCallee(string $callee): string
    {
        // No async prelude in this build ⇒ the twins do not exist to call.
        if ($this->asyncSrc === '') { return ''; }
        if ($callee === 'Async\\spawn')   { return 'Async\\__spawnAt'; }
        if ($callee === 'Async\\async')   { return 'Async\\__asyncAt'; }
        if ($callee === 'Async\\timeout') { return 'Async\\__timeoutAt'; }
        if ($callee === 'Async\\group')   { return 'Async\\__groupAt'; }
        return '';
    }

    /**
     * `file:line` for a call, relative to the compiling directory when it sits
     * under it — an absolute path is machine-specific noise in a task dump, and
     * the relative form is what the test expectations can pin.
     *
     * '' when the span has no file: a synthesized node, or the prelude blob,
     * which is parsed as one source with no path. That is also what keeps the
     * rewrite from firing on `prelude/async.php`'s own internal calls.
     */
    private function callSite(\Parser\Ast\Span $span): string
    {
        $file = $span->file;
        if ($file === '') { return ''; }
        if ($this->siteCwd === '') {
            $cwd = \getcwd();
            $this->siteCwd = $cwd === false ? '-' : ($cwd . '/');
        }
        $n = \strlen($this->siteCwd);
        if ($this->siteCwd !== '-' && \substr($file, 0, $n) === $this->siteCwd) {
            $file = \substr($file, $n);
        }
        return $file . ':' . (string)$span->line;
    }

    /** Cached `getcwd()` with a trailing slash for {@see callSite()}; '-' = none. */
    private string $siteCwd = '';

    /** Build the store node for an assignment target + already-lowered value. */
    private function storeToTarget(\Parser\Ast\Expr $target, Node $value): Node
    {
        if ($target->kind === 'Variable') {
            // `$GLOBALS = […]` / `$GLOBALS += […]` — a php 8.1 fatal, and silently
            // accepting it wrote a bogus local named GLOBALS while the real globals
            // sat untouched. {@see rejectGlobalsWrite}
            if ($this->isGlobalsVar($target)) { $this->rejectGlobalsWrite(); }
            return new StoreLocal($target->name, $value, $value->type);
        }
        if ($target->kind === 'ArrayAccess') {
            $g = $this->storeToGlobals($target, $value);
            if ($g !== null) { return $g; }
            $arr = $this->lowerExpr($target->array);
            $idx = $target->index === null
                ? new NullConst(Type::null_())
                : $this->foldNumericKey($this->lowerExpr($target->index));
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
                return new StoreStaticProp_($ref->global, $value, $value->type, $ref->type);
            }
        }
        if ($target->kind === 'ArrayLit') {
            return $this->lowerDestructure($target, $value);
        }
        // Name the FILE, not just the line. A whole-program build merges
        // thousands of files into one module, so "at line 25" sent three
        // separate searches to the wrong file before this was added — the
        // parse error has always named its file and this had no reason not to.
        throw new \RuntimeException(
            'MIR.lower: unsupported assign target kind ' . $target->kind
            . ' at ' . ($this->lowerSourceFile !== '' ? $this->lowerSourceFile : '<source>')
            . ':' . (string)$target->span->line
        );
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
        // Spaceship: a primitive that evaluates each operand ONCE. The old
        // `($a > $b) - ($a < $b)` expansion ran both twice (side effects
        // included) and could not express PHP's uncomparable answer.
        if ($op === '<=>') {
            return new \Compile\Mir\Spaceship($left, $right);
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
}
