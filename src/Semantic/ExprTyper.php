<?php

namespace Semantic;

use Analyze\Index;
use Analyze\TemplateSig;
use Parser\Ast\ArrayAccess;
use Parser\Ast\ArrayLit;
use Parser\Ast\ArrowFn;
use Parser\Ast\Assign;
use Parser\Ast\BinaryOp;
use Parser\Ast\BoolLiteral;
use Parser\Ast\CallExpr;
use Parser\Ast\Cast;
use Parser\Ast\CloneExpr;
use Parser\Ast\Closure;
use Parser\Ast\CompoundAssign;
use Parser\Ast\Expr;
use Parser\Ast\DynProp;
use Parser\Ast\Ellipsis;
use Parser\Ast\FloatLiteral;
use Parser\Ast\IncDec;
use Parser\Ast\InstanceofExpr;
use Parser\Ast\IntLiteral;
use Parser\Ast\Invoke;
use Parser\Ast\MagicConstant;
use Parser\Ast\MatchExpr;
use Parser\Ast\MethodCallExpr;
use Parser\Ast\NamedArg;
use Parser\Ast\NewExpr;
use Parser\Ast\NullCoalesce;
use Parser\Ast\NullLiteral;
use Parser\Ast\PropertyAccess;
use Parser\Ast\RefAssign;
use Parser\Ast\Spread;
use Parser\Ast\StaticAccess;
use Parser\Ast\StaticCall;
use Parser\Ast\StringLiteral;
use Parser\Ast\Ternary;
use Parser\Ast\UnaryOp;
use Parser\Ast\Variable;

/**
 * Source-faithful expression typing for the editor. null = unknown, never a guess. Pure: assignments do
 * not update the environment, and no flow narrowing is applied. Recursion is depth-capped.
 * A nullsafe chain `$a?->b->c` is typed from `b` alone (the short-circuit null is not propagated).
 */
final class ExprTyper
{
    private const MAX_DEPTH = 400;
    private const MAX_NODES = 4000;
    private const SHAPE_MAX = 8;

    /** @var array<string, bool> constants being evaluated (cycle guard) */
    private array $busy = [];
    private int $budget = 0;

    public function __construct(private Index $idx, private string $file) {}

    public function typeOf(Expr $e, TypeEnv $env): ?SourceType
    {
        $this->budget = self::MAX_NODES;
        $this->busy = [];
        return $this->go($e, $env, 0);
    }

    private function go(Expr $e, TypeEnv $env, int $d): ?SourceType
    {
        if ($d > self::MAX_DEPTH || $this->budget <= 0) { return null; }
        $this->budget = $this->budget - 1;
        $d = $d + 1;
        if ($e instanceof Variable) { return $env->vars[$e->name] ?? null; }
        if ($e instanceof IntLiteral) { return self::scalar('int'); }
        if ($e instanceof FloatLiteral) { return self::scalar('float'); }
        if ($e instanceof StringLiteral) { return self::scalar('string'); }
        if ($e instanceof BoolLiteral) { return self::scalar('bool'); }
        if ($e instanceof NullLiteral) { return self::scalar('null'); }
        if ($e instanceof MagicConstant) { return self::scalar($e->name === '__LINE__' ? 'int' : 'string'); }
        if ($e instanceof ArrayLit) { return $this->arrayLit($e, $env, $d); }
        if ($e instanceof ArrayAccess) { return $this->arrayAccess($e, $env, $d); }
        if ($e instanceof NewExpr) { return $this->classType($e->class, $env); }
        if ($e instanceof PropertyAccess) { return $this->property($e, $env, $d); }
        if ($e instanceof MethodCallExpr) { return $this->methodCall($e, $env, $d); }
        if ($e instanceof StaticCall) { return $this->staticCall($e, $env, $d); }
        if ($e instanceof StaticAccess) { return $this->staticAccess($e, $env, $d); }
        if ($e instanceof CallExpr) { return $this->call($e, $env, $d); }
        if ($e instanceof Invoke) { return $this->invoke($e, $env, $d); }
        if ($e instanceof Ternary) { return $this->ternary($e, $env, $d); }
        if ($e instanceof NullCoalesce) {
            $l = $this->go($e->left, $env, $d);
            $r = $this->go($e->right, $env, $d);
            if ($l === null || $r === null) { return null; }
            return self::hasNull($l) || self::maybeUnset($e->left) ? self::union([self::withoutNull($l), $r]) : $l;
        }
        if ($e instanceof MatchExpr) {
            $parts = [];
            foreach ($e->arms as $arm) {
                $t = $this->go($arm->body, $env, $d);
                if ($t === null) { return null; }
                $parts[] = $t;
            }
            return self::union($parts);
        }
        if ($e instanceof CloneExpr) { return $this->go($e->object, $env, $d); }
        if ($e instanceof Closure || $e instanceof ArrowFn) { return self::closure(); }
        if ($e instanceof Cast) { return $this->cast($e, $env, $d); }
        if ($e instanceof InstanceofExpr) { return self::scalar('bool'); }
        if ($e instanceof Assign) { return $this->go($e->value, $env, $d); }
        if ($e instanceof RefAssign) { return $this->go($e->source, $env, $d); }
        if ($e instanceof CompoundAssign) { return $this->compound($e, $env, $d); }
        if ($e instanceof IncDec) {
            $o = $this->go($e->operand, $env, $d);
            return $o !== null && $o->kind === SourceType::SCALAR && ($o->name === 'int' || $o->name === 'float') ? $o : null;
        }
        if ($e instanceof BinaryOp) {
            return self::binary($e->op, $this->go($e->left, $env, $d), $this->go($e->right, $env, $d));
        }
        if ($e instanceof UnaryOp) { return $this->unary($e, $env, $d); }
        return null;
    }

