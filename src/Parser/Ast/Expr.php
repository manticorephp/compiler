<?php

namespace Parser\Ast;

/**
 * Expression node — abstract root of the tagged hierarchy.
 *
 * Each PHP-source expression form lands in its own final subclass.
 * Consumers reach into named, typed properties; the `kind` string
 * survives so the dump format and string-switch dispatch keep
 * working during the migration.
 */
abstract class Expr
{
    public function __construct(
        public readonly string $kind,
        public readonly Span $span,
    ) {}

    // ── Construction helpers ──────────────────────────────────────
    //
    // Short-name static factories kept around so the parser
    // continues to read like `Expr::binary($op, $l, $r, $span)`;
    // each forwards to the matching concrete subclass.

    public static function int(int $value, Span $span): IntLiteral
    {
        return new IntLiteral($value, $span);
    }

    public static function float(float $value, Span $span): FloatLiteral
    {
        return new FloatLiteral($value, $span);
    }

    public static function string(string $value, Span $span): StringLiteral
    {
        return new StringLiteral($value, $span);
    }

    public static function bool(bool $value, Span $span): BoolLiteral
    {
        return new BoolLiteral($value, $span);
    }

    public static function null(Span $span): NullLiteral
    {
        return new NullLiteral($span);
    }

    public static function variable(string $name, Span $span): Variable
    {
        return new Variable($name, $span);
    }

    public static function identifier(string $name, Span $span): Identifier
    {
        return new Identifier($name, $span);
    }

    public static function magicConstant(string $name, Span $span): MagicConstant
    {
        return new MagicConstant(strtoupper($name), $span);
    }

    public static function binary(string $op, Expr $left, Expr $right, Span $span): BinaryOp
    {
        return new BinaryOp($op, $left, $right, $span);
    }

    public static function unary(string $op, Expr $operand, Span $span): UnaryOp
    {
        return new UnaryOp($op, $operand, $span);
    }

    public static function ternary(Expr $condition, ?Expr $then, Expr $else, Span $span): Ternary
    {
        return new Ternary($condition, $then, $else, $span);
    }

    public static function nullCoalesce(Expr $left, Expr $right, Span $span): NullCoalesce
    {
        return new NullCoalesce($left, $right, $span);
    }

    public static function cast(string $cast, Expr $operand, Span $span): Cast
    {
        return new Cast($cast, $operand, $span);
    }

    public static function instanceof_(Expr $operand, string $class, Span $span): InstanceofExpr
    {
        return new InstanceofExpr($operand, $class, $span);
    }

    public static function assign(Expr $target, Expr $value, Span $span): Assign
    {
        return new Assign($target, $value, $span);
    }

    public static function compoundAssign(string $op, Expr $target, Expr $value, Span $span): CompoundAssign
    {
        return new CompoundAssign($op, $target, $value, $span);
    }

    public static function refAssign(Expr $target, Expr $source, Span $span): RefAssign
    {
        return new RefAssign($target, $source, $span);
    }

    public static function incDec(string $op, bool $prefix, Expr $operand, Span $span): IncDec
    {
        return new IncDec($op, $prefix, $operand, $span);
    }

    /** @param ArrayElement[] $elements */
    public static function arrayLit(array $elements, Span $span): ArrayLit
    {
        return new ArrayLit($elements, $span);
    }

    public static function arrayAccess(Expr $array, ?Expr $index, Span $span): ArrayAccess
    {
        return new ArrayAccess($array, $index, $span);
    }

    /** @param Expr[] $args */
    public static function call(string $function, array $args, Span $span): CallExpr
    {
        return new CallExpr($function, $args, $span);
    }

    /** @param Expr[] $args */
    public static function methodCall(Expr $object, string $method, array $args, bool $nullsafe, Span $span): MethodCallExpr
    {
        return new MethodCallExpr($object, $method, $args, $nullsafe, $span);
    }

    public static function propertyAccess(Expr $object, string $property, bool $nullsafe, Span $span): PropertyAccess
    {
        return new PropertyAccess($object, $property, $nullsafe, $span);
    }

    public static function dynProp(Expr $object, Expr $name, bool $nullsafe, Span $span): DynProp
    {
        return new DynProp($object, $name, $nullsafe, $span);
    }

