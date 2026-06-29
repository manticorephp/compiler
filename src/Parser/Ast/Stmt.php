<?php

namespace Parser\Ast;

/**
 * Statement node — abstract root of the tagged hierarchy.
 *
 * Each variant is its own final subclass with typed readonly fields,
 * so consumers iterate via `instanceof` and reach into named
 * properties instead of poking at a `payload` array. The `kind`
 * string survives for compatibility with the existing dump format
 * and quick string-switch dispatch.
 */
abstract class Stmt
{
    public function __construct(
        public readonly string $kind,
        public readonly Span $span,
    ) {}

    // ── Construction helpers ──────────────────────────────────────
    //
    // The parser still calls these short-name factories; each one
    // forwards to the matching concrete subclass below.

    public static function expression(Expr $expr, Span $span): ExpressionStmt
    {
        return new ExpressionStmt($expr, $span);
    }

    /** @param Expr[] $exprs */
    public static function echo_(array $exprs, Span $span): EchoStmt
    {
        return new EchoStmt($exprs, $span);
    }

    public static function return_(?Expr $value, Span $span): ReturnStmt
    {
        return new ReturnStmt($value, $span);
    }

    /** @param ElseIfArm[] $elseifs */
    public static function if_(Expr $condition, Block $then, array $elseifs, ?Block $else, Span $span): IfStmt
    {
        return new IfStmt($condition, $then, $elseifs, $else, $span);
    }

    public static function while_(Expr $condition, Block $body, Span $span): WhileStmt
    {
        return new WhileStmt($condition, $body, $span);
    }

    public static function doWhile(Block $body, Expr $condition, Span $span): DoWhileStmt
    {
        return new DoWhileStmt($body, $condition, $span);
    }

    /**
     * @param Expr[] $init   comma-separated init expressions (PHP allows `,`)
     * @param Expr[] $update comma-separated step expressions
     */
    public static function for_(array $init, ?Expr $condition, array $update, Block $body, Span $span): ForStmt
    {
        return new ForStmt($init, $condition, $update, $body, $span);
    }

    public static function foreach_(Expr $expr, ?Expr $key, Expr $value, bool $valueByRef, Block $body, Span $span): ForeachStmt
    {
        return new ForeachStmt($expr, $key, $value, $valueByRef, $body, $span);
    }

    public static function function_(FunctionDecl $decl, Span $span): FunctionStmt
    {
        return new FunctionStmt($decl, $span);
    }

    public static function namespace_(string $name, ?Block $body, Span $span): NamespaceStmt
    {
        return new NamespaceStmt($name, $body, $span);
    }

    /** @param UseItem[] $items */
    public static function useDecl(array $items, Span $span): UseDeclStmt
    {
        return new UseDeclStmt($items, $span);
    }

    public static function class_(ClassDecl $decl, Span $span): ClassStmt
    {
        return new ClassStmt($decl, $span);
    }

    public static function break_(int $level, Span $span): BreakStmt
    {
        return new BreakStmt($level, $span);
    }

    public static function continue_(int $level, Span $span): ContinueStmt
    {
        return new ContinueStmt($level, $span);
    }

    public static function throw_(Expr $expr, Span $span): ThrowStmt
    {
        return new ThrowStmt($expr, $span);
    }

    /** @param CatchClause[] $catches */
    public static function tryCatch(Block $try, array $catches, ?Block $finally, Span $span): TryCatchStmt
    {
        return new TryCatchStmt($try, $catches, $finally, $span);
    }

    /** @param SwitchArm[] $cases */
    public static function switch_(Expr $expr, array $cases, Span $span): SwitchStmt
    {
        return new SwitchStmt($expr, $cases, $span);
    }

    /** @param StaticLocalDecl[] $decls */
    public static function staticLocal(array $decls, Span $span): StaticLocalStmt
    {
        return new StaticLocalStmt($decls, $span);
    }

    /** @param string[] $names */
    public static function global_(array $names, Span $span): GlobalStmt
    {
        return new GlobalStmt($names, $span);
    }
}

final class ExpressionStmt extends Stmt
{
    public function __construct(
        public readonly Expr $expr,
        Span $span,
    ) {
        parent::__construct('Expression', $span);
    }
}

final class EchoStmt extends Stmt
{
    /** @param Expr[] $exprs */
    public function __construct(
        public readonly array $exprs,
        Span $span,
    ) {
        parent::__construct('Echo', $span);
    }
}

final class ReturnStmt extends Stmt
{
    public function __construct(
        public readonly ?Expr $value,
        Span $span,
    ) {
        parent::__construct('Return', $span);
    }
}