    // ── calls and members ─────────────────────────────────────────────

    private function call(CallExpr $e, TypeEnv $env, int $d): ?SourceType
    {
        if (self::isFcc($e->args)) { return self::closure(); }
        $l = \strtolower($e->function);
        if ($l === 'isset' || $l === 'empty') { return self::scalar('bool'); }
        $fn = $this->idx->resolveFunction($e->function);
        if ($fn === null) { return null; }
        $native = SourceTypeParser::fromHint($fn->returnType, '');
        $doc = SourceTypeParser::fromHint($fn->docType, '');
        $t = SourceTypeParser::withDoc($native, $doc);
        if ($fn->docType !== null && \str_contains($fn->docType, '-of<')) { $t = $this->resolveOf($t, ''); }
        return $fn->tpl === null ? $t : $this->instantiate($fn->tpl, $e->args, $env, $d, $native, $t, '');
    }

    private function invoke(Invoke $e, TypeEnv $env, int $d): ?SourceType
    {
        $c = $this->go($e->callee, $env, $d);
        if ($c === null) { return null; }
        if ($c->kind === SourceType::CALLABLE) { return $c->ret; }
        $classes = $this->classesOf($c, $env);
        return $classes === null ? null : $this->overClasses($classes, '__invoke', 'method');
    }

    private function property(PropertyAccess $e, TypeEnv $env, int $d): ?SourceType
    {
        $recv = $this->go($e->object, $env, $d);
        $key = TypeEnv::pathKey($e);
        if ($key !== null && isset($env->vars[$key])) {
            // a narrowed or assigned property path (see Flow)
            $known = $env->vars[$key];
            return ($e->nullsafe || self::chainHasNullsafe($e->object)) && $recv !== null && self::hasNull($recv) ? self::union([$known, self::scalar('null')]) : $known;
        }
        $classes = $this->classesOf($recv, $env);
        if ($classes === null) { return null; }
        $t = $this->overClasses($classes, $e->property, 'prop');
        return $t !== null && ($e->nullsafe || self::chainHasNullsafe($e->object)) ? self::union([$t, self::scalar('null')]) : $t;
    }

    private function methodCall(MethodCallExpr $e, TypeEnv $env, int $d): ?SourceType
    {
        if (self::isFcc($e->args)) { return self::closure(); }
        $recv = $this->go($e->object, $env, $d);
        $classes = $this->classesOf($recv, $env);
        if ($classes === null) { return null; }
        $t = $this->overClasses($classes, $e->method, 'method');
        if (\count($classes) === 1) {
            $mi = $this->idx->findMethod($classes[0], \strtolower($e->method), 0);
            if ($mi !== null && $mi->tpl !== null) { $t = $this->instantiate($mi->tpl, $e->args, $env, $d, SourceTypeParser::fromHint($mi->returnType, $mi->declClass), $t, $mi->declClass); }
        }
        return $t !== null && ($e->nullsafe || self::chainHasNullsafe($e->object)) ? self::union([$t, self::scalar('null')]) : $t;
    }

    private function staticCall(StaticCall $e, TypeEnv $env, int $d): ?SourceType
    {
        if (self::isFcc($e->args)) { return self::closure(); }
        $cls = $this->classRef($e->class, $env);
        if ($cls === null) { return null; }
        $low = \strtolower($e->class);
        // self:: / parent:: forward the late-static class; Foo:: pins it.
        $static = ($low === 'self' || $low === 'parent' || $low === 'static') && $env->thisClass !== null ? $env->thisClass : $cls;
        $t = $this->memberType($cls, $e->method, 'method', $static);
        $mi = $this->idx->findMethod($cls, \strtolower($e->method), 0);
        if ($mi !== null && $mi->tpl !== null) { $t = $this->instantiate($mi->tpl, $e->args, $env, $d, SourceTypeParser::fromHint($mi->returnType, $mi->declClass), $t, $mi->declClass); }
        return $t;
    }

