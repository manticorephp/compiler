<?php
namespace Compile\Mir;

/** Monotonic facts changed by one analysis/transformation pass. */
final class ChangeSet
{
    public array $functions = [];
    public array $returns = [];
    public array $params = [];
    public array $classes = [];
    /** Bare PROPERTY names whose type a scan moved. Kept apart from
     *  {@see $classes}, which is a blanket "fall back to the whole module":
     *  a property is reachable through the functions that name it, and that is
     *  a set {@see DependencyIndex} can produce. */
    public array $props = [];
    public array $globals = [];
    public array $newFunctions = [];
    public bool $unknownEscape = false;

    public function addFunction(string $name): void { $this->functions[$name] = true; }
    public function addReturn(string $name): void { $this->returns[$name] = true; $this->addFunction($name); }
    public function addParam(string $name): void { $this->params[$name] = true; $this->addFunction($name); }
    public function addClass(string $name): void { $this->classes[$name] = true; }
    public function addProp(string $name): void { $this->props[$name] = true; }
    public function addGlobal(string $name): void { $this->globals[$name] = true; }
    public function addNewFunction(string $name): void { $this->newFunctions[$name] = true; $this->addFunction($name); }
    public function markUnknown(): void { $this->unknownEscape = true; }

    public function merge(self $other): void
    {
        foreach ($other->functions as $k => $_) { $this->functions[$k] = true; }
        foreach ($other->returns as $k => $_) { $this->returns[$k] = true; }
        foreach ($other->params as $k => $_) { $this->params[$k] = true; }
        foreach ($other->classes as $k => $_) { $this->classes[$k] = true; }
        foreach ($other->props as $k => $_) { $this->props[$k] = true; }
        foreach ($other->globals as $k => $_) { $this->globals[$k] = true; }
        foreach ($other->newFunctions as $k => $_) { $this->newFunctions[$k] = true; }
        if ($other->unknownEscape) { $this->unknownEscape = true; }
    }

    public function isEmpty(): bool
    {
        return !$this->unknownEscape && \count($this->functions) === 0
            && \count($this->classes) === 0 && \count($this->globals) === 0
            && \count($this->props) === 0;
    }
}
