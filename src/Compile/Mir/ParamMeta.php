<?php

namespace Compile\Mir;

/**
 * Declared shape of one parameter — the raw hint STRING as written, not a
 * lowered {@see Type}.
 *
 * Deliberate: a DI container asks "what class does this parameter want" and
 * then resolves it by name. A lowered Type has already erased a bare `array`
 * to KIND_UNKNOWN and a typevar to its bound, so it cannot answer that. The
 * hint string round-trips through `LowerFromAst::lowerTypeHint` whenever a
 * consumer does want the Type — the same trick the `.sig` plays
 * ({@see \Manticore\Sig::encodeType}).
 *
 * Never null — absent reads as `''`, per the ClassDef::$propHooks precedent.
 */
final class ParamMeta
{
    /** @param string[] $attributes attribute names, verbatim as written */
    public function __construct(
        public string $name,
        public string $typeHint = '',
        public bool $hasDefault = false,
        public bool $byRef = false,
        public bool $variadic = false,
        /** Non-empty when this param is a promoted property: its visibility. */
        public string $promoted = '',
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
