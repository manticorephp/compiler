<?php

namespace Compile\Mir;

/**
 * MIR type lattice. Inspired by HHIR — scalar primitives plus
 * vec / assoc / object-by-class. Tagged union ({@see KIND_CELL})
 * carries the set of atoms it can hold so future passes can
 * narrow the refinement.
 *
 * Flat shape, `kind` discriminant + optional payload fields. Self-
 * host pre-scan narrows on `kind` cheaply; deep subclass trees
 * would push it past current limits.
 */
final class Type
{
    public const KIND_VOID    = 'void';
    public const KIND_NULL    = 'null';
    public const KIND_BOOL    = 'bool';
    public const KIND_INT     = 'int';
    public const KIND_FLOAT   = 'float';
    public const KIND_STRING  = 'string';
    /**
     * ONE array kind (Stage 4 unified PhpArray). A "vec" is an array
     * with no explicit key type ({@see $key} null — implicit int keys);
     * an "assoc" is an array whose key type is string. The packed/hashed
     * split is a runtime detail, not a static kind. Use {@see isVec} /
     * {@see isAssoc} / {@see isArray} to discriminate.
     */
    public const KIND_ARRAY   = 'array';
    public const KIND_OBJ     = 'obj';
    public const KIND_CLOSURE = 'closure';
    public const KIND_CELL    = 'cell';
    public const KIND_UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $kind,
        public readonly ?self $element = null,
        public readonly ?self $key = null,
        public readonly ?string $class = null,
        /** @var self[] */
        public readonly array $atoms = [],
        /** A `cell` whose every arm is numeric (`int|float`): same NaN-boxed
         *  repr as a plain cell, but arithmetic may promote at runtime (the
         *  cell-arith path). A plain mixed cell keeps the integer path. A bool,
         *  not a Type[] atom list — the latter is a self-host miscompile hazard. */
        public readonly bool $numeric = false,
    ) {}

    public static function void():    self { return new self(self::KIND_VOID); }
    public static function null_():   self { return new self(self::KIND_NULL); }
    public static function bool_():   self { return new self(self::KIND_BOOL); }
    public static function int_():    self { return new self(self::KIND_INT); }
    public static function float_():  self { return new self(self::KIND_FLOAT); }
    public static function string_(): self { return new self(self::KIND_STRING); }
    public static function unknown(): self { return new self(self::KIND_UNKNOWN); }
    public static function closure(): self { return new self(self::KIND_CLOSURE); }

    public static function vec(self $element): self
    {
        return new self(self::KIND_ARRAY, element: $element);
    }

    public static function assoc(self $key, self $value): self
    {
        return new self(self::KIND_ARRAY, element: $value, key: $key);
    }

    /** Any array (vec or assoc — they share {@see KIND_ARRAY}). */
    public function isArray(): bool
    {
        return $this->kind === self::KIND_ARRAY;
    }

    /** A string-keyed array ("assoc"): {@see $key} is a string type. */
    public function isAssoc(): bool
    {
        return $this->kind === self::KIND_ARRAY
            && $this->key !== null && $this->key->kind === self::KIND_STRING;
    }

    /** An int-keyed / unkeyed array ("vec"): an array that is not assoc. */
    public function isVec(): bool
    {
        return $this->kind === self::KIND_ARRAY
            && !($this->key !== null && $this->key->kind === self::KIND_STRING);
    }

    public static function obj(string $class): self
    {
        return new self(self::KIND_OBJ, class: $class);
    }

    /**
     * A `Generator` value; `element` is the yielded value type, `key` the
     * yielded key type (both nullable) — i.e. Generator<TKey, TValue>.
     */
    public static function generator(?self $value, ?self $key = null): self
    {
        return new self(self::KIND_OBJ, element: $value, key: $key, class: 'Generator');
    }

    public function isGenerator(): bool
    {
        return $this->kind === self::KIND_OBJ && $this->class === 'Generator';
    }

    /** @param self[] $atoms */
    public static function cell(array $atoms = []): self
    {
        return new self(self::KIND_CELL, atoms: $atoms);
    }

    /** A numeric (`int|float`) cell — a NaN-boxed value known to be int OR
     *  float, so arithmetic over it promotes at runtime instead of forcing int. */
    public static function numericCell(): self
    {
        return new self(self::KIND_CELL, numeric: true);
    }

    /** A `cell` whose arms are all numeric (int|float) — arithmetic may promote. */
    public function isNumericCell(): bool
    {
        return $this->kind === self::KIND_CELL && $this->numeric;
    }

    /**
     * Join two types at a control-flow merge point. Same kind →
     * same type, anything else → `unknown`. Future passes refine
     * with proper union types (`int|float` → number, …).
     */
    public function unionWith(Type $other): Type
    {
        if ($this->kind !== $other->kind) { return self::unknown(); }
        if ($this->kind === self::KIND_OBJ) {
            if ($this->class !== $other->class) { return self::unknown(); }
            return $this;
        }
        // Arrays join element- AND key-wise so a control-flow merge keeps a
        // refined shape (`vec[unknown]` ∪ `vec[string]` → `vec[string]`; a
        // loop body that appends a typed value must not reset to
        // `vec[unknown]` on the back-edge). A null key (vec) joined with a
        // string key (assoc) lifts to the string key.
        if ($this->kind === self::KIND_ARRAY) {
            $key = ($this->key === null && $other->key === null)
                ? null
                : $this->joinElement($this->key, $other->key);
            return new self(
                self::KIND_ARRAY,
                element: $this->joinElement($this->element, $other->element),
                key: $key,
            );
        }
        return $this;
    }

    /** Join two optional element/key types; `unknown`/null defers to the other. */
    private function joinElement(?Type $a, ?Type $b): Type
    {
        if ($a === null || $a->kind === self::KIND_UNKNOWN) {
            return $b === null ? self::unknown() : $b;
        }
        if ($b === null || $b->kind === self::KIND_UNKNOWN) {
            return $a;
        }
        return $a->unionWith($b);
    }

    public function toString(): string
    {
        if ($this->kind === self::KIND_ARRAY) {
            // Preserve the vec[…] / assoc[…] presentation (golden-stable):
            // a string key reads as an assoc, otherwise a vec.
            if ($this->key !== null && $this->key->kind === self::KIND_STRING) {
                return 'assoc['
                    . $this->key->toString()
                    . ', '
                    . ($this->element?->toString() ?? '?')
                    . ']';
            }
            return 'vec[' . ($this->element?->toString() ?? '?') . ']';
        }
        if ($this->kind === self::KIND_OBJ) {
            return 'obj<' . ($this->class ?? '?') . '>';
        }
        if ($this->kind === self::KIND_CELL) {
            if ($this->atoms === []) { return 'cell'; }
            $parts = [];
            foreach ($this->atoms as $atom) { $parts[] = $atom->toString(); }
            return 'cell{' . implode('|', $parts) . '}';
        }
        return $this->kind;
    }
}