    private function staticAccess(StaticAccess $e, TypeEnv $env, int $d): ?SourceType
    {
        if ($e->name === 'class') {
            $c = \strtolower($e->class) === 'static' ? null : $this->classRef($e->class, $env);
            return new SourceType(SourceType::GENERIC, 'class-string', $c === null ? [] : [new SourceType(SourceType::CLASS_, $c)]);
        }
        $cls = $this->classRef($e->class, $env);
        if ($cls === null) { return null; }
        if ($e->name !== '' && $e->name[0] === '$') { return $this->memberType($cls, \substr($e->name, 1), 'prop', $cls); }
        $m = $this->idx->lookupMember($cls, $e->name, 'const');
        if ($m === null) { return null; }
        if ($m->kind === 'case') { return new SourceType(SourceType::CLASS_, $m->declClass); }
        if ($m->typeHint !== null) { return $this->memberType($cls, $e->name, 'const', $cls); }
        if ($m->value === null) { return null; }
        $key = \strtolower($m->declClass) . '::' . $m->name;
        if (isset($this->busy[$key])) { return null; }
        $this->busy[$key] = true;
        $r = $this->go($m->value, new TypeEnv([], $m->declClass, true, null), $d);
        unset($this->busy[$key]);
        return $r;
    }

    /**
     * @param string[] $classes
     */
    private function overClasses(array $classes, string $name, string $kind): ?SourceType
    {
        $parts = [];
        foreach ($classes as $c) {
            $t = $this->memberType($c, $name, $kind, $c);
            if ($t === null) { return null; }
            $parts[] = $t;
        }
        return \count($parts) === 0 ? null : self::union($parts);
    }

    /** Declared type of a member as seen through class $cls, `static` bound to $staticCls. */
    private function memberType(string $cls, string $name, string $kind, string $staticCls): ?SourceType
    {
        $m = $this->idx->lookupMember($cls, $name, $kind);
        if ($m === null) { return null; }
        $native = SourceTypeParser::fromHint($m->typeHint, $m->declClass);
        $doc = SourceTypeParser::fromHint($m->docType, $m->declClass);
        $t = SourceTypeParser::withDoc($native, $doc);
        if ($m->docType !== null && \str_contains($m->docType, '-of<')) { $t = $this->resolveOf($t, $m->declClass); }
        // A trait's `self` is the using class.
        $di = $this->idx->findClass($m->declClass);
        $selfCls = $di !== null && $di->kind === 'trait' ? $cls : $m->declClass;
        return self::bind($t, $selfCls, $staticCls, $this->idx);
    }

    // ── @template binding ─────────────────────────────────────────────

    /**
     * The call's result type with the callee's own `@template` names bound from its arguments: a `Foo::class`
     * or `class-string<Foo>` value for a `class-string<T>` parameter, the argument's type for a `T` one.
     * An unbound name takes its `of` bound, else the substituted type is unknown and $fallback stays.
     *
     * @param Expr[] $args
     */
    private function instantiate(TemplateSig $sig, array $args, TypeEnv $env, int $d, ?SourceType $native, ?SourceType $fallback, string $declClass): ?SourceType
    {
        if ($sig->ret === null) { return $fallback; }
        /** @var array<string, SourceType> $bound */
        $bound = [];
        /** @var array<string, SourceType> $argTypes `$name` → the argument's type, for a conditional return */
        $argTypes = [];
        $cond = \str_contains($sig->ret, ' is ');
        foreach ($args as $i => $arg) {
            if ($arg instanceof Spread || $arg instanceof Ellipsis) { break; }
            $name = $arg instanceof NamedArg ? $arg->name : ($sig->order[$i] ?? null);
            if ($name === null || (!$cond && !isset($sig->params[$name]))) { continue; }
            $got = $this->go($arg instanceof NamedArg ? $arg->value : $arg, $env, $d);
            if ($got === null) { continue; }
            $argTypes['$' . $name] = $got;
            $want = isset($sig->params[$name]) ? SourceTypeParser::fromHint($sig->params[$name], $declClass) : null;
            if ($want !== null) { $this->unify($want, $got, $sig, $bound); }
        }
        $rt = SourceTypeParser::fromHint($sig->ret, $declClass);
        if ($rt !== null && $cond) { $rt = self::reduceConditional($rt, $argTypes, $bound); }
        $t = self::subst($rt, $bound, $sig, $declClass);
        if ($t === null) { return $fallback; }
        if ($cond && $t->kind === SourceType::UNION) { $t = self::union($t->args); }
        $t = $this->resolveOf($t, $declClass);
        $core = $native !== null && $native->kind === SourceType::NULLABLE ? $native->args[0] : $native;
        // a class or loose native hint is what the template refines; a bare array / iterable takes the element types
        if ($core === null || $core->kind === SourceType::MIXED || $core->kind === SourceType::CLASS_) { return $t; }
        return $fallback === null ? $t : SourceTypeParser::withDoc($native, $t);
    }

