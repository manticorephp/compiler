<?php

namespace Analyze;

use Compile\TypeHint\GenericType;

/**
 * The analyzer's own type — a source-faithful view (unlike the compiler's
 * memory-oriented MIR `Type`, which erases `?T` / unions / element types). Built
 * from a hint string via the shared {@see GenericType} parser.
 *
 * Deliberately COARSE where precision would risk a false positive: a general
 * union (`A|B`), `callable`, `iterable`, `self/static/parent`, and anything
 * unhinted all collapse to a permissive kind that every assignability check
 * waves through. The point of the analyzer is to be trusted, so it only ever
 * reports a mismatch it can PROVE.
 */
final class Ty
{
    public const KIND_UNKNOWN = 'unknown';  // no hint / undecidable — permissive
    public const KIND_OPAQUE  = 'opaque';   // callable/iterable/self/union — permissive
    public const KIND_MIXED   = 'mixed';
    public const KIND_VOID    = 'void';
    public const KIND_NEVER   = 'never';
    public const KIND_NULL    = 'null';
    public const KIND_BOOL    = 'bool';
    public const KIND_INT     = 'int';
    public const KIND_FLOAT   = 'float';
    public const KIND_STRING  = 'string';
    public const KIND_ARRAY_  = 'array';
    public const KIND_OBJECT  = 'object';

    public function __construct(
        public string $kind,
        public bool $nullable = false,
        public string $className = '',
    ) {}

    public static function unknown(): Ty { return new Ty(self::KIND_UNKNOWN); }

    /** Parse a declared hint string into a Ty. null / unparseable → unknown. */
    public static function fromHint(?string $hint): Ty
    {
        if ($hint === null) { return new Ty(self::KIND_UNKNOWN); }
        $g = GenericType::parse($hint);
        if ($g === null) { return new Ty(self::KIND_UNKNOWN); }
        // A general union (`A|B`, not the `T|null` nullable sugar) is beyond this
        // coarse model — treat as permissive.
        if (\strpos($hint, '|') !== false && !$g->nullable) { return new Ty(self::KIND_OPAQUE); }
        $nullable = $g->nullable;
        if ($g->isArraySugar) { return new Ty(self::KIND_ARRAY_, $nullable); }
        $name = \strtolower($g->name);
        if ($name === 'array') { return new Ty(self::KIND_ARRAY_, $nullable); }
        if ($name === 'int' || $name === 'integer') { return new Ty(self::KIND_INT, $nullable); }
        if ($name === 'float' || $name === 'double') { return new Ty(self::KIND_FLOAT, $nullable); }
        if ($name === 'string') { return new Ty(self::KIND_STRING, $nullable); }
        if ($name === 'bool' || $name === 'boolean' || $name === 'false' || $name === 'true') {
            return new Ty(self::KIND_BOOL, $nullable);
        }
        if ($name === 'void') { return new Ty(self::KIND_VOID); }
        if ($name === 'never') { return new Ty(self::KIND_NEVER); }
        if ($name === 'null') { return new Ty(self::KIND_NULL, true); }
        if ($name === 'mixed') { return new Ty(self::KIND_MIXED); }
        if ($name === 'object' || $name === 'callable' || $name === 'iterable'
            || $name === 'self' || $name === 'static' || $name === 'parent'
            || $name === 'numeric' || $name === 'closure') {
            return new Ty(self::KIND_OPAQUE);
        }
        return new Ty(self::KIND_OBJECT, $nullable, $g->name);
    }

    private function permissive(): bool
    {
        return $this->kind === self::KIND_UNKNOWN || $this->kind === self::KIND_MIXED
            || $this->kind === self::KIND_OPAQUE || $this->kind === self::KIND_VOID
            || $this->kind === self::KIND_NEVER;
    }

    /**
     * Is a value of $source assignable to a slot declared $target under
     * strict_types (no scalar juggling; `int`→`float` widening is the one
     * implicit coercion PHP keeps)? Returns TRUE whenever it cannot prove
     * incompatibility — the caller only reports on a definite FALSE.
     */
    public static function assignable(Ty $target, Ty $source, Index $idx): bool
    {
        if ($target->permissive() || $source->permissive()) { return true; }

        if ($source->kind === self::KIND_NULL) {
            return $target->nullable || $target->kind === self::KIND_NULL;
        }
        if ($target->kind === self::KIND_NULL) {
            return false;
        }
        if ($target->kind === self::KIND_ARRAY_) { return $source->kind === self::KIND_ARRAY_; }
        if ($source->kind === self::KIND_ARRAY_) { return false; }

        if ($target->kind === self::KIND_OBJECT) {
            if ($source->kind !== self::KIND_OBJECT) { return false; }
            // Related by inheritance in EITHER direction is permitted: an upcast is
            // valid, and a downcast (`Node` where `IntConst` is declared — the
            // load-bearing-subclass idiom, or a value already narrowed by an
            // `instanceof` this flow model doesn't track) cannot be PROVEN wrong.
            // Only two UNRELATED classes are a definite mismatch.
            return $idx->isSubtype($source->className, $target->className)
                || $idx->isSubtype($target->className, $source->className);
        }
        if ($source->kind === self::KIND_OBJECT) { return false; }

        // Both scalars.
        if ($target->kind === $source->kind) { return true; }
        if ($target->kind === self::KIND_FLOAT && $source->kind === self::KIND_INT) { return true; }
        return false;
    }

    public function display(): string
    {
        if ($this->kind === self::KIND_NULL) { return 'null'; }
        $s = $this->kind === self::KIND_OBJECT ? $this->className : $this->kind;
        return $this->nullable ? '?' . $s : $s;
    }
}
