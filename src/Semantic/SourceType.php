<?php

namespace Semantic;

/**
 * Source-faithful type: what the author wrote, not the compiler's lowered
 * `Compile\Mir\Type`. Immutable.
 *
 * `resolved` is the FQN a SELF/STATIC type refers to (the enclosing class at
 * parse time); '' when unknown or for PARENT.
 * `ret` is the return type of a CALLABLE written with a signature.
 * `keys` are the key names of a SHAPE, parallel to `args`.
 */
final class SourceType
{
    public const SCALAR = 'scalar';

    /** Suffix of an optional shape key; a NUL cannot be confused with a quoted key that ends in `?`. */
    public const OPT = "\0";
    public const CLASS_ = 'class';
    public const NULLABLE = 'nullable';
    public const UNION = 'union';
    public const INTERSECTION = 'intersection';
    public const GENERIC = 'generic';
    public const SELF = 'self';
    public const STATIC = 'static';
    public const PARENT = 'parent';
    public const CALLABLE = 'callable';
    public const ITERABLE = 'iterable';
    public const MIXED = 'mixed';
    public const VOID = 'void';
    public const NEVER = 'never';
    public const LITERAL = 'literal';
    /** `array{k: T, o?: U}`: `args` hold the value types, `keys` the key names (`?` suffix = optional). */
    public const SHAPE = 'shape';

    /**
     * @param SourceType[] $args
     * @param string[] $keys
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $name = '',
        public readonly array $args = [],
        public readonly string $resolved = '',
        public readonly ?SourceType $ret = null,
        public readonly array $keys = [],
    ) {}

    public function toString(): string
    {
        switch ($this->kind) {
            case self::NULLABLE:
                return '?' . $this->args[0]->toString();
            case self::UNION:
                $parts = [];
                foreach ($this->args as $a) {
                    $s = $a->toString();
                    $parts[] = $a->kind === self::INTERSECTION ? '(' . $s . ')' : $s;
                }
                return \implode('|', $parts);
            case self::INTERSECTION:
                $parts = [];
                foreach ($this->args as $a) { $parts[] = $a->toString(); }
                return \implode('&', $parts);
            case self::GENERIC:
            case self::ITERABLE:
                if ($this->args === []) { return $this->name; }
                $parts = [];
                foreach ($this->args as $a) { $parts[] = $a->toString(); }
                return $this->name . '<' . \implode(',', $parts) . '>';
            case self::SHAPE:
                $parts = [];
                foreach ($this->args as $i => $a) { $parts[] = (\str_ends_with($this->keys[$i], self::OPT) ? \substr($this->keys[$i], 0, -1) . '?' : $this->keys[$i]) . ':' . $a->toString(); }
                return $this->name . '{' . \implode(',', $parts) . '}';
            case self::CALLABLE:
                if ($this->args === [] && $this->ret === null) { return $this->name; }
                $parts = [];
                foreach ($this->args as $a) { $parts[] = $a->toString(); }
                $out = $this->name . '(' . \implode(',', $parts) . ')';
                if ($this->ret === null) { return $out; }
                $r = $this->ret->toString();
                $wrap = $this->ret->kind === self::UNION || $this->ret->kind === self::INTERSECTION;
                return $out . ':' . ($wrap ? '(' . $r . ')' : $r);
            default:
                return $this->name;
        }
    }
}
