<?php

namespace Semantic;

use Analyze\Index;
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
use Parser\Ast\NewExpr;
use Parser\Ast\NullCoalesce;
use Parser\Ast\NullLiteral;
use Parser\Ast\PropertyAccess;
use Parser\Ast\RefAssign;
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
        if ($e instanceof StaticCall) { return $this->staticCall($e, $env); }
        if ($e instanceof StaticAccess) { return $this->staticAccess($e, $env, $d); }
        if ($e instanceof CallExpr) { return $this->call($e); }
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

    private function call(CallExpr $e): ?SourceType
    {
        if (self::isFcc($e->args)) { return self::closure(); }
        $l = \strtolower($e->function);
        if ($l === 'isset' || $l === 'empty') { return self::scalar('bool'); }
        $fn = $this->idx->resolveFunction($e->function);
        if ($fn === null) { return null; }
        $native = SourceTypeParser::fromHint($fn->returnType, '');
        $doc = SourceTypeParser::fromHint($fn->docType, '');
        return SourceTypeParser::withDoc($native, $doc);
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
        return $t !== null && ($e->nullsafe || self::chainHasNullsafe($e->object)) ? self::union([$t, self::scalar('null')]) : $t;
    }

    private function staticCall(StaticCall $e, TypeEnv $env): ?SourceType
    {
        if (self::isFcc($e->args)) { return self::closure(); }
        $cls = $this->classRef($e->class, $env);
        if ($cls === null) { return null; }
        $low = \strtolower($e->class);
        // self:: / parent:: forward the late-static class; Foo:: pins it.
        $static = ($low === 'self' || $low === 'parent' || $low === 'static') && $env->thisClass !== null ? $env->thisClass : $cls;
        return $this->memberType($cls, $e->method, 'method', $static);
    }

    private function staticAccess(StaticAccess $e, TypeEnv $env, int $d): ?SourceType
    {
        if ($e->name === 'class') { return new SourceType(SourceType::GENERIC, 'class-string'); }
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
        // A trait's `self` is the using class.
        $di = $this->idx->findClass($m->declClass);
        $selfCls = $di !== null && $di->kind === 'trait' ? $cls : $m->declClass;
        return self::bind($t, $selfCls, $staticCls, $this->idx);
    }

    /** Class FQN a written class reference means here, or null. */
    private function classRef(string $written, TypeEnv $env): ?string
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
    private function classesOf(?SourceType $t, TypeEnv $env): ?array
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
                $k = $this->go($el->key, $env, $d);
                if ($k === null) { return self::scalar('array'); }
                $keyed = true;
                $keys[] = $k;
            } else {
                $keys[] = self::scalar('int');
            }
        }
        $v = self::union($vals);
        if (!$keyed) { return new SourceType(SourceType::GENERIC, 'list', [$v]); }
        return new SourceType(SourceType::GENERIC, 'array', [self::union($keys), $v]);
    }

    private function arrayAccess(ArrayAccess $e, TypeEnv $env, int $d): ?SourceType
    {
        $b = $this->go($e->array, $env, $d);
        if ($b === null || $e->index === null) { return null; }
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

    private static function scalar(string $name): SourceType
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
        return self::isScalar($t, 'array') || ($t->kind === SourceType::GENERIC && ($t->name === 'array' || $t->name === 'list'));
    }

    private static function hasNull(SourceType $t): bool
    {
        if ($t->kind === SourceType::NULLABLE || $t->kind === SourceType::MIXED) { return true; }
        if (self::isScalar($t, 'null')) { return true; }
        if ($t->kind === SourceType::UNION) {
            foreach ($t->args as $a) { if (self::isScalar($a, 'null')) { return true; } }
        }
        return false;
    }

    /** $t minus its null part; a bare `null` stays as is (nothing left to keep). */
    private static function withoutNull(SourceType $t): SourceType
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
    private static function union(array $parts): SourceType
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
