<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Add;
use Compile\Mir\ArrayAccess_;
use Compile\Mir\ArrayLit;
use Compile\Mir\StringConst;
use Compile\Mir\Walk;
use Compile\Mir\Spread_;
use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\Closure_;
use Compile\Mir\Invoke_;
use Compile\Mir\NullCoalesce_;
use Compile\Mir\Instanceof_;
use Compile\Mir\Cast;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\Div;
use Compile\Mir\Echo_;
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
use Compile\Mir\Throw_;
use Compile\Mir\TryCatch_;
use Compile\Mir\MirCatch;
use Compile\Mir\Ternary;
use Compile\Mir\Switch_;
use Compile\Mir\SwitchArm_;
use Compile\Mir\Match_;
use Compile\Mir\MatchArm_;
use Compile\Mir\If_;
use Compile\Mir\LoadLocal;
use Compile\Mir\MethodCall_;
use Compile\Mir\Mod;
use Compile\Mir\Module;
use Compile\Mir\Mul;
use Compile\Mir\Neg;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\Pass;
use Compile\Mir\PropertyAccess_;
use Compile\Mir\Return_;
use Compile\Mir\StaticCall_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreLocal;
use Compile\Mir\StoreProperty;
use Compile\Mir\DynProp_;
use Compile\Mir\StoreDynProp_;
use Compile\Mir\Sub;
use Compile\Mir\Type;
use Compile\Mir\While_;

/**
 * Flow-sensitive narrowing: a `kind ===` / `instanceof` / `is_*` guard narrows
 * the local forward through the branch it dominates.
 *
 * A trait on the one {@see InferTypes} host — split by concern so a reader opens
 * the file for the thing they are looking at. State stays on the host.
 */
trait InferNarrow
{
    /**
     * Flow-narrow a local inside a then-branch from its guard condition.
     *   - `$x instanceof C` → obj<C> (subclass dispatch; both ptr repr).
     * NOTE: `is_int/string/…($x)` narrowing of an `unknown` local is UNSOUND —
     * `unknown` can mask a NaN-boxed cell (a heterogeneous-literal element
     * erased through a bare `array` param), so retyping to a scalar makes
     * codegen read the boxed bits raw → segfault. Scalar narrowing of a cell
     * needs an unbox at the narrow point (union/dispatch work), not here.
     */
    private function narrowFromCond(Node $cond): void
    {
        if ($cond->kind === Node::KIND_INSTANCEOF) {
            $io = $cond;
            if ($io->operand->kind === Node::KIND_LOAD_LOCAL) {
                $name = $io->operand->name;
                $this->localTypes[$name] = Type::obj($io->class);
            } else {
                // `$obj->prop instanceof C` → narrow the property PATH within the
                // branch (keyed in localTypes so inferIf scopes it). Lets a
                // `mixed` prop holding an object resolve `$obj->prop->field` to a
                // typed offset (the prop is boxed; emitPropertyAccess unmasks it).
                $pk = $this->propPathKey($io->operand);
                if ($pk !== null) { $this->localTypes[$pk] = Type::obj($io->class); }
            }
            return;
        }
        // `$n->kind === Node::KIND_X` → narrow $n to the matching Node subclass
        // within the branch — the eager equivalent of the `castX` pin. IR-neutral
        // where a pin already supplies the type; the enabler for dropping pins.
        if ($cond->kind === Node::KIND_CMP) {
            $c = $cond;
            if ($c->op === '===') {
                if (!$this->narrowKindEq($c->left, $c->right)) {
                    $this->narrowKindEq($c->right, $c->left);
                }
            }
            return;
        }
        // `A && B` lowers to `Ternary(A, !!B, false)` — BOTH A and B hold in the
        // then-branch, so narrow from each conjunct. Chained `A && B && C` nests
        // (the then-arm is itself such a ternary) and the recursion covers it.
        // Only the &&-shape qualifies (else arm is the literal `false`).
        if ($cond->kind === Node::KIND_TERNARY) {
            $then = $cond->then;
            if ($then !== null && $this->isLiteralFalse($cond->else_)) {
                $this->narrowFromCond($cond->cond);
                $b = $this->unwrapNotNot($then);
                if ($b !== null) { $this->narrowFromCond($b); }
            }
        }
    }

