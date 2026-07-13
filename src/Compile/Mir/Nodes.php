<?php

namespace Compile\Mir;

/**
 * Concrete MIR node subclasses. One file per logical group keeps
 * the kind / property layout co-located.
 *
 * Child references are intentionally *not* `readonly` — transform
 * passes (ConstFold, DSE, lowering, …) rewrite the tree in place,
 * which is the standard discipline in HHIR / Cranelift / LLVM IR.
 * Leaf payloads (literal int / float / string values, op strings,
 * function names) stay `readonly` since rewrites replace the whole
 * node rather than mutating its payload.
 */

// ── Constants ─────────────────────────────────────────────────────

final class IntConst extends Node
{
    public function __construct(public readonly int $value, Type $type)
    {
        parent::__construct(Node::KIND_INT_CONST, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitIntConst($this);
    }
}

final class FloatConst extends Node
{
    public function __construct(public readonly float $value, Type $type)
    {
        parent::__construct(Node::KIND_FLOAT_CONST, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitFloatConst($this);
    }
}

final class StringConst extends Node
{
    public function __construct(public readonly string $value, Type $type)
    {
        parent::__construct(Node::KIND_STRING_CONST, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStringConst($this);
    }
}

final class BoolConst extends Node
{
    public function __construct(public readonly bool $value, Type $type)
    {
        parent::__construct(Node::KIND_BOOL_CONST, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitBoolConst($this);
    }
}

final class NullConst extends Node
{
    public function __construct(Type $type)
    {
        parent::__construct(Node::KIND_NULL_CONST, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitNullConst($this);
    }
}

// ── Locals ────────────────────────────────────────────────────────

final class LoadLocal extends Node
{
    public function __construct(public readonly string $name, Type $type)
    {
        parent::__construct(Node::KIND_LOAD_LOCAL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitLoadLocal($this);
    }
}

final class StoreLocal extends Node
{
    /** Declared local type from an inline `/** @var T $x *\/` on the binding
     *  statement; seeds the slot type in InferTypes (else null → inferred). */
    public ?Type $declaredType = null;

