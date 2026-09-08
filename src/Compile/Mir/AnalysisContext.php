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

    /**
     * A barrier is NOT a reason to fall back.
     *
     * It used to be, and that is why targeted inference never once engaged: a
     * single method call, closure or static prop ANYWHERE in the module set
     * `barriers` non-empty, and every real program has all three. But a barrier
     * is a property of ONE function — it says this analysis cannot see that
     * function's dependencies — so the honest response is to re-infer THAT
     * function every round ({@see InferenceScope::fromContext}), not to re-infer
     * the other 2540. What genuinely cannot be scoped stays here: an unknown
     * escape, and a change to a class or a global, neither of which the
     * function-level dependency graph models at all.
     */
    public function isConservativeFallback(): bool
    {
        return $this->dependencies->hasUnknownEscape()
            || $this->changes->unknownEscape
            || \count($this->changes->classes) > 0
            || \count($this->changes->globals) > 0;
    }

    public function scope(): InferenceScope
    {
        return InferenceScope::fromContext($this);
    }
}
