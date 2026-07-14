<?php

namespace Compile\Mir\Passes;

use Compile\Mir\BitOp;
use Compile\Mir\Call;
use Compile\Mir\Cmp;
use Compile\Mir\FunctionDef;
use Compile\Mir\Instanceof_;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Return_;
use Compile\Mir\StoreElement;
use Compile\Mir\StoreProperty;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * The gate that makes `#[TypeDef]` sound: refuse every use where the ERASED
 * value would be observed as an OBJECT.
 *
 * A TypeDef is its carrier scalar. That is invisible — and therefore correct —
 * everywhere the program treats it as a value. It becomes VISIBLE, and diverges
 * from the object Zend builds, at exactly four kinds of site:
 *
 *   - `$a === $b`      Zend compares object IDENTITY (two `new U8(5)` are not
 *                      identical); the erased form compares two equal scalars.
 *                      (`==` is fine: PHP's loose object compare is field-wise,
 *                      and a TypeDef has one field — it AGREES with the scalar
 *                      compare.)
 *   - `$x instanceof U8`   there is no class id left to test.
 *   - `var_dump($x)`   Zend prints `object(U8)#1 { … }`, the erased form an int.
 *                      Same for print_r / var_export / get_class / is_object /
 *                      json_encode / serialize / gettype.
 *   - a `mixed` slot   boxing into a tagged cell (a `mixed` param, an untyped
 *                      array element, a `mixed` property, a `mixed` return) makes
 *                      the value an int cell, and everything downstream — a
 *                      var_dump, a `===`, an is_object — then sees an int. The
 *                      marker is gone by then, so it must be refused HERE.
 *
 * Every one of these is a HARD ERROR naming the class, the site and the fix. The
 * project has been bitten too often by a silently-wrong value to prefer a quiet
 * degrade; and a TypeDef that quietly fell back to a heap object would also
 * quietly give back the allocation it exists to remove.
 *
 * NOT an error: arithmetic (`$byte->value + 1` — reading the property is where
 * the TypeDef ends and a plain int begins), a TYPED container (`U8[]` is an int
 * vec: no boxing), a typed param/return, a comparison of the two `->value`s.
 */
final class CheckTypeDefs
{
    public const NAME = 'check-typedefs';

    /** @var array<string, \Compile\Mir\Param[]> fn / `Class__method` name → params */
    private array $paramsByFn = [];

    /** @var array<string, Type> fn name → declared return type */
    private array $returnByFn = [];

    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $typeDefs = [];

    /** Builtins that render or interrogate a value AS AN OBJECT. */
    private function observesObject(string $fn): bool
    {
        return $fn === 'var_dump'   || $fn === 'print_r'  || $fn === 'var_export'
            || $fn === 'get_class'  || $fn === 'is_object'
            || $fn === 'json_encode' || $fn === 'serialize' || $fn === 'gettype';
    }