    public function __construct(
        public readonly string $name,
        public Node $value,
        Type $type,
    ) {
        parent::__construct(Node::KIND_STORE_LOCAL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStoreLocal($this);
    }
}

// ── Arithmetic ────────────────────────────────────────────────────

final class Add extends Node
{
    public function __construct(
        public Node $left,
        public Node $right,
        Type $type,
    ) {
        parent::__construct(Node::KIND_ADD, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitAdd($this);
    }
}

final class Sub extends Node
{
    public function __construct(
        public Node $left,
        public Node $right,
        Type $type,
    ) {
        parent::__construct(Node::KIND_SUB, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitSub($this);
    }
}

final class Mul extends Node
{
    public function __construct(
        public Node $left,
        public Node $right,
        Type $type,
    ) {
        parent::__construct(Node::KIND_MUL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitMul($this);
    }
}

final class Div extends Node
{
    public function __construct(
        public Node $left,
        public Node $right,
        Type $type,
    ) {
        parent::__construct(Node::KIND_DIV, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitDiv($this);
    }
}

final class Mod extends Node
{
    public function __construct(
        public Node $left,
        public Node $right,
        Type $type,
    ) {
        parent::__construct(Node::KIND_MOD, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitMod($this);
    }
}

// ── Unary ─────────────────────────────────────────────────────────

final class Neg extends Node
{
    public function __construct(
        public Node $operand,
        Type $type,
    ) {
        parent::__construct(Node::KIND_NEG, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitNeg($this);
    }
}

final class Not_ extends Node
{
    public function __construct(public Node $operand)
    {
        parent::__construct(Node::KIND_NOT, Type::bool_());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitNot($this);
    }
}

/** Integer bitwise binary op: `op` ∈ shl | shr | and | or | xor. */
final class BitOp extends Node
{
    public function __construct(
        public readonly string $op,
        public Node $left,
        public Node $right,
        Type $type,
    ) {
        parent::__construct(Node::KIND_BITOP, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitBitOp($this);
    }
}

/** `~$x` — integer bitwise complement. */
final class BitNot_ extends Node
{
    public function __construct(public Node $operand, Type $type)
    {
        parent::__construct(Node::KIND_BITNOT, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitBitNot($this);
    }
}

/**
 * String concatenation (`.`). Always produces a `string`; operands
 * that aren't strings are coerced at lowering / emit time (int →
 * decimal text, etc.).
 */
final class Concat extends Node
{
    public function __construct(
        public Node $left,
        public Node $right,
    ) {
        parent::__construct(Node::KIND_CONCAT, Type::string_());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitConcat($this);
    }
}

// ── Statements ────────────────────────────────────────────────────

final class Echo_ extends Node
{
    /** @param Node[] $exprs */
    public function __construct(public array $exprs, Type $type)
    {
        parent::__construct(Node::KIND_ECHO, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitEcho($this);
    }
}

final class Return_ extends Node
{
    public function __construct(public ?Node $value, Type $type)
    {
        parent::__construct(Node::KIND_RETURN, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitReturn($this);
    }
}

final class Call extends Node
{
    /** @param Node[] $args */
    public function __construct(
        // Non-readonly: {@see Passes\Monomorphize} repoints a call to a
        // specialized copy (`f` → `f$mono$p0_vec_int`) in place — the args
        // and result type are unchanged, only the callee name.
        public string $function,
        public array $args,
        Type $type,
    ) {
        parent::__construct(Node::KIND_CALL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitCall($this);
    }
}

final class Block extends Node
{
    /** @param Node[] $stmts */
    public function __construct(public array $stmts, Type $type)
    {
        parent::__construct(Node::KIND_BLOCK, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitBlock($this);
    }
}

/**
 * Explicit memory operation, inserted by {@see Passes\InsertMemoryOps}
 * from the allocation-kind verdict — the MemoryOps layer (contract
 * step #5). EmitLlvm *consumes* these; it never invents retain/release
 * from its feature handlers.
 *
 * `op`     — 'retain' | 'release' | 'cow' | 'root' | 'arena_enter' | 'arena_leave'
 * `flavor` — heap family the runtime helper dispatches on:
 *            'string' | 'vec' | 'assoc' | 'obj' | 'cell' (empty for arena scope)
 * `target` — the value the op acts on (a `LoadLocal` for scope-exit
 *            releases; null for whole-frame arena enter / leave).
 */
final class MemoryOp_ extends Node
{
    public function __construct(
        public readonly string $op,
        public readonly string $flavor,
        public ?Node $target,
        Type $type,
    ) {
        parent::__construct(Node::KIND_MEMORY_OP, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitMemoryOp($this);
    }
}

// ── Comparison ────────────────────────────────────────────────────

final class Cmp extends Node
{
    // Field order matches the other binLeft/binRight-routed binary nodes
    // (Add/Sub/…): `left`,`right` first so a base-`Node`-typed read of
    // `->left`/`->right` in {@see Walk::children} lands at the same slot
    // across every arithmetic/compare node. `op` trails.
    public function __construct(
        public Node $left,
        public Node $right,
        public readonly string $op,
    ) {
        parent::__construct(Node::KIND_CMP, Type::bool_());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitCmp($this);
    }
}

// ── Control flow ──────────────────────────────────────────────────

final class If_ extends Node
{
    public function __construct(
        public Node $cond,
        public Block $then,
        public ?Block $else,
    ) {
        parent::__construct(Node::KIND_IF, Type::void());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitIf($this);
    }
}

final class While_ extends Node
{
    public function __construct(
        public Node $cond,
        public Block $body,
    ) {
        parent::__construct(Node::KIND_WHILE, Type::void());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitWhile($this);
    }
}

final class IncDec extends Node
{
    public function __construct(
        public string $name,
        public string $op,      // '+' or '-'
        public bool $prefix,
        Type $type,
    ) {
        parent::__construct(Node::KIND_INCDEC, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitIncDec($this);
    }
}

final class StaticProp_ extends Node
{
    public function __construct(public string $global, Type $type)
    {
        parent::__construct(Node::KIND_STATIC_PROP, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStaticProp($this);
    }
}

final class StoreStaticProp_ extends Node
{
    public function __construct(public string $global, public Node $value, Type $type)
    {
        parent::__construct(Node::KIND_STORE_STATIC_PROP, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStoreStaticProp($this);
    }
}

/**
 * `static $n = <init>;` inside a function body. Backed by a module
 * global value cell; `guard` (if non-empty) is a once-init flag cell
 * so the init runs on the first call only. Reads/writes of `$name`
 * inside the function route to `cell` instead of a stack slot.
 */
final class StaticLocalDecl_ extends Node
{
    public function __construct(
        public string $name,
        public string $cell,
        public string $guard,
        public ?Node $init,
        Type $type,
    ) {
        parent::__construct(Node::KIND_STATIC_LOCAL_DECL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStaticLocalDecl($this);
    }
}

/** `throw $value` — store + longjmp to the topmost active jmp_buf. */
final class Throw_ extends Node
{
    public function __construct(public Node $value, Type $type)
    {
        parent::__construct(Node::KIND_THROW, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitThrow($this);
    }
}

/**
 * `yield`, `yield $v`, `yield $k => $v`, `yield from $v` inside a
 * generator. `value` is null for a bare `yield;`; `key` is null unless
 * `$k => $v`; `from` marks delegation. EmitLlvm lowers it to
 * store-current / set-state / suspend within the resume function.
 */
final class Yield_ extends Node
{
    public function __construct(
        public ?Node $key,
        public ?Node $value,
        public bool $from,
        Type $type,
    ) {
        parent::__construct(Node::KIND_YIELD, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitYield($this);
    }
}

/** One `catch (A | B $e) { ... }` arm of a {@see TryCatch_}. */
final class MirCatch
{
    /**
     * @param string[] $types accepted class names
     * @param Node[]   $body
     */
    public function __construct(
        public array $types,
        public ?string $var,
        public array $body,
    ) {}
}

/** `try { } catch { } finally { }` — setjmp/longjmp structured handler. */
final class TryCatch_ extends Node
{
    /**
     * @param Node[]      $tryBody
     * @param MirCatch[]  $catches
     * @param Node[]      $finallyBody (empty when no finally)
     */
    /** Generator frame slot indices for the depth snapshots ($idb / $od);
     *  -1 outside a generator. A yield inside the try makes the resume switch
     *  bypass the entry-block depth SSA, so the snapshot lives in the frame. */
    public int $genDepthSlot = -1;
    public int $genOuterSlot = -1;
    /** Frame slot for the finally pending-flag (and +1 for the pending-value);
     *  -1 when not a generator finally. Allocas would sit past the resume
     *  switch and not dominate; the frame cell survives + dominates. */
    public int $genPendSlot = -1;

