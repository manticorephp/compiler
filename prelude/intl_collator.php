<?php

/**
 * ext/intl Collator over ICU's ucol_* (php-src ext/intl/collator, transcribed).
 * Needs prelude/intl.php (UTF-16 conversion, the intl error state).
 */

const ULOC_ACTUAL_LOCALE = 0;
const ULOC_VALID_LOCALE = 1;

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_getStrength'), \Ffi\CType('int')]
function __mc_icu_ucol_getStrength(\Ffi\Ptr $coll): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_setStrength')]
function __mc_icu_ucol_setStrength(\Ffi\Ptr $coll, #[\Ffi\CType('int')] int $strength): void {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_getAttribute'), \Ffi\CType('int')]
function __mc_icu_ucol_getAttribute(\Ffi\Ptr $coll, #[\Ffi\CType('int')] int $attr, \Ffi\Ptr $err): int { return 0; }

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_getLocaleByType')]
function __mc_icu_ucol_getLocaleByType(\Ffi\Ptr $coll, #[\Ffi\CType('int')] int $type, \Ffi\Ptr $err): \Ffi\Ptr {}

#[\Ffi\Library('icui18n'), \Ffi\Symbol('ucol_getSortKey'), \Ffi\CType('int')]
function __mc_icu_ucol_getSortKey(\Ffi\Ptr $coll, \Ffi\Ptr $src, #[\Ffi\CType('int')] int $len,
    \Ffi\Ptr $dest, #[\Ffi\CType('int')] int $cap): int { return 0; }

#[\Ffi\Library('icuuc'), \Ffi\Symbol('uloc_getDefault')]
function __mc_icu_uloc_getDefault(): \Ffi\Ptr {}

class Collator
{
    public const DEFAULT_VALUE = -1;
    public const PRIMARY = 0;
    public const SECONDARY = 1;
    public const TERTIARY = 2;
    public const DEFAULT_STRENGTH = 2;
    public const QUATERNARY = 3;
    public const IDENTICAL = 15;
    public const OFF = 16;
    public const ON = 17;
    public const SHIFTED = 20;
    public const NON_IGNORABLE = 21;
    public const LOWER_FIRST = 24;
    public const UPPER_FIRST = 25;
    public const FRENCH_COLLATION = 0;
    public const ALTERNATE_HANDLING = 1;
    public const CASE_FIRST = 2;
    public const CASE_LEVEL = 3;
    public const NORMALIZATION_MODE = 4;
    public const STRENGTH = 5;
    public const HIRAGANA_QUATERNARY_MODE = 6;
    public const NUMERIC_COLLATION = 7;
    public const SORT_REGULAR = 0;
    public const SORT_STRING = 1;
    public const SORT_NUMERIC = 2;

    private \Ffi\Ptr $coll;
    private int $errCode = 0;
    private string $errMessage = "";

    public function __construct(string $locale)
    {
        if ($locale === "") { $locale = \cstr_to_str(\__mc_icu_uloc_getDefault()); }
        $e = \__mc_icu_err();
        $this->coll = \__mc_icu_ucol_open($locale, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code > 0) {
            throw new \IntlException("Collator::__construct(): collator_create: unable to open ICU collator");
        }
    }

    public function __destruct()
    {
        \__mc_icu_ucol_close($this->coll);
    }

    public static function create(string $locale): ?Collator
    {
        return new Collator($locale);
    }

    /** Start a method: both error states cleared. */
    private function begin(): void
    {
        $this->errCode = 0;
        $this->errMessage = "";
        \__mc_intl_reset();
    }

    private function fail(string $fn, string $what, int $code): void
    {
        $this->errCode = $code;
        $this->errMessage = \__mc_intl_message($fn, $what, $code);
        \__mc_intl_fail($fn, $what, $code);
    }

    public function __mcCompare(string $fn, string $string1, string $string2): int|false
    {
        $this->begin();
        $a = \__mc_icu_to16($string1);
        if ($a === null) {
            $this->fail($fn, "Error converting first argument to UTF-16", 10);
            return false;
        }
        $b = \__mc_icu_to16($string2);
        if ($b === null) {
            \__mc_icu_free($a->buf);
            $this->fail($fn, "Error converting second argument to UTF-16", 10);
            return false;
        }
        $r = \__mc_icu_ucol_strcoll($this->coll, $a->buf, $a->len, $b->buf, $b->len);
        \__mc_icu_free($a->buf);
        \__mc_icu_free($b->buf);
        return $r;
    }

    public function compare(string $string1, string $string2): int|false
    {
        return $this->__mcCompare("Collator::compare", $string1, $string2);
    }

    /**
     * zend_hash_sort over the values with the flag's compare function; `$keep`
     * keeps the keys (asort), else the result is renumbered (sort).
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public function __mcSorted(array $array, int $flags, bool $keep): array
    {
        $keys = [];
        $vals = [];
        $u16 = [];
        foreach ($array as $k => $v) {
            $keys[] = $k;
            $vals[] = $v;
            $u16[] = \is_string($v) ? \__mc_icu_to16($v) : null;
        }
        $order = \array_keys($vals);
        $coll = $this->coll;
        $order = \__mc_intl_stable_sort($order, function (int $i, int $j) use ($vals, $u16, $flags, $coll): int {
            return \__mc_collator_cmp($coll, $flags, $vals[$i], $vals[$j], $u16[$i], $u16[$j]);
        });
        foreach ($u16 as $u) {
            if ($u !== null) { \__mc_icu_free($u->buf); }
        }
        $out = [];
        foreach ($order as $i) {
            if ($keep) {
                $out[$keys[$i]] = $vals[$i];
            } else {
                $out[] = $vals[$i];
            }
        }
        return $out;
    }

    /** @param array<mixed> $array */
    public function sort(array &$array, int $flags = Collator::SORT_REGULAR): bool
    {
        $this->begin();
        $array = $this->__mcSorted($array, $flags, false);
        return true;
    }

    /** @param array<mixed> $array */
    public function asort(array &$array, int $flags = Collator::SORT_REGULAR): bool
    {
        $this->begin();
        $array = $this->__mcSorted($array, $flags, true);
        return true;
    }

    /** @param array<mixed> $array */
    public function sortWithSortKeys(array &$array): bool
    {
        $this->begin();
        $vals = [];
        $keys = [];
        foreach ($array as $v) {
            $key = \is_string($v) ? $this->__mcSortKey("Collator::sortWithSortKeys", $v) : $this->__mcSortKey("Collator::sortWithSortKeys", "");
            if ($key === false) { return false; }
            $vals[] = $v;
            $keys[] = $key;
        }
        $order = \array_keys($vals);
        $order = \__mc_intl_stable_sort($order, function (int $i, int $j) use ($keys): int { return \strcmp($keys[$i], $keys[$j]) <=> 0; });
        $out = [];
        foreach ($order as $i) { $out[] = $vals[$i]; }
        $array = $out;
        return true;
    }

    public function getAttribute(int $attribute): int|false
    {
        $this->begin();
        $e = \__mc_icu_err();
        $v = \__mc_icu_ucol_getAttribute($this->coll, $attribute, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code > 0) {
            $this->fail("Collator::getAttribute", "Error getting attribute value", $code);
            return false;
        }
        return $v;
    }

    public function setAttribute(int $attribute, int $value): bool
    {
        $this->begin();
        $e = \__mc_icu_err();
        \__mc_icu_ucol_setAttribute($this->coll, $attribute, $value, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code > 0) {
            $this->fail("Collator::setAttribute", "Error setting attribute value", $code);
            return false;
        }
        return true;
    }

    public function getStrength(): int
    {
        $this->begin();
        return \__mc_icu_ucol_getStrength($this->coll);
    }

    public function setStrength(int $strength): bool
    {
        $this->begin();
        \__mc_icu_ucol_setStrength($this->coll, $strength);
        return true;
    }

    public function getLocale(int $type): string|false
    {
        $this->begin();
        $e = \__mc_icu_err();
        $p = \__mc_icu_ucol_getLocaleByType($this->coll, $type, $e);
        $code = \peek_i32($e, 0);
        \__mc_icu_free($e);
        if ($code > 0 || \ptr_to_int($p) === 0) {
            $this->fail("Collator::getLocale", "Error getting locale by type", $code > 0 ? $code : 1);
            return false;
        }
        return \cstr_to_str($p);
    }

    public function getErrorCode(): int|false
    {
        return $this->errCode;
    }

    public function getErrorMessage(): string|false
    {
        return $this->errMessage !== "" ? $this->errMessage : \intl_error_name($this->errCode);
    }

    public function __mcSortKey(string $fn, string $string): string|false
    {
        $this->begin();
        $u = \__mc_icu_to16($string);
        if ($u === null) {
            $this->fail($fn, "Error converting first argument to UTF-16", 10);
            return false;
        }
        $n = \__mc_icu_ucol_getSortKey($this->coll, $u->buf, $u->len, \int_to_ptr(0), 0);
        if ($n === 0) {
            \__mc_icu_free($u->buf);
            return false;
        }
        $buf = \__mc_icu_malloc($n);
        $n = \__mc_icu_ucol_getSortKey($this->coll, $u->buf, $u->len, $buf, $n);
        \__mc_icu_free($u->buf);
        $key = $n === 0 ? false : \str_from_buffer($buf, $n - 1);
        \__mc_icu_free($buf);
        return $key;
    }

    public function getSortKey(string $string): string|false
    {
        return $this->__mcSortKey("Collator::getSortKey", $string);
    }
}

/**
 * A stable merge sort of int indexes (zend_hash_sort is stable). Local rather
 * than usort: usort lives in a prelude file gated on the USER program's calls.
 * @param int[] $a
 * @return int[]
 */
function __mc_intl_stable_sort(array $a, \Closure $cmp): array
{
    $n = \count($a);
    $width = 1;
    while ($width < $n) {
        $out = [];
        $lo = 0;
        while ($lo < $n) {
            $mid = \min($lo + $width, $n);
            $hi = \min($lo + 2 * $width, $n);
            $i = $lo;
            $j = $mid;
            while ($i < $mid && $j < $hi) {
                if ($cmp($a[$j], $a[$i]) < 0) {
                    $out[] = $a[$j];
                    $j = $j + 1;
                } else {
                    $out[] = $a[$i];
                    $i = $i + 1;
                }
            }
            while ($i < $mid) { $out[] = $a[$i]; $i = $i + 1; }
            while ($j < $hi) { $out[] = $a[$j]; $j = $j + 1; }
            $lo = $hi;
        }
        $a = $out;
        $width = $width * 2;
    }
    return $a;
}

/** A numeric string's number (int or float), or null — collator_convert_string_to_number_if_possible. */
function __mc_collator_number(string $s): int|float|null
{
    if (!\is_numeric($s)) { return null; }
    return $s + 0;
}

/**
 * The three sort compare functions of collator_sort.c: SORT_NUMERIC compares as
 * doubles, SORT_STRING by ICU over the string forms, SORT_REGULAR by ICU unless
 * both are numeric strings or a value is not a string (then php's own compare
 * over normalized values).
 */
function __mc_collator_cmp(\Ffi\Ptr $coll, int $flags, mixed $a, mixed $b, ?__McIcuU16 $ua, ?__McIcuU16 $ub): int
{
    if ($flags === 2) {
        $x = \is_string($a) ? (float)$a : $a;
        $y = \is_string($b) ? (float)$b : $b;
        return $x <=> $y;
    }
    if ($flags === 1 || (\is_string($a) && \is_string($b)
        && (\__mc_collator_number($a) === null || \__mc_collator_number($b) === null))) {
        $sa = $ua;
        $sb = $ub;
        $fa = false;
        $fb = false;
        if ($sa === null) { $sa = \__mc_icu_to16((string)$a); $fa = true; }
        if ($sb === null) { $sb = \__mc_icu_to16((string)$b); $fb = true; }
        $r = 0;
        if ($sa !== null && $sb !== null) {
            $r = \__mc_icu_ucol_strcoll($coll, $sa->buf, $sa->len, $sb->buf, $sb->len);
        }
        if ($fa && $sa !== null) { \__mc_icu_free($sa->buf); }
        if ($fb && $sb !== null) { \__mc_icu_free($sb->buf); }
        return $r < 0 ? -1 : ($r > 0 ? 1 : 0);
    }
    $na = \is_string($a) ? (\__mc_collator_number($a) ?? $a) : $a;
    $nb = \is_string($b) ? (\__mc_collator_number($b) ?? $b) : $b;
    return $na <=> $nb;
}

function collator_create(string $locale): ?Collator
{
    return Collator::create($locale);
}

function collator_compare(Collator $object, string $string1, string $string2): int|false
{
    return $object->__mcCompare("collator_compare", $string1, $string2);
}

/** @param array<mixed> $array */
function collator_sort(Collator $object, array &$array, int $flags = Collator::SORT_REGULAR): bool
{
    return $object->sort($array, $flags);
}

/** @param array<mixed> $array */
function collator_asort(Collator $object, array &$array, int $flags = Collator::SORT_REGULAR): bool
{
    return $object->asort($array, $flags);
}

/** @param array<mixed> $array */
function collator_sort_with_sort_keys(Collator $object, array &$array): bool
{
    return $object->sortWithSortKeys($array);
}

function collator_get_attribute(Collator $object, int $attribute): int|false
{
    return $object->getAttribute($attribute);
}

function collator_set_attribute(Collator $object, int $attribute, int $value): bool
{
    return $object->setAttribute($attribute, $value);
}

function collator_get_strength(Collator $object): int
{
    return $object->getStrength();
}

function collator_set_strength(Collator $object, int $strength): bool
{
    return $object->setStrength($strength);
}

function collator_get_locale(Collator $object, int $type): string|false
{
    return $object->getLocale($type);
}

function collator_get_error_code(Collator $object): int|false
{
    return $object->getErrorCode();
}

function collator_get_error_message(Collator $object): string|false
{
    return $object->getErrorMessage();
}

function collator_get_sort_key(Collator $object, string $string): string|false
{
    return $object->__mcSortKey("collator_get_sort_key", $string);
}
