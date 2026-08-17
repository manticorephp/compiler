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
        return new self(self::TARGETED, $context->invalidated(), 'dependency-closure');
    }

    public function isTargeted(): bool { return $this->mode === self::TARGETED; }
}