    public function __construct(
        public array $tryBody,
        public array $catches,
        public array $finallyBody,
        public bool $hasFinally,
        Type $type,
    ) {
        parent::__construct(Node::KIND_TRY_CATCH, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitTryCatch($this);
    }
}

/** `$y = &$x` — bind local `target` as an alias of local `source`. */
final class RefAlias_ extends Node
{
    public function __construct(public string $target, public string $source, Type $type)
    {
        parent::__construct(Node::KIND_REF_ALIAS, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitRefAlias($this);
    }
}

/** `$r = &fn(...)` — bind `$r` as a reference to a by-ref return. */
final class RefBind_ extends Node
{
    public function __construct(public string $target, public Node $call, Type $type)
    {
        parent::__construct(Node::KIND_REF_BIND, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitRefBind($this);
    }
}

/** `$x = &<lvalue>` where lvalue is a container slot (`$obj->prop`, `$a[$k]`).
 *  $x's slot is bound to the ADDRESS of the slot so reads/writes of $x deref
 *  it (the refLocals mechanism), aliasing the property / element. */
final class RefAddr_ extends Node
{
    public function __construct(public string $target, public Node $lvalue, Type $type)
    {
        parent::__construct(Node::KIND_REF_ADDR, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitRefAddr($this);
    }
}

/** `goto L;` — an unconditional branch to the block emitted for label `L`. */
final class Goto_ extends Node
{
    public function __construct(public string $label, Type $type)
    {
        parent::__construct(Node::KIND_GOTO, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitGoto($this);
    }
}

/** `L:` — a statement label; EmitLlvm materializes a block a `goto L` targets. */
final class Label_ extends Node
{
    public function __construct(public string $name, Type $type)
    {
        parent::__construct(Node::KIND_LABEL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitLabel($this);
    }
}

/** `$obj::class` — the runtime class name of `operand` as a string. */
final class ClassName_ extends Node
{
    public function __construct(public Node $operand, Type $type)
    {
        parent::__construct(Node::KIND_CLASS_NAME, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitClassName($this);
    }
}

/** `isset($a, $b, ...)` — true iff every target is set and non-null. */
final class Isset_ extends Node
{
    /** @param Node[] $targets */
    public function __construct(public array $targets, Type $type)
    {
        parent::__construct(Node::KIND_ISSET, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitIsset($this);
    }
}

/** `unset($a, $b, ...)` — clear each target. */
final class Unset_ extends Node
{
    /** @param Node[] $targets */
    public function __construct(public array $targets, Type $type)
    {
        parent::__construct(Node::KIND_UNSET, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitUnset($this);
    }
}

final class Closure_ extends Node
{
    /**
     * @param Node[] $captures captured vars, in fn-param order. A by-value
     *   capture is a LoadLocal (its value is packed into the env); a by-ref
     *   capture (`use (&$x)`) has its slot ADDRESS packed instead — flagged
     *   index-parallel in {@see $captureByRef}, emitted as a refLocal in the
     *   closure body.
     * @param bool[] $captureByRef index-parallel: true = by-reference capture.
     */
    public function __construct(
        public int $id,
        public array $captures,
        Type $type,
        public array $captureByRef = [],
    ) {
        parent::__construct(Node::KIND_CLOSURE, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitClosure($this);
    }
}

final class Invoke_ extends Node
{
    /** @param Node[] $args */
    public function __construct(
        public Node $callee,
        public array $args,
        Type $type,
    ) {
        parent::__construct(Node::KIND_INVOKE, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitInvoke($this);
    }
}

final class NullCoalesce_ extends Node
{
    public function __construct(
        public Node $left,
        public Node $right,
        Type $type,
    ) {
        parent::__construct(Node::KIND_NULLCOALESCE, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitNullCoalesce($this);
    }
}

final class Instanceof_ extends Node
{
    public function __construct(
        public Node $operand,
        public string $class,
    ) {
        parent::__construct(Node::KIND_INSTANCEOF, Type::bool_());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitInstanceof($this);
    }
}

final class Cast extends Node
{
    public function __construct(
        public string $target,  // 'int' | 'float' | 'string' | 'bool'
        public Node $operand,
        Type $type,
    ) {
        parent::__construct(Node::KIND_CAST, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitCast($this);
    }
}

final class Ternary extends Node
{
    public function __construct(
        public Node $cond,
        public ?Node $then,     // null for short ternary `?:`
        public Node $else_,
        Type $type,
        // Nullsafe desugar (`$o?->prop`): the null arm must stay representable
        // (a tagged cell), so InferTypes unifies to a nullable cell. A plain
        // ternary keeps its historical "null arm takes the other branch's type".
        public bool $nullable = false,
    ) {
        parent::__construct(Node::KIND_TERNARY, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitTernary($this);
    }
}

/** One `case v:` / `default:` arm of a {@see Switch_}. value null = default. */
final class SwitchArm_
{
    /** @param Node[] $body */
    public function __construct(
        public ?Node $value,
        public array $body,
    ) {}
}

final class Switch_ extends Node
{
    /** @param SwitchArm_[] $arms */
    public function __construct(
        public Node $subject,
        public array $arms,
    ) {
        parent::__construct(Node::KIND_SWITCH, Type::void());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitSwitch($this);
    }
}

/** One `conds => body` arm of a {@see Match_}. conds null = default. */
final class MatchArm_
{
    /** @param Node[]|null $conds */
    public function __construct(
        public ?array $conds,
        public Node $body,
    ) {}
}

final class Match_ extends Node
{
    /** @param MatchArm_[] $arms */
    public function __construct(
        public Node $subject,
        public array $arms,
        Type $type,
    ) {
        parent::__construct(Node::KIND_MATCH, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitMatch($this);
    }
}

final class Foreach_ extends Node
{
    public function __construct(
        public Node $array,
        public ?string $keyVar,
        public string $valueVar,
        public bool $byRef,
        public Block $body,
    ) {
        parent::__construct(Node::KIND_FOREACH, Type::void());
    }