    /** @param Expr[] $args */
    public static function staticCall(string $class, string $method, array $args, Span $span): StaticCall
    {
        return new StaticCall($class, $method, $args, $span);
    }

    public static function staticAccess(string $class, string $name, Span $span): StaticAccess
    {
        return new StaticAccess($class, $name, $span);
    }

    /** @param Expr[] $args */
    public static function new_(string $class, array $args, Span $span): NewExpr
    {
        return new NewExpr($class, $args, $span);
    }

    /** @param Expr[] $args */
    public static function invoke(Expr $callee, array $args, Span $span): Invoke
    {
        return new Invoke($callee, $args, $span);
    }

    public static function clone_(Expr $object, ?Expr $withProps, Span $span): CloneExpr
    {
        return new CloneExpr($object, $withProps, $span);
    }

    /** @param Param[] $params */
    public static function arrowFn(bool $isStatic, array $params, ?string $returnType, Expr $body, Span $span): ArrowFn
    {
        return new ArrowFn($isStatic, $params, $returnType, $body, $span);
    }

    /**
     * @param Param[]      $params
     * @param ClosureUse[] $uses
     */
    public static function closure(bool $isStatic, array $params, array $uses, ?string $returnType, Block $body, Span $span): Closure
    {
        return new Closure($isStatic, $params, $uses, $returnType, $body, $span);
    }

    /** @param MatchArm[] $arms */
    public static function match_(Expr $subject, array $arms, Span $span): MatchExpr
    {
        return new MatchExpr($subject, $arms, $span);
    }

    public static function namedArg(string $name, Expr $value, Span $span): NamedArg
    {
        return new NamedArg($name, $value, $span);
    }

    public static function ellipsis(Span $span): Ellipsis
    {
        return new Ellipsis($span);
    }

    public static function spread(Expr $value, Span $span): Spread
    {
        return new Spread($value, $span);
    }

    public static function yield_(?Expr $key, ?Expr $value, bool $from, Span $span): YieldExpr
    {
        return new YieldExpr($key, $value, $from, $span);
    }
}

// ── Literals ───────────────────────────────────────────────────────────────

final class IntLiteral extends Expr
{
    public function __construct(public readonly int $value, Span $span)
    {
        parent::__construct('IntLiteral', $span);
    }
}

final class FloatLiteral extends Expr
{
    public function __construct(public readonly float $value, Span $span)
    {
        parent::__construct('FloatLiteral', $span);
    }
}

final class StringLiteral extends Expr
{
    public function __construct(public readonly string $value, Span $span)
    {
        parent::__construct('StringLiteral', $span);
    }
}

final class BoolLiteral extends Expr
{
    public function __construct(public readonly bool $value, Span $span)
    {
        parent::__construct('BoolLiteral', $span);
    }
}

final class NullLiteral extends Expr
{
    public function __construct(Span $span)
    {
        parent::__construct('NullLiteral', $span);
    }
}

// ── Names & references ────────────────────────────────────────────────────

final class Variable extends Expr
{
    public function __construct(public readonly string $name, Span $span)
    {
        parent::__construct('Variable', $span);
    }
}

final class Identifier extends Expr
{
    public function __construct(public readonly string $name, Span $span)
    {
        parent::__construct('Identifier', $span);
    }
}

final class MagicConstant extends Expr
{
    public function __construct(public readonly string $name, Span $span)
    {
        parent::__construct('MagicConstant', $span);
    }
}

// ── Arithmetic / logic ─────────────────────────────────────────────────────

final class BinaryOp extends Expr
{
    public function __construct(
        public readonly string $op,
        public readonly Expr $left,
        public readonly Expr $right,
        Span $span,
    ) {
        parent::__construct('BinaryOp', $span);
    }
}

final class UnaryOp extends Expr
{
    public function __construct(
        public readonly string $op,
        public readonly Expr $operand,
        Span $span,
    ) {
        parent::__construct('UnaryOp', $span);
    }
}

final class Ternary extends Expr
{
    public function __construct(
        public readonly Expr $condition,
        public readonly ?Expr $then,  // null for short ternary `?:`
        public readonly Expr $else,
        Span $span,
    ) {
        parent::__construct('Ternary', $span);
    }
}

