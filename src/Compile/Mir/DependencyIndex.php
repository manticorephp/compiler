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
    /**
     * A method's own symbol => its bare name.
     *
     * Composed from the class table (`Class` ⧺ `__` ⧺ `method`), never split out
     * of the symbol: `__` is also legal inside a class name, so parsing it back
     * is ambiguous while composing it is exact.
     */
    private array $methodOf = [];
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
                if (isset($functions[$sym])) { $index->methodOf[$sym] = $m; }
            }
        }
        foreach ($module->functions as $fn) {
            if ($fn->isPrelude) { continue; }
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
        return $queue;
    }

    public function invalidateChanges(ChangeSet $changes): array
    {
        if ($changes->unknownEscape || \count($changes->classes) > 0 || \count($changes->globals) > 0) {
            return \array_keys($this->functions);
        }
        return $this->invalidate(\array_keys($changes->functions));
    }
    public function dynamicCallerCount(): int { return \count($this->dynamicCallers); }
    public function externCalleeCount(): int { return \count($this->externCallees); }
    public function unknownReasonCount(): int { return \count($this->unknownReasons); }

    private function collect(Node $node, string $caller): void
    {
        if ($node->kind === Node::KIND_CALL) {
            /** @var Call $node */
            $callee = $node->function;
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
            /** @var MethodCall_ $node */
            $this->methodCallers[$node->method][$caller] = true;
            $this->dynamicCallers[$caller] = true;
        }
        // `new $cls(...)` reaches an unknown constructor, so it is a caller of
        // every `__construct` for invalidation purposes — and of nothing else.
        if ($node->kind === Node::KIND_NEW_DYN_OBJ) {
            $this->dynNewCallers[$caller] = true;
            $this->dynamicCallers[$caller] = true;
        }
        foreach (Walk::children($node) as $child) { $this->collect($child, $caller); }
    }
}
