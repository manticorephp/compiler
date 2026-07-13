<?php

namespace Compile\Mir;

/**
 * MIR node base. Flat shape, `kind` discriminant + per-subclass
 * payload. Mirrors {@see \Parser\Ast\Expr} so self-host pre-scan
 * walks both with the same idioms.
 *
 * Every node carries a {@see Type}. Lowering passes refine it from
 * `Type::unknown()` to a concrete shape; later passes assert their
 * required input types via the {@see Type} read.
 */
abstract class Node
{
    public const KIND_INT_CONST    = 'int_const';
    public const KIND_FLOAT_CONST  = 'float_const';
    public const KIND_STRING_CONST = 'string_const';
    public const KIND_BOOL_CONST   = 'bool_const';
    public const KIND_NULL_CONST   = 'null_const';

    public const KIND_LOAD_LOCAL  = 'load_local';
    public const KIND_STORE_LOCAL = 'store_local';

    public const KIND_ADD = 'add';
    public const KIND_SUB = 'sub';
    public const KIND_MUL = 'mul';
    public const KIND_DIV = 'div';
    public const KIND_MOD = 'mod';

    public const KIND_NEG = 'neg';
    public const KIND_NOT = 'not';

    public const KIND_BITOP  = 'bitop';   // shl | shr | and | or | xor
    public const KIND_BITNOT = 'bitnot';  // `~`

    public const KIND_CONCAT = 'concat';

    public const KIND_CMP = 'cmp';
    public const KIND_INCDEC  = 'incdec';
    public const KIND_TERNARY = 'ternary';
    public const KIND_CAST    = 'cast';
    public const KIND_INSTANCEOF = 'instanceof';
    public const KIND_NULLCOALESCE = 'nullcoalesce';
    public const KIND_CLOSURE = 'closure';
    public const KIND_INVOKE  = 'invoke';
    public const KIND_STATIC_PROP = 'static_prop';
    public const KIND_STORE_STATIC_PROP = 'store_static_prop';
    public const KIND_STATIC_LOCAL_DECL = 'static_local_decl';
    public const KIND_ISSET = 'isset';
    public const KIND_UNSET = 'unset';
    public const KIND_CLASS_NAME = 'class_name';
    public const KIND_REF_ALIAS = 'ref_alias';
    public const KIND_REF_BIND = 'ref_bind';
    public const KIND_REF_ADDR = 'ref_addr';
    public const KIND_GOTO = 'goto';
    public const KIND_LABEL = 'label';
    public const KIND_THROW = 'throw';
    public const KIND_TRY_CATCH = 'try_catch';
    public const KIND_YIELD = 'yield';

    public const KIND_ECHO   = 'echo';
    public const KIND_RETURN = 'return';
    public const KIND_CALL   = 'call';

    public const KIND_IF       = 'if';
    public const KIND_WHILE    = 'while';
    public const KIND_FOR      = 'for';
    public const KIND_DOWHILE  = 'dowhile';
    public const KIND_SWITCH   = 'switch';
    public const KIND_MATCH    = 'match';
    public const KIND_FOREACH  = 'foreach';
    public const KIND_BREAK    = 'break';
    public const KIND_CONTINUE = 'continue';

    public const KIND_SPREAD          = 'spread';
    public const KIND_ARRAY_LIT       = 'array_lit';
    public const KIND_ARRAY_ACCESS    = 'array_access';
    public const KIND_STORE_ELEMENT   = 'store_element';
    public const KIND_NEW_OBJ         = 'new_obj';
    public const KIND_CLONE           = 'clone';
    public const KIND_PROPERTY_ACCESS = 'property_access';
    public const KIND_STORE_PROPERTY  = 'store_property';
    public const KIND_DYN_PROP        = 'dyn_prop';
    public const KIND_STORE_DYN_PROP  = 'store_dyn_prop';
    public const KIND_METHOD_CALL     = 'method_call';
    public const KIND_STATIC_CALL     = 'static_call';

    public const KIND_BLOCK = 'block';

    public const KIND_MEMORY_OP = 'memory_op';


    /**
     * Double dispatch to the emitter: the node picks the visit method, so the
     * emitter never re-derives the node type from `kind`.
     */
    abstract public function accept(EmitVisitor $v): string;

    public function __construct(
        public readonly string $kind,
        public Type $type,
    ) {}

    /**
     * Intrinsic memory effects of this op, filled by
     * {@see Passes\InferEffects}. Null until that pass runs.
     */
    public ?Effects $effects = null;

    /**
     * Allocation verdict for nodes that allocate (effects->alloc).
     * One of {@see AllocationKind}'s constants, filled by
     * {@see Passes\InferAllocKind}. Null on non-allocating nodes and
     * until that pass runs.
     */
    public ?string $allocKind = null;

    /**
     * 1-based source line this node lowered from (0 = unknown). Stamped
     * centrally in {@see Passes\LowerFromAst::lowerStmt} / `lowerExpr` from
     * the AST node's {@see \Parser\Ast\Span}, so compile-time diagnostics
     * (the {@see Passes\TypeCheck} analyzer) can point at a real location.
     */
    public int $line = 0;
}
