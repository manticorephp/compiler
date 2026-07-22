<?php

namespace Compile\Mir;

/**
 * Declared shape of one instance property, kept alongside
 * {@see ClassDef::$propertyNames}. The reflection source for
 * `ReflectionProperty` — visibility, static, readonly, the raw type hint.
 *
 * `propertyNames`/`propertyTypes` answer "what slot, what MIR type"; they lost
 * the visibility and the AS-WRITTEN hint that php reports. This carries them,
 * the same way {@see MethodMeta} carries a method's declaration.
 *
 * A typed object, NOT a nested assoc: an assoc-of-assoc erases to KIND_UNKNOWN
 * under the native self-host and a missing key reads back `false`. Fields are
 * never null for the same reason — absent reads as `''`.
 *
 * The type hint is the SOURCE STRING (`?App\Foo`), not a lowered {@see Type}:
 * a lowered array has already erased its element and a typevar its bound, and
 * `ReflectionNamedType` wants the name php would report. Round-trips via
 * `lowerTypeHint` when a consumer needs the Type.
 */
final class PropertyMeta
{
    /** @param string[] $attributes attribute names, verbatim as written */
    public function __construct(
        public string $name,
        public string $visibility = 'public',
        public bool $isStatic = false,
        public bool $isReadonly = false,
        public string $typeHint = '',
        public bool $hasDefault = false,
        /** The class that actually declared it — an inherited property names its
         *  origin, not the inheriting class. */
        public string $declaringClass = '',
        public array $attributes = [],
    ) {}

    /** `?Foo` / `Foo|null` — what ReflectionNamedType::allowsNull() reports. */
    public function allowsNull(): bool
    {
        if ($this->typeHint === '') { return true; }
        if (\substr($this->typeHint, 0, 1) === '?') { return true; }
        return \strpos('|' . \strtolower($this->typeHint) . '|', '|null|') !== false;
    }
}
