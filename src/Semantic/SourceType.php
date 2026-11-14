<?php

namespace Semantic;

/**
 * Source-faithful type: what the author wrote, not the compiler's lowered
 * `Compile\Mir\Type`. Immutable.
 *
 * `resolved` is the FQN a SELF/STATIC type refers to (the enclosing class at
 * parse time); '' when unknown or for PARENT.
 * `ret` is the return type of a CALLABLE written with a signature.
 * `keys` are the key names of a SHAPE, parallel to `args` — for a CALLABLE
 * with a signature, what follows each parameter type (`$x`, `&$x`, `...$xs`, '' for none); a SHAPE with
 * `resolved === '...'` is unsealed (`array{a: int, ...}`). A NULLABLE with
 * `resolved === UNION_NULL` was spelled `T|null`, not `?T` — the compiler
 * lowers a nullable container differently under the two spellings.
 */
final class SourceType
{
    public const SCALAR = 'scalar';

    /** `resolved` of a NULLABLE spelled as a union with `null`. */
    public const UNION_NULL = '|null';

    /** `resolved` of a CALLABLE written in parentheses: `(callable(): T)|null` does not give the return the `|null`. */
    public const PARENS = '()';

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
    /** `Foo::BAR`, `Foo::*`, `Foo::PREFIX_*`: `name` is the text as written. */
    public const CONST_REF = 'const_ref';
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
                if ($this->resolved === '...') { $parts[] = '...'; }
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
            case self::STATIC:
                return 'static';
            default:
                return $this->name;
        }
    }
}
