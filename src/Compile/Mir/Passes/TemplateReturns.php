<?php

namespace Compile\Mir\Passes;

use Compile\Mir\Block;
use Compile\Mir\Call;
use Compile\Mir\Cmp;
use Compile\Mir\Concat;
use Compile\Mir\FunctionDef;
use Compile\Mir\Instanceof_;
use Compile\Mir\IntConst;
use Compile\Mir\LoadLocal;
use Compile\Mir\Module;
use Compile\Mir\NewObj;
use Compile\Mir\Node;
use Compile\Mir\NullConst;
use Compile\Mir\Param;
use Compile\Mir\Return_;
use Compile\Mir\StoreLocal;
use Compile\Mir\StringConst;
use Compile\Mir\Ternary;
use Compile\Mir\Throw_;
use Compile\Mir\Type;

/**
 * `$c->get(Foo::class)` where `get` declares `@template T` + `@param class-string<T> $id` +
 * `@return T` ({@see FunctionDef::$tplClassArg}): the call yields a `Foo`. php never checks a
 * docblock, so the result is not cast but GUARDED —
 *   `($t = call) instanceof Foo ? $t : throw new TypeError("C::get(): Return value must be of type Foo, X returned")`
 * (`?T`: a null passes; the message comes from one {@see helper} per module) — and the then-arm's
 * narrowing types the whole expression `obj<Foo>`.
 * Runs inside {@see InlineClosures}' rewrite, where receiver types are inferred and InferTypes #3 follows.
 */
final class TemplateReturns
{
    /** @var array<string, FunctionDef> */
    private array $fns = [];

    /** @var array<string, \Compile\Mir\ClassDef> */
    private array $classes = [];

    /** @var array<string, true> */
    private array $ifaces = [];

    private int $seq = 0;

    private bool $helperMade = false;

    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
        foreach ($module->functions as $fn) {
            if ($fn->tplClassArg >= 0) { $this->fns[$fn->name] = $fn; }
        }
        if ($this->fns !== []) {
            $this->classes = $module->classes;
            $this->ifaces = $module->interfaceNames;
        }
    }

    /** The guarded form of `$n`, or null when its callee binds no class template. */
    public function wrap(Node $n): ?Node
    {
        if ($this->fns === []) { return null; }
        $fn = null;
        $label = '';
        $args = [];
        $base = 0;
        if ($n->kind === Node::KIND_CALL) {
            $fn = $this->fns[$n->function] ?? null;
            $label = $n->function;
            $args = $n->args;
            $base = $n->recvArgs;
        } elseif ($n->kind === Node::KIND_STATIC_CALL) {
            $cls = $this->declarer($n->class, $n->method);
            $fn = $this->fns[$cls . '__' . $n->method] ?? null;
            $label = $cls . '::' . $n->method;
            $args = $n->args;
        } elseif ($n->kind === Node::KIND_METHOD_CALL) {
            if ($n->direct !== '') { return null; }
            $ot = $n->object->type;
            if ($ot->kind !== Type::KIND_OBJ || $ot->class === null) { return null; }
            $cls = $this->declarer($ot->class, $n->method);
            $fn = $this->fns[$cls . '__' . $n->method] ?? null;
            $label = $cls . '::' . $n->method;
            $args = $n->args;
        }
        if ($fn === null) { return null; }
        $arg = $args[$fn->tplClassArg + $base] ?? null;
        if ($arg === null || $arg->kind !== Node::KIND_STRING_CONST) { return null; }
        $want = \ltrim($arg->value, '\\');
        if (!isset($this->classes[$want]) && !isset($this->ifaces[$want])) { return null; }
        if ($n->type->kind === Type::KIND_OBJ && $n->type->class === $want) { return null; }
        $tmp = '__tpl_' . (string)$this->seq;
        $this->seq = $this->seq + 1;
        $line = $n->line;
        $head = new StringConst($label . '(): Return value must be of type ' . ($fn->tplNullable ? '?' : '') . $want . ', ', Type::string_());
        $throw = new Throw_(new Call($this->helper(), [$head, new LoadLocal($tmp, $n->type)], Type::obj('TypeError')), Type::void());
        $store = new StoreLocal($tmp, $n, $n->type);
        if (!$fn->tplNullable) {
            $out = new Ternary(new Instanceof_($store, $want), new LoadLocal($tmp, $n->type), $throw, Type::obj($want));
        } else {
            $inner = new Ternary(new Instanceof_(new LoadLocal($tmp, $n->type), $want), new LoadLocal($tmp, $n->type), $throw, Type::obj($want));
            $out = new Ternary(new Cmp($store, new NullConst(Type::null_()), '==='), new NullConst(Type::null_()), $inner, Type::obj($want));
        }
        $this->stamp($out, $n, $line);
        return $out;
    }

    /**
     * `__mc_fuse_tplret(string $head, mixed $v): TypeError` — the mismatch message built once per
     * module (internal, like every `__mc_fuse_` synthetic) instead of inline at each guard.
     */
    private function helper(): string
    {
        $name = '__mc_fuse_tplret';
        if (!$this->helperMade) {
            $this->helperMade = true;
            $msg = new Concat(new LoadLocal('head', Type::string_()),
                new Concat(new Call('get_debug_type', [new LoadLocal('v', Type::cell())], Type::string_()),
                    new StringConst(' returned', Type::string_()), Type::string_()),
                Type::string_());
            $err = new NewObj('TypeError', [$msg, new IntConst(0, Type::int_()), new NullConst(Type::null_())], Type::obj('TypeError'));
            $body = new Block([new Return_($err, Type::void())], Type::void());
            $this->module->addFunction(new FunctionDef($name, [new Param('head', Type::string_()), new Param('v', Type::cell())], Type::obj('TypeError'), $body));
        }
        return $name;
    }

    /** The class in `$class`'s chain that declares `$method` ('' = none). */
    private function declarer(string $class, string $method): string
    {
        $c = \ltrim($class, '\\');
        $guard = 0;
        while ($c !== '' && $guard < 64) {
            $guard = $guard + 1;
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { return ''; }
            if (isset($cd->methodNames[$method])) { return $c; }
            $c = $cd->parent;
        }
        return '';
    }

    private function stamp(Node $n, Node $call, int $line): void
    {
        if ($n === $call) { return; }
        if ($n->line === 0) { $n->line = $line; }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->stamp($c, $call, $line); }
    }
}