    public function run(Module $module): Module
    {
        $this->typeDefs = $module->typeDefs;
        if ($this->typeDefs === []) { return $module; }
        foreach ($module->functions as $fn) {
            $this->paramsByFn[$fn->name] = $fn->params;
            $this->returnByFn[$fn->name] = $fn->returnType;
        }
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            $this->checkNode($fn->body, $fn);
        }
        return $module;
    }

    private function checkNode(Node $n, FunctionDef $fn): void
    {
        if ($n->kind === Node::KIND_ADD || $n->kind === Node::KIND_SUB
            || $n->kind === Node::KIND_MUL || $n->kind === Node::KIND_DIV
            || $n->kind === Node::KIND_MOD || $n->kind === Node::KIND_CONCAT) {
            $this->checkArith($n);
        } elseif ($n->kind === Node::KIND_BITOP) {
            // Through a TYPED param, not the base-Node read above: BitOp declares
            // `op` FIRST and only then `left`/`right`, while Add/Sub/Cmp/Concat all
            // start with `left`. A base-`Node`-typed `->left` resolves by OFFSET
            // natively (by name under Zend), so on a BitOp it reads the `op` STRING
            // and the next `->type` dereferences it — a SIGSEGV in the compiler,
            // and only once self-built.
            $this->checkBitOp($n);
        } elseif ($n->kind === Node::KIND_CMP) {
            $this->checkIdentity($n);
        } elseif ($n->kind === Node::KIND_INSTANCEOF) {
            $this->checkInstanceof($n);
        } elseif ($n->kind === Node::KIND_CALL) {
            $this->checkCall($n);
        } elseif ($n->kind === Node::KIND_RETURN) {
            $this->checkReturn($n, $fn);
        } elseif ($n->kind === Node::KIND_STORE_ELEMENT) {
            $this->checkStoreElement($n);
        } elseif ($n->kind === Node::KIND_STORE_PROPERTY) {
            $this->checkStoreProperty($n);
        }
        foreach (Walk::children($n) as $c) { $this->checkNode($c, $fn); }
    }

    /**
     * `$a + $b` on a TypeDef. PHP has no userland operator overloading: Zend
     * raises `TypeError: Unsupported operand types: U8 + U8`. The erased form
     * would happily emit an `add i64` and print 300 — a SILENT divergence, and the
     * worst kind, because the answer looks reasonable. Refuse it: the carrier is
     * one `->value` away, and that is a real number PHP agrees about.
     */
    private function checkArith(Node $n): void
    {
        $this->failIfOperand($n->left, $n->right);
    }

    /** `$a & $b` — see the layout note at the dispatch site for why this is typed. */
    private function checkBitOp(BitOp $n): void
    {
        $this->failIfOperand($n->left, $n->right);
    }

    private function failIfOperand(Node $left, Node $right): void
    {
        $cls = $left->type->typeDefClass() ?? $right->type->typeDefClass();
        if ($cls === null || !isset($this->typeDefs[$cls])) { return; }
        $prop = $this->typeDefs[$cls]->typeDefProp;
        $this->fail(
            $cls,
            'PHP has no operator overloading — `$a op $b` on two `' . $cls . '` objects is a TypeError'
                . ' under `php`, and an erased value would quietly compute a number instead.'
                . ' Operate on the carrier: `$a->' . $prop . ' op $b->' . $prop . '`',
        );
    }

    /** `$a === $b` on a TypeDef — Zend compares object identity, we compare values. */
    private function checkIdentity(Cmp $n): void
    {
        if ($n->op !== '===' && $n->op !== '!==') { return; }
        $cls = $n->left->type->typeDefClass() ?? $n->right->type->typeDefClass();
        if ($cls === null || !isset($this->typeDefs[$cls])) { return; }
        $prop = $this->typeDefs[$cls]->typeDefProp;
        $this->fail(
            $cls,
            '`' . $n->op . '` compares object IDENTITY in PHP, and an erased value has none'
                . ' — compare the values: `$a->' . $prop . ' ' . $n->op . ' $b->' . $prop . '`',
        );
    }

    private function checkInstanceof(Instanceof_ $n): void
    {
        $cls = \ltrim($n->class, '\\');
        if (!isset($this->typeDefs[$cls])) { return; }
        $this->fail($cls, '`instanceof` needs a class id, and an erased value has none');
    }

    private function checkCall(Call $n): void
    {
        $callee = $n->function;
        if ($this->observesObject($callee)) {
            foreach ($n->args as $a) {
                $cls = $a->type->typeDefClass();
                if ($cls !== null && isset($this->typeDefs[$cls])) {
                    $prop = $this->typeDefs[$cls]->typeDefProp;
                    $this->fail(
                        $cls,
                        '`' . $callee . '()` would see the erased scalar, not the object PHP builds'
                            . ' — pass the value: `' . $callee . '($x->' . $prop . ')`',
                    );
                }
            }
            return;
        }
        $params = $this->paramsByFn[$callee] ?? null;
        if ($params === null) { return; }
        $i = 0;
        foreach ($n->args as $a) {
            $p = $params[$i] ?? null;
            $i = $i + 1;
            if ($p === null) { continue; }
            $cls = $a->type->typeDefClass();
            if ($cls === null || !isset($this->typeDefs[$cls])) { continue; }
            if (!$this->isBoxed($p->type)) { continue; }
            $this->fail(
                $cls,
                'argument ' . $i . ' of `' . $callee . '()` is `mixed` — boxing an erased value into a'
                    . ' tagged cell loses the type, and everything downstream sees a bare number.'
                    . ' Declare the parameter `' . $cls . '`, or pass `$x->'
                    . $this->typeDefs[$cls]->typeDefProp . '`',
            );
        }
    }

    private function checkReturn(Return_ $n, FunctionDef $fn): void
    {
        if ($n->value === null) { return; }
        $cls = $n->value->type->typeDefClass();
        if ($cls === null || !isset($this->typeDefs[$cls])) { return; }
        if (!$this->isBoxed($fn->returnType)) { return; }
        $this->fail(
            $cls,
            '`' . $fn->name . '()` returns `mixed` — declare the return type `' . $cls . '`, else the'
                . ' erased value is boxed into a tagged cell and its type is gone',
        );
    }

    private function checkStoreElement(StoreElement $n): void
    {
        $cls = $n->value->type->typeDefClass();
        if ($cls === null || !isset($this->typeDefs[$cls])) { return; }
        $elem = $n->array->type->element;
        if ($elem === null || !$this->isBoxed($elem)) { return; }
        $this->fail(
            $cls,
            'stored into an untyped array — annotate it `/** @var ' . $cls . '[] $arr */` so the'
                . ' elements stay raw, else each one is boxed into a tagged cell and its type is gone',
        );
    }

    private function checkStoreProperty(StoreProperty $n): void
    {
        $cls = $n->value->type->typeDefClass();
        if ($cls === null || !isset($this->typeDefs[$cls])) { return; }
        if (!$this->isBoxed($n->type)) { return; }
        $this->fail(
            $cls,
            'stored into a `mixed` property `->' . $n->property . '` — declare the property `'
                . $cls . '`, else the erased value is boxed into a tagged cell and its type is gone',
        );
    }

    /** A slot that would NaN-box the value (and so erase what it is). */
    private function isBoxed(Type $t): bool
    {
        return $t->kind === Type::KIND_CELL || $t->kind === Type::KIND_UNKNOWN;
    }

    private function fail(string $cls, string $why): void
    {
        throw new \RuntimeException('#[TypeDef] ' . $cls . ': ' . $why);
    }
}