    /** Narrow for the fall-through of an early-return/throw guard `if (NEG) …`,
     *  where NEG being FALSE (we fell through) means the un-negated form holds:
     *    - `!(P)`            → P holds       (`!($x instanceof C)` → obj<C>)
     *    - `$x->kind !== KIND_X` → `=== KIND_X` holds (narrow $x to X). */
    private function narrowFromNegatedCond(Node $cond): void
    {
        if ($cond->kind === Node::KIND_NOT) {
            $this->narrowFromCond($cond->operand);
            return;
        }
        if ($cond->kind === Node::KIND_CMP) {
            $c = $cond;
            if ($c->op === '!==') {
                if (!$this->narrowKindEq($c->left, $c->right)) {
                    $this->narrowKindEq($c->right, $c->left);
                }
            }
            return;
        }
        // `A || B` lowers to `Ternary(A, true, !!B)`. FALSE (we fell through)
        // means `!A && !B`, so BOTH negated conjuncts hold — narrow from each
        // (`if ($x->kind !== KIND_X || …) return;` ⇒ `$x` IS X below).
        if ($cond->kind === Node::KIND_TERNARY && $this->isLiteralTrue($cond->then)) {
            $this->narrowFromNegatedCond($cond->cond);
            $b = $this->unwrapNotNot($cond->else_);
            if ($b !== null) { $this->narrowFromNegatedCond($b); }
        }
    }

    /** Narrow the object whose `kind` a guard tests, from `=== KIND_X`. Handles
     *  `$local->kind`, a `$k = $obj->kind` alias (`$k === …`), AND a property path
     *  `$obj->prop->kind` (narrows the path via propPathKey → inferPropertyAccess
     *  reads it back). Returns true if applied. */
    private function narrowKindEq(Node $lhs, Node $rhs): bool
    {
        if ($rhs->kind !== Node::KIND_STRING_CONST) { return false; }
        $kv = $rhs->value;
        // Resolve the narrowing KEY (a local name or a NUL-free prop-path key) and
        // the object's CURRENT type.
        $key = '';
        $curr = null;
        if ($lhs->kind === Node::KIND_PROPERTY_ACCESS) {
            $pa = $lhs;
            if ($pa->property !== 'kind') { return false; }
            $obj = $pa->object;
            if ($obj->kind === Node::KIND_LOAD_LOCAL) {
                $key = $obj->name;
                if (!isset($this->localTypes[$key])) { return false; }
                $curr = $this->localTypes[$key];
            } elseif ($obj->kind === Node::KIND_PROPERTY_ACCESS) {
                $pk = $this->propPathKey($obj);
                if ($pk === null) { return false; }
                $key = $pk;
                // A branch-narrowed path wins; else the field's inferred type
                // (the cond was already inferred, so `$obj->type` is set).
                $curr = isset($this->localTypes[$pk]) ? $this->localTypes[$pk] : $obj->type;
            } else {
                return false;
            }
        } elseif ($lhs->kind === Node::KIND_LOAD_LOCAL) {
            $k = $lhs->name;
            if (!isset($this->kindAliasOf[$k])) { return false; }
            $key = $this->kindAliasOf[$k];
            if (!isset($this->localTypes[$key])) { return false; }
            $curr = $this->localTypes[$key];
        } else {
            return false;
        }
        // Only narrow an object currently typed as a recognised BASE — the MIR
        // `Node` or the AST `Expr`/`Stmt`. The kindClass maps return the FULLY-
        // qualified target (`Compile\Mir\Foreach_`, `Parser\Ast\ExpressionStmt`),
        // which the FQN-keyed class table resolves regardless of whether the
        // RECEIVER's type was short or FQN — so `->body` lands at the real
        // subclass offset. The base check also dodges the Type/Node 'closure'
        // kind-string clash (a `Type` local is class `Type`, not a base).
        // MIR kinds are snake_case, AST kinds PascalCase — no overlap, so the base
        // that matched selects the map. Node → MIR subclass; Expr/Stmt → AST.
        if ($curr->kind !== Type::KIND_OBJ) { return false; }
        $cc = $curr->class;
        if ($cc === null) { return false; }
        $len = \strlen($cc);
        if ($cc === 'Node' || ($len >= 5 && \substr($cc, $len - 5, 5) === '\\Node')) {
            $cls = $this->kindClass($kv);
        } elseif ($cc === 'Expr' || $cc === 'Stmt'
            || ($len >= 5 && \substr($cc, $len - 5, 5) === '\\Expr')
            || ($len >= 5 && \substr($cc, $len - 5, 5) === '\\Stmt')) {
            $cls = $this->astKindClass($kv);
        } else {
            return false;
        }
        if ($cls === '') { return false; }
        // The maps are canonical FQNs (for the compiler's own `Compile\Mir\*` /
        // `Parser\Ast\*`). A USER-space receiver is UNQUALIFIED (a global-namespace
        // `Stmt`/`ClassStmt`) — its class table is keyed by the short name, so
        // strip the map's namespace to match (else the narrow misses and `->decl`
        // falls back to a wrong offset).
        $slash = \strpos($cc, '\\');
        if ($slash === false || $slash < 0) {
            $ls = \strrpos($cls, '\\');
            if ($ls !== false && $ls >= 0) { $cls = \substr($cls, $ls + 1); }
        }
        $this->localTypes[$key] = Type::obj($cls);
        return true;
    }