    /**
     * Frame-slot base for the array iterator state (cursor + array ptr) when
     * this foreach is INSIDE a generator — its iterator state must survive a
     * `yield` suspension, so it lives in the heap frame, not stack allocas.
     * -1 outside a generator (use ordinary allocas). Set by EmitLlvm's
     * generator frame-layout pass.
     */
    public int $genSlotBase = -1;

    /**
     * Object-iterator dispatch (set by InferTypes): the class whose
     * Iterator protocol (rewind/valid/current/key/next) drives this foreach,
     * or '' for an array / generator. {@see $iterAggregate} true when the
     * subject is an IteratorAggregate (call getIterator() first → $iterClass
     * is the returned iterator's class).
     */
    public string $iterClass = '';
    public bool $iterAggregate = false;

    public function accept(EmitVisitor $v): string
    {
        return $v->visitForeach($this);
    }
}

final class For_ extends Node
{
    public function __construct(
        public ?Node $init,
        public ?Node $cond,
        public ?Node $step,
        public Block $body,
    ) {
        parent::__construct(Node::KIND_FOR, Type::void());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitFor($this);
    }
}

final class DoWhile_ extends Node
{
    public function __construct(
        public Block $body,
        public Node $cond,
    ) {
        parent::__construct(Node::KIND_DOWHILE, Type::void());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitDoWhile($this);
    }
}

final class Break_ extends Node
{
    public function __construct(public int $level = 1)
    {
        parent::__construct(Node::KIND_BREAK, Type::void());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitBreak($this);
    }
}

final class Continue_ extends Node
{
    public function __construct(public int $level = 1)
    {
        parent::__construct(Node::KIND_CONTINUE, Type::void());
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitContinue($this);
    }
}

// ── Containers ────────────────────────────────────────────────────

/**
 * One element of an `ArrayLit`. `key` is null for positional slots.
 * Positional + keyed mix is allowed mid-array — InferTypes decides
 * whether the literal lattice-narrows to `vec[T]` (all positional)
 * or `assoc[K,V]` (any keyed).
 */
final class ArrayElement_
{
    public function __construct(
        public ?Node $key,
        public Node $value,
    ) {}
}

final class ArrayLit extends Node
{
    /** @param ArrayElement_[] $elements */
    public function __construct(public array $elements, Type $type)
    {
        parent::__construct(Node::KIND_ARRAY_LIT, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitArrayLit($this);
    }
}

final class ArrayAccess_ extends Node
{
    public function __construct(
        public Node $array,
        public Node $index,
        Type $type,
    ) {
        parent::__construct(Node::KIND_ARRAY_ACCESS, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitArrayAccess($this);
    }
}

final class Spread_ extends Node
{
    public function __construct(
        public Node $operand,
        Type $type,
    ) {
        parent::__construct(Node::KIND_SPREAD, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitSpread($this);
    }
}

final class StoreElement extends Node
{
    public function __construct(
        public Node $array,
        public Node $index,
        public Node $value,
        Type $type,
    ) {
        parent::__construct(Node::KIND_STORE_ELEMENT, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStoreElement($this);
    }
}

// ── Objects ───────────────────────────────────────────────────────

/**
 * `new $cls(args)` — the class comes from a value at runtime. The emitter tests
 * the name against every class whose constructor can take these arguments, and
 * throws if none matches (PHP's "Class not found").
 */
final class NewDynObj extends Node
{
    /** @param Node[] $args */
    public function __construct(
        public Node $classExpr,
        public array $args,
        Type $type,
    ) {
        parent::__construct(Node::KIND_NEW_DYN_OBJ, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitNewDynObj($this);
    }
}

final class NewObj extends Node
{
    /** @param Node[] $args */
    public function __construct(
        public readonly string $class,
        public array $args,
        Type $type,
    ) {
        parent::__construct(Node::KIND_NEW_OBJ, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitNewObj($this);
    }
}

final class PropertyAccess_ extends Node
{
    public function __construct(
        public Node $object,
        public readonly string $property,
        Type $type,
    ) {
        parent::__construct(Node::KIND_PROPERTY_ACCESS, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitPropertyAccess($this);
    }
}

/**
 * One `'name' => value` override of a PHP 8.5 clone-with. A typed holder
 * (not a `[string, Node]` tuple): a heterogeneous tuple infers a cell
 * array and BOXES the name, so a reader expecting a raw string (e.g.
 * propertyOffset) gets a NaN-boxed value → strlen faults. Fixed fields
 * read at fixed offsets, no boxing.
 */
final class CloneWith
{
    public function __construct(
        public readonly string $name,
        public Node $value,
    ) {}
}

final class Clone_ extends Node
{
    /** @param CloneWith[] $withProps name => value overrides (8.5 clone-with) */
    public function __construct(
        public Node $object,
        public array $withProps,
        Type $type,
    ) {
        parent::__construct(Node::KIND_CLONE, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitClone($this);
    }
}

final class DynProp_ extends Node
{
    public function __construct(public Node $object, public Node $name, Type $type)
    {
        parent::__construct(Node::KIND_DYN_PROP, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitDynProp($this);
    }
}

final class StoreDynProp_ extends Node
{
    public function __construct(public Node $object, public Node $name, public Node $value, Type $type)
    {
        parent::__construct(Node::KIND_STORE_DYN_PROP, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStoreDynProp($this);
    }
}

final class StoreProperty extends Node
{
    public function __construct(
        public Node $object,
        public readonly string $property,
        public Node $value,
        Type $type,
        /** Write straight to the backing slot, skipping any set hook (default
         *  initialisation of a hooked property). */
        public bool $bypassHook = false,
    ) {
        parent::__construct(Node::KIND_STORE_PROPERTY, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStoreProperty($this);
    }
}

final class MethodCall_ extends Node
{
    /** @param Node[] $args */
    public function __construct(
        public Node $object,
        public readonly string $method,
        public array $args,
        Type $type,
    ) {
        parent::__construct(Node::KIND_METHOD_CALL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitMethodCall($this);
    }
}

final class StaticCall_ extends Node
{
    /**
     * `$staticClass` carries the late-static-binding scope (the called
     * class) separately from `$class` (the dispatch target). They differ for
     * a forwarding `self::`/`parent::` call inside an LSB specialization,
     * where dispatch goes to the lexical/parent class but `static::` must
     * still resolve to the original called class. '' means non-LSB.
     *
     * @param Node[] $args
     */
    public function __construct(
        public readonly string $class,
        public readonly string $method,
        public array $args,
        Type $type,
        public readonly string $staticClass = '',
    ) {
        parent::__construct(Node::KIND_STATIC_CALL, $type);
    }

    public function accept(EmitVisitor $v): string
    {
        return $v->visitStaticCall($this);
    }
}
