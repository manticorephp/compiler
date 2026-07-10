<?php

namespace Compile\Mir;

/**
 * Class layout descriptor carried on the {@see Module}.
 *
 * Object ABI:
 *   offset 0  : ptr class descriptor ({i64 class_id, ptr drop_fn})
 *   offset 8  : i64 refcount
 *   offset 16 : property 0
 *   offset 24 : property 1
 *   …          (8 bytes each, declaration order)
 *
 * `propertyNames` is the ordered list used to compute offsets;
 * `propertyTypes` maps each name to its MIR {@see Type} so
 * `InferTypes` can resolve `$obj->prop` reads and `EmitLlvm` can
 * pick the right load/store coercion. Methods live in the module's
 * function list under the `Class__method` mangled name — this
 * descriptor only tracks which method names exist for dispatch
 * resolution.
 */
final class ClassDef
{
    /**
     * @param string[]            $propertyNames ordered, for offsets
     * @param array<string, Type> $propertyTypes name → type
     * @param array<string, true> $methodNames   declared method names
     */
    public function __construct(
        public readonly string $name,
        public readonly int $classId,
        public array $propertyNames,
        public array $propertyTypes,
        public array $methodNames,
        public string $parent = '',
        public array $interfaces = [],
        public array $staticPropNames = [],
        public array $staticPropTypes = [],
        public bool $isStruct = false,
        public bool $hasBag = false,
        /**
         * PHP 8.4 property hooks: property name → ['get' => symbol|null,
         * 'set' => symbol|null], where symbol is the pre-mangle hook-method name
         * (`<DeclaringClass>____hook_<prop>_get`). Inherited from the parent.
         * @var array<string, array{get: ?string, set: ?string}>
         */
        public array $propHooks = [],
    ) {}

    /** Names of properties declared `array` (or a typed array). `clone` must
     *  value-copy their array slot even when a bare `array` hint erased the
     *  element type to unknown (else the clone aliases the original's buffer).
     *  @var array<string, bool> */
    public array $propertyArrayHinted = [];

    /** Names of `readonly` properties — a write from outside the declaring class
     *  scope is a fatal `Error` (Cannot modify readonly property).
     *  @var array<string, bool> */
    public array $propertyReadonly = [];

    /** Whether this class carries a dynamic-property bag. */
    public function usesBag(): bool
    {
        return $this->hasBag;
    }

    /**
     * Byte offset of the dynamic-property bag (an assoc ptr) placed
     * after the declared properties; -1 when the class has no bag.
     */
    public function bagOffset(): int
    {
        if (!$this->hasBag) { return -1; }
        return $this->headerSize() + 8 * \count($this->propertyNames);
    }

    /** Whether this class directly declares static property `$name`. */
    public function hasStaticProp(string $name): bool
    {
        foreach ($this->staticPropNames as $n) {
            if ($n === $name) { return true; }
        }
        return false;
    }

    /** Header bytes before the first property (0 for `#[Struct]`). */
    public function headerSize(): int
    {
        return $this->isStruct ? 0 : 16;
    }

    /** Byte offset of `$prop` within an instance, or -1 if unknown. */
    public function propertyOffset(string $prop): int
    {
        $base = $this->headerSize();
        $i = 0;
        foreach ($this->propertyNames as $name) {
            if ($name === $prop) { return $base + 8 * $i; }
            $i = $i + 1;
        }
        return -1;
    }

    /** Instance size in bytes: header + 8 per property + bag slot. */
    public function instanceSize(): int
    {
        $bag = $this->hasBag ? 8 : 0;
        return $this->headerSize() + 8 * \count($this->propertyNames) + $bag;
    }
}
