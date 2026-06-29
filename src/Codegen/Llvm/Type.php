<?php

namespace Codegen\Llvm;

/**
 * LLVM IR type abstraction.
 *
 * Types are immutable value objects. Their textual form is what LLVM IR
 * expects (e.g. `i32`, `i64`, `ptr`, `void`, `double`, `[14 x i8]`,
 * `i64 (i64, ptr)`).
 *
 * Pure string concatenation is used throughout — no interpolation with
 * `{$obj->prop}` because the current AOT compiler's string-interp pass is
 * unreliable for that form.
 */
final class Type
{
    private function __construct(
        public readonly string $text,
        /** @internal — number of bytes the type occupies (when statically known) */
        public readonly ?int $size = null,
    ) {}

    public static function void(): self  { return new self('void'); }
    public static function i1(): self    { return new self('i1', 1); }
    public static function i8(): self    { return new self('i8', 1); }
    public static function i16(): self   { return new self('i16', 2); }
    public static function i32(): self   { return new self('i32', 4); }
    public static function i64(): self   { return new self('i64', 8); }
    public static function f32(): self   { return new self('float', 4); }
    public static function f64(): self   { return new self('double', 8); }
    public static function ptr(): self   { return new self('ptr', 8); }

    /**
     * Fixed-size array type: `[count x elem]`.
     */
    public static function array(int $count, Type $elem): self
    {
        $size = $elem->size === null ? null : $count * $elem->size;
        return new self('[' . (string)$count . ' x ' . $elem->text . ']', $size);
    }

    /**
     * Function type: `<ret> (<params>...)`.
     *
     * @param Type[] $params
     */
    public static function func(Type $ret, array $params): self
    {
        $names = [];
        foreach ($params as $t) {
            $names[] = $t->text;
        }
        return new self($ret->text . ' (' . implode(', ', $names) . ')');
    }

    public static function raw(string $text, ?int $size = null): self
    {
        return new self($text, $size);
    }

    public function __toString(): string
    {
        return $this->text;
    }
}