    /**
     * @param array<string, SourceType> $bound
     */
    private function unify(SourceType $want, SourceType $got, TemplateSig $sig, array &$bound): void
    {
        if ($want->kind === SourceType::NULLABLE) {
            $this->unify($want->args[0], ExprTyper::hasNull($got) ? self::withoutNull($got) : $got, $sig, $bound);
            return;
        }
        if ($want->kind === SourceType::CLASS_) {
            if (isset($sig->templates[$want->name]) && !isset($bound[$want->name])) { $bound[$want->name] = $got; }
            return;
        }
        // `string|class-string<T>`: only a class-string argument binds T
        if ($want->kind === SourceType::UNION) {
            foreach ($want->args as $a) {
                if ($a->kind === SourceType::GENERIC && $a->name === 'class-string') { $this->unify($a, $got, $sig, $bound); }
            }
            return;
        }
        if ($want->kind === SourceType::GENERIC && $want->name === 'class-string') {
            if ($got->kind === SourceType::GENERIC && $got->name === 'class-string' && \count($got->args) === 1 && \count($want->args) === 1) {
                $this->unify($want->args[0], $got->args[0], $sig, $bound);
            }
            return;
        }
        $wl = $want->kind === SourceType::ITERABLE ? 'iterable' : ($want->kind === SourceType::GENERIC ? $want->name : '');
        if ($wl === 'array' || $wl === 'list' || $wl === 'iterable') {
            $gl = $got->kind === SourceType::GENERIC ? $got->name : '';
            if (($gl === 'array' || $gl === 'list' || $got->kind === SourceType::ITERABLE) && \count($got->args) > 0 && \count($want->args) > 0) {
                $this->unify($want->args[\count($want->args) - 1], $got->args[\count($got->args) - 1], $sig, $bound);
                if (\count($want->args) === 2 && \count($got->args) === 2) { $this->unify($want->args[0], $got->args[0], $sig, $bound); }
            }
        }
    }

    /**
     * `($x is TEST ? A : B)` → A when the argument (or template binding) named by the subject statically
     * satisfies TEST, B when it statically does not, else `A|B`. Nested conditionals reduce the same way.
     *
     * @param array<string, SourceType> $argTypes
     * @param array<string, SourceType> $bound
     */
    public static function reduceConditional(SourceType $t, array $argTypes, array $bound): SourceType
    {
        if ($t->kind === SourceType::CONDITIONAL) {
            $got = \str_starts_with($t->name, '$') ? ($argTypes[$t->name] ?? null) : ($bound[$t->name] ?? null);
            $hit = $got === null ? -1 : self::satisfies($got, $t->args[0]);
            $then = self::reduceConditional($t->args[1], $argTypes, $bound);
            $else = self::reduceConditional($t->args[2], $argTypes, $bound);
            if ($hit === 1) { return $then; }
            if ($hit === 0) { return $else; }
            return self::union([$then, $else]);
        }
        if ($t->args === [] && $t->ret === null) { return $t; }
        $args = [];
        foreach ($t->args as $a) { $args[] = self::reduceConditional($a, $argTypes, $bound); }
        $ret = $t->ret === null ? null : self::reduceConditional($t->ret, $argTypes, $bound);
        return new SourceType($t->kind, $t->name, $args, $t->resolved, $ret, $t->keys);
    }

    /** 1: `$got` is certainly a `$test`; 0: certainly not; -1: not statically known. */
    private static function satisfies(SourceType $got, SourceType $test): int
    {
        if ($test->kind === SourceType::GENERIC && $test->name === 'class-string') {
            if ($got->kind === SourceType::GENERIC && $got->name === 'class-string') { return 1; }
            if ($got->kind === SourceType::SCALAR && $got->name !== 'string' && $got->name !== 'scalar') { return 0; }
            return -1;
        }
        if ($test->kind === SourceType::SCALAR && $test->name === 'null') {
            if (self::isScalar($got, 'null')) { return 1; }
            if (self::hasNull($got)) { return -1; }
            $k = $got->kind;
            return $k === SourceType::CLASS_ || $k === SourceType::SCALAR || $k === SourceType::LITERAL || $k === SourceType::GENERIC || $k === SourceType::SHAPE ? 0 : -1;
        }
        $lits = $test->kind === SourceType::UNION ? $test->args : [$test];
        foreach ($lits as $l) { if ($l->kind !== SourceType::LITERAL) { return -1; } }
        if ($got->kind !== SourceType::LITERAL) { return -1; }
        foreach ($lits as $l) { if ($l->name === $got->name) { return 1; } }
        return 0;
    }