    /** AST kind-string → `Parser\Ast\*` subclass name, or '' for an unknown kind. */
    private function astKindClass(string $kv): string
    {
        return match ($kv) {
            'IntLiteral' => \Parser\Ast\IntLiteral::class, 'FloatLiteral' => \Parser\Ast\FloatLiteral::class,
            'StringLiteral' => \Parser\Ast\StringLiteral::class, 'BoolLiteral' => \Parser\Ast\BoolLiteral::class,
            'NullLiteral' => \Parser\Ast\NullLiteral::class, 'Variable' => \Parser\Ast\Variable::class,
            'Identifier' => \Parser\Ast\Identifier::class, 'MagicConstant' => \Parser\Ast\MagicConstant::class,
            'BinaryOp' => \Parser\Ast\BinaryOp::class, 'UnaryOp' => \Parser\Ast\UnaryOp::class, 'Ternary' => \Parser\Ast\Ternary::class,
            'NullCoalesce' => \Parser\Ast\NullCoalesce::class, 'Cast' => \Parser\Ast\Cast::class,
            'Instanceof' => \Parser\Ast\InstanceofExpr::class, 'Assign' => \Parser\Ast\Assign::class,
            'CompoundAssign' => \Parser\Ast\CompoundAssign::class, 'RefAssign' => \Parser\Ast\RefAssign::class,
            'IncDec' => \Parser\Ast\IncDec::class, 'ArrayLit' => \Parser\Ast\ArrayLit::class, 'ArrayAccess' => \Parser\Ast\ArrayAccess::class,
            'Call' => \Parser\Ast\CallExpr::class, 'MethodCall' => \Parser\Ast\MethodCallExpr::class,
            'PropertyAccess' => \Parser\Ast\PropertyAccess::class, 'DynProp' => \Parser\Ast\DynProp::class,
            'StaticCall' => \Parser\Ast\StaticCall::class, 'StaticAccess' => \Parser\Ast\StaticAccess::class,
            'New' => \Parser\Ast\NewExpr::class, 'Invoke' => \Parser\Ast\Invoke::class, 'Clone' => \Parser\Ast\CloneExpr::class,
            'ArrowFn' => \Parser\Ast\ArrowFn::class, 'Closure' => \Parser\Ast\Closure::class, 'Match' => \Parser\Ast\MatchExpr::class,
            'NamedArg' => \Parser\Ast\NamedArg::class, 'Ellipsis' => \Parser\Ast\Ellipsis::class, 'Spread' => \Parser\Ast\Spread::class,
            'Yield' => \Parser\Ast\YieldExpr::class, 'DynamicStaticAccess' => \Parser\Ast\DynamicStaticAccess::class,
            'DynamicStaticCall' => \Parser\Ast\DynamicStaticCall::class, 'Expression' => \Parser\Ast\ExpressionStmt::class,
            'Echo' => \Parser\Ast\EchoStmt::class, 'Return' => \Parser\Ast\ReturnStmt::class, 'If' => \Parser\Ast\IfStmt::class,
            'While' => \Parser\Ast\WhileStmt::class, 'DoWhile' => \Parser\Ast\DoWhileStmt::class, 'For' => \Parser\Ast\ForStmt::class,
            'Foreach' => \Parser\Ast\ForeachStmt::class, 'Function' => \Parser\Ast\FunctionStmt::class,
            'Namespace' => \Parser\Ast\NamespaceStmt::class, 'UseDecl' => \Parser\Ast\UseDeclStmt::class,
            'Class' => \Parser\Ast\ClassStmt::class, 'Break' => \Parser\Ast\BreakStmt::class, 'Continue' => \Parser\Ast\ContinueStmt::class,
            'Throw' => \Parser\Ast\ThrowStmt::class, 'TryCatch' => \Parser\Ast\TryCatchStmt::class, 'Switch' => \Parser\Ast\SwitchStmt::class,
            'StaticLocal' => \Parser\Ast\StaticLocalStmt::class, 'Global' => \Parser\Ast\GlobalStmt::class,
            'Goto' => \Parser\Ast\GotoStmt::class, 'Label' => \Parser\Ast\LabelStmt::class,
            default => '',
        };
    }

