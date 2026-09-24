<?php
namespace Compile\Mir;

final class DependencyIndex
{
    private array $callees = [];
    private array $callers = [];
    private array $functions = [];
    private array $dynamicCallers = [];
    /** Callees that are not module functions: builtins, FFI bindings, .sig imports. */
    private array $externCallees = [];
    /** Bare method name => the functions that dispatch on it. */
    private array $methodCallers = [];
    /** Functions containing a `new $cls(...)`, i.e. callers of every constructor. */
    private array $dynNewCallers = [];
    /** Functions containing a closure INVOKE — the callers of every closure body. */
    private array $invokers = [];
    /**
     * Bare property name => the functions that read or write it.
     *
     * Inference does not only travel along calls. `InferTypes`'s module
     * pre-scans retype CLASS PROPERTIES, and a function that reads one has to
     * be re-inferred even though it calls nothing that changed — that is what
     * left `Sig::libsFromJson`, `Exception::getTrace`, `array_reverse` and
     * `array_pad` narrowing only after a full round. Keyed by NAME, like method
     * dispatch, and for the same reason: whatever class the receiver is, the
     * slot reached is some `C::prop`.
     */
    private array $propUsers = [];
    /** Functions reading or writing a property by a RUNTIME name — every
     *  property change reaches them. */
    private array $dynPropUsers = [];
    /**
     * A method's own symbol => its bare name.
     *
     * Composed from the class table (`Class` ⧺ `__` ⧺ `method`), never split out
     * of the symbol: `__` is also legal inside a class name, so parsing it back
     * is ambiguous while composing it is exact.
     */
    private array $methodOf = [];
    /** Bare method name => every module symbol implementing it. @var array<string, array<string, bool>> */
    private array $methodSyms = [];
    /** Caller => the bare method names it dispatches on (static or dynamic). @var array<string, array<string, bool>> */
    private array $methodCallsOf = [];
    private array $unknownReasons = [];
    private bool $unknownEscape = false;

    private function __construct(array $functions) { $this->functions = $functions; }

    public static function build(Module $module): self
    {
        $functions = [];
        foreach ($module->functions as $fn) {
            $functions[$fn->name] = true;
        }
        $index = new self($functions);
        foreach ($module->classes as $cd) {
            foreach ($cd->methodNames as $m => $_) {
                $sym = $cd->name . '__' . $m;
                if (isset($functions[$sym])) {
                    $index->methodOf[$sym] = $m;
                    $index->methodSyms[$m][$sym] = true;
                }
            }
        }
        foreach ($module->functions as $fn) {
            // Prelude bodies used to be skipped. They are ordinary functions for
            // invalidation: a prelude helper that calls a narrowed function has to
            // be re-inferred like any other caller, and with no edge collected it
            // never would be. One extra walk per build buys that soundness.
            $index->callees[$fn->name] = [];
            $index->collect($fn->body, $fn->name);
        }
        return $index;
    }

    public function hasUnknownEscape(): bool { return $this->unknownEscape; }
    public function functionCount(): int { return \count($this->functions); }
    public function edgeCount(): int
    {
        $n = 0;
        foreach ($this->callees as $edges) { $n += \count($edges); }
        return $n;
    }

    public function invalidate(array $changed): array
    {
        if ($this->unknownEscape) { return \array_keys($this->functions); }
        $seen = [];
        $queue = [];
        foreach ($changed as $name) {
            if (!isset($this->functions[$name]) || isset($seen[$name])) { continue; }
            $seen[$name] = true;
            $queue[] = $name;
        }
        for ($i = 0; $i < \count($queue); $i = $i + 1) {
            $name = $queue[$i];
            foreach (($this->callers[$name] ?? []) as $caller => $_) {
                if (isset($seen[$caller])) { continue; }
                $seen[$caller] = true;
                $queue[] = $caller;
            }
            // A changed closure body is reachable through every indirect call.
            if (\str_starts_with($name, '__closure_')) {
                foreach ($this->invokers as $caller => $_) {
                    if (isset($seen[$caller])) { continue; }
                    $seen[$caller] = true;
                    $queue[] = $caller;
                }
            }
            // A changed method is reachable through every site dispatching on its
            // name, and a changed constructor through every dynamic `new`.
            $bare = $this->methodOf[$name] ?? '';
            if ($bare === '') { continue; }
            foreach (($this->methodCallers[$bare] ?? []) as $caller => $_) {
                if (isset($seen[$caller])) { continue; }
                $seen[$caller] = true;
                $queue[] = $caller;
            }
            if ($bare === '__construct') {
                foreach ($this->dynNewCallers as $caller => $_) {
                    if (isset($seen[$caller])) { continue; }
                    $seen[$caller] = true;
                    $queue[] = $caller;
                }
            }
        }
        // Inference does not only flow callee -> caller. A parameter's type is
        // refined FROM its call sites, so when a caller's types move, the
        // callee has to be re-inferred as well. One hop is enough: the rounds
        // are a fixpoint, and each round takes the next hop. Without this the
        // scope missed exactly the functions whose ARRAY ELEMENT types come
        // from a parameter — array_reverse, array_pad, Sig::libsFromJson,
        // Exception::getTrace — every one of which narrows only after a full
        // inference.
        $n = \count($queue);
        for ($i = 0; $i < $n; $i = $i + 1) {
            $src = $queue[$i];
            $edges = $this->callees[$src] ?? [];
            foreach ($edges as $callee => $_) {
                if (isset($seen[$callee])) { continue; }
                $seen[$callee] = true;
                $queue[] = $callee;
            }
            // …and the same hop through a dispatch: a method's parameter is
            // refined from its call sites exactly like a function's.
            foreach (($this->methodCallsOf[$src] ?? []) as $m => $_) {
                foreach (($this->methodSyms[$m] ?? []) as $sym => $__) {
                    if (isset($seen[$sym])) { continue; }
                    $seen[$sym] = true;
                    $queue[] = $sym;
                }
            }
        }
        return $queue;
    }

