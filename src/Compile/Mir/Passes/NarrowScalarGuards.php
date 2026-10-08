<?php

namespace Compile\Mir\Passes;

use Compile\Mir\BoolConst;
use Compile\Mir\Call;
use Compile\Mir\Cast;
use Compile\Mir\FunctionDef;
use Compile\Mir\Foreach_;
use Compile\Mir\If_;
use Compile\Mir\IncDec;
use Compile\Mir\LoadLocal;
use Compile\Mir\Module;
use Compile\Mir\Node;
use Compile\Mir\Not_;
use Compile\Mir\Spread_;
use Compile\Mir\StaticLocalDecl_;
use Compile\Mir\StoreLocal;
use Compile\Mir\Ternary;
use Compile\Mir\TryCatch_;
use Compile\Mir\Type;
use Compile\Mir\Walk;

/**
 * `if (is_int($x)) { … }` — inside the branch `$x` IS an int, so read an int.
 *
 * A `mixed` / untyped local is a CELL, and a cell stays a cell through every
 * use: `$i = $x` copies the box, `$i < $n` goes through the generic tagged
 * comparison, `$a[$i]` through the cell-key path. php-cs-fixer's hottest
 * function is exactly that shape — `SplFixedArray::__index(mixed $index)`,
 * behind every `$tokens[$i]` — and it alone was ~18% of the run.
 *
 * Narrowing the local's TYPE in place is unsound: its slot holds the boxed
 * word, and a load typed int would read the box's bits ({@see InferNarrow}).
 * So the guarded branch gets its own unboxed copy instead:
 *
 *     if (is_int($x)) { $x__ni1 = (int)$x; …$x__ni1… }
 *
 * and every read of `$x` in the branch reads the copy. The copy is exact — the
 * guard proved the tag — and InferTypes types it int from the cast.
 *
 * Only a branch that cannot CHANGE `$x` qualifies: no store, inc/dec, unset,
 * reference, foreach/catch/static binding of it, no closure (a capture binds by
 * name), and no call that could take it by reference. Scalars only (`is_int`,
 * `is_float`, `is_bool`): their copy owns nothing.
 */
final class NarrowScalarGuards
{
    public const NAME = 'narrow-scalar-guards';

    private const GUARDS = [
        'is_int' => 'int', 'is_integer' => 'int', 'is_long' => 'int',
        'is_float' => 'float', 'is_double' => 'float',
        'is_bool' => 'bool',
    ];

    /** Internal functions with a by-reference parameter somewhere. */
    public const BYREF_BUILTINS = [
        'settype', 'sort', 'rsort', 'usort', 'uasort', 'uksort', 'asort', 'arsort', 'ksort', 'krsort',
        'natsort', 'natcasesort', 'shuffle', 'array_multisort', 'array_push', 'array_pop', 'array_shift',
        'array_unshift', 'array_splice', 'array_walk', 'array_walk_recursive', 'end', 'reset', 'next',
        'prev', 'current', 'key', 'each', 'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback',
        'str_replace', 'str_ireplace', 'similar_text', 'parse_str', 'sscanf', 'extract', 'compact',
        'get_defined_vars', 'func_get_args', 'exec', 'openssl_sign', 'stream_select', 'socket_select',
        'headers_sent', 'getimagesize', 'mb_parse_str', 'dns_get_record', 'is_callable', 'list',
    ];

    /** @var array<string, int[]> user function → by-ref param positions */
    private array $byRefParams = [];

    private int $seq = 0;

    public function run(Module $module): Module
    {
        foreach ($module->functions as $fn) {
            $pos = [];
            foreach ($fn->params as $i => $p) {
                if ($p->byRef) { $pos[] = $i; }
            }
            if ($pos !== []) { $this->byRefParams[\strtolower($fn->name)] = $pos; }
        }
        foreach ($module->functions as $fn) {
            if ($fn->isExtern) { continue; }
            if ($this->usesDynamicScope($fn->body)) { continue; }
            $this->visit($fn->body);
        }
        return $module;
    }

    private function visit(Node $n): void
    {
        foreach (Walk::children($n) as $c) { $this->visit($c); }
        if ($n instanceof If_) { $this->narrowIf($n); }
    }

    private function narrowIf(If_ $n): void
    {
        /** @var array<string, string> $guards local → cast target */
        $guards = [];
        $this->collectGuards($n->cond, $guards);
        if ($guards === []) { return; }
        $pre = [];
        foreach ($guards as $name => $target) {
            if (!$this->branchKeeps($n->then, $name)) { continue; }
            $this->seq = $this->seq + 1;
            $alias = '__ni' . (string)$this->seq . '_' . $name;
            $this->renameLoads($n->then, $name, $alias);
            $t = $target === 'int' ? Type::int_() : ($target === 'float' ? Type::float_() : Type::bool_());
            $pre[] = new StoreLocal($alias, new Cast($target, new LoadLocal($name, Type::unknown()), $t), $t);
        }
        if ($pre === []) { return; }
        foreach ($n->then->stmts as $s) { $pre[] = $s; }
        $n->then->stmts = $pre;
    }