final class IfStmt extends Stmt
{
    /** @param ElseIfArm[] $elseifs */
    public function __construct(
        public readonly Expr $condition,
        public readonly Block $then,
        public readonly array $elseifs,
        public readonly ?Block $else,
        Span $span,
    ) {
        parent::__construct('If', $span);
    }
}

/** One `elseif (cond) { ... }` arm inside an {@see IfStmt}. */
final class ElseIfArm
{
    public function __construct(
        public readonly Expr $condition,
        public readonly Block $body,
    ) {}
}

final class WhileStmt extends Stmt
{
    public function __construct(
        public readonly Expr $condition,
        public readonly Block $body,
        Span $span,
    ) {
        parent::__construct('While', $span);
    }
}

final class DoWhileStmt extends Stmt
{
    public function __construct(
        public readonly Block $body,
        public readonly Expr $condition,
        Span $span,
    ) {
        parent::__construct('DoWhile', $span);
    }
}

final class ForStmt extends Stmt
{
    /**
     * @param Expr[] $init
     * @param Expr[] $update
     */
    public function __construct(
        public readonly array $init,
        public readonly ?Expr $condition,
        public readonly array $update,
        public readonly Block $body,
        Span $span,
    ) {
        parent::__construct('For', $span);
    }
}

final class ForeachStmt extends Stmt
{
    public function __construct(
        public readonly Expr $expr,
        public readonly ?Expr $key,
        public readonly Expr $value,
        public readonly bool $valueByRef,
        public readonly Block $body,
        Span $span,
    ) {
        parent::__construct('Foreach', $span);
    }
}

final class FunctionStmt extends Stmt
{
    public function __construct(
        public readonly FunctionDecl $decl,
        Span $span,
    ) {
        parent::__construct('Function', $span);
    }
}

final class NamespaceStmt extends Stmt
{
    public function __construct(
        public readonly string $name,
        public readonly ?Block $body,
        Span $span,
    ) {
        parent::__construct('Namespace', $span);
    }
}

final class UseDeclStmt extends Stmt
{
    /** @param UseItem[] $items */
    public function __construct(
        public readonly array $items,
        Span $span,
    ) {
        parent::__construct('UseDecl', $span);
    }
}

final class ClassStmt extends Stmt
{
    public function __construct(
        public readonly ClassDecl $decl,
        Span $span,
    ) {
        parent::__construct('Class', $span);
    }
}

final class BreakStmt extends Stmt
{
    public function __construct(
        public readonly int $level,
        Span $span,
    ) {
        parent::__construct('Break', $span);
    }
}

final class ContinueStmt extends Stmt
{
    public function __construct(
        public readonly int $level,
        Span $span,
    ) {
        parent::__construct('Continue', $span);
    }
}

final class ThrowStmt extends Stmt
{
    public function __construct(
        public readonly Expr $expr,
        Span $span,
    ) {
        parent::__construct('Throw', $span);
    }
}

final class TryCatchStmt extends Stmt
{
    /** @param CatchClause[] $catches */
    public function __construct(
        public readonly Block $try,
        public readonly array $catches,
        public readonly ?Block $finally,
        Span $span,
    ) {
        parent::__construct('TryCatch', $span);
    }
}

/** One `catch (A | B $e) { ... }` arm inside a {@see TryCatchStmt}. */
final class CatchClause
{
    /** @param string[] $types  fully-qualified or short class names */
    public function __construct(
        public readonly array $types,
        public readonly ?string $name,
        public readonly Block $body,
    ) {}
}

final class SwitchStmt extends Stmt
{
    /** @param SwitchArm[] $cases */
    public function __construct(
        public readonly Expr $expr,
        public readonly array $cases,
        Span $span,
    ) {
        parent::__construct('Switch', $span);
    }
}

/**
 * One `case value:` / `default:` arm inside a {@see SwitchStmt}. The
 * `$value` is `null` for the default arm. Bodies are sequences of
 * statements without their own brace block to preserve PHP's
 * fall-through semantics.
 */
final class SwitchArm
{
    /** @param Stmt[] $body */
    public function __construct(
        public readonly ?Expr $value,
        public readonly array $body,
    ) {}
}

final class StaticLocalStmt extends Stmt
{
    /** @param StaticLocalDecl[] $decls */
    public function __construct(
        public readonly array $decls,
        Span $span,
    ) {
        parent::__construct('StaticLocal', $span);
    }
}

/** One `$name [= default]` slot inside a `static $x, $y = expr;`. */
final class StaticLocalDecl
{
    public function __construct(
        public readonly string $name,
        public readonly ?Expr $default,
    ) {}
}

final class GlobalStmt extends Stmt
{
    /** @param string[] $names */
    public function __construct(
        public readonly array $names,
        Span $span,
    ) {
        parent::__construct('Global', $span);
    }
}
