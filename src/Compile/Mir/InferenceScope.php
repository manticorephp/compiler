<?php
namespace Compile\Mir;

/** Immutable decision for a pass: full reference or conservative target set. */
final class InferenceScope
{
    public const FULL = 'full';
    public const TARGETED = 'targeted';

    private function __construct(
        public readonly string $mode,
        public readonly array $functions,
        public readonly string $reason,
    ) {}

    public static function fromContext(AnalysisContext $context): self
    {
        if ($context->isConservativeFallback()) {
            return new self(self::FULL, [], 'barrier-or-unknown');
        }
        // The closure of what changed, WIDENED by every function the analysis
        // cannot see through: an indirect call, a static dispatch, a dynamic
        // property, shared state, a closure body, a reference. Those are exactly
        // the edges DependencyIndex does not model, so re-inferring their holders
        // unconditionally is what makes the narrowed scope SOUND rather than
        // optimistic.
        $names = [];
        foreach ($context->invalidated() as $n) { $names[$n] = true; }
        foreach ($context->barriers->escapers() as $n => $_) { $names[$n] = true; }
        return new self(self::TARGETED, \array_keys($names), 'dependency-closure+escapers');
    }

    public function isTargeted(): bool { return $this->mode === self::TARGETED; }
}