    /**
     * Replaces each template name in $t by its binding, else its bound; null when one has neither.
     *
     * @param array<string, SourceType> $bound
     */
    private static function subst(?SourceType $t, array $bound, TemplateSig $sig, string $declClass): ?SourceType
    {
        if ($t === null) { return null; }
        if ($t->kind === SourceType::CLASS_ && isset($sig->templates[$t->name])) {
            if (isset($bound[$t->name])) { return $bound[$t->name]; }
            return $sig->templates[$t->name] === '' ? null : SourceTypeParser::fromHint($sig->templates[$t->name], $declClass);
        }
        if ($t->args === [] && $t->ret === null) { return $t; }
        $args = [];
        foreach ($t->args as $a) {
            $s = self::subst($a, $bound, $sig, $declClass);
            if ($s === null) { return null; }
            $args[] = $s;
        }
        $ret = null;
        if ($t->ret !== null) {
            $ret = self::subst($t->ret, $bound, $sig, $declClass);
            if ($ret === null) { return null; }
        }
        return new SourceType($t->kind, $t->name, $args, $t->resolved, $ret, $t->keys);
    }

    /**
     * `key-of<X>` / `value-of<X>` over a known array type (generic, list, shape, or a class constant of one)
     * become the key / value type; any other X leaves the whole type unknown.
     */
    private function resolveOf(?SourceType $t, string $declClass): ?SourceType
    {
        if ($t === null) { return null; }
        if ($t->kind === SourceType::GENERIC && ($t->name === 'key-of' || $t->name === 'value-of')) {
            if (\count($t->args) !== 1) { return null; }
            $x = $t->args[0];
            if ($x->kind === SourceType::CONST_REF) { $x = $this->constRefType($x->name, $declClass); }
            else { $x = $this->resolveOf($x, $declClass); }
            return $x === null ? null : self::keyOrValue($x, $t->name === 'key-of');
        }
        if ($t->args === [] && $t->ret === null) { return $t; }
        $args = [];
        foreach ($t->args as $a) {
            $r = $this->resolveOf($a, $declClass);
            if ($r === null) { return null; }
            $args[] = $r;
        }
        $ret = null;
        if ($t->ret !== null) {
            $ret = $this->resolveOf($t->ret, $declClass);
            if ($ret === null) { return null; }
        }
        return new SourceType($t->kind, $t->name, $args, $t->resolved, $ret, $t->keys);
    }

    private function constRefType(string $ref, string $declClass): ?SourceType
    {
        $p = \strpos($ref, '::');
        if ($p === false) { return null; }
        $cls = \substr($ref, 0, $p);
        $low = \strtolower($cls);
        if ($low === 'self' || $low === 'static') { $cls = $declClass; }
        $name = \substr($ref, $p + 2);
        if ($cls === '' || $name === '' || \str_contains($name, '*')) { return null; }
        $key = \strtolower($ref) . '|of';
        if (isset($this->busy[$key])) { return null; }
        $this->busy[$key] = true;
        $r = $this->memberType(\ltrim($cls, '\\'), $name, 'const', \ltrim($cls, '\\'));
        unset($this->busy[$key]);
        return $r;
    }

    private static function keyOrValue(SourceType $x, bool $key): ?SourceType
    {
        if ($x->kind === SourceType::SHAPE) {
            if (\count($x->args) === 0 || $x->resolved === '...') { return null; }
            $parts = [];
            foreach ($x->args as $i => $a) {
                $parts[] = $key ? new SourceType(SourceType::LITERAL, self::isIntKey(\rtrim($x->keys[$i], SourceType::OPT)) ? \rtrim($x->keys[$i], SourceType::OPT) : "'" . \rtrim($x->keys[$i], SourceType::OPT) . "'") : $a;
            }
            return self::union($parts);
        }
        if ($x->kind === SourceType::GENERIC && ($x->name === 'array' || $x->name === 'list' || $x->name === 'non-empty-array' || $x->name === 'non-empty-list')) {
            $n = \count($x->args);
            if ($n === 0) { return null; }
            if (!$key) { return $x->args[$n - 1]; }
            return $n === 2 ? $x->args[0] : self::scalar('int');
        }
        return null;
    }

    /** Class FQN a written class reference means here, or null. */
    public function classRef(string $written, TypeEnv $env): ?string
    {
        $low = \strtolower($written);
        if ($low === 'self' || $low === 'static') { return $env->thisClass; }
        if ($low === 'parent') { return $env->parentClass; }
        return \ltrim($written, '\\');
    }

    private function classType(string $written, TypeEnv $env): ?SourceType
    {
        $c = $this->classRef($written, $env);
        return $c === null ? null : new SourceType(SourceType::CLASS_, $c);
    }