    /**
     * The functions that can observe `$name` DIRECTLY: its callers (they read its
     * return), its callees (their parameters are refined from its arguments),
     * the same two through a dispatch on its bare name, a closure body's
     * invokers, a constructor's dynamic `new`s. One hop — the scoped pass walks
     * further only when a re-inferred neighbour's own types actually move
     * ({@see \Compile\Mir\Passes\InferTypes::run}).
     * @return array<string, bool>
     */
    public function neighbors(string $name): array
    {
        $out = [];
        foreach (($this->callers[$name] ?? []) as $n => $_) { $out[$n] = true; }
        foreach (($this->callees[$name] ?? []) as $n => $_) { $out[$n] = true; }
        foreach (($this->methodCallsOf[$name] ?? []) as $m => $_) {
            foreach (($this->methodSyms[$m] ?? []) as $sym => $__) { $out[$sym] = true; }
        }
        $bare = $this->methodOf[$name] ?? '';
        if ($bare !== '') {
            foreach (($this->methodCallers[$bare] ?? []) as $n => $_) { $out[$n] = true; }
            if ($bare === '__construct') {
                foreach ($this->dynNewCallers as $n => $_) { $out[$n] = true; }
            }
        }
        if (\str_starts_with($name, '__closure_')) {
            foreach ($this->invokers as $n => $_) { $out[$n] = true; }
        }
        unset($out[$name]);
        return $out;
    }

    /**
     * The first wave of a scoped pass: what moved, what can see it directly, and
     * whoever names a retyped property. The rest is propagated by the pass itself.
     * @return array<string, bool>|null  null when only a full pass is sound
     */
    public function seedChanges(ChangeSet $changes): ?array
    {
        if ($this->unknownEscape || $changes->unknownEscape
            || \count($changes->classes) > 0 || \count($changes->globals) > 0) {
            return null;
        }
        $out = [];
        foreach ($changes->functions as $fn => $_) {
            if (!isset($this->functions[$fn])) { continue; }
            $out[$fn] = true;
            foreach ($this->neighbors($fn) as $n => $__) { $out[$n] = true; }
        }
        if (\count($changes->props) > 0) {
            foreach ($changes->props as $prop => $_) {
                foreach (($this->propUsers[$prop] ?? []) as $fn => $__) { $out[$fn] = true; }
            }
            foreach ($this->dynPropUsers as $fn => $_) { $out[$fn] = true; }
        }
        return $out;
    }

    /** Everyone who can read property `$prop`: the functions naming it, and
     *  those addressing properties by a runtime name. @return array<string, bool> */
    public function usersOfProp(string $prop): array
    {
        $out = $this->propUsers[$prop] ?? [];
        foreach ($this->dynPropUsers as $fn => $_) { $out[$fn] = true; }
        return $out;
    }

    public function invalidateChanges(ChangeSet $changes): array
    {
        if ($changes->unknownEscape || \count($changes->classes) > 0 || \count($changes->globals) > 0) {
            return \array_keys($this->functions);
        }
        $seed = $changes->functions;
        // A retyped property reaches the functions that name it, plus everyone
        // who addresses properties by a runtime name.
        if (\count($changes->props) > 0) {
            foreach ($changes->props as $prop => $_) {
                foreach (($this->propUsers[$prop] ?? []) as $fn => $__) { $seed[$fn] = true; }
            }
            foreach ($this->dynPropUsers as $fn => $_) { $seed[$fn] = true; }
        }
        return $this->invalidate(\array_keys($seed));
    }
    public function dynamicCallerCount(): int { return \count($this->dynamicCallers); }
    public function externCalleeCount(): int { return \count($this->externCallees); }
    public function unknownReasonCount(): int { return \count($this->unknownReasons); }

