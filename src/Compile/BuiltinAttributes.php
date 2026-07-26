<?php

declare(strict_types=1);

namespace Compile;

/**
 * The PHP reserved (built-in) attributes: their `#[Attribute(...)]` target
 * flags, plus the `Attribute::TARGET_*` constant values.
 *
 * Single source of truth for three consumers:
 *   - `LowerAttrChecks` — target / repeat validation and the `#[Override]` check
 *   - `LowerClasses::findClassConst` — resolving `Attribute::TARGET_*` in `src/`,
 *     which is compiled WITHOUT the prelude that declares the class
 *   - `prelude/attributes.php` — the declarations themselves must agree
 *
 * Values verified against php 8.5.8. `TARGET_CONSTANT` (64) is new in 8.5, which
 * is why `TARGET_ALL` is 127 and `IS_REPEATABLE` moved up to 128.
 */
final class BuiltinAttributes
{
    public const TARGET_CLASS          = 1;
    public const TARGET_FUNCTION       = 2;
    public const TARGET_METHOD         = 4;
    public const TARGET_PROPERTY       = 8;
    public const TARGET_CLASS_CONSTANT = 16;
    public const TARGET_PARAMETER      = 32;
    public const TARGET_CONSTANT       = 64;
    public const TARGET_ALL            = 127;
    public const IS_REPEATABLE         = 128;

    /**
     * Reserved attribute → the flags its own `#[Attribute(...)]` declares.
     * None of them is repeatable.
     *
     * @return array<string,int>
     */
    public static function flagsTable(): array
    {
        return [
            'Attribute'               => self::TARGET_CLASS,
            'Deprecated'              => self::TARGET_CLASS | self::TARGET_FUNCTION
                                       | self::TARGET_METHOD | self::TARGET_CLASS_CONSTANT
                                       | self::TARGET_CONSTANT,
            'NoDiscard'               => self::TARGET_FUNCTION | self::TARGET_METHOD,
            'Override'                => self::TARGET_METHOD | self::TARGET_PROPERTY,
            'SensitiveParameter'      => self::TARGET_PARAMETER,
            'ReturnTypeWillChange'    => self::TARGET_METHOD,
            'AllowDynamicProperties'  => self::TARGET_CLASS,
            'DelayedTargetValidation' => self::TARGET_ALL,
        ];
    }

    /** `Attribute::<NAME>` → value, for the no-prelude fallback. @return array<string,int> */
    public static function constTable(): array
    {
        return [
            'TARGET_CLASS'          => self::TARGET_CLASS,
            'TARGET_FUNCTION'       => self::TARGET_FUNCTION,
            'TARGET_METHOD'         => self::TARGET_METHOD,
            'TARGET_PROPERTY'       => self::TARGET_PROPERTY,
            'TARGET_CLASS_CONSTANT' => self::TARGET_CLASS_CONSTANT,
            'TARGET_PARAMETER'      => self::TARGET_PARAMETER,
            'TARGET_CONSTANT'       => self::TARGET_CONSTANT,
            'TARGET_ALL'            => self::TARGET_ALL,
            'IS_REPEATABLE'         => self::IS_REPEATABLE,
        ];
    }

    public static function isReserved(string $name): bool
    {
        $t = self::flagsTable();
        return isset($t[$name]);
    }

    /** Declared target flags, or -1 when `$name` is not a reserved attribute. */
    public static function flagsOf(string $name): int
    {
        $t = self::flagsTable();
        return isset($t[$name]) ? $t[$name] : -1;
    }

    /** The `Attribute::<NAME>` value, or null when there is no such constant. */
    public static function constValue(string $name): ?int
    {
        $t = self::constTable();
        return isset($t[$name]) ? $t[$name] : null;
    }

    /** The word php uses for one target bit in `cannot target <word>`. */
    public static function targetWord(int $bit): string
    {
        if ($bit === self::TARGET_CLASS)          { return 'class'; }
        if ($bit === self::TARGET_FUNCTION)       { return 'function'; }
        if ($bit === self::TARGET_METHOD)         { return 'method'; }
        if ($bit === self::TARGET_PROPERTY)       { return 'property'; }
        if ($bit === self::TARGET_CLASS_CONSTANT) { return 'class constant'; }
        if ($bit === self::TARGET_PARAMETER)      { return 'parameter'; }
        if ($bit === self::TARGET_CONSTANT)       { return 'constant'; }
        return 'unknown';
    }

    /** `allowed targets: <list>` — bits ascending, comma-joined, as php prints it. */
    public static function allowedList(int $flags): string
    {
        $out = '';
        $bit = self::TARGET_CLASS;
        while ($bit <= self::TARGET_CONSTANT) {
            if (($flags & $bit) !== 0) {
                if ($out !== '') { $out .= ', '; }
                $out .= self::targetWord($bit);
            }
            $bit = $bit * 2;
        }
        return $out;
    }
}
