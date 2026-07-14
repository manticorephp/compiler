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
}
