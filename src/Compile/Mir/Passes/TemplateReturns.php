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
use Compile\Mir\MethodMeta;
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
 * `@return T` ({@see FunctionDef::$tplClassArg}, {@see MethodMeta::$tplClassArg}): the call yields
 * a `Foo`. php never checks a docblock, so the result is not cast but GUARDED —
 *   `($t = call) instanceof Foo ? $t : throw new TypeError("C::get(): Return value must be of type Foo, X returned")`
 * (`?T`: a null passes; the message comes from one {@see helper} per module) — and the then-arm's
 * narrowing types the whole expression `obj<Foo>`. A method's signature may come from an interface
 * or a parent it inherits its doc from ({@see methodSig}).
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

    /** @var array<string, array<string, MethodMeta>> */
    private array $ifaceMethods = [];

    /** @var array<string, string[]> */
    private array $ifaceAncestors = [];

    /** Some declaration binds a class template: otherwise {@see wrap} is a no-op. */
    private bool $any = false;

    /** The declaration {@see methodSig} found the binding on (`Iface`), for the message. */
    private string $sigOwner = '';

    private int $seq = 0;

    private bool $helperMade = false;

    private Module $module;

    public function __construct(Module $module)
    {
        $this->module = $module;
        foreach ($module->functions as $fn) {
            if ($fn->tplClassArg >= 0) { $this->fns[$fn->name] = $fn; }
        }
        $any = $this->fns !== [];
        foreach ($module->classes as $cd) {
            foreach ($cd->methodMeta as $mm) { if ($mm->tplClassArg >= 0) { $any = true; } }
        }
        foreach ($module->interfaceMethods as $ims) {
            foreach ($ims as $mm) { if ($mm->tplClassArg >= 0) { $any = true; } }
        }
        $this->any = $any;
        if ($any) {
            $this->classes = $module->classes;
            $this->ifaces = $module->interfaceNames;
            $this->ifaceMethods = $module->interfaceMethods;
            $this->ifaceAncestors = $module->interfaceAncestors;
        }
    }

    /** The guarded form of `$n`, or null when its callee binds no class template. */
    public function wrap(Node $n): ?Node
    {
        if (!$this->any) { return null; }
        $idx = -1;
        $nullable = false;
        $label = '';
        $args = [];
        if ($n->kind === Node::KIND_CALL) {
            $fn = $this->fns[$n->function] ?? null;
            if ($fn === null) { return null; }
            $idx = $fn->tplClassArg + $n->recvArgs;
            $nullable = $fn->tplNullable;
            $label = $n->function;
            $args = $n->args;
        } else {
            $cls = '';
            $method = '';
            if ($n->kind === Node::KIND_STATIC_CALL) {
                $cls = $n->class;
                $method = $n->method;
                $args = $n->args;
            } elseif ($n->kind === Node::KIND_METHOD_CALL) {
                if ($n->direct !== '') { return null; }
                $ot = $n->object->type;
                if ($ot->kind !== Type::KIND_OBJ || $ot->class === null) { return null; }
                $cls = $ot->class;
                $method = $n->method;
                $args = $n->args;
            }
            if ($cls === '') { return null; }
            $mm = $this->methodSig(\ltrim($cls, '\\'), $method);
            if ($mm === null) { return null; }
            $idx = $mm->tplClassArg;
            $nullable = $mm->tplNullable;
            $label = $this->sigOwner . '::' . $method;
        }
        $arg = $args[$idx] ?? null;
        if ($arg === null || $arg->kind !== Node::KIND_STRING_CONST) { return null; }
        $want = \ltrim($arg->value, '\\');
        if (!isset($this->classes[$want]) && !isset($this->ifaces[$want])) { return null; }
        if ($n->type->kind === Type::KIND_OBJ && $n->type->class === $want) { return null; }
        $tmp = '__tpl_' . (string)$this->seq;
        $this->seq = $this->seq + 1;
        $line = $n->line;
        $head = new StringConst($label . '(): Return value must be of type ' . ($nullable ? '?' : '') . $want . ', ', Type::string_());
        $throw = new Throw_(new Call($this->helper(), [$head, new LoadLocal($tmp, $n->type)], Type::obj('TypeError')), Type::void());
        $store = new StoreLocal($tmp, $n, $n->type);
        if (!$nullable) {
            $out = new Ternary(new Instanceof_($store, $want), new LoadLocal($tmp, $n->type), $throw, Type::obj($want));
        } else {
            $inner = new Ternary(new Instanceof_(new LoadLocal($tmp, $n->type), $want), new LoadLocal($tmp, $n->type), $throw, Type::obj($want));
            $out = new Ternary(new Cmp($store, new NullConst(Type::null_()), '==='), new NullConst(Type::null_()), $inner, Type::obj($want));
        }
        $this->stamp($out, $n, $line);
        return $out;
    }

    /**
     * The template-bearing declaration `$class->$method` answers to, doc inheritance as phpstan
     * applies it: the class's own declaration, then up the parent chain, then the interfaces each
     * of them implements (declared order, each before its ancestors). A declaration with its own
     * `@return` and no binding ends the search; one without a docblock return inherits.
     */
    private function methodSig(string $class, string $method): ?MethodMeta
    {
        if (isset($this->ifaces[$class])) { return $this->ifaceSig($class, $method); }
        /** @var string[] $ifs */
        $ifs = [];
        $c = $class;
        $guard = 0;
        while ($c !== '' && $guard < 64) {
            $guard = $guard + 1;
            $cd = $this->classes[$c] ?? null;
            if ($cd === null) { break; }
            if (isset($cd->methodNames[$method])) {
                $mm = $cd->methodMeta[$method] ?? null;
                if ($mm !== null && $mm->tplClassArg >= 0) { $this->sigOwner = $c; return $mm; }
                if ($mm !== null && $mm->docReturn) { return null; }
            }
            foreach ($cd->interfaces as $i) { $ifs[] = $i; }
            $c = $cd->parent;
        }
        foreach ($ifs as $i) {
            $mm = $this->ifaceSig($i, $method);
            if ($mm !== null) { return $mm; }
        }
        return null;
    }

    private function ifaceSig(string $iface, string $method): ?MethodMeta
    {
        /** @var string[] $chain */
        $chain = [$iface];
        foreach ($this->ifaceAncestors[$iface] ?? [] as $a) { $chain[] = $a; }
        foreach ($chain as $i) {
            $mm = $this->ifaceMethods[$i][$method] ?? null;
            if ($mm === null) { continue; }
            if ($mm->tplClassArg >= 0) { $this->sigOwner = $i; return $mm; }
            if ($mm->docReturn) { return null; }
        }
        return null;
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

    private function stamp(Node $n, Node $call, int $line): void
    {
        if ($n === $call) { return; }
        if ($n->line === 0) { $n->line = $line; }
        foreach (\Compile\Mir\Walk::children($n) as $c) { $this->stamp($c, $call, $line); }
    }
}