    /** MIR kind-string → Node subclass name, or '' for a non-Node kind. A `match`
     *  (not a cached assoc): no shared-array RC / bare-array element erasure. */
    private function kindClass(string $kv): string
    {
        return match ($kv) {
            Node::KIND_INT_CONST => \Compile\Mir\IntConst::class,
            Node::KIND_FLOAT_CONST => \Compile\Mir\FloatConst::class,
            Node::KIND_STRING_CONST => \Compile\Mir\StringConst::class,
            Node::KIND_BOOL_CONST => \Compile\Mir\BoolConst::class,
            Node::KIND_NULL_CONST => \Compile\Mir\NullConst::class,
            Node::KIND_LOAD_LOCAL => \Compile\Mir\LoadLocal::class,
            Node::KIND_STORE_LOCAL => \Compile\Mir\StoreLocal::class,
            Node::KIND_ADD => \Compile\Mir\Add::class,
            Node::KIND_SUB => \Compile\Mir\Sub::class,
            Node::KIND_MUL => \Compile\Mir\Mul::class,
            Node::KIND_DIV => \Compile\Mir\Div::class,
            Node::KIND_MOD => \Compile\Mir\Mod::class,
            Node::KIND_NEG => \Compile\Mir\Neg::class,
            Node::KIND_NOT => \Compile\Mir\Not_::class,
            Node::KIND_BITOP => \Compile\Mir\BitOp::class,
            Node::KIND_BITNOT => \Compile\Mir\BitNot_::class,
            Node::KIND_CONCAT => \Compile\Mir\Concat::class,
            Node::KIND_ECHO => \Compile\Mir\Echo_::class,
            Node::KIND_RETURN => \Compile\Mir\Return_::class,
            Node::KIND_CALL => \Compile\Mir\Call::class,
            Node::KIND_BLOCK => \Compile\Mir\Block::class,
            Node::KIND_MEMORY_OP => \Compile\Mir\MemoryOp_::class,
            Node::KIND_CMP => \Compile\Mir\Cmp::class,
            Node::KIND_IF => \Compile\Mir\If_::class,
            Node::KIND_WHILE => \Compile\Mir\While_::class,
            Node::KIND_INCDEC => \Compile\Mir\IncDec::class,
            Node::KIND_STATIC_PROP => \Compile\Mir\StaticProp_::class,
            Node::KIND_STORE_STATIC_PROP => \Compile\Mir\StoreStaticProp_::class,
            Node::KIND_STATIC_LOCAL_DECL => \Compile\Mir\StaticLocalDecl_::class,
            Node::KIND_THROW => \Compile\Mir\Throw_::class,
            Node::KIND_YIELD => \Compile\Mir\Yield_::class,
            Node::KIND_TRY_CATCH => \Compile\Mir\TryCatch_::class,
            Node::KIND_REF_ALIAS => \Compile\Mir\RefAlias_::class,
            Node::KIND_REF_BIND => \Compile\Mir\RefBind_::class,
            Node::KIND_REF_ADDR => \Compile\Mir\RefAddr_::class,
            Node::KIND_GOTO => \Compile\Mir\Goto_::class,
            Node::KIND_LABEL => \Compile\Mir\Label_::class,
            Node::KIND_CLASS_NAME => \Compile\Mir\ClassName_::class,
            Node::KIND_ISSET => \Compile\Mir\Isset_::class,
            Node::KIND_UNSET => \Compile\Mir\Unset_::class,
            Node::KIND_CLOSURE => \Compile\Mir\Closure_::class,
            Node::KIND_INVOKE => \Compile\Mir\Invoke_::class,
            Node::KIND_NULLCOALESCE => \Compile\Mir\NullCoalesce_::class,
            Node::KIND_INSTANCEOF => \Compile\Mir\Instanceof_::class,
            Node::KIND_CAST => \Compile\Mir\Cast::class,
            Node::KIND_TERNARY => \Compile\Mir\Ternary::class,
            Node::KIND_SWITCH => \Compile\Mir\Switch_::class,
            Node::KIND_MATCH => \Compile\Mir\Match_::class,
            Node::KIND_FOREACH => \Compile\Mir\Foreach_::class,
            Node::KIND_FOR => \Compile\Mir\For_::class,
            Node::KIND_DOWHILE => \Compile\Mir\DoWhile_::class,
            Node::KIND_BREAK => \Compile\Mir\Break_::class,
            Node::KIND_CONTINUE => \Compile\Mir\Continue_::class,
            Node::KIND_ARRAY_LIT => \Compile\Mir\ArrayLit::class,
            Node::KIND_ARRAY_ACCESS => \Compile\Mir\ArrayAccess_::class,
            Node::KIND_SPREAD => \Compile\Mir\Spread_::class,
            Node::KIND_STORE_ELEMENT => \Compile\Mir\StoreElement::class,
            Node::KIND_NEW_OBJ => \Compile\Mir\NewObj::class,
            Node::KIND_PROPERTY_ACCESS => \Compile\Mir\PropertyAccess_::class,
            Node::KIND_CLONE => \Compile\Mir\Clone_::class,
            Node::KIND_DYN_PROP => \Compile\Mir\DynProp_::class,
            Node::KIND_STORE_DYN_PROP => \Compile\Mir\StoreDynProp_::class,
            Node::KIND_STORE_PROPERTY => \Compile\Mir\StoreProperty::class,
            Node::KIND_METHOD_CALL => \Compile\Mir\MethodCall_::class,
            Node::KIND_STATIC_CALL => \Compile\Mir\StaticCall_::class,
            default => '',
        };
    }

    /** Narrowing key for a `$local->p1->…->pN` property path (`->`-joined, rooted
     *  at a LoadLocal), or null. Handles ANY depth by recursing on the object. */
    private function propPathKey(Node $node): ?string
    {
        if ($node->kind !== Node::KIND_PROPERTY_ACCESS) { return null; }
        $pa = $node;
        $obj = $pa->object;
        // Separator MUST be NUL-free: this key indexes the `localTypes` assoc,
        // whose string-key compare is not reliably binary-safe across every
        // self-host layout (a `\0` made `"c\0value"` collide with `"c"`). `->`
        // cannot appear in a PHP identifier, so base/property can't collide.
        if ($obj->kind === Node::KIND_LOAD_LOCAL) {
            return $obj->name . "->" . $pa->property;
        }
        // `$a->b->c` → recurse: "a->b" + "->c". A non-LoadLocal/non-path base
        // (e.g. a call result) has no stable key.
        if ($obj->kind === Node::KIND_PROPERTY_ACCESS) {
            $base = $this->propPathKey($obj);
            if ($base === null) { return null; }
            return $base . "->" . $pa->property;
        }
        return null;
    }
}