    /**
     * Every class a receiver may be (null part dropped), or null when any part is not a class.
     *
     * @return string[]|null
     */
    public function classesOf(?SourceType $t, TypeEnv $env): ?array
    {
        if ($t === null) { return null; }
        switch ($t->kind) {
            case SourceType::CLASS_:
                return [\ltrim($t->name, '\\')];
            case SourceType::SELF:
            case SourceType::STATIC:
                $c = $t->resolved !== '' ? $t->resolved : $env->thisClass;
                return $c === null ? null : [$c];
            case SourceType::PARENT:
                return $env->parentClass === null ? null : [$env->parentClass];
            case SourceType::NULLABLE:
                return $this->classesOf($t->args[0], $env);
            case SourceType::UNION:
                $out = [];
                foreach ($t->args as $a) {
                    if ($a->kind === SourceType::SCALAR && $a->name === 'null') { continue; }
                    $cs = $this->classesOf($a, $env);
                    if ($cs === null) { return null; }
                    foreach ($cs as $c) { $out[] = $c; }
                }
                return \count($out) === 0 ? null : $out;
            default:
                return null;
        }
    }

    /**
     * Replace self / static / parent by concrete classes; null when one cannot be resolved.
     */
    public static function bind(?SourceType $t, string $selfCls, string $staticCls, Index $idx): ?SourceType
    {
        if ($t === null) { return null; }
        switch ($t->kind) {
            case SourceType::SELF:
                $c = $t->resolved !== '' && $selfCls === '' ? $t->resolved : $selfCls;
                return $c === '' ? null : new SourceType(SourceType::CLASS_, $c);
            case SourceType::STATIC:
                $c = $staticCls !== '' ? $staticCls : $selfCls;
                return $c === '' ? null : new SourceType(SourceType::CLASS_, $c);
            case SourceType::PARENT:
                $ci = $selfCls === '' ? null : $idx->findClass($selfCls);
                if ($ci === null || \count($ci->parents) === 0) { return null; }
                return new SourceType(SourceType::CLASS_, \ltrim($ci->parents[0], '\\'));
            case SourceType::NULLABLE:
                $in = self::bind($t->args[0], $selfCls, $staticCls, $idx);
                return $in === null ? null : new SourceType(SourceType::NULLABLE, '', [$in]);
            case SourceType::UNION:
                $parts = [];
                foreach ($t->args as $a) {
                    $b = self::bind($a, $selfCls, $staticCls, $idx);
                    if ($b === null) { return null; }
                    $parts[] = $b;
                }
                return new SourceType(SourceType::UNION, '', $parts);
            default:
                return $t;
        }
    }

    // ── operators ─────────────────────────────────────────────────────

    private function ternary(Ternary $e, TypeEnv $env, int $d): ?SourceType
    {
        $else = $this->go($e->else, $env, $d);
        if ($else === null) { return null; }
        $then = $e->then === null ? $this->go($e->condition, $env, $d) : $this->go($e->then, $env, $d);
        if ($then === null) { return null; }
        if ($e->then === null && self::hasNull($then)) { $then = self::withoutNull($then); }
        return self::union([$then, $else]);
    }

    private function compound(CompoundAssign $e, TypeEnv $env, int $d): ?SourceType
    {
        $op = \substr($e->op, 0, -1);
        $l = $this->go($e->target, $env, $d);
        $r = $this->go($e->value, $env, $d);
        if ($op === '??') {
            if ($l === null || $r === null) { return null; }
            return self::hasNull($l) || self::maybeUnset($e->target) ? self::union([self::withoutNull($l), $r]) : $l;
        }
        return self::binary($op, $l, $r);
    }

    private function unary(UnaryOp $e, TypeEnv $env, int $d): ?SourceType
    {
        $op = $e->op;
        if ($op === '!') { return self::scalar('bool'); }
        if ($op === 'print') { return self::scalar('int'); }
        if ($op === 'throw') { return new SourceType(SourceType::NEVER, 'never'); }
        $o = $this->go($e->operand, $env, $d);
        if ($op === '@') { return $o; }
        if ($op === '~') { return self::bitwise($o, $o); }
        if (($op === '-' || $op === '+') && self::isNum($o)) { return $o; }
        return null;
    }

    private function cast(Cast $e, TypeEnv $env, int $d): ?SourceType
    {
        switch (\strtolower($e->cast)) {
            case 'int': case 'integer': return self::scalar('int');
            case 'float': case 'double': case 'real': return self::scalar('float');
            case 'string': case 'binary': return self::scalar('string');
            case 'bool': case 'boolean': return self::scalar('bool');
            case 'array': return self::scalar('array');
            case 'object':
                $o = $this->go($e->operand, $env, $d);
                if ($o === null) { return null; }
                if ($o->kind === SourceType::CLASS_) { return $o; }
                $arrayish = $o->kind === SourceType::GENERIC && ($o->name === 'array' || $o->name === 'list');
                return $o->kind === SourceType::SCALAR || $arrayish ? new SourceType(SourceType::CLASS_, 'stdClass') : null;
            case 'unset': return self::scalar('null');
            default: return null;
        }
    }