final class NullCoalesce extends Expr
{
    public function __construct(
        public readonly Expr $left,
        public readonly Expr $right,
        Span $span,
    ) {
        parent::__construct('NullCoalesce', $span);
    }
}

final class Cast extends Expr
{
    public function __construct(
        public readonly string $cast,
        public readonly Expr $operand,
        Span $span,
    ) {
        parent::__construct('Cast', $span);
    }
}

final class InstanceofExpr extends Expr
{
    public function __construct(
        public readonly Expr $operand,
        public readonly string $class,
        Span $span,
    ) {
        parent::__construct('Instanceof', $span);
    }
}

// ── Assignment forms ───────────────────────────────────────────────────────

final class Assign extends Expr
{
    public function __construct(
        public readonly Expr $target,
        public readonly Expr $value,
        Span $span,
    ) {
        parent::__construct('Assign', $span);
    }
}

final class CompoundAssign extends Expr
{
    public function __construct(
        public readonly string $op,
        public readonly Expr $target,
        public readonly Expr $value,
        Span $span,
    ) {
        parent::__construct('CompoundAssign', $span);
    }
}

final class RefAssign extends Expr
{
    public function __construct(
        public readonly Expr $target,
        public readonly Expr $source,
        Span $span,
    ) {
        parent::__construct('RefAssign', $span);
    }
}

final class IncDec extends Expr
{
    public function __construct(
        public readonly string $op,
        public readonly bool $prefix,
        public readonly Expr $operand,
        Span $span,
    ) {
        parent::__construct('IncDec', $span);
    }
}

// ── Arrays ────────────────────────────────────────────────────────────────

final class ArrayLit extends Expr
{
    /** @param ArrayElement[] $elements */
    public function __construct(
        public readonly array $elements,
        Span $span,
    ) {
        parent::__construct('ArrayLit', $span);
    }
}

/** One element of an array literal. `key` is null for positional. */
final class ArrayElement
{
    public function __construct(
        public readonly ?Expr $key,
        public readonly Expr $value,
    ) {}
}

final class ArrayAccess extends Expr
{
    public function __construct(
        public readonly Expr $array,
        public readonly ?Expr $index,  // null = bare `$arr[]` push slot
        Span $span,
    ) {
        parent::__construct('ArrayAccess', $span);
    }
}

// ── Calls / member access ─────────────────────────────────────────────────

final class CallExpr extends Expr
{
    /** @param Expr[] $args */
    public function __construct(
        public readonly string $function,
        public readonly array $args,
        Span $span,
    ) {
        parent::__construct('Call', $span);
    }
}

final class MethodCallExpr extends Expr
{
    /** @param Expr[] $args */
    public function __construct(
        public readonly Expr $object,
        public readonly string $method,
        public readonly array $args,
        public readonly bool $nullsafe,
        Span $span,
    ) {
        parent::__construct('MethodCall', $span);
    }
}

final class PropertyAccess extends Expr
{
    public function __construct(
        public readonly Expr $object,
        public readonly string $property,
        public readonly bool $nullsafe,
        Span $span,
    ) {
        parent::__construct('PropertyAccess', $span);
    }
}

/** `$obj->$name` — property whose name is a runtime expression. */
final class DynProp extends Expr
{
    public function __construct(
        public readonly Expr $object,
        public readonly Expr $name,
        public readonly bool $nullsafe,
        Span $span,
    ) {
        parent::__construct('DynProp', $span);
    }
}

final class StaticCall extends Expr
{
    /** @param Expr[] $args */
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public readonly array $args,
        Span $span,
    ) {
        parent::__construct('StaticCall', $span);
    }
}

final class StaticAccess extends Expr
{
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        Span $span,
    ) {
        parent::__construct('StaticAccess', $span);
    }
}

/**
 * `new $cls(args)` — the class is named by a value at runtime, not written in
 * the source. The expression must evaluate to a class-name string.
 */
final class NewDynExpr extends Expr
{
    /** @param Expr[] $args */
    public function __construct(
        public readonly Expr $classExpr,
        public readonly array $args,
        Span $span,
    ) {
        parent::__construct('NewDyn', $span);
    }
}

final class NewExpr extends Expr
{
    /** @param Expr[] $args */
    public function __construct(
        public readonly string $class,
        public readonly array $args,
        Span $span,
    ) {
        parent::__construct('New', $span);
    }
}

