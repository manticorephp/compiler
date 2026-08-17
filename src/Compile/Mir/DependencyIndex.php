<?php
namespace Compile\Mir;

final class DependencyIndex
{
    private array $callees = [];
    private array $callers = [];
    private array $functions = [];
    private array $dynamicCallers = [];
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
                $this->dynamicCallers[$caller] = true;
                $this->unknownEscape = true;
                $this->unknownReasons['call:' . $callee] = true;
            }
        }
        if ($node->kind === Node::KIND_METHOD_CALL
            || $node->kind === Node::KIND_NEW_DYN_OBJ) {
            $this->dynamicCallers[$caller] = true;
            $this->unknownEscape = true;
            $this->unknownReasons['dispatch:' . $caller] = true;
        }
        foreach (Walk::children($node) as $child) { $this->collect($child, $caller); }
    }
}