    private static function binary(string $op, ?SourceType $l, ?SourceType $r): ?SourceType
    {
        switch ($op) {
            case '.':
                return self::scalar('string');
            case '%': case '<<': case '>>': case '<=>':
                return self::scalar('int');
            case '==': case '===': case '!=': case '!==': case '<>': case '<': case '>': case '<=': case '>=':
            case '&&': case '||': case 'and': case 'or': case 'xor':
                return self::scalar('bool');
            case '&': case '|': case '^':
                return self::bitwise($l, $r);
            case '+': case '-': case '*':
                if ($op === '+' && self::isArray($l) && self::isArray($r)) { return self::scalar('array'); }
                if (!self::isNum($l) || !self::isNum($r)) { return null; }
                return self::scalar($l->name === 'int' && $r->name === 'int' ? 'int' : 'float');
            case '/': case '**':
                if (!self::isNum($l) || !self::isNum($r)) { return null; }
                if ($l->name === 'float' || $r->name === 'float') { return self::scalar('float'); }
                return new SourceType(SourceType::UNION, '', [self::scalar('int'), self::scalar('float')]);
            default:
                return null;
        }
    }

    /** `&` `|` `^` `~`: byte-wise over two strings, else integer; unknown while either may be a string. */
    private static function bitwise(?SourceType $l, ?SourceType $r): ?SourceType
    {
        if (self::isScalar($l, 'string') && self::isScalar($r, 'string')) { return self::scalar('string'); }
        if (self::isNum($l) || self::isScalar($l, 'bool') || self::isNum($r) || self::isScalar($r, 'bool')) { return self::scalar('int'); }
        return null;
    }

    // ── arrays ────────────────────────────────────────────────────────

    private function arrayLit(ArrayLit $e, TypeEnv $env, int $d): SourceType
    {
        if (\count($e->elements) === 0) { return self::scalar('array'); }
        $vals = [];
        $keys = [];
        $keyed = false;
        foreach ($e->elements as $el) {
            $v = $this->go($el->value, $env, $d);
            if ($v === null || $el->byRef) { return self::scalar('array'); }
            $vals[] = $v;
            if ($el->key !== null) {
                $k = $el->key instanceof StringLiteral && self::isIntKey($el->key->value) ? self::scalar('int') : $this->go($el->key, $env, $d);
                if ($k === null) { return self::scalar('array'); }
                $keyed = true;
                $keys[] = $k;
            } else {
                $keys[] = self::scalar('int');
            }
        }
        $shape = $this->literalShape($e, $vals);
        if ($shape !== null) { return $shape; }
        $v = self::union($vals);
        if (!$keyed) { return new SourceType(SourceType::GENERIC, 'list', [$v]); }
        return new SourceType(SourceType::GENERIC, 'array', [self::union($keys), $v]);
    }

    /**
     * `array{a: T, …}` when every element has a distinct string-literal key and there are at most SHAPE_MAX of them.
     *
     * @param SourceType[] $vals
     */
    private function literalShape(ArrayLit $e, array $vals): ?SourceType
    {
        if (\count($e->elements) > self::SHAPE_MAX) { return null; }
        $keys = [];
        $seen = [];
        foreach ($e->elements as $el) {
            if (!($el->key instanceof StringLiteral) || self::isIntKey($el->key->value) || $el->key->value === '' || \str_ends_with($el->key->value, '?')
                || isset($seen['k:' . $el->key->value])) { return null; }
            $seen['k:' . $el->key->value] = true;
            $keys[] = $el->key->value;
        }
        return new SourceType(SourceType::SHAPE, 'array', $vals, '', null, $keys);
    }

    /** PHP stores a decimal-integer string key as an int key. */
    public static function isIntKey(string $k): bool
    {
        if (\preg_match('/^(0|-?[1-9][0-9]*)\z/', $k) !== 1) { return false; }
        $neg = $k[0] === '-';
        $digits = $neg ? \substr($k, 1) : $k;
        if (\strlen($digits) !== 19) { return \strlen($digits) < 19; }
        return \strcmp($digits, $neg ? '9223372036854775808' : '9223372036854775807') <= 0;
    }