final class Invoke extends Expr
{
    /** @param Expr[] $args */
    public function __construct(
        public readonly Expr $callee,
        public readonly array $args,
        Span $span,
    ) {
        parent::__construct('Invoke', $span);
    }
}

final class CloneExpr extends Expr
{
    public function __construct(
        public readonly Expr $object,
        public readonly ?Expr $withProps,
        Span $span,
    ) {
        parent::__construct('Clone', $span);
    }
}

// ── Closures & match ──────────────────────────────────────────────────────

final class ArrowFn extends Expr
{
    /** @param Param[] $params */
    public function __construct(
        public readonly bool $isStatic,
        public readonly array $params,
        public readonly ?string $returnType,
        public readonly Expr $body,
        Span $span,
    ) {
        parent::__construct('ArrowFn', $span);
    }
}

final class Closure extends Expr
{
    /**
     * @param Param[]      $params
     * @param ClosureUse[] $uses
     */
    public function __construct(
        public readonly bool $isStatic,
        public readonly array $params,
        public readonly array $uses,
        public readonly ?string $returnType,
        public readonly Block $body,
        Span $span,
    ) {
        parent::__construct('Closure', $span);
    }
}

/** One `use ($x)` / `use (&$x)` slot in a closure. */
final class ClosureUse
{
    public function __construct(
        public readonly string $name,
        public readonly bool $byRef,
    ) {}
}

final class MatchExpr extends Expr
{
    /** @param MatchArm[] $arms */
    public function __construct(
        public readonly Expr $subject,
        public readonly array $arms,
        Span $span,
    ) {
        parent::__construct('Match', $span);
    }
}

/** One arm of `match (subject) { conds => body, default => body }`. */
final class MatchArm
{
    /** @param Expr[]|null $conds  null marks the `default` arm */
    public function __construct(
        public readonly ?array $conds,
        public readonly Expr $body,
    ) {}
}

// ── Call-list specials ────────────────────────────────────────────────────

final class NamedArg extends Expr
{
    public function __construct(
        public readonly string $name,
        public readonly Expr $value,
        Span $span,
    ) {
        parent::__construct('NamedArg', $span);
    }
}

/** `...` placeholder inside `f(...)` for first-class callable syntax. */
final class Ellipsis extends Expr
{
    public function __construct(Span $span)
    {
        parent::__construct('Ellipsis', $span);
    }
}

/** `...$args` spread inside a call or an array literal. */
final class Spread extends Expr
{
    public function __construct(
        public readonly Expr $value,
        Span $span,
    ) {
        parent::__construct('Spread', $span);
    }
}

/**
 * `yield`, `yield $v`, `yield $k => $v`, or `yield from $v`. A function
 * whose body contains one is a generator (detected at lowering). `value`
 * is null for a bare `yield;`; `key` is null unless `$k => $v`; `from`
 * marks delegation (`yield from`, lowered in a later stage).
 */
final class YieldExpr extends Expr
{
    public function __construct(
        public readonly ?Expr $key,
        public readonly ?Expr $value,
        public readonly bool $from,
        Span $span,
    ) {
        parent::__construct('Yield', $span);
    }
}

// ── Dynamic class targets (`$obj::class`, `$obj::method(...)`) ──────────

/**
 * `$receiver::class` — return the class name of whatever object the
 * receiver expression yields. Same lookup path as `Foo::class` but
 * the class is resolved from the receiver at compile time.
 */
final class DynamicStaticAccess extends Expr
{
    public function __construct(
        public readonly Expr $receiver,
        public readonly string $name,
        Span $span,
    ) {
        parent::__construct('DynamicStaticAccess', $span);
    }
}

/**
 * `$receiver::method(args)` — static-style dispatch through the
 * receiver's class. We compile it by reading the class id from the
 * object header at offset 0 and routing through the matching
 * class's symbol.
 */
final class DynamicStaticCall extends Expr
{
    /** @param Expr[] $args */
    public function __construct(
        public readonly Expr $receiver,
        public readonly string $method,
        public readonly array $args,
        Span $span,
    ) {
        parent::__construct('DynamicStaticCall', $span);
    }
}
