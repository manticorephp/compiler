<?php

namespace Codegen\Llvm;

/**
 * Operand of an LLVM IR instruction.
 *
 * Combines a type and a textual operand. Operands can be SSA registers
 * (`%name`), globals (`@name`), or immediates (`42`, `0.0`, `null`).
 *
 * String concatenation only — no `{$obj->prop}` interpolation, since the
 * current AOT compiler's expansion of that form is unreliable.
 */
final class Value
{
    public function __construct(
        public readonly Type $type,
        public readonly string $operand,
    ) {}

    public static function int(Type $type, int $value): self
    {
        return new self($type, (string)$value);
    }

    public static function float(Type $type, float $value): self
    {
        // Don't call `is_finite()` — it's a stub in the self-host
        // build (returns 0), which routed every finite float into
        // the "+inf" hex path. Direct compares against DBL_MAX-ish
        // bounds catch both infinities; NaN trips the inequality
        // self-test (`x !== x`).
        if ($value !== $value) {
            return new self($type, '0x7FF8000000000000'); // quiet NaN
        }
        if ($value > 1.7976931348623157e+308) {
            return new self($type, '0x7FF0000000000000');
        }
        if ($value < -1.7976931348623157e+308) {
            return new self($type, '0xFFF0000000000000');
        }
        return new self($type, sprintf('%.17e', $value));
    }

    public static function bool(bool $value): self
    {
        return new self(Type::i1(), $value ? 'true' : 'false');
    }

    public static function null(): self
    {
        return new self(Type::ptr(), 'null');
    }

    public static function global(Type $type, string $name): self
    {
        return new self($type, '@' . $name);
    }

    public static function reg(Type $type, string $name): self
    {
        return new self($type, '%' . $name);
    }

    /**
     * Format as `<type> <operand>` for instructions that expect a typed
     * operand (e.g. `call i32 @puts(ptr %arg)`).
     */
    public function typed(): string
    {
        return $this->type->text . ' ' . $this->operand;
    }
}
