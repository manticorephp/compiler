<?php
namespace Compile\Mir;

/** Opt-in analysis state kept outside Module's native-sensitive object layout. */
final class AnalysisContext
{
    public DependencyIndex $dependencies;
    public ChangeSet $changes;
    public InferenceBarriers $barriers;

    public function __construct(Module $module)
    {
        $this->dependencies = DependencyIndex::build($module);
        $this->changes = new ChangeSet();
        $this->barriers = InferenceBarriers::scan($module);
    }

    public function invalidated(): array
    {
        return $this->dependencies->invalidateChanges($this->changes);
    }

    public function isConservativeFallback(): bool
    {
        return !$this->barriers->isEmpty()
            || $this->dependencies->hasUnknownEscape()
            || $this->changes->unknownEscape
            || \count($this->changes->classes) > 0
            || \count($this->changes->globals) > 0;
    }

    public function scope(): InferenceScope
    {
        return InferenceScope::fromContext($this);
    }
}