    /**
     * The conjuncts of `$cond` that are `is_<scalar>($local)`. `A && B` lowers
     * to `Ternary(A, !!B, false)`; both sides hold in the then-branch.
     * @param array<string, string> $out
     */
    private function collectGuards(Node $c, array &$out): void
    {
        if ($c instanceof Call) {
            $fn = \strtolower(\ltrim($c->function, '\\'));
            $target = self::GUARDS[$fn] ?? '';
            $arg = \count($c->args) === 1 ? $c->args[0] : null;
            if ($target !== '' && $arg instanceof LoadLocal) {
                $name = $arg->name;
                if ($name !== 'this') { $out[$name] = $target; }
            }
            return;
        }
        if ($c instanceof Not_) {
            $inner = $c->operand;
            if ($inner instanceof Not_) { $this->collectGuards($inner->operand, $out); }
            return;
        }
        if ($c instanceof Ternary) {
            $else = $c->else_;
            $then = $c->then;
            if ($then !== null && $else instanceof BoolConst && $else->value === false) {
                $this->collectGuards($c->cond, $out);
                $this->collectGuards($then, $out);
            }
        }
    }

    /** Can nothing in `$n` rebind, change, alias or capture `$name`? */
    private function branchKeeps(Node $n, string $name): bool
    {
        $k = $n->kind;
        if ($n instanceof StoreLocal && $n->name === $name) { return false; }
        if ($n instanceof IncDec && $n->name === $name) { return false; }
        if ($n instanceof StaticLocalDecl_ && $n->name === $name) { return false; }
        if ($n instanceof Foreach_ && ($n->valueVar === $name || $n->keyVar === $name)) { return false; }
        if ($k === Node::KIND_CLOSURE || $k === Node::KIND_REF_ALIAS || $k === Node::KIND_REF_BIND
            || $k === Node::KIND_REF_CELL || $k === Node::KIND_GOTO || $k === Node::KIND_LABEL) {
            return false;
        }
        if ($k === Node::KIND_REF_ADDR || $k === Node::KIND_UNSET) {
            if ($this->mentions($n, $name)) { return false; }
        }
        if ($n instanceof TryCatch_) {
            foreach ($n->catches as $cat) {
                if ($cat->var === $name) { return false; }
            }
        }
        if ($n instanceof Call) {
            $fn = \strtolower(\ltrim($n->function, '\\'));
            $byRef = $this->byRefParams[$fn] ?? [];
            $builtinRef = \in_array($fn, self::BYREF_BUILTINS, true);
            foreach ($n->args as $i => $a) {
                if (!$this->isLoadOf($a, $name)) { continue; }
                if ($builtinRef || \in_array($i, $byRef, true)) { return false; }
            }
        }
        if ($k === Node::KIND_METHOD_CALL || $k === Node::KIND_STATIC_CALL || $k === Node::KIND_INVOKE
            || $k === Node::KIND_NEW_OBJ || $k === Node::KIND_NEW_DYN_OBJ) {
            // The callee is not known here, and any of them may take it by reference.
            foreach (Walk::children($n) as $a) {
                if ($this->isLoadOf($a, $name)) { return false; }
            }
        }
        foreach (Walk::children($n) as $c) {
            if (!$this->branchKeeps($c, $name)) { return false; }
        }
        return true;
    }

    private function isLoadOf(Node $n, string $name): bool
    {
        if ($n instanceof Spread_) {
            foreach (Walk::children($n) as $c) {
                if ($this->isLoadOf($c, $name)) { return true; }
            }
            return false;
        }
        return $n instanceof LoadLocal && $n->name === $name;
    }

    private function mentions(Node $n, string $name): bool
    {
        if ($n instanceof LoadLocal && $n->name === $name) { return true; }
        foreach (Walk::children($n) as $c) {
            if ($this->mentions($c, $name)) { return true; }
        }
        return false;
    }

    private function renameLoads(Node $n, string $name, string $alias): void
    {
        if ($n instanceof LoadLocal && $n->name === $name) {
            $n->name = $alias;
            return;
        }
        foreach (Walk::children($n) as $c) { $this->renameLoads($c, $name, $alias); }
    }

    /** `compact`, `extract`, `get_defined_vars`, `$$x`-style access: the scope is read by name. */
    private function usesDynamicScope(Node $n): bool
    {
        if ($n instanceof Call) {
            $fn = \strtolower(\ltrim($n->function, '\\'));
            if ($fn === 'compact' || $fn === 'extract' || $fn === 'get_defined_vars') { return true; }
        }
        foreach (Walk::children($n) as $c) {
            if ($this->usesDynamicScope($c)) { return true; }
        }
        return false;
    }
}