    /**
     * Narrow before reading a field.
     *
     * A `@var` docblock is enough for Zend, which looks properties up by name,
     * and is NOTHING to the native build, which resolves them by OFFSET off the
     * declared type — here `Node`, which has no `function` at all. That read
     * returned garbage, no callee ever matched a module function, and the index
     * carried ZERO call edges (`edges=0`) while still looking healthy: method
     * dispatch is keyed separately, so invalidation still produced a plausible
     * 733 functions and nobody had a reason to doubt it.
     */
    private function asCall(Node $node): Call { return $node; }

    private function asMethodCall(Node $node): MethodCall_ { return $node; }

    private function asPropRead(Node $node): PropertyAccess_ { return $node; }

    private function asPropWrite(Node $node): StoreProperty { return $node; }

    private function asStaticProp(Node $node): StaticProp_ { return $node; }

    private function asStaticCall(Node $node): StaticCall_ { return $node; }

    private function asClosure(Node $node): Closure_ { return $node; }

    private function asStoreStaticProp(Node $node): StoreStaticProp_ { return $node; }

    private function collect(Node $node, string $caller): void
    {
        if ($node->kind === Node::KIND_CALL) {
            $callee = $this->asCall($node)->function;
            if (isset($this->functions[$callee])) {
                $this->callees[$caller][$callee] = true;
                $this->callers[$callee][$caller] = true;
            } else {
                // Not a module function, so it is a builtin, an FFI binding or a
                // symbol imported through a library `.sig`. Invalidation flows
                // from a CHANGED function to its callers, and none of those can
                // ever be the changed one — this analysis only mutates functions
                // it can see. A leaf needs no reverse edge, and treating it as an
                // unknown escape is what made every real module conservative:
                // one `strlen()` disabled targeted inference for the whole build.
                $this->externCallees[$callee] = true;
            }
        }
        // A dispatch does not make the whole module unanalysable — it makes ONE
        // edge imprecise. Key it by the method NAME instead: whatever class the
        // receiver turns out to be, the body reached is some `C::m`, so a change
        // to any `C::m` can only be observed by a site that calls `m`. That is a
        // sound over-approximation and a far narrower one than "every function".
        if ($node->kind === Node::KIND_METHOD_CALL) {
            $m = $this->asMethodCall($node)->method;
            $this->methodCallers[$m][$caller] = true;
            $this->methodCallsOf[$caller][$m] = true;
            $this->dynamicCallers[$caller] = true;
        }
        if ($node->kind === Node::KIND_PROPERTY_ACCESS) {
            $this->propUsers[$this->asPropRead($node)->property][$caller] = true;
        }
        if ($node->kind === Node::KIND_STORE_PROPERTY) {
            $this->propUsers[$this->asPropWrite($node)->property][$caller] = true;
        }
        if ($node->kind === Node::KIND_STATIC_PROP || $node->kind === Node::KIND_STORE_STATIC_PROP) {
            // A static prop's slot name is its global; reads and stores use
            // different node classes but are indexed under the same key.
            $global = $node->kind === Node::KIND_STATIC_PROP
                ? $this->asStaticProp($node)->global
                : $this->asStoreStaticProp($node)->global;
            $this->propUsers[$global][$caller] = true;
        }
        if ($node->kind === Node::KIND_DYN_PROP || $node->kind === Node::KIND_STORE_DYN_PROP) {
            $this->dynPropUsers[$caller] = true;
        }
        // `new $cls(...)` reaches an unknown constructor, so it is a caller of
        // every `__construct` for invalidation purposes — and of nothing else.
        // A static call reaches `C::m` — keyed by the method NAME like a
        // dispatch, which also covers a `parent::` / late-static-bound target.
        if ($node->kind === Node::KIND_STATIC_CALL) {
            $sm = $this->asStaticCall($node)->method;
            $this->methodCallers[$sm][$caller] = true;
            $this->methodCallsOf[$caller][$sm] = true;
        }
        // A closure body is typed from its captures, which its DEFINER types:
        // the definer changing re-infers the body (a callee edge), and the body's
        // return reaches the definer's own uses of the literal (a caller edge).
        if ($node->kind === Node::KIND_CLOSURE) {
            $body = '__closure_' . (string)$this->asClosure($node)->id;
            if (isset($this->functions[$body])) {
                $this->callees[$caller][$body] = true;
                $this->callers[$body][$caller] = true;
            }
        }
        // An indirect call can reach any closure body.
        if ($node->kind === Node::KIND_INVOKE) {
            $this->invokers[$caller] = true;
        }
        if ($node->kind === Node::KIND_NEW_DYN_OBJ) {
            $this->dynNewCallers[$caller] = true;
            $this->dynamicCallers[$caller] = true;
        }
        foreach (Walk::children($node) as $child) { $this->collect($child, $caller); }
    }
}