    private function arrayAccess(ArrayAccess $e, TypeEnv $env, int $d): ?SourceType
    {
        $b = $this->go($e->array, $env, $d);
        if ($b === null || $e->index === null) { return null; }
        if ($b->kind === SourceType::SHAPE) {
            $k = $e->index instanceof StringLiteral ? $e->index->value : null;
            $parts = [];
            foreach ($b->args as $i => $a) {
                if ($k === null || \rtrim($b->keys[$i], SourceType::OPT) === $k) { $parts[] = $a; }
            }
            return \count($parts) === 0 ? null : self::union($parts);
        }
        if ($b->kind === SourceType::GENERIC && ($b->name === 'array' || $b->name === 'list') && \count($b->args) > 0) {
            return $b->args[\count($b->args) - 1];
        }
        return null;
    }

    private static function closure(): SourceType
    {
        return new SourceType(SourceType::CLASS_, 'Closure');
    }

    /**
     * @param Expr[] $args
     */
    private static function isFcc(array $args): bool
    {
        return \count($args) === 1 && $args[0] instanceof Ellipsis;
    }

    /** Does a `?->` earlier in the chain short-circuit the whole expression to null? */
    private static function chainHasNullsafe(Expr $o): bool
    {
        while (true) {
            if ($o instanceof PropertyAccess || $o instanceof MethodCallExpr) {
                if ($o->nullsafe) { return true; }
                $o = $o->object;
            } elseif ($o instanceof ArrayAccess) {
                $o = $o->array;
            } else {
                return false;
            }
        }
    }

    /** Reads that may find nothing at all (missing key, unset or uninitialized property), whatever their declared type. */
    private static function maybeUnset(Expr $e): bool
    {
        return $e instanceof ArrayAccess || $e instanceof PropertyAccess || $e instanceof DynProp
            || ($e instanceof StaticAccess && $e->name !== '' && $e->name[0] === '$');
    }

    // ── type algebra ──────────────────────────────────────────────────

    public static function scalar(string $name): SourceType
    {
        return new SourceType(SourceType::SCALAR, $name);
    }

    private static function isScalar(?SourceType $t, string $name): bool
    {
        return $t !== null && $t->kind === SourceType::SCALAR && $t->name === $name;
    }

    private static function isNum(?SourceType $t): bool
    {
        return $t !== null && $t->kind === SourceType::SCALAR && ($t->name === 'int' || $t->name === 'float');
    }

    private static function isArray(?SourceType $t): bool
    {
        if ($t === null) { return false; }
        return self::isScalar($t, 'array') || $t->kind === SourceType::SHAPE || ($t->kind === SourceType::GENERIC && ($t->name === 'array' || $t->name === 'list'));
    }

    public static function hasNull(SourceType $t): bool
    {
        if ($t->kind === SourceType::NULLABLE || $t->kind === SourceType::MIXED) { return true; }
        if (self::isScalar($t, 'null')) { return true; }
        if ($t->kind === SourceType::UNION) {
            foreach ($t->args as $a) { if (self::isScalar($a, 'null')) { return true; } }
        }
        return false;
    }

    /** $t minus its null part; a bare `null` stays as is (nothing left to keep). */
    public static function withoutNull(SourceType $t): SourceType
    {
        if ($t->kind === SourceType::NULLABLE) { return $t->args[0]; }
        if ($t->kind !== SourceType::UNION) { return $t; }
        $rest = [];
        foreach ($t->args as $a) { if (!self::isScalar($a, 'null')) { $rest[] = $a; } }
        return self::union($rest);
    }

    /**
     * Flattened, de-duplicated union in order of first appearance; `never` parts vanish, `mixed` absorbs all.
     *
     * @param SourceType[] $parts
     */
    public static function union(array $parts): SourceType
    {
        /** @var SourceType[] $flat */
        $flat = [];
        $seen = [];
        $sawNull = false;
        foreach ($parts as $p) {
            if ($p->kind === SourceType::MIXED) { return $p; }
            $members = $p->kind === SourceType::NULLABLE ? [$p->args[0], self::scalar('null')]
                : ($p->kind === SourceType::UNION ? $p->args : [$p]);
            foreach ($members as $m) {
                if ($m->kind === SourceType::NEVER) { continue; }
                if (self::isScalar($m, 'null')) { $sawNull = true; continue; }
                $k = 't:' . $m->toString();
                if (isset($seen[$k])) { continue; }
                $seen[$k] = true;
                $flat[] = $m;
            }
        }
        if (\count($flat) === 0) { return $sawNull ? self::scalar('null') : new SourceType(SourceType::NEVER, 'never'); }
        if (\count($flat) === 1) {
            if (!$sawNull) { return $flat[0]; }
            return $flat[0]->kind === SourceType::INTERSECTION
                ? new SourceType(SourceType::UNION, '', [$flat[0], self::scalar('null')])
                : new SourceType(SourceType::NULLABLE, '', [$flat[0]]);
        }
        if ($sawNull) { $flat[] = self::scalar('null'); }
        return new SourceType(SourceType::UNION, '', $flat);
    }
}
