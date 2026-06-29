<?php

namespace Codegen\Llvm;

/**
 * One incoming edge of a {@see PhiNode} — a value paired with the
 * block it flows in from.
 */
final class PhiIncoming
{
    public function __construct(
        public readonly Value $value,
        public readonly Block $source,
    ) {}
}

/**
 * Phi instruction holder.
 *
 * Phi nodes typically need at least one incoming edge that originates from
 * a block emitted *after* the phi itself (loop back-edges, merging after
 * branch arms). Building the line eagerly does not allow that.
 *
 * The block stores a reference to this object; `emit()` is called once
 * the block formats itself, by which time the caller has registered all
 * incoming edges via {@see addIncoming()}.
 */
final class PhiNode
{
    /** @var PhiIncoming[] */
    private array $incomings = [];

    public function __construct(
        public readonly string $name,
        public readonly Type $type,
    ) {}

    public function addIncoming(Value $value, Block $source): self
    {
        $this->incomings[] = new PhiIncoming($value, $source);
        return $this;
    }

    /**
     * @internal Called by {@see Block::emit()}.
     */
    public function emit(): string
    {
        $parts = [];
        foreach ($this->incomings as $in) {
            $parts[] = '[ ' . $in->value->operand . ', %' . $in->source->label . ' ]';
        }
        return '  %' . $this->name . ' = phi ' . $this->type->text . ' ' . implode(', ', $parts);
    }

    public function value(): Value
    {
        return Value::reg($this->type, $this->name);
    }
}
